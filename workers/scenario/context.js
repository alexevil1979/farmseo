'use strict';

const path = require('path');
const fs = require('fs');
const { resolveRange } = require('./random');

/**
 * Build mutable execution context for a scenario run.
 * @param {object} opts
 */
function createContext(opts) {
  const {
    task,
    browser,
    page,
    reporter,
    config,
    human,
    scenario,
    taskRunId = null,
    eventSink = null,
    fast = false,
  } = opts;

  const payload = task.payload || {};
  const defaults = (scenario && scenario.defaults) || {};
  const limits = {
    ...(defaults.limits || {}),
    ...(payload.limits || {}),
  };

  const preferMin = Boolean(fast || limits.fast || process.env.SCENARIO_FAST === '1');

  const vars = {
    account_id: task.account_id ?? payload.account_id ?? null,
    task_id: task.task_id ?? task.id ?? null,
    scenario_id: task.scenario_id ?? payload.scenario_id ?? null,
    keyword: payload.keyword || defaults.keyword || null,
    target_url: payload.target_url || defaults.target_url || null,
    geo: payload.geo || defaults.geo || 'ru',
    device: payload.device || defaults.device || 'desktop',
    day: Number(payload.day ?? payload.warmup_day ?? defaults.day ?? 1),
    query: payload.keyword || null,
    ...flattenDefaults(defaults.vars),
    ...(payload.vars || {}),
  };

  const queries = Array.isArray(defaults.queries) ? defaults.queries.slice() : [];
  if (payload.keyword && !queries.includes(payload.keyword)) {
    queries.unshift(payload.keyword);
  }

  const screenshots = [];
  const metrics = {
    steps_ok: 0,
    steps_fail: 0,
    steps_skip: 0,
    loops: 0,
    branches: 0,
  };
  const events = [];

  /** @type {import('puppeteer').Page} */
  let activePage = page;

  const ctx = {
    task,
    browser,
    get page() {
      return activePage;
    },
    set page(p) {
      activePage = p;
    },
    reporter,
    config,
    human,
    scenario,
    taskRunId,
    vars,
    queries,
    limits,
    preferMin,
    screenshots,
    metrics,
    events,
    startedAt: Date.now(),

    get(name, fallback = undefined) {
      if (Object.prototype.hasOwnProperty.call(vars, name)) return vars[name];
      return fallback;
    },

    set(name, value) {
      vars[name] = value;
      return value;
    },

    /**
     * Interpolate {{var}} in strings.
     * @param {unknown} value
     */
    interpolate(value) {
      if (typeof value !== 'string') return value;
      return value.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, key) => {
        if (key === 'random_query') {
          const q = queries.length
            ? queries[Math.floor(Math.random() * queries.length)]
            : vars.keyword || 'новости';
          return String(q);
        }
        const parts = key.split('.');
        let cur = vars;
        for (const p of parts) {
          if (cur == null) return '';
          cur = cur[p];
        }
        return cur == null ? '' : String(cur);
      });
    },

    range(step, fallback = 0) {
      return resolveRange(step, { preferMin, fallback });
    },

    async sleepMs(ms) {
      const n = preferMin ? Math.min(Number(ms) || 0, 50) : Number(ms) || 0;
      if (n <= 0) return;
      if (human && typeof human.sleep === 'function') await human.sleep(n);
      else await new Promise((r) => setTimeout(r, n));
    },

    async log(event, level = 'info', message = null, data = null) {
      const entry = {
        event: String(event).slice(0, 64),
        level: String(level),
        message: message != null ? String(message).slice(0, 1024) : null,
        data: data && typeof data === 'object' ? data : null,
        ts: new Date().toISOString(),
      };
      events.push(entry);
      if (typeof eventSink === 'function') {
        try {
          await eventSink(entry);
        } catch (err) {
          // never break the run on logging failure
          console.warn('[scenario] eventSink error:', err.message);
        }
      }
      return entry;
    },

    async screenshot(name) {
      const taskId = vars.task_id;
      // Recover page if prior step detached/closed the tab (fail shots otherwise vanish).
      try {
        const { ensureLivePage } = require('./steps/_helpers');
        await ensureLivePage(ctx);
      } catch (err) {
        console.warn('[scenario] screenshot: no live page:', err.message);
        return null;
      }
      if (!activePage) return null;
      const take = async () => {
        let rel = null;
        if (reporter && typeof reporter.saveScreenshot === 'function' && taskId != null) {
          rel = await reporter.saveScreenshot(taskId, activePage, name || `step-${Date.now()}.png`);
        } else if (config && config.storage && config.storage.screenshots) {
          const dir = path.join(config.storage.screenshots, String(taskId || 'adhoc'));
          fs.mkdirSync(dir, { recursive: true });
          const file = name || `shot-${Date.now()}.png`;
          const abs = path.join(dir, file);
          await activePage.screenshot({ path: abs, fullPage: false, timeout: 10000 });
          rel = path.posix.join(String(taskId || 'adhoc'), file);
        }
        return rel;
      };
      let rel = null;
      try {
        rel = await Promise.race([
          take(),
          new Promise((_, rej) => setTimeout(() => rej(new Error('screenshot timeout')), 12000)),
        ]);
      } catch (err) {
        console.warn('[scenario] screenshot failed:', err.message);
        return null;
      }
      if (rel) screenshots.push(rel);
      return rel;
    },
  };

  return ctx;
}

