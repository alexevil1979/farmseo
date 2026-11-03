# CONTRACT.md — замороженный интерфейс системы

> **Единственный источник правды** по именам, схемам и протоколам.
> После принятия этот файл **не изменять**. Реализация подчиняется контракту, а не наоборот.

---

## 1. Стек (обязателен, не заменять)

| Компонент | Версия / роль |
|-----------|----------------|
| ОС | Ubuntu 24.04 |
| Веб-сервер | Apache 2.4 |
| Backend панели / API | PHP 8.3-fpm |
| СУБД | MySQL 8 |
| Chrome-оркестрация | Node.js 20 (Puppeteer + CDP) |
| Очереди / блокировки / конфиг | Redis 7 |
| Процесс-менеджер | Supervisor |
| Удалённый просмотр сессий | Xvfb + noVNC + websockify |
| Графики в панели | Chart.js |

### Разделение ролей

- **Apache / PHP** — панель управления, публичный REST API, бизнес-логика, персистентность, авторизация, вебхуки.
- **Node.js** — **только** управление Chrome (запуск/остановка профилей, выполнение сценариев через CDP/Puppeteer, скриншоты, VNC-сессии).
- **Связь PHP ↔ Node**: Redis-очередь + внутренний HTTP/JSON.
- **Запрещено**: прямые вызовы Chrome / CDP / Puppeteer из PHP.

---

## 2. Каталоги

Корень установки: `/opt/farm/`

```
/opt/farm/
├── app/                          # точка входа PHP-приложения (bootstrap, config)
├── public/                       # DocumentRoot Apache (index.php, assets)
├── src/
│   ├── Controllers/
│   ├── Services/
│   ├── Repositories/
│   ├── Models/
│   └── Jobs/
├── workers/                      # Node.js worker(s): очередь, Chrome, internal HTTP
├── scenarios/                    # определения сценариев (скрипты/конфиги шагов)
├── storage/
│   ├── logs/
│   ├── screenshots/
│   ├── profiles/                 # изолированные профили браузера
│   └── cookies/
├── migrations/                   # SQL: 0001_*.sql, 0002_*.sql, …
├── seeds/
├── docs/
└── deploy/                       # apache/php-fpm/supervisor/systemd/env шаблоны
```

---

## 3. Порты

| Порт | Сервис |
|------|--------|
| 80 | Apache HTTP |
| 443 | Apache HTTPS |
| 9000 | PHP-FPM |
| 3000 | Worker internal HTTP (только localhost / внутренняя сеть) |
| 5900+N | VNC на аккаунт/сессию (N = смещение от базы) |
| 6080 | noVNC (websockify → VNC) |

База VNC-портов задаётся через `VNC_BASE_PORT` (по умолчанию логически 5900).

---

## 4. Redis keyspace (фиксировано)

| Ключ / паттерн | Назначение |
|----------------|------------|
| `farm:q:tasks` | Очередь задач (PHP → Node): LPUSH / BRPOP или эквивалент list |
| `farm:q:results` | Очередь результатов (опциональный буфер; канонический ack — internal HTTP) |
| `farm:lock:acc:{id}` | Распределённая блокировка аккаунта |
| `farm:lock:pxy:{id}` | Распределённая блокировка прокси |
| `farm:rl:{key}` | Счётчики rate-limit |
| `farm:worker:{id}:hb` | Heartbeat воркера, **TTL 30s** |
| `farm:cfg:{name}` | Кэш/оверрайд конфигурации |
| `farm:evt:{channel}` | Pub/Sub или stream событий (`{channel}` — имя канала) |

Имена ключей и префикс `farm:` **не менять**.

---

## 5. Внутренний HTTP (Node ↔ PHP)

- Транспорт: HTTP/JSON.
- Базовый URL воркера: `http://127.0.0.1:3000` (порт 3000).
- Обязательный заголовок: `X-Internal-Token: {INTERNAL_TOKEN}`.
- Без валидного токена — `401`. Неверный Content-Type / тело — `400`.

### POST `/internal/task/result`

Результат выполнения задачи от Node к PHP.

**Request body:**

```json
{
  "task_id": 0,
  "status": "done",
  "error": null,
  "metrics": {},
  "screenshots": []
}
```

