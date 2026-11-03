'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Persist screenshots and report task result via HTTP + Redis buffer.
 * @param {object} deps
 * @param {ReturnType<import('./config').loadConfig>} deps.config
 * @param {import('ioredis').Redis} deps.redis
 */
function createReporter({ config, redis }) {
  /**
   * @param {number|string} taskId
   * @param {import('puppeteer').Page} page
   * @param {string} [name]
   * @returns {Promise<string>} relative path under storage/screenshots
   */
  async function saveScreenshot(taskId, page, name) {
    const dir = path.join(config.storage.screenshots, String(taskId));
    fs.mkdirSync(dir, { recursive: true });
    const file = name || `shot-${Date.now()}.png`;
    const abs = path.join(dir, file);
    await page.screenshot({ path: abs, fullPage: false, timeout: 10000 });
    return path.posix.join(String(taskId), file);
  }

  /**
   * Canonical result payload (CONTRACT §5).
   * @param {object} result
   */
  function normalize(result) {
    const body = {
      task_id: Number(result.task_id),
      status: String(result.status),
      error: result.error == null ? null : String(result.error),
      metrics: result.metrics && typeof result.metrics === 'object' ? result.metrics : {},
      screenshots: Array.isArray(result.screenshots) ? result.screenshots : [],
      worker_id: config.workerId,
      finished_at: new Date().toISOString(),
    };
    if (result.attempt != null) {
      body.attempt = Number(result.attempt);
    }
    return body;
  }

  /**
   * POST /internal/task/result + LPUSH farm:q:results
   * @param {object} result
   */
  async function report(result) {
    const body = normalize(result);
    const payload = JSON.stringify(body);

    await redis.lpush(config.keys.results, payload);

    let httpOk = false;
    let httpError = null;
    try {
      const res = await fetch(config.resultUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Internal-Token': config.internalToken,
        },
        body: payload,
        signal: AbortSignal.timeout(15000),
      });
      httpOk = res.ok;
      if (!res.ok) {
        httpError = `HTTP ${res.status}: ${await res.text().catch(() => '')}`;
      }
    } catch (err) {
      httpError = err.message;
      // PHP may be down — Redis buffer is the fallback
    }

    return { body, httpOk, httpError };
  }

  return {
    saveScreenshot,
    report,
    normalize,
  };
}

module.exports = { createReporter };
