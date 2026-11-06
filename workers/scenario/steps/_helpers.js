'use strict';

const { rand, randInt } = require('../random');

const YANDEX_SERVICES = Object.freeze({
  mail: 'https://mail.yandex.ru/',
  weather: 'https://yandex.ru/pogoda/',
  news: 'https://dzen.ru/news',
  market: 'https://market.yandex.ru/',
  images: 'https://yandex.ru/images/',
  картинки: 'https://yandex.ru/images/',
  music: 'https://music.yandex.ru/',
  maps: 'https://yandex.ru/maps/',
  video: 'https://yandex.ru/video/',
});

const SERP_SELECTORS = [
  '#search-result .OrganicTitle a',
  '#search-result a.Link',
  '.serp-item a.organic__url',
  '.OrganicTitle-Link',
  'li.serp-item h2 a',
  '#search a[href*="://"]',
];

function isNavTransientError(err) {
  const msg = String(err && err.message ? err.message : err || '');
  return /detached Frame|Session closed|Target closed|Execution context was destroyed|frame was detached|Navigating frame was detached|Protocol error/i.test(msg);
}

/**
 * Ensure ctx.page is a live Puppeteer page (recover after crash / detach).
 * @param {object} ctx
 * @returns {Promise<import('puppeteer').Page>}
 */
async function ensureLivePage(ctx) {
  let page = ctx.page;
  const alive = async (p) => {
    if (!p) return false;
    try {
      if (typeof p.isClosed === 'function' && p.isClosed()) return false;
      const frame = p.mainFrame && p.mainFrame();
      if (!frame) return false;
      return await p.evaluate(() => true).then(() => true).catch(() => false);
    } catch (_) {
      return false;
    }
  };

  if (await alive(page)) return page;

  const browser = ctx.browser;
  if (!browser) throw new Error('ensureLivePage: no browser');

  try {
    const pages = await browser.pages();
    for (const p of pages) {
      if (await alive(p)) {
        ctx.page = p;
        return p;
      }
    }
  } catch (_) { /* fall through */ }

  const fresh = await browser.newPage();
  ctx.page = fresh;
  return fresh;
}

async function waitReady(page, timeout = 30000) {
  try {
    if (!page || (typeof page.isClosed === 'function' && page.isClosed())) return;
    await page.waitForFunction(
      () => document.readyState === 'interactive' || document.readyState === 'complete',
      { timeout },
    ).catch(() => {});
  } catch (_) { /* detached / closed — ignore */ }
}

/**
 * Navigate with retries for Puppeteer detached-frame / closed-target races.
 * @param {object} ctx
 * @param {string} url
 * @param {object} [opts]
 */
async function safeGoto(ctx, url, opts = {}) {
  const timeout = opts.timeout != null ? opts.timeout : 60000;
  const waitUntil = opts.waitUntil || 'domcontentloaded';
  const retries = opts.retries != null ? opts.retries : 2;

  let lastErr = null;
  for (let attempt = 0; attempt <= retries; attempt++) {
    const page = await ensureLivePage(ctx);
    try {
      const res = await page.goto(url, { waitUntil, timeout });
      await waitReady(page, Math.min(timeout, 15000));
      return { page: ctx.page, response: res, url: ctx.page.url() };
    } catch (err) {
      lastErr = err;
      if (!isNavTransientError(err)) throw err;

      // Sometimes navigation actually completed despite detached Frame.
      try {
        const p = ctx.page;
        if (p && !(typeof p.isClosed === 'function' && p.isClosed())) {
          const cur = p.url();
          if (cur && cur !== 'about:blank' && !cur.startsWith('chrome-error://')) {
            await waitReady(p, 5000);
            return { page: p, response: null, url: cur, recovered: true };
          }
        }
      } catch (_) { /* ignore */ }

      // Drop dead page and retry with a fresh tab.
      try {
        if (ctx.page && typeof ctx.page.close === 'function' && !ctx.page.isClosed()) {
          await ctx.page.close({ runBeforeUnload: false }).catch(() => {});
        }
      } catch (_) { /* ignore */ }
      ctx.page = null;
      await ctx.sleepMs?.(200 + attempt * 300).catch?.(() => {});
      if (!ctx.sleepMs) {
        await new Promise((r) => setTimeout(r, 200 + attempt * 300));
      }
    }
  }
  throw lastErr || new Error(`safeGoto failed: ${url}`);
}

async function humanClick(ctx, selectorOrHandle) {
  const { page, human } = ctx;
  if (human && typeof human.clickHuman === 'function') {
    await human.clickHuman(page, selectorOrHandle);
    return;
  }
  if (typeof selectorOrHandle === 'string') {
    await page.click(selectorOrHandle);
  } else {
    await selectorOrHandle.click();
  }
}

async function humanType(ctx, selector, text) {
  const { page, human } = ctx;
  const el = await page.$(selector);
  if (!el) throw new Error(`type: selector not found: ${selector}`);
  await el.click({ clickCount: 3 }).catch(() => el.click());
  await ctx.sleepMs(rand(80, 200));
  if (human && typeof human.typeHuman === 'function') {
    await human.typeHuman(page, text);
  } else {
    await page.keyboard.type(text, { delay: randInt(40, 120) });
  }
}

async function findFirst(page, selectors) {
  for (const sel of selectors) {
    const el = await page.$(sel);
    if (el) return { el, selector: sel };
  }
  return null;
}

async function getSerpLinks(page) {
  for (const sel of SERP_SELECTORS) {
    const handles = await page.$$(sel);
    if (handles.length) {
      const links = [];
      for (const h of handles) {
        const href = await page.evaluate((a) => a.href || a.getAttribute('href'), h).catch(() => null);
        if (href && /^https?:/i.test(href) && !/yandex\.(ru|com)|ya\.ru/i.test(new URL(href).hostname)) {
          links.push({ handle: h, href });
        } else if (href && /^https?:/i.test(href)) {
          // keep yandex too as fallback organic/service
          links.push({ handle: h, href, yandex: true });
        }
      }
      if (links.length) return links;
    }
  }
  // fallback: any external-ish anchors
  return page.$$eval('a[href^="http"]', (as) => as.slice(0, 30).map((a, i) => ({
    href: a.href,
    index: i,
  }))).then(async (list) => {
    const out = [];
    for (const item of list) {
      out.push({ href: item.href, handle: null, index: item.index });
    }
    return out;
  }).catch(() => []);
}

async function clickSerpIndex(ctx, position) {
  const { page } = ctx;
  const idx = Math.max(1, Number(position) || 1) - 1;
  const links = await getSerpLinks(page);
  if (!links.length) throw new Error('SERP: no organic links found');
  const target = links[Math.min(idx, links.length - 1)];
  if (target.handle) {
    await humanClick(ctx, target.handle);
  } else {
    await page.goto(target.href, { waitUntil: 'domcontentloaded', timeout: 60000 });
  }
  await waitReady(page);
  return target.href;
}

function resolveServiceUrl(service) {
  if (!service) return null;
  const key = String(service).trim().toLowerCase();
  if (YANDEX_SERVICES[key]) return YANDEX_SERVICES[key];
  if (YANDEX_SERVICES[service]) return YANDEX_SERVICES[service];
  if (/^https?:\/\//i.test(service)) return service;
  return null;
}

module.exports = {
  YANDEX_SERVICES,
  SERP_SELECTORS,
  waitReady,
  humanClick,
  humanType,
  findFirst,
  getSerpLinks,
  clickSerpIndex,
  resolveServiceUrl,
  isNavTransientError,
  ensureLivePage,
  safeGoto,
};
