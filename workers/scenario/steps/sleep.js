'use strict';

async function sleepStep(step, ctx) {
  let ms;
  if (step.ms != null) {
    ms = Number(step.ms);
  } else if (step.sec != null) {
    ms = Number(step.sec) * 1000;
  } else {
    // min/max treated as seconds when both present without unit
    const unit = step.unit || (step.min > 50 || step.max > 50 ? 'ms' : 'sec');
    const n = ctx.range(step, unit === 'ms' ? 500 : 1);
    ms = unit === 'ms' ? n : n * 1000;
  }
  await ctx.sleepMs(ms);
  return { ms };
}

module.exports = sleepStep;
