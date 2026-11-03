'use strict';

const { humanType } = require('./_helpers');

async function type_human(step, ctx) {
  const selector = ctx.interpolate(step.selector);
  const text = ctx.interpolate(step.text || step.value || '');
  if (!selector) throw new Error('type_human: selector required');
  await humanType(ctx, selector, String(text));
  return { selector, length: String(text).length };
}

module.exports = type_human;
