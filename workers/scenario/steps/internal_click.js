'use strict';

const { waitReady } = require('./_helpers');
const { rand, pickOne } = require('../random');

/**
 * Click an internal same-origin link (depth navigation).
 */
async function internal_click(step, ctx) {
  const { page } = ctx;
  const selector = step.selector ? ctx.interpolate(step.selector) : 'a[href]';

  const candidates = await page.$$eval(selector, (as, originHref) => {
    const originUrl = new URL(originHref);
    return as
      .map((a) => {
        try {
          const u = new URL(a.href, originHref);
          if (u.origin !== originUrl.origin) return null;
          if (u.pathname === originUrl.pathname && u.search === originUrl.search) return null;
          const text = (a.textContent || '').trim().slice(0, 80);
          return { href: u.href, text };
        } catch (_) {
          return null;
        }
      })
      .filter(Boolean);
  }, page.url()).catch(() => []);

  if (!candidates.length) {
    if (step.optional || step.on_fail === 'continue') {
      return { skipped: true, reason: 'no_internal_links' };
    }
    throw new Error('internal_click: no same-origin links');
  }

  const pick = pickOne(candidates);
  const before = page.url();
  await page.goto(pick.href, { waitUntil: 'domcontentloaded', timeout: (step.timeout_sec || 60) * 1000 });
  await waitReady(page);
  await ctx.sleepMs(rand(200, 800));
  ctx.set('url', page.url());
  ctx.set('internal_clicks', Number(ctx.get('internal_clicks') || 0) + 1);
  return { from: before, to: page.url(), text: pick.text };
}

module.exports = internal_click;
