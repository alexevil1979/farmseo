'use strict';

const { waitReady } = require('./_helpers');

async function back(step, ctx) {
  const before = ctx.page.url();
  await ctx.page.goBack({ waitUntil: 'domcontentloaded', timeout: (step.timeout_sec || 30) * 1000 }).catch(async () => {
    // If history empty, stay
  });
  await waitReady(ctx.page);
  ctx.set('url', ctx.page.url());
  return { from: before, to: ctx.page.url() };
}

module.exports = back;
