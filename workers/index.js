'use strict';

const crypto = require('crypto');
const { loadConfig } = require('./lib/config');
const { installSeededRandom } = require('./lib/seed-random');
const { createRedis } = require('./lib/redis');
const { createQueue } = require('./lib/queue');
const { createHeartbeat } = require('./lib/heartbeat');
const { createReporter } = require('./lib/reporter');
const { createHttpServer } = require('./lib/http');
const { createDisplayManager } = require('./lib/display');
const { launchBrowser, maybeApplyFingerprint } = require('./lib/browser');
const { leaveBrowserOpenRandom } = require('./lib/profile');
const human = require('./lib/human');
const { execute } = require('./lib/executor');
const { samplePid, sampleChromeList } = require('./lib/procstats');
const fs = require('fs');
const path = require('path');

async function main() {
  const config = loadConfig();
  const seed = installSeededRandom(process.env.RANDOM_SEED);
  if (seed !== null) {
    console.log(`[worker] RANDOM_SEED=${seed} (deterministic Math.random)`);
  }
  const redis = createRedis(config.redis);
  const queue = createQueue(redis, config);
  const reporter = createReporter({ config, redis });
  const displays = createDisplayManager(config);

  /** @type {Map<number, { browser: import('puppeteer').Browser, releaseLock: Function, interactive?: boolean, pid?: number|null, task_id?: number|null }>} */
  const sessions = new Map();
  /** @type {Map<number, { account_id: number, pid: number|null, task_id: number|null }>} */
  const chromeProcs = new Map();
  let chromeCount = 0;
  let shuttingDown = false;
  let activeJobs = 0;

  const heartbeat = createHeartbeat(redis, config.workerId, config.heartbeatTtl, {
    nodeId: config.nodeId,
    nodeHbTtl: config.nodeHeartbeatTtl,
    address: config.nodeAddress,
    getCapacity: () => config.maxChrome,
    getLoad: () => chromeCount,
  });

  const state = {
    get workers() {
      return config.slots;
    },
    get chrome() {
      return chromeCount;
    },
    get shuttingDown() {
      return shuttingDown;
    },
  };

  async function publishChromeSessions() {
    const entries = [];
    for (const [accountId, meta] of chromeProcs) {
      entries.push({
        account_id: accountId,
        pid: meta.pid,
        task_id: meta.task_id,
        worker_id: config.workerId,
      });
    }
    for (const [accountId, s] of sessions) {
      if (!chromeProcs.has(accountId)) {
        entries.push({
          account_id: accountId,
          pid: s.pid ?? null,
          task_id: s.task_id ?? null,
          worker_id: config.workerId,
        });
      }
    }
    const sampled = sampleChromeList(entries);
    try {
      await redis.set('farm:cfg:chrome_sessions', JSON.stringify(sampled), 'EX', 45);
    } catch (_) { /* ignore */ }
    return sampled;
  }

  const chromeStatsTimer = setInterval(() => {
    publishChromeSessions().catch(() => {});
  }, 10000);
  if (typeof chromeStatsTimer.unref === 'function') chromeStatsTimer.unref();

  const http = createHttpServer({
    config,
    displays,
    getStats: () => ({
      workers: state.workers,
      chrome: state.chrome,
      shuttingDown: state.shuttingDown,
      chrome_sessions: sampleChromeList(
        [...chromeProcs.entries()].map(([account_id, meta]) => ({
          account_id,
          pid: meta.pid,
          task_id: meta.task_id,
          worker_id: config.workerId,
        })),
      ),
    }),
    async onSessionStart(accountId) {
      const disp = await displays.start(accountId);
      try {
        if (!sessions.has(accountId)) {
          const launched = await launchBrowser({
            accountId,
            profilesRoot: config.storage.profiles,
            headless: false,
            display: disp.display,
            executablePath: config.chromePath,
            extraArgs: config.chromeExtraArgs || [],
            lockOwner: `${config.workerId}:live${accountId}`,
          });
          sessions.set(accountId, {
            browser: launched.browser,
            releaseLock: launched.releaseLock,
            interactive: true,
          });
          chromeCount += 1;
          launched.browser.once('disconnected', () => {
            if (sessions.get(accountId)?.browser === launched.browser) {
              sessions.delete(accountId);
              chromeCount = Math.max(0, chromeCount - 1);
            }
          });
        }
      } catch (err) {
        await displays.stop(accountId).catch(() => {});
        throw err;
      }
      return {
        vnc_port: disp.vnc_port,
        ws_port: disp.ws_port,
        display: disp.display,
        novnc_path: disp.novnc_path,
      };
    },
    async onSessionStop(accountId) {
      const s = sessions.get(accountId);
      if (s) {
        try {
          await s.browser.close();
        } catch (_) { /* ignore */ }
        try {
          s.releaseLock();
        } catch (_) { /* ignore */ }
        sessions.delete(accountId);
        chromeCount = Math.max(0, chromeCount - 1);
      }
      await displays.stop(accountId);
    },
    async onScreenshot(accountId) {
      return takeAccountScreenshot(accountId, {
        sessions,
        config,
        chromeCountRef: {
          get: () => chromeCount,
          set: (n) => { chromeCount = n; },
        },
      });
    },
  });

  await redis.ping();
  heartbeat.start();
  await http.listen();
  console.log(
    `[worker] id=${config.workerId} node=${config.nodeId ?? '-'} slots=${config.slots} http=:${config.httpPort} redis=${config.redis.host}:${config.redis.port}`,
  );

  const reclaimTimer = setInterval(() => {
    queue.reclaimStale()
      .then((n) => {
        if (n > 0) console.warn(`[reclaim] returned ${n} task(s) to farm:q:tasks`);
      })
      .catch((err) => console.error('[reclaim]', err.message));
  }, config.reclaimIntervalMs);
  if (typeof reclaimTimer.unref === 'function') reclaimTimer.unref();

  // Immediate reclaim on boot (crash recovery)
  queue.reclaimStale().catch(() => {});

  const slotPromises = [];
  for (let i = 0; i < config.slots; i++) {
    slotPromises.push(slotLoop(i));
  }

  async function slotLoop(slotIndex) {
    while (!shuttingDown) {
      let task = null;
      try {
        task = await queue.fetchTask(config.brpopTimeout);
      } catch (err) {
        if (shuttingDown) break;
        console.error(`[slot ${slotIndex}] fetch error`, err.message);
        await human.sleep(1000);
        continue;
      }
      if (!task) continue;
      if (shuttingDown) {
        await queue.requeue(task).catch(() => {});
        break;
      }

      activeJobs += 1;
      try {
        await handleTask(task, slotIndex);
      } catch (err) {
        console.error(`[slot ${slotIndex}] unhandled`, err);
      } finally {
        activeJobs -= 1;
      }
    }
  }

  /**
   * @param {object} task
   * @param {number} slotIndex
   */
  async function handleTask(task, slotIndex) {
    const taskId = task.task_id ?? task.id;
    const accountId = task.account_id ?? task.payload?.account_id;
    const lockToken = crypto.randomBytes(16).toString('hex');
    let accountLocked = false;
    let proxyLocked = false;
    let proxyId = null;
    let browser = null;
    let releaseLock = null;
    let keepOpen = true;
    let chromePid = null;
    let displayStarted = false;

    if (accountId == null) {
      await failAndAck(task, 'account_id missing');
      return;
    }

    // Sticky node: never run an account pinned to another node (no remigration here).
    const taskNodeId = task.node_id ?? task.assigned_node_id ?? null;
    if (taskNodeId != null) {
      if (config.nodeId == null || Number(config.nodeId) !== Number(taskNodeId)) {
        console.log(
          `[slot ${slotIndex}] task ${taskId} node=${taskNodeId} not local=${config.nodeId ?? '-'} — requeue`,
        );
        await human.sleep(human.rand(200, 800));
        await queue.requeue(task);
        return;
      }
    }

    const gotLock = await queue.tryAccountLock(accountId, lockToken, config.accountLockTtl);
    if (!gotLock) {
      console.log(`[slot ${slotIndex}] account ${accountId} locked — requeue task ${taskId}`);
      await human.sleep(human.rand(500, 1500));
      await queue.requeue(task);
      return;
    }
    accountLocked = true;

    const proxy = extractProxy(task);
    proxyId = task.proxy_id ?? proxy?.id ?? null;
    if (proxyId != null) {
      const gotPxy = await queue.tryProxyLock(proxyId, lockToken, config.proxyLockTtl);
      if (!gotPxy) {
        console.log(`[slot ${slotIndex}] proxy ${proxyId} locked — requeue task ${taskId}`);
        await human.sleep(human.rand(500, 1500));
        await queue.releaseAccountLock(accountId, lockToken).catch(() => {});
        accountLocked = false;
        await queue.requeue(task);
        return;
      }
      proxyLocked = true;
    }

    const started = Date.now();
    try {
      // CONTRACT §12.2: mark running + task_runs before Chrome work
      const attempt = Math.max(1, Number(task.attempts || 0) + 1);
      await reporter.report({
        task_id: taskId,
        status: 'running',
        error: null,
        metrics: {
          worker_id: config.workerId,
          slot: slotIndex,
        },
        screenshots: [],
        attempt,
      });

      // Per-task Xvfb stack (DISPLAY_BASE+N). Ambient :99 alone is not enough —
      // slot loops used to pass displayBase+slot without starting Xvfb.
      const disp = await displays.start(accountId);
      displayStarted = true;

      chromeCount += 1;
      const launched = await launchBrowser({
        accountId,
        profilesRoot: config.storage.profiles,
        headless: config.chromeHeadless,
        display: disp.display,
        proxy,
        executablePath: config.chromePath,
        extraArgs: config.chromeExtraArgs || [],
        lockOwner: `${config.workerId}:slot${slotIndex}`,
      });
      browser = launched.browser;
      releaseLock = launched.releaseLock;
      const { page } = launched;

      try {
        const proc = browser.process?.();
        chromePid = proc?.pid ?? null;
      } catch (_) {
        chromePid = null;
      }
      chromeProcs.set(Number(accountId), {
        account_id: Number(accountId),
        pid: chromePid,
        task_id: Number(taskId),
      });
      await publishChromeSessions();

      await maybeApplyFingerprint(page, task.fingerprint || task.payload?.fingerprint);

      const result = await withTimeout(
        execute({
          task,
          browser,
          page,
          reporter,
          config,
          human,
          slotIndex,
        }),
        Number(task.timeout || 300) * 1000,
      );

      const chromeStats = samplePid(chromePid);
      await reporter.report({
        task_id: taskId,
        status: result.status || 'done',
        error: result.error ?? null,
        metrics: {
          ...(result.metrics || {}),
          worker_id: config.workerId,
          slot: slotIndex,
          duration_ms: Date.now() - started,
          chrome_cpu_pct: chromeStats.cpu_pct,
          chrome_ram_mb: chromeStats.ram_mb,
        },
        screenshots: result.screenshots || [],
        events: result.events || [],
        attempt,
      });

      await queue.ack(task);
      console.log(`[slot ${slotIndex}] task ${taskId} → ${result.status || 'done'}`);
    } catch (err) {
      keepOpen = false;
      console.error(`[slot ${slotIndex}] task ${taskId} error:`, err.message);

      if (err.code === 'PROFILE_LOCKED') {
        await queue.requeue(task);
      } else {
        // Report failed; PHP RetryPolicy decides retrying vs terminal failed
        const chromeStats = samplePid(chromePid);
        await reporter.report({
          task_id: taskId,
          status: 'failed',
          error: err.message,
          metrics: {
            worker_id: config.workerId,
            slot: slotIndex,
            duration_ms: Date.now() - started,
            chrome_cpu_pct: chromeStats.cpu_pct,
            chrome_ram_mb: chromeStats.ram_mb,
          },
          screenshots: [],
        }).catch(() => {});
        await queue.ack(task);
      }
    } finally {
      chromeProcs.delete(Number(accountId));
      publishChromeSessions().catch(() => {});
      if (browser) {
        try {
          if (keepOpen && !shuttingDown) {
            await leaveBrowserOpenRandom(browser, {
              minMs: config.keepOpenMinMs,
              maxMs: config.keepOpenMaxMs,
            });
          } else {
            await browser.close();
          }
        } catch (_) { /* ignore */ }
        chromeCount = Math.max(0, chromeCount - 1);
      }
      if (typeof releaseLock === 'function') {
        try {
          releaseLock();
        } catch (_) { /* ignore */ }
      }
      if (displayStarted) {
        await displays.stop(accountId).catch(() => {});
      }
      if (proxyLocked && proxyId != null) {
        await queue.releaseProxyLock(proxyId, lockToken).catch(() => {});
      }
      if (accountLocked) {
        await queue.releaseAccountLock(accountId, lockToken).catch(() => {});
      }
    }
  }

  async function failAndAck(task, message) {
    const taskId = task.task_id ?? task.id;
    await reporter.report({
      task_id: taskId,
      status: 'failed',
      error: message,
      metrics: {},
      screenshots: [],
    }).catch(() => {});
    await queue.ack(task);
  }

  async function shutdown(signal) {
    if (shuttingDown) return;
    shuttingDown = true;
    console.log(`[worker] ${signal} — graceful shutdown`);
    clearInterval(reclaimTimer);

    const deadline = Date.now() + 20000;
    while (activeJobs > 0 && Date.now() < deadline) {
      await human.sleep(200);
    }

    await heartbeat.stop();
    await http.close();

    for (const [, s] of sessions) {
      try {
        await s.browser.close();
      } catch (_) { /* ignore */ }
      try {
        s.releaseLock();
      } catch (_) { /* ignore */ }
    }
    sessions.clear();
    await displays.releaseAll().catch(() => {});

    try {
      await redis.quit();
    } catch (_) {
      redis.disconnect();
    }
    console.log('[worker] bye');
    process.exit(0);
  }

  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));

  await Promise.all(slotPromises);
  await shutdown('slots-done');
}

