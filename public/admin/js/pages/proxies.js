import { Api } from '../api.js';
import { el, escapeHtml, fmtDate, toCsv, downloadBlob, mapPool } from '../utils.js';
import { createTable } from '../components/table.js';
import { openModal } from '../components/modal.js';
import { toast } from '../components/toast.js';

export async function renderProxies(root) {
  root.innerHTML = '';
  let page = 1;
  let health = '';

  const toolbar = el('div', { class: 'toolbar' });
  const healthSel = el('select', { class: 'select', style: { width: '140px' } }, [
    el('option', { value: '', text: 'Все health' }),
    ...['ok', 'slow', 'dead', 'unknown'].map((h) => el('option', { value: h, text: h })),
  ]);
  const addBtn = el('button', { class: 'btn btn-primary', type: 'button', text: '+ Прокси' });
  const importBtn = el('button', { class: 'btn', type: 'button', text: 'Импорт строк' });
  const exportBtn = el('button', { class: 'btn', type: 'button', text: 'CSV' });
  toolbar.append(healthSel, el('span', { class: 'spacer' }), importBtn, exportBtn, addBtn);

  const table = createTable({
    selectable: true,
    columns: [
      { key: 'id', label: 'ID', class: 'mono' },
      {
        key: 'endpoint',
        label: 'Endpoint',
        render: (r) => `<span class="mono">${escapeHtml(`${r.protocol}://${r.host}:${r.port}`)}</span>`,
      },
      { key: 'country', label: 'CC' },
      { key: 'health', label: 'Health', type: 'badge' },
      { key: 'latency_ms', label: 'ms', class: 'mono' },
      { key: 'is_active', label: 'On', render: (r) => (r.is_active ? 'yes' : 'no') },
      { key: 'last_check_at', label: 'Check', render: (r) => `<span class="mono">${fmtDate(r.last_check_at)}</span>` },
    ],
    onRowClick: (row) => formProxy(row, load),
  });

  root.append(toolbar, table.el, table.pager);

  async function load() {
    const res = await Api.list('proxies', { page, per_page: 50, health: health || undefined });
    table.setRows(res.data || [], res.meta);
  }
  table.on({ onPage: async (p) => { page = p; await load(); } });
  healthSel.onchange = async () => { health = healthSel.value; page = 1; await load(); };

  addBtn.onclick = () => formProxy(null, load);
  exportBtn.onclick = async () => {
    const res = await Api.list('proxies', { page: 1, per_page: 1000 });
    downloadBlob('proxies.csv', toCsv(
      ['id', 'host', 'port', 'protocol', 'country', 'health', 'username'],
      (res.data || []).map((p) => ({
        id: p.id, host: p.host, port: p.port, protocol: p.protocol,
        country: p.country || '', health: p.health, username: p.username || '',
      })),
    ));
  };
  importBtn.onclick = () => {
    const ta = el('textarea', {
      class: 'textarea',
      placeholder: 'host:port или protocol://user:pass@host:port — по строке',
    });
    const protocol = el('select', { class: 'select' }, [
      el('option', { value: 'http', text: 'http' }),
      el('option', { value: 'socks5', text: 'socks5' }),
    ]);
    const go = el('button', { class: 'btn btn-primary', type: 'button', text: 'Импорт' });
    const ui = openModal({
      title: 'Импорт прокси',
      body: el('div', {}, [
        el('div', { class: 'field' }, [el('label', { text: 'Protocol default' }), protocol]),
        el('div', { class: 'field' }, [el('label', { text: 'Список' }), ta]),
      ]),
      footer: go,
    });
    go.onclick = async () => {
      const lines = ta.value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
      const payloads = lines.map((line) => parseProxyLine(line, protocol.value)).filter(Boolean);
      const results = await mapPool(payloads, 8, (p) => Api.create('proxies', p));
      const failed = results.filter((r) => !r.ok).length;
      toast(failed ? `Ошибки: ${failed}` : `Импортировано ${payloads.length}`, failed ? 'warn' : 'ok');
      ui.close();
      await load();
    };
  };

  await load();
  const t = setInterval(() => load().catch(() => {}), window.FarmConfig?.tablePollMs || 6000);
  return () => clearInterval(t);
}

function parseProxyLine(line, defaultProtocol) {
  try {
    let protocol = defaultProtocol;
    let rest = line;
    if (line.includes('://')) {
      const [p, r] = line.split('://');
      protocol = p;
      rest = r;
    }
    let username; let password; let hostport = rest;
    if (rest.includes('@')) {
      const [cred, hp] = rest.split('@');
      hostport = hp;
      const [u, pw] = cred.split(':');
      username = u;
      password = pw;
    }
    const [host, port] = hostport.split(':');
    if (!host || !port) return null;
    return {
      host, port: Number(port), protocol, username: username || null, password: password || undefined, health: 'unknown',
    };
  } catch {
    return null;
  }
}

function formProxy(existing, onDone) {
  const host = el('input', { class: 'input', value: existing?.host || '' });
  const port = el('input', { class: 'input', type: 'number', value: existing?.port || '' });
  const protocol = el('select', { class: 'select' }, ['http', 'socks5', 'https'].map((p) => el('option', {
    value: p, text: p, selected: (existing?.protocol || 'http') === p,
  })));
  const country = el('input', { class: 'input', value: existing?.country || '', maxlength: '2' });
  const username = el('input', { class: 'input', value: existing?.username || '' });
  const password = el('input', { class: 'input', type: 'password' });
  const save = el('button', { class: 'btn btn-primary', type: 'button', text: 'Сохранить' });
  const ui = openModal({
    title: existing ? `Прокси #${existing.id}` : 'Новый прокси',
    body: el('div', {}, [
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'Host' }), host]),
        el('div', { class: 'field' }, [el('label', { text: 'Port' }), port]),
      ]),
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'Protocol' }), protocol]),
        el('div', { class: 'field' }, [el('label', { text: 'Country' }), country]),
      ]),
      el('div', { class: 'form-row cols-2' }, [
        el('div', { class: 'field' }, [el('label', { text: 'Username' }), username]),
        el('div', { class: 'field' }, [el('label', { text: 'Password' }), password]),
      ]),
    ]),
    footer: save,
  });
  save.onclick = async () => {
    const payload = {
      host: host.value.trim(),
      port: Number(port.value),
      protocol: protocol.value,
      country: country.value || null,
      username: username.value || null,
    };
    if (password.value) payload.password = password.value;
    try {
      if (existing) await Api.update('proxies', existing.id, payload);
      else await Api.create('proxies', payload);
      toast('Сохранено');
      ui.close();
      await onDone();
    } catch (e) { toast(e.message, 'error'); }
  };
}
