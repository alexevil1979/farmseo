#!/usr/bin/env bash
# Farm (Project L) — one-shot install for clean Ubuntu 24.04
# Usage: sudo bash deploy/install.sh [--domain=farm.example.com] [--workers=1] [--skip-seed]
set -euo pipefail

FARM_ROOT="/opt/farm"
FARM_USER="farm"
FARM_GROUP="farm"
DOMAIN="localhost"
WORKER_COUNT=1
DO_SEED=1
MYSQL_DB="farm"
MYSQL_USER="farm"
BACKUP_CRON="15 3 * * *"

for arg in "$@"; do
  case "$arg" in
    --domain=*) DOMAIN="${arg#*=}" ;;
    --workers=*) WORKER_COUNT="${arg#*=}" ;;
    --skip-seed) DO_SEED=0 ;;
    --help|-h)
      echo "Usage: sudo bash deploy/install.sh [--domain=HOST] [--workers=N] [--skip-seed]"
      exit 0
      ;;
    *)
      echo "Unknown arg: $arg" >&2
      exit 1
      ;;
  esac
done

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash deploy/install.sh" >&2
  exit 1
fi

. /etc/os-release
if [[ "${ID:-}" != "ubuntu" ]] || [[ "${VERSION_ID:-}" != "24.04" ]]; then
  echo "WARNING: designed for Ubuntu 24.04 (got ${PRETTY_NAME:-unknown}). Continuing…" >&2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

log() { echo "[farm-install] $*"; }
die() { echo "[farm-install] ERROR: $*" >&2; exit 1; }

rand_hex() { openssl rand -hex "${1:-24}"; }
rand_b64() { openssl rand -base64 32 | tr -d '\n/=+' | head -c 48; }

# ---------------------------------------------------------------------------
# Packages
# ---------------------------------------------------------------------------
log "Installing packages…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y \
  apache2 \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-curl php8.3-mbstring \
  php8.3-xml php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
  mysql-server redis-server \
  supervisor \
  curl ca-certificates gnupg unzip git openssl rsync \
  xvfb x11vnc novnc websockify \
  fail2ban logrotate cron \
  fonts-liberation fonts-noto-color-emoji \
  libnss3 libatk-bridge2.0-0 libdrm2 libxkbcommon0 libxcomposite1 \
  libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 \
  libcairo2 libcups2 libgtk-3-0
apt-get install -y libasound2t64 2>/dev/null || apt-get install -y libasound2

# Node.js 20
if ! command -v node >/dev/null 2>&1 || [[ "$(node -v 2>/dev/null | sed 's/^v//' | cut -d. -f1)" != "20" ]]; then
  log "Installing Node.js 20…"
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi
node -v | grep -qE '^v20\.' || die "Node.js 20 required"

# Google Chrome (stable)
if ! command -v google-chrome-stable >/dev/null 2>&1 && ! command -v google-chrome >/dev/null 2>&1; then
  log "Installing Google Chrome…"
  curl -fsSL https://dl.google.com/linux/linux_signing_key.pub \
    | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg
  echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" \
    > /etc/apt/sources.list.d/google-chrome.list
  apt-get update -y
  apt-get install -y google-chrome-stable
fi
CHROME_BIN="$(command -v google-chrome-stable || command -v google-chrome || true)"
[[ -n "${CHROME_BIN}" ]] || die "Chrome binary not found"

# Composer
if ! command -v composer >/dev/null 2>&1; then
  log "Installing Composer…"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

# Apache modules
a2enmod proxy proxy_http proxy_wstunnel rewrite headers ssl setenvif 2>/dev/null || true
a2enmod proxy_fcgi 2>/dev/null || true
a2enconf php8.3-fpm 2>/dev/null || true

systemctl enable --now mysql redis-server php8.3-fpm apache2 supervisor cron fail2ban

# ---------------------------------------------------------------------------
# User + directories
# ---------------------------------------------------------------------------
log "Creating user/group and ${FARM_ROOT}…"
if ! getent group "${FARM_GROUP}" >/dev/null; then
  groupadd --system "${FARM_GROUP}"
fi
if ! id -u "${FARM_USER}" >/dev/null 2>&1; then
  useradd --system --gid "${FARM_GROUP}" --home-dir "${FARM_ROOT}" \
    --shell /usr/sbin/nologin --create-home "${FARM_USER}"
fi
usermod -aG "${FARM_GROUP}" www-data 2>/dev/null || true

mkdir -p "${FARM_ROOT}"
if [[ "${SRC_ROOT}" != "${FARM_ROOT}" ]]; then
  log "Syncing tree ${SRC_ROOT} → ${FARM_ROOT}…"
  rsync -a --delete \
    --exclude '.git/' \
    --exclude 'vendor/' \
    --exclude 'workers/node_modules/' \
    --exclude 'storage/profiles/*/' \
    --exclude 'storage/screenshots/*' \
    --exclude 'storage/cookies/*' \
    --exclude 'storage/logs/*' \
    --exclude '.env' \
    --exclude '.phpunit.cache/' \
    "${SRC_ROOT}/" "${FARM_ROOT}/"
