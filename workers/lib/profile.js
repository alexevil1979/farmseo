'use strict';

const fs = require('fs');
const path = require('path');
const { sleep, rand } = require('./human');

const LOCK_NAME = '.farm.lock';
const CHROME_SINGLETON_FILES = [
  'SingletonLock',
  'SingletonCookie',
  'SingletonSocket',
  'lockfile',
];

/**
 * @param {string} profilesRoot
 * @param {number|string} accountId
 */
function profileDir(profilesRoot, accountId) {
  return path.join(profilesRoot, String(accountId));
}

/**
 * Ensure profile directory exists.
 * @param {string} dir
 */
function ensureProfileDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

/**
 * Remove Chrome Singleton* leftovers after crash / zombie.
 * @param {string} dir
 */
function cleanSingletonLocks(dir) {
  if (!fs.existsSync(dir)) return [];
  const removed = [];
  for (const name of CHROME_SINGLETON_FILES) {
    const p = path.join(dir, name);
    try {
      if (fs.existsSync(p)) {
        fs.rmSync(p, { force: true });
        removed.push(name);
      }
    } catch (_) { /* ignore */ }
  }
  // DevToolsActivePort can also block relaunch
  try {
    const dt = path.join(dir, 'DevToolsActivePort');
    if (fs.existsSync(dt)) fs.rmSync(dt, { force: true });
  } catch (_) { /* ignore */ }
  return removed;
}

/**
 * Acquire exclusive profile lock (filesystem). Prevents double Chrome on same profile.
 * @param {string} dir
 * @param {string} owner
 * @returns {{ ok: true, release: function } | { ok: false, reason: string }}
 */
function acquireProfileLock(dir, owner) {
  ensureProfileDir(dir);
  const lockPath = path.join(dir, LOCK_NAME);

  if (fs.existsSync(lockPath)) {
    try {
      const raw = fs.readFileSync(lockPath, 'utf8');
      const meta = JSON.parse(raw);
      const ageMs = Date.now() - (meta.ts || 0);
      // Stale lock > 30 min → steal after singleton cleanup
      if (ageMs < 30 * 60 * 1000 && meta.pid && isPidAlive(meta.pid)) {
        return { ok: false, reason: `profile locked by ${meta.owner || meta.pid}` };
      }
    } catch (_) {
      // corrupt lock → overwrite
    }
    try {
      fs.rmSync(lockPath, { force: true });
    } catch (_) { /* ignore */ }
  }

  cleanSingletonLocks(dir);

  const payload = JSON.stringify({
    owner,
    pid: process.pid,
    ts: Date.now(),
  });
  try {
    const fd = fs.openSync(lockPath, 'wx');
    try {
      fs.writeFileSync(fd, payload);
    } finally {
      fs.closeSync(fd);
    }
  } catch (err) {
    if (err && err.code === 'EEXIST') {
      return { ok: false, reason: 'profile lock race' };
    }
    throw err;
  }

  return {
    ok: true,
    release() {
      try {
        if (fs.existsSync(lockPath)) {
          const cur = fs.readFileSync(lockPath, 'utf8');
          const meta = JSON.parse(cur);
          if (meta.pid === process.pid && meta.owner === owner) {
            fs.rmSync(lockPath, { force: true });
          }
        }
      } catch (_) { /* ignore */ }
    },
  };
}

function isPidAlive(pid) {
  if (!pid) return false;
  try {
    process.kill(pid, 0);
    return true;
  } catch (err) {
    return err && err.code === 'EPERM';
  }
}

/**
 * Leave browser open for a random human-like cooldown, then close.
 * @param {import('puppeteer').Browser|null} browser
 * @param {{minMs?: number, maxMs?: number, skip?: boolean}} [opts]
 */
async function leaveBrowserOpenRandom(browser, opts = {}) {
  if (!browser || opts.skip) return;
  const minMs = opts.minMs ?? 3000;
  const maxMs = opts.maxMs ?? 15000;
  const wait = Math.floor(rand(minMs, maxMs));
  await sleep(wait);
  try {
    await browser.close();
  } catch (_) { /* ignore */ }
}

module.exports = {
  profileDir,
  ensureProfileDir,
  cleanSingletonLocks,
  acquireProfileLock,
  leaveBrowserOpenRandom,
  LOCK_NAME,
};
