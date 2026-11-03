'use strict';

/**
 * Apply a coherent fingerprint to a Puppeteer page via CDP + evaluateOnNewDocument.
 *
 * Usage:
 *   const { applyFingerprint, verifyFingerprint } = require('./apply');
 *   await applyFingerprint(page, fp);
 *   const observed = await verifyFingerprint(page);
 *
 * @module workers/fingerprint/apply
 */

/**
 * @typedef {object} Fingerprint
 * @property {string} ua
 * @property {string} platform
 * @property {{width:number,height:number,availWidth?:number,availHeight?:number}} screen
 * @property {number} dpr
 * @property {string} timezone
 * @property {string} lang
 * @property {string[]} [languages]
 * @property {string} [accept_language]
 * @property {string[]} fonts
 * @property {string} webgl_vendor
 * @property {string} webgl_renderer
 * @property {string} canvas_hash_seed
 * @property {string} audio_seed
 * @property {number} hardwareConcurrency
 * @property {number} deviceMemory
 * @property {Array<object>} plugins
 * @property {{noise?:number,seed?:string}} clientRects
 * @property {{maxTouchPoints:number,touchEvent:boolean,touchStart:boolean}} touch
 * @property {Array<object>} mediaDevices
 * @property {{latitude:number,longitude:number,accuracy?:number}} [geolocation]
 * @property {string} [profile]
 * @property {string} [os]
 */

/**
 * Apply fingerprint overrides before any document scripts run.
 *
 * @param {import('puppeteer').Page} page
 * @param {Fingerprint} fp
 * @returns {Promise<void>}
 */
async function applyFingerprint(page, fp) {
  assertFp(fp);

  const client = typeof page.createCDPSession === 'function'
    ? await page.createCDPSession()
    : await page.target().createCDPSession();

  const screen = fp.screen;
  const width = Number(screen.width);
  const height = Number(screen.height);
  const dpr = Number(fp.dpr) || 1;
  const mobile = fp.profile === 'mobile'
    || /Mobile|Android|iPhone|iPad/.test(fp.ua);

  await client.send('Emulation.setUserAgentOverride', {
    userAgent: fp.ua,
    platform: fp.platform,
    acceptLanguage: fp.accept_language || fp.lang,
  });

  await client.send('Emulation.setTimezoneOverride', {
    timezoneId: fp.timezone,
  });

  try {
    await client.send('Emulation.setLocaleOverride', {
      locale: fp.lang,
    });
  } catch (_) {
    // Older Chromium builds may lack setLocaleOverride — header + JS still apply.
  }

  await client.send('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: dpr,
    mobile,
    screenWidth: width,
    screenHeight: height,
  });

  if (fp.geolocation
    && Number.isFinite(fp.geolocation.latitude)
    && Number.isFinite(fp.geolocation.longitude)) {
    await client.send('Emulation.setGeolocationOverride', {
      latitude: fp.geolocation.latitude,
      longitude: fp.geolocation.longitude,
      accuracy: fp.geolocation.accuracy != null ? fp.geolocation.accuracy : 50,
    });
  }

  try {
    const { Browser } = client;
    // Prefer Browser.grantPermissions when available via Target
    await client.send('Browser.grantPermissions', {
      origin: 'https://example.com',
      permissions: ['geolocation', 'notifications'],
    });
    void Browser;
  } catch (_) {
    // Permissions may fail without a browser context origin — non-fatal.
  }

  if (typeof page.setExtraHTTPHeaders === 'function') {
    await page.setExtraHTTPHeaders({
      'Accept-Language': fp.accept_language || fp.lang,
    });
  }

  // Inject before any page script (navigations included).
  if (typeof page.evaluateOnNewDocument === 'function') {
    await page.evaluateOnNewDocument(buildInjectionSource(), fp);
  } else {
    await client.send('Page.addScriptToEvaluateOnNewDocument', {
      source: `(${buildInjectionSource()})(${JSON.stringify(fp)});`,
    });
  }
}

/**
 * Read back fingerprint-related values via page.evaluate (CDP Runtime.evaluate under the hood).
 *
 * @param {import('puppeteer').Page} page
 * @returns {Promise<object>}
 */