| Поле | Тип | Обязательно | Описание |
|------|-----|-------------|----------|
| `task_id` | integer | да | ID задачи |
| `status` | string (enum `task.status`) | да | Итоговый/промежуточный статус |
| `error` | string \| null | да | Текст ошибки или `null` |
| `metrics` | object | да | Произвольные метрики прогона (snake_case ключи) |
| `screenshots` | string[] | да | Пути/имена файлов в `storage/screenshots` |

**Response:** `{ "ok": true }` или `{ "ok": false, "error": "..." }`.

### POST `/internal/session/start`

**Request:** `{ "account_id": 0 }`  
**Response:** `{ "vnc_port": 5900 }`

### POST `/internal/session/stop`

**Request:** `{ "account_id": 0 }`  
**Response:** `{ "ok": true }`

### GET `/internal/health`

**Response:** `{ "ok": true, "workers": N, "chrome": N }` (поля могут расширяться, но `ok` обязателен).

---

## 6. Публичный REST API `/api/v1`

- Префикс: `/api/v1`.
- Авторизация: `Authorization: Bearer {token}` (`api_tokens`).
- Rate-limit: по токену / IP через `farm:rl:{key}`.
- Версия API зафиксирована в пути (`v1`); breaking changes → новый префикс.
- JSON request/response; поля — **snake_case**.
- Ошибки: `{ "error": { "code": "...", "message": "..." } }` + подходящий HTTP-код.

### CRUD-ресурсы

Для каждого ресурса — стандартный набор (если не оговорено иное):

| Метод | Путь | Действие |
|-------|------|----------|
| GET | `/api/v1/{resource}` | Список (pagination: `page`, `per_page`) |
| GET | `/api/v1/{resource}/{id}` | Одна сущность |
| POST | `/api/v1/{resource}` | Создание |
| PUT/PATCH | `/api/v1/{resource}/{id}` | Обновление |
| DELETE | `/api/v1/{resource}/{id}` | Удаление |

**Ресурсы:** `accounts`, `proxies`, `scenarios`, `tasks`, `campaigns`, `keywords`, `targets`, `users`, `roles`.

### Специальные эндпоинты

| Метод | Путь | Описание |
|-------|------|----------|
| POST | `/api/v1/tasks` | Создание задачи(ей); **батч** допускается: тело — объект или массив объектов payload |
| POST | `/api/v1/accounts/{id}/session` | Старт/запрос интерактивной сессии (VNC/noVNC) |
| GET | `/api/v1/accounts/{id}/screenshot` | Последний/актуальный скриншот аккаунта |
| POST | `/api/v1/scenarios/{id}/run` | Поставить сценарий в очередь на выполнение |
| GET | `/api/v1/stats` | Агрегированная статистика |
| GET | `/api/v1/health` | Публичный healthcheck |
| — | webhook | URL из настроек/задачи; вызов **на завершение задачи** (status `done` \| `failed` \| `cancelled`) |

Webhook payload (минимум): `{ "task_id", "status", "error", "finished_at" }`, заголовок подписи/токена — по `settings` / контракту интеграции.

---

## 7. Enum (фиксировано, значения — строки)

### `account.status`

`new` | `warming` | `ready` | `active` | `paused` | `banned` | `error`

### `task.status`

`pending` | `queued` | `running` | `done` | `failed` | `retrying` | `cancelled`

### `task.type`

`register` | `warmup` | `pf` | `service` | `check`

### `scenario.type`

`warmup` | `pf` | `service` | `register`

### `proxy.health`

`ok` | `slow` | `dead` | `unknown`

### `role` (системные роли)

`admin` | `manager` | `viewer`

В БД хранить как `VARCHAR` / `ENUM` строковых значений **точно** в этом написании (нижний регистр).

---

## 8. Правила именования

| Сущность | Правило | Пример |
|----------|---------|--------|
| Таблицы БД | snake_case, **множественное** число | `account_sessions` |
| PHP-классы | PascalCase | `AccountSession` |
| JSON-поля / API | snake_case | `trust_score` |
| Миграции | `NNNN_description.sql` | `0001_init.sql` |
| Redis-ключи | как в §4 | `farm:lock:acc:42` |
| Файлы скриншотов | под `storage/screenshots/`, имя с `task_id` / `account_id` / timestamp | — |

