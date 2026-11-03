'use strict';

function rand(min, max) {
  return min + Math.random() * (max - min);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Cubic Bezier point.
 */
function bezier(t, p0, p1, p2, p3) {
  const u = 1 - t;
  return u * u * u * p0
    + 3 * u * u * t * p1
    + 3 * u * t * t * p2
    + t * t * t * p3;
}

/**
 * Human-like mouse move along a Bezier curve.
 * @param {import('puppeteer').Page} page
 * @param {number} toX
 * @param {number} toY
 * @param {{steps?: number}} [opts]
 */
async function moveMouseBezier(page, toX, toY, opts = {}) {
  const steps = opts.steps || Math.floor(rand(18, 35));
  const pos = await page.evaluate(() => ({
    x: window.__farmMouseX || 0,
    y: window.__farmMouseY || 0,
  })).catch(() => ({ x: rand(10, 200), y: rand(10, 200) }));

  const fromX = pos.x;
  const fromY = pos.y;
  const cp1x = fromX + rand(-80, 120);
  const cp1y = fromY + rand(-100, 100);
  const cp2x = toX + rand(-100, 80);
  const cp2y = toY + rand(-80, 120);

  for (let i = 1; i <= steps; i++) {
    const t = i / steps;
    // ease-in-out velocity
    const eased = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
    const x = bezier(eased, fromX, cp1x, cp2x, toX);
    const y = bezier(eased, fromY, cp1y, cp2y, toY);
    await page.mouse.move(x, y);
    await sleep(rand(4, 18));
  }

  await page.evaluate((x, y) => {
    window.__farmMouseX = x;
    window.__farmMouseY = y;
  }, toX, toY).catch(() => {});
}

/**
 * Type with variable inter-key delays and occasional longer pauses.
 * @param {import('puppeteer').Page} page
 * @param {string} text
 */
async function typeHuman(page, text) {
  for (const ch of text) {
    await page.keyboard.type(ch, { delay: 0 });
    let delay = rand(45, 160);
    if (Math.random() < 0.08) delay += rand(200, 450);
    if (ch === ' ' && Math.random() < 0.15) delay += rand(100, 300);
    await sleep(delay);
  }
}

/**
 * Inertial scroll (decelerating wheel bursts).
 * @param {import('puppeteer').Page} page
 * @param {number} distance  positive = down
 */
async function scrollInertia(page, distance) {
  const direction = Math.sign(distance) || 1;
  let remaining = Math.abs(distance);
  let speed = rand(80, 160);

  while (remaining > 0) {
    const delta = Math.min(remaining, speed);
    await page.mouse.wheel({ deltaY: direction * delta });
    remaining -= delta;
    speed *= rand(0.82, 0.94);
    if (speed < 20) speed = rand(20, 40);
    await sleep(rand(16, 40));
  }
  await sleep(rand(120, 400));
}

/**
 * Random short / medium human pause.
 * @param {'short'|'medium'|'long'} [kind]
 */
async function humanPause(kind = 'short') {
  const ranges = {
    short: [120, 450],
    medium: [400, 1200],
    long: [900, 2800],
  };
  const [a, b] = ranges[kind] || ranges.short;
  await sleep(rand(a, b));
}

/**
 * Occasionally open a blank tab and switch back (noise).
 * @param {import('puppeteer').Browser} browser
 * @param {import('puppeteer').Page} page
 */
async function maybeSwitchTabs(browser, page) {
  if (Math.random() > 0.25) return page;

  const extra = await browser.newPage();
  await sleep(rand(300, 900));
  const pages = await browser.pages();
  if (pages.length > 1 && Math.random() < 0.5) {
    await pages[Math.floor(Math.random() * pages.length)].bringToFront();
    await sleep(rand(200, 700));
  }
  await page.bringToFront();
  await extra.close().catch(() => {});
  return page;
}

/**
 * Click element with Bezier approach.
 * @param {import('puppeteer').Page} page
 * @param {import('puppeteer').ElementHandle|string} target
 */
async function clickHuman(page, target) {
  const el = typeof target === 'string' ? await page.$(target) : target;
  if (!el) throw new Error('clickHuman: element not found');
  const box = await el.boundingBox();
  if (!box) throw new Error('clickHuman: no bounding box');
  const x = box.x + box.width * rand(0.3, 0.7);
  const y = box.y + box.height * rand(0.3, 0.7);
  await moveMouseBezier(page, x, y);
  await sleep(rand(40, 140));
  await page.mouse.down();
  await sleep(rand(30, 90));
  await page.mouse.up();
}

module.exports = {
  sleep,
  rand,
  moveMouseBezier,
  typeHuman,
  scrollInertia,
  humanPause,
  maybeSwitchTabs,
  clickHuman,
};
