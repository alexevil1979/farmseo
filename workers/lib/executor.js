'use strict';

const path = require('path');
const fs = require('fs');
const { humanPause, scrollInertia, maybeSwitchTabs, moveMouseBezier, rand } = require('./human');

/**
 * Scenario dispatcher.
 * Prefer JSON scenarios via workers/scenario (module 4); fall back to
 * scenarios/{id|type}.js modules; else smoke navigation.
 *
 * @param {object} ctx
 * @returns {Promise<{ status: string, error: string|null, metrics: object, screenshots: string[] }>}
 */
async function execute(ctx) {
  const { task } = ctx;
  const scenarioId = task.scenario_id ?? task.payload?.scenario_id;
  const type = task.type || task.payload?.type || 'check';
  const name = task.payload?.scenario_name || task.payload?.scenario || type;

  // 1) JSON interpreter (warmup / pf / service)
  try {
    const { executeFromTask } = require('../scenario/executor');
    const { loadScenarioFile } = require('../scenario/loader');
    if (loadScenarioFile(name) || loadScenarioFile(type)) {
      return executeFromTask(ctx);
    }
  } catch (err) {
    if (err.code === 'SCENARIO_NOT_FOUND') {
      // fall through
    } else if (err.code === 'SCENARIO_INVALID') {
      return {
        status: 'failed',
        error: err.message,
        metrics: { scenario_invalid: 1 },
        screenshots: [],
      };
    } else {
      console.warn('[executor] scenario engine error:', err.message);
    }
  }

  // 2) Legacy JS module under scenarios/
  const mod = loadScenarioModule(scenarioId, type);
  if (mod && typeof mod.run === 'function') {
    return mod.run(ctx);
  }
  if (typeof mod === 'function') {
    return mod(ctx);
  }

  return runSmoke(ctx);
}

function loadScenarioModule(scenarioId, type) {
  const root = path.resolve(__dirname, '..', '..', 'scenarios');
  const candidates = [
    scenarioId != null ? path.join(root, `${scenarioId}.js`) : null,
    path.join(root, `${type}.js`),
    path.join(root, 'index.js'),
  ].filter(Boolean);

  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    try {
      // eslint-disable-next-line import/no-dynamic-require, global-require
      return require(file);
    } catch (err) {
      console.warn(`[executor] failed to load ${file}:`, err.message);
    }
  }
  return null;
}

async function runSmoke(ctx) {
  const { task, browser, page, reporter } = ctx;
  const taskId = task.task_id ?? task.id;
  const screenshots = [];
  const started = Date.now();
  const engine = pickEngine(task);

  await page.goto(engine, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await humanPause('medium');
  await moveMouseBezier(page, rand(100, 600), rand(100, 400));
  await scrollInertia(page, rand(200, 600));
  await maybeSwitchTabs(browser, page);

  if (taskId != null) {
    const rel = await reporter.saveScreenshot(taskId, page, 'smoke.png');
    screenshots.push(rel);
  }

  return {
    status: 'done',
    error: null,
    metrics: {
      duration_ms: Date.now() - started,
      engine,
      smoke: 1,
    },
    screenshots,
  };
}

function pickEngine(task) {
  const url = task.target_url || task.payload?.target_url;
  if (url && /^https?:\/\//i.test(url)) return url;
  const geo = String(task.geo || task.payload?.geo || 'ru').toLowerCase();
  if (geo === 'ru' || geo === 'ua' || geo === 'by' || geo === 'kz') {
    return 'https://yandex.ru';
  }
  return 'https://www.google.com';
}

module.exports = { execute, runSmoke, loadScenarioModule };
