# Operations

## Units

| Unit | Role |
|------|------|
| `farm-api.service` | Gate for Apache + PHP-FPM (panel + `/api/v1` + `/internal`) |
| `farm-scheduler.service` | Cron schedules → `tasks` → Redis `farm:q:tasks` (governor) |
| `farm-worker@.service` | Node Chrome workers; `@1` listens on `:3000` |
| `farm-monitor.service` | Metrics every ~15s + alerts / Telegram |
| `farm-gc.timer` → `farm-gc.service` | Every 2 min: stale tasks, locks, orphan Chrome, flush `farm:q:results` |

```bash
systemctl status farm-api farm-scheduler farm-monitor 'farm-worker@1' farm-gc.timer
journalctl -u farm-scheduler -u 'farm-worker@1' -f
```

After reboot systemd restarts long-running units; GC + worker reclaim restores `running`/`queued` work from MySQL/Redis.

## CLI (`/opt/farm/farm`)

```bash
sudo -u farm php /opt/farm/farm migrate
sudo -u farm php /opt/farm/farm sched:stats
sudo -u farm php /opt/farm/farm governor:status
sudo -u farm php /opt/farm/farm gc:run
sudo -u farm php /opt/farm/farm mon:watch --once
sudo -u farm php /opt/farm/farm proxy:stats
sudo -u farm php /opt/farm/farm token:issue --email=admin@farm.local --name=ops
sudo -u farm php /opt/farm/farm fp:generate --account=ID
sudo -u farm php /opt/farm/farm fp:assign --group=GID
```

## Secrets

- `/opt/farm/.env` — mode `600`, owner `farm:farm`. Never world-readable.
- Rotate `INTERNAL_TOKEN`, `APP_KEY`, DB password together with a brief worker/API restart.
- Worker → PHP: header `X-Internal-Token` on `http://127.0.0.1:3000` and `/internal/*`.
- Alerts: set `TG_TOKEN` + `TG_CHAT_ID` for Telegram on proxy/worker failures.

## Backups

- Script: `/usr/local/sbin/farm-backup` (`deploy/backup.sh`)
- Cron: `/etc/cron.d/farm-backup` (daily 03:15 UTC by default)
- Output: `/var/backups/farm/<stamp>/{farm-db.sql.gz,profiles-cookies.tar.gz,MANIFEST.txt}`
- Retention: **7 days**

Manual:

```bash
sudo /usr/local/sbin/farm-backup
```

Restore (outline): stop workers → gunzip SQL into MySQL → extract profiles/cookies under `storage/` → start units.

## Добавление клиента

Клиент = группа аккаунтов + sticky-прокси + отпечатки + (опционально) API-токен менеджера.

1. **Прокси в пул** (панель «Proxies» или CSV): `host`, `port`, `protocol`, `username`/`password`, `country`. Health станет `unknown` → monitor проверит.
2. **Аккаунты**: панель «Аккаунты» → «Создать 100» (или CSV). Включите «Сразу назначить прокси и отпечатки» — вызов `POST /api/v1/accounts/bulk-provision`.
3. Либо вручную: выделите аккаунты → «Прокси+FP», либо API:
   ```bash
   curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -H "X-CSRF-Token: $CSRF" \
     -d '{"account_ids":[1,2,3],"with_fingerprint":true}' \
     https://farm.example/api/v1/accounts/bulk-provision
   ```
4. Задайте `group_id` / теги клиента для фильтрации в кампаниях и расписаниях.
5. При необходимости выпустите токен: панель Tokens или `farm token:issue --email=…`.
6. Проверка: у аккаунта есть `proxy_id`, вкладка fingerprint отдаёт UA/timezone, `profile_path` появится после первого запуска Chrome.

См. также [add-client.md](add-client.md), [add-proxy.md](add-proxy.md).

## Добавление кампании

