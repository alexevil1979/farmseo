'use strict';

const Redis = require('ioredis');

/**
 * @param {object} cfg
 * @returns {import('ioredis').Redis}
 */
function createRedis(cfg) {
  const opts = {
    host: cfg.host,
    port: cfg.port,
    db: cfg.db || 0,
    maxRetriesPerRequest: null,
    enableReadyCheck: true,
    lazyConnect: Boolean(cfg.lazyConnect),
    retryStrategy: cfg.retryStrategy || ((times) => Math.min(times * 200, 2000)),
  };
  if (cfg.password) opts.password = cfg.password;
  const client = new Redis(opts);
  // Prevent unhandled 'error' from crashing the process when Redis is optional/down
  client.on('error', (err) => {
    if (process.env.REDIS_DEBUG) console.warn('[redis]', err.message);
  });
  return client;
}

module.exports = { createRedis };
