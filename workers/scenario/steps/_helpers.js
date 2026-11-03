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

async function waitReady(page, timeout = 30000) {
  await page.waitForFunction(() => document.readyState === 'interactive' || document.readyState === 'complete', {
    timeout,
  }).catch(() => {});
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
};
