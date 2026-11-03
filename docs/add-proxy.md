# Add a proxy

## Format

Import file — one proxy per line:

```text
host:port:username:password
1.2.3.4:8000:user:secret
socks.example.net:1080:u:p
```

## CLI

```bash
sudo -u farm php /opt/farm/farm proxy:import /path/to/proxies.txt --protocol=http --country=RU
sudo -u farm php /opt/farm/farm proxy:check --all
sudo -u farm php /opt/farm/farm proxy:stats
sudo -u farm php /opt/farm/farm proxy:rotate   # replace dead assigned proxies (same geo)
```

Health enum (`proxy.health`): `ok` | `slow` | `dead` | `unknown`.

## API

```bash
TOKEN=...   # Bearer from token:issue
curl -sk -X POST "https://farm.example.com/api/v1/proxies" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"host":"1.2.3.4","port":8000,"protocol":"http","username":"u","password":"p","country":"RU"}'
```

Password is stored encrypted (`password_enc`). Assign to an account via `proxy_id` on create/update account, or let assignment / rotate logic bind it.

## Tips

- Keep pool size ≥ concurrent Chrome (`MAX_CHROME`).
- Run `proxy:check` from cron or rely on monitor alerts (`MONITOR_PROXY_DEAD_PCT`).
- Prefer residential / ISP exit matching account fingerprint geo (`fp:generate`).
