import { el, escapeHtml, badge as badgeHtml } from '../utils.js';

/**
 * Live data table with selection, pagination, optional poll refresh.
 */
export function createTable({
  columns,
  rowKey = 'id',
  selectable = false,
  emptyText = 'Нет данных',
  onRowClick,
}) {
  const wrap = el('div', { class: 'table-wrap' });
  const table = el('table', { class: 'data' });
  const thead = el('thead');
  const tbody = el('tbody');
  const pager = el('div', { class: 'pager' });
  table.append(thead, tbody);
  wrap.append(table);

  let rows = [];
  let selected = new Set();
  let meta = { page: 1, per_page: 50, total: 0, total_pages: 1 };
  let sortHandlers = {};

  function renderHead() {
    thead.innerHTML = '';
    const tr = el('tr');
    if (selectable) {
      tr.append(el('th', { class: 'check-cell', html: '<input type="checkbox" data-all />' }));
    }
    columns.forEach((c) => {
      tr.append(el('th', { text: c.label, style: c.width ? { width: c.width } : undefined }));
    });
    thead.append(tr);
    const all = thead.querySelector('[data-all]');
    if (all) {
      all.onchange = () => {
        selected = all.checked ? new Set(rows.map((r) => r[rowKey])) : new Set();
        renderBody();
        sortHandlers.onSelect?.(selectedIds());
      };
    }
  }

  function cellHtml(col, row) {
    if (col.render) return col.render(row);
    const v = row[col.key];
    if (col.type === 'badge') return badgeHtml(v);
    if (col.type === 'mono') return `<span class="mono">${escapeHtml(v)}</span>`;
    return escapeHtml(v ?? '—');
  }

  function renderBody() {
    tbody.innerHTML = '';
    if (!rows.length) {
      const tr = el('tr');
      const cols = columns.length + (selectable ? 1 : 0);
      tr.append(el('td', { colspan: cols, class: 'empty', text: emptyText }));
      tbody.append(tr);
      return;
    }
    rows.forEach((row) => {
      const id = row[rowKey];
      const tr = el('tr', { class: selected.has(id) ? 'selected' : '' });
      if (selectable) {
        const td = el('td', { class: 'check-cell' });
        const cb = el('input', { type: 'checkbox' });
        cb.checked = selected.has(id);
        cb.onchange = () => {
          if (cb.checked) selected.add(id);
          else selected.delete(id);
          tr.classList.toggle('selected', cb.checked);
          sortHandlers.onSelect?.(selectedIds());
        };
        td.append(cb);
        tr.append(td);
      }
      columns.forEach((c) => {
        const td = el('td', { html: cellHtml(c, row) });
        if (c.class) td.className = c.class;
        tr.append(td);
      });
      if (onRowClick) {
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', (e) => {
          if (e.target.closest('input,button,a,select')) return;
          onRowClick(row);
        });
      }
      tbody.append(tr);
    });
  }

  function renderPager() {
    pager.innerHTML = '';
    const info = el('span', {
      text: `стр. ${meta.page}/${meta.total_pages} · всего ${meta.total}`,
    });
    const prev = el('button', {
      class: 'btn btn-sm',
      type: 'button',
      text: '←',
      disabled: meta.page <= 1,
      onClick: () => sortHandlers.onPage?.(meta.page - 1),
    });
    const next = el('button', {
      class: 'btn btn-sm',
      type: 'button',
      text: '→',
      disabled: meta.page >= meta.total_pages,
      onClick: () => sortHandlers.onPage?.(meta.page + 1),
    });
    pager.append(prev, info, next);
  }

  function selectedIds() {
    return [...selected];
  }

  renderHead();

  return {
    el: wrap,
    pager,
    setRows(next, nextMeta) {
      rows = Array.isArray(next) ? next : [];
      if (nextMeta) meta = { ...meta, ...nextMeta };
      const ids = new Set(rows.map((r) => r[rowKey]));
      selected = new Set([...selected].filter((id) => ids.has(id)));
      renderBody();
      renderPager();
    },
    selectedIds,
    clearSelection() {
      selected = new Set();
      renderBody();
    },
    on(handlers) {
      sortHandlers = { ...sortHandlers, ...handlers };
    },
  };
}
