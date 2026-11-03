import { qs } from './utils.js';

const STORAGE_KEY = 'farm_admin_auth';

function cfg() {
  return window.FarmConfig || { apiBase: '/api/v1' };
}

function loadAuth() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
  } catch {
    return null;
  }
}

function saveAuth(data) {
  if (!data) localStorage.removeItem(STORAGE_KEY);
  else localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
}

export const Api = {
  getAuth() {
    return loadAuth();
  },

  setAuth(data) {
    saveAuth(data);
  },

  clearAuth() {
    saveAuth(null);
  },

  base() {
    return String(cfg().apiBase || '/api/v1').replace(/\/$/, '');
  },

  async request(path, options = {}) {
    const auth = loadAuth();
    const headers = {
      Accept: 'application/json',
      ...(options.body != null ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    };
    if (auth?.token) headers.Authorization = `Bearer ${auth.token}`;
    if (auth?.csrf && !headers['X-CSRF-Token']) headers['X-CSRF-Token'] = auth.csrf;

    const url = path.startsWith('http') ? path : `${this.base()}${path.startsWith('/') ? '' : '/'}${path}`;
    const res = await fetch(url, {
      ...options,
      headers,
      body: options.body != null && typeof options.body !== 'string'
        ? JSON.stringify(options.body)
        : options.body,
    });

    const text = await res.text();
    let json = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch {
      json = { raw: text };
    }

    if (res.status === 401) {
      if (!path.includes('/auth/login')) {
        this.clearAuth();
        window.dispatchEvent(new CustomEvent('farm:unauthorized'));
      }
    }

    if (!res.ok) {
      const err = new Error(json?.error?.message || json?.message || `HTTP ${res.status}`);
      err.status = res.status;
      err.code = json?.error?.code || 'http_error';
      err.payload = json;
      throw err;
    }

    return json;
  },

  get(path, params) {
    return this.request(`${path}${qs(params)}`);
  },

  post(path, body, headers) {
    return this.request(path, { method: 'POST', body, headers });
  },

  put(path, body) {
    return this.request(path, { method: 'PUT', body });
  },

  patch(path, body) {
    return this.request(path, { method: 'PATCH', body });
  },

  del(path) {
    return this.request(path, { method: 'DELETE' });
  },

  /** Soft-fail helper for optional nested endpoints (404 → null). */
  async tryGet(path, params) {
    try {
      return await this.get(path, params);
    } catch (e) {
      if (e.status === 404 || e.status === 501) return null;
      throw e;
    }
  },

  // ——— Auth ———
  login(email, password, totp_code) {
    return this.post('/auth/login', { email, password, totp_code: totp_code || undefined });
  },
  logout() {
    return this.post('/auth/logout', {});
  },
  me() {
    return this.get('/auth/me');
  },

  // ——— Core CRUD ———
  list(resource, params) {
    return this.get(`/${resource}`, params);
  },
  getOne(resource, id) {
    return this.get(`/${resource}/${id}`);
  },
  create(resource, body) {
    return this.post(`/${resource}`, body);
  },
  update(resource, id, body) {
    return this.patch(`/${resource}/${id}`, body);
  },
  remove(resource, id) {
    return this.del(`/${resource}/${id}`);
  },

  // ——— Special ———
  stats() {
    return this.get('/stats');
  },
  health() {
    return this.get('/health');
  },
  startSession(accountId) {
    return this.post(`/accounts/${accountId}/session`, {});
  },
  stopSession(accountId) {
    return this.post(`/accounts/${accountId}/session/stop`, {}).catch((e) => {
      if (e.status === 404) return this.post(`/accounts/${accountId}/session`, { action: 'stop' });
      throw e;
    });
  },
  resetSession(accountId) {
    return this.post(`/accounts/${accountId}/session/reset`, {}).catch((e) => {
      if (e.status === 404) return this.post(`/accounts/${accountId}/session`, { action: 'reset' });
      throw e;
    });
  },
  screenshot(accountId) {
    return this.get(`/accounts/${accountId}/screenshot`);
  },
  screenshotNow(accountId) {
    return this.post(`/accounts/${accountId}/screenshot`, { now: true }).catch((e) => {
      if (e.status === 404 || e.status === 405) {
        return this.get(`/accounts/${accountId}/screenshot`, { fresh: 1 });
      }
      throw e;
    });
  },
  liveView(accountId) {
    return this.get(`/accounts/${accountId}/live`);
  },
  runScenario(id, body) {
    return this.post(`/scenarios/${id}/run`, body);
  },

  // Account nested (consume when API exposes them)
  accountFingerprint(id) {
    return this.tryGet(`/accounts/${id}/fingerprint`);
  },
  generateFingerprint(id, body = {}) {
    return this.post(`/accounts/${id}/fingerprint`, body);
  },
  assignProxy(id, body = {}) {
    return this.post(`/accounts/${id}/assign-proxy`, body);
  },
  bulkProvision(body) {
    return this.post('/accounts/bulk-provision', body);
  },
  accountSessions(id, params) {
    return this.tryGet(`/accounts/${id}/sessions`, params);
  },
  accountCookies(id, params) {
    return this.tryGet(`/accounts/${id}/cookies`, params);
  },
  accountScreenshots(id, params) {
    return this.tryGet(`/accounts/${id}/screenshots`, params);
  },

  // Optional resources used by admin sections
  schedules(params) {
    return this.tryGet('/scenario_schedules', params);
  },
  createSchedule(body) {
    return this.post('/scenario_schedules', body);
  },
  updateSchedule(id, body) {
    return this.patch(`/scenario_schedules/${id}`, body);
  },
  deleteSchedule(id) {
    return this.del(`/scenario_schedules/${id}`);
  },
  settings(params) {
    return this.tryGet('/settings', params);
  },
  saveSetting(body) {
    return this.post('/settings', body);
  },
  updateSetting(id, body) {
    return this.patch(`/settings/${id}`, body);
  },
  alerts(params) {
    return this.tryGet('/alerts', params);
  },
  ackAlert(id) {
    return this.post(`/alerts/${id}/ack`, {});
  },
  auditLog(params) {
    return this.tryGet('/audit_log', params);
  },
  metrics(params) {
    return this.tryGet('/metrics', params);
  },
  apiTokens(params) {
    return this.tryGet('/api_tokens', params);
  },
  createApiToken(body) {
    return this.post('/api_tokens', body);
  },
  revokeApiToken(id) {
    return this.del(`/api_tokens/${id}`);
  },
  reports(params) {
    return this.tryGet('/reports', params);
  },
  exportReport(format, params) {
    return this.get(`/reports/export${qs({ ...params, format })}`);
  },
};
