'use strict';

const { rand } = require('../random');

/**
 * Long read: scroll through page with pauses until end (or max scrolls).
 */
async function read_article(step, ctx) {
  const { page, human } = ctx;
  const maxScrolls = Math.round(ctx.range({
    min: step.min != null ? step.min : (ctx.limits.max_scrolls || 4),
    max: step.max != null ? step.max : (ctx.limits.max_scrolls || 12),
  }, 6));

  const dwellSec = ctx.range({
    min: step.dwell_min != null ? step.dwell_min : 1,
    max: step.dwell_max != null ? step.dwell_max : 4,
  }, 2);

  let scrolls = 0;
  let atEnd = false;

  for (let i = 0; i < maxScrolls; i++) {
    const distance = rand(250, 900);
    if (human && typeof human.scrollInertia === 'function') {
      await human.scrollInertia(page, distance);
    } else {
      await page.evaluate((dy) => window.scrollBy(0, dy), distance);
    }
    scrolls += 1;
    await ctx.sleepMs(dwellSec * 1000 * (0.6 + Math.random() * 0.8));

    atEnd = await page.evaluate(() => {
      const max = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
      return window.scrollY + window.innerHeight >= max - 60;
    }).catch(() => false);

    if (atEnd && step.until_end !== false) break;

    if (human && typeof human.moveMouseBezier === 'function' && Math.random() < 0.4) {
      const vp = page.viewport() || { width: 1200, height: 700 };
      await human.moveMouseBezier(page, rand(50, vp.width - 50), rand(80, vp.height - 80));
    }
  }

  ctx.set('read_scrolls', scrolls);
  return { scrolls, at_end: atEnd };
}

module.exports = read_article;
