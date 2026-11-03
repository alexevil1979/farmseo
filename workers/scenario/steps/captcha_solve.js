'use strict';

const { solveYandex, createSolver } = require('../../lib/captcha');
const yandex = require('../../lib/captcha/yandex');

/**
 * Captcha solver step: detect Yandex SmartCaptcha and solve via CAPTCHA_PROVIDER.
 */
async function captcha_solve(step, ctx) {
  const { page, config } = ctx;

  const detected = await yandex.detect(page);
  if (!detected.present && !step.force) {
    return { solved: false, present: false };
  }

  const key = (config && config.captchaKey) || process.env.CAPTCHA_KEY || '';
  const provider = (config && config.captchaProvider)
    || process.env.CAPTCHA_PROVIDER
    || '2captcha';
  const tokenUrl = (config && config.captchaTokenUrl) || process.env.CAPTCHA_TOKEN_URL || '';

  if (provider !== 'token' && provider !== 'token-provider' && provider !== 'local' && !key) {
    await ctx.log('captcha_needed', 'warn', 'CAPTCHA visible but CAPTCHA_KEY empty', {
      url: page.url(),
      provider,
    });
    if (step.optional) return { solved: false, present: true, skipped: true };
    throw new Error('captcha_solve: captcha present, no CAPTCHA_KEY');
  }
  if ((provider === 'token' || provider === 'token-provider' || provider === 'local') && !tokenUrl) {
    await ctx.log('captcha_needed', 'warn', 'CAPTCHA visible but CAPTCHA_TOKEN_URL empty', {
      url: page.url(),
    });
    if (step.optional) return { solved: false, present: true, skipped: true };
    throw new Error('captcha_solve: captcha present, no CAPTCHA_TOKEN_URL');
  }

  await ctx.log('captcha_solve_attempt', 'info', `Solving via ${provider}`, {
    url: page.url(),
    sitekey: detected.sitekey,
    in_iframe: detected.inIframe,
  });

  const retries = step.retries != null ? Number(step.retries) : 3;
  const timeoutMs = step.timeout_ms != null
    ? Number(step.timeout_ms)
    : (step.max != null ? Number(step.max) * 1000 : 180000);

  try {
    const result = await solveYandex(page, {
      provider,
      apiKey: key,
      tokenUrl,
      sitekey: step.sitekey || detected.sitekey,
      pageurl: step.pageurl || page.url(),
      retries,
      timeoutMs,
      force: Boolean(step.force || detected.present),
      type: step.captcha_type || 'yandex',
      skipWait: Boolean(step.skip_wait),
      clearTimeoutMs: step.clear_timeout_ms != null ? Number(step.clear_timeout_ms) : 10000,
    });

    await ctx.log('captcha_solved', 'info', 'Captcha token injected', {
      provider: result.provider,
      attempts: result.attempts,
      sitekey: result.sitekey,
      cleared: result.cleared,
    });

    return {
      solved: true,
      present: true,
      provider: result.provider,
      attempts: result.attempts,
      sitekey: result.sitekey,
      cleared: result.cleared,
    };
  } catch (err) {
    await ctx.log('captcha_solve_failed', 'error', err.message, {
      provider,
      url: page.url(),
    });
    if (step.optional) {
      return { solved: false, present: true, error: err.message };
    }
    throw err;
  }
}

module.exports = captcha_solve;
module.exports.createSolver = createSolver;
