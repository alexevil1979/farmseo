'use strict';

const { spawn } = require('child_process');
const fs = require('fs');
const http = require('http');
const net = require('net');
const path = require('path');

/**
 * Per-session virtual display stack: Xvfb + x11vnc + websockify.
 *
 * Offset N (0..max-1):
 *   DISPLAY     = :(displayBase + N)
 *   vnc_port    = VNC_BASE_PORT + N
 *   ws_port     = WEBSOCKIFY_BASE_PORT + N  (default 6080)
 *
 * All listeners bind to 127.0.0.1; the panel / worker proxies externally.
 *
 * @param {object} config
 * @param {number} [config.vncBasePort=5900]
 * @param {number} [config.displayBase=99]
 * @param {number} [config.websockifyBasePort=6080]
 * @param {number} [config.maxChrome]
 * @param {number} [config.slots]
 * @param {string} [config.xvfbScreen]
 * @param {string} [config.novncWebPath]
 * @param {boolean} [config.displayDryRun]
 */
function createDisplayManager(config = {}) {
  const displayBase = int(config.displayBase, 99);
  const vncBase = int(config.vncBasePort, 5900);
  const wsBase = int(config.websockifyBasePort, 6080);
  const maxSessions = Math.max(1, int(config.maxChrome, int(config.slots, 40)));
  const screen = config.xvfbScreen || '1920x1080x24';
  const novncWeb = config.novncWebPath || detectNovncWeb();
  const dryRun = Boolean(
    config.displayDryRun
    || process.env.DISPLAY_DRY_RUN === '1'
    || process.platform === 'win32',
  );

  /** @type {Map<number, DisplaySession>} */
  const byAccount = new Map();
  /** @type {Set<number>} */
  const usedOffsets = new Set();

  /**
   * @typedef {object} DisplaySession
   * @property {number} accountId
   * @property {number} offset
   * @property {number} display
   * @property {number} vncPort
   * @property {number} wsPort
   * @property {string} displayEnv
   * @property {import('child_process').ChildProcess[]} procs
   * @property {number} startedAt
   * @property {boolean} dryRun
   */

  function allocateOffset() {
    for (let i = 0; i < maxSessions; i++) {
      if (!usedOffsets.has(i)) {
        usedOffsets.add(i);
        return i;
      }
    }
    const err = new Error(`no free display slots (max=${maxSessions})`);
    err.code = 'DISPLAY_FULL';
    throw err;
  }

  /**
   * Start (or reuse) Xvfb/x11vnc/websockify for an account.
   * @param {number} accountId
   */
  async function start(accountId) {
    const id = Number(accountId);
    if (!Number.isFinite(id) || id <= 0) {
      throw new Error('account_id required');
    }

    const existing = byAccount.get(id);
    if (existing) {
      return summarize(existing);
    }

    const offset = allocateOffset();
    /** @type {DisplaySession} */
    const entry = {
      accountId: id,
      offset,
      display: displayBase + offset,
      vncPort: vncBase + offset,
      wsPort: wsBase + offset,
      displayEnv: `:${displayBase + offset}`,
      procs: [],
      startedAt: Date.now(),
      dryRun,
    };

    try {
      if (dryRun) {
        byAccount.set(id, entry);
        return summarize(entry);
      }

      await assertPortFree(entry.vncPort);
      await assertPortFree(entry.wsPort);

      await startXvfb(entry, screen);
      await waitForDisplay(entry.display, 8000);
      await startX11vnc(entry);
      await waitForPort(entry.vncPort, 8000);
      await startWebsockify(entry, novncWeb);
      await waitForPort(entry.wsPort, 8000);

      byAccount.set(id, entry);
      return summarize(entry);
    } catch (err) {
      await killEntry(entry);
      usedOffsets.delete(offset);
      throw err;
    }
  }

  /**
   * Stop stack and free ports for account.
   * @param {number} accountId
   * @returns {Promise<boolean>} true if a session was released
   */
  async function stop(accountId) {
    const id = Number(accountId);
    const entry = byAccount.get(id);
    if (!entry) return false;
    await killEntry(entry);
    usedOffsets.delete(entry.offset);
    byAccount.delete(id);
    return true;
  }

  /** @param {number} accountId */
  function get(accountId) {
    return byAccount.get(Number(accountId)) || null;
  }

  /** @returns {number[]} */
  function busyPorts() {
    const ports = [];
    for (const e of byAccount.values()) {
      ports.push(e.vncPort, e.wsPort);
    }
    return ports.sort((a, b) => a - b);
  }

  function list() {
    return [...byAccount.values()].map(summarize);
  }

  async function releaseAll() {
    const ids = [...byAccount.keys()];
    for (const id of ids) {
      await stop(id);
    }
  }

  /**
   * @param {DisplaySession} entry
   */
  function summarize(entry) {
    return {
      account_id: entry.accountId,
      display: entry.display,
      display_env: entry.displayEnv,
      vnc_port: entry.vncPort,
      ws_port: entry.wsPort,
      offset: entry.offset,
      dry_run: entry.dryRun,
      started_at: entry.startedAt,
      // path=live/{id}/ is REQUIRED: stock noVNC builds wss://host/<path>
      // and defaults to /websockify (404 behind our /live/ proxy).
      novnc_path: `/live/${entry.accountId}/vnc.html?autoconnect=1&resize=scale&reconnect=true&path=${encodeURIComponent(`live/${entry.accountId}/`)}`,
    };
  }

  /**
   * @param {DisplaySession} entry
   * @param {string} screenGeom
   */
  async function startXvfb(entry, screenGeom) {
    const proc = spawn(
      'Xvfb',
      [entry.displayEnv, '-screen', '0', screenGeom, '-ac', '-nolisten', 'tcp', '+extension', 'RANDR'],
      { stdio: ['ignore', 'ignore', 'pipe'], detached: false },
    );
    track(entry, proc, 'Xvfb');
    await sleep(200);
    if (proc.exitCode !== null) {
      throw new Error(`Xvfb exited early code=${proc.exitCode}`);
    }
  }

  /**
   * @param {DisplaySession} entry
   */
  async function startX11vnc(entry) {
    const proc = spawn(
      'x11vnc',
      [
        '-display', entry.displayEnv,
        '-rfbport', String(entry.vncPort),
        '-localhost',
        '-forever',
        '-shared',
        '-nopw',
        '-xkb',
        '-ncache', '10',
        '-ncache_cr',
        '-quiet',
      ],
      { stdio: ['ignore', 'ignore', 'pipe'], detached: false },
    );
    track(entry, proc, 'x11vnc');
  }

  /**
   * @param {DisplaySession} entry
   * @param {string|null} webRoot
   */
  async function startWebsockify(entry, webRoot) {
    const args = [];
    if (webRoot && fs.existsSync(webRoot)) {
      args.push(`--web=${webRoot}`);
    }
    // websockify [opts] [source_addr:]source_port target_addr:target_port
    args.push(`127.0.0.1:${entry.wsPort}`);
    args.push(`localhost:${entry.vncPort}`);

    // Prefer websockify CLI; fall back to python module.
    let cmd = 'websockify';
    let cmdArgs = args;
    if (!commandExists('websockify')) {
      cmd = 'python3';
      cmdArgs = ['-m', 'websockify', ...args];
    }

    const proc = spawn(cmd, cmdArgs, {
      stdio: ['ignore', 'ignore', 'pipe'],
      detached: false,
    });
    track(entry, proc, 'websockify');
  }

  /**
   * @param {DisplaySession} entry
   * @param {import('child_process').ChildProcess} proc
   * @param {string} label
   */
  function track(entry, proc, label) {
    entry.procs.push(proc);
    let errBuf = '';
    if (proc.stderr) {
      proc.stderr.on('data', (chunk) => {
        errBuf += String(chunk);
        if (errBuf.length > 4000) errBuf = errBuf.slice(-2000);
      });
    }
    proc.on('exit', (code, signal) => {
      if (byAccount.get(entry.accountId) === entry && code && code !== 0) {
        console.warn(`[display] ${label} for account ${entry.accountId} exited code=${code} signal=${signal} ${errBuf.slice(0, 200)}`);
      }
    });
  }

  /**
   * @param {DisplaySession} entry
   */
  async function killEntry(entry) {
    const procs = [...entry.procs].reverse();
    entry.procs = [];
    for (const proc of procs) {
      await killProcessTree(proc);
    }
    // Extra safety: free ports if orphans linger
    if (!entry.dryRun) {
      await killListenersOnPort(entry.vncPort);
      await killListenersOnPort(entry.wsPort);
      await killXvfbDisplay(entry.display);
    }
  }

  return {
    start,
    stop,
    get,
    busyPorts,
    list,
    releaseAll,
    isDryRun: () => dryRun,
  };
}