---

## 9. Схема базы данных (MySQL 8)

Общие соглашения:

- Движок: **InnoDB**, charset **utf8mb4**, collation **utf8mb4_unicode_ci**.
- Первичный ключ: `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`.
- Временные метки: `DATETIME(3)` или `TIMESTAMP` — в контракте ниже указан конкретный тип на колонку; по умолчанию `DATETIME(3)`.
- JSON-колонки: тип `JSON`.
- Денежные/дробные скоры: `DECIMAL`.
- Мягкое удаление — только если колонка `deleted_at` явно указана; иначе физическое удаление с учётом FK.

---

### 9.1. `roles`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `name` | VARCHAR(64) | NO | — | `admin` \| `manager` \| `viewer` (+ кастомные имена при расширении) |
| `description` | VARCHAR(255) | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_roles_name (name)`.

**FK:** нет.

---

### 9.2. `permissions`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `role_id` | BIGINT UNSIGNED | NO | — | → roles |
| `resource` | VARCHAR(64) | NO | — | например `accounts`, `tasks` |
| `action` | VARCHAR(32) | NO | — | `read` \| `write` \| `delete` \| `run` |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_permissions_role_resource_action (role_id, resource, action)`; `KEY idx_permissions_role_id (role_id)`.

**FK:**

- `fk_permissions_role_id`: `role_id` → `roles(id)` **ON DELETE CASCADE**.

---

### 9.3. `users`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `role_id` | BIGINT UNSIGNED | NO | — | → roles |
| `email` | VARCHAR(255) | NO | — | логин панели |
| `password_hash` | VARCHAR(255) | NO | — | |
| `name` | VARCHAR(128) | NO | — | |
| `is_active` | TINYINT(1) | NO | 1 | |
| `last_login_at` | DATETIME(3) | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_users_email (email)`; `KEY idx_users_role_id (role_id)`.

**FK:**

- `fk_users_role_id`: `role_id` → `roles(id)` **ON DELETE RESTRICT**.

---

### 9.4. `api_tokens`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `user_id` | BIGINT UNSIGNED | NO | — | → users |
| `name` | VARCHAR(128) | NO | — | метка токена |
| `token_hash` | CHAR(64) | NO | — | sha256 токена |
| `abilities` | JSON | YES | NULL | список scopes |
| `last_used_at` | DATETIME(3) | YES | NULL | |
| `expires_at` | DATETIME(3) | YES | NULL | |
| `revoked_at` | DATETIME(3) | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_api_tokens_token_hash (token_hash)`; `KEY idx_api_tokens_user_id (user_id)`.

**FK:**

- `fk_api_tokens_user_id`: `user_id` → `users(id)` **ON DELETE CASCADE**.

---

### 9.5. `audit_log`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `user_id` | BIGINT UNSIGNED | YES | NULL | → users; NULL = система |
| `action` | VARCHAR(64) | NO | — | |
| `entity_type` | VARCHAR(64) | NO | — | |
| `entity_id` | BIGINT UNSIGNED | YES | NULL | |
| `ip` | VARCHAR(45) | YES | NULL | |
| `meta` | JSON | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_audit_log_user_id (user_id)`; `KEY idx_audit_log_entity (entity_type, entity_id)`; `KEY idx_audit_log_created_at (created_at)`.

**FK:**

- `fk_audit_log_user_id`: `user_id` → `users(id)` **ON DELETE SET NULL**.

---

### 9.6. `proxies`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `host` | VARCHAR(255) | NO | — | |
| `port` | INT UNSIGNED | NO | — | |
| `protocol` | VARCHAR(16) | NO | `http` | http/socks5/… |
| `username` | VARCHAR(128) | YES | NULL | |
| `password_enc` | VARCHAR(512) | YES | NULL | |
| `country` | CHAR(2) | YES | NULL | ISO |
| `health` | VARCHAR(16) | NO | `unknown` | enum `proxy.health` |
| `latency_ms` | INT UNSIGNED | YES | NULL | |
| `last_check_at` | DATETIME(3) | YES | NULL | |
| `is_active` | TINYINT(1) | NO | 1 | |
| `meta` | JSON | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_proxies_health (health)`; `KEY idx_proxies_is_active (is_active)`; `UNIQUE KEY uq_proxies_host_port_protocol (host, port, protocol)`.

