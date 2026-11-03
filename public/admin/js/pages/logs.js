import { Api } from '../api.js';
import { el, escapeHtml, badge, fmtDate } from '../utils.js';
import { toast } from '../components/toast.js';

export async function renderLogs(root) {
  root.innerHTML = '';
  let page = 1;
  const entity = el('input', { class: 'input', placeholder: 'entity_type', style: { width: '160px' } });
  const action = el('input', { class: 'input', placeholder: 'action', style: { width: '140px' } });
  const host = el('div', { class: 'table-wrap' });
  const pager = el('div', { class: 'pager' });

  root.append(
    el('div', { class: 'toolbar' }, [
      entity, action,
      el('button', {
        class: 'btn',
        type: 'button',
        text: 'Обновить',
        onClick: () => load(),
      }),
      el('span', { class: 'spacer' }),
      el('span', { class: 'muted', text: 'GET /audit_log · runs_events через задачи' }),
    ]),
    host,
    pager,
  );

  async function load() {
    try {
      const res = await Api.auditLog({
        page,
        per_page: 50,
        entity_type: entity.value || undefined,
        action: action.value || undefined,
      });
      const rows = res?.data || [];
      if (!res) {
        host.innerHTML = '<div class="empty">Эндпоинт /audit_log пока недоступен</div>';
        pager.textContent = '';
        return;
      }
      host.innerHTML = `<table class="data"><thead><tr>
        <th>ID</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Meta</th><th>At</th>
      </tr></thead><tbody>${rows.map((r) => `<tr>
        <td class="mono">${r.id}</td>
        <td class="mono">${r.user_id ?? '—'}</td>
        <td>${badge(r.action)}</td>
        <td class="mono">${escapeHtml(r.entity_type)} #${r.entity_id ?? '—'}</td>
        <td class="mono">${escapeHtml(r.ip || '—')}</td>
        <td class="mono">${escapeHtml(JSON.stringify(r.meta || {}).slice(0, 80))}</td>
        <td class="mono">${fmtDate(r.created_at)}</td>
      </tr>`).join('') || '<tr><td colspan="7" class="empty">Пусто</td></tr>'}</tbody></table>`;
      const meta = res.meta || { page: 1, total_pages: 1, total: rows.length };
      pager.innerHTML = '';
      pager.append(
        el('button', {
          class: 'btn btn-sm', type: 'button', text: '←', disabled: page <= 1,
          onClick: async () => { page -= 1; await load(); },
        }),
        el('span', { text: `стр. ${meta.page}/${meta.total_pages} · ${meta.total}` }),
        el('button', {
          class: 'btn btn-sm', type: 'button', text: '→', disabled: page >= (meta.total_pages || 1),
          onClick: async () => { page += 1; await load(); },
        }),
      );
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  await load();
  const t = setInterval(() => load().catch(() => {}), window.FarmConfig?.tablePollMs || 6000);
  return () => clearInterval(t);
}
