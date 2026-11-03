#!/usr/bin/env bash
# Runs INSIDE the farm container (called by e2e/run.sh).
set -euo pipefail

TOKEN=$(cat /tmp/e2e.token)
api() { curl -sf -H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json" "$@"; }

json_id() {
  php -r '$j=json_decode(stream_get_contents(STDIN), true); $d=$j["data"]??null; if (is_array($d) && array_is_list($d)) echo $d[0]["id"]??""; elseif (is_array($d)) echo $d["id"]??"";'
}

PROXY_JSON=$(api -X POST http://127.0.0.1/api/v1/proxies -d '{
  "host": "proxy",
  "port": 8888,
  "protocol": "http",
  "country": "RU",
  "is_active": 1,
  "health": "ok"
}' 2>/dev/null || true)
PROXY_ID=$(json_id <<<"${PROXY_JSON}" || true)

if [[ -z "${PROXY_ID}" ]]; then
  LIST=$(api "http://127.0.0.1/api/v1/proxies?per_page=50")
  PROXY_ID=$(php -r '
    $j=json_decode(stream_get_contents(STDIN), true);
    foreach (($j["data"] ?? []) as $row) {
      if (($row["host"] ?? "") === "proxy" && (int)($row["port"] ?? 0) === 8888) {
        echo $row["id"];
        exit;
      }
    }
  ' <<<"${LIST}")
fi
[[ -n "${PROXY_ID}" ]] || { echo "proxy create failed: ${PROXY_JSON}"; exit 1; }

mysql farm -e "UPDATE proxies SET health='ok', is_active=1 WHERE id=${PROXY_ID};" 2>/dev/null \
  || sudo mysql farm -e "UPDATE proxies SET health='ok', is_active=1 WHERE id=${PROXY_ID};" 2>/dev/null || true
echo "${PROXY_ID}" > /tmp/e2e.proxy_id

ACC_IDS=()
for i in 1 2 3; do
  RESP=$(api -X POST http://127.0.0.1/api/v1/accounts -d "{
    \"login\": \"e2e_seed42_${i}@farm.local\",
    \"password\": \"E2ePass!${i}\",
    \"status\": \"ready\",
    \"proxy_id\": ${PROXY_ID},
    \"tags\": [\"e2e\"]
  }" 2>/dev/null || true)
  AID=$(json_id <<<"${RESP}" || true)
  if [[ -z "${AID}" ]]; then
    LIST=$(api "http://127.0.0.1/api/v1/accounts?per_page=50")
    AID=$(php -r '
      $j=json_decode(stream_get_contents(STDIN), true);
      $want=$argv[1];
      foreach (($j["data"] ?? []) as $row) {
        if (($row["login"] ?? "") === $want) { echo $row["id"]; exit; }
      }
    ' "e2e_seed42_${i}@farm.local" <<<"${LIST}")
  fi
  [[ -n "${AID}" ]] || { echo "${RESP}"; exit 1; }
  ACC_IDS+=("${AID}")
  sudo -u farm php /opt/farm/farm fp:generate --account="${AID}" >/dev/null || true
done

printf '%s\n' "${ACC_IDS[@]}" > /tmp/e2e.account_ids
echo "${ACC_IDS[0]}" > /tmp/e2e.account_id

sudo -u farm php /opt/farm/farm scenario:sync --scenario=e2e-warmup
SC=$(api "http://127.0.0.1/api/v1/scenarios?per_page=50")
SCENARIO_ID=$(php -r '
  $j=json_decode(stream_get_contents(STDIN), true);
  $list=$j["data"] ?? $j;
  if (!is_array($list)) exit;
  foreach ($list as $row) {
    if (($row["name"] ?? "") === "E2E Warmup Local" || str_contains((string)($row["name"] ?? ""), "E2E")) {
      echo $row["id"];
      exit;
    }
  }
  foreach (array_reverse($list) as $row) {
    if (($row["type"] ?? "") === "warmup") { echo $row["id"]; exit; }
  }
' <<<"${SC}")
[[ -n "${SCENARIO_ID}" ]] || { echo "scenario not found: ${SC}"; exit 1; }
echo "${SCENARIO_ID}" > /tmp/e2e.scenario_id
echo "proxy=${PROXY_ID} accounts=${ACC_IDS[*]} scenario=${SCENARIO_ID}"