**FK:** нет.

---

### 9.7. `accounts`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `login` | VARCHAR(255) | NO | — | **обязательное** |
| `password_enc` | VARCHAR(512) | NO | — | **обязательное**, шифротекст |
| `status` | VARCHAR(16) | NO | `new` | enum `account.status` |
| `trust_score` | DECIMAL(5,2) | NO | 0.00 | **обязательное** |
| `phone` | VARCHAR(32) | YES | NULL | **обязательное поле схемы** (NULL допустим до привязки) |
| `group_id` | BIGINT UNSIGNED | YES | NULL | логическая группа |
| `tags` | JSON | YES | NULL | массив строк |
| `notes` | TEXT | YES | NULL | |
| `proxy_id` | BIGINT UNSIGNED | YES | NULL | текущий прокси |
| `profile_path` | VARCHAR(512) | YES | NULL | путь под `storage/profiles` |
| `last_activity` | DATETIME(3) | YES | NULL | **обязательное поле схемы** |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | **обязательное** |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_accounts_login (login)`; `KEY idx_accounts_status (status)`; `KEY idx_accounts_group_id (group_id)`; `KEY idx_accounts_proxy_id (proxy_id)`; `KEY idx_accounts_last_activity (last_activity)`.

**FK:**

- `fk_accounts_proxy_id`: `proxy_id` → `proxies(id)` **ON DELETE SET NULL**.

---

### 9.8. `account_fingerprints`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `account_id` | BIGINT UNSIGNED | NO | — | → accounts |
| `user_agent` | VARCHAR(512) | NO | — | |
| `platform` | VARCHAR(64) | YES | NULL | |
| `viewport_w` | INT UNSIGNED | YES | NULL | |
| `viewport_h` | INT UNSIGNED | YES | NULL | |
| `timezone` | VARCHAR(64) | YES | NULL | |
| `locale` | VARCHAR(16) | YES | NULL | |
| `webgl_hash` | VARCHAR(64) | YES | NULL | |
| `canvas_hash` | VARCHAR(64) | YES | NULL | |
| `fonts_hash` | VARCHAR(64) | YES | NULL | |
| `extra` | JSON | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_account_fingerprints_account_id (account_id)`.

**FK:**

- `fk_account_fingerprints_account_id`: `account_id` → `accounts(id)` **ON DELETE CASCADE**.

---

### 9.9. `account_sessions`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `account_id` | BIGINT UNSIGNED | NO | — | → accounts |
| `worker_id` | VARCHAR(64) | YES | NULL | id Node-воркера |
| `vnc_port` | INT UNSIGNED | YES | NULL | |
| `novnc_url` | VARCHAR(512) | YES | NULL | |
| `status` | VARCHAR(16) | NO | `starting` | starting\|active\|stopping\|stopped\|error |
| `started_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `ended_at` | DATETIME(3) | YES | NULL | |
| `meta` | JSON | YES | NULL | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_account_sessions_account_id (account_id)`; `KEY idx_account_sessions_status (status)`; `KEY idx_account_sessions_started_at (started_at)`.

**FK:**

- `fk_account_sessions_account_id`: `account_id` → `accounts(id)` **ON DELETE CASCADE**.

---

