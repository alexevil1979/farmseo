#!/usr/bin/env bash
# Farm VPS bootstrap for shared host (/ssd/www/farm).
# Does NOT touch other Apache vhosts. Safe to re-run (idempotent-ish).
#
# Usage (on server, as root):
#   bash /ssd/www/farm/deploy/vps-bootstrap.sh --domain=farm.1tlt.ru
set -euo pipefail

FARM_ROOT="/ssd/www/farm"
FARM_USER="farm"
FARM_GROUP="farm"
DOMAIN="farm.1tlt.ru"
WORKER_COUNT=1
PHP_VER="8.2"
MYSQL_DB="farm"
MYSQL_USER="farm"
DO_SEED=1

for arg in "$@"; do
  case "$arg" in
    --domain=*) DOMAIN="${arg#*=}" ;;
    --workers=*) WORKER_COUNT="${arg#*=}" ;;
    --root=*) FARM_ROOT="${arg#*=}" ;;
    --php=*) PHP_VER="${arg#*=}" ;;
    --skip-seed) DO_SEED=0 ;;
    *) echo "Unknown arg: $arg" >&2; exit 1 ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || { echo "run as root"; exit 1; }
[[ -d "${FARM_ROOT}" ]] || { echo "missing ${FARM_ROOT}"; exit 1; }

log() { echo "[farm-vps] $*"; }
die() { echo "[farm-vps] ERROR: $*" >&2; exit 1; }
rand_hex() { openssl rand -hex "${1:-24}"; }
rand_b64() { openssl rand -base64 32 | tr -d '\n/=+' | head -c 48; }

mysql_root() {
  if [[ -f /etc/mysql/debian.cnf ]]; then
    local u p
    u="$(awk -F' *= *' '/^user/{print $2; exit}' /etc/mysql/debian.cnf)"
    p="$(awk -F' *= *' '/^password/{print $2; exit}' /etc/mysql/debian.cnf)"
    MYSQL_PWD="$p" mysql -u"$u" "$@"
    return
  fi
  mysql "$@"
}

log "packages (only if missing)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y >/dev/null
apt-get install -y \
  "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" "php${PHP_VER}-redis" \
  "php${PHP_VER}-curl" "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" "php${PHP_VER}-zip" \
  "php${PHP_VER}-gd" "php${PHP_VER}-bcmath" "php${PHP_VER}-intl" \
  redis-server supervisor xvfb x11vnc novnc websockify \
  curl ca-certificates unzip git openssl rsync >/dev/null

CHROME_BIN="$(command -v google-chrome-stable || command -v google-chrome || true)"
[[ -n "${CHROME_BIN}" ]] || die "Chrome not found — install google-chrome-stable first"

if ! command -v composer >/dev/null 2>&1; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

a2enmod proxy proxy_http proxy_wstunnel rewrite headers ssl setenvif proxy_fcgi >/dev/null 2>&1 || true

if ! getent group "${FARM_GROUP}" >/dev/null; then
  groupadd --system "${FARM_GROUP}"
fi
if ! id -u "${FARM_USER}" >/dev/null 2>&1; then
  useradd --system --gid "${FARM_GROUP}" --home-dir "${FARM_ROOT}" \
    --shell /usr/sbin/nologin "${FARM_USER}"
fi
usermod -aG "${FARM_GROUP}" www-data 2>/dev/null || true

mkdir -p \
  "${FARM_ROOT}/storage/logs" \
  "${FARM_ROOT}/storage/screenshots" \
  "${FARM_ROOT}/storage/profiles" \
  "${FARM_ROOT}/storage/cookies" \
  /var/backups/farm \
  /var/log/farm \
  /etc/ssl/farm

chown -R "${FARM_USER}:${FARM_GROUP}" "${FARM_ROOT}"
chmod 755 "${FARM_ROOT}/public"
chmod +x "${FARM_ROOT}/farm" 2>/dev/null || true

log "MySQL database…"
MYSQL_PASS="$(rand_b64)"
if [[ -f "${FARM_ROOT}/.env" ]] && grep -q '^DB_PASSWORD=' "${FARM_ROOT}/.env"; then
  MYSQL_PASS="$(grep '^DB_PASSWORD=' "${FARM_ROOT}/.env" | head -1 | cut -d= -f2-)"
fi
mysql_root -e "CREATE DATABASE IF NOT EXISTS \`${MYSQL_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql_root -e "CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'localhost' IDENTIFIED BY '${MYSQL_PASS}';" || true
mysql_root -e "ALTER USER '${MYSQL_USER}'@'localhost' IDENTIFIED BY '${MYSQL_PASS}';"
mysql_root -e "GRANT ALL PRIVILEGES ON \`${MYSQL_DB}\`.* TO '${MYSQL_USER}'@'localhost';"
mysql_root -e "FLUSH PRIVILEGES;"

REDIS_PASS=""
if grep -qE '^requirepass ' /etc/redis/redis.conf 2>/dev/null; then
  REDIS_PASS="$(awk '/^requirepass /{print $2; exit}' /etc/redis/redis.conf)"
