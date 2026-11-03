#!/usr/bin/env bash
# Farm backup: mysqldump + tar of browser profiles. Retention: 7 days.
# Cron: /etc/cron.d/farm-backup (installed by deploy/install.sh)
set -euo pipefail

FARM_ROOT="${FARM_ROOT:-/opt/farm}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/farm}"
KEEP_DAYS="${KEEP_DAYS:-7}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORKDIR="${BACKUP_ROOT}/${STAMP}"
ENV_FILE="${FARM_ROOT}/.env"

log() { echo "[farm-backup $(date -u +%H:%M:%S)] $*"; }
die() { echo "[farm-backup] ERROR: $*" >&2; exit 1; }

[[ -f "${ENV_FILE}" ]] || die "missing ${ENV_FILE}"
[[ -r "${ENV_FILE}" ]] || die "cannot read ${ENV_FILE}"

# shellcheck disable=SC1090
set -a
# shellcheck source=/dev/null
source <(grep -E '^(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' "${ENV_FILE}" | sed 's/\r$//')
set +a

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-farm}"
DB_USERNAME="${DB_USERNAME:-farm}"
DB_PASSWORD="${DB_PASSWORD:-}"

mkdir -p "${WORKDIR}"
chmod 700 "${BACKUP_ROOT}"
chmod 700 "${WORKDIR}"

log "Dumping MySQL ${DB_DATABASE}…"
export MYSQL_PWD="${DB_PASSWORD}"
mysqldump \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --user="${DB_USERNAME}" \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "${DB_DATABASE}" \
  | gzip -c > "${WORKDIR}/farm-db.sql.gz"
unset MYSQL_PWD
chmod 600 "${WORKDIR}/farm-db.sql.gz"

PROFILES="${FARM_ROOT}/storage/profiles"
COOKIES="${FARM_ROOT}/storage/cookies"
log "Archiving profiles + cookies…"
tar -C "${FARM_ROOT}/storage" -czf "${WORKDIR}/profiles-cookies.tar.gz" \
  --exclude='SingletonLock' \
  --exclude='SingletonCookie' \
  --exclude='SingletonSocket' \
  --exclude='DevToolsActivePort' \
  profiles cookies 2>/dev/null \
  || tar -czf "${WORKDIR}/profiles-cookies.tar.gz" -T /dev/null
chmod 600 "${WORKDIR}/profiles-cookies.tar.gz"

# Manifest
cat > "${WORKDIR}/MANIFEST.txt" <<EOF
stamp=${STAMP}
host=$(hostname -f 2>/dev/null || hostname)
db=${DB_DATABASE}
profiles=${PROFILES}
cookies=${COOKIES}
db_bytes=$(stat -c%s "${WORKDIR}/farm-db.sql.gz" 2>/dev/null || echo 0)
profiles_bytes=$(stat -c%s "${WORKDIR}/profiles-cookies.tar.gz" 2>/dev/null || echo 0)
EOF
chmod 600 "${WORKDIR}/MANIFEST.txt"

# Rotate: keep last KEEP_DAYS days of stamped dirs
log "Rotating backups older than ${KEEP_DAYS} days…"
find "${BACKUP_ROOT}" -mindepth 1 -maxdepth 1 -type d -mtime "+${KEEP_DAYS}" -print -exec rm -rf {} +

# Also prune orphaned loose files
find "${BACKUP_ROOT}" -mindepth 1 -maxdepth 1 -type f -mtime "+${KEEP_DAYS}" -delete 2>/dev/null || true

log "OK → ${WORKDIR}"
ls -lah "${WORKDIR}"