/**
 * Reverse-proxy WebSocket upgrades from /live/:accountId/* → websockify.
 * HTTP is handled via mountLiveProxyRoutes on Express.
 *
 * @param {import('http').Server} server
 * @param {{ get: (id: number) => { wsPort: number, dryRun?: boolean }|null }} displays
 */
function attachLiveProxy(server, displays) {
  server.on('upgrade', (req, socket, head) => {
    const parsed = parseLivePath(req.url || '');
    if (!parsed) return;
    const session = displays.get(parsed.accountId);
    if (!session || session.dryRun) {
      socket.write('HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n');
      socket.destroy();
      return;
    }
    proxyWebSocket(req, socket, head, session.wsPort, parsed.restPath);
  });
}

/**
 * Express middleware: proxy /live/:accountId/* → local websockify.
 * @param {import('express').Express} app
 * @param {{ get: (id: number) => { wsPort: number, dryRun?: boolean }|null }} displays
 */
function mountLiveProxyRoutes(app, displays) {
  app.use('/live/:accountId', (req, res) => {
    const accountId = Number(req.params.accountId);
    const session = displays.get(accountId);
    if (!session) {
      return res.status(404).json({ ok: false, error: 'live session not found' });
    }
    if (session.dryRun) {
      return res.status(503).json({
        ok: false,
        error: 'display dry-run (no websockify); install Xvfb/x11vnc/websockify on Linux',
      });
    }
    const rest = req.url || '/';
    const targetPath = rest.startsWith('/') ? rest : `/${rest}`;
    proxyHttp(req, res, session.wsPort, targetPath);
  });
}

