import { Api } from '../api.js';
import { el, toCsv, downloadBlob, fmtDate } from '../utils.js';
import { toast } from '../components/toast.js';

export async function renderReports(root) {
  root.innerHTML = '';
  const from = el('input', { class: 'input', type: 'date' });
  const to = el('input', { class: 'input', type: 'date' });
  const preview = el('pre', { class: 'json-preview' });
  const summary = el('div', { class: 'kpi-grid' });

  root.append(
    el('div', { class: 'toolbar' }, [
      el('div', { class: 'field', style: { margin: 0 } }, [el('label', { text: 'С' }), from]),
      el('div', { class: 'field', style: { margin: 0 } }, [el('label', { text: 'По' }), to]),
      el('button', { class: 'btn', type: 'button', text: 'Собрать', onClick: () => build() }),
      el('span', { class: 'spacer' }),
      el('button', { class: 'btn btn-primary', type: 'button', text: 'CSV', onClick: () => exportCsv() }),
      el('button', { class: 'btn', type: 'button', text: 'PDF', onClick: () => exportPdf() }),
    ]),
    summary,
    el('div', { class: 'panel' }, [el('h3', { text: 'Данные отчёта' }), preview]),
  );

  let report = null;

  async function build() {
    try {
      const [stats, tasks, accounts] = await Promise.all([
        Api.stats(),
        Api.list('tasks', { page: 1, per_page: 200 }),
        Api.list('accounts', { page: 1, per_page: 200 }),
      ]);
      const taskRows = (tasks.data || []).filter((t) => inRange(t.created_at));
      const accRows = accounts.data || [];
      report = {
        generated_at: new Date().toISOString(),
        range: { from: from.value || null, to: to.value || null },
        stats: stats.data || {},
        tasks_sample: taskRows.length,
        accounts_total: accRows.length,
        by_task_status: countBy(taskRows, 'status'),
        by_account_status: countBy(accRows, 'status'),
        avg_trust: accRows.length
          ? (accRows.reduce((a, r) => a + Number(r.trust_score || 0), 0) / accRows.length).toFixed(2)
          : '0.00',
      };
      preview.textContent = JSON.stringify(report, null, 2);
      summary.innerHTML = '';
      [
        ['Tasks in range', report.tasks_sample],
        ['Accounts', report.accounts_total],
        ['Avg trust', report.avg_trust],
        ['Done', report.by_task_status.done || 0],
        ['Failed', report.by_task_status.failed || 0],
        ['Queue', report.stats.queue_depth ?? '—'],
      ].forEach(([label, value]) => {
        summary.append(el('div', { class: 'kpi' }, [
          el('div', { class: 'label', text: label }),
          el('div', { class: 'value', text: String(value) }),
        ]));
      });
      toast('Отчёт собран из /stats + CRUD');
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  function inRange(ts) {
    if (!ts) return true;
    const d = new Date(ts).getTime();
    if (from.value && d < new Date(from.value).getTime()) return false;
    if (to.value && d > new Date(to.value).getTime() + 86400000) return false;
    return true;
  }

  function countBy(rows, key) {
    const out = {};
    rows.forEach((r) => { out[r[key] || 'unknown'] = (out[r[key] || 'unknown'] || 0) + 1; });
    return out;
  }

  async function exportCsv() {
    if (!report) await build();
    if (!report) return;
    const rows = Object.entries(report.by_task_status).map(([status, count]) => ({
      metric: 'task_status', status, count,
    }));
    Object.entries(report.by_account_status).forEach(([status, count]) => {
      rows.push({ metric: 'account_status', status, count });
    });
    downloadBlob(`farm-report-${Date.now()}.csv`, toCsv(['metric', 'status', 'count'], rows));
    toast('CSV скачан');
  }

  function exportPdf() {
    if (!report) {
      toast('Сначала соберите отчёт', 'warn');
      return;
    }
    // Printable HTML → user saves as PDF (no extra libs)
    const w = window.open('', '_blank');
    if (!w) { toast('Popup blocked', 'warn'); return; }
    w.document.write(`<!doctype html><html><head><title>Farm Report</title>
      <style>body{font-family:system-ui;padding:24px} pre{background:#111;color:#eee;padding:12px;border-radius:8px}</style>
      </head><body>
      <h1>Farm Report</h1>
      <p>${fmtDate(report.generated_at)}</p>
      <pre>${JSON.stringify(report, null, 2).replace(/</g, '&lt;')}</pre>
      <script>onload=()=>print()</script>
      </body></html>`);
    w.document.close();
  }

  await build();
}
