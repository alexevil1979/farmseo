#!/usr/bin/env bash
# Farm E2E entrypoint — no install.sh, no systemd.
# Prepares fixed .env, waits for supervisor-managed ports, migrates, marks ready.
set -euo pipefail

FARM_ROOT="${FARM_ROOT:-/opt/farm}"
ENV_FILE="${FARM_ROOT}/.env"
READY_MARK=/var/lib/farm-e2e/ready
LOG=/var/log/farm/entrypoint.log

mkdir -p /var/log/farm /var/lib/farm-e2e /var/run/mysqld /run/php \
  /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
  "${FARM_ROOT}/storage/logs" \
  "${FARM_ROOT}/storage/screenshots" \
  "${FARM_ROOT}/storage/profiles" \
  "${FARM_ROOT}/storage/cookies"
chown mysql:mysql /var/run/mysqld
chown www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2
chown -R farm:farm "${FARM_ROOT}/storage" /var/log/farm

log() { echo "[farm-e2e] $*" | tee -a "${LOG}"; }

# ---------------------------------------------------------------------------
# Deterministic secrets for reproducible runs (RANDOM_SEED drives RNG separately)
# ---------------------------------------------------------------------------
CHROME_BIN="$(command -v google-chrome-stable || command -v chromium-browser || command -v chromium || true)"
: "${CHROME_BIN:=/usr/bin/google-chrome-stable}"
: "${CHROME_EXTRA_ARGS:=--no-sandbox --disable-dev-shm-usage --disable-gpu}"
: "${MAX_CHROME:=2}"
: "${GEO_PROVIDER:=stub}"
: "${RANDOM_SEED:=42}"
: "${DISPLAY:=:99}"
: "${DB_PASSWORD:=farm_e2e_fixed_pass}"
: "${INTERNAL_TOKEN:=e2e_internal_token_fixed_0123456789abcdef}"
: "${APP_KEY:=e2e_app_key_fixed_0123456789abcdef01234567}"

if [[ ! -f "${ENV_FILE}" ]]; then
  log "writing ${ENV_FILE}"
  cat > "${ENV_FILE}" <<EOF
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=farm
DB_USERNAME=farm
DB_PASSWORD=${DB_PASSWORD}
DB_TEST_DATABASE=farm_test

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
REDIS_TIMEOUT=2.0

APP_KEY=${APP_KEY}
API_URL=http://127.0.0.1
INTERNAL_TOKEN=${INTERNAL_TOKEN}
IPINFO_TOKEN=
GEO_PROVIDER=${GEO_PROVIDER}
RANDOM_SEED=${RANDOM_SEED}
PROXY_SLOW_MS=2000
PROXY_CHECK_TIMEOUT=12
PROXY_BAD_ASN=
TG_TOKEN=
TG_CHAT_ID=
TG_DEDUPE_SEC=300
MONITOR_INTERVAL_SEC=5
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
MAX_CHROME=${MAX_CHROME}
WORKER_SLOTS=${MAX_CHROME}
WORKER_HTTP_PORT=3000
CHROME_HEADLESS=false
CHROME_PATH=${CHROME_BIN}
PUPPETEER_EXECUTABLE_PATH=${CHROME_BIN}
CHROME_EXTRA_ARGS=${CHROME_EXTRA_ARGS}
# Session stacks start at 5910/6090 — ambient x11vnc/websockify keep 5900/6080.
VNC_BASE_PORT=5910
WEBSOCKIFY_BASE_PORT=6090
DISPLAY=${DISPLAY}
DISPLAY_BASE=100
XVFB_SCREEN=1920x1080x24
NOVNC_WEB_PATH=/usr/share/novnc
LIVE_VIEW_IDLE_SEC=600
WORKER_URL=http://127.0.0.1:3000
RESULT_URL=http://127.0.0.1/internal/task/result
QUEUE_BRPOP_TIMEOUT=5
RECLAIM_INTERVAL_MS=5000
RECLAIM_STALE_SEC=20
ACCOUNT_LOCK_TTL=600
PROXY_LOCK_TTL=120
KEEP_OPEN_MIN_MS=500
KEEP_OPEN_MAX_MS=1500
GOVERNOR_CPU_PCT=80
GOVERNOR_RAM_PCT=90
NIGHT_WINDOW_START=23:00
NIGHT_WINDOW_END=07:00
RETRY_BASE_SEC=30
RETRY_MAX_SEC=900
SCHED_ENQUEUE_BATCH=50
GC_STALE_GRACE_SEC=15
GC_KILL_ORPHAN_CHROME=1
EOF
else
  # Refresh e2e knobs from environment on every boot
  for kv in \
    "CHROME_EXTRA_ARGS=${CHROME_EXTRA_ARGS}" \
    "MAX_CHROME=${MAX_CHROME}" \
    "WORKER_SLOTS=${MAX_CHROME}" \
    "GEO_PROVIDER=${GEO_PROVIDER}" \
    "RANDOM_SEED=${RANDOM_SEED}" \
    "DISPLAY=${DISPLAY}" \
    "DISPLAY_BASE=100" \
    "VNC_BASE_PORT=5910" \
    "WEBSOCKIFY_BASE_PORT=6090" \
    "RECLAIM_STALE_SEC=20" \
    "RECLAIM_INTERVAL_MS=5000" \
    "GC_STALE_GRACE_SEC=15" \
    "MONITOR_INTERVAL_SEC=5" \
    "KEEP_OPEN_MIN_MS=500" \
    "KEEP_OPEN_MAX_MS=1500" \
    "API_URL=http://127.0.0.1" \
    "CHROME_PATH=${CHROME_BIN}" \
    "PUPPETEER_EXECUTABLE_PATH=${CHROME_BIN}"
  do
    key="${kv%%=*}"
    if grep -qE "^${key}=" "${ENV_FILE}"; then
      sed -i "s|^${key}=.*|${kv}|" "${ENV_FILE}"
    else
      echo "${kv}" >> "${ENV_FILE}"
    fi
  done
