import { Api } from '../api.js';
import {
  el, escapeHtml, badge, fmtDate, fmtScore, parseCsv, toCsv, downloadBlob, mapPool,
} from '../utils.js';
import { createTable } from '../components/table.js';
import { openModal, confirmDialog } from '../components/modal.js';
import { toast } from '../components/toast.js';
import { navigate } from '../router.js';

const STATUSES = ['new', 'warming', 'ready', 'active', 'paused', 'banned', 'error'];

export async function renderAccounts(root, { id } = {}) {
  if (id) return renderAccountDetail(root, id);

  root.innerHTML = '';
  let page = 1;
  let status = '';
  let q = '';
  let pollTimer;

  const toolbar = el('div', { class: 'toolbar' });
  const statusSel = el('select', { class: 'select', style: { width: '160px' } }, [
    el('option', { value: '', text: 'Все статусы' }),
    ...STATUSES.map((s) => el('option', { value: s, text: s })),
  ]);
  const search = el('input', { class: 'input', placeholder: 'логин / группа', style: { width: '200px' } });
  const bulkBtn = el('button', { class: 'btn btn-primary', type: 'button', text: 'Создать 100' });
  const provisionBtn = el('button', { class: 'btn', type: 'button', text: 'Прокси+FP' });
  const importBtn = el('button', { class: 'btn', type: 'button', text: 'CSV импорт' });
  const exportBtn = el('button', { class: 'btn', type: 'button', text: 'CSV экспорт' });
  const createBtn = el('button', { class: 'btn', type: 'button', text: '+ Аккаунт' });
  const massStatus = el('select', { class: 'select', style: { width: '140px' } }, [
    el('option', { value: '', text: 'Массово…' }),
    ...STATUSES.map((s) => el('option', { value: s, text: `→ ${s}` })),
  ]);
  const delSel = el('button', { class: 'btn btn-danger btn-sm', type: 'button', text: 'Удалить выбранные' });

  toolbar.append(
    statusSel, search,
    el('span', { class: 'spacer' }),
    massStatus, delSel, provisionBtn, importBtn, exportBtn, bulkBtn, createBtn,
  );

  const table = createTable({
    selectable: true,
    columns: [
      { key: 'id', label: 'ID', class: 'mono', width: '70px' },
      { key: 'login', label: 'Login' },
      { key: 'status', label: 'Статус', type: 'badge' },
      { key: 'trust_score', label: 'Trust', render: (r) => `<span class="mono">${fmtScore(r.trust_score)}</span>` },
      { key: 'group_id', label: 'Группа', class: 'mono' },
      {
        key: 'tags',
        label: 'Теги',
        render: (r) => {
          const tags = Array.isArray(r.tags) ? r.tags : (typeof r.tags === 'string' ? safeJson(r.tags) : []);
          return (tags || []).map((t) => `<span class="tag">${escapeHtml(t)}</span>`).join('') || '—';
        },
      },
      { key: 'proxy_id', label: 'Proxy', class: 'mono' },
      { key: 'last_activity', label: 'Активность', render: (r) => `<span class="mono">${fmtDate(r.last_activity)}</span>` },
      {
        key: '_',
        label: '',
        render: (r) => `<button class="btn btn-sm" data-open="${r.id}">Карточка</button>`,
      },
    ],
    onRowClick: (row) => navigate(`accounts/${row.id}`),
  });

  root.append(toolbar, table.el, table.pager);

  table.el.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open]');
    if (btn) navigate(`accounts/${btn.getAttribute('data-open')}`);
  });

  async function load() {
    const res = await Api.list('accounts', {
      page,
      per_page: window.FarmConfig?.perPageDefault || 50,
      status: status || undefined,
    });
    let rows = res.data || [];
    if (q) {
      const qq = q.toLowerCase();
      rows = rows.filter((r) => String(r.login || '').toLowerCase().includes(qq)
        || String(r.group_id || '').includes(qq)
        || JSON.stringify(r.tags || '').toLowerCase().includes(qq));
    }
    table.setRows(rows, res.meta);
  }

  table.on({
    onPage: async (p) => { page = p; await load(); },
  });

  statusSel.onchange = async () => { status = statusSel.value; page = 1; await load(); };
  search.oninput = async () => { q = search.value.trim(); await load(); };

  createBtn.onclick = () => openAccountForm(null, load);
  bulkBtn.onclick = () => openBulkCreate(load);
  provisionBtn.onclick = async () => {
    const ids = table.selectedIds();
    if (!ids.length) { toast('Выберите аккаунты', 'warn'); return; }
    const ok = await confirmDialog(`Назначить sticky-прокси и отпечатки для ${ids.length} аккаунтов?`);
    if (!ok) return;
    try {
      const res = await Api.bulkProvision({
        account_ids: ids.map(Number),
        with_fingerprint: true,
      });
      const meta = res.meta || {};
      toast(`Готово: ok ${meta.ok || 0}, ошибок ${(meta.errors || []).length}`);
      await load();
    } catch (e) {
      toast(e.message, 'error');
    }
  };
  importBtn.onclick = () => openCsvImport(load);
  exportBtn.onclick = async () => {
    const res = await Api.list('accounts', { page: 1, per_page: 1000 });
    const rows = (res.data || []).map((a) => ({
      id: a.id,
      login: a.login,
      status: a.status,
      trust_score: a.trust_score,
      phone: a.phone || '',
      group_id: a.group_id || '',
      tags: Array.isArray(a.tags) ? a.tags.join('|') : '',
      proxy_id: a.proxy_id || '',
      notes: a.notes || '',
    }));
    downloadBlob('accounts.csv', toCsv(
      ['id', 'login', 'status', 'trust_score', 'phone', 'group_id', 'tags', 'proxy_id', 'notes'],
      rows,
    ));
    toast('Экспорт готов');
  };

  massStatus.onchange = async () => {
    const st = massStatus.value;
    massStatus.value = '';
    if (!st) return;
    const ids = table.selectedIds();
    if (!ids.length) { toast('Ничего не выбрано', 'warn'); return; }
    const ok = await confirmDialog(`Сменить статус ${ids.length} аккаунтов → ${st}?`);
    if (!ok) return;
    const results = await mapPool(ids, 8, (id) => Api.update('accounts', id, { status: st }));
    const failed = results.filter((r) => !r.ok).length;
    toast(failed ? `Обновлено с ошибками: ${failed}` : `Обновлено: ${ids.length}`, failed ? 'warn' : 'ok');
    await load();
  };

  delSel.onclick = async () => {
    const ids = table.selectedIds();
    if (!ids.length) { toast('Ничего не выбрано', 'warn'); return; }
    const ok = await confirmDialog(`Удалить ${ids.length} аккаунтов?`, { danger: true, confirmText: 'Удалить' });
    if (!ok) return;
    await mapPool(ids, 8, (id) => Api.remove('accounts', id));
    toast('Удалено');
    await load();
  };

  await load();
  pollTimer = setInterval(() => load().catch(() => {}), window.FarmConfig?.tablePollMs || 6000);

  return () => clearInterval(pollTimer);
}

