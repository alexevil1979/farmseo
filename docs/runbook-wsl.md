# Runbook — WSL2 Ubuntu 24.04

Рабочий контур приёмки: **WSL2 + Ubuntu 24.04**, не отдельная VM.  
`deploy/install.sh` **не адаптируется под WSL** — он остаётся универсальным для чистой Ubuntu. На WSL критерий «install.sh одной командой на чистой машине» **не закрывается**.

## Быстрый старт

```bash
# 1) Репозиторий только на ext4 внутри WSL (НЕ /mnt/c/...)
cd ~/src/seoup   # пример

# 2) Preflight
bash scripts/preflight.sh

# 3) E2E (Docker, MAX_CHROME=2, shm_size=1gb)
bash e2e/run.sh
```

## systemd в WSL

PID 1 должен быть `systemd`. `/etc/wsl.conf`:

```ini
[boot]
systemd=true
```

Применить:

```powershell
wsl --shutdown
```

Снова открыть дистрибутив, проверить: `ps -p 1 -o comm=` → `systemd`.

## Docker Desktop + интеграция

1. Установить Docker Desktop for Windows.
2. Settings → Resources → WSL integration → включить ваш Ubuntu 24.04.
3. Внутри WSL: `docker info` и `docker compose version` без ошибок.
4. Демон должен отвечать **из этой** distro (не только из Windows PowerShell).

E2E-стенд: `e2e/docker-compose.yml` — `shm_size: 1gb` (обязательно), `MAX_CHROME: "2"`.

## Экспорт / импорт вместо снапшотов VM

Вместо hypervisor-снапшотов используйте backup дистрибутива:

```powershell
# Экспорт (замена snapshot)
wsl --export Ubuntu-24.04 D:\backups\farm-wsl-ubuntu24.tar

# Импорт в новое имя (откат / клон)
wsl --import FarmUbuntu24 D:\WSL\FarmUbuntu24 D:\backups\farm-wsl-ubuntu24.tar --version 2

wsl -d FarmUbuntu24
```

Список: `wsl -l -v`. Удаление клона: `wsl --unregister FarmUbuntu24`.

Перед тяжёлым экспериментом — свежий `--export`. После прогона e2e тома снимает `e2e/run.sh` (`compose down -v`).

## .env для WSL / e2e

```env
MAX_CHROME=2
CHROME_EXTRA_ARGS=--no-sandbox --disable-dev-shm-usage --disable-gpu
GEO_PROVIDER=stub
```

`scripts/preflight.sh` падает, если `MAX_CHROME > 2` или нет `--no-sandbox`.

## Ограничения WSL

| Тема | Суть |
|------|------|
| **systemd** | Нужен `[boot] systemd=true`; без этого host-units из `install.sh` и preflight не проходят. |
| **drvfs (`/mnt/c`)** | Репозиторий на `/mnt/...` запрещён: ломаются профили Chrome, flock, Docker bind mounts. Только `~/...` на ext4. |
| **Нет чистой Ubuntu** | Ядро Microsoft WSL — это не «чистая Ubuntu 24.04». Критерий ТЗ про `install.sh` на чистой машине → **NOT TESTED** в e2e-отчёте. |
| **install.sh** | Не запускать как единственный критерий приёмки на WSL; для бизнес-логики — `e2e/run.sh` (образ без install.sh). |
| **Ресурсы** | Preflight: RAM ≥ 7 GiB, свободно ≥ 30 GiB на ФС репозитория. |
| **Chrome на хосте** | Preflight требует `google-chrome --version`; в контейнере e2e — свой `google-chrome-stable`. |

## Что смотреть после e2e

- Отчёт: `e2e/out/e2e-report.json`
- Блок **`environment_limitations`**: всё, что на WSL нельзя закрыть, со статусом `NOT TESTED`
- Внешние HTTP при `GEO_PROVIDER=stub` не допускаются (`ss -tupn` в `farm`)

## Ссылки

- [install.md](install.md) — канон `install.sh` (чистая Ubuntu / VM)
- [e2e/README.md](../e2e/README.md) — стенд Docker
- [capacity.md](capacity.md) — RAM / `MAX_CHROME` для продакшена (не путать с e2e cap=2)
