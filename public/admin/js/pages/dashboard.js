import { Api } from '../api.js';
import {
  el, fmtNum, fmtScore, badge, escapeHtml, sumValues, avgTrust, fmtDate,
} from '../utils.js';
import { barChart, lineChart, createSeries } from '../components/charts.js';
import { toast } from '../components/toast.js';

export async function renderDashboard(root) {
  root.innerHTML = '';
  const kpiHost = el('div', { class: 'kpi-grid' });
  const charts = el('div', { class: 'grid-2', style: { marginBottom: '14px' } });
  const bottom = el('div', { class: 'grid-2' });
  root.append(kpiHost, charts, bottom);

  const tasksCanvas = el('canvas');
  const successCanvas = el('canvas');
  const geoCanvas = el('canvas');
  const errorsCanvas = el('canvas');

  charts.append(
    el('div', { class: 'panel' }, [
      el('h3', { text: 'Задачи (live)' }),
      el('div', { class: 'chart-box' }, [tasksCanvas]),
    ]),
    el('div', { class: 'panel' }, [
      el('h3', { text: 'Успешность' }),
      el('div', { class: 'chart-box' }, [successCanvas]),
    ]),
  );

  const chromeHost = el('div', { class: 'table-wrap' });
  const errorsHost = el('div');

  bottom.append(
    el('div', { class: 'panel' }, [
      el('h3', { text: 'Гео (прокси / кампании)' }),
      el('div', { class: 'chart-box sm' }, [geoCanvas]),
    ]),
    el('div', { class: 'panel' }, [
      el('h3', { text: 'Топ ошибок' }),
      errorsHost,
    ]),
  );

  root.append(
    el('div', { class: 'panel', style: { marginTop: '14px' } }, [
      el('h3', { html: 'Живой список Chrome <span class="muted">CPU / RAM</span>' }),
      chromeHost,
    ]),
  );

  const series = createSeries(30);
  let stopped = false;

  function kpi(label, value, hint = '') {
    return el('div', { class: 'kpi' }, [
      el('div', { class: 'label', text: label }),
      el('div', { class: 'value', text: value }),
      hint ? el('div', { class: 'hint', text: hint }) : null,
    ]);
  }

  async function tick() {
    if (stopped) return;
    try {
      const [statsRes, accountsRes, proxiesRes, tasksRes, alertsRes, metricsRes] = await Promise.all([
        Api.stats(),
        Api.list('accounts', { per_page: 100, page: 1 }),
        Api.list('proxies', { per_page: 200, page: 1 }).catch(() => ({ data: [] })),
        Api.list('tasks', { per_page: 50, page: 1 }).catch(() => ({ data: [] })),
        Api.alerts({ per_page: 20 }).catch(() => null),
        Api.metrics({ entity_type: 'worker', per_page: 50 }).catch(() => null),
      ]);

      const st = statsRes.data || {};
      const accBy = st.accounts || {};
      const taskBy = st.tasks || {};
      const accounts = accountsRes.data || [];
      const proxies = proxiesRes.data || [];
      const tasks = tasksRes.data || [];

      const total = sumValues(accBy) || accounts.length;
      const active = Number(accBy.active || 0);
      const warming = Number(accBy.warming || 0);
      const working = Number(taskBy.running || 0) + Number(st.chrome_active || 0);
      const errors = Number(accBy.error || 0) + Number(accBy.banned || 0);
      const trust = avgTrust(accounts);

      kpiHost.innerHTML = '';
      kpiHost.append(
        kpi('Всего', fmtNum(total)),
        kpi('Активных', fmtNum(active)),
        kpi('В работе', fmtNum(working), `chrome ${st.chrome_active || 0}/${st.max_chrome || '—'}`),
        kpi('Прогретых', fmtNum(warming + Number(accBy.ready || 0))),
        kpi('Ошибок', fmtNum(errors)),
        kpi('Trust avg', fmtScore(trust)),
      );

      const nowLabel = new Date().toLocaleTimeString('ru-RU', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      const done = Number(taskBy.done || 0);
      const failed = Number(taskBy.failed || 0);
      const successRate = done + failed > 0 ? Math.round((done / (done + failed)) * 100) : 0;
      series.push(nowLabel, {
        queued: Number(taskBy.queued || 0) + Number(st.queue_depth || 0),
        running: Number(taskBy.running || 0),
        done,
        failed,
        success: successRate,
      });

      lineChart(tasksCanvas, series.labels(), [
        { label: 'queued', data: series.get('queued') },
        { label: 'running', data: series.get('running') },
        { label: 'done', data: series.get('done') },
        { label: 'failed', data: series.get('failed') },
      ]);
      lineChart(successCanvas, series.labels(), [
        { label: 'success %', data: series.get('success'), color: '#3ecf8e' },
      ]);

      const geoMap = {};
      proxies.forEach((p) => {
        const g = (p.country || '??').toUpperCase();
        geoMap[g] = (geoMap[g] || 0) + 1;
      });
      const geoLabels = Object.keys(geoMap).sort((a, b) => geoMap[b] - geoMap[a]).slice(0, 12);
      barChart(geoCanvas, geoLabels, geoLabels.map((k) => geoMap[k]), '#5b8def');

      const errCount = {};
      tasks.filter((t) => t.error).forEach((t) => {
        const key = String(t.error).slice(0, 80);
        errCount[key] = (errCount[key] || 0) + 1;
      });
      (alertsRes?.data || []).forEach((a) => {
        const key = a.message || a.source || 'alert';
        errCount[key] = (errCount[key] || 0) + 1;
      });
      const topErr = Object.entries(errCount).sort((a, b) => b[1] - a[1]).slice(0, 8);
      if (!topErr.length) {
        errorsHost.innerHTML = '<div class="empty">Ошибок нет</div>';
      } else {
        errorsHost.innerHTML = `<table class="data"><thead><tr><th>Ошибка</th><th>N</th></tr></thead><tbody>${
          topErr.map(([m, n]) => `<tr><td>${escapeHtml(m)}</td><td class="mono">${n}</td></tr>`).join('')
        }</tbody></table>`;
      }

      // Chrome live list: farm:cfg:chrome_sessions via /stats, then metrics, then running tasks
      const chromeRows = [];
      const sessions = Array.isArray(st.chrome_sessions) ? st.chrome_sessions : [];
      if (sessions.length) {
        const byId = Object.fromEntries(accounts.map((a) => [a.id, a]));
        sessions.forEach((s) => {
          const acc = byId[s.account_id];
          chromeRows.push({
            id: s.account_id,
            name: acc?.login || `acc-${s.account_id}`,
            cpu: s.cpu_pct != null ? `${s.cpu_pct}%` : '—',
            ram: s.ram_mb != null ? `${s.ram_mb} MB` : '—',
            at: null,
            status: s.task_id ? 'running' : 'active',
          });
        });
      } else {
        const metrics = metricsRes?.data || [];
        if (metrics.length) {
          metrics.forEach((m) => {
            const meta = m.meta && typeof m.meta === 'object' ? m.meta : {};
            chromeRows.push({
              id: m.entity_id || m.id,
              name: m.name || `worker-${m.entity_id ?? m.id}`,
              cpu: meta.cpu ?? meta.cpu_pct ?? (m.name === 'cpu' ? m.value : '—'),
              ram: meta.ram ?? meta.ram_mb ?? (m.name === 'ram' ? m.value : '—'),
              at: m.captured_at,
              status: meta.status,
            });
          });
        } else {
          const runningAccounts = new Set(
            tasks.filter((t) => t.status === 'running' && t.account_id).map((t) => t.account_id),
          );
          accounts
            .filter((a) => runningAccounts.has(a.id) || a.status === 'active')
            .slice(0, 40)
            .forEach((a) => {
              chromeRows.push({
                id: a.id,
                name: a.login,
                cpu: a.cpu ?? a.meta?.cpu ?? '—',
                ram: a.ram ?? a.meta?.ram ?? '—',
                at: a.last_activity,
                status: a.status,
              });
            });
        }
      }

      chromeHost.innerHTML = `<table class="data"><thead><tr>
        <th>ID</th><th>Профиль</th><th>CPU</th><th>RAM</th><th>Статус</th><th>Активность</th>
      </tr></thead><tbody>${chromeRows.map((r) => `<tr>
        <td class="mono">${escapeHtml(r.id)}</td>
        <td>${escapeHtml(r.name)}</td>
        <td class="mono">${escapeHtml(r.cpu)}</td>
        <td class="mono">${escapeHtml(r.ram)}</td>
        <td>${r.status ? badge(r.status) : '—'}</td>
        <td class="mono">${fmtDate(r.at)}</td>
      </tr>`).join('')}</tbody></table>`;
    } catch (e) {
      toast(e.message || 'Ошибка дашборда', 'error');
    }
  }

  await tick();
  const timer = setInterval(tick, window.FarmConfig?.chartRefreshMs || 8000);

  return () => {
    stopped = true;
    clearInterval(timer);
  };
}
