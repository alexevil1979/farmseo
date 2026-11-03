#!/usr/bin/env node
'use strict';

/**
 * Scenario CLI
 *
 *   node workers/scenario/cli.js run --account=1 --scenario=warmup [--day=1] [--fast]
 *   node workers/scenario/cli.js validate --scenario=warmup
 *   node workers/scenario/cli.js list
 *
 * Also invoked by: php farm scenario:run --account=1 --scenario=warmup
 */

const path = require('path');
const fs = require('fs');
const { loadConfig } = require('../lib/config');
const { launchBrowser, maybeApplyFingerprint } = require('../lib/browser');
const { createReporter } = require('../lib/reporter');
const { createRedis } = require('../lib/redis');
const human = require('../lib/human');
const { loadScenarioFile, listScenarioFiles, toDbRows } = require('./loader');
const { validateScenario } = require('./schema');
const { runScenario } = require('./executor');
const { createEventSink } = require('./events');

async function main() {
  const argv = process.argv.slice(2);
  const cmd = argv[0] || 'help';
  const args = parseArgs(argv.slice(1));

  if (cmd === 'list') {
    const names = listScenarioFiles();
    for (const n of names) console.log(n);
    return 0;
  }

  if (cmd === 'validate') {
    const name = args.scenario || args._[0];
    if (!name) {
      console.error('Usage: validate --scenario=NAME');
      return 1;
    }
    const sc = loadScenarioFile(name);
    if (!sc) {
      console.error(`Scenario not found: ${name}`);
      return 1;
    }
    const v = validateScenario(sc);
    if (!v.ok) {
      console.error('INVALID');
      for (const e of v.errors) console.error(' -', e);
      return 1;
    }
    console.log(`OK ${sc.name} type=${sc.type} steps=${sc.steps.length}`);
    return 0;
  }

  if (cmd === 'dump-db') {
    const name = args.scenario || args._[0];
    const sc = loadScenarioFile(name);
    if (!sc) {
      console.error(`Scenario not found: ${name}`);
      return 1;
    }
    console.log(JSON.stringify(toDbRows(sc), null, 2));
    return 0;
  }

  if (cmd === 'help' || cmd === '--help' || cmd === '-h') {
    printHelp();
    return 0;
  }

  if (cmd === 'run') {
    return runCmd(args);
  }

  console.error(`Unknown command: ${cmd}`);
  printHelp();
  return 1;
}

