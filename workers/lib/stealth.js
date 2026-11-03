'use strict';

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

const stealth = StealthPlugin();
puppeteer.use(stealth);

/**
 * Extra navigator / chrome patches on top of puppeteer-extra-plugin-stealth.
 * @param {import('puppeteer').Page} page
 */
async function applyCustomStealthPatches(page) {
  await page.evaluateOnNewDocument(() => {
    try {
      Object.defineProperty(navigator, 'webdriver', {
        get: () => undefined,
        configurable: true,
      });
    } catch (_) { /* ignore */ }

    try {
      // Chrome runtime stub
      window.chrome = window.chrome || {};
      window.chrome.runtime = window.chrome.runtime || {
        connect: () => ({}),
        sendMessage: () => ({}),
      };
    } catch (_) { /* ignore */ }

    try {
      const originalQuery = window.navigator.permissions?.query?.bind(window.navigator.permissions);
      if (originalQuery) {
        window.navigator.permissions.query = (parameters) => {
          if (parameters && parameters.name === 'notifications') {
            return Promise.resolve({ state: Notification.permission });
          }
          return originalQuery(parameters);
        };
      }
    } catch (_) { /* ignore */ }

    try {
      Object.defineProperty(navigator, 'plugins', {
        get: () => [
          { name: 'Chrome PDF Plugin', filename: 'internal-pdf-viewer', description: 'Portable Document Format' },
          { name: 'Chrome PDF Viewer', filename: 'mhjfbmdgcfjbbpaeojofohoefgiehjai', description: '' },
          { name: 'Native Client', filename: 'internal-nacl-plugin', description: '' },
        ],
        configurable: true,
      });
    } catch (_) { /* ignore */ }

    try {
      Object.defineProperty(navigator, 'languages', {
        get: () => navigator.language ? [navigator.language, 'en-US', 'en'] : ['en-US', 'en'],
        configurable: true,
      });
    } catch (_) { /* ignore */ }

    try {
      // Hide headless leftovers
      const proto = WebGLRenderingContext?.prototype;
      if (proto) {
        const getParameter = proto.getParameter;
        proto.getParameter = function patchedGetParameter(param) {
          // UNMASKED_VENDOR_WEBGL / RENDERER left to fingerprint module when applied
          return getParameter.call(this, param);
        };
      }
    } catch (_) { /* ignore */ }
  });
}

/**
 * Launch-ready puppeteer-extra instance.
 */
function getPuppeteer() {
  return puppeteer;
}

module.exports = {
  getPuppeteer,
  applyCustomStealthPatches,
};
