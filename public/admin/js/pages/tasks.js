import { Api } from '../api.js';
import { el, escapeHtml, badge, fmtDate } from '../utils.js';
import { createTable } from '../components/table.js';
import { openModal } from '../components/modal.js';
import { toast } from '../components/toast.js';
import { navigate } from '../router.js';

const STATUSES = ['pending', 'queued', 'running', 'done', 'failed', 'retrying', 'cancelled'];
const TYPES = ['register', 'warmup', 'pf', 'service', 'check'];

export async function renderTasks(root, { id } = {}) {
  if (id) return renderTaskDetail(root, id);

  root.innerHTML = '';
  let page = 1;
  let status = '';
  let type = '';

  const statusSel = el('select', { class: 'select', style: { width: '140px' } }, [
    el('option', { value: '', text: 'Статус' }),
    ...STATUSES.map((s) => el('option', { value: s, text: s })),
  ]);
  const typeSel = el('select', { class: 'select', style: { width: '140px' } }, [
    el('option', { value: '', text: 'Тип' }),
    ...TYPES.map((s) => el('option', { value: s, text: s })),
  ]);
  const createBtn = el('button', { class: 'btn btn-primary', type: 'button', text: '+ Задача' });
  const retryBtn = el('button', { class: 'btn', type: 'button', text: 'Повтор выбранных' });

  const toolbar = el('div', { class: 'toolbar' }, [
    statusSel, typeSel, el('span', { class: 'spacer' }), retryBtn, createBtn,
  ]);

  const table = createTable({
    selectable: true,
    columns: [
      { key: 'id', label: 'ID', class: 'mono' },
      { key: 'type', label: 'Type', type: 'badge' },
      { key: 'status', label: 'Status', type: 'badge' },
      { key: 'priority', label: 'Prio', class: 'mono' },
      { key: 'account_id', label: 'Acc', class: 'mono' },
      { key: 'scenario_id', label: 'Scn', class: 'mono' },
      { key: 'attempts', label: 'Try', render: (r) => `<span class="mono">${r.attempts}/${r.max_attempts}</span>` },
      { key: 'scheduled_at', label: 'Sched', render: (r) => `<span class="mono">${fmtDate(r.scheduled_at)}</span>` },
      {
        key: 'error',
        label: 'Error',
        render: (r) => `<span class="err-text">${escapeHtml((r.error || '').slice(0, 60))}</span>`,
      },
    ],
    onRowClick: (r) => navigate(`tasks/${r.id}`),
  });

  root.append(toolbar, table.el, table.pager);

  async function load() {
    const res = await Api.list('tasks', {
      page,
      per_page: 50,
      status: status || undefined,
      type: type || undefined,
    });
    table.setRows(res.data || [], res.meta);
  }
  table.on({ onPage: async (p) => { page = p; await load(); } });
  statusSel.onchange = async () => { status = statusSel.value; page = 1; await load(); };
  typeSel.onchange = async () => { type = typeSel.value; page = 1; await load(); };

  createBtn.onclick = () => openTaskForm(load);
  retryBtn.onclick = async () => {
    const ids = table.selectedIds();
    if (!ids.length) { toast('Выберите задачи', 'warn'); return; }
    for (const tid of ids) {
      try {
        await Api.update('tasks', tid, { status: 'pending', attempts: 0 });
      } catch (e) { toast(`#${tid}: ${e.message}`, 'error'); }
    }
    toast('Отправлено на повтор');
    await load();
  };

  await load();
  const t = setInterval(() => load().catch(() => {}), window.FarmConfig?.tablePollMs || 6000);
  return () => clearInterval(t);
}

