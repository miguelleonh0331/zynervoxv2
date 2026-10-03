let ultimosDatos = [];

function showMsg(text, ok) {
  const el = document.getElementById('msg');
  el.textContent = text;
  el.className = ok ? 'ok' : 'err';
  setTimeout(() => { el.className = ''; }, 6000);
}

async function api(action, agent, password, extra) {
  const res = await fetch(window.ZYNERVOX_FARM_API || 'api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
    body: JSON.stringify(Object.assign({ action, agent, password }, extra || {}))
  });
  return res.json();
}

/** "2026-09-06T16:02:32" (short-iso) -> hace cuanto. */
function relativeTime(value) {
  if (!value) return '';
  const ts = Date.parse(value);
  if (Number.isNaN(ts)) return '';
  const seconds = Math.max(0, Math.floor((Date.now() - ts) / 1000));
  if (seconds < 60) return `hace ${seconds}s`;
  if (seconds < 3600) return `hace ${Math.floor(seconds / 60)}m`;
  return `hace ${Math.floor(seconds / 3600)}h`;
}

function renderRow(a) {
  const active = a.service_active === 'active';
  const reg = a.reg_state || (active ? 'desconocido' : 'apagado');
  let pillClass = 'off', pillText = 'Apagado';
  if (reg === 'registrado') { pillClass = 'on'; pillText = 'Registrado'; }
  else if (reg === 'error') { pillClass = 'error'; pillText = 'Error de registro'; }
  else if (reg === 'desconocido') { pillClass = 'off'; pillText = 'Sin datos aún'; }
  const detalle = a.reg_detail ? `<span class="reg-detail">${a.reg_detail}${a.reg_since ? ' · ' + relativeTime(a.reg_since) : ''}</span>` : (a.reg_since ? `<span class="reg-detail">${relativeTime(a.reg_since)}</span>` : '');
  return `<tr data-agent="${a.agent}" data-state="${active ? (reg === 'error' ? 'error' : (reg === 'registrado' ? 'on' : 'off')) : 'off'}">
    <td><strong>${a.agent}</strong></td>
    <td><span class="reg-pill ${active ? 'on' : 'off'}">${active ? 'Encendido' : 'Apagado'}</span></td>
    <td><span class="reg-pill ${pillClass}">${pillText}</span>${detalle}</td>
    <td class="annex-actions">
      <button class="ghost" data-action="start" data-agent="${a.agent}" ${active ? 'disabled' : ''}>Iniciar</button>
      <button class="ghost" data-action="stop" data-agent="${a.agent}" ${!active ? 'disabled' : ''}>Detener</button>
      <button class="danger" data-action="delete" data-agent="${a.agent}">Eliminar</button>
    </td>
  </tr>`;
}

function applyFilter() {
  const query = document.getElementById('annexSearch').value.trim().toLowerCase();
  const estado = document.getElementById('annexFilter').value;
  document.querySelectorAll('#tbody tr[data-agent]').forEach(row => {
    const matchesQuery = !query || row.dataset.agent.includes(query);
    const matchesEstado = !estado || row.dataset.state === estado;
    row.hidden = !(matchesQuery && matchesEstado);
  });
}

function updateSummary(agents) {
  const total = agents.length;
  const on = agents.filter(a => a.service_active === 'active').length;
  const reg = agents.filter(a => a.reg_state === 'registrado').length;
  const err = agents.filter(a => a.reg_state === 'error').length;
  document.getElementById('sum-total').textContent = total;
  document.getElementById('sum-on').textContent = on;
  document.getElementById('sum-reg').textContent = reg;
  document.getElementById('sum-err').textContent = err;
}

async function refresh() {
  const data = await api('status_detail', '');
  const tbody = document.getElementById('tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4">Error: ' + (data.error || 'desconocido') + '</td></tr>';
    return;
  }
  ultimosDatos = data.agents || [];
  if (!ultimosDatos.length) {
    tbody.innerHTML = '<tr><td colspan="4">Sin anexos creados todavía.</td></tr>';
    updateSummary([]);
    return;
  }
  tbody.innerHTML = ultimosDatos.map(renderRow).join('');
  updateSummary(ultimosDatos);
  applyFilter();
}

async function doAction(action, agent) {
  const data = await api(action, agent);
  if (data.ok) showMsg(`${action} ${agent}: OK`, true);
  else showMsg(`${action} ${agent}: ${data.error || 'fallo'}`, false);
  refresh();
}

async function doDelete(agent) {
  if (!confirm(`Eliminar anexo ${agent}?`)) return;
  await doAction('delete', agent);
}

// Delegado en el tbody (2026-09-06): sin onclick="" inline, la CSP de esta
// pagina (script-src 'self') tambien bloquea los manejadores de evento en
// atributos, no solo un <script> completo.
document.getElementById('tbody').addEventListener('click', event => {
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  const { action, agent } = button.dataset;
  if (action === 'delete') doDelete(agent);
  else doAction(action, agent);
});

document.getElementById('btnCreate').addEventListener('click', async () => {
  const agent = document.getElementById('newAgent').value.trim();
  const password = document.getElementById('newPassword').value.trim();
  if (!/^4\d{3}$/.test(agent)) { showMsg('Anexo inválido: usa formato 4XXX', false); return; }
  const data = await api('create', agent, password);
  if (data.ok) {
    showMsg(`Anexo ${agent} creado (apagado). Puertos: ${JSON.stringify(data.ports)}`, true);
    document.getElementById('newAgent').value = '';
    document.getElementById('newPassword').value = '';
  } else {
    showMsg(`Error creando ${agent}: ${data.error || 'fallo'}`, false);
  }
  refresh();
});

