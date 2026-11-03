'use strict';

/**
 * Captcha provider adapters. Selected via CAPTCHA_PROVIDER (.env).
 * Interface: solve(type, page, sitekey, opts) → { token, provider, taskId? }
 */

const DEFAULT_POLL_MS = 5000;
const DEFAULT_TIMEOUT_MS = 180000;

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function httpJson(url, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
  let body;
  if (options.body != null) {
    if (typeof options.body === 'string') {
      body = options.body;
    } else {
      headers['Content-Type'] = headers['Content-Type'] || 'application/json';
      body = JSON.stringify(options.body);
    }
  }
  const res = await fetch(url, { method, headers, body, signal: options.signal });
  const text = await res.text();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = null;
  }
  return { ok: res.ok, status: res.status, text, json };
}

function pageUrl(page, opts = {}) {
  if (opts.pageurl) return String(opts.pageurl);
  if (page && typeof page.url === 'function') return page.url();
  return opts.url || '';
}

/**
 * Shared 2captcha / rucaptcha JSON API v2 (Yandex SmartCaptcha).
 */
function createCaptchaApiProvider(name, apiBase) {
  return {
    name,
    async solve(type, page, sitekey, opts = {}) {
      const key = opts.apiKey || process.env.CAPTCHA_KEY || '';
      if (!key) {
        throw new Error(`${name}: CAPTCHA_KEY is empty`);
      }
      const websiteURL = pageUrl(page, opts);
      const websiteKey = String(sitekey || opts.sitekey || '');
      if (!websiteKey) {
        throw new Error(`${name}: sitekey required`);
      }

      const captchaType = String(type || 'yandex').toLowerCase();
      let task;
      if (captchaType === 'yandex' || captchaType === 'smartcaptcha' || captchaType === 'yandex_smart') {
        task = {
          type: 'YandexSmartCaptchaTaskProxyless',
          websiteURL,
          websiteKey,
        };
        if (opts.userAgent) task.userAgent = opts.userAgent;
      } else {
        throw new Error(`${name}: unsupported captcha type "${type}"`);
      }

      const create = await httpJson(`${apiBase}/createTask`, {
        method: 'POST',
        body: { clientKey: key, task },
      });
      if (!create.json || create.json.errorId) {
        const msg = (create.json && (create.json.errorDescription || create.json.errorCode))
          || create.text
          || `HTTP ${create.status}`;
        throw new Error(`${name}: createTask failed: ${msg}`);
      }
      const taskId = create.json.taskId;
      if (!taskId) {
        throw new Error(`${name}: createTask returned no taskId`);
      }

      const pollMs = opts.pollMs != null ? Number(opts.pollMs) : DEFAULT_POLL_MS;
      const timeoutMs = opts.timeoutMs != null ? Number(opts.timeoutMs) : DEFAULT_TIMEOUT_MS;
      const deadline = Date.now() + timeoutMs;

      while (Date.now() < deadline) {
        await sleep(pollMs);
        const result = await httpJson(`${apiBase}/getTaskResult`, {
          method: 'POST',
          body: { clientKey: key, taskId },
        });
        if (!result.json) {
          throw new Error(`${name}: getTaskResult invalid response`);
        }
        if (result.json.errorId) {
          throw new Error(
            `${name}: getTaskResult error: ${result.json.errorDescription || result.json.errorCode}`,
          );
        }
        if (result.json.status === 'ready') {
          const token = result.json.solution && (
            result.json.solution.token
            || result.json.solution.gRecaptchaResponse
          );
          if (!token) {
            throw new Error(`${name}: ready but empty token`);
          }
          return { token: String(token), provider: name, taskId };
        }
        // status processing → keep polling
      }

      throw new Error(`${name}: timed out waiting for task ${taskId}`);
    },
  };
}

const twoCaptcha = createCaptchaApiProvider('2captcha', 'https://api.2captcha.com');
const ruCaptcha = createCaptchaApiProvider(
  'rucaptcha',
  process.env.RUCAPTCHA_API_BASE || 'https://api.rucaptcha.com',
);

/**
 * Own token provider: HTTP endpoint returns a ready SmartCaptcha token.
 *
 * CAPTCHA_TOKEN_URL required.
 * Request POST JSON: { type, sitekey, pageurl }
 * Response JSON: { token } | { status:"ready", token } | { solution:{ token } }
 */
const tokenProvider = {
  name: 'token',
  async solve(type, page, sitekey, opts = {}) {
    const url = opts.tokenUrl || process.env.CAPTCHA_TOKEN_URL || '';
    if (!url) {
      throw new Error('token-provider: CAPTCHA_TOKEN_URL is empty');
    }
    const websiteURL = pageUrl(page, opts);
    const websiteKey = String(sitekey || opts.sitekey || '');
    if (!websiteKey) {
      throw new Error('token-provider: sitekey required');
    }

    const headers = { 'Content-Type': 'application/json' };
    const apiKey = opts.apiKey || process.env.CAPTCHA_KEY || '';
    if (apiKey) {
      headers.Authorization = `Bearer ${apiKey}`;
      headers['X-Api-Key'] = apiKey;
    }

    const pollMs = opts.pollMs != null ? Number(opts.pollMs) : 1000;
    const timeoutMs = opts.timeoutMs != null ? Number(opts.timeoutMs) : 60000;
    const deadline = Date.now() + timeoutMs;
    let lastErr = 'no response';

    while (Date.now() < deadline) {
      const res = await httpJson(url, {
        method: 'POST',
        headers,
        body: {
          type: type || 'yandex',
          sitekey: websiteKey,
          pageurl: websiteURL,
          websiteURL,
          websiteKey,
        },
      });

      const j = res.json || {};
      const token = j.token
        || (j.solution && j.solution.token)
        || (j.data && j.data.token)
        || null;

      if (token) {
        return { token: String(token), provider: 'token' };
      }
      if (j.status === 'processing' || j.status === 'pending') {
        await sleep(pollMs);
        continue;
      }
      lastErr = j.error || j.message || res.text || `HTTP ${res.status}`;
      if (!res.ok) {
        throw new Error(`token-provider: ${lastErr}`);
      }
      await sleep(pollMs);
    }

    throw new Error(`token-provider: timed out (${lastErr})`);
  },
};

const PROVIDERS = {
  '2captcha': twoCaptcha,
  twocaptcha: twoCaptcha,
  rucaptcha: ruCaptcha,
  ru: ruCaptcha,
  token: tokenProvider,
  'token-provider': tokenProvider,
  local: tokenProvider,
};

function resolveProviderName(name) {
  const raw = String(name || process.env.CAPTCHA_PROVIDER || '2captcha').trim().toLowerCase();
  return raw;
}

function getProvider(name) {
  const key = resolveProviderName(name);
  const provider = PROVIDERS[key];
  if (!provider) {
    throw new Error(`Unknown CAPTCHA_PROVIDER "${key}". Use: 2captcha | rucaptcha | token`);
  }
  return provider;
}

module.exports = {
  PROVIDERS,
  getProvider,
  resolveProviderName,
  twoCaptcha,
  ruCaptcha,
  tokenProvider,
  httpJson,
  sleep,
};