async function verifyFingerprint(page) {
  return page.evaluate(() => {
    const canvas = document.createElement('canvas');
    const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
    let webglVendor = null;
    let webglRenderer = null;
    if (gl) {
      const dbg = gl.getExtension('WEBGL_debug_renderer_info');
      if (dbg) {
        webglVendor = gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL);
        webglRenderer = gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL);
      }
    }

    let timezone = null;
    try {
      timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch (_) {
      timezone = null;
    }

    return {
      ua: navigator.userAgent,
      platform: navigator.platform,
      languages: Array.from(navigator.languages || []),
      language: navigator.language,
      hardwareConcurrency: navigator.hardwareConcurrency,
      deviceMemory: navigator.deviceMemory,
      maxTouchPoints: navigator.maxTouchPoints,
      timezone,
      screen: {
        width: screen.width,
        height: screen.height,
        availWidth: screen.availWidth,
        availHeight: screen.availHeight,
      },
      dpr: window.devicePixelRatio,
      webgl_vendor: webglVendor,
      webgl_renderer: webglRenderer,
      pluginCount: navigator.plugins ? navigator.plugins.length : 0,
      farmFpApplied: Boolean(window.__farmFpApplied),
    };
  });
}

/**
 * Compare applied fp with values observed in the page.
 *
 * @param {Fingerprint} fp
 * @param {object} observed from verifyFingerprint
 * @returns {{ok: boolean, mismatches: string[]}}
 */
function compareFingerprint(fp, observed) {
  const mismatches = [];

  if (observed.ua !== fp.ua) mismatches.push('ua');
  if (observed.platform !== fp.platform) mismatches.push('platform');
  if (observed.timezone !== fp.timezone) mismatches.push('timezone');
  if (observed.language && observed.language !== fp.lang) mismatches.push('lang');
  if (Number(observed.hardwareConcurrency) !== Number(fp.hardwareConcurrency)) {
    mismatches.push('hardwareConcurrency');
  }
  if (fp.deviceMemory != null && observed.deviceMemory != null
    && Number(observed.deviceMemory) !== Number(fp.deviceMemory)) {
    mismatches.push('deviceMemory');
  }
  if (observed.screen && Number(observed.screen.width) !== Number(fp.screen.width)) {
    mismatches.push('screen.width');
  }
  if (observed.screen && Number(observed.screen.height) !== Number(fp.screen.height)) {
    mismatches.push('screen.height');
  }
  if (fp.webgl_vendor && observed.webgl_vendor
    && observed.webgl_vendor !== fp.webgl_vendor) {
    mismatches.push('webgl_vendor');
  }
  if (fp.webgl_renderer && observed.webgl_renderer
    && observed.webgl_renderer !== fp.webgl_renderer) {
    mismatches.push('webgl_renderer');
  }
  if (!observed.farmFpApplied) mismatches.push('injection');

  return { ok: mismatches.length === 0, mismatches };
}

/**
 * Source function serialized into the page. Receives `fp` as argument.
 * @returns {Function}
 */
