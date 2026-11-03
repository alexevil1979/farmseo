'use strict';

/**
 * Worker HB: SETEX farm:worker:{id}:hb 30
 * Node HB:   SETEX farm:node:{id}:hb 15 + capacity/load payload
 *
 * @param {import('ioredis').Redis} redis
 * @param {string} workerId
 * @param {number} [ttlSec]
 * @param {{
 *   nodeId?: number|string|null,
 *   nodeHbTtl?: number,
 *   address?: string|null,
 *   getCapacity?: () => number,
 *   getLoad?: () => number,
 * }} [opts]
 */
function createHeartbeat(redis, workerId, ttlSec = 30, opts = {}) {
  const key = `farm:worker:${workerId}:hb`;
  const nodeId = opts.nodeId != null && opts.nodeId !== '' ? Number(opts.nodeId) : null;
  const nodeHbTtl = Math.max(5, Number(opts.nodeHbTtl || 15));
  const nodeKey = nodeId != null && Number.isFinite(nodeId) ? `farm:node:${nodeId}:hb` : null;
  let timer = null;
  let stopped = false;

  async function beat() {
    if (stopped) return;
    const payload = JSON.stringify({
      pid: process.pid,
      ts: Date.now(),
      slots: process.env.WORKER_SLOTS || null,
      node_id: nodeId,
    });
    await redis.setex(key, ttlSec, payload);

    if (nodeKey) {
      const capacity = typeof opts.getCapacity === 'function'
        ? Number(opts.getCapacity()) || 0
        : Number(process.env.MAX_CHROME || process.env.WORKER_SLOTS || 40);
      const load = typeof opts.getLoad === 'function' ? Number(opts.getLoad()) || 0 : 0;
      const nodePayload = JSON.stringify({
        pid: process.pid,
        ts: Date.now(),
        capacity,
        load,
        address: opts.address || process.env.NODE_ADDRESS || null,
        worker_id: workerId,
      });
      await redis.setex(nodeKey, nodeHbTtl, nodePayload);
    }
  }

  function start(intervalMs = Math.max(5000, Math.floor(ttlSec * 1000 * 0.4))) {
    stopped = false;
    // Node HB TTL is shorter — refresh often enough for 15s expiry.
    const nodeInterval = nodeKey
      ? Math.max(3000, Math.floor(nodeHbTtl * 1000 * 0.4))
      : intervalMs;
    const tick = Math.min(intervalMs, nodeInterval);
    beat().catch(() => {});
    timer = setInterval(() => {
      beat().catch((err) => {
        console.error('[heartbeat] failed', err.message);
      });
    }, tick);
    if (typeof timer.unref === 'function') timer.unref();
    return { key, nodeKey, intervalMs: tick };
  }

  async function stop() {
    stopped = true;
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
    try {
      await redis.del(key);
    } catch (_) { /* ignore */ }
    if (nodeKey) {
      try {
        await redis.del(nodeKey);
      } catch (_) { /* ignore */ }
    }
  }

  return { start, stop, beat, key, nodeKey };
}

module.exports = { createHeartbeat };