fi

mkdir -p \
  "${FARM_ROOT}/storage/logs" \
  "${FARM_ROOT}/storage/screenshots" \
  "${FARM_ROOT}/storage/profiles" \
  "${FARM_ROOT}/storage/cookies" \
  /var/backups/farm \
  /var/log/farm

# Web-accessible screenshots (admin panel /storage/screenshots/...)
ln -sfn "${FARM_ROOT}/storage" "${FARM_ROOT}/public/storage"

chown -R "${FARM_USER}:${FARM_GROUP}" "${FARM_ROOT}"
chmod 750 "${FARM_ROOT}"
chmod -R u+rwX,g+rX,o-rwx "${FARM_ROOT}/storage"
# Apache (www-data) must read PNGs via public/storage symlink
chmod o+rX "${FARM_ROOT}/storage" "${FARM_ROOT}/storage/screenshots" || true
find "${FARM_ROOT}/storage/screenshots" -type d -exec chmod o+rx {} \; 2>/dev/null || true
find "${FARM_ROOT}/storage/screenshots" -type f -name '*.png' -exec chmod o+r {} \; 2>/dev/null || true
chmod 755 "${FARM_ROOT}/public"
chmod +x "${FARM_ROOT}/farm" "${FARM_ROOT}/src/Console/farm" \
  "${FARM_ROOT}/deploy/install.sh" "${FARM_ROOT}/deploy/backup.sh" 2>/dev/null || true

# ---------------------------------------------------------------------------
# MySQL
# ---------------------------------------------------------------------------
log "Configuring MySQL database and user…"
MYSQL_PASS="$(rand_b64)"
# Ensure mysql is ready
for _ in $(seq 1 30); do
  mysqladmin ping --silent && break
  sleep 1
done

mysql -e "CREATE DATABASE IF NOT EXISTS \`${MYSQL_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'localhost' IDENTIFIED BY '${MYSQL_PASS}';"
mysql -e "ALTER USER '${MYSQL_USER}'@'localhost' IDENTIFIED BY '${MYSQL_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${MYSQL_DB}\`.* TO '${MYSQL_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# ---------------------------------------------------------------------------
# Redis (optional password)
# ---------------------------------------------------------------------------
REDIS_PASS=""
if grep -qE '^requirepass ' /etc/redis/redis.conf 2>/dev/null; then
  REDIS_PASS="$(awk '/^requirepass /{print $2; exit}' /etc/redis/redis.conf)"
fi
systemctl restart redis-server

# ---------------------------------------------------------------------------
# .env (mode 600)
# ---------------------------------------------------------------------------
log "Writing ${FARM_ROOT}/.env…"
INTERNAL_TOKEN="$(rand_hex 32)"
APP_KEY="$(rand_hex 32)"
API_URL="https://${DOMAIN}"
if [[ "${DOMAIN}" == "localhost" ]]; then
  API_URL="http://127.0.0.1"
fi

if [[ -f "${FARM_ROOT}/.env" ]]; then
  log ".env exists — preserving secrets, refreshing paths only"
else
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
IPINFO_TOKEN=
PROXY_SLOW_MS=2000
PROXY_CHECK_TIMEOUT=12
PROXY_BAD_ASN=
TG_TOKEN=
TG_CHAT_ID=
TG_DEDUPE_SEC=300
MONITOR_INTERVAL_SEC=15
MONITOR_PROXY_DEAD_PCT=20
MONITOR_CAPTCHA_WINDOW_SEC=300
MONITOR_CAPTCHA_THRESHOLD=5
MONITOR_DISK_PCT=95
CAPTCHA_PROVIDER=2captcha
CAPTCHA_KEY=
CAPTCHA_TOKEN_URL=
SMS_PROVIDER=sms-activate
SMS_KEY=
SMS_API_URL=
SMS_SERVICE=ya
SMS_COUNTRY=0
SMS_RENT_MINUTES=20
SMS_POLL_INTERVAL_SEC=5
SMS_WAIT_TIMEOUT_SEC=180
MAX_CHROME=40
WORKER_SLOTS=40
WORKER_HTTP_PORT=3000
CHROME_HEADLESS=false
CHROME_PATH=${CHROME_BIN}
PUPPETEER_EXECUTABLE_PATH=${CHROME_BIN}
CHROME_EXTRA_ARGS=
VNC_BASE_PORT=5900
WEBSOCKIFY_BASE_PORT=6080
DISPLAY_BASE=99
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
EOF
fi

