import { Api } from '../js/api.js';

const params = new URLSearchParams(window.location.search);
const accountId = Number(params.get('account_id') || params.get('id') || 0);

const el = {
  meta: document.getElementById('meta'),
  status: document.getElementById('status'),
  frame: document.getElementById('frame'),
  shot: document.getElementById('shot'),
  btnBack: document.getElementById('btnBack'),
  btnRefresh: document.getElementById('btnRefresh'),
  btnShot: document.getElementById('btnShot'),
  btnStop: document.getElementById('btnStop'),
};

function setStatus(text, isErr = false) {
  el.status.textContent = text;
  el.status.classList.toggle('err', isErr);
}

async function ensureAuth() {
  const auth = Api.getAuth();
  if (!auth?.token) {
    window.location.href = `../index.html#/login`;
    throw new Error('Требуется вход');
  }
  try {
    const me = await Api.me();
    if (me.data?.csrf) Api.setAuth({ ...auth, csrf: me.data.csrf, user: me.data.user || auth.user });
  } catch {
    Api.clearAuth();
    window.location.href = `../index.html#/login`;
    throw new Error('Сессия истекла');
  }
}

async function startLive() {
  setStatus('Запуск сессии / VNC…');
  const res = await Api.startSession(accountId);
  const data = res.data || res;
  const url = data.novnc_url || `/live/${accountId}/vnc.html?autoconnect=1&resize=scale&reconnect=true&path=${encodeURIComponent(`live/${accountId}/`)}`;
  el.meta.textContent = `account #${accountId}`
    + (data.vnc_port != null ? ` · vnc ${data.vnc_port}` : '')
    + (data.ws_port != null ? ` · ws ${data.ws_port}` : '')
    + (data.display != null ? ` · DISPLAY :${data.display}` : '');
  el.frame.src = url;
  setStatus(`Поток: ${url}`);
  // Keep idle timer warm while page is open
  try { await Api.liveView(accountId); } catch { /* optional */ }
  return data;
}

async function shotNow() {
  setStatus('Скриншот…');
  // Independent of iframe / live-view: POST forces fresh capture via worker
  const res = await Api.screenshotNow(accountId);
  const data = res.data || res;
  const path = data.path || data.filename || '';
  const src = path.startsWith('http') || path.startsWith('/') ? path : `/${path}`;
  el.shot.src = `${src}?t=${Date.now()}`;
  el.shot.classList.add('show');
  setStatus(`Скриншот: ${data.filename || path}`);
}

async function stopLive() {
  setStatus('Остановка…');
  await Api.stopSession(accountId);
  el.frame.src = 'about:blank';
  setStatus('Сессия остановлена, порты освобождены');
}

async function boot() {
  if (!Number.isFinite(accountId) || accountId <= 0) {
    setStatus('Укажите ?account_id=N', true);
    return;
  }
  await ensureAuth();
  el.btnBack.onclick = () => {
    window.location.href = `../index.html#/accounts/${accountId}`;
  };
  el.btnRefresh.onclick = () => startLive().catch((e) => setStatus(e.message, true));
  el.btnShot.onclick = () => shotNow().catch((e) => setStatus(e.message, true));
  el.btnStop.onclick = () => stopLive().catch((e) => setStatus(e.message, true));

  try {
    await startLive();
  } catch (e) {
    setStatus(e.message || 'Ошибка запуска', true);
  }

  // Heartbeat touch every 60s so idle GC does not kill an open view
  setInterval(() => {
    Api.liveView(accountId).catch(() => {});
  }, 60000);
}

boot();
