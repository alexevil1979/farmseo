'use strict';

/**
 * Captcha solver facade.
 *
 * Env:
 *   CAPTCHA_PROVIDER=2captcha|rucaptcha|token
 *   CAPTCHA_KEY=...
 *   CAPTCHA_TOKEN_URL=...   (for token provider)
 *
 * Interface: solve(type, page, sitekey, opts?) → { token, provider, ... }
 */

const {
  getProvider,
  resolveProviderName,
  PROVIDERS,
  twoCaptcha,
  ruCaptcha,
  tokenProvider,
} = require('./providers');
const yandex = require('./yandex');

/**
 * @param {object} [options]
 * @param {string} [options.provider]
 * @param {string} [options.apiKey]
 * @param {string} [options.tokenUrl]
 */
function createSolver(options = {}) {
  const providerName = resolveProviderName(options.provider);
  const provider = getProvider(providerName);
  const baseOpts = {
    apiKey: options.apiKey || process.env.CAPTCHA_KEY || '',
    tokenUrl: options.tokenUrl || process.env.CAPTCHA_TOKEN_URL || '',
    pollMs: options.pollMs,
    timeoutMs: options.timeoutMs,
  };

  return {
    provider: provider.name,
    /**
     * @param {string} type  e.g. "yandex"
     * @param {import('puppeteer').Page|null} page
     * @param {string} sitekey
     * @param {object} [opts]
     */
    async solve(type, page, sitekey, opts = {}) {
      return provider.solve(type, page, sitekey, { ...baseOpts, ...opts });
    },
  };
}

/**
 * Convenience: solve via active provider from env.
 * @param {string} type
 * @param {import('puppeteer').Page|null} page
 * @param {string} sitekey
 * @param {object} [opts]
 */
async function solve(type, page, sitekey, opts = {}) {
  const solver = createSolver(opts);
  return solver.solve(type, page, sitekey, opts);
}

/**
 * Detect + solve Yandex SmartCaptcha on a page (with retries / token inject).
 * @param {import('puppeteer').Page} page
 * @param {object} [opts]
 */
async function solveYandex(page, opts = {}) {
  const solver = createSolver(opts);
  return yandex.solveWithRetries(page, solver, opts);
}

module.exports = {
  createSolver,
  solve,
  solveYandex,
  getProvider,
  resolveProviderName,
  PROVIDERS,
  providers: {
    twoCaptcha,
    ruCaptcha,
    tokenProvider,
    '2captcha': twoCaptcha,
    rucaptcha: ruCaptcha,
    token: tokenProvider,
  },
  yandex,
};
