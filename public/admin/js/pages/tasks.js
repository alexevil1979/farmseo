import { Api } from '../api.js';
import { el, escapeHtml, badge, fmtDate } from '../utils.js';
import { createTable } from '../components/table.js';
import { openModal } from '../components/modal.js';
import { toast } from '../components/toast.js';
import { navigate } from '../router.js';

const STATUSES = ['pending', 'queued', 'running', 'done', 'failed', 'retrying', 'cancelled'];
const TYPES = ['register', 'warmup', 'pf', 'service', 'check'];
const LIVE_STATUSES = new Set(['running', 'queued', 'pending', 'retrying']);

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
  const retryBtn = el('button', { class: 'btn', type: 'button', text: 'Повтор отмеченных' });

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

/**
 * Task detail with embedded noVNC.
 * Shell DOM is built once; polls only patch text/tables — iframe never remounts.
 */
async function renderTaskDetail(root, id) {
  root.innerHTML = '';

  const titleEl = el('strong', { html: `Task #${id}` });
  const liveHint = el('span', { class: 'muted task-live-hint', text: '' });
  const toolbar = el('div', { class: 'toolbar' }, [
    el('button', { class: 'btn btn-sm', type: 'button', text: '← Назад', onClick: () => navigate('tasks') }),
    titleEl,
    el('span', { class: 'spacer' }),
    liveHint,
  ]);

  const detailsBox = el('div', { class: 'task-details-box' });
  const payloadBox = el('pre', { class: 'json-preview' });
  const runsBox = el('div', { class: 'task-runs-box' });
  const leftPanel = el('div', { class: 'panel' }, [
    el('h3', { text: 'Детали' }),
    detailsBox,
    el('h3', { text: 'Payload', style: { marginTop: '16px' } }),
    payloadBox,
    runsBox,
  ]);

  const liveStatus = el('span', { class: 'muted mono task-vnc-status', text: 'VNC: —' });
  const vncFrame = el('iframe', {
    class: 'live-view-frame task-vnc-frame',
    title: 'Live VNC',
    allow: 'clipboard-read; clipboard-write',
    // about:blank until we know account + want stream; never recreate the node
    src: 'about:blank',
  });
  const shotsBox = el('div', { class: 'task-shots-box' });
  const eventsBox = el('div', { class: 'task-events-box' });

  const popoutBtn = el('button', {
    class: 'btn btn-sm',
    type: 'button',
    text: 'В окне',
    disabled: true,
  });
  const reconnectBtn = el('button', {
    class: 'btn btn-sm',
    type: 'button',
    text: 'Переподключить VNC',
  });
  const accountBtn = el('button', {
    class: 'btn btn-sm',
    type: 'button',
    text: 'Аккаунт',
    disabled: true,
  });
  const retryBtn = el('button', {
    class: 'btn btn-sm',
    type: 'button',
    text: 'Повтор',
  });

  toolbar.append(popoutBtn, accountBtn, reconnectBtn, retryBtn);

  const rightPanel = el('div', { class: 'panel task-live-panel' }, [
    el('div', { class: 'task-vnc-head' }, [
      el('h3', { text: 'Live-view (VNC)', style: { margin: 0 } }),
      liveStatus,
    ]),
    vncFrame,
    el('p', {
      class: 'muted',
      style: { marginTop: '8px', fontSize: '12px' },
      text: 'Экран обновляется сам. Статус/логи справа снизу — без перезагрузки страницы, VNC не дёргается.',
    }),
    el('h3', { text: 'Скриншоты', style: { marginTop: '16px' } }),
    shotsBox,
    el('h3', { text: 'Логи по шагам / events', style: { marginTop: '16px' } }),
    eventsBox,
  ]);

  root.append(toolbar, el('div', { class: 'grid-2 task-detail-grid' }, [leftPanel, rightPanel]));

  /** @type {number|null} */
  let accountId = null;
  /** @type {string} */
  let boundVncUrl = '';
  let stopped = false;
  let pollTimer = 0;
  let hbTimer = 0;

  function vncUrlFor(accId) {
    return `/live/${accId}/vnc.html?autoconnect=1&resize=scale&reconnect=1`;
  }

  function connectVnc(accId, force = false) {
    if (!accId) return;
    const url = vncUrlFor(accId);
    if (!force && boundVncUrl === url && vncFrame.getAttribute('src') === url) return;
    boundVncUrl = url;
    // Only assign src when changed — avoids iframe reload flicker
    if (vncFrame.getAttribute('src') !== url) {
      vncFrame.setAttribute('src', url);
    }
    liveStatus.textContent = `VNC: account #${accId}`;
    popoutBtn.disabled = false;
  }

  function disconnectVnc(reason) {
    // Keep last frame painted; don't blank unless leaving the page
    liveStatus.textContent = reason || 'VNC: нет активного экрана';
  }

  popoutBtn.onclick = () => {
    if (!accountId) return;
    window.open(`live-view/?account_id=${encodeURIComponent(accountId)}`, '_blank', 'noopener');
  };
  reconnectBtn.onclick = () => {
    if (!accountId) {
      toast('Нет account_id у задачи', 'warn');
      return;
    }
    // Force remount stream once (user-initiated)
    vncFrame.setAttribute('src', 'about:blank');
    boundVncUrl = '';
    setTimeout(() => connectVnc(accountId, true), 50);
    toast('VNC переподключаю');
  };
  accountBtn.onclick = () => {
    if (accountId) navigate(`accounts/${accountId}`);
  };
  retryBtn.onclick = async () => {
    try {
      await Api.update('tasks', id, { status: 'pending', attempts: 0 });
      toast('pending');
      await refresh(false);
    } catch (e) {
      toast(e.message, 'error');
    }
  };

  function paint(task, runs, events) {
    const st = String(task.status || '');
    titleEl.innerHTML = `Task #${id} ${badge(st)}`;
    liveHint.textContent = LIVE_STATUSES.has(st)
      ? '· идёт / в очереди — смотри VNC справа'
      : '';

    accountId = task.account_id != null ? Number(task.account_id) : null;
    accountBtn.disabled = !accountId;

    detailsBox.innerHTML = `<dl class="kv">
      <dt>Type</dt><dd>${badge(task.type)}</dd>
      <dt>Priority</dt><dd class="mono">${task.priority}</dd>
      <dt>Account</dt><dd class="mono">${task.account_id ?? '—'}</dd>
      <dt>Scenario</dt><dd class="mono">${task.scenario_id ?? '—'}</dd>
      <dt>Attempts</dt><dd class="mono">${task.attempts}/${task.max_attempts}</dd>
      <dt>Scheduled</dt><dd class="mono">${fmtDate(task.scheduled_at)}</dd>
      <dt>Started</dt><dd class="mono">${fmtDate(task.started_at)}</dd>
      <dt>Finished</dt><dd class="mono">${fmtDate(task.finished_at)}</dd>
      <dt>Error</dt><dd class="err-text">${escapeHtml(task.error || '—')}</dd>
    </dl>`;

    const payloadText = JSON.stringify(task.payload || {}, null, 2);
    if (payloadBox.textContent !== payloadText) payloadBox.textContent = payloadText;

    runsBox.innerHTML = '';
    for (const node of renderRunsMeta(runs)) runsBox.append(node);

    const shots = collectShots(runs);
    shotsBox.innerHTML = '';
    shotsBox.append(renderShots(shots));

    eventsBox.innerHTML = '';
    eventsBox.append(renderEvents(events));

    // Attach VNC when we have an account. Prefer connecting while live/retrying;
    // keep stream after done so user can still peek during keep-open window.
    if (accountId) {
      if (LIVE_STATUSES.has(st) || st === 'done' || st === 'failed') {
        connectVnc(accountId);
      } else {
        disconnectVnc('VNC: ожидание запуска');
      }
    } else {
      disconnectVnc('VNC: нет account_id');
    }
  }

  async function refresh() {
    if (stopped) return;
    const res = await Api.getOne('tasks', id);
    const task = res.data;
    const runsRes = await Api.tryGet(`/tasks/${id}/runs`);
    const eventsRes = await Api.tryGet(`/tasks/${id}/events`);
    const runs = Array.isArray(runsRes?.data) ? runsRes.data : [];
    const events = Array.isArray(eventsRes?.data) ? eventsRes.data : [];
    paint(task, runs, events);

    // Soft touch so idle GC does not kill display while this page is open
    if (accountId && LIVE_STATUSES.has(String(task.status || ''))) {
      Api.liveView(accountId).catch(() => {});
    }
    return task;
  }

  await refresh();

  pollTimer = setInterval(() => {
    if (stopped) return;
    if (!location.hash.includes(`tasks/${id}`)) return;
    refresh().catch(() => {});
  }, 4000);

  hbTimer = setInterval(() => {
    if (stopped || !accountId) return;
    Api.liveView(accountId).catch(() => {});
  }, 60000);

  return () => {
    stopped = true;
    clearInterval(pollTimer);
    clearInterval(hbTimer);
    // Release iframe resources when leaving the route
    try { vncFrame.setAttribute('src', 'about:blank'); } catch { /* ignore */ }
  };
}

