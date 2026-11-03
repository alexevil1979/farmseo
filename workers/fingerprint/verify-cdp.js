'use strict';

/**
 * Acceptance smoke: applyFingerprint → navigate → verifyFingerprint via CDP/evaluate.
 *
 *   cd workers/fingerprint && npm i puppeteer && npm run verify
 */

const { applyFingerprint, verifyFingerprint, compareFingerprint } = require('./apply');

async function main() {
  let puppeteer;
  try {
    puppeteer = require('puppeteer');
  } catch (_) {
    console.error('puppeteer not installed. Run: npm i puppeteer');
    process.exit(2);
  }

  const fp = {
    profile: 'desktop',
    os: 'Windows',
    ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.6261.112 Safari/537.36',
    platform: 'Win32',
    screen: { width: 1920, height: 1080, availWidth: 1920, availHeight: 1040 },
    dpr: 1,
    timezone: 'Europe/Moscow',
    lang: 'ru-RU',
    languages: ['ru-RU', 'ru', 'en-US'],
    accept_language: 'ru-RU,ru;q=0.9,en-US;q=0.8',
    fonts: ['Arial', 'Segoe UI', 'Tahoma'],
    webgl_vendor: 'Google Inc. (NVIDIA)',
    webgl_renderer: 'ANGLE (NVIDIA, NVIDIA GeForce GTX 1660 Direct3D11 vs_5_0 ps_5_0, D3D11)',
    canvas_hash_seed: 'abc123canvasseed0000000000000001',
    audio_seed: 'abc123audioseed000000000000000001',
    hardwareConcurrency: 8,
    deviceMemory: 8,
    plugins: [
      { name: 'PDF Viewer', filename: 'internal-pdf-viewer', description: 'Portable Document Format' },
      { name: 'Chrome PDF Viewer', filename: 'internal-pdf-viewer', description: 'Portable Document Format' },
    ],
    clientRects: { noise: 0.00001, seed: 'rects01' },
    touch: { maxTouchPoints: 0, touchEvent: false, touchStart: false },
    mediaDevices: [
      { kind: 'audioinput', label: '', deviceId: 'd1', groupId: 'g1' },
    ],
    geolocation: { latitude: 55.75, longitude: 37.62, accuracy: 40 },
  };

  const launchOpts = {
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  };
  const exe = process.env.PUPPETEER_EXECUTABLE_PATH || findCachedChrome();
  if (exe) {
    launchOpts.executablePath = exe;
  }

  const browser = await puppeteer.launch(launchOpts);

  try {
    const page = await browser.newPage();
    await applyFingerprint(page, fp);
    await page.goto('data:text/html,<title>fp-check</title><h1>ok</h1>', {
      waitUntil: 'domcontentloaded',
    });

    const observed = await verifyFingerprint(page);
    const cmp = compareFingerprint(fp, observed);

    console.log('observed:', JSON.stringify(observed, null, 2));
    console.log('compare:', cmp);

    if (!cmp.ok) {
      console.error('CDP verify FAILED mismatches:', cmp.mismatches.join(', '));
      process.exit(1);
    }

    console.log('CDP verify OK');
    process.exit(0);
  } finally {
    await browser.close();
  }
}

/**
 * @returns {string|null}
 */
function findCachedChrome() {
  const fs = require('fs');
  const path = require('path');
  const home = process.env.USERPROFILE || process.env.HOME || '';
  const base = path.join(home, '.cache', 'puppeteer', 'chrome');
  if (!fs.existsSync(base)) return null;
  const versions = fs.readdirSync(base).sort().reverse();
  for (const ver of versions) {
    const candidates = [
      path.join(base, ver, 'chrome-win64', 'chrome.exe'),
      path.join(base, ver, 'chrome-linux64', 'chrome'),
      path.join(base, ver, 'chrome-mac-x64', 'Google Chrome for Testing.app', 'Contents', 'MacOS', 'Google Chrome for Testing'),
    ];
    for (const c of candidates) {
      if (fs.existsSync(c)) return c;
    }
  }
  return null;
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