function safeJson(s) {
  try { return JSON.parse(s); } catch { return []; }
}

function openAccountForm(existing, onDone) {
  const body = el('div');
  const fields = {
    login: el('input', { class: 'input', value: existing?.login || '' }),
    password: el('input', { class: 'input', type: 'password', placeholder: existing ? 'оставить пустым' : '' }),
    status: el('select', { class: 'select' }, STATUSES.map((s) => el('option', {
      value: s, text: s, selected: (existing?.status || 'new') === s,
    }))),
    trust_score: el('input', { class: 'input', type: 'number', step: '0.01', value: existing?.trust_score ?? '0' }),
    phone: el('input', { class: 'input', value: existing?.phone || '' }),
    group_id: el('input', { class: 'input', value: existing?.group_id ?? '' }),
    proxy_id: el('input', { class: 'input', value: existing?.proxy_id ?? '' }),
    tags: el('input', { class: 'input', value: Array.isArray(existing?.tags) ? existing.tags.join(', ') : '' }),
    notes: el('textarea', { class: 'textarea', text: existing?.notes || '' }),
  };
  body.append(
    row2('Login', fields.login, 'Password', fields.password),
    row2('Status', fields.status, 'Trust', fields.trust_score),
    row2('Phone', fields.phone, 'Group ID', fields.group_id),
    row2('Proxy ID', fields.proxy_id, 'Tags', fields.tags),
    el('div', { class: 'field' }, [el('label', { text: 'Notes' }), fields.notes]),
  );

  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Сохранить' });
  const ui = openModal({
    title: existing ? `Аккаунт #${existing.id}` : 'Новый аккаунт',
    body,
    footer: save,
  });

  save.onclick = async () => {
    const payload = {
      login: fields.login.value.trim(),
      status: fields.status.value,
      trust_score: fields.trust_score.value,
      phone: fields.phone.value || null,
      group_id: fields.group_id.value ? Number(fields.group_id.value) : null,
      proxy_id: fields.proxy_id.value ? Number(fields.proxy_id.value) : null,
      tags: fields.tags.value.split(',').map((t) => t.trim()).filter(Boolean),
      notes: fields.notes.value || null,
    };
    if (fields.password.value) payload.password = fields.password.value;
    try {
      if (existing) await Api.update('accounts', existing.id, payload);
      else {
        if (!payload.password) throw new Error('password обязателен');
        await Api.create('accounts', payload);
      }
      toast('Сохранено');
      ui.close(true);
      await onDone();
    } catch (e) {
      toast(e.message, 'error');
    }
  };
}

