'use strict';

const { validateStep } = require('./schema');
const { shouldRun } = require('./random');
const { createContext, evalBranch } = require('./context');
const { getHandler } = require('./steps');
const { resolveScenario } = require('./loader');

const DEFAULT_STEP_TIMEOUT_SEC = 60;
const CONTROL_TYPES = new Set(['branch', 'loop']);

/**
 * Run a JSON scenario against a live page.
 *
 * @param {object} scenario  validated scenario object
 * @param {object} runCtx    { task, browser, page, reporter, config, human, taskRunId?, eventSink?, fast? }
 * @returns {Promise<{ status: string, error: string|null, metrics: object, screenshots: string[], events: object[] }>}
 */
async function runScenario(scenario, runCtx) {
  const ctx = createContext({ ...runCtx, scenario });
  await ctx.log('scenario_start', 'info', `start ${scenario.name}`, {
    type: scenario.type,
    version: scenario.version || 1,
    day: ctx.get('day'),
    steps: (scenario.steps || []).length,
  });

  try {
    await runSteps(scenario.steps || [], ctx, 'root');
    await ctx.log('scenario_done', 'info', 'completed', {
      steps_ok: ctx.metrics.steps_ok,
      steps_fail: ctx.metrics.steps_fail,
      steps_skip: ctx.metrics.steps_skip,
    });
    return {
      status: 'done',
      error: null,
      metrics: {
        ...ctx.metrics,
        duration_ms: Date.now() - ctx.startedAt,
        scenario: scenario.name,
        scenario_type: scenario.type,
        day: ctx.get('day'),
      },
      screenshots: ctx.screenshots,
      events: ctx.events,
      vars: { ...ctx.vars },
    };
  } catch (err) {
    let shot = null;
    try {
      shot = await ctx.screenshot(`fail-${Date.now()}.png`);
    } catch (_) { /* ignore */ }

    await ctx.log('scenario_fail', 'error', err.message, {
      code: err.code || null,
      step: err.stepType || null,
      screenshot: shot,
    });

    return {
      status: 'failed',
      error: err.message,
      metrics: {
        ...ctx.metrics,
        duration_ms: Date.now() - ctx.startedAt,
        scenario: scenario.name,
        scenario_type: scenario.type,
        failed_step: err.stepType || null,
        day: ctx.get('day'),
      },
      screenshots: ctx.screenshots,
      events: ctx.events,
      vars: { ...ctx.vars },
    };
  }
}

/**
 * Convenience: resolve scenario from task and run.
 */
async function executeFromTask(runCtx) {
  const scenario = resolveScenario(runCtx.task, {
    scenariosDir: runCtx.config && runCtx.config.root
      ? require('path').join(runCtx.config.root, 'scenarios')
      : undefined,
    dbScenario: runCtx.dbScenario,
    dbSteps: runCtx.dbSteps,
  });
  return runScenario(scenario, runCtx);
}

/**
 * @param {object[]} steps
 * @param {ReturnType<typeof createContext>} ctx
 * @param {string} path
 */
async function runSteps(steps, ctx, path) {
  if (!Array.isArray(steps)) return;

  for (let i = 0; i < steps.length; i++) {
    const step = steps[i];
    const stepPath = `${path}[${i}]`;
    await runOne(step, ctx, stepPath);
  }
}

/**
 * @param {object} step
 * @param {ReturnType<typeof createContext>} ctx
 * @param {string} stepPath
 */
