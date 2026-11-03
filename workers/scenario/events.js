'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

/**
 * Create an event sink that:
 * 1) appends NDJSON to a run log file
 * 2) optionally notifies PHP via `php farm scenario:event` (writes runs_events)
 *
 * @param {object} opts
 * @param {number|null} opts.taskRunId
 * @param {string} opts.logPath
 * @param {string} [opts.farmBin]  path to `farm` php entry
 * @param {boolean} [opts.persist] call PHP for each event
 */
function createEventSink(opts) {
  const {
    taskRunId = null,
    logPath,
    farmBin = null,
    persist = Boolean(taskRunId && farmBin),
  } = opts;

  if (logPath) {
    fs.mkdirSync(path.dirname(logPath), { recursive: true });
    if (!fs.existsSync(logPath)) fs.writeFileSync(logPath, '');
  }

  return async function eventSink(entry) {
    const line = JSON.stringify({
      task_run_id: taskRunId,
      ...entry,
    });

    if (logPath) {
      fs.appendFileSync(logPath, `${line}\n`);
    }

    if (persist && taskRunId && farmBin) {
      const args = [
        farmBin,
        'scenario:event',
        `--run=${taskRunId}`,
        `--event=${entry.event}`,
        `--level=${entry.level || 'info'}`,
      ];
      if (entry.message) args.push(`--message=${entry.message}`);
      if (entry.data) args.push(`--data=${JSON.stringify(entry.data)}`);

      const r = spawnSync(process.env.PHP_BIN || 'php', args, {
        encoding: 'utf8',
        timeout: 10000,
        windowsHide: true,
      });
      if (r.status !== 0) {
        const err = (r.stderr || r.stdout || '').trim();
        if (err) console.warn('[scenario:event]', err.slice(0, 300));
      }
    }
  };
}

/**
 * Flush remaining events from NDJSON into PHP if live persist failed.
 */
function importEventsFile(farmBin, taskRunId, logPath) {
  if (!farmBin || !taskRunId || !logPath || !fs.existsSync(logPath)) return 0;
  const r = spawnSync(process.env.PHP_BIN || 'php', [
    farmBin,
    'scenario:import-events',
    `--run=${taskRunId}`,
    `--file=${logPath}`,
  ], {
    encoding: 'utf8',
    timeout: 60000,
    windowsHide: true,
  });
  if (r.status !== 0) {
    console.warn('[scenario:import-events]', (r.stderr || r.stdout || '').trim().slice(0, 400));
    return 0;
  }
  const m = String(r.stdout || '').match(/imported[=:\s]+(\d+)/i);
  return m ? Number(m[1]) : 0;
}

module.exports = {
  createEventSink,
  importEventsFile,
};
