'use strict';

const http = require('http');
const express = require('express');
const { mountLiveProxyRoutes, attachLiveProxy } = require('./display');

/**
 * Internal Express API on :3000 (CONTRACT §5).
 *
 * @param {object} opts
 * @param {ReturnType<import('./config').loadConfig>} opts.config
 * @param {() => { workers: number, chrome: number, shuttingDown?: boolean }} opts.getStats
 * @param {(accountId: number) => Promise<{ vnc_port: number, ws_port?: number, display?: number, novnc_path?: string }>} [opts.onSessionStart]
 * @param {(accountId: number) => Promise<void>} [opts.onSessionStop]
 * @param {(accountId: number) => Promise<{ path: string, filename: string, mtime?: number }>} [opts.onScreenshot]
 * @param {(body: object) => Promise<object>} [opts.onTaskResult]
 * @param {{ get: (id: number) => object|null }} [opts.displays]
 */
function createHttpServer(opts) {
  const {
    config,
    getStats,
    onSessionStart,
    onSessionStop,
    onScreenshot,
    onTaskResult,
    displays,
  } = opts;

  const app = express();
  app.disable('x-powered-by');
  app.use(express.json({ limit: '2mb' }));

  app.use('/internal', (req, res, next) => {
    const token = req.get('X-Internal-Token');
    if (!token || token !== config.internalToken) {
      return res.status(401).json({ ok: false, error: 'unauthorized' });
    }
    return next();
  });

  app.get('/internal/health', (_req, res) => {
    const stats = getStats();
    res.json({
      ok: !stats.shuttingDown,
      workers: stats.workers,
      chrome: stats.chrome,
      worker_id: config.workerId,
      chrome_sessions: Array.isArray(stats.chrome_sessions) ? stats.chrome_sessions : [],
      busy_ports: displays && typeof displays.busyPorts === 'function'
        ? displays.busyPorts()
        : [],
    });
  });

  app.post('/internal/session/start', async (req, res) => {
    if (!req.is('application/json') || !req.body || typeof req.body !== 'object') {
      return res.status(400).json({ ok: false, error: 'invalid body' });
    }
    const accountId = Number(req.body.account_id);
    if (!Number.isFinite(accountId) || accountId <= 0) {
      return res.status(400).json({ ok: false, error: 'account_id required' });
    }
    try {
      const result = onSessionStart
        ? await onSessionStart(accountId)
        : { vnc_port: config.vncBasePort + (accountId % 1000) };
      return res.json({
        vnc_port: result.vnc_port,
        ws_port: result.ws_port ?? null,
        display: result.display ?? null,
        novnc_path: result.novnc_path ?? null,
      });
    } catch (err) {
      return res.status(500).json({ ok: false, error: err.message });
    }
  });

  app.post('/internal/session/stop', async (req, res) => {
    if (!req.is('application/json') || !req.body || typeof req.body !== 'object') {
      return res.status(400).json({ ok: false, error: 'invalid body' });
    }
    const accountId = Number(req.body.account_id);
    if (!Number.isFinite(accountId) || accountId <= 0) {
      return res.status(400).json({ ok: false, error: 'account_id required' });
    }
    try {
      if (onSessionStop) await onSessionStop(accountId);
      return res.json({ ok: true });
    } catch (err) {
      return res.status(500).json({ ok: false, error: err.message });
    }
  });

  /** Fresh screenshot for account — independent of live-view / VNC. */
  app.post('/internal/account/screenshot', async (req, res) => {
    if (!req.is('application/json') || !req.body || typeof req.body !== 'object') {
      return res.status(400).json({ ok: false, error: 'invalid body' });
    }
    const accountId = Number(req.body.account_id);
    if (!Number.isFinite(accountId) || accountId <= 0) {
      return res.status(400).json({ ok: false, error: 'account_id required' });
    }
    if (!onScreenshot) {
      return res.status(501).json({ ok: false, error: 'screenshot not implemented' });
    }
    try {
      const result = await onScreenshot(accountId);
      return res.json({ ok: true, ...result });
    } catch (err) {
      return res.status(500).json({ ok: false, error: err.message });
    }
  });

  /**
   * Accept result posts (local sink / PHP mirror). Always returns CONTRACT shape.
   * Canonical ack path for the worker process itself is lib/reporter.js → PHP + Redis.
   */
  app.post('/internal/task/result', async (req, res) => {
    if (!req.is('application/json') || !req.body || typeof req.body !== 'object') {
      return res.status(400).json({ ok: false, error: 'invalid body' });
    }
    const { task_id: taskId, status } = req.body;
    if (taskId == null || !status) {
      return res.status(400).json({ ok: false, error: 'task_id and status required' });
    }
    try {
      if (onTaskResult) await onTaskResult(req.body);
      return res.json({ ok: true });
    } catch (err) {
      return res.status(500).json({ ok: false, error: err.message });
    }
  });

  if (displays) {
    mountLiveProxyRoutes(app, displays);
  }

  /** @type {import('http').Server|null} */
  let server = null;

  function listen() {
    return new Promise((resolve, reject) => {
      server = http.createServer(app);
      if (displays) {
        attachLiveProxy(server, displays);
      }
      server.listen(config.httpPort, '127.0.0.1', () => {
        resolve(server);
      });
      server.on('error', reject);
    });
  }

  function close() {
    return new Promise((resolve) => {
      if (!server) return resolve();
      server.close(() => resolve());
    });
  }

  return { app, listen, close, getServer: () => server };
}

module.exports = { createHttpServer };
