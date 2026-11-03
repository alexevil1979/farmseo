# Install — Ubuntu 24.04

> WSL2: критерий «чистая Ubuntu + install.sh» **не закрывается**. См. [runbook-wsl.md](runbook-wsl.md) и `bash scripts/preflight.sh`. `install.sh` не меняется под WSL.

One command on a clean host:

```bash
sudo bash deploy/install.sh --domain=farm.example.com
```

Optional flags:

| Flag | Default | Meaning |
|------|---------|---------|
| `--domain=HOST` | `localhost` | Apache `ServerName`, `API_URL`, TLS CN |
| `--workers=N` | `1` | How many `farm-worker@N` instances to enable |
| `--skip-seed` | off | Skip idempotent roles/admin seed |

## What the installer does

1. Installs Apache 2.4, PHP 8.3-FPM, MySQL 8, Redis 7, Node.js 20, Google Chrome, Xvfb, noVNC, websockify, x11vnc, Supervisor, fail2ban.
2. Creates system user `farm`, tree under `/opt/farm`, storage dirs.
3. Creates MySQL DB/user, writes `/opt/farm/.env` with mode **600**.
4. `composer install`, `npm install` in `workers/`, runs `php farm migrate` (+ seed unless skipped).
5. Installs Apache HTTPS vhost (self-signed under `/etc/ssl/farm/` — replace with Let’s Encrypt), PHP-FPM pool `farm`, fail2ban jail, daily backup cron.
6. Enables and starts: `farm-api`, `farm-scheduler`, `farm-monitor`, `farm-worker@1`, `farm-gc.timer`.

Root-only credentials dump: `/root/.farm/install-credentials.txt`.

Default panel user after seed: `admin@farm.local` / `admin123!` — **change immediately**.

## Acceptance checks

```bash
systemctl status farm-api farm-scheduler farm-monitor 'farm-worker@1' farm-gc.timer
curl -k https://127.0.0.1/api/v1/health
sudo /usr/local/sbin/farm-backup
ls /var/backups/farm/
```

Reboot test: `sudo reboot` → all units `active`, queued tasks reclaim via Redis + GC.

## TLS (production)

```bash
# Example certbot (after DNS points here)
sudo certbot certonly --webroot -w /opt/farm/public -d farm.example.com
# Point SSLCertificate* in /etc/apache2/sites-available/farm.conf to live/fullchain.pem
sudo systemctl reload apache2
```

## Re-run / upgrade code

```bash
sudo rsync -a --exclude .env --exclude vendor --exclude workers/node_modules \
  ./ /opt/farm/
cd /opt/farm && sudo -u farm composer install --no-dev
cd /opt/farm/workers && sudo -u farm npm install --omit=dev
sudo -u farm php /opt/farm/farm migrate
sudo systemctl restart farm-scheduler farm-monitor 'farm-worker@1'
sudo systemctl reload apache2 php8.3-fpm
```
