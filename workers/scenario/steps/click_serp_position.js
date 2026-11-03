'use strict';

const { clickSerpIndex } = require('./_helpers');

async function click_serp_position(step, ctx) {
  const position = Math.round(ctx.range(step, step.position || 1));
  const href = await clickSerpIndex(ctx, position);
  ctx.set('last_serp_url', href);
  ctx.set('url', ctx.page.url());
  return { position, href, url: ctx.page.url() };
}

module.exports = click_serp_position;
