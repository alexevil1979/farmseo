# Troubleshooting

## Units not active

```bash
systemctl status farm-api farm-scheduler farm-monitor 'farm-worker@1' farm-gc.timer
journalctl -u farm-worker@1 -u farm-scheduler -n 100 --no-pager
```

| Symptom | Check |
|---------|--------|
| `farm-api` failed | `apache2`, `php8.3-fpm`, socket `/run/php/php8.3-fpm-farm.sock` |
| Worker crash loop | Chrome path (`CHROME_PATH`), missing libs, Redis auth, port bind |
| Scheduler idle | MySQL connectivity, `.env` DB_*, `sched:stats` |
| GC timer unused | `systemctl list-timers farm-gc.timer` |

## After reboot tasks stuck in `running`

Expected briefly. `farm-gc` + worker reclaim marks stale runs for `retrying`/`failed` per `max_attempts`. Force:

```bash
sudo -u farm php /opt/farm/farm gc:run
sudo systemctl restart 'farm-worker@1' farm-scheduler
```

## Worker cannot start Chrome

```bash
google-chrome-stable --version
sudo -u farm test -x "$(grep CHROME_PATH /opt/farm/.env | cut -d= -f2)"
# Shared memory
df -h /dev/shm
```

Install deps again via `deploy/install.sh` package list; ensure `CHROME_HEADLESS=false` only when Xvfb stack is present.

## Live-view / noVNC blank

1. `curl -s http://127.0.0.1:3000/internal/health` with `X-Internal-Token`
2. Apache `IncludeOptional …/apache-live-view.conf` loaded
3. `xvfb-run` / `x11vnc` / `websockify` on PATH
4. Session started: `POST /api/v1/accounts/{id}/session`

## 401 on API / internal

- Public: Bearer token revoked or wrong hash → `token:issue`
- Internal: `INTERNAL_TOKEN` mismatch between PHP `.env` and worker (same file)

## Redis / locks

```bash
redis-cli KEYS 'farm:*'
redis-cli TTL farm:worker:w-1:hb
```

Heartbeats expire at **30s**. Missing HB → GC treats Chrome jobs as stale.

## Backup / rotation

```bash
sudo /usr/local/sbin/farm-backup
ls -la /var/backups/farm/
grep farm-backup /var/log/farm/backup.log
```

If mysqldump fails: check `DB_*` in `.env`, MySQL running, disk on `/var/backups`.

## fail2ban false positives

```bash
fail2ban-client status farm-api
fail2ban-client set farm-api unbanip A.B.C.D
```

Tune `maxretry` / `findtime` in `/etc/fail2ban/jail.d/farm.conf`.

## Disk full

Profiles + screenshots grow fast. Purge old screenshots, rotate logs, confirm backup retention (7d). Alert threshold: `MONITOR_DISK_PCT`.
