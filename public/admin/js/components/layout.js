import { el } from '../utils.js';

const NAV = [
  {
    title: 'Обзор',
    items: [
      { route: 'dashboard', label: 'Дашборд', ico: '▣' },
      { route: 'monitoring', label: 'Мониторинг', ico: '◉' },
      { route: 'docs', label: 'Документация', ico: '◇' },
    ],
  },
  {
    title: 'Операции',
    items: [
      { route: 'accounts', label: 'Аккаунты', ico: '◈' },
      { route: 'proxies', label: 'Прокси', ico: '⇄' },
      { route: 'scenarios', label: 'Сценарии', ico: '☰' },
      { route: 'tasks', label: 'Задачи', ico: '▹' },
      { route: 'scheduler', label: 'Планировщик', ico: '◷' },
    ],
  },
  {
    title: 'Кампании',
    items: [
      { route: 'campaigns', label: 'Кампании', ico: '▦' },
      { route: 'keywords', label: 'Ключи', ico: '✦' },
      { route: 'targets', label: 'Цели', ico: '◎' },
    ],
  },
  {
    title: 'Система',
    items: [
      { route: 'logs', label: 'Логи', ico: '▤' },
      { route: 'reports', label: 'Отчёты', ico: '▥' },
      { route: 'settings', label: 'Настройки', ico: '⚙' },
      { route: 'users', label: 'Пользователи', ico: '☺' },
      { route: 'tokens', label: 'API-токены', ico: '⚿' },
    ],
  },
];

export function renderShell({ user, title, onNavigate, onLogout }) {
  const root = el('div', { class: 'shell' });
  const sidebar = el('aside', { class: 'sidebar' });
  const main = el('div', { class: 'main' });
  const topbar = el('header', { class: 'topbar' });
  const content = el('main', { class: 'content', id: 'page-root' });

  sidebar.append(
    el('div', { class: 'logo' }, [
      el('strong', { html: `${(window.FarmConfig?.appName || 'Farm')}<span>.</span>` }),
      el('small', { text: 'admin' }),
    ]),
  );

  NAV.forEach((group) => {
    const g = el('div', { class: 'nav-group' });
    g.append(el('div', { class: 'nav-group-title', text: group.title }));
    group.items.forEach((item) => {
      const btn = el('button', {
        class: 'nav-link',
        type: 'button',
        'data-route': item.route,
        html: `<span class="ico">${item.ico}</span><span>${item.label}</span>`,
        onClick: () => onNavigate(item.route),
      });
      g.append(btn);
    });
    sidebar.append(g);
  });

  const titleEl = el('h1', { text: title || 'Дашборд' });
  const meta = el('div', { class: 'topbar-meta' }, [
    el('span', { class: 'live-dot', title: 'live' }),
    el('span', { id: 'live-clock', text: '' }),
    el('span', { text: user?.email || user?.name || 'user' }),
    el('button', {
      class: 'btn btn-sm',
      type: 'button',
      text: 'Выйти',
      onClick: onLogout,
    }),
  ]);
  topbar.append(titleEl, meta);
  main.append(topbar, content);
  root.append(sidebar, main);

  function setActive(route) {
    sidebar.querySelectorAll('.nav-link').forEach((n) => {
      n.classList.toggle('active', n.getAttribute('data-route') === route);
    });
  }

  function setTitle(t) {
    titleEl.textContent = t;
  }

  const clock = meta.querySelector('#live-clock');
  const tick = () => {
    clock.textContent = new Date().toLocaleTimeString('ru-RU', { hour12: false });
  };
  tick();
  const clockTimer = setInterval(tick, 1000);

  return {
    el: root,
    content,
    setActive,
    setTitle,
    destroy() {
      clearInterval(clockTimer);
    },
  };
}
