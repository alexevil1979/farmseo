import { Api } from './api.js';
import { navigate, onRoute, startRouter, parseHash } from './router.js';
import { renderShell } from './components/layout.js';
import { renderLogin } from './pages/login.js';
import { renderDashboard } from './pages/dashboard.js';
import { renderAccounts } from './pages/accounts.js';
import { renderProxies } from './pages/proxies.js';
import { renderScenarios } from './pages/scenarios.js';
import { renderTasks } from './pages/tasks.js';
import { renderScheduler } from './pages/scheduler.js';
import { renderCampaigns, renderKeywords, renderTargets } from './pages/campaigns.js';
import { renderMonitoring } from './pages/monitoring.js';
import { renderLogs } from './pages/logs.js';
import { renderReports } from './pages/reports.js';
import { renderSettings } from './pages/settings.js';
import { renderUsers } from './pages/users.js';
import { renderTokens } from './pages/tokens.js';
import { renderDocs } from './pages/docs.js';
import { toast } from './components/toast.js';

const TITLES = {
  dashboard: 'Дашборд',
  docs: 'Документация',
  accounts: 'Аккаунты',
  proxies: 'Прокси',
  scenarios: 'Сценарии',
  tasks: 'Задачи и очередь',
  scheduler: 'Планировщик',
  campaigns: 'Кампании',
  keywords: 'Ключи',
  targets: 'Цели',
  monitoring: 'Мониторинг',
  logs: 'Логи',
  reports: 'Отчёты',
  settings: 'Настройки',
  users: 'Пользователи и роли',
  tokens: 'API-токены',
};

const PAGES = {
  dashboard: (el) => renderDashboard(el),
  docs: (el, s) => renderDocs(el, s),
  accounts: (el, s) => renderAccounts(el, { id: s.id }),
  proxies: (el) => renderProxies(el),
  scenarios: (el, s) => renderScenarios(el, { id: s.id }),
  tasks: (el, s) => renderTasks(el, { id: s.id }),
  scheduler: (el) => renderScheduler(el),
  campaigns: (el) => renderCampaigns(el),
  keywords: (el) => renderKeywords(el),
  targets: (el) => renderTargets(el),
  monitoring: (el) => renderMonitoring(el),
  logs: (el) => renderLogs(el),
  reports: (el) => renderReports(el),
  settings: (el) => renderSettings(el),
  users: (el) => renderUsers(el),
  tokens: (el) => renderTokens(el),
};

async function boot() {
  await (window.FarmConfigReady || Promise.resolve());
  const app = document.getElementById('app');
  let shell = null;
  let cleanup = null;
  let user = Api.getAuth()?.user || null;
  let lastRouteKey = '';

  async function ensureSession() {
    const auth = Api.getAuth();
    if (!auth?.token) return false;
    try {
      const me = await Api.me();
      user = me.data?.user || auth.user;
      if (me.data?.csrf) Api.setAuth({ ...auth, csrf: me.data.csrf, user });
      return true;
    } catch {
      Api.clearAuth();
      return false;
    }
  }

  async function showLogin() {
    if (cleanup) { cleanup(); cleanup = null; }
    if (shell) { shell.destroy(); shell = null; }
    lastRouteKey = '';
    app.innerHTML = '';
    app.append(renderLogin({
      onSuccess: (data) => {
        user = data.user;
        if (!location.hash || location.hash === '#') navigate('dashboard', true);
        showApp();
      },
    }));
  }

  async function showApp() {
    if (shell) { shell.destroy(); shell = null; }
    shell = renderShell({
      user,
      title: TITLES.dashboard,
      onNavigate: (route) => navigate(route),
      onLogout: async () => {
        try { await Api.logout(); } catch { /* ignore */ }
        Api.clearAuth();
        user = null;
        showLogin();
      },
    });
    app.innerHTML = '';
    app.append(shell.el);
    await renderCurrent();
  }

  async function renderCurrent() {
    if (!shell) return;
    const state = parseHash();

    const route = PAGES[state.route] ? state.route : 'dashboard';
    if (!PAGES[state.route]) {
      navigate('dashboard', true);
      return;
    }

    const key = `${route}/${state.id || ''}/${state.params?.q || ''}`;
    lastRouteKey = key;

    shell.setActive(route);
    shell.setTitle(TITLES[route] || route);
    if (cleanup) { cleanup(); cleanup = null; }
    shell.content.innerHTML = '<div class="empty">Загрузка…</div>';
    try {
      const result = await PAGES[route](shell.content, state);
      if (typeof result === 'function') cleanup = result;
    } catch (e) {
      shell.content.innerHTML = `<div class="empty err-text">${e.message || 'Ошибка загрузки'}</div>`;
      toast(e.message || 'Ошибка', 'error');
    }
  }

  window.addEventListener('farm:unauthorized', () => {
    Api.clearAuth();
    user = null;
    showLogin();
  });

  onRoute(() => {
    if (shell) renderCurrent();
  });
  startRouter();

  const ok = user ? await ensureSession() : false;
  if (!ok) await showLogin();
  else {
    if (!location.hash || location.hash === '#') navigate('dashboard', true);
    await showApp();
  }
}

boot().catch((e) => {
  document.getElementById('app').innerHTML = `<div class="boot err-text">${e.message}</div>`;
});
