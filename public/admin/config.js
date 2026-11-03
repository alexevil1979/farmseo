/**
 * Runtime admin config. Base API URL comes from config.json — never hardcode hosts.
 * Override before load: window.FarmConfigOverride = { apiBase: '...' }
 */
(function () {
  const defaults = {
    apiBase: '/api/v1',
    appName: 'Farm',
    refreshIntervalMs: 5000,
    chartRefreshMs: 8000,
    tablePollMs: 6000,
    perPageDefault: 50,
    bulkConcurrency: 10,
  };

  function resolveConfigUrl() {
    const scripts = document.getElementsByTagName('script');
    for (let i = scripts.length - 1; i >= 0; i -= 1) {
      const src = scripts[i].src || '';
      if (src.includes('config.js')) {
        return src.replace(/config\.js(?:\?.*)?$/, 'config.json');
      }
    }
    const base = document.querySelector('base')?.href
      || (window.location.pathname.replace(/\/[^/]*$/, '/') || '/admin/');
    return new URL('config.json', base).href;
  }

  window.FarmConfigReady = fetch(resolveConfigUrl(), { cache: 'no-store' })
    .then((r) => (r.ok ? r.json() : {}))
    .catch(() => ({}))
    .then((json) => {
      window.FarmConfig = Object.freeze({
        ...defaults,
        ...(json && typeof json === 'object' ? json : {}),
        ...(window.FarmConfigOverride && typeof window.FarmConfigOverride === 'object'
          ? window.FarmConfigOverride
          : {}),
      });
      return window.FarmConfig;
    });
})();
