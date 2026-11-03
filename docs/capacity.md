# Capacity planning

## Per Chrome session

| Resource | Typical |
|----------|---------|
| RAM | **400–600 MB** per Chrome profile (Xvfb + renderer + extensions) |
| CPU | **2–4%** of one vCPU at steady browse; spikes higher on navigation/captcha |

Headful + VNC (`CHROME_HEADLESS=false`) sits at the upper end of the RAM range.

## 100 accounts (concurrent Chrome)

| Resource | Guidance |
|----------|----------|
| RAM | **32–64 GB** — \(100 \times 0.4\)–\(100 \times 0.6\) GB plus OS / MySQL / Redis / PHP (~4–8 GB) |
| CPU | **8–16 vCPU** — \(100 \times 2\%\)–\(4\%\) ≈ 2–4 cores average; oversubscribe for bursts |
| Disk | **NVMe ≥ 200 GB** — OS + profiles/cookies growth + logs + 7-day backups |
| Network | **≥ 100 Mbit/s** symmetric recommended (proxies + page weight + live-view) |

Set `MAX_CHROME` / `WORKER_SLOTS` to what fits RAM, not to account count. Cold accounts can share a smaller concurrent cap; governor (`GOVERNOR_RAM_PCT`, `GOVERNOR_CPU_PCT`) will shed load.

## Rough formula

```text
RAM_GB ≈ 6 + MAX_CHROME * 0.55
vCPU   ≈ max(4, ceil(MAX_CHROME * 0.03 * 1.5))   # 3% avg × 1.5 headroom
DISK   ≈ 40 + accounts * 0.5 + backup_days * daily_delta   # GB; profiles dominate
```

## Examples

| Concurrent Chrome | RAM | vCPU | Notes |
|-------------------|-----|------|-------|
| 20 | 16–24 GB | 4–6 | Small staging |
| 40 (default `.env`) | 24–40 GB | 6–10 | Mid |
| 100 | 32–64 GB | 8–16 | Production target above |
| 200 | 96–128 GB | 16–32 | Split workers / hosts |

## Operational knobs

- `MAX_CHROME`, `WORKER_SLOTS` — hard concurrency
- `farm-worker@2…` — more Node processes (still one Redis queue)
- `NIGHT_WINDOW_*` — reduce daytime pressure
- `proxy:rotate` / health — dead proxies waste Chrome slots
- Live-view idle: `LIVE_VIEW_IDLE_SEC` — stop abandoned VNC sessions

Monitor: `mon:watch`, `/api/v1/stats`, `governor:status`.
