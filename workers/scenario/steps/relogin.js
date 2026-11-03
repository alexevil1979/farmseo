'use strict';

const { waitReady, humanType, findFirst, humanClick } = require('./_helpers');

const LOGIN_URL = 'https://passport.yandex.ru/auth';

/**
 * Relogin using account credentials from task/payload.
 * Expects payload.login / payload.password or account.login / account.password.
 */
async function relogin(step, ctx) {
  const payload = ctx.task.payload || {};
  const account = ctx.task.account || payload.account || {};
  const login = ctx.interpolate(step.login)
    || payload.login
    || account.login
    || ctx.get('login');
  const password = ctx.interpolate(step.password)
    || payload.password
    || account.password
    || ctx.get('password');

  if (!login || !password) {
    if (step.optional) return { skipped: true, reason: 'no_credentials' };
    throw new Error('relogin: login/password missing');
  }

  await ctx.page.goto(step.url || LOGIN_URL, {
    waitUntil: 'domcontentloaded',
    timeout: (step.timeout_sec || 60) * 1000,
  });
  await waitReady(ctx.page);

  const loginSel = await findFirst(ctx.page, [
    step.login_selector,
    'input[name="login"]',
    '#passp-field-login',
    'input[type="text"]',
  ].filter(Boolean));

  if (!loginSel) throw new Error('relogin: login field not found');
  await humanType(ctx, loginSel.selector, String(login));
  await ctx.sleepMs(300);

  // submit login → password step
  const nextBtn = await findFirst(ctx.page, [
    'button[type="submit"]',
    'button.Button',
    '#passp\\:sign-in',
  ]);
  if (nextBtn) await humanClick(ctx, nextBtn.selector);
  await ctx.page.waitForSelector('input[name="passwd"], #passp-field-passwd, input[type="password"]', {
    timeout: 15000,
  }).catch(() => {});

  const passSel = await findFirst(ctx.page, [
    step.password_selector,
    'input[name="passwd"]',
    '#passp-field-passwd',
    'input[type="password"]',
  ].filter(Boolean));

  if (!passSel) throw new Error('relogin: password field not found');
  await humanType(ctx, passSel.selector, String(password));
  await ctx.sleepMs(200);

  const submit = await findFirst(ctx.page, [
    'button[type="submit"]',
    '#passp\\:sign-in',
    'button.Button',
  ]);
  if (submit) await humanClick(ctx, submit.selector);

  await ctx.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
  await waitReady(ctx.page);
  ctx.set('url', ctx.page.url());
  ctx.set('logged_in', true);
  return { login: String(login), url: ctx.page.url() };
}

module.exports = relogin;
