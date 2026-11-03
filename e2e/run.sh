#!/usr/bin/env bash
# Farm E2E — reproducible business-logic run with real Chrome.
# No install.sh. Wall-clock budget 10 minutes. Always tears down volumes.
#
#   bash e2e/run.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${ROOT}/e2e/docker-compose.yml"
COMPOSE=(docker compose -f "${COMPOSE_FILE}")
WALL_SEC=600
REPORT_DIR="${ROOT}/e2e/out"
FARM_SERVICE=farm
START_EPOCH="$(date +%s)"
CLEANED=0

log() { echo "[e2e] $*"; }
die() { echo "[e2e] ERROR: $*" >&2; exit 1; }

need_cmd() { command -v "$1" >/dev/null 2>&1 || die "missing command: $1"; }
need_cmd docker
docker compose version >/dev/null 2>&1 || die "docker compose plugin required"

# Propagate WSL host flag into assert.php (container may not see Microsoft /proc/version)
if [[ -r /proc/version ]] && grep -qi microsoft /proc/version; then
  export E2E_HOST_WSL=1
elif [[ -n "${WSL_DISTRO_NAME:-}" ]]; then
  export E2E_HOST_WSL=1
fi

mkdir -p "${REPORT_DIR}"

remaining() {
  local now elapsed left
  now="$(date +%s)"
  elapsed=$((now - START_EPOCH))
  left=$((WALL_SEC - elapsed))
  if (( left < 1 )); then
    die "wall-clock timeout (${WALL_SEC}s) exceeded"
  fi
  echo "${left}"
}

check_budget() {
  local left
  left="$(remaining)"
  log "budget_left=${left}s — $*"
}

cleanup() {
  if [[ "${CLEANED}" -eq 1 ]]; then
    return 0
  fi
  CLEANED=1
  log "cleanup: docker compose down -v"
  "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

farm_exec() {
  "${COMPOSE[@]}" exec -T "${FARM_SERVICE}" "$@"
}

farm_exec_root() {
  "${COMPOSE[@]}" exec -T -u root "${FARM_SERVICE}" "$@"
}

wait_ready() {
  local left tries i
  left="$(remaining)"
  tries=$(( left < 120 ? left : 120 ))
  log "waiting for farm ready (≤${tries}s)…"
  for i in $(seq 1 "${tries}"); do
    remaining >/dev/null
    if "${COMPOSE[@]}" exec -T "${FARM_SERVICE}" \
         bash -lc 'test -f /var/lib/farm-e2e/ready && curl -sf http://127.0.0.1/api/v1/health >/dev/null' \
         2>/dev/null; then
      log "farm is ready"
      return 0
    fi
    sleep 2
  done
  "${COMPOSE[@]}" logs --tail=80 "${FARM_SERVICE}" || true
  die "timeout waiting for farm ready"
}

wait_tcp() {
  local service="$1" host="$2" port="$3" label="$4"
  local left tries i
  left="$(remaining)"
  tries=$(( left < 60 ? left : 60 ))
  log "waiting for ${label} (${service} ${host}:${port})…"
  for i in $(seq 1 "${tries}"); do
    remaining >/dev/null
    case "${service}" in
      site)
        # nginx:alpine — no bash; use wget healthz
        if "${COMPOSE[@]}" exec -T site wget -qO- "http://${host}/healthz" 2>/dev/null | grep -q ok; then
          log "${label} ready"
          return 0
        fi
        ;;
      proxy)
        # tinyproxy alpine — container Up is enough (checked by caller); optional wget
        if "${COMPOSE[@]}" exec -T proxy wget -qO- "http://${host}:${port}/" >/dev/null 2>&1 \
           || "${COMPOSE[@]}" ps proxy --format '{{.Status}}' 2>/dev/null | grep -qiE 'up|running|healthy'; then
          log "${label} ready"
          return 0
        fi
        ;;
      *)
        # Ubuntu farm image has bash
        if "${COMPOSE[@]}" exec -T "${service}" \
             bash -lc "echo >/dev/tcp/${host}/${port}" >/dev/null 2>&1; then
          log "${label} ready"
          return 0
        fi
        ;;
    esac
    sleep 1
  done
  die "timeout waiting for ${label}"
}

# ---------------------------------------------------------------------------
# 0) Clean slate + up
# ---------------------------------------------------------------------------
check_budget "compose down -v (clean slate)"
"${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true

check_budget "compose up --build -d"
"${COMPOSE[@]}" up -d --build

check_budget "site readiness"
wait_tcp site 127.0.0.1 80 site

check_budget "proxy readiness"
wait_tcp proxy 127.0.0.1 8888 proxy

check_budget "farm readiness"
wait_ready

