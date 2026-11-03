'use strict';

async function screenshot(step, ctx) {
  const name = ctx.interpolate(step.name || step.filename) || `manual-${Date.now()}.png`;
  const rel = await ctx.screenshot(name);
  return { path: rel };
}

module.exports = screenshot;
