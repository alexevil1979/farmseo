#!/usr/bin/env node
'use strict';

/**
 * End-to-end captcha test without real 2captcha:
 * local token-provider HTTP → detect sitekey → solve → inject smart-token.
 *
 * Run: node workers/lib/captcha/test-e2e.js
 */

const http = require('http');
const { createSolver, solveYandex, yandex } = require('./index');

function assert(cond, msg) {
  if (!cond) throw new Error(msg || 'assertion failed');
}

function fakePage(htmlState) {
  const state = {
    url: 'https://passport.yandex.ru/auth',
    sitekey: 'ysc1_testSiteKeyABCDEF1234567890',
    token: '',
    captchaPresent: true,
    ...htmlState,
  };

  return {
    url: () => state.url,
    mainFrame: () => ({ url: () => state.url }),
    frames: () => [{
      url: () => state.url,
      evaluate: async () => null,
    }, {
      // iframe SmartCaptcha
      url: () => `https://smartcaptcha.yandexcloud.net/captcha?sitekey=${state.sitekey}`,
      evaluate: async (fn, ...args) => {
        // Simulate cross-origin failure for evaluate on captcha host unless we allow it
        if (typeof fn === 'function') {
          try {
            return fn(...args);
          } catch {
            return null;
          }
        }
        return null;
      },
    }],
    evaluate: async (fn, ...args) => {
      // Provide a minimal DOM shim inside the evaluate callback context
      const document = {
        querySelector(sel) {
          const tokenInput = () => {
            const el = {
              type: 'hidden',
              name: 'smart-token',
              getAttribute: (n) => (n === 'value' ? state.token : null),
              setAttribute(n, v) { if (n === 'value') state.token = String(v); },
              dispatchEvent() {},
            };
            Object.defineProperty(el, 'value', {
              get: () => state.token,
              set: (v) => { state.token = String(v); },
              configurable: true,
            });
            return el;
          };

          if (sel.includes('smart-token')) {
            return tokenInput();
          }
          if (sel.includes('data-sitekey') || sel.includes('SmartCaptcha') || sel.includes('smart-captcha')) {
            if (!state.captchaPresent && !state.sitekey) return null;
            return {
              getAttribute: (n) => (n === 'data-sitekey' ? state.sitekey : null),
              dataset: { sitekey: state.sitekey },
              setAttribute() {},
              dispatchEvent() {},
              querySelector: () => null,
              appendChild() {},
            };
          }
          if (sel.includes('iframe')) {
            return state.captchaPresent
              ? { getAttribute: () => `https://smartcaptcha.yandexcloud.net/captcha?sitekey=${state.sitekey}` }
              : null;
          }
          if (sel.includes('checkcaptcha') || sel.includes('form')) {
            return state.captchaPresent
              ? {
                querySelector: (s) => (s.includes('smart-token') ? tokenInput() : null),
                appendChild() {},
              }
              : null;
          }
          if (sel.includes('script')) return null;
          return null;
        },
        querySelectorAll(sel) {
          const one = document.querySelector(sel);
          return one ? [one] : [];
        },
        createElement(tag) {
          const el = {
            type: '',
            name: '',
            value: '',
            tagName: tag.toUpperCase(),
            setAttribute(n, v) { if (n === 'value') el.value = v; },
            getAttribute(n) { return n === 'value' ? el.value : null; },
            dispatchEvent() {},
          };
          Object.defineProperty(el, 'value', {
            get: () => state.token,
            set: (v) => { state.token = String(v); },
            configurable: true,
          });
          return el;
        },
      };

      const window = {
        smartCaptchaCallback: null,
        onSmartCaptchaSuccess: null,
        __smartCaptchaCallback: null,
        smartCaptcha: null,
      };

      // Bind globals expected by injectToken / detect
      const prevDoc = global.document;
      const prevWin = global.window;
      const prevEvent = global.Event;
      const prevCustom = global.CustomEvent;
      global.document = document;
      global.window = window;
      global.Event = class Event { constructor() {} };
      global.CustomEvent = class CustomEvent { constructor() {} };
      try {
        return await fn(...args);
      } finally {
        global.document = prevDoc;
        global.window = prevWin;
        global.Event = prevEvent;
        global.CustomEvent = prevCustom;
      }
    },
    _state: state,
  };
}

async function startTokenServer(token) {
  const server = http.createServer((req, res) => {
    let raw = '';
    req.on('data', (c) => { raw += c; });
    req.on('end', () => {
      let body = {};
      try { body = JSON.parse(raw || '{}'); } catch { body = {}; }
      assert(body.sitekey, 'token server expected sitekey');
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ token, status: 'ready', sitekey: body.sitekey }));
    });
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const { port } = server.address();
  return {
    url: `http://127.0.0.1:${port}/token`,
    close: () => new Promise((r) => server.close(r)),
  };
}

async function main() {
  const expectedToken = 'dV9x_TEST_SMART_TOKEN_' + Date.now();
  const server = await startTokenServer(expectedToken);

  process.env.CAPTCHA_PROVIDER = 'token';
  process.env.CAPTCHA_TOKEN_URL = server.url;
  delete process.env.CAPTCHA_KEY;

  const page = fakePage();

  // 1) detect
  const detected = await yandex.detect(page);
  assert(detected.present, 'captcha should be detected');
  assert(detected.sitekey === page._state.sitekey, `sitekey mismatch: ${detected.sitekey}`);
  assert(detected.inIframe, 'iframe captcha expected');

  // 2) solve via token-provider
  const solver = createSolver({ provider: 'token', tokenUrl: server.url });
  const solved = await solver.solve('yandex', page, detected.sitekey);
  assert(solved.token === expectedToken, 'token mismatch from provider');
  assert(solved.provider === 'token', 'provider name');

  // 3) inject
  await yandex.injectToken(page, solved.token);
  assert(page._state.token === expectedToken, 'token not injected into page state');

  // 4) full solveYandex path with retries
  page._state.token = '';
  const full = await solveYandex(page, {
    provider: 'token',
    tokenUrl: server.url,
    retries: 2,
    skipWait: true,
    force: true,
  });
  assert(full.solved === true, 'solveYandex should succeed');
  assert(full.token === expectedToken, 'full token');
  assert(full.attempts >= 1, 'attempts');
  assert(page._state.token === expectedToken, 'token after solveYandex');

  await server.close();
  console.log('OK captcha e2e: detect → token-provider → inject');
  console.log(JSON.stringify({
    sitekey: full.sitekey,
    provider: full.provider,
    attempts: full.attempts,
    inIframe: full.inIframe,
  }));
}

main().catch((err) => {
  console.error('FAIL', err);
  process.exit(1);
});
