'use strict';

const { rand } = require('../random');

async function mouse_move(step, ctx) {
  const { page, human } = ctx;
  let x = step.x != null ? Number(step.x) : null;
  let y = step.y != null ? Number(step.y) : null;

  if (x == null || y == null) {
    const vp = page.viewport() || { width: 1280, height: 720 };
    x = x != null ? x : rand(40, Math.max(80, vp.width - 40));
    y = y != null ? y : rand(40, Math.max(80, vp.height - 40));
  }

  if (human && typeof human.moveMouseBezier === 'function') {
    await human.moveMouseBezier(page, x, y);
  } else {
    await page.mouse.move(x, y, { steps: 12 });
  }
  return { x, y };
}

module.exports = mouse_move;
