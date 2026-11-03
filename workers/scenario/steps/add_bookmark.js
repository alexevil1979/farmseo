'use strict';

/**
 * Simulate bookmarking via CDP / local storage of bookmarks list in vars.
 * Real Chrome bookmarks UI is not reliably automatable; we persist intent.
 */
async function add_bookmark(step, ctx) {
  const page = ctx.page;
  const url = ctx.interpolate(step.url) || page.url();
  const title = ctx.interpolate(step.title)
    || await page.title().catch(() => url);

  const list = Array.isArray(ctx.get('bookmarks')) ? ctx.get('bookmarks') : [];
  list.push({ url, title, at: new Date().toISOString() });
  ctx.set('bookmarks', list);

  // Best-effort: Ctrl+D is often intercepted; still send for realism
  if (step.hotkey !== false) {
    await page.keyboard.down('Control');
    await page.keyboard.press('KeyD');
    await page.keyboard.up('Control');
    await ctx.sleepMs(200);
    // dismiss possible dialog
    await page.keyboard.press('Escape').catch(() => {});
  }

  return { url, title, count: list.length };
}

module.exports = add_bookmark;
