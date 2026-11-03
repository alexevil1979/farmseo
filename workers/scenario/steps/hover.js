'use strict';

const { rand } = require('../random');

async function hover(step, ctx) {
  const selector = ctx.interpolate(step.selector);
  if (!selector) throw new Error('hover: selector required');
  const { page, human } = ctx;
  await page.waitForSelector(selector, { timeout: (step.timeout_sec || 20) * 1000, visible: true });
  const el = await page.$(selector);
  if (!el) throw new Error(`hover: not found ${selector}`);
  const box = await el.boundingBox();
  if (!box) throw new Error('hover: no bounding box');
  const x = box.x + box.width * rand(0.3, 0.7);
  const y = box.y + box.height * rand(0.3, 0.7);
  if (human && typeof human.moveMouseBezier === 'function') {
    await human.moveMouseBezier(page, x, y);
  } else {
    await page.mouse.move(x, y, { steps: 10 });
  }
  await el.hover().catch(() => {});
  await ctx.sleepMs(rand(150, 500));
  return { selector, x, y };
}

module.exports = hover;