async function runCmd(args) {
  const accountId = Number(args.account);
  const scenarioName = args.scenario || 'warmup';
  if (!Number.isFinite(accountId) || accountId <= 0) {
    console.error('Usage: run --account=ID --scenario=NAME [--day=N] [--fast] [--task-run=ID]');
    return 1;
  }

  const scenario = loadScenarioFile(scenarioName);
  if (!scenario) {
    console.error(`Scenario not found: ${scenarioName}`);
    return 1;
  }
  const v = validateScenario(scenario);
  if (!v.ok) {
    console.error('Invalid scenario:', v.errors.join('; '));
    return 1;
  }

  const config = loadConfig();

  const taskId = args.task != null ? Number(args.task) : Date.now();
  const taskRunId = args['task-run'] != null ? Number(args['task-run']) : null;
  const day = args.day != null ? Number(args.day) : Number(scenario.defaults?.day || 1);
  const fast = Boolean(args.fast) || process.env.SCENARIO_FAST === '1';

  const logPath = path.join(
    config.storage.logs,
    'scenarios',
    `run-${taskRunId || taskId}.ndjson`,
  );

  const farmBin = resolveFarmBin(config.root);
  // Default: NDJSON only; PHP `scenario:run` imports into runs_events at the end.
  // Opt-in live persist with --persist-live (spawns php per event).
  const eventSink = createEventSink({
    taskRunId,
    logPath,
    farmBin,
    persist: Boolean(args['persist-live'] && taskRunId && farmBin),
  });

  let redis = null;
  let reporter;
  const noopRedis = {
    lpush: async () => 0,
    quit: async () => {},
    disconnect: () => {},
    on: () => noopRedis,
  };
  try {
    redis = createRedis({ ...config.redis, lazyConnect: true });
    // Avoid crashing CLI when Redis is down — connect best-effort
    if (typeof redis.connect === 'function') {
      await Promise.race([
        redis.connect().catch(() => {}),
        new Promise((r) => setTimeout(r, 500)),
      ]);
    }
    redis.on('error', () => {});
    reporter = createReporter({ config, redis });
  } catch (_) {
    redis = noopRedis;
    reporter = createReporter({ config, redis: noopRedis });
  }

  const task = {
    task_id: taskId,
    id: taskId,
    account_id: accountId,
    type: scenario.type,
    scenario_id: args['scenario-id'] != null ? Number(args['scenario-id']) : null,
    timeout: Number(args.timeout || 1800),
    payload: {
      type: scenario.type,
      account_id: accountId,
      scenario_id: args['scenario-id'] != null ? Number(args['scenario-id']) : null,
      scenario_name: scenarioName,
      keyword: args.keyword || scenario.defaults?.keyword || null,
      target_url: args['target-url'] || scenario.defaults?.target_url || null,
      geo: args.geo || 'ru',
      device: args.device || 'desktop',
      day,
      limits: {
        ...(scenario.defaults?.limits || {}),
        ...(fast ? { fast: true, dwell_sec_min: 0, dwell_sec_max: 1 } : {}),
      },
      login: args.login || undefined,
      password: args.password || undefined,
    },
  };

  console.log(`[scenario:run] account=${accountId} scenario=${scenarioName} day=${day} fast=${fast}`);

  let browser = null;
  let releaseLock = null;
  const started = Date.now();

  try {
    const launched = await launchBrowser({
      accountId,
      profilesRoot: config.storage.profiles,
      headless: args.headless != null ? args.headless === '1' || args.headless === true : config.chromeHeadless,
      display: config.displayBase,
      executablePath: args.chrome || config.chromePath || undefined,
      lockOwner: `scenario-cli:${process.pid}`,
    });
    browser = launched.browser;
    releaseLock = launched.releaseLock;
    const { page } = launched;

    if (args.fingerprint) {
      try {
        const fp = JSON.parse(fs.readFileSync(args.fingerprint, 'utf8'));
        await maybeApplyFingerprint(page, fp);
      } catch (err) {
        console.warn('[scenario:run] fingerprint skip:', err.message);
      }
    }

    const result = await runScenario(scenario, {
      task,
      browser,
      page,
      reporter,
      config,
      human,
      taskRunId,
      eventSink,
      fast,
    });

    // Final result report (task_runs via PHP result endpoint / Redis)
    try {
      await Promise.race([
        reporter.report({
          task_id: taskId,
          status: result.status,
          error: result.error,
          metrics: {
            ...result.metrics,
            worker_id: config.workerId,
            events_count: (result.events || []).length,
            log_path: logPath,
          },
          screenshots: result.screenshots || [],
        }),
        new Promise((resolve) => setTimeout(() => resolve({ skipped: true }), 8000)),
      ]);
    } catch (err) {
      console.warn('[scenario:run] report failed:', err.message);
    }

    // Write machine-readable summary for PHP farm scenario:run
    const summaryPath = path.join(config.storage.logs, 'scenarios', `summary-${taskRunId || taskId}.json`);
    fs.mkdirSync(path.dirname(summaryPath), { recursive: true });
    fs.writeFileSync(summaryPath, JSON.stringify({
      ...result,
      task_id: taskId,
      task_run_id: taskRunId,
      account_id: accountId,
      duration_ms: Date.now() - started,
      log_path: logPath,
    }, null, 2));

    console.log(`[scenario:run] status=${result.status} duration_ms=${Date.now() - started}`);
    if (result.error) console.error(`[scenario:run] error: ${result.error}`);
    console.log(`SUMMARY ${summaryPath}`);

    return result.status === 'done' ? 0 : 2;
  } catch (err) {
    console.error('[scenario:run] fatal:', err.message);
    if (taskRunId && farmBin) {
      try {
        await eventSink({
          event: 'scenario_fatal',
          level: 'error',
          message: err.message,
          data: { code: err.code || null },
          ts: new Date().toISOString(),
        });
      } catch (_) { /* ignore */ }
    }
    return 1;
  } finally {
    if (browser) {
      try {
        await Promise.race([
          browser.close(),
          new Promise((r) => setTimeout(r, 5000)),
        ]);
      } catch (_) { /* ignore */ }
    }
    if (typeof releaseLock === 'function') {
      try { releaseLock(); } catch (_) { /* ignore */ }
    }
    if (redis && typeof redis.quit === 'function') {
      try {
        await Promise.race([
          redis.quit(),
          new Promise((r) => setTimeout(r, 1000)),
        ]);
      } catch (_) {
        try { redis.disconnect(); } catch (__) { /* ignore */ }
      }
    }
  }
}

function parseArgs(argv) {
  const out = { _: [] };
  for (const a of argv) {
    if (a.startsWith('--')) {
      const eq = a.indexOf('=');
      if (eq === -1) {
        const key = a.slice(2);
        out[key] = true;
      } else {
        const key = a.slice(2, eq);
        let val = a.slice(eq + 1);
        if (val === 'true') val = true;
        else if (val === 'false') val = false;
        out[key] = val;
      }
    } else {
      out._.push(a);
    }
  }
  return out;
}

function resolveFarmBin(root) {
  const candidates = [
    path.join(root, 'farm'),
    path.join(root, 'src', 'Console', 'farm'),
  ];
  for (const c of candidates) {
    if (fs.existsSync(c)) return c;
  }
  return null;
}

function printHelp() {
  console.log(`Scenario interpreter CLI

Usage:
  node workers/scenario/cli.js run --account=1 --scenario=warmup [--day=1] [--fast]
  node workers/scenario/cli.js validate --scenario=pf
  node workers/scenario/cli.js list

Options (run):
  --account=ID       account id (required)
  --scenario=NAME    warmup | pf | service | file stem
  --day=N            warmup day 1..14
  --fast             clamp sleeps / use min ranges
  --task=ID          task id for screenshots/reporting
  --task-run=ID      task_runs.id for runs_events
  --scenario-id=ID   DB scenarios.id
  --keyword=TEXT     search keyword (pf)
  --target-url=URL   target URL (pf)
  --headless=0|1     override CHROME_HEADLESS
  --no-persist       skip PHP event writes
`);
}

main()
  .then((code) => process.exit(code || 0))
  .catch((err) => {
    console.error(err);
    process.exit(1);
  });