### 9.10. `account_cookies_snapshots`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `account_id` | BIGINT UNSIGNED | NO | — | → accounts |
| `storage_path` | VARCHAR(512) | NO | — | файл под `storage/cookies` |
| `cookie_count` | INT UNSIGNED | YES | NULL | |
| `checksum` | CHAR(64) | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_account_cookies_snapshots_account_id (account_id)`; `KEY idx_account_cookies_snapshots_created_at (created_at)`.

**FK:**

- `fk_account_cookies_snapshots_account_id`: `account_id` → `accounts(id)` **ON DELETE CASCADE**.

---

### 9.11. `proxy_assignments`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `account_id` | BIGINT UNSIGNED | NO | — | → accounts |
| `proxy_id` | BIGINT UNSIGNED | NO | — | → proxies |
| `assigned_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `released_at` | DATETIME(3) | YES | NULL | |
| `is_current` | TINYINT(1) | NO | 1 | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_proxy_assignments_account_id (account_id)`; `KEY idx_proxy_assignments_proxy_id (proxy_id)`; `KEY idx_proxy_assignments_is_current (is_current)`; `UNIQUE KEY uq_proxy_assignments_current_account (account_id, is_current)` — допускается частичный уникальный индекс/логика «только один current» на уровне приложения, если MySQL-ограничение неудобно; канон: не более одной строки с `is_current=1` на `account_id`.

**FK:**

- `fk_proxy_assignments_account_id`: `account_id` → `accounts(id)` **ON DELETE CASCADE**.
- `fk_proxy_assignments_proxy_id`: `proxy_id` → `proxies(id)` **ON DELETE RESTRICT**.

---

### 9.12. `scenarios`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `name` | VARCHAR(128) | NO | — | |
| `type` | VARCHAR(16) | NO | — | enum `scenario.type` |
| `description` | TEXT | YES | NULL | |
| `version` | INT UNSIGNED | NO | 1 | |
| `is_active` | TINYINT(1) | NO | 1 | |
| `defaults` | JSON | YES | NULL | дефолтный payload/limits |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_scenarios_type (type)`; `KEY idx_scenarios_is_active (is_active)`; `UNIQUE KEY uq_scenarios_name_version (name, version)`.

**FK:** нет.

---

### 9.13. `scenario_steps`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `scenario_id` | BIGINT UNSIGNED | NO | — | → scenarios |
| `step_order` | INT UNSIGNED | NO | — | порядок с 1 |
| `action` | VARCHAR(64) | NO | — | тип шага |
| `params` | JSON | YES | NULL | |
| `timeout_sec` | INT UNSIGNED | YES | NULL | |
| `on_fail` | VARCHAR(32) | NO | `abort` | abort\|retry\|continue |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_scenario_steps_scenario_order (scenario_id, step_order)`; `KEY idx_scenario_steps_scenario_id (scenario_id)`.

**FK:**

- `fk_scenario_steps_scenario_id`: `scenario_id` → `scenarios(id)` **ON DELETE CASCADE**.

---

### 9.14. `scenario_schedules`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `scenario_id` | BIGINT UNSIGNED | NO | — | → scenarios |
| `cron_expr` | VARCHAR(64) | NO | — | |
| `timezone` | VARCHAR(64) | NO | `UTC` | |
| `is_enabled` | TINYINT(1) | NO | 1 | |
| `payload_override` | JSON | YES | NULL | |
| `next_run_at` | DATETIME(3) | YES | NULL | |
| `last_run_at` | DATETIME(3) | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_scenario_schedules_scenario_id (scenario_id)`; `KEY idx_scenario_schedules_next_run_at (next_run_at)`; `KEY idx_scenario_schedules_is_enabled (is_enabled)`.

**FK:**

- `fk_scenario_schedules_scenario_id`: `scenario_id` → `scenarios(id)` **ON DELETE CASCADE**.

---

### 9.15. `campaigns`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `name` | VARCHAR(128) | NO | — | |
| `status` | VARCHAR(16) | NO | `draft` | draft\|active\|paused\|done |
| `scenario_id` | BIGINT UNSIGNED | YES | NULL | → scenarios |
| `budget_tasks` | INT UNSIGNED | YES | NULL | лимит задач |
| `starts_at` | DATETIME(3) | YES | NULL | |
| `ends_at` | DATETIME(3) | YES | NULL | |
| `meta` | JSON | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_campaigns_name (name)`; `KEY idx_campaigns_status (status)`; `KEY idx_campaigns_scenario_id (scenario_id)`.

**FK:**

- `fk_campaigns_scenario_id`: `scenario_id` → `scenarios(id)` **ON DELETE SET NULL**.

---

### 9.16. `keywords`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `campaign_id` | BIGINT UNSIGNED | YES | NULL | → campaigns |
| `phrase` | VARCHAR(512) | NO | — | |
| `lang` | VARCHAR(16) | YES | NULL | |
| `geo` | VARCHAR(64) | YES | NULL | |
| `priority` | INT | NO | 0 | |
| `is_active` | TINYINT(1) | NO | 1 | |
| `meta` | JSON | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_keywords_campaign_id (campaign_id)`; `KEY idx_keywords_phrase (phrase(191))`; `KEY idx_keywords_is_active (is_active)`.