function flattenDefaults(vars) {
  if (!vars || typeof vars !== 'object') return {};
  return { ...vars };
}

/**
 * Evaluate branch condition against ctx.vars.
 * @param {string|boolean|object} branch
 * @param {object} ctx
 */
function evalBranch(branch, ctx) {
  if (typeof branch === 'boolean') return branch;
  if (branch == null) return false;

  if (typeof branch === 'string') {
    return evalExpr(branch, ctx.vars);
  }

  const b = branch;
  const left = b.var != null
    ? ctx.get(b.var)
    : (b.left !== undefined ? b.left : b.value);
  const right = b.right !== undefined
    ? b.right
    : (b.var != null ? b.value : undefined);
  const op = b.op || 'truthy';

  switch (op) {
    case 'eq': return left == right; // eslint-disable-line eqeqeq
    case 'neq': return left != right; // eslint-disable-line eqeqeq
    case 'gt': return Number(left) > Number(right);
    case 'gte': return Number(left) >= Number(right);
    case 'lt': return Number(left) < Number(right);
    case 'lte': return Number(left) <= Number(right);
    case 'in': return Array.isArray(right) && right.includes(left);
    case 'exists': return left !== undefined && left !== null && left !== '';
    case 'falsy': return !left;
    case 'truthy':
    default:
      return left === undefined ? true : Boolean(left);
  }
}

/**
 * Very small safe expression evaluator for "day >= 8 && geo == ru".
 * Supports: numbers, strings in quotes, vars, comparisons, && || ! ()
 */
function evalExpr(expr, vars) {
  const tokens = tokenize(expr);
  let i = 0;

  function peek() { return tokens[i]; }
  function next() { return tokens[i++]; }

  function parsePrimary() {
    const t = next();
    if (!t) return false;
    if (t === '(') {
      const v = parseOr();
      if (next() !== ')') throw new Error('unclosed (');
      return v;
    }
    if (t === '!') return !parsePrimary();
    if (t === 'true') return true;
    if (t === 'false') return false;
    if (/^".*"$/.test(t) || /^'.*'$/.test(t)) return t.slice(1, -1);
    if (/^-?\d+(\.\d+)?$/.test(t)) return Number(t);
    if (Object.prototype.hasOwnProperty.call(vars, t)) return vars[t];
    return undefined;
  }

  function parseCmp() {
    let left = parsePrimary();
    while (peek() && ['==', '!=', '>=', '<=', '>', '<'].includes(peek())) {
      const op = next();
      const right = parsePrimary();
      switch (op) {
        case '==': left = left == right; break; // eslint-disable-line eqeqeq
        case '!=': left = left != right; break; // eslint-disable-line eqeqeq
        case '>=': left = Number(left) >= Number(right); break;
        case '<=': left = Number(left) <= Number(right); break;
        case '>': left = Number(left) > Number(right); break;
        case '<': left = Number(left) < Number(right); break;
        default: break;
      }
    }
    return left;
  }

  function parseAnd() {
    let left = parseCmp();
    while (peek() === '&&') {
      next();
      left = Boolean(left) && Boolean(parseCmp());
    }
    return left;
  }

  function parseOr() {
    let left = parseAnd();
    while (peek() === '||') {
      next();
      left = Boolean(left) || Boolean(parseAnd());
    }
    return left;
  }

  try {
    return Boolean(parseOr());
  } catch (_) {
    return false;
  }
}

function tokenize(expr) {
  const re = /\s*(&&|\|\||==|!=|>=|<=|<|>|!|\(|\)|"[^"]*"|'[^']*'|[A-Za-z_][\w.]*|-?\d+(?:\.\d+)?)\s*/g;
  const out = [];
  let m;
  while ((m = re.exec(expr)) !== null) {
    out.push(m[1]);
  }
  return out;
}

module.exports = {
  createContext,
  evalBranch,
  evalExpr,
};
