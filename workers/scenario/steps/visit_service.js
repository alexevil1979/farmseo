'use strict';

const { waitReady, resolveServiceUrl, YANDEX_SERVICES } = require('./_helpers');
const { rand, pickOne } = require('../random');

async function visit_service(step, ctx) {
  let service = ctx.interpolate(step.service);
  if (!service && Array.isArray(step.services)) {
    service = pickOne(step.services);
  }
  if (!service && step.random) {
    service = pickOne(Object.keys(YANDEX_SERVICES).filter((k) => k !== 'картинки'));
  }

  const url = ctx.interpolate(step.url) || resolveServiceUrl(service);
  if (!url) throw new Error('visit_service: service|url required');

  await ctx.page.goto(url, { waitUntil: 'domcontentloaded', timeout: (step.timeout_sec || 60) * 1000 });
  await waitReady(ctx.page);

  // light interaction
  if (ctx.human && typeof ctx.human.scrollInertia === 'function') {
    await ctx.human.scrollInertia(ctx.page, rand(150, 500));
  }
  await ctx.sleepMs(rand(800, 2500));

  ctx.set('url', ctx.page.url());
  ctx.set('last_service', service || url);
  return { service: service || null, url: ctx.page.url() };
}

module.exports = visit_service;