/**
 * @param {import('http').IncomingMessage} req
 * @param {import('http').ServerResponse} res
 * @param {number} port
 * @param {string} targetPath
 */
function proxyHttp(req, res, port, targetPath) {
  const headers = { ...req.headers, host: `127.0.0.1:${port}` };
  delete headers['content-length'];
  const preq = http.request(
    {
      hostname: '127.0.0.1',
      port,
      path: targetPath,
      method: req.method,
      headers,
    },
    (pres) => {
      res.writeHead(pres.statusCode || 502, pres.headers);
      pres.pipe(res);
    },
  );
  preq.on('error', (err) => {
    if (!res.headersSent) {
      res.statusCode = 502;
      res.setHeader('Content-Type', 'application/json');
      res.end(JSON.stringify({ ok: false, error: err.message }));
    } else {
      res.end();
    }
  });
  req.pipe(preq);
}

/**
 * @param {import('http').IncomingMessage} req
 * @param {import('net').Socket} socket
 * @param {Buffer} head
 * @param {number} port
 * @param {string} targetPath
 */
function proxyWebSocket(req, socket, head, port, targetPath) {
  const headers = { ...req.headers, host: `127.0.0.1:${port}` };
  const preq = http.request({
    hostname: '127.0.0.1',
    port,
    path: targetPath,
    method: 'GET',
    headers,
  });
  preq.on('upgrade', (pres, psocket, phead) => {
    const lines = [`HTTP/1.1 ${pres.statusCode} Switching Protocols`];
    for (let i = 0; i < pres.rawHeaders.length; i += 2) {
      lines.push(`${pres.rawHeaders[i]}: ${pres.rawHeaders[i + 1]}`);
    }
    socket.write(lines.join('\r\n') + '\r\n\r\n');
    if (phead && phead.length) socket.write(phead);
    psocket.pipe(socket);
    socket.pipe(psocket);
    psocket.on('error', () => socket.destroy());
    socket.on('error', () => psocket.destroy());
  });
  preq.on('error', () => {
    try {
      socket.write('HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n');
    } catch (_) { /* ignore */ }
    socket.destroy();
  });
  preq.end();
  if (head && head.length) {
    // head already consumed by http parser on upgrade; upstream gets empty body
  }
}