**FK:**

- `fk_keywords_campaign_id`: `campaign_id` → `campaigns(id)` **ON DELETE SET NULL**.

---

### 9.17. `targets`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `campaign_id` | BIGINT UNSIGNED | YES | NULL | → campaigns |
| `url` | VARCHAR(2048) | NO | — | |
| `domain` | VARCHAR(255) | YES | NULL | |
| `title` | VARCHAR(512) | YES | NULL | |
| `is_active` | TINYINT(1) | NO | 1 | |
| `meta` | JSON | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_targets_campaign_id (campaign_id)`; `KEY idx_targets_domain (domain)`; `KEY idx_targets_is_active (is_active)`.

**FK:**

- `fk_targets_campaign_id`: `campaign_id` → `campaigns(id)` **ON DELETE SET NULL**.

---

### 9.18. `tasks`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `type` | VARCHAR(16) | NO | — | enum `task.type` **обязательное** |
| `priority` | INT | NO | 0 | **обязательное** |
| `status` | VARCHAR(16) | NO | `pending` | enum `task.status` **обязательное** |
| `account_id` | BIGINT UNSIGNED | YES | NULL | → accounts **обязательное поле** |
| `scenario_id` | BIGINT UNSIGNED | YES | NULL | → scenarios **обязательное поле** |
| `campaign_id` | BIGINT UNSIGNED | YES | NULL | → campaigns |
| `keyword_id` | BIGINT UNSIGNED | YES | NULL | → keywords |
| `target_id` | BIGINT UNSIGNED | YES | NULL | → targets |
| `payload` | JSON | NO | — | см. §10 **обязательное** |
| `attempts` | INT UNSIGNED | NO | 0 | **обязательное** |
| `max_attempts` | INT UNSIGNED | NO | 3 | **обязательное** |
| `scheduled_at` | DATETIME(3) | YES | NULL | **обязательное поле схемы** |
| `timeout` | INT UNSIGNED | NO | 300 | секунды **обязательное** |
| `started_at` | DATETIME(3) | YES | NULL | |
| `finished_at` | DATETIME(3) | YES | NULL | |
| `error` | TEXT | YES | NULL | |
| `webhook_url` | VARCHAR(2048) | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_tasks_status (status)`; `KEY idx_tasks_type (type)`; `KEY idx_tasks_priority_scheduled (priority, scheduled_at)`; `KEY idx_tasks_account_id (account_id)`; `KEY idx_tasks_scenario_id (scenario_id)`; `KEY idx_tasks_campaign_id (campaign_id)`; `KEY idx_tasks_status_scheduled (status, scheduled_at)`.

**FK:**

- `fk_tasks_account_id`: `account_id` → `accounts(id)` **ON DELETE SET NULL**.
- `fk_tasks_scenario_id`: `scenario_id` → `scenarios(id)` **ON DELETE SET NULL**.
- `fk_tasks_campaign_id`: `campaign_id` → `campaigns(id)` **ON DELETE SET NULL**.
- `fk_tasks_keyword_id`: `keyword_id` → `keywords(id)` **ON DELETE SET NULL**.
- `fk_tasks_target_id`: `target_id` → `targets(id)` **ON DELETE SET NULL**.

---

### 9.19. `task_runs`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `task_id` | BIGINT UNSIGNED | NO | — | → tasks |
| `attempt` | INT UNSIGNED | NO | — | номер попытки |
| `worker_id` | VARCHAR(64) | YES | NULL | |
| `status` | VARCHAR(16) | NO | — | зеркало `task.status` для прогона |
| `started_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `finished_at` | DATETIME(3) | YES | NULL | |
| `error` | TEXT | YES | NULL | |
| `metrics` | JSON | YES | NULL | |
| `screenshots` | JSON | YES | NULL | массив путей |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_task_runs_task_attempt (task_id, attempt)`; `KEY idx_task_runs_task_id (task_id)`; `KEY idx_task_runs_status (status)`.

**FK:**

- `fk_task_runs_task_id`: `task_id` → `tasks(id)` **ON DELETE CASCADE**.

---