chown "${FARM_USER}:${FARM_GROUP}" "${FARM_ROOT}/.env"
chmod 600 "${FARM_ROOT}/.env"

# Credentials note (root-only)
install -d -m 700 /root/.farm
cat > /root/.farm/install-credentials.txt <<EOF
# Generated $(date -u +%Y-%m-%dT%H:%M:%SZ) — mode 600, root only
DB_DATABASE=${MYSQL_DB}
DB_USERNAME=${MYSQL_USER}
DB_PASSWORD=${MYSQL_PASS}
INTERNAL_TOKEN=${INTERNAL_TOKEN}
API_URL=${API_URL}
DOMAIN=${DOMAIN}
EOF
chmod 600 /root/.farm/install-credentials.txt
# Re-read password if .env was preserved
if [[ -f "${FARM_ROOT}/.env" ]]; then
  # shellcheck disable=SC1090
  set -a; source <(grep -E '^(DB_|INTERNAL_TOKEN|API_URL)=' "${FARM_ROOT}/.env" | sed 's/\r$//'); set +a
fi

# ---------------------------------------------------------------------------
# Composer + npm + worker
# ---------------------------------------------------------------------------
log "composer install…"
sudo -u "${FARM_USER}" -H bash -lc "cd '${FARM_ROOT}' && composer install --no-dev --optimize-autoloader --no-interaction"

log "npm install (workers)…"
sudo -u "${FARM_USER}" -H bash -lc "cd '${FARM_ROOT}/workers' && npm install --omit=dev"

# ---------------------------------------------------------------------------
# Migrations (+ optional seed)
# ---------------------------------------------------------------------------
log "Running migrations…"
sudo -u "${FARM_USER}" -H bash -lc "cd '${FARM_ROOT}' && php farm migrate"
if [[ "${DO_SEED}" -eq 1 ]]; then
  log "Seeding roles/admin (demo, idempotent)…"
  sudo -u "${FARM_USER}" -H bash -lc "cd '${FARM_ROOT}' && php farm seed --accounts=0 --proxies=0 --scenarios=0 --tasks=0" || true
fi

# ---------------------------------------------------------------------------
# PHP-FPM pool tweak
# ---------------------------------------------------------------------------
PHP_POOL="/etc/php/8.3/fpm/pool.d/farm.conf"
cat > "${PHP_POOL}" <<EOF
[farm]
user = ${FARM_USER}
group = ${FARM_GROUP}
listen = /run/php/php8.3-fpm-farm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 32
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500
env[APP_ENV] = production
clear_env = no
php_admin_value[error_log] = /var/log/farm/php-fpm.log
php_admin_flag[log_errors] = on
EOF
systemctl restart php8.3-fpm

# ---------------------------------------------------------------------------
# Apache / HTTPS vhost
# ---------------------------------------------------------------------------
log "Configuring Apache vhost…"
VHOST_SRC="${FARM_ROOT}/deploy/nginx-or-apache.conf"
VHOST_DST="/etc/apache2/sites-available/farm.conf"
# Render ServerName + paths
sed \
  -e "s|__DOMAIN__|${DOMAIN}|g" \
  -e "s|__FARM_ROOT__|${FARM_ROOT}|g" \
  -e "s|__PHP_SOCK__|/run/php/php8.3-fpm-farm.sock|g" \
  "${VHOST_SRC}" > "${VHOST_DST}"
if [[ "${DOMAIN}" != "localhost" && "${DOMAIN}" != "127.0.0.1" ]]; then
  # Inject HTTP→HTTPS redirect after ServerAdmin line in *:80 vhost
  python3 - "${VHOST_DST}" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding="utf-8").read()
needle = "    # __HTTP_TO_HTTPS__ (install.sh enables redirect for real domains)\n"
inject = """    # HTTP→HTTPS (ACME challenge exempt)
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [R=301,L]

"""
if needle in text:
    open(path, "w", encoding="utf-8").write(text.replace(needle, inject, 1))
PY
fi

# Self-signed cert if none for domain
CERT_DIR="/etc/ssl/farm"
mkdir -p "${CERT_DIR}"
if [[ ! -f "${CERT_DIR}/fullchain.pem" ]]; then
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout "${CERT_DIR}/privkey.pem" \
    -out "${CERT_DIR}/fullchain.pem" \
    -subj "/CN=${DOMAIN}" \
    -addext "subjectAltName=DNS:${DOMAIN},DNS:localhost,IP:127.0.0.1"
  chmod 600 "${CERT_DIR}/privkey.pem"
fi

a2dissite 000-default.conf 2>/dev/null || true
a2ensite farm.conf
systemctl reload apache2

