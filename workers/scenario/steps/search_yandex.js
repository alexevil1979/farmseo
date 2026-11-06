'use strict';

const { waitReady, humanType, findFirst, safeGoto, ensureLivePage } = require('./_helpers');
const { rand } = require('../random');

const INPUT_SELECTORS = [
  'input#text',
  'input[name="text"]',
  '.search3__input',
  'input.search3__input',
  'form[action*="yandex"] input[type="text"]',
  'input[aria-label*="запрос" i]',
  'input[type="search"]',
];

async function search_yandex(step, ctx) {
  let query = ctx.interpolate(step.query || step.text || ctx.get('keyword') || '{{random_query}}');
  if (!query || query === 'null' || query === 'undefined') {
    query = ctx.interpolate('{{random_query}}');
  }
  if (!query) throw new Error('search_yandex: query required');

  const navTimeout = (step.timeout_sec || (ctx.preferMin ? 25 : 60)) * 1000;
  const start = step.url || 'https://yandex.ru/';
  await ensureLivePage(ctx);
  if (!/yandex\.(ru|com)|ya\.ru/i.test(ctx.page.url())) {
    await safeGoto(ctx, start, { timeout: navTimeout, retries: 2 });
  }

  const found = await findFirst(ctx.page, step.selector ? [step.selector] : INPUT_SELECTORS);
  if (!found) {
    // fallback: navigate via search URL
    const u = `https://yandex.ru/search/?text=${encodeURIComponent(query)}`;
    await safeGoto(ctx, u, { timeout: navTimeout, retries: 1 });
    ctx.set('query', query);
    ctx.set('url', ctx.page.url());
    return { query, via: 'url' };
  }

  await humanType(ctx, found.selector, query);
  await ctx.sleepMs(rand(200, 600));
  await ctx.page.keyboard.press('Enter');
  await ctx.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: navTimeout }).catch(() => {});
  await waitReady(ctx.page, Math.min(navTimeout, 15000));
  ctx.set('query', query);
  ctx.set('url', ctx.page.url());
  return { query, via: 'input' };
}

module.exports = search_yandex;