### 9.20. `runs_events`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `task_run_id` | BIGINT UNSIGNED | NO | — | → task_runs |
| `level` | VARCHAR(16) | NO | `info` | debug\|info\|warn\|error |
| `event` | VARCHAR(64) | NO | — | код события |
| `message` | VARCHAR(1024) | YES | NULL | |
| `data` | JSON | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_runs_events_task_run_id (task_run_id)`; `KEY idx_runs_events_created_at (created_at)`; `KEY idx_runs_events_event (event)`.

**FK:**

- `fk_runs_events_task_run_id`: `task_run_id` → `task_runs(id)` **ON DELETE CASCADE**.

---

### 9.21. `metrics`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `entity_type` | VARCHAR(64) | NO | — | account\|task\|campaign\|proxy\|worker |
| `entity_id` | BIGINT UNSIGNED | YES | NULL | |
| `name` | VARCHAR(64) | NO | — | |
| `value` | DECIMAL(18,6) | NO | — | |
| `unit` | VARCHAR(16) | YES | NULL | |
| `captured_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `meta` | JSON | YES | NULL | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_metrics_entity (entity_type, entity_id)`; `KEY idx_metrics_name_captured (name, captured_at)`; `KEY idx_metrics_captured_at (captured_at)`.

**FK:** нет (полиморфная сущность).

---

### 9.22. `alerts`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `severity` | VARCHAR(16) | NO | `warn` | info\|warn\|critical |
| `source` | VARCHAR(64) | NO | — | |
| `message` | VARCHAR(1024) | NO | — | |
| `entity_type` | VARCHAR(64) | YES | NULL | |
| `entity_id` | BIGINT UNSIGNED | YES | NULL | |
| `is_acked` | TINYINT(1) | NO | 0 | |
| `acked_by` | BIGINT UNSIGNED | YES | NULL | → users |
| `acked_at` | DATETIME(3) | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `meta` | JSON | YES | NULL | |

**Индексы:** `PRIMARY KEY (id)`; `KEY idx_alerts_severity (severity)`; `KEY idx_alerts_is_acked (is_acked)`; `KEY idx_alerts_created_at (created_at)`; `KEY idx_alerts_entity (entity_type, entity_id)`.

**FK:**

- `fk_alerts_acked_by`: `acked_by` → `users(id)` **ON DELETE SET NULL**.

---

### 9.23. `settings`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `name` | VARCHAR(128) | NO | — | ключ настройки |
| `value` | JSON | NO | — | |
| `updated_by` | BIGINT UNSIGNED | YES | NULL | → users |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_settings_name (name)`.

**FK:**

- `fk_settings_updated_by`: `updated_by` → `users(id)` **ON DELETE SET NULL**.

Синхронизация с Redis: зеркало часто читаемых ключей в `farm:cfg:{name}`.

---

### 9.24. `blacklists`

