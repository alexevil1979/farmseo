import { Api } from '../api.js';
import { el, escapeHtml, badge, fmtDate } from '../utils.js';
import { createTable } from '../components/table.js';
import { openModal, confirmDialog } from '../components/modal.js';
import { toast } from '../components/toast.js';

/**
 * Generic CRUD page for simple REST resources.
 * fields: [{ key, label, type?: 'text'|'number'|'select'|'textarea'|'json', options?: string[], required?: bool }]
 */
export async function renderResourcePage(root, {
  resource,
  title,
  fields,
  columns,
  filters = [],
}) {
  root.innerHTML = '';
  let page = 1;
  const filterState = {};

  const toolbar = el('div', { class: 'toolbar' });
  filters.forEach((f) => {
    const sel = el('select', { class: 'select', style: { width: '150px' } }, [
      el('option', { value: '', text: f.label }),
      ...(f.options || []).map((o) => el('option', { value: o, text: o })),
    ]);
    sel.onchange = async () => {
      filterState[f.key] = sel.value;
      page = 1;
      await load();
    };
    toolbar.append(sel);
  });
  toolbar.append(
    el('span', { class: 'spacer' }),
    el('button', {
      class: 'btn btn-danger btn-sm',
      type: 'button',
      text: 'Удалить',
      onClick: async () => {
        const ids = table.selectedIds();
        if (!ids.length) return toast('Не выбрано', 'warn');
        if (!(await confirmDialog(`Удалить ${ids.length}?`, { danger: true, confirmText: 'Удалить' }))) return;
        for (const id of ids) await Api.remove(resource, id);
        toast('Удалено');
        await load();
      },
    }),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: `+ ${title}`,
      onClick: () => openForm(null),
    }),
  );

  const table = createTable({
    selectable: true,
    columns: columns || defaultColumns(fields),
    onRowClick: (row) => openForm(row),
  });
  root.append(toolbar, table.el, table.pager);

  async function load() {
    const params = { page, per_page: 50, ...Object.fromEntries(Object.entries(filterState).filter(([, v]) => v)) };
    const res = await Api.list(resource, params);
    table.setRows(res.data || [], res.meta);
  }
  table.on({ onPage: async (p) => { page = p; await load(); } });

  function openForm(existing) {
    const inputs = {};
    const body = el('div');
    fields.forEach((f) => {
      let input;
      const val = existing?.[f.key];
      if (f.type === 'select') {
        input = el('select', { class: 'select' }, (f.options || []).map((o) => el('option', {
          value: o, text: o, selected: String(val ?? f.default ?? '') === String(o),
        })));
      } else if (f.type === 'textarea' || f.type === 'json') {
        input = el('textarea', {
          class: 'textarea',
          text: f.type === 'json'
            ? JSON.stringify(val ?? f.default ?? {}, null, 2)
            : (val ?? ''),
        });
      } else {
        input = el('input', {
          class: 'input',
          type: f.type === 'number' ? 'number' : 'text',
          value: val ?? f.default ?? '',
        });
      }
      inputs[f.key] = input;
      body.append(el('div', { class: 'field' }, [el('label', { text: f.label }), input]));
    });
    const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Сохранить' });
    const ui = openModal({
      title: existing ? `${title} #${existing.id}` : `Новый ${title}`,
      body,
      footer: save,
    });
    save.onclick = async () => {
      const payload = {};
      for (const f of fields) {
        let v = inputs[f.key].value;
        if (f.type === 'number') v = v === '' ? null : Number(v);
        if (f.type === 'json') {
          try { v = JSON.parse(v || '{}'); } catch { toast(`JSON: ${f.key}`, 'error'); return; }
        }
        if (f.type === 'select' && (v === '1' || v === '0') && f.asInt) v = Number(v);
        payload[f.key] = v;
      }
      try {
        if (existing) await Api.update(resource, existing.id, payload);
        else await Api.create(resource, payload);
        toast('Сохранено');
        ui.close();
        await load();
      } catch (e) { toast(e.message, 'error'); }
    };
  }

  await load();
  const t = setInterval(() => load().catch(() => {}), window.FarmConfig?.tablePollMs || 6000);
  return () => clearInterval(t);
}

function defaultColumns(fields) {
  return [
    { key: 'id', label: 'ID', class: 'mono' },
    ...fields.slice(0, 5).map((f) => ({
      key: f.key,
      label: f.label,
      render: (r) => {
        const v = r[f.key];
        if (f.key.includes('status') || f.key === 'health' || f.key === 'type') return badge(v);
        if (typeof v === 'object') return `<span class="mono">${escapeHtml(JSON.stringify(v))}</span>`;
        return escapeHtml(v ?? '—');
      },
    })),
    {
      key: 'updated_at',
      label: 'Updated',
      render: (r) => `<span class="mono">${fmtDate(r.updated_at || r.created_at)}</span>`,
    },
  ];
}