1. **Сценарий** типа `pf` / `warmup` (панель Scenarios или `scenarios/*.json` + `farm scenario:sync`).
2. **Кампания**: `POST /api/v1/campaigns` — `name`, `status=active`, `scenario_id`, лимиты в `meta` / `budget_tasks`.
3. **Keywords** + **Targets**: фразы и целевые URL, привязанные к `campaign_id`.
4. **Задачи ПФ** (панель Tasks или API):
   ```json
   {
     "type": "pf",
     "account_id": 42,
     "scenario_id": 3,
     "campaign_id": 1,
     "keyword": "запрос",
     "target_url": "https://example.com/page",
     "payload": {
       "type": "pf",
       "account_id": 42,
       "scenario_id": 3,
       "keyword": "запрос",
       "target_url": "https://example.com/page",
       "limits": {}
     }
   }
   ```
   Создание сразу кладёт сообщение в `farm:q:tasks` (со sticky `proxy` + `fingerprint` из БД).
5. **Расписание**: `scenario_schedules` с `cron_expr` + `payload_override.account_ids` / `group_id` — scheduler тикает и уважает night-window / governor.
6. Отчёт: статус задачи `queued→running→done|retrying|failed`, скриншоты в `storage/screenshots/`, события `GET /api/v1/tasks/{id}/events`, webhook при терминальном статусе.

## Масштабирование на несколько воркер-нод

Нода = только исполнитель. Очередь и блокировки — общие в Redis; панель/API/MySQL — на control-ноде (или общем VIP).

### Одна машина (несколько слотов)

```bash
sudo systemctl enable --now farm-worker@2 farm-worker@3
# @N: WORKER_ID=wN, HTTP :3000+(N-1); очередь одна — farm:q:tasks
```

Live-view / screenshot API ходит на `WORKER_URL` (обычно `@1 :3000`). Tune: `MAX_CHROME`, `WORKER_SLOTS`, `GOVERNOR_*` — [capacity.md](capacity.md).

### Несколько хостов (500+ аккаунтов)

1. **Control node**: Apache/PHP, MySQL, Redis, scheduler, monitor, GC, `farm-worker@1` (live-view).
2. **Worker nodes**: только Node workers + Chrome + Xvfb. На каждой:
   - тот же код `/opt/farm` (rsync / пакет);
   - `.env` с `REDIS_HOST=<control>`, `DB_*` (если нужен CLI) или минимум Redis + `RESULT_URL=https://farm.example/internal/task/result`, `INTERNAL_TOKEN`, `API_URL`;
   - общий или локальный `storage/profiles` (для sticky-профилей предпочтителен NFS/ Gluster на `storage/profiles` и `storage/cookies`, иначе аккаунт должен sticky к ноде);
   - `systemctl enable --now farm-worker@1` с уникальным `WORKER_ID` (например `node2-w1`).
3. Воркеры делают `BRPOPLPUSH farm:q:tasks → farm:q:processing`, берут `farm:lock:acc:{id}` / `farm:lock:pxy:{id}`, шлют heartbeat `farm:worker:{id}:hb` TTL 30s.
4. Крах воркера: TTL снимает HB → reclaim processing + GC помечает stale `running` через RetryPolicy (`retrying` → снова в очередь). Задачи не теряются.
5. Live-view на remote-ноде: проксируйте `/live/{accountId}` на воркер, держащий сессию (сейчас default — control `:3000`; для multi-node держите интерактивные сессии на control или добавьте маршрутизацию по `account_sessions.worker_id`).

Целевой масштаб: десятки воркеров × `WORKER_SLOTS` при общем Redis; governor на control режет enqueue по CPU/RAM/chrome cap.

## Supervisor

Installed for contingency. Program `farm-worker` in `/etc/supervisor/conf.d/farm-worker.conf` stays **autostart=false** — prefer systemd.

## Logs

| Path | Content |
|------|---------|
| `journalctl -u farm-*` | Units |
| `/var/log/farm/` | PHP-FPM / backup / supervisor |
| `/var/log/apache2/farm-*.log` | Access / errors |
| `/opt/farm/storage/logs/` | App / scenario NDJSON |
