import { Api } from '../api.js';
import { el, escapeHtml, badge, fmtDate } from '../utils.js';
import { doughnutChart, barChart } from '../components/charts.js';
import { toast } from '../components/toast.js';

export async function renderMonitoring(root) {
  root.innerHTML = '';
  const kpi = el('div', { class: 'kpi-grid' });
  const charts = el('div', { class: 'grid-2' });
  const alertsHost = el('div', { class: 'panel' });
  const healthHost = el('div', { class: 'panel' });
  root.append(kpi, charts, el('div', { class: 'grid-2', style: { marginTop: '14px' } }, [alertsHost, healthHost]));

  const proxyCanvas = el('canvas');
  const taskCanvas = el('canvas');
  charts.append(
    el('div', { class: 'panel' }, [el('h3', { text: 'Прокси health' }), el('div', { class: 'chart-box sm' }, [proxyCanvas])]),
    el('div', { class: 'panel' }, [el('h3', { text: 'Задачи по статусам' }), el('div', { class: 'chart-box sm' }, [taskCanvas])]),
  );

  async function tick() {
    try {
      const [stats, health, alerts] = await Promise.all([
        Api.stats(),
        Api.health().catch(() => ({ ok: false })),
        Api.alerts({ per_page: 30, is_acked: 0 }).catch(() => null),
      ]);
      const st = stats.data || {};
      kpi.innerHTML = '';
      [
        ['Queue', st.queue_depth],
        ['Chrome', `${st.chrome_active || 0}/${st.max_chrome || '—'}`],
        ['Workers', health.workers ?? '—'],
        ['DB', health.db ? 'ok' : 'down'],
        ['Redis', health.redis ? 'ok' : 'down'],
        ['API', health.ok ? 'ok' : 'degraded'],
      ].forEach(([label, value]) => {
        kpi.append(el('div', { class: 'kpi' }, [
          el('div', { class: 'label', text: label }),
          el('div', { class: 'value', text: String(value ?? '—'), style: { fontSize: '20px' } }),
        ]));
      });

      const proxies = st.proxies || {};
      doughnutChart(proxyCanvas, Object.keys(proxies), Object.values(proxies).map(Number));
      const tasks = st.tasks || {};
      barChart(taskCanvas, Object.keys(tasks), Object.values(tasks).map(Number), '#3ecf8e');

      const rows = alerts?.data || [];
      alertsHost.innerHTML = `<h3>Алерты</h3>`;
      if (!rows.length) {
        alertsHost.append(el('div', { class: 'empty', text: 'Нет активных алертов' }));
      } else {
        const wrap = el('div', { class: 'table-wrap' });
        wrap.innerHTML = `<table class="data"><thead><tr>
          <th>Sev</th><th>Source</th><th>Message</th><th>At</th><th></th>
        </tr></thead><tbody>${rows.map((a) => `<tr>
          <td>${badge(a.severity)}</td>
          <td class="mono">${escapeHtml(a.source)}</td>
          <td>${escapeHtml(a.message)}</td>
          <td class="mono">${fmtDate(a.created_at)}</td>
          <td><button class="btn btn-sm" data-ack="${a.id}">ack</button></td>
        </tr>`).join('')}</tbody></table>`;
        alertsHost.append(wrap);
        wrap.addEventListener('click', async (e) => {
          const b = e.target.closest('[data-ack]');
          if (!b) return;
          try {
            await Api.ackAlert(b.getAttribute('data-ack'));
            toast('acked');
            await tick();
          } catch (err) { toast(err.message, 'error'); }
        });
      }

      healthHost.innerHTML = '';
      healthHost.append(
        el('h3', { text: 'Health /api/v1/health' }),
        el('pre', { class: 'json-preview', text: JSON.stringify(health, null, 2) }),
        el('h3', { text: 'Stats snapshot', style: { marginTop: '12px' } }),
        el('pre', { class: 'json-preview', text: JSON.stringify(st, null, 2) }),
      );
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  await tick();
  const timer = setInterval(tick, window.FarmConfig?.refreshIntervalMs || 5000);
  return () => clearInterval(timer);
}
