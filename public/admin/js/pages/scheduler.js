import { Api } from '../api.js';
import { el, escapeHtml, badge, fmtDate } from '../utils.js';
import { openModal } from '../components/modal.js';
import { toast } from '../components/toast.js';

export async function renderScheduler(root) {
  root.innerHTML = '';
  const cal = el('div', { class: 'panel' });
  const rules = el('div', { class: 'panel' });
  const toolbar = el('div', { class: 'toolbar' }, [
    el('strong', { text: 'Планировщик' }),
    el('span', { class: 'spacer' }),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: '+ Cron-правило',
      onClick: () => openRuleForm(null, refresh),
    }),
  ]);
  root.append(toolbar, el('div', { class: 'grid-2' }, [cal, rules]));

  async function refresh() {
    const data = await Api.schedules({ per_page: 200 });
    const rows = data?.data || [];
    renderCalendar(cal, rows);
    renderRules(rules, rows, refresh);
  }

  await refresh();
  const t = setInterval(() => refresh().catch(() => {}), window.FarmConfig?.tablePollMs || 6000);
  return () => clearInterval(t);
}

function renderCalendar(host, rows) {
  const now = new Date();
  const year = now.getFullYear();
  const month = now.getMonth();
  const first = new Date(year, month, 1);
  const startPad = (first.getDay() + 6) % 7;
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const byDay = {};
  rows.forEach((r) => {
    const d = r.next_run_at || r.last_run_at;
    if (!d) return;
    const dt = new Date(d);
    if (dt.getMonth() !== month || dt.getFullYear() !== year) return;
    const key = dt.getDate();
    (byDay[key] ||= []).push(r);
  });

  const grid = el('div', { class: 'cal-grid' });
  ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'].forEach((d) => {
    grid.append(el('div', { class: 'muted', style: { textAlign: 'center', fontSize: '11px' }, text: d }));
  });
  for (let i = 0; i < startPad; i += 1) grid.append(el('div'));
  for (let day = 1; day <= daysInMonth; day += 1) {
    const cell = el('div', { class: 'cal-cell' }, [el('div', { class: 'd', text: String(day) })]);
    (byDay[day] || []).slice(0, 3).forEach((r) => {
      cell.append(el('div', {
        class: 'evt',
        title: r.cron_expr,
        text: `#${r.scenario_id} ${r.cron_expr}`,
      }));
    });
    grid.append(cell);
  }

  host.innerHTML = '';
  host.append(
    el('h3', { text: `Календарь · ${now.toLocaleString('ru-RU', { month: 'long', year: 'numeric' })}` }),
    grid,
  );
}

function renderRules(host, rows, onDone) {
  host.innerHTML = '';
  host.append(el('h3', { text: 'Cron-правила' }));
  if (!rows.length) {
    host.append(el('div', {
      class: 'empty',
      text: 'Нет правил (GET /scenario_schedules). Создайте правило или дождитесь API.',
    }));
    return;
  }
  const wrap = el('div', { class: 'table-wrap' });
  wrap.innerHTML = `<table class="data"><thead><tr>
    <th>ID</th><th>Scenario</th><th>Cron</th><th>TZ</th><th>On</th><th>Next</th><th>Last</th><th></th>
  </tr></thead><tbody>${rows.map((r) => `<tr>
    <td class="mono">${r.id}</td>
    <td class="mono">${r.scenario_id}</td>
    <td class="mono">${escapeHtml(r.cron_expr)}</td>
    <td>${escapeHtml(r.timezone || 'UTC')}</td>
    <td>${r.is_enabled ? badge('ok') : badge('paused')}</td>
    <td class="mono">${fmtDate(r.next_run_at)}</td>
    <td class="mono">${fmtDate(r.last_run_at)}</td>
    <td><button class="btn btn-sm" data-edit="${r.id}">✏️</button>
        <button class="btn btn-sm btn-danger" data-del="${r.id}">✕</button></td>
  </tr>`).join('')}</tbody></table>`;
  host.append(wrap);
  wrap.addEventListener('click', async (e) => {
    const edit = e.target.closest('[data-edit]');
    const del = e.target.closest('[data-del]');
    if (edit) {
      const row = rows.find((r) => String(r.id) === edit.getAttribute('data-edit'));
      openRuleForm(row, onDone);
    }
    if (del) {
      try {
        await Api.deleteSchedule(del.getAttribute('data-del'));
        toast('Удалено');
        await onDone();
      } catch (err) { toast(err.message, 'error'); }
    }
  });
}

function openRuleForm(existing, onDone) {
  const scenarioId = el('input', { class: 'input', type: 'number', value: existing?.scenario_id || '' });
  const cron = el('input', { class: 'input', value: existing?.cron_expr || '0 */6 * * *' });
  const tz = el('input', { class: 'input', value: existing?.timezone || 'UTC' });
  const enabled = el('select', { class: 'select' }, [
    el('option', { value: '1', text: 'enabled', selected: existing?.is_enabled !== 0 }),
    el('option', { value: '0', text: 'disabled', selected: existing?.is_enabled === 0 }),
  ]);
  const payload = el('textarea', {
    class: 'textarea',
    text: JSON.stringify(existing?.payload_override || {}, null, 2),
  });
  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Сохранить' });
  const ui = openModal({
    title: existing ? `Правило #${existing.id}` : 'Новое cron-правило',
    body: el('div', {}, [
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'scenario_id' }), scenarioId]),
        el('div', { class: 'field' }, [el('label', { text: 'Enabled' }), enabled]),
      ]),
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'cron_expr' }), cron]),
        el('div', { class: 'field' }, [el('label', { text: 'timezone' }), tz]),
      ]),
      el('div', { class: 'field' }, [el('label', { text: 'payload_override' }), payload]),
    ]),
    footer: save,
  });
  save.onclick = async () => {
    let override = {};
    try { override = JSON.parse(payload.value || '{}'); } catch { toast('JSON error', 'error'); return; }
    const body = {
      scenario_id: Number(scenarioId.value),
      cron_expr: cron.value.trim(),
      timezone: tz.value.trim() || 'UTC',
      is_enabled: Number(enabled.value),
      payload_override: override,
    };
    try {
      if (existing) await Api.updateSchedule(existing.id, body);
      else await Api.createSchedule(body);
      toast('Сохранено');
      ui.close();
      await onDone();
    } catch (e) { toast(e.message, 'error'); }
  };
}
