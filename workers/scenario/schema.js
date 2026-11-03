'use strict';

/**
 * Scenario / step schema validator (module 4).
 *
 * Step shape: { type, selector?, url?, min?, max?, weight?, branch?, then?, else?, ... }
 */

const STEP_TYPES = Object.freeze([
  'goto',
  'search_yandex',
  'type_human',
  'click_selector',
  'click_serp_position',
  'scroll',
  'dwell',
  'mouse_move',
  'hover',
  'open_new_tab',
  'internal_click',
  'back',
  'add_bookmark',
  'read_article',
  'visit_service',
  'watch_video',
  'set_cookie',
  'assert',
  'sleep',
  'branch',
  'loop',
  'screenshot',
  'captcha_solve',
  'relogin',
]);

const ON_FAIL = Object.freeze(['abort', 'retry', 'continue']);

const BRANCH_OPS = Object.freeze([
  'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'exists', 'truthy', 'falsy',
]);

/**
 * @param {unknown} step
 * @param {string} [path]
 * @returns {{ ok: true, step: object } | { ok: false, errors: string[] }}
 */
function validateStep(step, path = 'step') {
  const errors = [];
  if (!step || typeof step !== 'object' || Array.isArray(step)) {
    return { ok: false, errors: [`${path}: must be an object`] };
  }

  const s = /** @type {Record<string, unknown>} */ (step);
  if (typeof s.type !== 'string' || !s.type) {
    errors.push(`${path}.type: required string`);
  } else if (!STEP_TYPES.includes(s.type)) {
    errors.push(`${path}.type: unknown "${s.type}"`);
  }

  for (const key of ['selector', 'url', 'text', 'query', 'service', 'name', 'value', 'var', 'op']) {
    if (s[key] != null && typeof s[key] !== 'string' && typeof s[key] !== 'number') {
      errors.push(`${path}.${key}: expected string|number`);
    }
  }

  for (const key of ['min', 'max', 'weight', 'position', 'timeout_sec', 'times', 'duration', 'x', 'y']) {
    if (s[key] != null && typeof s[key] !== 'number') {
      errors.push(`${path}.${key}: expected number`);
    }
  }

  if (s.min != null && s.max != null && Number(s.min) > Number(s.max)) {
    errors.push(`${path}: min > max`);
  }

  if (s.weight != null) {
    const w = Number(s.weight);
    if (!(w >= 0 && w <= 1)) errors.push(`${path}.weight: must be 0..1`);
  }

  if (s.on_fail != null && !ON_FAIL.includes(String(s.on_fail))) {
    errors.push(`${path}.on_fail: abort|retry|continue`);
  }

  if (s.branch != null) {
    const bErr = validateBranch(s.branch, `${path}.branch`);
    errors.push(...bErr);
  }

  if (s.then != null) {
    if (!Array.isArray(s.then)) errors.push(`${path}.then: must be array`);
    else s.then.forEach((ch, i) => {
      const r = validateStep(ch, `${path}.then[${i}]`);
      if (!r.ok) errors.push(...r.errors);
    });
  }

  if (s.else != null) {
    if (!Array.isArray(s.else)) errors.push(`${path}.else: must be array`);
    else s.else.forEach((ch, i) => {
      const r = validateStep(ch, `${path}.else[${i}]`);
      if (!r.ok) errors.push(...r.errors);
    });
  }

  if (s.steps != null) {
    if (!Array.isArray(s.steps)) errors.push(`${path}.steps: must be array`);
    else s.steps.forEach((ch, i) => {
      const r = validateStep(ch, `${path}.steps[${i}]`);
      if (!r.ok) errors.push(...r.errors);
    });
  }

  if (s.type === 'branch' && !s.branch && s.if == null) {
    errors.push(`${path}: branch requires branch|if condition`);
  }

  if (s.type === 'loop' && !Array.isArray(s.steps)) {
    errors.push(`${path}: loop requires steps[]`);
  }

  if (s.type === 'goto' && s.url == null) {
    errors.push(`${path}: goto requires url`);
  }

  if (
    s.type === 'visit_service'
    && s.service == null
    && s.url == null
    && !Array.isArray(s.services)
    && !s.random
  ) {
    errors.push(`${path}: visit_service requires service|url|services|random`);
  }

  return errors.length ? { ok: false, errors } : { ok: true, step: s };
}

/**
 * @param {unknown} branch
 * @param {string} path
 * @returns {string[]}
 */
function validateBranch(branch, path) {
  if (typeof branch === 'string') return [];
  if (typeof branch === 'boolean') return [];
  if (!branch || typeof branch !== 'object' || Array.isArray(branch)) {
    return [`${path}: expected string|object|boolean`];
  }
  const b = /** @type {Record<string, unknown>} */ (branch);
  const errors = [];
  if (b.op != null && !BRANCH_OPS.includes(String(b.op))) {
    errors.push(`${path}.op: unsupported`);
  }
  if (b.var != null && typeof b.var !== 'string') {
    errors.push(`${path}.var: expected string`);
  }
  return errors;
}

/**
 * @param {unknown} scenario
 * @returns {{ ok: true, scenario: object } | { ok: false, errors: string[] }}
 */
function validateScenario(scenario) {
  const errors = [];
  if (!scenario || typeof scenario !== 'object' || Array.isArray(scenario)) {
    return { ok: false, errors: ['scenario: must be an object'] };
  }
  const sc = /** @type {Record<string, unknown>} */ (scenario);

  if (typeof sc.name !== 'string' || !sc.name) {
    errors.push('name: required');
  }
  if (typeof sc.type !== 'string' || !['warmup', 'pf', 'service', 'register'].includes(sc.type)) {
    errors.push('type: warmup|pf|service|register');
  }
  if (sc.version != null && typeof sc.version !== 'number') {
    errors.push('version: number');
  }
  if (!Array.isArray(sc.steps) || sc.steps.length === 0) {
    errors.push('steps: non-empty array required');
  } else {
    sc.steps.forEach((st, i) => {
      const r = validateStep(st, `steps[${i}]`);
      if (!r.ok) errors.push(...r.errors);
    });
  }

  return errors.length ? { ok: false, errors } : { ok: true, scenario: sc };
}

/**
 * Normalize DB row (scenario_steps.action + params) → runtime step.
 * @param {{ action?: string, type?: string, params?: object, timeout_sec?: number, on_fail?: string }} row
 */
function normalizeDbStep(row) {
  const params = row.params && typeof row.params === 'object' ? { ...row.params } : {};
  const type = row.action || row.type || params.type;
  const step = { ...params, type };
  if (row.timeout_sec != null) step.timeout_sec = Number(row.timeout_sec);
  if (row.on_fail) step.on_fail = row.on_fail;
  return step;
}

module.exports = {
  STEP_TYPES,
  ON_FAIL,
  BRANCH_OPS,
  validateStep,
  validateScenario,
  normalizeDbStep,
};
