'use strict';

async function set_cookie(step, ctx) {
  const name = ctx.interpolate(step.name || step.cookie_name);
  const value = ctx.interpolate(step.value != null ? step.value : step.cookie_value);
  if (!name) throw new Error('set_cookie: name required');

  const url = ctx.interpolate(step.url) || ctx.page.url() || 'https://yandex.ru/';
  const cookie = {
    name: String(name),
    value: String(value == null ? '' : value),
    url,
  };
  if (step.domain) cookie.domain = ctx.interpolate(step.domain);
  if (step.path) cookie.path = String(step.path);
  if (step.expires) cookie.expires = Number(step.expires);
  if (step.httpOnly != null) cookie.httpOnly = Boolean(step.httpOnly);
  if (step.secure != null) cookie.secure = Boolean(step.secure);
  if (step.sameSite) cookie.sameSite = step.sameSite;

  await ctx.page.setCookie(cookie);
  return { name: cookie.name, url };
}

module.exports = set_cookie;