fi
chown farm:farm "${ENV_FILE}"
chmod 600 "${ENV_FILE}"

# Sync live sources if mounted (dev loop) — never call install.sh
if [[ -d /src/workers ]]; then
  rsync -a --delete --exclude node_modules --exclude '.git' /src/workers/ "${FARM_ROOT}/workers/" || true
  if [[ ! -d "${FARM_ROOT}/workers/node_modules" && -f "${FARM_ROOT}/workers/package.json" ]]; then
    log "npm install (workers) after sync"
    sudo -u farm -H bash -lc "cd '${FARM_ROOT}/workers' && PUPPETEER_SKIP_DOWNLOAD=true npm install --omit=dev --no-fund --no-audit"
  fi
fi
if [[ -d /src/src ]]; then
  rsync -a /src/src/ "${FARM_ROOT}/src/" || true
fi
if [[ -f /src/scenarios/e2e-warmup.json ]]; then
  install -m 644 /src/scenarios/e2e-warmup.json "${FARM_ROOT}/scenarios/e2e-warmup.json"
fi
if [[ -f /src/e2e/assert.php ]]; then
  install -m 644 /src/e2e/assert.php "${FARM_ROOT}/e2e-assert.php"
fi
if [[ -f /src/e2e/geo-fixtures.json ]]; then
  mkdir -p "${FARM_ROOT}/e2e"
  install -m 644 /src/e2e/geo-fixtures.json "${FARM_ROOT}/e2e/geo-fixtures.json"
fi
# Do NOT chown -R workers/ (node_modules is huge and stalls on WSL overlay).
chown -R farm:farm "${FARM_ROOT}/src" "${FARM_ROOT}/scenarios" "${FARM_ROOT}/e2e" 2>/dev/null || true
chown farm:farm "${FARM_ROOT}/e2e-assert.php" 2>/dev/null || true
if [[ -d "${FARM_ROOT}/workers" ]]; then
  find "${FARM_ROOT}/workers" \( -path '*/node_modules' -o -path '*/.git' \) -prune -o \
    \( -exec chown farm:farm {} + \) 2>/dev/null || true
fi

# Ensure MySQL datadir exists (package usually initializes it)
if [[ ! -d /var/lib/mysql/mysql ]]; then
  log "initializing MySQL datadir"
  mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql
fi

rm -f "${READY_MARK}"

wait_port() {
  local host="$1" port="$2" name="$3" tries="${4:-60}"
  local i
  for i in $(seq 1 "${tries}"); do
    if bash -c "echo >/dev/tcp/${host}/${port}" 2>/dev/null; then
      log "${name} ready on ${host}:${port}"
      return 0
    fi
    sleep 1
  done
  log "ERROR: timeout waiting for ${name} (${host}:${port})"
  return 1
}

wait_sock() {
  local sock="$1" name="$2" tries="${3:-60}"
  local i
  for i in $(seq 1 "${tries}"); do
    if [[ -S "${sock}" ]]; then
      log "${name} socket ready: ${sock}"
      return 0
    fi
    sleep 1
  done
  log "ERROR: timeout waiting for ${name} socket"
  return 1
}