function row2(l1, a, l2, b) {
  return el('div', { class: 'form-row cols-2' }, [
    el('div', { class: 'field' }, [el('label', { text: l1 }), a]),
    el('div', { class: 'field' }, [el('label', { text: l2 }), b]),
  ]);
}

function openBulkCreate(onDone) {
  const count = el('input', { class: 'input', type: 'number', value: '100', min: '1', max: '500' });
  const prefix = el('input', { class: 'input', value: `acc_${Date.now()}_` });
  const password = el('input', { class: 'input', value: 'ChangeMe123!' });
  const status = el('select', { class: 'select' }, STATUSES.map((s) => el('option', { value: s, text: s })));
  const group = el('input', { class: 'input', value: '' });
  const tags = el('input', { class: 'input', value: 'bulk' });
  const autoProvision = el('input', { type: 'checkbox', checked: true });
  const progress = el('div', { class: 'progress' }, [el('i')]);
  const log = el('div', { class: 'muted', style: { marginTop: '8px', fontFamily: 'var(--mono)', fontSize: '12px' } });

  const body = el('div', {}, [
    el('p', { class: 'muted', text: 'Массовое создание через POST /accounts + опционально bulk-provision (прокси+отпечатки).' }),
    row2('Количество', count, 'Префикс login', prefix),
    row2('Password', password, 'Status', status),
    row2('Group ID', group, 'Tags', tags),
    el('label', { class: 'field', style: { display: 'flex', gap: '8px', alignItems: 'center' } }, [
      autoProvision,
      el('span', { text: 'Сразу назначить прокси и отпечатки' }),
    ]),
    progress,
    log,
  ]);
  const go = el('button', { class: 'btn btn-primary', type: 'button', text: 'Запустить' });
  const ui = openModal({ title: 'Массовое создание аккаунтов', body, footer: go });

  go.onclick = async () => {
    const n = Math.min(500, Math.max(1, Number(count.value) || 100));
    go.disabled = true;
    const items = Array.from({ length: n }, (_, i) => ({
      login: `${prefix.value}${String(i + 1).padStart(3, '0')}`,
      password: password.value,
      status: status.value,
      trust_score: '0.00',
      group_id: group.value ? Number(group.value) : null,
      tags: tags.value.split(',').map((t) => t.trim()).filter(Boolean),
    }));
    let done = 0;
    let failed = 0;
    const createdIds = [];
    const bar = progress.querySelector('i');
    const results = await mapPool(items, window.FarmConfig?.bulkConcurrency || 10, async (item) => {
      const res = await Api.create('accounts', item);
      if (res?.data?.id) createdIds.push(res.data.id);
      done += 1;
      bar.style.width = `${Math.round(((done + failed) / n) * 100)}%`;
      log.textContent = `ok ${done} / fail ${failed} / total ${n}`;
    });
    failed = results.filter((r) => !r.ok).length;
    done = results.filter((r) => r.ok).length;
    if (autoProvision.checked && createdIds.length) {
      log.textContent = `Назначаю прокси/FP для ${createdIds.length}…`;
      try {
        const prov = await Api.bulkProvision({
          account_ids: createdIds,
          with_fingerprint: true,
        });
        log.textContent = `Создано ${done}, provision ok ${prov.meta?.ok || 0}`;
      } catch (e) {
        log.textContent = `Создано ${done}, provision error: ${e.message}`;
      }
    }
    bar.style.width = '100%';
    if (failed) {
      const first = results.find((r) => !r.ok);
      toast(first?.error?.message || `Ошибки: ${failed}`, 'warn');
    } else toast(`Создано ${done} аккаунтов`);
    go.disabled = false;
    if (done) {
      ui.close(true);
      await onDone();
    }
  };
}

