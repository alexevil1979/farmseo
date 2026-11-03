'use strict';

const { waitReady } = require('./_helpers');

async function open_new_tab(step, ctx) {
  const url = ctx.interpolate(step.url);
  const page = await ctx.browser.newPage();
  if (url) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: (step.timeout_sec || 60) * 1000 });
    await waitReady(page);
  }
  await page.bringToFront();
  ctx.page = page;
  ctx.set('url', page.url());
  const pages = await ctx.browser.pages();
  return { url: page.url(), tabs: pages.length };
}

module.exports = open_new_tab;