# Sync latest scenario/assert (bind-mounted /src)
farm_exec_root bash -lc '
  set -e
  if [[ -f /src/scenarios/e2e-warmup.json ]]; then
    install -m 644 /src/scenarios/e2e-warmup.json /opt/farm/scenarios/e2e-warmup.json
  fi
  if [[ -f /src/e2e/assert.php ]]; then
    install -m 644 /src/e2e/assert.php /opt/farm/e2e-assert.php
  fi
  rsync -a --exclude node_modules /src/workers/ /opt/farm/workers/ 2>/dev/null || true
  rsync -a /src/src/ /opt/farm/src/ 2>/dev/null || true
  chown -R farm:farm /opt/farm/workers /opt/farm/src /opt/farm/scenarios /opt/farm/e2e-assert.php 2>/dev/null || true
  # Ensure seed + chrome flags
  grep -qE "^RANDOM_SEED=" /opt/farm/.env || echo "RANDOM_SEED=42" >> /opt/farm/.env
  grep -qE "^GEO_PROVIDER=" /opt/farm/.env || echo "GEO_PROVIDER=stub" >> /opt/farm/.env
  supervisorctl restart farm-worker >/dev/null 2>&1 || true
  sleep 2
'

# ---------------------------------------------------------------------------
# 1) API token
# ---------------------------------------------------------------------------
check_budget "issue API token"
farm_exec bash -lc '
  set -e
  cd /opt/farm
  sudo -u farm php farm seed --accounts=0 --proxies=0 --scenarios=0 --tasks=0 >/dev/null 2>&1 || true
  OUT=$(sudo -u farm php farm token:issue --email=admin@farm.local --name=e2e 2>&1)
  TOKEN=$(echo "$OUT" | sed -n "s/^token: //p" | head -n1 | tr -d "\r")
  [[ -n "$TOKEN" ]] || { echo "$OUT"; exit 1; }
  echo -n "$TOKEN" > /tmp/e2e.token
  echo "token ok"
'

# ---------------------------------------------------------------------------
# 2) Proxy + 3 accounts + scenario
# ---------------------------------------------------------------------------
check_budget "create proxy + accounts + scenario"
farm_exec_root bash -lc '
  set -e
  if [[ -f /src/e2e/setup-fixtures.sh ]]; then
    install -m 755 /src/e2e/setup-fixtures.sh /tmp/e2e-setup-fixtures.sh
  elif [[ -f /opt/farm/e2e/setup-fixtures.sh ]]; then
    install -m 755 /opt/farm/e2e/setup-fixtures.sh /tmp/e2e-setup-fixtures.sh
  else
    echo "setup-fixtures.sh missing"; exit 1
  fi
'
farm_exec bash /tmp/e2e-setup-fixtures.sh

ACCOUNT_ID="$(farm_exec cat /tmp/e2e.account_id | tr -d '\r')"
SCENARIO_ID="$(farm_exec cat /tmp/e2e.scenario_id | tr -d '\r')"
log "account=${ACCOUNT_ID} scenario=${SCENARIO_ID}"

# ---------------------------------------------------------------------------
# 3) Warmup task → done
# ---------------------------------------------------------------------------
check_budget "enqueue warmup"
TASK_ID="$(farm_exec bash -lc '
  set -euo pipefail
  TOKEN=$(cat /tmp/e2e.token)
  ACC=$(cat /tmp/e2e.account_id)
  SC=$(cat /tmp/e2e.scenario_id)
  RESP=$(curl -sf -X POST http://127.0.0.1/api/v1/tasks \
    -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
    -d "{
      \"type\": \"warmup\",
      \"account_id\": ${ACC},
      \"scenario_id\": ${SC},
      \"timeout\": 180,
      \"scenario_name\": \"e2e-warmup\",
      \"day\": 1,
      \"payload\": {
        \"type\": \"warmup\",
        \"account_id\": ${ACC},
        \"scenario_id\": ${SC},
        \"scenario_name\": \"e2e-warmup\",
        \"target_url\": \"http://site/\",
        \"keyword\": null,
        \"geo\": \"ru\",
        \"device\": \"desktop\",
        \"day\": 1,
        \"limits\": {\"max_duration_sec\": 90}
      }
    }")
  TID=$(php -r "\$j=json_decode(stream_get_contents(STDIN), true); \$d=\$j[\"data\"]??null; if (is_array(\$d) && array_is_list(\$d)) echo \$d[0][\"id\"]??\"\"; elseif (is_array(\$d)) echo \$d[\"id\"]??\"\";" <<<"$RESP")
  [[ -n "$TID" ]] || { echo "$RESP"; exit 1; }
  echo -n "$TID"
')"
[[ -n "${TASK_ID}" ]] || die "failed to create warmup task"
log "warmup task_id=${TASK_ID}"
farm_exec bash -lc "echo -n '${TASK_ID}' > /tmp/e2e.task_id"