function openCsvImport(onDone) {
  const file = el('input', { class: 'input', type: 'file', accept: '.csv,text/csv' });
  const hint = el('p', {
    class: 'muted',
    text: 'Ожидаемые колонки: login,password,status,trust_score,phone,group_id,tags,proxy_id,notes',
  });
  const go = el('button', { class: 'btn btn-primary', type: 'button', text: 'Импорт' });
  const ui = openModal({
    title: 'CSV импорт',
    body: el('div', {}, [hint, el('div', { class: 'field' }, [el('label', { text: 'Файл' }), file])]),
    footer: go,
  });
  go.onclick = async () => {
    const f = file.files?.[0];
    if (!f) { toast('Выберите файл', 'warn'); return; }
    const text = await f.text();
    const { rows } = parseCsv(text);
    if (!rows.length) { toast('Пустой CSV', 'warn'); return; }
    go.disabled = true;
    const results = await mapPool(rows, window.FarmConfig?.bulkConcurrency || 10, async (row) => {
      const payload = {
        login: row.login,
        password: row.password,
        status: row.status || 'new',
        trust_score: row.trust_score || '0.00',
        phone: row.phone || null,
        group_id: row.group_id ? Number(row.group_id) : null,
        proxy_id: row.proxy_id ? Number(row.proxy_id) : null,
        tags: row.tags ? String(row.tags).split(/[|,]/).map((t) => t.trim()).filter(Boolean) : [],
        notes: row.notes || null,
      };
      await Api.create('accounts', payload);
    });
    const failed = results.filter((r) => !r.ok).length;
    toast(failed ? `Импорт с ошибками: ${failed}` : `Импортировано ${rows.length}`, failed ? 'warn' : 'ok');
    ui.close(true);
    await onDone();
  };
}

