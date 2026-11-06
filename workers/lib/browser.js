'use strict';

const fs = require('fs');
const path = require('path');
const { getPuppeteer, applyCustomStealthPatches } = require('./stealth');
const {
  profileDir,
  ensureProfileDir,
  cleanSingletonLocks,
  acquireProfileLock,
} = require('./profile');

/**
 * @typedef {object} ProxyConfig
 * @property {string} host
 * @property {number|string} port
 * @property {string} [protocol]
 * @property {string} [username]
 * @property {string} [password]
 */

/**
 * @typedef {object} LaunchOptions
 * @property {number|string} accountId
 * @property {string} profilesRoot
 * @property {boolean} [headless]
 * @property {number} [display]  Xvfb display number (N → DISPLAY=:N)
 * @property {ProxyConfig|null} [proxy]
 * @property {string} [executablePath]
 * @property {string[]} [extraArgs]
 * @property {string} [lockOwner]
 */

/**
 * Build Chrome args for an isolated, stealth-friendly profile.
 * @param {object} opts
 */
function buildChromeArgs(opts) {
  const userDataDir = opts.userDataDir;
  const args = [
    `--user-data-dir=${userDataDir}`,
    '--disable-blink-features=AutomationControlled',
    '--force-webrtc-ip-handling-policy=default_public_interface_only',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-background-networking',
    '--disable-features=IsolateOrigins,site-per-process,TranslateUI',
    '--disable-dev-shm-usage',
    '--disable-ipc-flooding-protection',
    '--password-store=basic',
    '--use-mock-keychain',
    '--lang=en-US,en',
  ];

  if (opts.proxyServer) {
    args.push(`--proxy-server=${opts.proxyServer}`);
  }

  if (Array.isArray(opts.extraArgs)) {
    args.push(...opts.extraArgs);
  }

  return args;
}

/**
 * Start Xvfb-aware headful Chrome with persistent profile + optional proxy auth via CDP.
 * @param {LaunchOptions} options
 */
async function launchBrowser(options) {
  const {
    accountId,
    profilesRoot,
    headless = false,
    display,
    proxy = null,
    executablePath,
    extraArgs = [],
    lockOwner = `pid-${process.pid}`,
  } = options;

  const dir = ensureProfileDir(profileDir(profilesRoot, accountId));
  cleanSingletonLocks(dir);

  const lock = acquireProfileLock(dir, lockOwner);
  if (!lock.ok) {
    const err = new Error(lock.reason || 'profile lock failed');
    err.code = 'PROFILE_LOCKED';
    throw err;
  }

  const proxyServer = proxy
    ? `${proxy.protocol || 'http'}://${proxy.host}:${proxy.port}`
    : null;

  const args = buildChromeArgs({
    userDataDir: dir,
    proxyServer,
    extraArgs,
  });

  const env = { ...process.env };
  if (!headless && display != null && process.platform !== 'win32') {
    env.DISPLAY = `:${display}`;
  }

  const puppeteer = getPuppeteer();
  let browser;
  try {
    browser = await puppeteer.launch({
      headless: headless ? 'new' : false,
      executablePath: executablePath || undefined,
      userDataDir: dir,
      args,
      ignoreDefaultArgs: ['--enable-automation'],
      defaultViewport: null,
      env,
      dumpio: false,
    });
  } catch (err) {
    lock.release();
    throw err;
  }

  browser.once('disconnected', () => {
    try {
      lock.release();
    } catch (_) { /* ignore */ }
  });

  const pages = await browser.pages();
  const page = pages[0] || await browser.newPage();
  await applyCustomStealthPatches(page);

  if (proxy && proxy.username) {
    await setupProxyAuth(page, browser, proxy);
  }

  return {
    browser,
    page,
    profilePath: dir,
    releaseLock: () => lock.release(),
  };
}

/**
 * Proxy credentials via CDP Fetch auth + page.authenticate fallback.
 * @param {import('puppeteer').Page} page
 * @param {import('puppeteer').Browser} browser
 * @param {ProxyConfig} proxy
 */
async function setupProxyAuth(page, browser, proxy) {
  await page.authenticate({
    username: proxy.username || '',
    password: proxy.password || '',
  });

  // Fetch CDP interception frequently triggers "detached Frame" during navigations.
  // Opt-in only: PROXY_FETCH_AUTH=1. page.authenticate covers most HTTP proxies.
  if (process.env.PROXY_FETCH_AUTH === '1' || process.env.PROXY_FETCH_AUTH === 'true') {
    try {
      const client = await page.createCDPSession();
      await client.send('Fetch.enable', {
        handleAuthRequests: true,
        patterns: [{ urlPattern: '*' }],
      });
      client.on('Fetch.authRequired', async (event) => {
        try {
          await client.send('Fetch.continueWithAuth', {
            requestId: event.requestId,
            authChallengeResponse: {
              response: 'ProvideCredentials',
              username: proxy.username || '',
              password: proxy.password || '',
            },
          });
        } catch (_) { /* ignore */ }
      });
      client.on('Fetch.requestPaused', async (event) => {
        try {
          await client.send('Fetch.continueRequest', { requestId: event.requestId });
        } catch (_) { /* ignore */ }
      });
    } catch (_) {
      // page.authenticate is enough for most HTTP proxies
    }
  }

  browser.on('targetcreated', async (target) => {
    try {
      const p = await target.page();
      if (!p) return;
      await p.authenticate({
        username: proxy.username || '',
        password: proxy.password || '',
      });
    } catch (_) { /* ignore */ }
  });
}

/**
 * Apply optional fingerprint module if present.
 * @param {import('puppeteer').Page} page
 * @param {object} [fp]
 */
async function maybeApplyFingerprint(page, fp) {
  if (!fp) return;
  const applyPath = path.join(__dirname, '..', 'fingerprint', 'apply.js');
  if (!fs.existsSync(applyPath)) return;
  try {
    const { applyFingerprint } = require(applyPath);
    await applyFingerprint(page, fp);
  } catch (_) { /* fingerprint optional at worker layer */ }
}

module.exports = {
  launchBrowser,
  buildChromeArgs,
  setupProxyAuth,
  maybeApplyFingerprint,
};