document.getElementById('btnCreateRange').addEventListener('click', async () => {
  const from = document.getElementById('rangeFrom').value.trim();
  const to = document.getElementById('rangeTo').value.trim();
  const password = document.getElementById('rangePassword').value.trim();
  if (!/^4\d{3}$/.test(from) || !/^4\d{3}$/.test(to)) { showMsg('Rango inválido: usa formato 4XXX en ambos campos', false); return; }
  if (parseInt(to, 10) < parseInt(from, 10)) { showMsg('El "hasta" debe ser mayor o igual al "desde"', false); return; }
  const btn = document.getElementById('btnCreateRange');
  btn.disabled = true; btn.textContent = 'Creando…';
  const data = await api('create_range', '', password, { from, to });
  btn.disabled = false; btn.textContent = 'Crear lote (max 50, quedan apagados)';
  if (data.ok) {
    const fails = (data.results || []).filter(r => !r.ok);
    let msg = `Lote: ${data.created}/${data.total} creados.`;
    if (fails.length) msg += ' Fallaron: ' + fails.map(r => `${r.agent} (${r.error})`).join(', ');
    showMsg(msg, fails.length === 0);
  } else {
    showMsg(`Error en el lote: ${data.error || 'fallo'}`, false);
  }
  refresh();
});

document.getElementById('btnStopAll').addEventListener('click', async () => {
  if (!confirm('¿Detener todos los anexos encendidos del pool?')) return;
  const btn = document.getElementById('btnStopAll');
  btn.disabled = true; btn.textContent = 'Deteniendo…';
  const data = await api('stop_all', '');
  btn.disabled = false; btn.textContent = 'Detener todos';
  if (data.ok) showMsg(`Detenidos: ${data.stopped}/${data.total}.`, true);
  else showMsg(`Error: ${data.error || 'fallo'}`, false);
  refresh();
});

document.getElementById('btnStartAll').addEventListener('click', async () => {
  if (!confirm('¿Iniciar todos los anexos apagados del pool?')) return;
  const btn = document.getElementById('btnStartAll');
  btn.disabled = true; btn.textContent = 'Iniciando…';
  const data = await api('start_all', '');
  btn.disabled = false; btn.textContent = 'Iniciar todos';
  if (data.ok) showMsg(`Iniciados: ${data.started}/${data.total}.`, true);
  else showMsg(`Error: ${data.error || 'fallo'}`, false);
  refresh();
});

document.getElementById('btnTestDestino').addEventListener('click', async () => {
  const host = document.getElementById('destinoHost').value.trim();
  const resultEl = document.getElementById('destinoTestResult');
  if (!/^[A-Za-z0-9_.-]{1,253}(:[0-9]{1,5})?$/.test(host)) {
    showMsg('Servidor inválido: usa host o host:puerto', false);
    return;
  }
  const btn = document.getElementById('btnTestDestino');
  btn.disabled = true; btn.textContent = 'Probando…';
  resultEl.textContent = 'Enviando OPTIONS SIP por UDP (hasta 3s)…';
  const data = await api('test_destino', '', '', { host });
  btn.disabled = false; btn.textContent = 'Probar';
  if (!data.ok) {
    resultEl.textContent = `Error: ${data.error || 'fallo'}`;
    return;
  }
  if (data.is_sip) {
    resultEl.textContent = `✔ Responde como SIP real (${data.status_line}, ${data.elapsed_ms}ms)`;
  } else if (data.reachable) {
    resultEl.textContent = `⚠ Respondió pero no parece SIP: "${data.status_line || ''}"`;
  } else {
    resultEl.textContent = `✘ Sin respuesta: ${data.error || 'timeout'}`;
  }
});

async function loadDestino() {
  const data = await api('get_destino', '', '');
  if (data.ok) document.getElementById('destinoHost').value = data.host || '';
}

document.getElementById('btnSaveDestino').addEventListener('click', async () => {
  const host = document.getElementById('destinoHost').value.trim();
  if (!/^[A-Za-z0-9_.-]{1,253}(:[0-9]{1,5})?$/.test(host)) {
    showMsg('Servidor inválido: usa host o host:puerto', false);
    return;
  }
  if (!confirm(`¿Aplicar "${host}" como destino a TODOS los anexos (existentes se reinician)?`)) return;
  const btn = document.getElementById('btnSaveDestino');
  btn.disabled = true; btn.textContent = 'Aplicando…';
  const data = await api('set_destino', '', '', { host });
  btn.disabled = false; btn.textContent = 'Guardar y aplicar a todos';
  if (data.ok) {
    const updated = (data.agents || []).filter(a => a.updated).length;
    showMsg(`Destino guardado. Anexos actualizados: ${updated}/${(data.agents || []).length}.`, true);
  } else {
    showMsg(`Error: ${data.error || 'fallo'}`, false);
  }
  refresh();
});

document.getElementById('btnRefresh').addEventListener('click', refresh);
document.getElementById('annexSearch').addEventListener('input', applyFilter);
document.getElementById('annexFilter').addEventListener('change', applyFilter);
document.getElementById('sum-err-card').addEventListener('click', () => {
  document.getElementById('annexFilter').value = 'error';
  applyFilter();
});
document.getElementById('sum-err-card').addEventListener('keydown', event => {
  if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); document.getElementById('sum-err-card').click(); }
});

refresh();
loadDestino();
setInterval(refresh, 15000);