async function renderAccountDetail(root, id) {
  root.innerHTML = '';
  const back = el('button', {
    class: 'btn btn-sm',
    type: 'button',
    text: '← К списку',
    onClick: () => navigate('accounts'),
  });
  const head = el('div', { class: 'toolbar' }, [
    back,
    el('strong', { text: `Аккаунт #${id}` }),
    el('span', { class: 'spacer' }),
  ]);
  const actions = el('div', { class: 'actions' });
  head.append(actions);
  const tabs = el('div', { class: 'detail-tabs' });
  const pane = el('div', { class: 'panel' });
  root.append(head, tabs, pane);

  let account;
  let proxy;
  let fingerprint;
  let sessions = [];
  let cookies = [];
  let screenshots = [];
  let activeTab = 'overview';

  async function load() {
    const res = await Api.getOne('accounts', id);
    account = res.data;
    const [fp, sess, cook, shots, px] = await Promise.all([
      Api.accountFingerprint(id),
      Api.accountSessions(id, { per_page: 50 }),
      Api.accountCookies(id, { per_page: 50 }),
      Api.accountScreenshots(id, { per_page: 50 }),
      account.proxy_id ? Api.getOne('proxies', account.proxy_id).catch(() => null) : Promise.resolve(null),
    ]);
    fingerprint = fp?.data || fp || null;
    sessions = sess?.data || [];
    cookies = cook?.data || [];
    screenshots = shots?.data || [];
    proxy = px?.data || null;

    // Fallback screenshot endpoint (single latest)
    if (!screenshots.length) {
      try {
        const one = await Api.screenshot(id);
        if (one?.data) screenshots = [one.data];
      } catch { /* none */ }
    }
  }

  function renderActions() {
    actions.innerHTML = '';
    const mk = (label, fn, cls = 'btn btn-sm') => el('button', {
      class: cls, type: 'button', text: label, onClick: fn,
    });
    actions.append(
      mk('Запуск', async () => {
        try {
          const r = await Api.startSession(id);
          toast(`Сессия ${r.data?.session_id || 'started'}`);
          await load(); render();
        } catch (e) { toast(e.message, 'error'); }
      }, 'btn btn-sm btn-primary'),
      mk('Стоп', async () => {
        try { await Api.stopSession(id); toast('Стоп'); await load(); render(); }
        catch (e) { toast(e.message, 'error'); }
      }),
      mk('Скриншот', async () => {
        try {
          const r = await Api.screenshotNow(id);
          toast(r.data?.filename || 'screenshot ok');
          await load(); render();
        } catch (e) { toast(e.message, 'error'); }
      }),
      mk('Live-view', () => {
        window.open(`live-view/?account_id=${encodeURIComponent(id)}`, '_blank', 'noopener');
      }),
      mk('Сброс сессии', async () => {
        try { await Api.resetSession(id); toast('Сессия сброшена'); await load(); render(); }
        catch (e) { toast(e.message, 'error'); }
      }),
      mk('Изменить', () => openAccountForm(account, async () => { await load(); render(); })),
    );
  }

  function tabBtn(name, label) {
    return el('button', {
      class: `tab-btn${activeTab === name ? ' active' : ''}`,
      type: 'button',
      text: label,
      onClick: () => { activeTab = name; render(); },
    });
  }

  function render() {
    renderActions();
    tabs.innerHTML = '';
    tabs.append(
      tabBtn('overview', 'Обзор'),
      tabBtn('fingerprint', 'Отпечаток'),
      tabBtn('proxy', 'Прокси'),
      tabBtn('history', 'История'),
      tabBtn('cookies', 'Cookie'),
      tabBtn('screenshots', 'Скриншоты'),
      tabBtn('live', 'Live-view'),
    );

    if (activeTab === 'overview') {
      const tags = Array.isArray(account.tags) ? account.tags : [];
      pane.innerHTML = `
        <dl class="kv">
          <dt>Login</dt><dd class="mono">${escapeHtml(account.login)}</dd>
          <dt>Status</dt><dd>${badge(account.status)}</dd>
          <dt>Trust</dt><dd class="mono">${fmtScore(account.trust_score)}</dd>
          <dt>Phone</dt><dd>${escapeHtml(account.phone || '—')}</dd>
          <dt>Group</dt><dd class="mono">${escapeHtml(account.group_id ?? '—')}</dd>
          <dt>Tags</dt><dd>${tags.map((t) => `<span class="tag">${escapeHtml(t)}</span>`).join('') || '—'}</dd>
          <dt>Proxy</dt><dd class="mono">${escapeHtml(account.proxy_id ?? '—')}</dd>
          <dt>Profile</dt><dd class="mono">${escapeHtml(account.profile_path || '—')}</dd>
          <dt>Last activity</dt><dd class="mono">${fmtDate(account.last_activity)}</dd>
          <dt>Created</dt><dd class="mono">${fmtDate(account.created_at)}</dd>
          <dt>Notes</dt><dd>${escapeHtml(account.notes || '—')}</dd>
        </dl>`;
    } else if (activeTab === 'fingerprint') {
      if (!fingerprint) {
        pane.innerHTML = '<div class="empty">Отпечаток недоступен (GET /accounts/{id}/fingerprint)</div>';
      } else {
        const fp = fingerprint;
        pane.innerHTML = `<dl class="kv">
          <dt>UA</dt><dd class="mono">${escapeHtml(fp.user_agent || '—')}</dd>
          <dt>Platform</dt><dd>${escapeHtml(fp.platform || '—')}</dd>
          <dt>Viewport</dt><dd class="mono">${escapeHtml(`${fp.viewport_w || '?'}×${fp.viewport_h || '?'}`)}</dd>
          <dt>Timezone</dt><dd>${escapeHtml(fp.timezone || '—')}</dd>
          <dt>Locale</dt><dd>${escapeHtml(fp.locale || '—')}</dd>
          <dt>WebGL</dt><dd class="mono">${escapeHtml(fp.webgl_hash || '—')}</dd>
          <dt>Canvas</dt><dd class="mono">${escapeHtml(fp.canvas_hash || '—')}</dd>
          <dt>Fonts</dt><dd class="mono">${escapeHtml(fp.fonts_hash || '—')}</dd>
        </dl>
        <pre class="json-preview">${escapeHtml(JSON.stringify(fp.extra || fp, null, 2))}</pre>`;
      }
    } else if (activeTab === 'proxy') {
      if (!proxy && !account.proxy_id) {
        pane.innerHTML = '<div class="empty">Прокси не назначен</div>';
      } else if (!proxy) {
        pane.innerHTML = `<div class="empty">Proxy #${escapeHtml(account.proxy_id)} не найден</div>`;
      } else {
        pane.innerHTML = `<dl class="kv">
          <dt>ID</dt><dd class="mono">${proxy.id}</dd>
          <dt>Endpoint</dt><dd class="mono">${escapeHtml(`${proxy.protocol}://${proxy.host}:${proxy.port}`)}</dd>
          <dt>Country</dt><dd>${escapeHtml(proxy.country || '—')}</dd>
          <dt>Health</dt><dd>${badge(proxy.health)}</dd>
          <dt>Latency</dt><dd class="mono">${escapeHtml(proxy.latency_ms ?? '—')} ms</dd>
          <dt>Active</dt><dd>${proxy.is_active ? 'yes' : 'no'}</dd>
        </dl>`;
      }
    } else if (activeTab === 'history') {
      if (!sessions.length) {
        pane.innerHTML = '<div class="empty">История сессий пуста (GET /accounts/{id}/sessions)</div>';
      } else {
        pane.innerHTML = `<table class="data"><thead><tr>
          <th>ID</th><th>Status</th><th>VNC</th><th>noVNC</th><th>Started</th><th>Ended</th>
        </tr></thead><tbody>${sessions.map((s) => `<tr>
          <td class="mono">${s.id}</td>
          <td>${badge(s.status)}</td>
          <td class="mono">${escapeHtml(s.vnc_port ?? '—')}</td>
          <td class="mono">${escapeHtml(s.novnc_url || '—')}</td>
          <td class="mono">${fmtDate(s.started_at)}</td>
          <td class="mono">${fmtDate(s.ended_at)}</td>
        </tr>`).join('')}</tbody></table>`;
      }
    } else if (activeTab === 'cookies') {
      if (!cookies.length) {
        pane.innerHTML = '<div class="empty">Снимков cookie нет</div>';
      } else {
        pane.innerHTML = `<table class="data"><thead><tr>
          <th>ID</th><th>Path</th><th>Count</th><th>Checksum</th><th>Created</th>
        </tr></thead><tbody>${cookies.map((c) => `<tr>
          <td class="mono">${c.id}</td>
          <td class="mono">${escapeHtml(c.storage_path || '—')}</td>
          <td class="mono">${escapeHtml(c.cookie_count ?? '—')}</td>
          <td class="mono">${escapeHtml(c.checksum || '—')}</td>
          <td class="mono">${fmtDate(c.created_at)}</td>
        </tr>`).join('')}</tbody></table>`;
      }
    } else if (activeTab === 'screenshots') {
      if (!screenshots.length) {
        pane.innerHTML = '<div class="empty">Скриншотов нет</div>';
      } else {
        pane.innerHTML = `<div class="screenshot-grid">${screenshots.map((s) => {
          const path = s.path || s.filename || '';
          const src = path.startsWith('http') || path.startsWith('/') ? path : `/${path}`;
          return `<figure><img src="${escapeHtml(src)}" alt="" loading="lazy" onerror="this.style.opacity=.3"/><figcaption>${escapeHtml(s.filename || path)}</figcaption></figure>`;
        }).join('')}</div>`;
      }
    } else if (activeTab === 'live') {
      const url = sessions.find((s) => s.novnc_url && (s.status === 'active' || s.status === 'starting'))?.novnc_url
        || sessions.find((s) => s.novnc_url)?.novnc_url
        || `/live/${id}/vnc.html?autoconnect=1&resize=scale`;
      pane.innerHTML = '';
      const openBtn = el('button', {
        class: 'btn btn-sm btn-primary',
        type: 'button',
        text: 'Открыть Live-view',
        onClick: () => window.open(`live-view/?account_id=${encodeURIComponent(id)}`, '_blank', 'noopener'),
      });
      const shotBtn = el('button', {
        class: 'btn btn-sm',
        type: 'button',
        text: 'Скриншот сейчас',
        onClick: async () => {
          try {
            const r = await Api.screenshotNow(id);
            toast(r.data?.filename || 'ok');
            await load();
            render();
          } catch (e) { toast(e.message, 'error'); }
        },
      });
      pane.append(
        el('div', { class: 'actions', style: { marginBottom: '8px' } }, [openBtn, shotBtn]),
        el('p', { class: 'muted mono', text: url }),
        el('iframe', { class: 'live-view-frame', src: url, title: 'live-view' }),
      );
    }
  }

  await load();
  render();
  const timer = setInterval(async () => {
    try { await load(); render(); } catch { /* ignore */ }
  }, window.FarmConfig?.tablePollMs || 6000);
  return () => clearInterval(timer);
}
