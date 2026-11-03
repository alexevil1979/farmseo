#!/usr/bin/env node
'use strict';

/**
 * Offline smoke test: schema + branch/loop executor with a fake page.
 * Does not launch Chrome.
 */

const { loadScenarioFile } = require('./loader');
const { runScenario } = require('./executor');
const { evalBranch } = require('./context');

function fakePage() {
  let url = 'https://yandex.ru/';
  return {
    url: () => url,
    goto: async (u) => { url = u; },
    title: async () => 'Yandex',
    viewport: () => ({ width: 1280, height: 720 }),
    mouse: {
      move: async () => {},
      wheel: async () => {},
      down: async () => {},
      up: async () => {},
    },
    keyboard: {
      type: async () => {},
      press: async () => {},
      down: async () => {},
      up: async () => {},
    },
    $: async () => ({
      click: async () => {},
      hover: async () => {},
      boundingBox: async () => ({ x: 10, y: 10, width: 100, height: 20 }),
    }),
    $$: async () => [],
    $$eval: async () => [],
    evaluate: async (fn, ...args) => {
      if (typeof fn === 'function') {
        try { return fn(...args); } catch (_) { return null; }
      }
      return null;
    },
    waitForSelector: async () => {},
    waitForFunction: async () => {},
    waitForNavigation: async () => {},
    setCookie: async () => {},
    screenshot: async () => Buffer.from([]),
    bringToFront: async () => {},
  };
}

function fakeBrowser(page) {
  return {
    newPage: async () => page,
    pages: async () => [page],
  };
}

async function main() {
  // branch unit checks
  const ctxLike = { get: (k) => ({ day: 5, target_url: 'https://ex.com' }[k]), vars: { day: 5 } };
  console.assert(evalBranch({ var: 'day', op: 'lte', value: 7 }, ctxLike) === true, 'lte');
  console.assert(evalBranch('day >= 3', { vars: { day: 5 } }) === true, 'expr');
  console.assert(evalBranch(true, ctxLike) === true, 'bool');

  const scenario = {
    name: 'Smoke',
    type: 'warmup',
    version: 1,
    defaults: { day: 1, limits: { fast: true }, queries: ['тест'] },
    steps: [
      { type: 'sleep', ms: 1 },
      {
        type: 'branch',
        branch: { var: 'day', op: 'lte', value: 2 },
        then: [
          {
            type: 'loop',
            min: 2,
            max: 2,
            steps: [
              { type: 'sleep', ms: 1 },
              { type: 'mouse_move' },
            ],
          },
        ],
        else: [{ type: 'sleep', ms: 1 }],
      },
      { type: 'assert', var: 'day', equals: 1 },
    ],
  };

  const page = fakePage();
  const result = await runScenario(scenario, {
    task: {
      task_id: 1,
      account_id: 1,
      type: 'warmup',
      payload: { day: 1, limits: { fast: true }, account_id: 1, scenario_id: 1, type: 'warmup' },
    },
    browser: fakeBrowser(page),
    page,
    reporter: { saveScreenshot: async () => '1/x.png' },
    config: { storage: { screenshots: require('path').join(__dirname, '..', '..', 'storage', 'screenshots') } },
    human: {
      sleep: (ms) => new Promise((r) => setTimeout(r, Math.min(ms, 5))),
      moveMouseBezier: async () => {},
      scrollInertia: async () => {},
      typeHuman: async () => {},
      clickHuman: async () => {},
    },
    fast: true,
  });

  if (result.status !== 'done') {
    console.error('FAIL', result.error, result.events.slice(-5));
    process.exit(1);
  }

  // load real JSON files
  for (const name of ['warmup', 'pf', 'service']) {
    const sc = loadScenarioFile(name);
    if (!sc) throw new Error(`missing ${name}`);
    console.log(`ok load ${name} steps=${sc.steps.length}`);
  }

  console.log('smoke-test OK', {
    steps_ok: result.metrics.steps_ok,
    branches: result.metrics.branches,
    loops: result.metrics.loops,
    events: result.events.length,
  });
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
