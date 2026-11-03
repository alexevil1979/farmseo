'use strict';

const fs = require('fs');
const os = require('os');

/**
 * Sample CPU%/RSS for a Chrome process (Linux /proc; best-effort elsewhere).
 * @param {number|null|undefined} pid
 * @returns {{ pid: number|null, cpu_pct: number|null, ram_mb: number|null, ram_pct: number|null }}
 */
function samplePid(pid) {
  const out = { pid: pid != null ? Number(pid) : null, cpu_pct: null, ram_mb: null, ram_pct: null };
  if (!out.pid || !Number.isFinite(out.pid) || out.pid <= 0) {
    return out;
  }

  if (process.platform === 'linux') {
    try {
      const stat = fs.readFileSync(`/proc/${out.pid}/stat`, 'utf8').trim();
      const parts = stat.split(' ');
      // fields: utime(13) stime(14) — 1-indexed → parts[13], parts[14]
      const utime = Number(parts[13] || 0);
      const stime = Number(parts[14] || 0);
      const totalJiffies = utime + stime;
      const prev = samplePid._prev.get(out.pid);
      const now = Date.now();
      const hz = 100;
      if (prev) {
        const dJ = totalJiffies - prev.jiffies;
        const dT = (now - prev.ts) / 1000;
        if (dT > 0 && dJ >= 0) {
          out.cpu_pct = Math.round(Math.min(100 * os.cpus().length, (dJ / hz / dT) * 100) * 10) / 10;
        }
      }
      samplePid._prev.set(out.pid, { jiffies: totalJiffies, ts: now });

      const status = fs.readFileSync(`/proc/${out.pid}/status`, 'utf8');
      const m = status.match(/^VmRSS:\s+(\d+)\s+kB/m);
      if (m) {
        const kb = Number(m[1]);
        out.ram_mb = Math.round((kb / 1024) * 10) / 10;
        const totalMb = os.totalmem() / (1024 * 1024);
        out.ram_pct = totalMb > 0 ? Math.round((out.ram_mb / totalMb) * 1000) / 10 : null;
      }
    } catch (_) {
      /* process may have exited */
    }
    return out;
  }

  // Non-Linux: no per-pid CPU; leave nulls (panel still shows account slots).
  return out;
}
samplePid._prev = new Map();

/**
 * @param {Array<{ account_id: number, pid?: number|null, task_id?: number|null, worker_id?: string }>} entries
 */
function sampleChromeList(entries) {
  return (entries || []).map((e) => {
    const stats = samplePid(e.pid);
    return {
      account_id: e.account_id,
      task_id: e.task_id ?? null,
      worker_id: e.worker_id ?? null,
      pid: stats.pid,
      cpu_pct: stats.cpu_pct,
      ram_mb: stats.ram_mb,
      ram_pct: stats.ram_pct,
    };
  });
}

module.exports = { samplePid, sampleChromeList };
