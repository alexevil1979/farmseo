const listeners = new Set();

export function parseHash() {
  const raw = (window.location.hash || '#/dashboard').replace(/^#\/?/, '');
  const [path, query = ''] = raw.split('?');
  const parts = path.split('/').filter(Boolean);
  const params = Object.fromEntries(new URLSearchParams(query));
  return {
    route: parts[0] || 'dashboard',
    id: parts[1] || null,
    rest: parts.slice(1),
    params,
    path: parts.join('/'),
  };
}

export function navigate(path, replace = false) {
  const hash = `#/${String(path).replace(/^#?\/?/, '')}`;
  if (replace) window.location.replace(hash);
  else window.location.hash = hash;
}

export function onRoute(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function startRouter() {
  const emit = () => {
    const state = parseHash();
    listeners.forEach((fn) => fn(state));
  };
  window.addEventListener('hashchange', emit);
  emit();
  return () => window.removeEventListener('hashchange', emit);
}