function collectShots(runs) {
  const out = [];
  for (const run of runs) {
    for (const s of (run.screenshots || [])) {
      out.push({
        ...s,
        attempt: run.attempt,
        run_id: run.id,
      });
    }
  }
  return out;
}

function renderShots(shots) {
  if (!shots.length) {
    return el('div', {
      class: 'empty',
      text: 'Скриншотов пока нет (появятся после шага screenshot / конца сценария)',
    });
  }
  const wrap = el('div', { class: 'screenshot-grid' });
  wrap.innerHTML = shots.map((s) => {
    const src = s.path || '';
    const cap = s.filename || src;
    const attempt = s.attempt != null ? ` · try ${s.attempt}` : '';
    return `<figure>
      <a href="${escapeHtml(src)}" target="_blank" rel="noopener">
        <img src="${escapeHtml(src)}" alt="" loading="lazy" onerror="this.style.opacity=.25;this.closest('figure').dataset.miss=1"/>
      </a>
      <figcaption>${escapeHtml(cap)}${escapeHtml(attempt)}</figcaption>
    </figure>`;
  }).join('');
  return wrap;
}

function renderRunsMeta(runs) {
  if (!runs.length) return [];
  const latest = runs[runs.length - 1];
  const metrics = latest.metrics || {};
  const tableWrap = el('div', { class: 'table-wrap' });
  tableWrap.innerHTML = `<table class="data"><thead><tr>
    <th>Try</th><th>Status</th><th>Worker</th><th>Started</th><th>Finished</th><th>Shots</th>
  </tr></thead><tbody>${runs.map((r) => `<tr>
    <td class="mono">${r.attempt}</td>
    <td>${badge(r.status)}</td>
    <td class="mono">${escapeHtml(r.worker_id || '—')}</td>
    <td class="mono">${fmtDate(r.started_at)}</td>
    <td class="mono">${fmtDate(r.finished_at)}</td>
    <td class="mono">${(r.screenshots || []).length}</td>
  </tr>`).join('')}</tbody></table>`;

  return [
    el('h3', { text: 'Runs', style: { marginTop: '16px' } }),
    tableWrap,
    Object.keys(metrics).length
      ? el('pre', {
        class: 'json-preview',
        style: { marginTop: '8px' },
        text: JSON.stringify(metrics, null, 2),
      })
      : null,
  ].filter(Boolean);
}

function renderEvents(rows) {
  if (!rows.length) {
    return el('div', {
      class: 'empty',
      text: 'Нет runs_events — появятся у новых прогонов после обновления воркера',
    });
  }
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