async function runOne(step, ctx, stepPath) {
  const v = validateStep(step, stepPath);
  if (!v.ok) {
    const err = new Error(`Invalid step ${stepPath}: ${v.errors.join('; ')}`);
    err.code = 'STEP_INVALID';
    throw err;
  }

  if (!shouldRun(step)) {
    ctx.metrics.steps_skip += 1;
    await ctx.log('step_skip', 'debug', `${step.type} skipped by weight`, {
      path: stepPath,
      weight: step.weight,
    });
    return;
  }

  if (step.type === 'branch') {
    await runBranch(step, ctx, stepPath);
    return;
  }
  if (step.type === 'loop') {
    await runLoop(step, ctx, stepPath);
    return;
  }

  const onFail = step.on_fail || 'abort';
  const defaultTimeout = ctx.preferMin ? 25 : DEFAULT_STEP_TIMEOUT_SEC;
  const timeoutSec = step.timeout_sec != null ? Number(step.timeout_sec) : defaultTimeout;
  const retries = onFail === 'retry' ? (step.retries != null ? Number(step.retries) : 1) : 0;

  let attempt = 0;
  // eslint-disable-next-line no-constant-condition
  while (true) {
    attempt += 1;
    await ctx.log('step_start', 'info', step.type, {
      path: stepPath,
      attempt,
      type: step.type,
    });
    if (process.env.SCENARIO_VERBOSE === '1' || ctx.preferMin) {
      console.log(`[step] ${stepPath} ${step.type} attempt=${attempt}`);
    }

    try {
      const handler = getHandler(step.type);
      if (CONTROL_TYPES.has(step.type)) {
        throw new Error(`${step.type} must be handled as control flow`);
      }
      const result = await withTimeout(handler(step, ctx), timeoutSec * 1000, step.type);
      ctx.metrics.steps_ok += 1;
      await ctx.log('step_ok', 'info', step.type, {
        path: stepPath,
        result: summarize(result),
      });
      return result;
    } catch (err) {
      ctx.metrics.steps_fail += 1;
      await ctx.log('step_error', 'error', err.message, {
        path: stepPath,
        type: step.type,
        attempt,
        on_fail: onFail,
      });

      if (attempt <= retries) {
        await ctx.sleepMs(500 * attempt);
        continue;
      }

      if (onFail === 'continue') {
        try {
          await ctx.screenshot(`continue-${step.type}-${Date.now()}.png`);
        } catch (_) { /* ignore */ }
        return null;
      }

      try {
        await ctx.screenshot(`fail-${step.type}-${Date.now()}.png`);
      } catch (_) { /* ignore */ }

      err.stepType = step.type;
      err.stepPath = stepPath;
      throw err;
    }
  }
}

async function runBranch(step, ctx, stepPath) {
  const cond = step.branch != null ? step.branch : step.if;
  const ok = evalBranch(cond, ctx);
  ctx.metrics.branches += 1;
  await ctx.log('branch', 'info', ok ? 'then' : 'else', {
    path: stepPath,
    result: ok,
  });
  const branchSteps = ok ? (step.then || []) : (step.else || []);
  await runSteps(branchSteps, ctx, `${stepPath}.${ok ? 'then' : 'else'}`);
}

async function runLoop(step, ctx, stepPath) {
  let times = Math.max(0, Math.round(ctx.range(step, step.times || 1)));
  // Fast acceptance runs: cap heavy loops so a day-1 warmup finishes quickly
  if (ctx.preferMin && process.env.SCENARIO_FAST_LOOP_CAP) {
    times = Math.min(times, Number(process.env.SCENARIO_FAST_LOOP_CAP) || times);
  } else if (ctx.preferMin && times > 5) {
    times = 5;
  }
  ctx.metrics.loops += 1;
  await ctx.log('loop_start', 'info', `iterations=${times}`, { path: stepPath, times });

  for (let i = 0; i < times; i++) {
    ctx.set('loop_i', i);
    ctx.set('loop_n', times);
    await ctx.log('loop_iter', 'debug', `i=${i}`, { path: stepPath, i });
    await runSteps(step.steps || [], ctx, `${stepPath}.steps#${i}`);
  }

  await ctx.log('loop_done', 'info', `done ${times}`, { path: stepPath });
}

function withTimeout(promise, ms, label) {
  if (!ms || ms <= 0) return promise;
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => {
      const err = new Error(`step timeout (${label}) after ${ms}ms`);
      err.code = 'STEP_TIMEOUT';
      reject(err);
    }, ms);
    promise.then(
      (v) => { clearTimeout(t); resolve(v); },
      (e) => { clearTimeout(t); reject(e); },
    );
  });
}

function summarize(result) {
  if (result == null) return null;
  if (typeof result !== 'object') return result;
  try {
    return JSON.parse(JSON.stringify(result, (_k, v) => {
      if (v && typeof v === 'object' && typeof v.click === 'function') return '[Handle]';
      return v;
    }));
  } catch (_) {
    return { ok: true };
  }
}

module.exports = {
  runScenario,
  executeFromTask,
  runSteps,
  runOne,
};
