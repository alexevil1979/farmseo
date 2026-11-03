#!/usr/bin/env bash
# Farm preflight — WSL2 Ubuntu 24 / Linux host checks before e2e or install.
# Does NOT modify deploy/install.sh. Exit 0 = ready (WSL warnings allowed).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ROOT}/.env"
FAILS=0
WARNS=0

log()  { echo "[preflight] $*"; }
ok()   { echo "[preflight] OK    $*"; }
warn() { echo "[preflight] WARN  $*"; WARNS=$((WARNS + 1)); }
fail() { echo "[preflight] FAIL  $*" >&2; FAILS=$((FAILS + 1)); }

# ---------------------------------------------------------------------------
# 1) WSL detect → warning only (install.sh clean-Ubuntu criterion not closed)
# ---------------------------------------------------------------------------
IS_WSL=0
if [[ -r /proc/version ]] && grep -qi microsoft /proc/version; then
  IS_WSL=1
elif [[ -n "${WSL_DISTRO_NAME:-}" ]] || [[ -n "${WSL_INTEROP:-}" ]]; then
  IS_WSL=1
fi

if [[ "${IS_WSL}" -eq 1 ]]; then
  warn "WSL: критерий install.sh не закрывается"
  log "env=WSL2 (Microsoft kernel) — use docs/runbook-wsl.md; e2e via Docker only"
else
  ok "not WSL (bare metal / VM Linux)"
fi

# ---------------------------------------------------------------------------
# 2) PID 1 must be systemd
# ---------------------------------------------------------------------------
PID1_COMM="$(ps -p 1 -o comm= 2>/dev/null | tr -d '[:space:]' || true)"
if [[ "${PID1_COMM}" != "systemd" ]]; then
  fail "PID 1 is '${PID1_COMM:-unknown}', need systemd"
  cat >&2 <<'EOF'
[preflight] Enable systemd in WSL — /etc/wsl.conf:

  [boot]
  systemd=true

Then from Windows:  wsl --shutdown
Re-open the distro and re-run:  bash scripts/preflight.sh
EOF
else
  ok "PID 1 is systemd"
fi

