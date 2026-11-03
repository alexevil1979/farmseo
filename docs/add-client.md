# Add a client (account / API consumer)

“Client” here means either a **browser account** in the farm or an **API consumer** (user + token).

## 1. Panel / API user

Seed creates `admin@farm.local`. Add more users via API (admin token) or SQL + `token:issue`.

```bash
# Issue API token for existing user
sudo -u farm php /opt/farm/farm token:issue --email=admin@farm.local --name=ci
sudo -u farm php /opt/farm/farm token:list --user=1
```

Use: `Authorization: Bearer <plaintext-token>` on `/api/v1/*`.

Roles: `admin` | `manager` | `viewer`.

## 2. Browser account

```bash
TOKEN=...
curl -sk -X POST "https://farm.example.com/api/v1/accounts" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{
    "login": "user@example.com",
    "password": "plain-password",
    "status": "new",
    "proxy_id": 1,
    "tags": ["batch-a"]
  }'
```

Then fingerprint + optional session:

```bash
sudo -u farm php /opt/farm/farm fp:generate --account=ID
curl -sk -X POST "https://farm.example.com/api/v1/accounts/ID/session" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{}'
# Live view: https://farm.example.com/live/{accountId}/  (proxied to worker :3000)
```

Profile path lands under `/opt/farm/storage/profiles/{id}/`.

## 3. Enqueue work

```bash
curl -sk -X POST "https://farm.example.com/api/v1/tasks" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{
    "type": "warmup",
    "account_id": 1,
    "scenario_id": 1,
    "payload": {
      "type": "warmup",
      "account_id": 1,
      "scenario_id": 1,
      "target_url": null,
      "keyword": null,
      "geo": "ru",
      "device": "desktop",
      "limits": {}
    }
  }'
```

Or schedules: panel / `scenario_schedules` → `farm-scheduler` → Redis `farm:q:tasks`.

## Checklist

1. Proxy healthy (`proxy:check`)
2. Account + fingerprint
3. Scenario synced (`scenario:sync`)
4. Worker up (`systemctl status farm-worker@1`)
5. Token scoped to least privilege
