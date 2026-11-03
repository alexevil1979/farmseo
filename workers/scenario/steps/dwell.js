'use strict';

async function dwell(step, ctx) {
  // min/max in seconds (CONTRACT limits use dwell_sec_*)
  let sec;
  if (step.min != null || step.max != null) {
    sec = ctx.range(step, 3);
  } else if (step.sec != null) {
    sec = Number(step.sec);
  } else {
    const lim = ctx.limits || {};
    sec = ctx.range({
      min: lim.dwell_sec_min != null ? lim.dwell_sec_min : 2,
      max: lim.dwell_sec_max != null ? lim.dwell_sec_max : 8,
    }, 4);
  }
  const ms = Math.max(0, sec * 1000);
  await ctx.sleepMs(ms);
  return { sec, ms };
}

module.exports = dwell;
