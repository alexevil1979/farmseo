'use strict';

/**
 * Reliable list queue: BRPOPLPUSH tasks → processing + claim meta.
 * Reclaim moves orphaned processing items back to tasks when worker HB is gone.
 */

/**
 * @param {import('ioredis').Redis} redis
 * @param {ReturnType<import('./config').loadConfig>} config
 */
function createQueue(redis, config) {
  const { keys } = config;

  /**
   * @param {number} timeoutSec
   * @returns {Promise<object|null>}
   */
  async function fetchTask(timeoutSec = config.brpopTimeout) {
    const raw = await redis.brpoplpush(keys.tasks, keys.processing, timeoutSec);
    if (!raw) return null;

    let task;
    try {
      task = typeof raw === 'string' ? JSON.parse(raw) : raw;
    } catch (err) {
      await redis.lrem(keys.processing, 1, raw);
      throw new Error(`Invalid task JSON in queue: ${err.message}`);
    }

    const claimedAt = Date.now();
    const envelope = {
      ...task,
      _raw: raw,
      _claimed_at: claimedAt,
      _worker_id: config.workerId,
    };

    const taskId = task.task_id ?? task.id;
    if (taskId != null) {
      await redis.set(
        keys.claim(taskId),
        JSON.stringify({
          worker_id: config.workerId,
          claimed_at: claimedAt,
          raw,
        }),
        'EX',
        Math.max(60, Number(task.timeout || 300) + config.reclaimStaleSec),
      );
    }

    return envelope;
  }

  /**
   * Remove task from processing list after success or terminal failure.
   * @param {object} task
   */
  async function ack(task) {
    const raw = task._raw || JSON.stringify(stripInternal(task));
    await redis.lrem(keys.processing, 1, raw);
    const taskId = task.task_id ?? task.id;
    if (taskId != null) {
      await redis.del(keys.claim(taskId));
    }
  }

  /**
   * Put task back to the main queue (retry / lock busy).
   * @param {object} task
   */
  async function requeue(task) {
    const raw = task._raw || JSON.stringify(stripInternal(task));
    await redis.lrem(keys.processing, 1, raw);
    const clean = stripInternal(task);
    delete clean._raw;
    await redis.lpush(keys.tasks, typeof raw === 'string' && !task._mutated ? raw : JSON.stringify(clean));
    const taskId = task.task_id ?? task.id;
    if (taskId != null) {
      await redis.del(keys.claim(taskId));
    }
  }

  /**
   * Scan processing list; return tasks whose claimer heartbeat is dead / claim expired.
   * @returns {Promise<number>} number reclaimed
   */
  async function reclaimStale() {
    const items = await redis.lrange(keys.processing, 0, -1);
    if (!items.length) return 0;

    let reclaimed = 0;
    const now = Date.now();

    for (const raw of items) {
      let task;
      try {
        task = JSON.parse(raw);
      } catch {
        await redis.lrem(keys.processing, 1, raw);
        continue;
      }

      const taskId = task.task_id ?? task.id;
      let claim = null;
      if (taskId != null) {
        const claimRaw = await redis.get(keys.claim(taskId));
        if (claimRaw) {
          try {
            claim = JSON.parse(claimRaw);
          } catch {
            claim = null;
          }
        }
      }

      const workerId = claim?.worker_id || task._worker_id;
      const claimedAt = claim?.claimed_at || task._claimed_at || 0;
      const ageSec = claimedAt ? (now - claimedAt) / 1000 : Infinity;

      let alive = false;
      if (workerId) {
        const hb = await redis.get(keys.workerHb(workerId));
        alive = Boolean(hb);
      }

      const stale = !alive && ageSec >= config.reclaimStaleSec;
      if (!stale) continue;

      const moved = await redis.lrem(keys.processing, 1, raw);
      if (moved > 0) {
        const clean = stripInternal(task);
        await redis.lpush(keys.tasks, JSON.stringify(clean));
        if (taskId != null) await redis.del(keys.claim(taskId));
        reclaimed += 1;
      }
    }

    return reclaimed;
  }

  /**
   * Account lock: SET NX EX farm:lock:acc:{id}
   * @param {number|string} accountId
   * @param {string} token
   * @param {number} [ttl]
   * @returns {Promise<boolean>}
   */
  async function tryAccountLock(accountId, token, ttl = config.accountLockTtl) {
    const r = await redis.set(keys.accountLock(accountId), token, 'EX', ttl, 'NX');
    return r === 'OK';
  }

  /**
   * @param {number|string} accountId
   * @param {string} token
   */
  async function releaseAccountLock(accountId, token) {
    const script = `
      if redis.call("get", KEYS[1]) == ARGV[1] then
        return redis.call("del", KEYS[1])
      end
      return 0
    `;
    await redis.eval(script, 1, keys.accountLock(accountId), token);
  }

  /**
   * Proxy lock: SET NX EX farm:lock:pxy:{id}
   * @param {number|string} proxyId
   * @param {string} token
   * @param {number} [ttl]
   */
  async function tryProxyLock(proxyId, token, ttl = config.proxyLockTtl || 120) {
    if (proxyId == null) return true;
    const r = await redis.set(keys.proxyLock(proxyId), token, 'EX', ttl, 'NX');
    return r === 'OK';
  }

  /**
   * @param {number|string} proxyId
   * @param {string} token
   */
  async function releaseProxyLock(proxyId, token) {
    if (proxyId == null) return;
    const script = `
      if redis.call("get", KEYS[1]) == ARGV[1] then
        return redis.call("del", KEYS[1])
      end
      return 0
    `;
    await redis.eval(script, 1, keys.proxyLock(proxyId), token);
  }

  return {
    fetchTask,
    ack,
    requeue,
    reclaimStale,
    tryAccountLock,
    releaseAccountLock,
    tryProxyLock,
    releaseProxyLock,
  };
}

function stripInternal(task) {
  const out = { ...task };
  delete out._raw;
  delete out._claimed_at;
  delete out._worker_id;
  delete out._mutated;
  return out;
}

module.exports = { createQueue };
