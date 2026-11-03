'use strict';

const path = require('path');
const fs = require('fs');
const dotenv = require('dotenv');

const ROOT = path.resolve(__dirname, '..', '..');

function loadEnv() {
  const envPath = path.join(ROOT, '.env');
  if (fs.existsSync(envPath)) {
    dotenv.config({ path: envPath });
  }
  dotenv.config();
}

function bool(v, fallback = false) {
  if (v === undefined || v === null || v === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(v).toLowerCase());
}

function int(v, fallback) {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isFinite(n) ? n : fallback;
}

function loadConfig() {
  loadEnv();

  const slots = Math.max(1, int(process.env.WORKER_SLOTS, int(process.env.MAX_CHROME, 4)));
  const workerId =
    process.env.WORKER_ID
    || `w-${require('os').hostname()}-${process.pid}`;
  const nodeIdRaw = process.env.NODE_ID;
  const nodeId = nodeIdRaw !== undefined && nodeIdRaw !== '' ? int(nodeIdRaw, 0) || null : null;
  const httpPort = int(process.env.WORKER_HTTP_PORT, 3000);
  const nodeAddress =
    process.env.NODE_ADDRESS
    || process.env.WORKER_URL
    || `http://127.0.0.1:${httpPort}`;

  return {
    root: ROOT,
    workerId,
    nodeId,
    nodeAddress,
    slots,
    redis: {
      host: process.env.REDIS_HOST || '127.0.0.1',
      port: int(process.env.REDIS_PORT, 6379),
      password: process.env.REDIS_PASSWORD || undefined,
      db: int(process.env.REDIS_DB, 0),
    },
    httpPort,
    internalToken: process.env.INTERNAL_TOKEN || 'change-me',
    apiUrl: (process.env.API_URL || 'http://127.0.0.1').replace(/\/$/, ''),
    resultUrl:
      process.env.RESULT_URL
      || `${(process.env.API_URL || 'http://127.0.0.1').replace(/\/$/, '')}/internal/task/result`,
    maxChrome: int(process.env.MAX_CHROME, slots),
    chromeHeadless: bool(process.env.CHROME_HEADLESS, false),
    chromePath: process.env.CHROME_PATH || process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
    // Space/comma-separated Chrome flags (e.g. Docker: --no-sandbox --disable-setuid-sandbox)
    chromeExtraArgs: String(process.env.CHROME_EXTRA_ARGS || '')
      .split(/[\s,]+/)
      .map((s) => s.trim())
      .filter(Boolean),
    vncBasePort: int(process.env.VNC_BASE_PORT, 5900),
    displayBase: int(process.env.DISPLAY_BASE, 99),
    websockifyBasePort: int(process.env.WEBSOCKIFY_BASE_PORT, 6080),
    xvfbScreen: process.env.XVFB_SCREEN || '1920x1080x24',
    novncWebPath: process.env.NOVNC_WEB_PATH || undefined,
    displayDryRun: bool(process.env.DISPLAY_DRY_RUN, false),
    heartbeatTtl: 30,
    nodeHeartbeatTtl: 15,
    brpopTimeout: int(process.env.QUEUE_BRPOP_TIMEOUT, 5),
    reclaimIntervalMs: int(process.env.RECLAIM_INTERVAL_MS, 15000),
    reclaimStaleSec: int(process.env.RECLAIM_STALE_SEC, 45),
    accountLockTtl: int(process.env.ACCOUNT_LOCK_TTL, 600),
    proxyLockTtl: int(process.env.PROXY_LOCK_TTL, 120),
    keepOpenMinMs: int(process.env.KEEP_OPEN_MIN_MS, 3000),
    keepOpenMaxMs: int(process.env.KEEP_OPEN_MAX_MS, 15000),
    captchaKey: process.env.CAPTCHA_KEY || '',
    captchaProvider: process.env.CAPTCHA_PROVIDER || '2captcha',
    captchaTokenUrl: process.env.CAPTCHA_TOKEN_URL || '',
    storage: {
      profiles: path.join(ROOT, 'storage', 'profiles'),
      screenshots: path.join(ROOT, 'storage', 'screenshots'),
      logs: path.join(ROOT, 'storage', 'logs'),
      cookies: path.join(ROOT, 'storage', 'cookies'),
    },
    keys: {
      tasks: 'farm:q:tasks',
      processing: 'farm:q:processing',
      results: 'farm:q:results',
      workerHb: (id) => `farm:worker:${id}:hb`,
      nodeHb: (id) => `farm:node:${id}:hb`,
      accountLock: (id) => `farm:lock:acc:${id}`,
      proxyLock: (id) => `farm:lock:pxy:${id}`,
      claim: (taskId) => `farm:claim:${taskId}`,
    },
  };
}

module.exports = { loadConfig, ROOT };
