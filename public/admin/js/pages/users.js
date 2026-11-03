import { Api } from '../api.js';
import { el, badge, fmtDate } from '../utils.js';
import { createTable } from '../components/table.js';
import { openModal } from '../components/modal.js';
import { toast } from '../components/toast.js';

export async function renderUsers(root) {
  root.innerHTML = '';
  const tabs = el('div', { class: 'detail-tabs' });
  const pane = el('div');
  root.append(tabs, pane);
  let mode = 'users';

  function renderTabs() {
    tabs.innerHTML = '';
    [['users', 'Пользователи'], ['roles', 'Роли']].forEach(([k, label]) => {
      tabs.append(el('button', {
        class: `tab-btn${mode === k ? ' active' : ''}`,
        type: 'button',
        text: label,
        onClick: async () => { mode = k; renderTabs(); await draw(); },
      }));
    });
  }

  async function draw() {
    pane.innerHTML = '';
    if (mode === 'users') await drawUsers(pane);
    else await drawRoles(pane);
  }

  renderTabs();
  await draw();
}

async function drawUsers(root) {
  let page = 1;
  const toolbar = el('div', { class: 'toolbar' }, [
    el('span', { class: 'spacer' }),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: '+ Пользователь',
      onClick: () => formUser(null, load),
    }),
  ]);
  const table = createTable({
    columns: [
      { key: 'id', label: 'ID', class: 'mono' },
      { key: 'email', label: 'Email' },
      { key: 'name', label: 'Name' },
      { key: 'role_id', label: 'Role', class: 'mono' },
      { key: 'is_active', label: 'Active', render: (r) => (r.is_active ? badge('ok') : badge('paused')) },
      { key: 'last_login_at', label: 'Login', render: (r) => `<span class="mono">${fmtDate(r.last_login_at)}</span>` },
    ],
    onRowClick: (r) => formUser(r, load),
  });
  root.append(toolbar, table.el, table.pager);

  async function load() {
    const res = await Api.list('users', { page, per_page: 50 });
    table.setRows(res.data || [], res.meta);
  }
  table.on({ onPage: async (p) => { page = p; await load(); } });
  await load();
}

function formUser(existing, onDone) {
  const email = el('input', { class: 'input', type: 'email', value: existing?.email || '' });
  const name = el('input', { class: 'input', value: existing?.name || '' });
  const roleId = el('input', { class: 'input', type: 'number', value: existing?.role_id || '1' });
  const password = el('input', { class: 'input', type: 'password', placeholder: existing ? 'оставить пустым' : '' });
  const active = el('select', { class: 'select' }, [
    el('option', { value: '1', text: 'active', selected: existing?.is_active !== 0 }),
    el('option', { value: '0', text: 'disabled', selected: existing?.is_active === 0 }),
  ]);
  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Сохранить' });
  const ui = openModal({
    title: existing ? `User #${existing.id}` : 'Новый пользователь',
    body: el('div', {}, [
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'Email' }), email]),
        el('div', { class: 'field' }, [el('label', { text: 'Name' }), name]),
      ]),
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'role_id' }), roleId]),
        el('div', { class: 'field' }, [el('label', { text: 'Active' }), active]),
      ]),
      el('div', { class: 'field' }, [el('label', { text: 'Password' }), password]),
    ]),
    footer: save,
  });
  save.onclick = async () => {
    const payload = {
      email: email.value.trim(),
      name: name.value.trim(),
      role_id: Number(roleId.value),
      is_active: Number(active.value),
    };
    if (password.value) payload.password = password.value;
    try {
      if (existing) await Api.update('users', existing.id, payload);
      else {
        if (!payload.password) throw new Error('password обязателен');
        await Api.create('users', payload);
      }
      toast('Сохранено');
      ui.close();
      await onDone();
    } catch (e) { toast(e.message, 'error'); }
  };
}

async function drawRoles(root) {
  let page = 1;
  const toolbar = el('div', { class: 'toolbar' }, [
    el('span', { class: 'spacer' }),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: '+ Роль',
      onClick: () => formRole(null, load),
    }),
  ]);
  const table = createTable({
    columns: [
      { key: 'id', label: 'ID', class: 'mono' },
      { key: 'name', label: 'Name', render: (r) => badge(r.name) },
      { key: 'description', label: 'Description' },
      { key: 'created_at', label: 'Created', render: (r) => `<span class="mono">${fmtDate(r.created_at)}</span>` },
    ],
    onRowClick: (r) => formRole(r, load),
  });
  root.append(toolbar, table.el, table.pager);

  async function load() {
    const res = await Api.list('roles', { page, per_page: 50 });
    table.setRows(res.data || [], res.meta);
  }
  table.on({ onPage: async (p) => { page = p; await load(); } });
  await load();
}

function formRole(existing, onDone) {
  const name = el('input', { class: 'input', value: existing?.name || '' });
  const description = el('input', { class: 'input', value: existing?.description || '' });
  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Сохранить' });
  const ui = openModal({
    title: existing ? `Role #${existing.id}` : 'Новая роль',
    body: el('div', {}, [
      el('div', { class: 'field' }, [el('label', { text: 'Name' }), name]),
      el('div', { class: 'field' }, [el('label', { text: 'Description' }), description]),
    ]),
    footer: save,
  });
  save.onclick = async () => {
    try {
      const payload = { name: name.value.trim(), description: description.value || null };
      if (existing) await Api.update('roles', existing.id, payload);
      else await Api.create('roles', payload);
      toast('Сохранено');
      ui.close();
      await onDone();
    } catch (e) { toast(e.message, 'error'); }
  };
}
