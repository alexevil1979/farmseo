'use strict';

const { waitReady } = require('./_helpers');

async function goto(step, ctx) {
  const url = ctx.interpolate(step.url);
  if (!url) throw new Error('goto: url required');
  const timeout = (step.timeout_sec || (ctx.preferMin ? 25 : 60)) * 1000;
  await ctx.page.goto(url, { waitUntil: 'domcontentloaded', timeout });
  await waitReady(ctx.page, Math.min(timeout, 15000));
  ctx.set('url', ctx.page.url());
  return { url: ctx.page.url() };
}

module.exports = goto;
