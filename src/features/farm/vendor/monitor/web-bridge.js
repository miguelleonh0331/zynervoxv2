(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  async function request(route, options = {}) {
    const [path, query = ''] = route.split('?');
    const response = await fetch(`proxy_gateway.php?route=${encodeURIComponent(path)}${query ? `&${query}` : ''}`, {
      method: options.method || 'GET',
      headers: {'Content-Type':'application/json', 'X-CSRF-Token':csrf},
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      credentials: 'same-origin'
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `Error HTTP ${response.status}`);
    return data;
  }
  window.controlPlane = {
    snapshot: () => request('snapshot'),
    events: (workerId = null) => request(`events${workerId ? `?worker=${Number(workerId)}` : ''}`),
    workerAction: (id, action, payload = {}) => request(`workers/${Number(id)}/${action}`, {method:'POST', body:payload}),
    fleetAction: (action, payload = {}) => request(`fleet/${action}`, {method:'POST', body:payload}),
    testProxy: (endpoint) => request('proxy-health/test', {method:'POST', body:{endpoint}}),
    testAllProxies: () => request('proxy-health/test-all', {method:'POST', body:{}}),
    editProxy: (endpoint, host, port, username, password) => request('proxy-health/edit', {method:'POST', body:{endpoint, host, port, username, password}})
  };
})();
