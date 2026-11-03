import { el } from '../utils.js';

let root;

function ensure() {
  if (!root) {
    root = el('div', { class: 'toasts' });
    document.body.append(root);
  }
  return root;
}

export function toast(message, type = 'ok', ms = 3200) {
  const node = el('div', { class: `toast ${type === 'error' ? 'error' : type === 'warn' ? 'warn' : ''}`, text: message });
  ensure().append(node);
  setTimeout(() => node.remove(), ms);
}
