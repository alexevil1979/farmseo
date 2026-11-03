import { el } from '../utils.js';

export function openModal({ title, body, footer, wide = false, onClose } = {}) {
  const backdrop = el('div', { class: 'modal-backdrop' });
  const modal = el('div', { class: `modal${wide ? ' wide' : ''}` });
  const head = el('div', { class: 'modal-head' }, [
    el('h2', { text: title || '' }),
    el('button', {
      class: 'btn btn-ghost btn-sm',
      type: 'button',
      text: '✕',
      onClick: () => close(),
    }),
  ]);
  const bodyEl = el('div', { class: 'modal-body' });
  if (typeof body === 'string') bodyEl.innerHTML = body;
  else if (body) bodyEl.append(body);

  const foot = el('div', { class: 'modal-foot' });
  if (footer) foot.append(footer);

  modal.append(head, bodyEl, foot);
  backdrop.append(modal);
  document.body.append(backdrop);

  function close(result) {
    backdrop.remove();
    if (onClose) onClose(result);
  }

  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) close(null);
  });

  return { close, bodyEl, foot, modal };
}

export function confirmDialog(message, { confirmText = 'OK', danger = false } = {}) {
  return new Promise((resolve) => {
    const actions = el('div', {}, [
      el('button', {
        class: 'btn',
        type: 'button',
        text: 'Отмена',
        onClick: () => ui.close(false),
      }),
      el('button', {
        class: `btn ${danger ? 'btn-danger' : 'btn-primary'}`,
        type: 'button',
        text: confirmText,
        onClick: () => ui.close(true),
      }),
    ]);
    const ui = openModal({
      title: 'Подтверждение',
      body: el('p', { text: message }),
      footer: actions,
      onClose: resolve,
    });
  });
}