bootstrap_db() {
  log "waiting for MySQL…"
  local i
  for i in $(seq 1 90); do
    if mysqladmin --socket=/var/run/mysqld/mysqld.sock ping --silent 2>/dev/null \
       || mysqladmin ping --silent 2>/dev/null; then
      break
    fi
    sleep 1
  done
  mysqladmin --socket=/var/run/mysqld/mysqld.sock ping --silent 2>/dev/null \
    || mysqladmin ping --silent \
    || { log "ERROR: MySQL not up"; return 1; }

  mysql --socket=/var/run/mysqld/mysqld.sock -e \
    "CREATE DATABASE IF NOT EXISTS \`farm\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER IF NOT EXISTS 'farm'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
     ALTER USER 'farm'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
     GRANT ALL PRIVILEGES ON \`farm\`.* TO 'farm'@'localhost';
     FLUSH PRIVILEGES;" 2>/dev/null \
  || mysql -e \
    "CREATE DATABASE IF NOT EXISTS \`farm\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER IF NOT EXISTS 'farm'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
     ALTER USER 'farm'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
     GRANT ALL PRIVILEGES ON \`farm\`.* TO 'farm'@'localhost';
     FLUSH PRIVILEGES;"

  log "migrate + seed"
  sudo -u farm -H bash -lc "cd '${FARM_ROOT}' && php farm migrate"
  sudo -u farm -H bash -lc "cd '${FARM_ROOT}' && php farm seed --accounts=0 --proxies=0 --scenarios=0 --tasks=0" || true
}

# If CMD is supervisord — start it in background, bootstrap, mark ready, wait
if [[ "${1:-}" == *supervisord* ]] || [[ "${1:-}" == "/usr/bin/supervisord" ]]; then
  log "starting supervisord…"
  "$@" &
  SUP_PID=$!

  wait_port 127.0.0.1 3306 mysql 90 || { kill "${SUP_PID}" 2>/dev/null || true; exit 1; }
  wait_port 127.0.0.1 6379 redis 60 || { kill "${SUP_PID}" 2>/dev/null || true; exit 1; }
  bootstrap_db || { kill "${SUP_PID}" 2>/dev/null || true; exit 1; }

  # PHP daemons need schema — start only after migrate.
  # Do NOT use `supervisorctl status` as readiness: it exits non-zero while
  # autostart=false programs are STOPPED.
  log "starting farm-scheduler + farm-monitor"
  for i in $(seq 1 30); do
    if [[ -S /var/run/supervisor.sock ]]; then
      break
    fi
    sleep 1
  done
  if [[ -S /var/run/supervisor.sock ]]; then
    supervisorctl -c /etc/supervisor/supervisord.conf start farm-scheduler || true
    supervisorctl -c /etc/supervisor/supervisord.conf start farm-monitor || true
  else
    log "WARN: supervisor.sock missing — scheduler/monitor not started"
  fi

  wait_sock /run/php/php8.3-fpm-farm.sock php-fpm 60 \
    || wait_sock /run/php/php8.3-fpm.sock php-fpm-www 60 \
    || true
  wait_port 127.0.0.1 80 apache 60 || { kill "${SUP_PID}" 2>/dev/null || true; exit 1; }
  wait_port 127.0.0.1 3000 worker 90 || { kill "${SUP_PID}" 2>/dev/null || true; exit 1; }

  # Health endpoint
  for i in $(seq 1 30); do
    if curl -sf http://127.0.0.1/api/v1/health >/dev/null 2>&1; then
      break
    fi
    sleep 1
  done
  curl -sf http://127.0.0.1/api/v1/health >/dev/null || log "WARN: /api/v1/health not green yet"

  date -u +%Y-%m-%dT%H:%M:%SZ > "${READY_MARK}"
  log "ready (RANDOM_SEED=${RANDOM_SEED} GEO_PROVIDER=${GEO_PROVIDER} MAX_CHROME=${MAX_CHROME})"
  wait "${SUP_PID}"
  exit $?
fi

# Non-supervisord CMD: still ensure Xvfb ambient display if requested
if ! pgrep -x Xvfb >/dev/null 2>&1; then
  log "starting ambient Xvfb ${DISPLAY}"
  Xvfb "${DISPLAY}" -screen 0 1920x1080x24 -ac -nolisten tcp +extension RANDR &
  sleep 1
fi

exec "$@"