function openTaskForm(onDone) {
  const type = el('select', { class: 'select' }, TYPES.map((t) => el('option', { value: t, text: t })));
  const priority = el('input', { class: 'input', type: 'number', value: '0' });
  const accountId = el('input', { class: 'input', type: 'number' });
  const scenarioId = el('input', { class: 'input', type: 'number' });
  const payload = el('textarea', {
    class: 'textarea',
    text: JSON.stringify({
      type: 'pf', account_id: 0, scenario_id: 0, target_url: null, keyword: null, geo: null, device: 'desktop', limits: {},
    }, null, 2),
  });
  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Создать' });
  const ui = openModal({
    title: 'Новая задача',
    body: el('div', {}, [
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'Type' }), type]),
        el('div', { class: 'field' }, [el('label', { text: 'Priority' }), priority]),
      ]),
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'account_id' }), accountId]),
        el('div', { class: 'field' }, [el('label', { text: 'scenario_id' }), scenarioId]),
      ]),
      el('div', { class: 'field' }, [el('label', { text: 'Payload JSON' }), payload]),
    ]),
    footer: save,
    wide: true,
  });
  save.onclick = async () => {
    let body;
    try { body = JSON.parse(payload.value); } catch { toast('Невалидный JSON', 'error'); return; }
    body.type = type.value;
    body.account_id = Number(accountId.value);
    body.scenario_id = Number(scenarioId.value);
    try {
      await Api.create('tasks', {
        type: type.value,
        priority: Number(priority.value) || 0,
        account_id: Number(accountId.value) || null,
        scenario_id: Number(scenarioId.value) || null,
        payload: body,
      });
      toast('Задача создана');
      ui.close();
      await onDone();
    } catch (e) { toast(e.message, 'error'); }
  };
}

async function renderTaskDetail(root, id) {
  root.innerHTML = '';
  const res = await Api.getOne('tasks', id);
  const task = res.data;
  const runs = await Api.tryGet(`/tasks/${id}/runs`);
  const events = await Api.tryGet(`/tasks/${id}/events`);

  root.append(
    el('div', { class: 'toolbar' }, [
      el('button', { class: 'btn btn-sm', type: 'button', text: '← Назад', onClick: () => navigate('tasks') }),
      el('strong', { html: `Task #${id} ${badge(task.status)}` }),
      el('span', { class: 'spacer' }),
      el('button', {
        class: 'btn btn-sm',
        type: 'button',
        text: 'Повтор',
        onClick: async () => {
          await Api.update('tasks', id, { status: 'pending' });
          toast('pending');
          navigate(`tasks/${id}`);
          location.reload();
        },
      }),
    ]),
    el('div', { class: 'grid-2' }, [
      el('div', { class: 'panel' }, [
        el('h3', { text: 'Детали' }),
        el('dl', {
          class: 'kv',
          html: `
            <dt>Type</dt><dd>${badge(task.type)}</dd>
            <dt>Priority</dt><dd class="mono">${task.priority}</dd>
            <dt>Account</dt><dd class="mono">${task.account_id ?? '—'}</dd>
            <dt>Scenario</dt><dd class="mono">${task.scenario_id ?? '—'}</dd>
            <dt>Attempts</dt><dd class="mono">${task.attempts}/${task.max_attempts}</dd>
            <dt>Scheduled</dt><dd class="mono">${fmtDate(task.scheduled_at)}</dd>
            <dt>Started</dt><dd class="mono">${fmtDate(task.started_at)}</dd>
            <dt>Finished</dt><dd class="mono">${fmtDate(task.finished_at)}</dd>
            <dt>Error</dt><dd class="err-text">${escapeHtml(task.error || '—')}</dd>`,
        }),
        el('h3', { text: 'Payload', style: { marginTop: '16px' } }),
        el('pre', { class: 'json-preview', text: JSON.stringify(task.payload || {}, null, 2) }),
      ]),
      el('div', { class: 'panel' }, [
        el('h3', { text: 'Логи по шагам / events' }),
        renderEvents(events?.data || runs?.data || []),
      ]),
    ]),
  );
}

function renderEvents(rows) {
  if (!rows.length) return el('div', { class: 'empty', text: 'Нет runs_events (GET /tasks/{id}/events)' });
  const wrap = el('div', { class: 'table-wrap' });
  wrap.innerHTML = `<table class="data"><thead><tr>
    <th>Time</th><th>Level</th><th>Event</th><th>Message</th>
  </tr></thead><tbody>${rows.map((e) => `<tr>
    <td class="mono">${fmtDate(e.created_at || e.started_at)}</td>
    <td>${badge(e.level || e.status || 'info')}</td>
    <td class="mono">${escapeHtml(e.event || e.attempt || '')}</td>
    <td>${escapeHtml(e.message || e.error || JSON.stringify(e.data || e.metrics || ''))}</td>
  </tr>`).join('')}</tbody></table>`;
  return wrap;
}
