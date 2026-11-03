'use strict';

/**
 * Randomization helpers for scenario steps (min/max ranges, weights).
 */

function rand(min, max) {
  const a = Number(min);
  const b = Number(max);
  if (!Number.isFinite(a) && !Number.isFinite(b)) return 0;
  if (!Number.isFinite(b)) return a;
  if (!Number.isFinite(a)) return b;
  if (a === b) return a;
  return a + Math.random() * (b - a);
}

function randInt(min, max) {
  const a = Math.ceil(Number(min));
  const b = Math.floor(Number(max));
  if (!Number.isFinite(a) && !Number.isFinite(b)) return 0;
  if (!Number.isFinite(b)) return a;
  if (!Number.isFinite(a)) return b;
  if (a >= b) return a;
  return Math.floor(rand(a, b + 1));
}

/**
 * Resolve a numeric value from step fields.
 * Prefer min/max range; else value/n/ms/sec/duration/times/position.
 * @param {object} step
 * @param {object} [opts]
 * @param {boolean} [opts.preferMin]  fast mode — always use lower bound
 * @param {number} [opts.fallback]
 */
function resolveRange(step, opts = {}) {
  const fallback = opts.fallback != null ? opts.fallback : 0;
  if (step == null) return fallback;

  if (typeof step === 'number') return step;

  const min = step.min != null ? Number(step.min) : null;
  const max = step.max != null ? Number(step.max) : null;

  if (min != null && max != null) {
    if (opts.preferMin) return min;
    return Number.isInteger(min) && Number.isInteger(max)
      ? randInt(min, max)
      : rand(min, max);
  }
  if (min != null) return min;
  if (max != null) return max;

  for (const key of ['value', 'n', 'ms', 'sec', 'duration', 'times', 'position', 'count']) {
    if (step[key] != null && Number.isFinite(Number(step[key]))) {
      return Number(step[key]);
    }
  }
  return fallback;
}

/**
 * Probability gate. weight omitted → always run. weight 0..1.
 * @param {object} step
 * @returns {boolean} true = execute
 */
function shouldRun(step) {
  if (step == null || step.weight == null) return true;
  const w = Number(step.weight);
  if (!Number.isFinite(w)) return true;
  if (w <= 0) return false;
  if (w >= 1) return true;
  return Math.random() < w;
}

/**
 * Pick one item from weighted list [{item, weight}|any].
 * @param {unknown[]} items
 */
function pickWeighted(items) {
  if (!Array.isArray(items) || items.length === 0) return null;
  const normalized = items.map((it) => {
    if (it && typeof it === 'object' && 'weight' in it) {
      return { value: it.value !== undefined ? it.value : it, weight: Number(it.weight) || 1 };
    }
    return { value: it, weight: 1 };
  });
  const total = normalized.reduce((s, x) => s + x.weight, 0);
  let r = Math.random() * total;
  for (const x of normalized) {
    r -= x.weight;
    if (r <= 0) return x.value;
  }
  return normalized[normalized.length - 1].value;
}

function pickOne(arr) {
  if (!Array.isArray(arr) || arr.length === 0) return null;
  return arr[randInt(0, arr.length - 1)];
}

module.exports = {
  rand,
  randInt,
  resolveRange,
  shouldRun,
  pickWeighted,
  pickOne,
};
