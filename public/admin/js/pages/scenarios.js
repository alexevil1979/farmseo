import { Api } from '../api.js';
import { el, badge, fmtDate } from '../utils.js';
import { createTable } from '../components/table.js';
import { openModal } from '../components/modal.js';
import { toast } from '../components/toast.js';
import { navigate } from '../router.js';

const TYPES = ['warmup', 'pf', 'service', 'register'];
const ACTIONS = ['navigate', 'click', 'type', 'wait', 'scroll', 'screenshot', 'custom'];

export async function renderScenarios(root, { id } = {}) {
  if (id) return renderEditor(root, id);

  root.innerHTML = '';
  let page = 1;
  const toolbar = el('div', { class: 'toolbar' }, [
    el('span', { class: 'spacer' }),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: '+ Сценарий',
      onClick: () => openScenarioForm(null, load),
    }),
  ]);
  const table = createTable({
    columns: [
      { key: 'id', label: 'ID', class: 'mono' },
      { key: 'name', label: 'Name' },
      { key: 'type', label: 'Type', type: 'badge' },
      { key: 'version', label: 'Ver', class: 'mono' },
      { key: 'is_active', label: 'Active', render: (r) => (r.is_active ? badge('ok') : badge('paused')) },
      { key: 'updated_at', label: 'Updated', render: (r) => `<span class="mono">${fmtDate(r.updated_at)}</span>` },
      {
        key: '_',
        label: '',
        render: (r) => `<button class="btn btn-sm" data-edit="${r.id}">Редактор</button>`,
      },
    ],
    onRowClick: (r) => navigate(`scenarios/${r.id}`),
  });
  root.append(toolbar, table.el, table.pager);
  table.el.addEventListener('click', (e) => {
    const b = e.target.closest('[data-edit]');
    if (b) navigate(`scenarios/${b.getAttribute('data-edit')}`);
  });

  async function load() {
    const res = await Api.list('scenarios', { page, per_page: 50 });
    table.setRows(res.data || [], res.meta);
  }
  table.on({ onPage: async (p) => { page = p; await load(); } });
  await load();
}

function openScenarioForm(existing, onDone) {
  const name = el('input', { class: 'input', value: existing?.name || '' });
  const type = el('select', { class: 'select' }, TYPES.map((t) => el('option', {
    value: t, text: t, selected: (existing?.type || 'warmup') === t,
  })));
  const description = el('textarea', { class: 'textarea', text: existing?.description || '' });
  const defaults = el('textarea', {
    class: 'textarea',
    text: JSON.stringify(normalizeDefaults(existing?.defaults), null, 2),
  });
  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Сохранить' });
  const ui = openModal({
    title: existing ? `Сценарий #${existing.id}` : 'Новый сценарий',
    body: el('div', {}, [
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'Name' }), name]),
        el('div', { class: 'field' }, [el('label', { text: 'Type' }), type]),
      ]),
      el('div', { class: 'field' }, [el('label', { text: 'Description' }), description]),
      el('div', { class: 'field' }, [el('label', { text: 'Defaults JSON' }), defaults]),
    ]),
    footer: save,
  });
  save.onclick = async () => {
    let defs = {};
    try { defs = JSON.parse(defaults.value || '{}'); } catch { toast('Невалидный JSON defaults', 'error'); return; }
    defs = normalizeDefaults(defs);
    const payload = {
      name: name.value.trim(),
      type: type.value,
      description: description.value || null,
      defaults: defs,
      is_active: 1,
    };
    try {
      if (existing) await Api.update('scenarios', existing.id, payload);
      else await Api.create('scenarios', payload);
      toast('Сохранено');
      ui.close();
      await onDone();
    } catch (e) { toast(e.message, 'error'); }
  };
}

function normalizeDefaults(raw) {
  if (raw == null) return {};
  if (typeof raw === 'string') {
    try {
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
      return {};
    }
  }
  if (typeof raw === 'object' && !Array.isArray(raw)) {
    // Guard against string-spread leftovers: { "0": "{", "1": "\"" ... }
    const keys = Object.keys(raw);
    if (keys.length && keys.every((k) => /^\d+$/.test(k)) && !('steps' in raw)) return {};
    return { ...raw };
  }
  return {};
}

async function loadAccountOptions() {
  try {
    const res = await Api.list('accounts', { per_page: 200 });
    return Array.isArray(res.data) ? res.data : [];
  } catch {
    return [];
  }
}