check_budget "wait task ${TASK_ID} → done"
farm_exec bash -lc '
  set -e
  TID=$(cat /tmp/e2e.task_id)
  for i in $(seq 1 60); do
    ST=$(mysql --batch --skip-column-names farm -e "SELECT status FROM tasks WHERE id=${TID}" 2>/dev/null \
      || sudo mysql --batch --skip-column-names farm -e "SELECT status FROM tasks WHERE id=${TID}")
    echo "  tick=$i status=$ST"
    case "$ST" in
      done) exit 0 ;;
      failed|cancelled)
        mysql farm -e "SELECT id,status,error,attempts FROM tasks WHERE id=${TID}\G" 2>/dev/null || true
        exit 1
        ;;
    esac
    sleep 3
  done
  echo "timeout waiting for done"
  mysql farm -e "SELECT * FROM task_runs WHERE task_id=${TID}\G" 2>/dev/null || true
  exit 1
'

# ---------------------------------------------------------------------------
# 4) Live-view / screenshot frame
# ---------------------------------------------------------------------------
check_budget "live-view session + screenshot"
farm_exec bash -lc '
  set -euo pipefail
  TOKEN=$(cat /tmp/e2e.token)
  ACC=$(cat /tmp/e2e.account_id)
  curl -sf -X POST "http://127.0.0.1/api/v1/accounts/${ACC}/session" \
    -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d "{}" >/tmp/e2e.session.json || true
  sleep 2
  RESP=$(curl -sf "http://127.0.0.1/api/v1/accounts/${ACC}/screenshot?fresh=1" \
    -H "Authorization: Bearer $TOKEN" || true)
  echo "$RESP" > /tmp/e2e.shot.json
  PATH_REL=$(php -r "\$j=json_decode(stream_get_contents(STDIN), true); echo \$j[\"data\"][\"path\"] ?? \"\";" <<<"$RESP")
  if [[ -n "$PATH_REL" && -f "/opt/farm/${PATH_REL}" ]]; then
    echo -n "/opt/farm/${PATH_REL}" > /tmp/e2e.live_shot
    echo ok > /tmp/e2e.live_frame
  else
    LATEST=$(ls -1t /opt/farm/storage/screenshots/**/*.png /opt/farm/storage/screenshots/*.png 2>/dev/null | head -n1 || true)
    if [[ -n "$LATEST" ]]; then
      echo -n "$LATEST" > /tmp/e2e.live_shot
      echo ok > /tmp/e2e.live_frame
    else
      echo fail > /tmp/e2e.live_frame
    fi
  fi
  sudo -u farm php /opt/farm/farm mon:watch --once >/dev/null 2>&1 || true
'

# ---------------------------------------------------------------------------
# 5) kill -9 worker → alert + reclaim (supervisor, not systemd)
# ---------------------------------------------------------------------------
check_budget "kill -9 worker"
farm_exec_root bash -lc '
  set -euo pipefail
  TOKEN=$(cat /tmp/e2e.token)
  ACC=$(sed -n "2p" /tmp/e2e.account_ids || cat /tmp/e2e.account_id)
  SC=$(cat /tmp/e2e.scenario_id)

  # Prevent supervisor autorestart during HB window (farm-worker.conf only)
  supervisorctl stop farm-worker >/dev/null 2>&1 || true
  sleep 1
  sed -i "s/^autorestart=true/autorestart=false/" /etc/supervisor/conf.d/farm-worker.conf
  supervisorctl reread >/dev/null
  supervisorctl update farm-worker >/dev/null 2>&1 || supervisorctl update >/dev/null 2>&1 || true
  supervisorctl start farm-worker >/dev/null 2>&1 || true
  sleep 3

  sudo -u farm php /opt/farm/farm mon:watch --once >/dev/null 2>&1 || true
  sleep 1
  sudo -u farm php /opt/farm/farm mon:watch --once >/dev/null 2>&1 || true

  RESP=$(curl -sf -X POST http://127.0.0.1/api/v1/tasks \
    -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
    -d "{
      \"type\": \"warmup\",
      \"account_id\": ${ACC},
      \"scenario_id\": ${SC},
      \"timeout\": 300,
      \"max_attempts\": 3,
      \"scenario_name\": \"e2e-warmup\",
      \"day\": 1,
      \"payload\": {
        \"type\": \"warmup\",
        \"account_id\": ${ACC},
        \"scenario_id\": ${SC},
        \"scenario_name\": \"e2e-warmup\",
        \"target_url\": \"http://site/\",
        \"day\": 1,
        \"limits\": {\"max_duration_sec\": 180}
      }
    }")
  KILL_TID=$(php -r "\$j=json_decode(stream_get_contents(STDIN), true); \$d=\$j[\"data\"]??null; if (is_array(\$d) && array_is_list(\$d)) echo \$d[0][\"id\"]??\"\"; elseif (is_array(\$d)) echo \$d[\"id\"]??\"\";" <<<"$RESP")
  [[ -n "$KILL_TID" ]] || { echo "$RESP"; exit 1; }
  echo -n "$KILL_TID" > /tmp/e2e.kill_task_id
  echo "kill-task=$KILL_TID"

  for i in $(seq 1 40); do
    ST=$(mysql --batch --skip-column-names farm -e "SELECT status FROM tasks WHERE id=${KILL_TID}" 2>/dev/null || echo pending)
    echo "  kill-task status=$ST"
    [[ "$ST" == "running" ]] && break
    sleep 2
  done

  # Prefer supervisorctl pid — pgrep -f matches this bash -lc argv too.
  WPID=$(supervisorctl pid farm-worker 2>/dev/null || true)
  if [[ -z "$WPID" || "$WPID" == "0" || "$WPID" == *"no such"* ]]; then
    WPID=$(pgrep -P 1 -f "node.*index.js" 2>/dev/null | head -n1 || true)
  fi
  [[ "$WPID" =~ ^[0-9]+$ ]] || { echo "worker pid not found (got=$WPID)"; supervisorctl status; exit 1; }
  echo "kill -9 $WPID"
  kill -9 "$WPID" || true
  # Keep supervisor from bringing it back until reclaim window
  supervisorctl stop farm-worker >/dev/null 2>&1 || true

  for i in $(seq 1 24); do
    sudo -u farm php /opt/farm/farm mon:watch --once >/dev/null 2>&1 || true
    sudo -u farm php /opt/farm/farm gc:run >/dev/null 2>&1 || true
    ALERTS=$(mysql --batch --skip-column-names farm -e \
      "SELECT COUNT(*) FROM alerts WHERE message LIKE \"%heartbeat%\" OR meta LIKE \"%worker_down%\"" 2>/dev/null || echo 0)
    ST=$(mysql --batch --skip-column-names farm -e "SELECT status FROM tasks WHERE id=${KILL_TID}" 2>/dev/null || echo "")
    echo "  wait reclaim/alert tick=$i alerts=$ALERTS status=$ST"
    if [[ "$ALERTS" -gt 0 ]]; then
      break
    fi
    sleep 5
  done

  # Restore autorestart and worker
  sed -i "s/^autorestart=false/autorestart=true/" /etc/supervisor/conf.d/farm-worker.conf
  supervisorctl reread >/dev/null
  supervisorctl update farm-worker >/dev/null 2>&1 || supervisorctl update >/dev/null 2>&1 || true
  supervisorctl start farm-worker >/dev/null 2>&1 || true
  sleep 3

  for i in $(seq 1 40); do
    ST=$(mysql --batch --skip-column-names farm -e "SELECT status FROM tasks WHERE id=${KILL_TID}" 2>/dev/null || echo "")
    echo "  post-restart status=$ST"
    [[ "$ST" == "done" || "$ST" == "failed" || "$ST" == "retrying" || "$ST" == "queued" ]] && break
    sleep 3
  done
'

KILL_TASK_ID="$(farm_exec cat /tmp/e2e.kill_task_id | tr -d '\r')"

# ---------------------------------------------------------------------------
# 6) assert.php
# ---------------------------------------------------------------------------
check_budget "assert.php"
set +e
farm_exec bash -lc "
  export E2E_LIVE_FRAME=\$(cat /tmp/e2e.live_frame 2>/dev/null || echo '')
  export E2E_LIVE_SHOT=\$(cat /tmp/e2e.live_shot 2>/dev/null || echo '')
  export E2E_HOST_WSL='${E2E_HOST_WSL:-0}'
  php /opt/farm/e2e-assert.php \
    --task=${TASK_ID} \
    --account=${ACCOUNT_ID} \
    --kill-task=${KILL_TASK_ID} \
    --report=/opt/farm/storage/logs/e2e-report.json
"
RC=$?
set -e

"${COMPOSE[@]}" cp "${FARM_SERVICE}:/opt/farm/storage/logs/e2e-report.json" "${REPORT_DIR}/e2e-report.json" 2>/dev/null \
  || farm_exec cat /opt/farm/storage/logs/e2e-report.json > "${REPORT_DIR}/e2e-report.json" || true

ELAPSED=$(( $(date +%s) - START_EPOCH ))
log "report → ${REPORT_DIR}/e2e-report.json (elapsed=${ELAPSED}s)"
if [[ "${RC}" -eq 0 ]]; then
  log "RESULT: PASS"
else
  log "RESULT: FAIL (assert exit ${RC})"
fi
# trap cleanup runs: compose down -v
exit "${RC}"
