'use strict';

const { rand } = require('../random');

async function scroll(step, ctx) {
  const { page, human } = ctx;
  const times = Math.max(1, Math.round(ctx.range(
    step.times != null ? { min: step.times, max: step.times } : { min: step.min_times || 1, max: step.max_times || step.max || 1 },
    1,
  )));

  const distance = ctx.range(
    { min: step.min != null ? step.min : 200, max: step.max != null ? step.max : 800 },
    400,
  );

  for (let i = 0; i < times; i++) {
    const d = typeof distance === 'number' ? distance * (0.7 + Math.random() * 0.6) : rand(200, 800);
    if (human && typeof human.scrollInertia === 'function') {
      await human.scrollInertia(page, d);
    } else {
      await page.evaluate((dy) => window.scrollBy(0, dy), d);
      await ctx.sleepMs(rand(120, 400));
    }
  }

  const atEnd = await page.evaluate(() => {
    const max = Math.max(
      document.body.scrollHeight,
      document.documentElement.scrollHeight,
    );
    return window.scrollY + window.innerHeight >= max - 40;
  }).catch(() => false);

  return { times, distance, at_end: atEnd };
}

module.exports = scroll;
