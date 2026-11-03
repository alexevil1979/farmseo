# Farm E2E

Reproducible end-to-end acceptance on **clean Ubuntu 24.04** with **real Chrome** (not mocked).  
`deploy/install.sh` is **not** used — runtime is baked into `e2e/Dockerfile` and started by `e2e/entrypoint.sh` + supervisord.

## Stack

| Service | Role |
|---------|------|
| `farm` | `ubuntu:24.04` + apache/php-fpm/mysql/redis/chrome/xvfb/novnc under supervisor |
| `site` | nginx + 3 static pages (local warmup target) |
| `proxy` | tinyproxy `:8888` without auth |

Determinism: `RANDOM_SEED=42`, `GEO_PROVIDER=stub`, fixed DB/internal secrets in entrypoint.

## Run

Requires Docker + Compose. On **WSL2 Ubuntu 24** follow [docs/runbook-wsl.md](../docs/runbook-wsl.md).

```bash
bash scripts/preflight.sh   # WSL warnings OK; fails on /mnt/c, no systemd, MAX_CHROME>2, …
bash e2e/run.sh
```

Defaults: `MAX_CHROME=2`, `shm_size: 1gb` (mandatory). Wall-clock budget: **10 minutes**. On exit → `compose down -v`.

Report: `e2e/out/e2e-report.json` — includes **`environment_limitations`** (`NOT TESTED` on WSL for install.sh / clean Ubuntu / VM snapshots).

## What it checks

1. Creates **3 accounts**, **1 proxy**, **1 scenario** (`scenarios/e2e-warmup.json` — goto/dwell/scroll/internal_click/mouse_move/back/screenshot only).
2. Runs warmup against `http://site/` through the fake proxy with **real Chrome**.
3. Waits for `task_runs`, verifies screenshots + CPU/RAM metrics.
4. Starts live-view / fresh screenshot (PNG frame).
5. `kill -9` on the worker → HB loss → `worker_down` alert → task not lost.
6. `e2e/assert.php` prints **PASS/FAIL** for TZ criteria and fixes **14a–14c**.
