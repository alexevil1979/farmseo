import { Api } from '../api.js';
import { el, escapeHtml, fmtDate } from '../utils.js';
import { openModal, confirmDialog } from '../components/modal.js';
import { toast } from '../components/toast.js';

export async function renderTokens(root) {
  root.innerHTML = '';
  const host = el('div', { class: 'panel' });
  const toolbar = el('div', { class: 'toolbar' }, [
    el('strong', { text: 'API-токены' }),
    el('span', { class: 'spacer' }),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: '+ Токен',
      onClick: () => createToken(load),
    }),
  ]);
  root.append(
    toolbar,
    el('p', { class: 'muted', text: 'Bearer-токены для /api/v1. Plaintext показывается один раз при создании.' }),
    host,
  );

  async function load() {
    const res = await Api.apiTokens({ per_page: 100 });
    const rows = res?.data || [];
    if (!res) {
      host.innerHTML = '<div class="empty">GET /api_tokens недоступен</div>';
      return;
    }
    host.innerHTML = `<table class="data"><thead><tr>
      <th>ID</th><th>Name</th><th>User</th><th>Abilities</th><th>Last used</th><th>Expires</th><th>Revoked</th><th></th>
    </tr></thead><tbody>${rows.map((r) => `<tr>
      <td class="mono">${r.id}</td>
      <td>${escapeHtml(r.name)}</td>
      <td class="mono">${r.user_id}</td>
      <td class="mono">${escapeHtml(JSON.stringify(r.abilities || []))}</td>
      <td class="mono">${fmtDate(r.last_used_at)}</td>
      <td class="mono">${fmtDate(r.expires_at)}</td>
      <td class="mono">${fmtDate(r.revoked_at)}</td>
      <td><button class="btn btn-sm btn-danger" data-rev="${r.id}" ${r.revoked_at ? 'disabled' : ''}>Revoke</button></td>
    </tr>`).join('') || '<tr><td colspan="8" class="empty">Нет токенов</td></tr>'}</tbody></table>`;

    host.querySelectorAll('[data-rev]').forEach((btn) => {
      btn.onclick = async () => {
        if (!(await confirmDialog('Отозвать токен?', { danger: true, confirmText: 'Revoke' }))) return;
        try {
          await Api.revokeApiToken(btn.getAttribute('data-rev'));
          toast('Отозван');
          await load();
        } catch (e) { toast(e.message, 'error'); }
      };
    });
  }

  await load();
}

function createToken(onDone) {
  const name = el('input', { class: 'input', value: 'admin-panel' });
  const abilities = el('textarea', {
    class: 'textarea',
    text: JSON.stringify(['*'], null, 2),
  });
  const expires = el('input', { class: 'input', type: 'datetime-local' });
  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Создать' });
  const ui = openModal({
    title: 'Новый API-токен',
    body: el('div', {}, [
      el('div', { class: 'field' }, [el('label', { text: 'Name' }), name]),
      el('div', { class: 'field' }, [el('label', { text: 'Abilities JSON' }), abilities]),
      el('div', { class: 'field' }, [el('label', { text: 'Expires' }), expires]),
    ]),
    footer: save,
  });
  save.onclick = async () => {
    let ab;
    try { ab = JSON.parse(abilities.value || '[]'); } catch { toast('JSON abilities', 'error'); return; }
    try {
      const res = await Api.createApiToken({
        name: name.value.trim(),
        abilities: ab,
        expires_at: expires.value ? new Date(expires.value).toISOString() : null,
      });
      const token = res.data?.token || res.token || res.data;
      ui.close();
      openModal({
        title: 'Токен создан',
        body: el('div', {}, [
          el('p', { text: 'Скопируйте сейчас — повторно не покажем:' }),
          el('pre', { class: 'json-preview', text: typeof token === 'string' ? token : JSON.stringify(token, null, 2) }),
        ]),
        footer: el('button', {
          class: 'btn btn-primary',
          type: 'button',
          text: 'OK',
          onClick: () => { document.querySelector('.modal-backdrop')?.remove(); },
        }),
      });
      await onDone();
    } catch (e) { toast(e.message, 'error'); }
  };
}
