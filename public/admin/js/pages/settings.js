import { Api } from '../api.js';
import { el, escapeHtml, fmtDate } from '../utils.js';
import { openModal } from '../components/modal.js';
import { toast } from '../components/toast.js';

export async function renderSettings(root) {
  root.innerHTML = '';
  const host = el('div', { class: 'panel' });
  const toolbar = el('div', { class: 'toolbar' }, [
    el('strong', { text: 'Настройки' }),
    el('span', { class: 'spacer' }),
    el('button', {
      class: 'btn btn-primary',
      type: 'button',
      text: '+ Ключ',
      onClick: () => editSetting(null, load),
    }),
  ]);
  root.append(toolbar, host);

  async function load() {
    const res = await Api.settings({ per_page: 200 });
    const rows = res?.data || [];
    if (!res) {
      host.innerHTML = `<div class="empty">GET /settings недоступен. Можно задать через API позже.</div>
        <p class="muted">Ожидаемые ключи: webhook_url, webhook_secret, max_chrome, night_window, …</p>`;
      return;
    }
    host.innerHTML = `<table class="data"><thead><tr>
      <th>ID</th><th>Name</th><th>Value</th><th>Updated</th><th></th>
    </tr></thead><tbody>${rows.map((r) => `<tr>
      <td class="mono">${r.id}</td>
      <td class="mono">${escapeHtml(r.name)}</td>
      <td class="mono">${escapeHtml(JSON.stringify(r.value).slice(0, 120))}</td>
      <td class="mono">${fmtDate(r.updated_at)}</td>
      <td><button class="btn btn-sm" data-id="${r.id}">Изменить</button></td>
    </tr>`).join('') || '<tr><td colspan="5" class="empty">Пусто</td></tr>'}</tbody></table>`;
    host.querySelectorAll('[data-id]').forEach((btn) => {
      btn.onclick = () => {
        const row = rows.find((r) => String(r.id) === btn.getAttribute('data-id'));
        editSetting(row, load);
      };
    });
  }

  await load();
}

function editSetting(existing, onDone) {
  const name = el('input', { class: 'input', value: existing?.name || '' });
  const value = el('textarea', {
    class: 'textarea',
    text: JSON.stringify(existing?.value ?? '', null, 2),
  });
  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Сохранить' });
  const ui = openModal({
    title: existing ? `Setting #${existing.id}` : 'Новая настройка',
    body: el('div', {}, [
      el('div', { class: 'field' }, [el('label', { text: 'name' }), name]),
      el('div', { class: 'field' }, [el('label', { text: 'value JSON' }), value]),
    ]),
    footer: save,
  });
  save.onclick = async () => {
    let parsed;
    try { parsed = JSON.parse(value.value); } catch { toast('Невалидный JSON', 'error'); return; }
    try {
      if (existing) await Api.updateSetting(existing.id, { name: name.value, value: parsed });
      else await Api.saveSetting({ name: name.value.trim(), value: parsed });
      toast('Сохранено');
      ui.close();
      await onDone();
    } catch (e) { toast(e.message, 'error'); }
  };
}
