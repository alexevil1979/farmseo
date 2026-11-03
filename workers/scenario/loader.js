'use strict';

const fs = require('fs');
const path = require('path');
const { validateScenario, normalizeDbStep } = require('./schema');

const SCENARIOS_DIR = path.resolve(__dirname, '..', '..', 'scenarios');

/**
 * Load scenario JSON by name/type/id filename (without .json).
 * @param {string|number} nameOrType
 * @param {{ root?: string, validate?: boolean }} [opts]
 */
function loadScenarioFile(nameOrType, opts = {}) {
  const root = opts.root || SCENARIOS_DIR;
  const key = String(nameOrType);
  const candidates = [
    path.join(root, `${key}.json`),
    path.join(root, key, 'scenario.json'),
  ];

  for (const file of candidates) {
    if (!fs.existsSync(file)) continue;
    const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
    if (opts.validate !== false) {
      const v = validateScenario(raw);
      if (!v.ok) {
        const err = new Error(`Invalid scenario ${file}: ${v.errors.join('; ')}`);
        err.code = 'SCENARIO_INVALID';
        err.errors = v.errors;
        throw err;
      }
    }
    return { ...raw, _source: file };
  }
  return null;
}

/**
 * Resolve scenario from task context: file by type/name, then optional DB steps.
 * @param {object} task
 * @param {{ scenariosDir?: string, dbScenario?: object, dbSteps?: object[] }} [opts]
 */
function resolveScenario(task, opts = {}) {
  const payload = task.payload || {};
  const type = task.type || payload.type;
  const name = payload.scenario_name || payload.scenario || type;
  const scenarioId = task.scenario_id ?? payload.scenario_id;

  let scenario = null;

  if (opts.dbScenario && Array.isArray(opts.dbSteps) && opts.dbSteps.length) {
    scenario = {
      id: opts.dbScenario.id,
      name: opts.dbScenario.name,
      type: opts.dbScenario.type,
      version: opts.dbScenario.version || 1,
      description: opts.dbScenario.description || null,
      defaults: opts.dbScenario.defaults || {},
      steps: opts.dbSteps.map(normalizeDbStep),
      _source: 'db',
    };
  }

  if (!scenario) {
    scenario = loadScenarioFile(name, { root: opts.scenariosDir })
      || loadScenarioFile(type, { root: opts.scenariosDir })
      || (scenarioId != null ? loadScenarioFile(scenarioId, { root: opts.scenariosDir }) : null);
  }

  if (!scenario) {
    const err = new Error(`Scenario not found: name=${name} type=${type} id=${scenarioId}`);
    err.code = 'SCENARIO_NOT_FOUND';
    throw err;
  }

  const v = validateScenario(scenario);
  if (!v.ok) {
    const err = new Error(`Invalid scenario: ${v.errors.join('; ')}`);
    err.code = 'SCENARIO_INVALID';
    err.errors = v.errors;
    throw err;
  }

  return scenario;
}

/**
 * List available JSON scenario files.
 */
function listScenarioFiles(root = SCENARIOS_DIR) {
  if (!fs.existsSync(root)) return [];
  return fs.readdirSync(root)
    .filter((f) => f.endsWith('.json'))
    .map((f) => path.basename(f, '.json'));
}

/**
 * Convert runtime scenario → rows for scenarios + scenario_steps tables.
 * @param {object} scenario
 */
function toDbRows(scenario) {
  const meta = {
    name: scenario.name,
    type: scenario.type,
    description: scenario.description || null,
    version: scenario.version || 1,
    is_active: 1,
    defaults: scenario.defaults || null,
  };
  const steps = (scenario.steps || []).map((step, i) => {
    const { type, timeout_sec, on_fail, ...params } = step;
    return {
      step_order: i + 1,
      action: type,
      params: { ...params, type },
      timeout_sec: timeout_sec != null ? timeout_sec : null,
      on_fail: on_fail || 'abort',
    };
  });
  return { scenario: meta, steps };
}

module.exports = {
  SCENARIOS_DIR,
  loadScenarioFile,
  resolveScenario,
  listScenarioFiles,
  toDbRows,
};