fi

API_URL="https://${DOMAIN}"
INTERNAL_TOKEN="$(rand_hex 32)"
APP_KEY="$(rand_hex 32)"
if [[ -f "${FARM_ROOT}/.env" ]]; then
  grep -q '^INTERNAL_TOKEN=' "${FARM_ROOT}/.env" && INTERNAL_TOKEN="$(grep '^INTERNAL_TOKEN=' "${FARM_ROOT}/.env" | head -1 | cut -d= -f2-)"
  grep -q '^APP_KEY=' "${FARM_ROOT}/.env" && APP_KEY="$(grep '^APP_KEY=' "${FARM_ROOT}/.env" | head -1 | cut -d= -f2-)"
fi

if [[ ! -f "${FARM_ROOT}/.env" ]]; then
  log "writing .env"
  cat > "${FARM_ROOT}/.env" <<EOF
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${MYSQL_DB}
DB_USERNAME=${MYSQL_USER}
DB_PASSWORD=${MYSQL_PASS}
DB_TEST_DATABASE=farm_test

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=${REDIS_PASS}
REDIS_DB=0
REDIS_TIMEOUT=2.0

APP_KEY=${APP_KEY}
API_URL=${API_URL}
INTERNAL_TOKEN=${INTERNAL_TOKEN}
CLOUDFLARE_API_TOKEN=
GEO_PROVIDER=stub
MAX_CHROME=2
WORKER_SLOTS=2
WORKER_HTTP_PORT=3000
CHROME_HEADLESS=false
CHROME_PATH=${CHROME_BIN}
PUPPETEER_EXECUTABLE_PATH=${CHROME_BIN}
CHROME_EXTRA_ARGS=--no-sandbox --disable-dev-shm-usage --disable-gpu
VNC_BASE_PORT=5910
WEBSOCKIFY_BASE_PORT=6090
DISPLAY_BASE=100
XVFB_SCREEN=1920x1080x24
NOVNC_WEB_PATH=/usr/share/novnc
LIVE_VIEW_IDLE_SEC=600
WORKER_URL=http://127.0.0.1:3000
RESULT_URL=${API_URL}/internal/task/result
QUEUE_BRPOP_TIMEOUT=5
RECLAIM_INTERVAL_MS=15000
RECLAIM_STALE_SEC=45
ACCOUNT_LOCK_TTL=600
PROXY_LOCK_TTL=120
KEEP_OPEN_MIN_MS=3000
KEEP_OPEN_MAX_MS=15000
GOVERNOR_CPU_PCT=80
GOVERNOR_RAM_PCT=90
NIGHT_WINDOW_START=23:00
NIGHT_WINDOW_END=07:00
RETRY_BASE_SEC=30
RETRY_MAX_SEC=900
SCHED_ENQUEUE_BATCH=50
GC_STALE_GRACE_SEC=60
GC_KILL_ORPHAN_CHROME=1
MONITOR_INTERVAL_SEC=15
EOF
else
  # refresh non-secret knobs
  sed -i "s|^API_URL=.*|API_URL=${API_URL}|" "${FARM_ROOT}/.env"
  sed -i "s|^RESULT_URL=.*|RESULT_URL=${API_URL}/internal/task/result|" "${FARM_ROOT}/.env"
  grep -q '^CLOUDFLARE_API_TOKEN=' "${FARM_ROOT}/.env" || echo 'CLOUDFLARE_API_TOKEN=' >> "${FARM_ROOT}/.env"
fi
chown "${FARM_USER}:${FARM_GROUP}" "${FARM_ROOT}/.env"
chmod 600 "${FARM_ROOT}/.env"

install -d -m 700 /root/.farm
cat > /root/.farm/install-credentials.txt <<EOF
# Generated $(date -u +%Y-%m-%dT%H:%M:%SZ)
FARM_ROOT=${FARM_ROOT}
DOMAIN=${DOMAIN}
DB_DATABASE=${MYSQL_DB}
DB_USERNAME=${MYSQL_USER}
DB_PASSWORD=${MYSQL_PASS}
API_URL=${API_URL}
ADMIN=admin@farm.local / admin123!
EOF
chmod 600 /root/.farm/install-credentials.txt

log "composer + npm…"
sudo -u "${FARM_USER}" -H bash -lc "cd '${FARM_ROOT}' && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction"
sudo -u "${FARM_USER}" -H bash -lc "cd '${FARM_ROOT}/workers' && npm install --omit=dev --no-fund --no-audit"

log "migrate + seed…"
sudo -u "${FARM_USER}" -H bash -lc "cd '${FARM_ROOT}' && php farm migrate"
if [[ "${DO_SEED}" -eq 1 ]]; then
  sudo -u "${FARM_USER}" -H bash -lc "cd '${FARM_ROOT}' && php farm seed --accounts=0 --proxies=0 --scenarios=0 --tasks=0" || true
fi

