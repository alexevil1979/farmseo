'use strict';

const { STEP_TYPES } = require('../schema');

const handlers = {
  goto: require('./goto'),
  search_yandex: require('./search_yandex'),
  type_human: require('./type_human'),
  click_selector: require('./click_selector'),
  click_serp_position: require('./click_serp_position'),
  scroll: require('./scroll'),
  dwell: require('./dwell'),
  mouse_move: require('./mouse_move'),
  hover: require('./hover'),
  open_new_tab: require('./open_new_tab'),
  internal_click: require('./internal_click'),
  back: require('./back'),
  add_bookmark: require('./add_bookmark'),
  read_article: require('./read_article'),
  visit_service: require('./visit_service'),
  watch_video: require('./watch_video'),
  set_cookie: require('./set_cookie'),
  assert: require('./assert'),
  sleep: require('./sleep'),
  branch: require('./branch'),
  loop: require('./loop'),
  screenshot: require('./screenshot'),
  captcha_solve: require('./captcha_solve'),
  relogin: require('./relogin'),
};

for (const t of STEP_TYPES) {
  if (typeof handlers[t] !== 'function') {
    throw new Error(`steps/index: missing handler for ${t}`);
  }
}

function getHandler(type) {
  const fn = handlers[type];
  if (!fn) {
    const err = new Error(`Unknown step type: ${type}`);
    err.code = 'UNKNOWN_STEP';
    throw err;
  }
  return fn;
}

module.exports = {
  handlers,
  getHandler,
};
