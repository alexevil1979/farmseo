'use strict';

/**
 * Control-flow step — handled by executor (then/else).
 * Exported so the registry lists every CONTRACT action type.
 */
async function branch() {
  throw new Error('branch: must be executed by scenario executor');
}

module.exports = branch;
