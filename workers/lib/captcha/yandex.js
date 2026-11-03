'use strict';

/**
 * Yandex SmartCaptcha: detect in DOM/iframe, extract sitekey, inject token, retries.
 */

const SITEKEY_SELECTORS = [
  '[data-sitekey]',
  '.smart-captcha[data-sitekey]',
  '.SmartCaptcha[data-sitekey]',
  '#captcha[data-sitekey]',
  'div[id*="captcha"][data-sitekey]',
];

const WIDGET_MARKERS = [
  '.SmartCaptcha',
  '.smart-captcha',
  '[class*="SmartCaptcha"]',
  '[class*="smart-captcha"]',
  'iframe[src*="smartcaptcha"]',
  'iframe[src*="captcha.yandex"]',
  'iframe[src*="captcha-api.yandex"]',
  'form[action*="checkcaptcha"]',
  'input[name="smart-token"]',
  'textarea[name="smart-token"]',
];

/**
 * @param {import('puppeteer').Page} page
 * @returns {Promise<{ present: boolean, sitekey: string|null, inIframe: boolean, iframeSrc: string|null }>}
 */
async function detect(page) {
  const main = await page.evaluate((markers, keySelectors) => {
    const present = markers.some((s) => document.querySelector(s));
    let sitekey = null;
    for (const sel of keySelectors) {
      const el = document.querySelector(sel);
      if (el) {
        const k = el.getAttribute('data-sitekey') || el.dataset?.sitekey;
        if (k) {
          sitekey = k;
          break;
        }
      }
    }
    if (!sitekey) {
      const scripts = Array.from(document.querySelectorAll('script[src], script'));
      for (const s of scripts) {
        const src = s.getAttribute('src') || '';
        const text = s.textContent || '';
        const blob = `${src}\n${text}`;
        const m = blob.match(/sitekey["'\s:=]+["']([A-Za-z0-9_-]{10,})["']/i)
          || blob.match(/data-sitekey=["']([A-Za-z0-9_-]{10,})["']/i)
          || blob.match(/smartcaptcha\.yandex[^"']*sitekey=([A-Za-z0-9_-]{10,})/i);
        if (m) {
          sitekey = m[1];
          break;
        }
      }
    }
    const iframe = document.querySelector(
      'iframe[src*="smartcaptcha"], iframe[src*="captcha.yandex"], iframe[src*="captcha-api.yandex"]',
    );
    return {
      present: present || Boolean(iframe) || Boolean(sitekey),
      sitekey,
      inIframe: Boolean(iframe),
      iframeSrc: iframe ? iframe.getAttribute('src') : null,
    };
  }, WIDGET_MARKERS, SITEKEY_SELECTORS).catch(() => ({
    present: false,
    sitekey: null,
    inIframe: false,
    iframeSrc: null,
  }));

  let sitekey = main.sitekey;
  let inIframe = main.inIframe;
  let iframeSrc = main.iframeSrc;

  if (!sitekey && iframeSrc) {
    const fromSrc = iframeSrc.match(/[?&]sitekey=([A-Za-z0-9_-]+)/i)
      || iframeSrc.match(/\/([A-Za-z0-9_-]{20,})\//);
    if (fromSrc) sitekey = fromSrc[1];
  }

  // Probe same-origin iframes for sitekey / markers
  if (!sitekey) {
    const frames = page.frames ? page.frames() : [];
    for (const frame of frames) {
      if (frame === page.mainFrame()) continue;
      const url = frame.url() || '';
      if (!/captcha|smartcaptcha/i.test(url) && !inIframe) continue;
      inIframe = true;
      if (!iframeSrc && url) iframeSrc = url;
      const fromUrl = url.match(/[?&]sitekey=([A-Za-z0-9_-]+)/i);
      if (fromUrl) {
        sitekey = fromUrl[1];
        break;
      }
      try {
        const inner = await frame.evaluate((keySelectors) => {
          for (const sel of keySelectors) {
            const el = document.querySelector(sel);
            const k = el && (el.getAttribute('data-sitekey') || el.dataset?.sitekey);
            if (k) return k;
          }
          return null;
        }, SITEKEY_SELECTORS);
        if (inner) {
          sitekey = inner;
          break;
        }
      } catch {
        // cross-origin iframe — ignore
      }
    }
  }

  return {
    present: Boolean(main.present || sitekey || inIframe),
    sitekey: sitekey || null,
    inIframe: Boolean(inIframe),
    iframeSrc: iframeSrc || null,
  };
}

/**
 * @param {import('puppeteer').Page} page
 * @returns {Promise<string|null>}
 */
async function extractSitekey(page) {
  const info = await detect(page);
  return info.sitekey;
}

/**
 * Insert SmartCaptcha token into the page (hidden input + callbacks).
 * @param {import('puppeteer').Page} page
 * @param {string} token
 */
async function injectToken(page, token) {
  if (!token) throw new Error('yandex.injectToken: empty token');

  const result = await page.evaluate((tok) => {
    const setVal = (el, value) => {
      if (!el) return false;
      el.value = value;
      el.setAttribute('value', value);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    };

    let filled = 0;
    const selectors = [
      'input[name="smart-token"]',
      'textarea[name="smart-token"]',
      'input[name="smart-token-value"]',
      '#smart-token',
      '[data-testid="smart-token"]',
      'input[name="captcha_token"]',
    ];
    for (const sel of selectors) {
      document.querySelectorAll(sel).forEach((el) => {
        if (setVal(el, tok)) filled += 1;
      });
    }

    // Create hidden field if form exists but no token input
    if (filled === 0) {
      const form = document.querySelector('form[action*="checkcaptcha"], form.SmartCaptcha, form');
      if (form) {
        let input = form.querySelector('input[name="smart-token"]');
        if (!input) {
          input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'smart-token';
          form.appendChild(input);
        }
        if (setVal(input, tok)) filled += 1;
      }
    }

    // Widget / global callbacks used by Yandex SmartCaptcha embeds
    const callbacks = [
      window.smartCaptchaCallback,
      window.onSmartCaptchaSuccess,
      window.__smartCaptchaCallback,
    ];
    let callbackFired = 0;
    for (const cb of callbacks) {
      if (typeof cb === 'function') {
        try {
          cb(tok);
          callbackFired += 1;
        } catch (_) { /* ignore */ }
      }
    }

    if (window.smartCaptcha && typeof window.smartCaptcha.submit === 'function') {
      try {
        window.smartCaptcha.submit();
      } catch (_) { /* ignore */ }
    }

    // data attribute on widget container
    document.querySelectorAll('.SmartCaptcha, .smart-captcha, [data-sitekey]').forEach((el) => {
      el.setAttribute('data-token', tok);
      el.dispatchEvent(new CustomEvent('smartcaptcha:success', { detail: { token: tok }, bubbles: true }));
    });

    return { filled, callbackFired };
  }, token);

  return result;
}

/**
 * @param {import('puppeteer').Page} page
 * @param {{ timeoutMs?: number, pollMs?: number }} [opts]
 */
async function waitCleared(page, opts = {}) {
  const timeoutMs = opts.timeoutMs != null ? Number(opts.timeoutMs) : 15000;
  const pollMs = opts.pollMs != null ? Number(opts.pollMs) : 500;
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const still = await detect(page);
    // Cleared when widget gone OR token field filled and challenge form gone
    const challenge = await page.evaluate(() => Boolean(
      document.querySelector('form[action*="checkcaptcha"] .SmartCaptcha, form[action*="checkcaptcha"]'),
    )).catch(() => false);
    if (!still.present || !challenge) {
      const tokenFilled = await page.evaluate(() => {
        const el = document.querySelector('input[name="smart-token"], textarea[name="smart-token"]');
        return Boolean(el && el.value && el.value.length > 20);
      }).catch(() => false);
      if (!still.present || tokenFilled) {
        return true;
      }
    }
    await new Promise((r) => setTimeout(r, pollMs));
  }
  return false;
}

/**
 * Full detect → solve → inject loop with retries.
 *
 * @param {import('puppeteer').Page} page
 * @param {{ solve: Function }} solver  provider with solve(type, page, sitekey, opts)
 * @param {object} [opts]
 */
async function solveWithRetries(page, solver, opts = {}) {
  const maxAttempts = opts.retries != null ? Number(opts.retries) : 3;
  const type = opts.type || 'yandex';
  let lastError = null;
  let detected = await detect(page);

  if (!detected.present && !opts.force) {
    return { solved: false, present: false, attempts: 0 };
  }

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      detected = await detect(page);
      const sitekey = opts.sitekey || detected.sitekey;
      if (!sitekey) {
        throw new Error('SmartCaptcha sitekey not found in DOM/iframe');
      }

      const solved = await solver.solve(type, page, sitekey, {
        ...opts,
        sitekey,
        pageurl: opts.pageurl || (page.url && page.url()),
      });

      await injectToken(page, solved.token);

      const cleared = opts.skipWait
        ? true
        : await waitCleared(page, {
          timeoutMs: opts.clearTimeoutMs != null ? opts.clearTimeoutMs : 10000,
        });

      return {
        solved: true,
        present: true,
        attempts: attempt,
        token: solved.token,
        provider: solved.provider,
        taskId: solved.taskId || null,
        sitekey,
        cleared,
        inIframe: detected.inIframe,
      };
    } catch (err) {
      lastError = err;
      if (attempt < maxAttempts) {
        await new Promise((r) => setTimeout(r, opts.retryDelayMs != null ? opts.retryDelayMs : 1500));
      }
    }
  }

  throw new Error(
    `yandex.solveWithRetries: failed after ${maxAttempts} attempt(s): ${lastError && lastError.message}`,
  );
}

module.exports = {
  detect,
  extractSitekey,
  injectToken,
  waitCleared,
  solveWithRetries,
  WIDGET_MARKERS,
  SITEKEY_SELECTORS,
};
