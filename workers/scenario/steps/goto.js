'use strict';

const { safeGoto } = require('./_helpers');

async function goto(step, ctx) {
  const url = ctx.interpolate(step.url);
  if (!url) throw new Error('goto: url required');
  const timeout = (step.timeout_sec || (ctx.preferMin ? 25 : 60)) * 1000;
  const result = await safeGoto(ctx, url, { timeout, retries: 2 });
  ctx.set('url', result.url);
  return { url: result.url, recovered: Boolean(result.recovered) };
}

module.exports = goto;
