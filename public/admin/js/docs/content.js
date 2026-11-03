/**
 * Structured operator documentation for Farm Admin.
 * Each guide: searchable title/summary/tags/steps.
 */

export const DOC_CATEGORIES = [
  { id: 'start', label: 'Старт' },
  { id: 'ops', label: 'Операции' },
  { id: 'campaigns', label: 'Кампании' },
  { id: 'system', label: 'Система' },
  { id: 'help', label: 'Справка' },
];

/** @type {Array<{
 *  id: string,
 *  category: string,
 *  title: string,
 *  summary: string,
 *  tags: string[],
 *  panel?: string,
 *  illustration?: string,
 *  steps: Array<{ title: string, body: string, tip?: string }>,
 *  checklist?: string[],
 * }>} */
export const DOC_GUIDES = [
  {
    id: 'quickstart',
    category: 'start',
    title: 'Быстрый старт: первая рабочая задача',
    summary: 'За 5 шагов поднимите цепочку прокси → аккаунт → сценарий → задача → Chrome.',
    tags: ['старт', 'онбординг', 'warmup', 'первая задача', 'чеклист'],
    panel: 'dashboard',
    illustration: 'firstRun',
    steps: [
      {
        title: 'Проверьте здоровье системы',
        body: 'Откройте Дашборд и Мониторинг: worker в статусе online, Redis/MySQL без ошибок, CPU/RAM в норме. Если воркер мёртв — задачи останутся в pending/queued.',
        tip: 'Health API: /api/v1/health должен отвечать ok:true, workers ≥ 1.',
      },
      {
        title: 'Добавьте рабочий прокси',
        body: 'Раздел «Прокси» → Создать. Укажите host, port, protocol (http/socks5), при необходимости login/password и country. Дождитесь health=ok после проверки монитором.',
        tip: 'Без живого прокси прогрев и ПФ часто падают на сеть/гео.',
      },
      {
        title: 'Создайте аккаунт и привяжите ресурсы',
        body: '«Аккаунты» → Создать (или bulk). Назначьте proxy_id, сгенерируйте fingerprint (FP). Статус лучше сразу ready, если аккаунт готов к работе.',
        tip: 'Кнопка «Прокси+FP» на выделенных строках ускоряет массовую подготовку.',
      },
      {
        title: 'Подготовьте сценарий',
        body: '«Сценарии» → создайте warmup или синхронизируйте JSON. Минимальный набор шагов: goto → dwell/scroll → screenshot. Цель для теста — локальный или ваш URL.',
        tip: 'Для приёмки используйте простой warmup без внешних капч.',
      },
      {
        title: 'Поставьте задачу и дождитесь done',
        body: '«Задачи» → Создать: type=warmup, account_id, scenario_id. Следите: pending → queued → running → done. Смотрите screenshots и cookies профиля.',
        tip: 'Долгий pending при CPU 100% — governor throttle. Снизьте нагрузку или MAX_CHROME.',
      },
    ],
    checklist: [
      'Мониторинг: worker online',
      'Прокси health=ok',
      'Аккаунт с proxy + fingerprint',
      'Сценарий с валидными шагами',
      'Задача дошла до done + есть screenshot',
    ],
  },
  {
    id: 'pipeline',
    category: 'start',
    title: 'Как устроен пайплайн Farm',
    summary: 'Схема: ресурсы → сценарий → очередь → worker → профиль Chrome.',
    tags: ['архитектура', 'очередь', 'redis', 'worker', 'chrome'],
    illustration: 'pipeline',
    steps: [
      {
        title: 'Ресурсы',
        body: 'Прокси и аккаунты хранятся в MySQL. Fingerprint и путь профиля привязаны к аккаунту. Sticky-прокси снижает смену IP.',
      },
      {
        title: 'Планирование',
        body: 'Планировщик читает расписания/кампании, создаёт tasks в MySQL и кладёт работу в Redis (farm:q:tasks) с учётом governor (CPU/RAM/слоты Chrome).',
      },
      {
        title: 'Исполнение',
        body: 'Worker забирает задачу, поднимает display/Xvfb, запускает headful Chrome с профилем, гоняет шаги сценария, шлёт результат на /internal/task/result.',
      },
      {
        title: 'Наблюдаемость',
        body: 'Монитор пишет метрики и алерты (в т.ч. worker_down). Логи и отчёты показывают ошибки, CPU/RAM Chrome, скриншоты прогонов.',
      },
    ],
  },
  {
    id: 'proxies',
    category: 'ops',
    title: 'Прокси: пул, health и гео',
    summary: 'Как завести прокси, понять статусы health и не блокировать очередь.',
    tags: ['прокси', 'proxy', 'health', 'geo', 'socks', 'http'],
    panel: 'proxies',
    steps: [
      {
        title: 'Создание',
        body: 'Укажите host, port, protocol, credentials. Country желателен для гео-фильтров кампаний. is_active=1 — иначе пул его не выдаст.',
      },
      {
        title: 'Проверка health',
        body: 'Монитор/proxy checker переводит unknown → ok|bad|dead. Для e2e/stub гео можно без внешних HTTP, в проде нужен доступ наружу.',
        tip: 'Массовый bad — проверьте логин/порт/бан провайдера, не только Farm.',
      },
      {
        title: 'Назначение',
        body: 'Аккаунтам назначайте прокси вручную или через bulk-provision. Один прокси на несколько аккаунтов возможен, но учитывайте лимиты провайдера.',
      },
    ],
    checklist: ['Хотя бы 1 прокси health=ok', 'Страна заполнена', 'Аккаунты ссылаются на proxy_id'],
  },
  {
    id: 'accounts',
    category: 'ops',
    title: 'Аккаунты: создание, FP, профиль',
    summary: 'Полный цикл подготовки браузерного аккаунта к прогреву и ПФ.',
    tags: ['аккаунты', 'fingerprint', 'fp', 'профиль', 'cookies', 'bulk'],
    panel: 'accounts',
    illustration: 'accounts',
    steps: [
      {
        title: 'Создайте аккаунт',
        body: 'Login/password — учётные данные целевого сервиса. Tags и group_id помогают фильтровать клиентов. Status: new → ready.',
      },
      {
        title: 'Назначьте прокси и fingerprint',
        body: 'Без FP Chrome стартует «голым». Сгенерируйте отпечаток (fp:generate / кнопка в панели). Проверьте UA, timezone, language.',
      },
      {
        title: 'Первый запуск профиля',
        body: 'После первой успешной задачи в storage/profiles появится каталог аккаунта, cookies snapshot — в storage/cookies. Это признак живого профиля.',
        tip: 'Live-view можно открыть с карточки аккаунта, если worker слушает :3000.',
      },
      {
        title: 'Массовое создание',
        body: '«Создать N» / CSV / bulk-provision: создаёт аккаунты и сразу вешает прокси+FP. Удобно для нового клиента.',
      },
    ],
  },
  {
    id: 'scenarios',
    category: 'ops',
    title: 'Сценарии: шаги и типы',
    summary: 'Соберите warmup/pf/service сценарий из шагов без поломки рантайма.',
    tags: ['сценарии', 'steps', 'warmup', 'pf', 'json'],
    panel: 'scenarios',
    steps: [
      {
        title: 'Типы',
        body: 'warmup — прогрев профиля; pf — поведенческий фактор/выдача; service — сервисные действия. Тип влияет на то, как кампании и планировщик их используют.',
      },
      {
        title: 'Редактор шагов',
        body: 'Каждый шаг: type + params + опциональные delay. Типичный warmup: goto → dwell → scroll → mouse_move → screenshot. Избегайте пустых обязательных полей.',
      },
      {
        title: 'Синхронизация из файлов',
        body: 'JSON в scenarios/ можно подтянуть CLI scenario:sync. В панели после sync сценарий появится в списке с id.',
        tip: 'Меняйте сценарий осторожно на живых кампаниях — старые задачи уже ссылаются на scenario_id.',
      },
    ],
  },
  {
    id: 'tasks',
    category: 'ops',
    title: 'Задачи и очередь',
    summary: 'Создание задач, статусы lifecycle и что делать при зависаниях.',
    tags: ['задачи', 'queue', 'pending', 'running', 'retry', 'done'],
    panel: 'tasks',
    illustration: 'queue',
    steps: [
      {
        title: 'Создание задачи',
        body: 'Нужны account_id, scenario_id (или scenario_name), type, timeout, max_attempts. Payload может содержать target_url и лимиты длительности.',
      },
      {
        title: 'Статусы',
        body: 'pending — ждёт enqueue; queued — в Redis; running — взял worker; retrying — ошибка с повтором; done/failed — финал. Смотрите task_runs для attempt/error/metrics.',
      },
      {
        title: 'Если задача «висит»',
        body: 'pending + THROTTLED:cpu — governor. queued без running — нет worker/слотов. running без финала — смотрите journal worker и kill orphan через GC.',
        tip: 'После kill -9 воркера монитор должен поднять worker_down, reclaim вернёт задачу в цикл.',
      },
    ],
  },
  {
    id: 'scheduler',
    category: 'ops',
    title: 'Планировщик и governor',
    summary: 'Расписания, ночное окно, троттлинг CPU/RAM и слоты Chrome.',
    tags: ['планировщик', 'scheduler', 'governor', 'cron', 'night'],
    panel: 'scheduler',
    steps: [
      {
        title: 'Расписания сценариев',
        body: 'Привяжите scenario_id к cron/окну. Планировщик периодически создаёт и промоутит задачи.',
      },
      {
        title: 'Governor',
        body: 'При высокой CPU/RAM enqueue останавливается (throttle). Нагрузка на хосте (другие сайты, Docker) тоже учитывается.',
        tip: 'На shared VPS держите MAX_CHROME низким (1–2), иначе очередь будет голодать.',
      },
      {
        title: 'Ночное окно',
        body: 'NIGHT_WINDOW_* ограничивает агрессивную постановку ночью. Проверьте настройки, если задачи «не идут» в ожидаемые часы.',
      },
    ],
  },
  {
    id: 'campaigns',
    category: 'campaigns',
    title: 'Кампании, ключи и цели',
    summary: 'Связка кампании с сценарием ПФ, keyword и target URL.',
    tags: ['кампании', 'keywords', 'targets', 'пф', 'выдача'],
    panel: 'campaigns',
    illustration: 'campaign',
    steps: [
      {
        title: 'Создайте кампанию',
        body: 'Имя, status=active, scenario_id (обычно pf), лимиты в meta/budget. Без активного статуса генерация задач может не идти.',
      },
      {
        title: 'Добавьте ключи',
        body: 'Раздел «Ключи»: фразы поиска, привязка к campaign_id. От них зависят search_yandex / серп-шаги.',
      },
      {
        title: 'Добавьте цели',
        body: '«Цели» — целевые URL/домены, которые сценарий должен найти или посетить. Сверьте с шагами click_serp_position / visit_service.',
      },
      {
        title: 'Запустите задачи ПФ',
        body: 'Через Tasks или API создайте задачи type=pf на подготовленные аккаунты. Контролируйте успешность в Отчётах и Мониторинге.',
      },
    ],
    checklist: ['Сценарий pf готов', 'Кампания active', 'Есть keywords', 'Есть targets', 'Аккаунты ready + proxy ok'],
  },
  {
    id: 'monitoring',
    category: 'system',
    title: 'Мониторинг и алерты',
    summary: 'Heartbeat воркеров, health прокси, Telegram-алерты.',
    tags: ['мониторинг', 'alerts', 'heartbeat', 'telegram', 'worker_down'],
    panel: 'monitoring',
    illustration: 'monitoring',
    steps: [
      {
        title: 'Что смотреть',
        body: 'Онлайн воркеров, доля мёртвых прокси, CPU/RAM, свежие алерты. Красный worker_down — очередь встанет.',
      },
      {
        title: 'Telegram',
        body: 'В настройках/.env задайте TG_TOKEN и TG_CHAT_ID. Иначе алерты только в БД/панели.',
      },
      {
        title: 'После инцидента',
        body: 'Подняли worker → дождитесь reclaim/retry. Проверьте, что застрявшие running ушли в retrying/queued.',
      },
    ],
  },
  {
    id: 'liveview',
    category: 'ops',
    title: 'Live-view сессии Chrome',
    summary: 'Как открыть живой экран аккаунта и что нужно для VNC-стека.',
    tags: ['live', 'vnc', 'novnc', 'screen', 'сессия'],
    panel: 'accounts',
    illustration: 'liveview',
    steps: [
      {
        title: 'Условия',
        body: 'Worker запущен, для аккаунта есть активная/недавняя сессия или onSessionStart поднимает Xvfb+Chrome. Порт воркера (обычно 3000) доступен с API-хоста.',
      },
      {
        title: 'Открытие',
        body: 'Из аккаунта / live-view UI: кадр PNG или noVNC. Ambient :99 — общий экран; сессии используют DISPLAY_BASE+N.',
      },
      {
        title: 'Если пусто',
        body: 'Нет X-сервера → «Missing X server». Порт VNC занят ambient-стеком → сдвиньте VNC_BASE_PORT. Проверьте farm-xvfb / display manager.',
      },
    ],
  },
  {
    id: 'logs-reports',
    category: 'system',
    title: 'Логи и отчёты',
    summary: 'Где смотреть ошибки прогонов, метрики Chrome и выгрузки.',
    tags: ['логи', 'отчёты', 'screenshots', 'metrics', 'ошибки'],
    panel: 'logs',
    steps: [
      {
        title: 'Логи',
        body: 'Панель «Логи» + journalctl на сервере (farm-worker@, farm-scheduler, farm-monitor). В task_runs.error — текст падения шага.',
      },
      {
        title: 'Отчёты',
        body: 'Агрегаты успешности, гео, топ ошибок. Используйте после серии задач, а не по одной.',
      },
      {
        title: 'Артефакты',
        body: 'Скриншоты — storage/screenshots и метаданные task_runs. Cookies — признак сохранения профиля.',
      },
    ],
  },
  {
    id: 'settings',
    category: 'system',
    title: 'Настройки и лимиты',
    summary: 'Ключевые knobs: MAX_CHROME, governor, интеграции captcha/SMS/TG.',
    tags: ['настройки', 'env', 'max_chrome', 'captcha', 'sms'],
    panel: 'settings',
    steps: [
      {
        title: 'Производительность',
        body: 'MAX_CHROME / WORKER_SLOTS ограничивают параллельность. На shared-хосте начинайте с 1–2.',
      },
      {
        title: 'Интеграции',
        body: 'Captcha/SMS ключи нужны только для соответствующих шагов сценария. Пустые ключи = шаги captcha_solve/sms упадут.',
      },
      {
        title: 'Безопасность',
        body: 'Не светите INTERNAL_TOKEN и DB password. После смены секретов перезапустите worker/scheduler.',
      },
    ],
  },
  {
    id: 'users-tokens',
    category: 'system',
    title: 'Пользователи, роли и API-токены',
    summary: 'Доступ в панель и Bearer-токены для автоматизации.',
    tags: ['пользователи', 'роли', 'токены', 'api', 'rbac'],
    panel: 'users',
    steps: [
      {
        title: 'Роли',
        body: 'admin — полный доступ; manager — операции; viewer — чтение. Создавайте отдельных людей, не шарьте admin.',
      },
      {
        title: 'Токены',
        body: '«API-токены» → выпустить. Plaintext показывается один раз. Заголовок Authorization: Bearer … для /api/v1.',
        tip: 'Для CI/скриптов заведите отдельного пользователя с минимальными правами.',
      },
      {
        title: 'Смена пароля админа',
        body: 'После первого входа смените admin@farm.local / admin123! (дефолт seed).',
      },
    ],
  },
  {
    id: 'troubleshooting',
    category: 'help',
    title: 'Типовые проблемы',
    summary: 'Шпаргалка: pending, Missing X, proxy bad, 421/SSL, пустой live-view.',
    tags: ['troubleshoot', 'ошибки', 'pending', 'xvfb', 'ssl', 'faq'],
    steps: [
      {
        title: 'Задачи вечно pending',
        body: 'Смотрите лог scheduler на THROTTLED:cpu_throttle. Снизьте нагрузку хоста, MAX_CHROME, дождитесь падения CPU ниже порога governor.',
      },
      {
        title: 'Missing X server',
        body: 'Headful Chrome без дисплея. Включите farm-xvfb / display manager; worker должен вызывать displays.start перед launch.',
      },
      {
        title: 'Прокси bad/dead',
        body: 'Проверьте credentials и IP allowlist у провайдера. Временно исключите прокси из пула (is_active=0).',
      },
      {
        title: 'Live-view / :16080 пустой',
        body: 'Websockify должен слушать 0.0.0.0, не только 127.0.0.1. Для сессий порты VNC не должны пересекаться с ambient 5900/6080.',
      },
      {
        title: 'Панель открывается, API 404 на /',
        body: 'Это нормально: корень — API router. UI живёт в /admin/.',
      },
    ],
  },
];

export function searchGuides(query) {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return DOC_GUIDES.slice();
  const tokens = q.split(/\s+/).filter(Boolean);
  return DOC_GUIDES.filter((g) => {
    const hay = [
      g.title,
      g.summary,
      g.id,
      ...(g.tags || []),
      ...(g.steps || []).flatMap((s) => [s.title, s.body, s.tip || '']),
      ...(g.checklist || []),
    ].join('\n').toLowerCase();
    return tokens.every((t) => hay.includes(t));
  });
}

export function getGuide(id) {
  return DOC_GUIDES.find((g) => g.id === id) || null;
}