| Колонка | Тип | NULL | По умолчанию | Описание |
|---------|-----|------|--------------|----------|
| `id` | BIGINT UNSIGNED | NO | AI | PK |
| `kind` | VARCHAR(32) | NO | — | domain\|url\|keyword\|login\|ip\|fingerprint |
| `value` | VARCHAR(512) | NO | — | |
| `reason` | VARCHAR(255) | YES | NULL | |
| `is_active` | TINYINT(1) | NO | 1 | |
| `expires_at` | DATETIME(3) | YES | NULL | |
| `created_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) | |
| `updated_at` | DATETIME(3) | NO | CURRENT_TIMESTAMP(3) ON UPDATE | |

**Индексы:** `PRIMARY KEY (id)`; `UNIQUE KEY uq_blacklists_kind_value (kind, value)`; `KEY idx_blacklists_is_active (is_active)`; `KEY idx_blacklists_expires_at (expires_at)`.

**FK:** нет.

---

### Порядок миграций (рекомендуемый)

1. `0001_roles_permissions_users.sql`
2. `0002_api_tokens_audit_log_settings.sql`
3. `0003_proxies_accounts_fingerprints_sessions_cookies.sql`
4. `0004_proxy_assignments.sql`
5. `0005_scenarios_steps_schedules.sql`
6. `0006_campaigns_keywords_targets.sql`
7. `0007_tasks_task_runs_runs_events.sql`
8. `0008_metrics_alerts_blacklists.sql`

---

## 10. Схема payload задачи (JSON)

Канонический объект в `tasks.payload` и в сообщении очереди `farm:q:tasks`:

```json
{
  "type": "pf",
  "account_id": 0,
  "scenario_id": 0,
  "target_url": "https://example.com/page",
  "keyword": "пример запроса",
  "geo": "ru",
  "device": "desktop",
  "limits": {}
}
```

| Поле | Тип | Обязательно | Описание |
|------|-----|-------------|----------|
| `type` | string | да | одно из `task.type` |
| `account_id` | integer | да | ID аккаунта |
| `scenario_id` | integer | да | ID сценария |
| `target_url` | string \| null | условно | URL цели (для `pf` / `service` / `check`) |
| `keyword` | string \| null | условно | поисковый запрос |
| `geo` | string \| null | нет | гео/регион |
| `device` | string \| null | нет | `desktop` \| `mobile` \| `tablet` |
| `limits` | object | да | лимиты прогона (пустой объект допустим) |

Рекомендуемые ключи `limits` (не исчерпывающий список, но имена фиксировать при использовании):

- `max_duration_sec` (integer)
- `max_pages` (integer)
- `max_scrolls` (integer)
- `dwell_sec_min` / `dwell_sec_max` (integer)
- `click_depth` (integer)

В очереди Redis сообщение дополняется служебными полями верхнего уровня (не внутри `payload` дублировать без нужды): `task_id`, `priority`, `timeout`, `attempts`, `max_attempts`.

---

## 11. Переменные окружения (`.env`, права `600`)

| Переменная | Назначение |
|------------|------------|
| `DB_HOST` | Хост MySQL |
| `DB_PORT` | Порт MySQL |
| `DB_DATABASE` | Имя БД |
| `DB_USERNAME` | Пользователь БД |
| `DB_PASSWORD` | Пароль БД |
| `REDIS_HOST` | Хост Redis |
| `REDIS_PORT` | Порт Redis |
| `REDIS_PASSWORD` | Пароль Redis (пустая строка, если нет) |
| `REDIS_DB` | Номер DB Redis |
| `API_URL` | Публичный базовый URL API |
| `INTERNAL_TOKEN` | Общий секрет для `X-Internal-Token` |
| `TG_TOKEN` | Telegram bot token (алерты) |
| `CAPTCHA_KEY` | Ключ сервиса капчи |
| `SMS_KEY` | Ключ SMS-провайдера |
| `MAX_CHROME` | Макс. одновременных Chrome |
| `CHROME_HEADLESS` | `false` (по контракту по умолчанию / обязательно учитывать) |
| `VNC_BASE_PORT` | Базовый порт VNC (логически 5900) |

Файл `.env` в корне деплоя (`/opt/farm/` или `deploy/`): режим файла **`600`**, владелец — пользователь рантайма приложения. Секреты не коммитить.

---

## 12. Протокол жизненного цикла задачи (сводка)

1. PHP создаёт запись в `tasks` (`pending`), кладёт сообщение в `farm:q:tasks`, статус → `queued`.
2. Node забирает задачу, берёт `farm:lock:acc:{id}` (и при необходимости `farm:lock:pxy:{id}`), статус → `running`, пишет `task_runs`.
3. Node выполняет сценарий через Puppeteer/CDP; события — в `runs_events` (через internal API или буфер `farm:q:results` + flush).
4. Node вызывает `POST /internal/task/result`.
5. PHP обновляет `tasks` / `task_runs` / `metrics`, снимает блокировки (или TTL), шлёт webhook при терминальном статусе.
6. Heartbeat воркера: `farm:worker:{id}:hb` TTL 30s; отсутствие → PHP помечает зависшие `running` для `retrying` / `failed` по политике `max_attempts`.

---

## 13. Зачем это всё

Система держит пул изолированных профилей браузера с отдельными cookies, отпечатками и прокси.  
Каждый профиль управляется удалённо через Chrome DevTools Protocol без смешивания сессий.  
Планировщик и сценарии автономно воспроизводят типичные действия живого пользователя в сети.  
Собранные метрики и статусы аккаунтов позволяют дозировать нагрузку и отсекать сбои.  
Цель контура — устойчивое влияние на поведенческие факторы целевых ресурсов через такие сессии.
