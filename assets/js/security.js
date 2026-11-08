(() => {
  const original = window.fetch.bind(window);
  window.fetch = (input, options = {}) => {
    const url = new URL(input instanceof Request ? input.url : input, window.location.href);
    if (url.origin !== window.location.origin || url.pathname !== '/api.php') return original(input, options);
    const headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));
    headers.set('X-CSRF', document.querySelector('meta[name="csrf-token"]').content);
    // A API do app recebe inclusive consultas por POST para manter o contrato existente.
    return original(input, { ...options, method: 'POST', headers });
  };
})();