function extractProxy(task) {
  const p = task.proxy || task.payload?.proxy;
  if (!p || !p.host || !p.port) return null;
  return {
    host: p.host,
    port: p.port,
    protocol: p.protocol || 'http',
    username: p.username || p.user || undefined,
    password: p.password || p.pass || undefined,
  };
}

/**
 * Take a screenshot for an account without requiring live-view.
 * Reuses an open interactive session browser when present; otherwise
 * launches a short-lived headless Chrome against the profile.
 *
 * @param {number} accountId
 * @param {object} deps
 */
async function takeAccountScreenshot(accountId, deps) {
  const { sessions, config, chromeCountRef } = deps;
  const existing = sessions.get(accountId);
  let browser = existing?.browser || null;
  let temporary = false;
  let releaseLock = null;

  if (!browser) {
    const launched = await launchBrowser({
      accountId,
      profilesRoot: config.storage.profiles,
      headless: true,
      executablePath: config.chromePath,
      extraArgs: config.chromeExtraArgs || [],
      lockOwner: `${config.workerId}:shot${accountId}`,
    });
    browser = launched.browser;
    releaseLock = launched.releaseLock;
    temporary = true;
    chromeCountRef.set(chromeCountRef.get() + 1);
  }

  try {
    const pages = await browser.pages();
    const page = pages[0] || await browser.newPage();
    const dir = config.storage.screenshots;
    fs.mkdirSync(dir, { recursive: true });
    const filename = `account_${accountId}_${Date.now()}.png`;
    const abs = path.join(dir, filename);
    await page.screenshot({ path: abs, fullPage: false, timeout: 15000 });
    return {
      path: `storage/screenshots/${filename}`,
      filename,
      mtime: Math.floor(Date.now() / 1000),
    };
  } finally {
    if (temporary && browser) {
      try {
        await browser.close();
      } catch (_) { /* ignore */ }
      if (typeof releaseLock === 'function') {
        try { releaseLock(); } catch (_) { /* ignore */ }
      }
      chromeCountRef.set(Math.max(0, chromeCountRef.get() - 1));
    }
  }
}

function withTimeout(promise, ms) {
  if (!ms || ms <= 0) return promise;
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => {
      const err = new Error(`task timeout after ${ms}ms`);
      err.code = 'TASK_TIMEOUT';
      reject(err);
    }, ms);
    promise.then(
      (v) => {
        clearTimeout(t);
        resolve(v);
      },
      (e) => {
        clearTimeout(t);
        reject(e);
      },
    );
  });
}

main().catch((err) => {
  console.error('[worker] fatal', err);
  process.exit(1);
});
