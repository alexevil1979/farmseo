'use strict';

/**
 * Install a deterministic Math.random when RANDOM_SEED is set.
 * Mulberry32 — same seed → same sequence across process lifetime.
 * @param {string|number|undefined|null} seedRaw
 * @returns {number|null} applied seed, or null if left native
 */
function installSeededRandom(seedRaw) {
  if (seedRaw === undefined || seedRaw === null || seedRaw === '') {
    return null;
  }
  const n = Number(seedRaw);
  if (!Number.isFinite(n)) {
    return null;
  }
  let t = (n >>> 0) || 1;
  Math.random = function seededRandom() {
    t += 0x6d2b79f5;
    let r = Math.imul(t ^ (t >>> 15), 1 | t);
    r ^= r + Math.imul(r ^ (r >>> 7), 61 | r);
    return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
  };
  return n;
}

module.exports = { installSeededRandom };