function buildInjectionSource() {
  return function injectFingerprint(fp) {
    if (window.__farmFpApplied) return;
    window.__farmFpApplied = true;

    const define = (obj, prop, value) => {
      try {
        Object.defineProperty(obj, prop, {
          get: () => value,
          configurable: true,
        });
      } catch (_) { /* ignore */ }
    };

    define(Navigator.prototype, 'userAgent', fp.ua);
    define(Navigator.prototype, 'platform', fp.platform);
    define(Navigator.prototype, 'language', fp.lang);
    define(Navigator.prototype, 'languages', Object.freeze(
      Array.isArray(fp.languages) && fp.languages.length
        ? fp.languages.slice()
        : [fp.lang]
    ));
    define(Navigator.prototype, 'hardwareConcurrency', Number(fp.hardwareConcurrency));
    if (fp.deviceMemory != null) {
      define(Navigator.prototype, 'deviceMemory', Number(fp.deviceMemory));
    }
    define(Navigator.prototype, 'maxTouchPoints', Number(fp.touch && fp.touch.maxTouchPoints) || 0);

    define(screen, 'width', Number(fp.screen.width));
    define(screen, 'height', Number(fp.screen.height));
    define(screen, 'availWidth', Number(
      fp.screen.availWidth != null ? fp.screen.availWidth : fp.screen.width
    ));
    define(screen, 'availHeight', Number(
      fp.screen.availHeight != null ? fp.screen.availHeight : fp.screen.height
    ));
    define(window, 'devicePixelRatio', Number(fp.dpr) || 1);

    // Plugins
    const pluginData = Array.isArray(fp.plugins) ? fp.plugins : [];
    const fakePlugins = {
      length: pluginData.length,
      item(i) { return this[i] || null; },
      namedItem(name) {
        for (let i = 0; i < pluginData.length; i++) {
          if (pluginData[i].name === name) return this[i];
        }
        return null;
      },
      refresh() {},
    };
    pluginData.forEach((p, i) => {
      fakePlugins[i] = {
        name: p.name,
        filename: p.filename,
        description: p.description,
        length: 0,
      };
    });
    define(Navigator.prototype, 'plugins', fakePlugins);
    define(Navigator.prototype, 'mimeTypes', { length: 0, item() { return null; }, namedItem() { return null; } });

    // WebGL vendor/renderer
    const patchWebGL = (proto) => {
      if (!proto || !proto.getParameter) return;
      const original = proto.getParameter;
      proto.getParameter = function getParameterPatched(param) {
        const dbg = this.getExtension && this.getExtension('WEBGL_debug_renderer_info');
        if (dbg) {
          if (param === dbg.UNMASKED_VENDOR_WEBGL) return fp.webgl_vendor;
          if (param === dbg.UNMASKED_RENDERER_WEBGL) return fp.webgl_renderer;
        }
        return original.call(this, param);
      };
    };
    try {
      const canvas = document.createElement('canvas');
      const gl = canvas.getContext('webgl');
      if (gl) patchWebGL(Object.getPrototypeOf(gl));
      const gl2 = canvas.getContext('webgl2');
      if (gl2) patchWebGL(Object.getPrototypeOf(gl2));
    } catch (_) { /* ignore */ }

    // Canvas noise seeded by canvas_hash_seed
    try {
      const seed = String(fp.canvas_hash_seed || '');
      let h = 0;
      for (let i = 0; i < seed.length; i++) h = ((h << 5) - h + seed.charCodeAt(i)) | 0;
      const noise = () => {
        h = (h * 1664525 + 1013904223) | 0;
        return ((h >>> 0) % 7) - 3;
      };
      const toBlob = HTMLCanvasElement.prototype.toBlob;
      const toDataURL = HTMLCanvasElement.prototype.toDataURL;
      const shift = (ctx) => {
        try {
          const { width, height } = ctx.canvas;
          if (!width || !height) return;
          const img = ctx.getImageData(0, 0, Math.min(width, 16), Math.min(height, 16));
          for (let i = 0; i < img.data.length; i += 4) {
            img.data[i] = Math.max(0, Math.min(255, img.data[i] + noise()));
          }
          ctx.putImageData(img, 0, 0);
        } catch (_) { /* tainted */ }
      };
      HTMLCanvasElement.prototype.toDataURL = function toDataURLPatched() {
        const ctx = this.getContext('2d');
        if (ctx) shift(ctx);
        return toDataURL.apply(this, arguments);
      };
      HTMLCanvasElement.prototype.toBlob = function toBlobPatched() {
        const ctx = this.getContext('2d');
        if (ctx) shift(ctx);
        return toBlob.apply(this, arguments);
      };
    } catch (_) { /* ignore */ }

    // Audio seed marker (subtle OfflineAudioContext spoof is heavy; stash seed for workers)
    try {
      define(window, '__farmAudioSeed', fp.audio_seed);
    } catch (_) { /* ignore */ }

    // clientRects noise
    try {
      const n = (fp.clientRects && fp.clientRects.noise) || 0;
      if (n) {
        const orig = Element.prototype.getBoundingClientRect;
        Element.prototype.getBoundingClientRect = function getBoundingClientRectPatched() {
          const r = orig.call(this);
          return DOMRect.fromRect({
            x: r.x + n,
            y: r.y + n,
            width: r.width,
            height: r.height,
          });
        };
      }
    } catch (_) { /* ignore */ }

    // mediaDevices.enumerateDevices
    try {
      if (navigator.mediaDevices && Array.isArray(fp.mediaDevices)) {
        navigator.mediaDevices.enumerateDevices = async function enumerateDevicesPatched() {
          return fp.mediaDevices.map((d) => ({
            deviceId: d.deviceId || '',
            kind: d.kind || 'audioinput',
            label: d.label || '',
            groupId: d.groupId || '',
            toJSON() { return this; },
          }));
        };
      }
    } catch (_) { /* ignore */ }

    // Touch event presence
    if (fp.touch) {
      if (fp.touch.touchEvent && !('ontouchstart' in window)) {
        define(window, 'ontouchstart', null);
      }
      if (fp.touch.touchStart) {
        try {
          window.TouchEvent = window.TouchEvent || function TouchEvent() {};
        } catch (_) { /* ignore */ }
      }
    }
  };
}

/**
 * @param {Fingerprint} fp
 */
function assertFp(fp) {
  if (!fp || typeof fp !== 'object') {
    throw new TypeError('fp must be an object');
  }
  const need = ['ua', 'platform', 'screen', 'dpr', 'timezone', 'lang'];
  for (const k of need) {
    if (fp[k] == null) throw new TypeError(`fp.${k} is required`);
  }
  if (!fp.screen.width || !fp.screen.height) {
    throw new TypeError('fp.screen.width/height are required');
  }
}

module.exports = {
  applyFingerprint,
  verifyFingerprint,
  compareFingerprint,
  buildInjectionSource,
};