/**
 * @param {string} url
 * @returns {{ accountId: number, restPath: string }|null}
 */
function parseLivePath(url) {
  const q = url.indexOf('?');
  const pathOnly = q === -1 ? url : url.slice(0, q);
  const m = pathOnly.match(/^\/live\/(\d+)(\/.*)?$/);
  if (!m) return null;
  const rest = (m[2] || '/') + (q === -1 ? '' : url.slice(q));
  return { accountId: Number(m[1]), restPath: rest || '/' };
}

function detectNovncWeb() {
  const candidates = [
    '/usr/share/novnc',
    '/usr/share/novnc/',
    path.join('/usr', 'share', 'novnc'),
  ];
  for (const c of candidates) {
    if (fs.existsSync(path.join(c, 'vnc.html')) || fs.existsSync(path.join(c, 'vnc_lite.html'))) {
      return c;
    }
  }
  return null;
}

function commandExists(bin) {
  const exts = process.platform === 'win32' ? ['.exe', '.cmd', ''] : [''];
  const dirs = (process.env.PATH || '').split(path.delimiter);
  for (const dir of dirs) {
    for (const ext of exts) {
      try {
        if (fs.existsSync(path.join(dir, bin + ext))) return true;
      } catch (_) { /* ignore */ }
    }
  }
  return false;
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function int(v, fallback) {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isFinite(n) ? n : fallback;
}

function assertPortFree(port) {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.once('error', (err) => {
      reject(new Error(`port ${port} busy: ${err.message}`));
    });
    srv.once('listening', () => {
      srv.close(() => resolve());
    });
    srv.listen(port, '127.0.0.1');
  });
}

function waitForPort(port, timeoutMs) {
  const started = Date.now();
  return new Promise((resolve, reject) => {
    const tryOnce = () => {
      const sock = net.connect({ port, host: '127.0.0.1' }, () => {
        sock.end();
        resolve();
      });
      sock.on('error', () => {
        sock.destroy();
        if (Date.now() - started > timeoutMs) {
          reject(new Error(`timeout waiting for port ${port}`));
        } else {
          setTimeout(tryOnce, 150);
        }
      });
    };
    tryOnce();
  });
}

function waitForDisplay(displayNum, timeoutMs) {
  const sockPath = `/tmp/.X11-unix/X${displayNum}`;
  const started = Date.now();
  return new Promise((resolve, reject) => {
    const tick = () => {
      if (fs.existsSync(sockPath)) return resolve();
      if (Date.now() - started > timeoutMs) {
        return reject(new Error(`timeout waiting for DISPLAY :${displayNum}`));
      }
      setTimeout(tick, 100);
    };
    tick();
  });
}

/**
 * @param {import('child_process').ChildProcess} proc
 */
function killProcessTree(proc) {
  return new Promise((resolve) => {
    if (!proc || proc.exitCode !== null || proc.killed) return resolve();
    const done = () => resolve();
    proc.once('exit', done);
    try {
      proc.kill('SIGTERM');
    } catch (_) {
      return resolve();
    }
    setTimeout(() => {
      try {
        if (proc.exitCode === null) proc.kill('SIGKILL');
      } catch (_) { /* ignore */ }
      resolve();
    }, 1500).unref?.();
  });
}

async function killListenersOnPort(port) {
  if (process.platform === 'win32') return;
  try {
    const { execFile } = require('child_process');
    await new Promise((resolve) => {
      execFile('fuser', ['-k', `${port}/tcp`], { timeout: 3000 }, () => resolve());
    });
  } catch (_) { /* optional */ }
}

async function killXvfbDisplay(displayNum) {
  if (process.platform === 'win32') return;
  try {
    const { execFile } = require('child_process');
    await new Promise((resolve) => {
      execFile('pkill', ['-f', `Xvfb :${displayNum}`], { timeout: 3000 }, () => resolve());
    });
  } catch (_) { /* optional */ }
}

module.exports = {
  createDisplayManager,
  attachLiveProxy,
  mountLiveProxyRoutes,
  parseLivePath,
};