async function renderEditor(root, id) {
  root.innerHTML = '';
  const res = await Api.getOne('scenarios', id);
  const scenario = res.data;
  scenario.defaults = normalizeDefaults(scenario.defaults);

  const stepsHost = el('div', { class: 'steps-editor' });
  const preview = el('pre', { class: 'json-preview' });
  const accounts = await loadAccountOptions();
  const accountSelect = el('select', { class: 'select step-account-select' }, [
    el('option', { value: '', text: accounts.length ? '— выберите аккаунт —' : 'Нет аккаунтов (создайте в «Аккаунты»)' }),
    ...accounts.map((a) => el('option', {
      value: String(a.id),
      text: `#${a.id} · ${a.login || a.name || 'account'} · ${a.status || '?'}`,
    })),
  ]);

  let steps = Array.isArray(scenario.defaults?.steps) ? structuredClone(scenario.defaults.steps) : [];
  if (!steps.length) {
    steps = [{ step_order: 1, action: 'navigate', params: { url: 'https://example.com' }, timeout_sec: 30, on_fail: 'abort' }];
  }

  function syncPreview() {
    const doc = {
      id: scenario.id,
      name: scenario.name,
      type: scenario.type,
      defaults: { ...normalizeDefaults(scenario.defaults), steps },
    };
    preview.textContent = JSON.stringify(doc, null, 2);
  }

  function renderSteps() {
    stepsHost.innerHTML = '';
    stepsHost.append(el('div', { class: 'step step-head' }, [
      el('span', { text: '№' }),
      el('span', { text: 'Действие' }),
      el('span', { text: 'Params (JSON)' }),
      el('span', { text: 'Timeout' }),
      el('span', { text: 'On fail' }),
      el('span', { text: '' }),
    ]));

    steps.forEach((step, idx) => {
      const order = el('input', {
        class: 'input step-order',
        type: 'number',
        min: '1',
        value: String(step.step_order ?? idx + 1),
        title: 'Номер шага',
        'aria-label': 'Номер шага',
      });
      const action = el('select', { class: 'select' }, ACTIONS.map((a) => el('option', {
        value: a, text: a, selected: step.action === a,
      })));
      const params = el('input', { class: 'input', value: JSON.stringify(step.params || {}) });
      const timeout = el('input', { class: 'input', type: 'number', value: String(step.timeout_sec ?? 30) });
      const onFail = el('select', { class: 'select' }, ['abort', 'retry', 'continue'].map((v) => el('option', {
        value: v, text: v, selected: (step.on_fail || 'abort') === v,
      })));
      const del = el('button', {
        class: 'btn btn-sm btn-danger',
        type: 'button',
        text: '✕',
        title: 'Удалить шаг',
        onClick: () => { steps.splice(idx, 1); renderSteps(); syncPreview(); },
      });
      const row = el('div', { class: 'step' }, [order, action, params, timeout, onFail, del]);
      const commit = () => {
        let p = {};
        try { p = JSON.parse(params.value || '{}'); } catch { /* keep */ }
        steps[idx] = {
          step_order: Number(order.value) || idx + 1,
          action: action.value,
          params: p,
          timeout_sec: Number(timeout.value) || 30,
          on_fail: onFail.value,
        };
        syncPreview();
      };
      [order, action, params, timeout, onFail].forEach((n) => n.addEventListener('change', commit));
      params.addEventListener('blur', commit);
      stepsHost.append(row);
    });
    syncPreview();
  }

  const toolbar = el('div', { class: 'toolbar' }, [
    el('button', { class: 'btn btn-sm', type: 'button', text: '← Назад', onClick: () => navigate('scenarios') }),
    el('strong', { text: `${scenario.name} · ${scenario.type}` }),
    el('span', { class: 'spacer' }),
    el('button', {
      class: 'btn',
      type: 'button',
      text: '+ Шаг',
      onClick: () => {
        steps.push({
          step_order: steps.length + 1, action: 'wait', params: { ms: 1000 }, timeout_sec: 10, on_fail: 'continue',
        });
        renderSteps();
      },
    }),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: 'Сохранить шаги',
      onClick: async () => {
        try {
          const defaults = { ...normalizeDefaults(scenario.defaults), steps };
          await Api.update('scenarios', id, { defaults });
          scenario.defaults = defaults;
          toast('Шаги сохранены');
          syncPreview();
        } catch (e) { toast(e.message, 'error'); }
      },
    }),
  ]);

  const testBar = el('div', { class: 'toolbar step-test-bar' }, [
    el('div', { class: 'field step-test-account' }, [
      el('label', { text: 'Аккаунт для теста' }),
      accountSelect,
    ]),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: 'Тестовый прогон',
      onClick: async () => {
        if (!accountSelect.value) { toast('Выберите аккаунт из списка', 'warn'); return; }
        try {
          const r = await Api.runScenario(id, { account_id: Number(accountSelect.value) });
          toast(`Task #${r.data?.task_id} → ${r.data?.status}`);
        } catch (e) { toast(e.message, 'error'); }
      },
    }),
  ]);

  root.append(
    toolbar,
    el('div', { class: 'split' }, [
      el('div', { class: 'panel' }, [el('h3', { text: 'Редактор шагов' }), stepsHost, testBar]),
      el('div', { class: 'panel' }, [el('h3', { text: 'Превью JSON' }), preview]),
    ]),
  );
  renderSteps();
}
