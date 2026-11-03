'use strict';

async function assertStep(step, ctx) {
  const { page } = ctx;

  if (step.selector) {
    const sel = ctx.interpolate(step.selector);
    const el = await page.$(sel);
    if (!el) throw new Error(`assert: selector missing: ${sel}`);
  }

  if (step.url_includes) {
    const needle = ctx.interpolate(step.url_includes);
    if (!page.url().includes(String(needle))) {
      throw new Error(`assert: url does not include "${needle}" (got ${page.url()})`);
    }
  }

  if (step.url_matches) {
    const re = new RegExp(ctx.interpolate(step.url_matches));
    if (!re.test(page.url())) {
      throw new Error(`assert: url !~ /${step.url_matches}/`);
    }
  }

  if (step.title_includes) {
    const title = await page.title();
    const needle = ctx.interpolate(step.title_includes);
    if (!title.includes(String(needle))) {
      throw new Error(`assert: title missing "${needle}"`);
    }
  }

  if (step.var != null) {
    const v = ctx.get(step.var);
    if (step.equals !== undefined && v != step.equals) { // eslint-disable-line eqeqeq
      throw new Error(`assert: var ${step.var}=${v} != ${step.equals}`);
    }
    if (step.exists && (v === undefined || v === null || v === '')) {
      throw new Error(`assert: var ${step.var} empty`);
    }
  }

  if (step.text_includes) {
    const needle = ctx.interpolate(step.text_includes);
    const body = await page.evaluate(() => document.body && document.body.innerText
      ? document.body.innerText.slice(0, 50000)
      : '');
    if (!body.includes(String(needle))) {
      throw new Error(`assert: page text missing "${needle}"`);
    }
  }

  return { ok: true };
}

module.exports = assertStep;