# Dedicated PHP-FPM pool (does not replace www)
POOL="/etc/php/${PHP_VER}/fpm/pool.d/farm.conf"
cat > "${POOL}" <<EOF
[farm]
user = ${FARM_USER}
group = ${FARM_GROUP}
listen = /run/php/php${PHP_VER}-fpm-farm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 20
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
chdir = /
EOF
systemctl enable --now "php${PHP_VER}-fpm"
systemctl reload "php${PHP_VER}-fpm"

# Self-signed until certbot
if [[ ! -f /etc/ssl/farm/fullchain.pem ]]; then
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout /etc/ssl/farm/privkey.pem \
    -out /etc/ssl/farm/fullchain.pem \
    -subj "/CN=${DOMAIN}" \
    -addext "subjectAltName=DNS:${DOMAIN}" >/dev/null 2>&1 || \
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout /etc/ssl/farm/privkey.pem \
    -out /etc/ssl/farm/fullchain.pem \
    -subj "/CN=${DOMAIN}"
  chmod 600 /etc/ssl/farm/privkey.pem
fi

SOCK="/run/php/php${PHP_VER}-fpm-farm.sock"
VHOST_HTTP="/etc/apache2/sites-available/farm.1tlt.ru.conf"
VHOST_SSL="/etc/apache2/sites-available/farm.1tlt.ru-le-ssl.conf"

cat > "${VHOST_HTTP}" <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${FARM_ROOT}/public

    <Directory ${FARM_ROOT}/public>
        AllowOverride All
        Require all granted
        Options FollowSymLinks
    </Directory>

    <FilesMatch "\\.php\$">
        SetHandler "proxy:unix:${SOCK}|fcgi://localhost"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/farm_error.log
    CustomLog \${APACHE_LOG_DIR}/farm_access.log combined

    RewriteEngine on
    RewriteCond %{SERVER_NAME} =${DOMAIN}
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
EOF

cat > "${VHOST_SSL}" <<EOF
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName ${DOMAIN}
    DocumentRoot ${FARM_ROOT}/public

    <Directory ${FARM_ROOT}/public>
        AllowOverride All
        Require all granted
        Options FollowSymLinks
    </Directory>

    <FilesMatch "\\.php\$">
        SetHandler "proxy:unix:${SOCK}|fcgi://localhost"
    </FilesMatch>

    ProxyPass /live http://127.0.0.1:3000/live
    ProxyPassReverse /live http://127.0.0.1:3000/live
    ProxyPass /ws ws://127.0.0.1:3000/ws
    ProxyPassReverse /ws ws://127.0.0.1:3000/ws

    ErrorLog \${APACHE_LOG_DIR}/farm_ssl_error.log
    CustomLog \${APACHE_LOG_DIR}/farm_ssl_access.log combined

    SSLEngine on
    SSLCertificateFile /etc/ssl/farm/fullchain.pem
    SSLCertificateKeyFile /etc/ssl/farm/privkey.pem
</VirtualHost>
</IfModule>
EOF

a2ensite farm.1tlt.ru.conf >/dev/null
a2ensite farm.1tlt.ru-le-ssl.conf >/dev/null
apache2ctl configtest
systemctl reload apache2

# Systemd units — rewrite WorkingDirectory / User paths from templates
UNIT_DIR=/etc/systemd/system
for u in farm-api.service farm-scheduler.service farm-monitor.service farm-worker@.service farm-gc.service farm-gc.timer; do
  src="${FARM_ROOT}/deploy/${u}"
  [[ -f "$src" ]] || continue
  sed \
    -e "s|/opt/farm|${FARM_ROOT}|g" \
    -e "s|php8.3-fpm|php${PHP_VER}-fpm|g" \
    "$src" > "${UNIT_DIR}/${u}"
done
systemctl daemon-reload
systemctl enable --now farm-api.service farm-scheduler.service farm-monitor.service "farm-worker@1.service" farm-gc.timer

# Ambient Xvfb for headful chrome (optional shared display)
if ! systemctl cat farm-xvfb.service >/dev/null 2>&1; then
  cat > /etc/systemd/system/farm-xvfb.service <<'EOF'
[Unit]
Description=Farm ambient Xvfb :99
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/Xvfb :99 -screen 0 1920x1080x24 -ac -nolisten tcp +extension RANDR
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF
fi
systemctl enable --now farm-xvfb.service

log "try certbot (non-fatal if DNS not ready)…"
if command -v certbot >/dev/null 2>&1; then
  certbot --apache -d "${DOMAIN}" --non-interactive --agree-tos --register-unsafely-without-email --redirect 2>/dev/null \
    || log "certbot skipped/failed — run later after DNS"
fi

log "health"
curl -sk "https://127.0.0.1/api/v1/health" -H "Host: ${DOMAIN}" || true
echo
curl -s "http://127.0.0.1/api/v1/health" -H "Host: ${DOMAIN}" || true
echo
log "done. credentials: /root/.farm/install-credentials.txt"
log "admin: admin@farm.local / admin123!"
