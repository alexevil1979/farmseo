'use strict';

const { humanClick, waitReady } = require('./_helpers');

async function click_selector(step, ctx) {
  const selector = ctx.interpolate(step.selector);
  if (!selector) throw new Error('click_selector: selector required');
  const timeout = (step.timeout_sec || 30) * 1000;
  await ctx.page.waitForSelector(selector, { timeout, visible: true });
  await humanClick(ctx, selector);
  if (step.wait_nav) {
    await ctx.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout }).catch(() => {});
  }
  await waitReady(ctx.page, timeout);
  ctx.set('url', ctx.page.url());
  return { selector, url: ctx.page.url() };
}

module.exports = click_selector;