# ---------------------------------------------------------------------------
# 3) Repo must not live on /mnt/… (drvfs)
# ---------------------------------------------------------------------------
if [[ "${ROOT}" == /mnt/* ]]; then
  fail "repository path is on drvfs: ${ROOT}"
  cat >&2 <<EOF
[preflight] Clone or copy the repo into the WSL ext4 filesystem, e.g.:
  mkdir -p ~/src && cp -a ${ROOT} ~/src/seoup
  cd ~/src/seoup && bash scripts/preflight.sh
Paths under /mnt/c (or /mnt/d) break Chrome profiles, Docker bind mounts, and file locking.
EOF
else
  ok "repo path not under /mnt: ${ROOT}"
fi

# ---------------------------------------------------------------------------
# 4) Google Chrome
# ---------------------------------------------------------------------------
CHROME_BIN=""
for c in google-chrome-stable google-chrome chromium-browser chromium; do
  if command -v "$c" >/dev/null 2>&1; then
    CHROME_BIN="$c"
    break
  fi
done
if [[ -z "${CHROME_BIN}" ]]; then
  fail "google-chrome / chromium not found in PATH"
  log "hint: install google-chrome-stable (or rely on e2e image Chrome only after Docker is up)"
else
  VER="$("${CHROME_BIN}" --version 2>/dev/null || true)"
  if [[ -z "${VER}" ]]; then
    fail "${CHROME_BIN} present but --version failed"
  else
    ok "chrome: ${VER}"
  fi
fi

# ---------------------------------------------------------------------------
# 5) Docker Compose + daemon
# ---------------------------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
  fail "docker not found"
else
  if ! docker compose version >/dev/null 2>&1; then
    fail "docker compose plugin not available"
  else
    ok "docker compose: $(docker compose version --short 2>/dev/null || docker compose version | head -n1)"
  fi
  if ! docker info >/dev/null 2>&1; then
    fail "docker daemon not responding (Docker Desktop: enable WSL integration for this distro)"
  else
    ok "docker daemon responds"
  fi
fi

# ---------------------------------------------------------------------------
# 6) RAM >= 7 GiB, free disk >= 30 GiB on repo filesystem
# ---------------------------------------------------------------------------
MEM_KB="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
MEM_NEED_KB=$((7 * 1024 * 1024))
if [[ "${MEM_KB}" -lt "${MEM_NEED_KB}" ]]; then
  MEM_GB="$(awk -v k="${MEM_KB}" 'BEGIN { printf "%.1f", k/1024/1024 }')"
  fail "RAM ${MEM_GB} GiB < 7 GiB required"
else
  MEM_GB="$(awk -v k="${MEM_KB}" 'BEGIN { printf "%.1f", k/1024/1024 }')"
  ok "RAM ${MEM_GB} GiB >= 7 GiB"
fi

AVAIL_KB="$(df -Pk "${ROOT}" 2>/dev/null | awk 'NR==2 {print $4}')"
AVAIL_KB="${AVAIL_KB:-0}"
DISK_NEED_KB=$((30 * 1024 * 1024))
if [[ "${AVAIL_KB}" -lt "${DISK_NEED_KB}" ]]; then
  AVAIL_GB="$(awk -v k="${AVAIL_KB}" 'BEGIN { printf "%.1f", k/1024/1024 }')"
  fail "free disk ${AVAIL_GB} GiB < 30 GiB on ${ROOT}"
else
  AVAIL_GB="$(awk -v k="${AVAIL_KB}" 'BEGIN { printf "%.1f", k/1024/1024 }')"
  ok "free disk ${AVAIL_GB} GiB >= 30 GiB"
fi

# ---------------------------------------------------------------------------
# 7) .env: CHROME_EXTRA_ARGS has --no-sandbox; MAX_CHROME <= 2
# ---------------------------------------------------------------------------
if [[ ! -f "${ENV_FILE}" ]]; then
  fail ".env missing at ${ENV_FILE} — copy .env.example and set WSL/e2e knobs"
  log "required: CHROME_EXTRA_ARGS=--no-sandbox ...  and  MAX_CHROME=2"
else
  # shellcheck disable=SC1090
  set -a
  # parse KEY=VAL lines without executing
  CHROME_EXTRA_ARGS=""
  MAX_CHROME=""
  while IFS= read -r line || [[ -n "${line}" ]]; do
    line="${line%$'\r'}"
    [[ -z "${line}" || "${line}" == \#* ]] && continue
    [[ "${line}" != *=* ]] && continue
    key="${line%%=*}"
    val="${line#*=}"
    key="$(echo "${key}" | tr -d '[:space:]')"
    case "${key}" in
      CHROME_EXTRA_ARGS) CHROME_EXTRA_ARGS="${val}" ;;
      MAX_CHROME) MAX_CHROME="${val}" ;;
    esac
  done < "${ENV_FILE}"
  set +a

  if [[ "${CHROME_EXTRA_ARGS}" != *"--no-sandbox"* ]]; then
    fail ".env CHROME_EXTRA_ARGS must include --no-sandbox (got: '${CHROME_EXTRA_ARGS}')"
  else
    ok "CHROME_EXTRA_ARGS includes --no-sandbox"
  fi

  if [[ -z "${MAX_CHROME}" ]]; then
    fail ".env MAX_CHROME is unset (require <= 2 for WSL/e2e)"
  elif ! [[ "${MAX_CHROME}" =~ ^[0-9]+$ ]]; then
    fail ".env MAX_CHROME='${MAX_CHROME}' is not an integer"
  elif [[ "${MAX_CHROME}" -gt 2 ]]; then
    fail ".env MAX_CHROME=${MAX_CHROME} > 2 (WSL/e2e cap is 2)"
  else
    ok "MAX_CHROME=${MAX_CHROME} <= 2"
  fi
fi

# ---------------------------------------------------------------------------
# 8) e2e compose invariants (MAX_CHROME=2, shm_size 1gb)
# ---------------------------------------------------------------------------
COMPOSE="${ROOT}/e2e/docker-compose.yml"
if [[ -f "${COMPOSE}" ]]; then
  if grep -qE 'shm_size:[[:space:]]*["'\'']?1gb' "${COMPOSE}"; then
    ok "e2e/docker-compose.yml shm_size: 1gb"
  else
    fail "e2e/docker-compose.yml must set shm_size: 1gb"
  fi
  if grep -qE 'MAX_CHROME:[[:space:]]*["'\'']?2["'\'']?' "${COMPOSE}"; then
    ok "e2e/docker-compose.yml MAX_CHROME: 2"
  else
    fail "e2e/docker-compose.yml must set MAX_CHROME: \"2\""
  fi
else
  warn "e2e/docker-compose.yml not found — skip compose checks"
fi

# ---------------------------------------------------------------------------
echo
if [[ "${FAILS}" -gt 0 ]]; then
  log "RESULT: FAIL (${FAILS} error(s), ${WARNS} warning(s))"
  exit 1
fi
log "RESULT: PASS (${WARNS} warning(s))"
exit 0