# ---------------------------------------------------------------------------
# Supervisor (installed; systemd is primary for farm-* units)
# ---------------------------------------------------------------------------
cat > /etc/supervisor/conf.d/farm-worker.conf <<EOF
; Optional alternate process manager. Prefer systemd farm-worker@.service.
[program:farm-worker]
command=/usr/bin/node ${FARM_ROOT}/workers/index.js
directory=${FARM_ROOT}/workers
user=${FARM_USER}
autostart=false
autorestart=true
stopwaitsecs=30
environment=HOME="${FARM_ROOT}",NODE_ENV="production"
stdout_logfile=/var/log/farm/supervisor-worker.log
stderr_logfile=/var/log/farm/supervisor-worker.err.log
EOF
supervisorctl reread >/dev/null || true
supervisorctl update >/dev/null || true

# ---------------------------------------------------------------------------
# systemd units
# ---------------------------------------------------------------------------
log "Installing systemd units…"
install -m 644 "${FARM_ROOT}/deploy/farm-api.service" /etc/systemd/system/farm-api.service
install -m 644 "${FARM_ROOT}/deploy/farm-scheduler.service" /etc/systemd/system/farm-scheduler.service
install -m 644 "${FARM_ROOT}/deploy/farm-worker@.service" /etc/systemd/system/farm-worker@.service
install -m 644 "${FARM_ROOT}/deploy/farm-monitor.service" /etc/systemd/system/farm-monitor.service
install -m 644 "${FARM_ROOT}/deploy/farm-gc.service" /etc/systemd/system/farm-gc.service
install -m 644 "${FARM_ROOT}/deploy/farm-gc.timer" /etc/systemd/system/farm-gc.timer
systemctl daemon-reload

systemctl enable farm-api.service farm-scheduler.service farm-monitor.service farm-gc.timer
systemctl enable "farm-worker@1.service"
for i in $(seq 2 "${WORKER_COUNT}"); do
  systemctl enable "farm-worker@${i}.service" || true
done

systemctl restart farm-api.service
systemctl restart farm-scheduler.service
systemctl restart farm-monitor.service
systemctl restart farm-gc.timer
systemctl restart farm-worker@1.service
for i in $(seq 2 "${WORKER_COUNT}"); do
  systemctl restart "farm-worker@${i}.service" || true
done

# ---------------------------------------------------------------------------
# fail2ban
# ---------------------------------------------------------------------------
log "Configuring fail2ban…"
install -m 644 "${FARM_ROOT}/deploy/fail2ban-farm.conf" /etc/fail2ban/jail.d/farm.conf
# filter
cat > /etc/fail2ban/filter.d/farm-api.conf <<'EOF'
[Definition]
failregex = ^<HOST> .* "(POST|GET|PUT|PATCH|DELETE) /api/v1/auth/login.*" (401|403|429)
            ^<HOST> .* ".*" 401
ignoreregex =
EOF
systemctl restart fail2ban

# ---------------------------------------------------------------------------
# Backup cron
# ---------------------------------------------------------------------------
log "Installing backup cron…"
install -m 755 "${FARM_ROOT}/deploy/backup.sh" /usr/local/sbin/farm-backup
cat > /etc/cron.d/farm-backup <<EOF
# Farm daily backup (mysqldump + profiles), 7-day rotation
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${BACKUP_CRON} root /usr/local/sbin/farm-backup >> /var/log/farm/backup.log 2>&1
EOF
chmod 644 /etc/cron.d/farm-backup

# Logrotate
cat > /etc/logrotate.d/farm <<'EOF'
/var/log/farm/*.log {
  weekly
  rotate 8
  compress
  missingok
  notifempty
  copytruncate
}
EOF

# ---------------------------------------------------------------------------
# Smoke checks
# ---------------------------------------------------------------------------
log "Smoke checks…"
wait_active() {
  local u="$1" n="${2:-30}"
  for _ in $(seq 1 "${n}"); do
    systemctl is-active --quiet "${u}" && return 0
    sleep 1
  done
  return 1
}
wait_active farm-api.service 15 || die "farm-api not active"
wait_active farm-scheduler.service 30 || die "farm-scheduler not active"
wait_active farm-monitor.service 30 || die "farm-monitor not active"
wait_active farm-worker@1.service 60 || die "farm-worker@1 not active"
wait_active farm-gc.timer 15 || die "farm-gc.timer not active"
wait_active php8.3-fpm 15 || die "php8.3-fpm not active"
wait_active apache2 15 || die "apache2 not active"
wait_active redis-server 15 || die "redis-server not active"
wait_active mysql 15 || die "mysql not active"

# One-shot backup to verify rotation path
/usr/local/sbin/farm-backup || die "backup.sh failed"

log "OK — Farm installed at ${FARM_ROOT}"
log "Credentials: /root/.farm/install-credentials.txt"
log "Panel/API: ${API_URL}"
log "Check: systemctl status farm-api farm-scheduler farm-monitor 'farm-worker@1' farm-gc.timer"
log "Docs: ${FARM_ROOT}/docs/"
