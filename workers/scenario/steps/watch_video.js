'use strict';

const { waitReady, findFirst, humanClick } = require('./_helpers');
const { rand } = require('../random');

const PLAY_SELECTORS = [
  'button[aria-label*="Смотреть" i]',
  'button[aria-label*="Play" i]',
  '.ytp-play-button',
  'video',
  '.player-overlay',
  '.VideoPlayer button',
];

async function watch_video(step, ctx) {
  const { page } = ctx;
  if (step.url) {
    await page.goto(ctx.interpolate(step.url), {
      waitUntil: 'domcontentloaded',
      timeout: (step.timeout_sec || 60) * 1000,
    });
    await waitReady(page);
  }

  const found = await findFirst(page, step.selector ? [step.selector] : PLAY_SELECTORS);
  if (found) {
    if (found.selector === 'video') {
      await page.evaluate((sel) => {
        const v = document.querySelector(sel);
        if (v) {
          v.muted = true;
          const p = v.play();
          if (p && p.catch) p.catch(() => {});
        }
      }, found.selector);
    } else {
      await humanClick(ctx, found.selector).catch(() => {});
    }
  }

  const sec = ctx.range({
    min: step.min != null ? step.min : 5,
    max: step.max != null ? step.max : 25,
  }, 10);

  await ctx.sleepMs(sec * 1000);

  // occasional scrub / pause noise
  if (Math.random() < 0.3) {
    await page.keyboard.press('Space').catch(() => {});
    await ctx.sleepMs(rand(300, 900));
    await page.keyboard.press('Space').catch(() => {});
  }

  return { watched_sec: sec, clicked: Boolean(found) };
}

module.exports = watch_video;
