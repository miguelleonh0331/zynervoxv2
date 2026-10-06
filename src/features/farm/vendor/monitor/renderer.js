const grid = document.querySelector("#worker-grid");
const template = document.querySelector("#worker-template");
const eventList = document.querySelector("#event-list");
const message = document.querySelector("#operation-message");
const targetRange = document.querySelector("#target-range");
const targetNumber = document.querySelector("#target-number");
const startEngineButton = document.querySelector("#start-engine");
const workerSearch = document.querySelector("#worker-search");
const statusFilter = document.querySelector("#status-filter");
const cards = new Map();
const workersById = new Map();
const workerModal = document.querySelector("#worker-modal");
const workerDetail = document.querySelector("#worker-detail");
const worldMapModal = document.querySelector("#world-map-modal");
const worldMapPoints = document.querySelector("#world-map-points");
const blockedModal = document.querySelector("#blocked-modal");
const blockedList = document.querySelector("#blocked-list");
const blockedBadge = document.querySelector("#blocked-badge");
const proxyLocations = window.PROXY_LOCATIONS || [];
const locationsByIp = new Map(proxyLocations.map(location => [location.ip, location]));
let selectedWorker = null;
let refreshing = false;
let messageTimer = null;
let motorRunning = false;

const labels = {
  stopped: "Detenido", starting: "Iniciando", idle: "Disponible", busy: "Procesando",
  pausing: "Pausando", paused: "Pausado", draining: "Drenando", stopping: "Deteniendo",
  restarting: "Reiniciando", degraded: "Degradado", error: "Error", blocked: "Bloqueado",
};
const activeStates = new Set(["starting", "idle", "busy", "pausing", "paused", "draining", "stopping", "restarting", "degraded"]);

// Proxy bloqueado (2026-09-06): pisa el status real (idle/stopped/draining...) para
// que el worker se vea rojo mientras el proxy siga bloqueado, sin importar en que
// parte de su ciclo de vida normal este ahora mismo.
function effectiveStatus(worker) {
  return worker && worker.blocked ? "blocked" : worker?.status;
}
const regionNames = typeof Intl.DisplayNames === "function" ? new Intl.DisplayNames(["es"], { type: "region" }) : null;

function countryText(code) {
  if (!code || code === "??") return "—";
  const upper = code.toUpperCase();
  const flag = /^[A-Z]{2}$/.test(upper) ? String.fromCodePoint(...[...upper].map(char => 127397 + char.charCodeAt())) : "";
  return `${flag} ${regionNames?.of(upper) || upper}`.trim();
}

function notify(text, error = false) {
  clearTimeout(messageTimer);
  message.textContent = text;
  message.hidden = false;
  message.classList.toggle("error", error);
  messageTimer = setTimeout(() => { message.hidden = true; }, 5000);
}

function relativeTime(value) {
  if (!value) return "—";
  const seconds = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 1000));
  if (seconds < 5) return "ahora";
  if (seconds < 60) return `hace ${seconds}s`;
  if (seconds < 3600) return `hace ${Math.floor(seconds / 60)}m`;
  return `hace ${Math.floor(seconds / 3600)}h`;
}

async function workerAction(index, action, payload = {}) {
  const friendly = { start: "Inicio", stop: "Detención", pause: "Pausa", resume: "Reanudación", drain: "Drenado", restart: "Reinicio", "rotate-proxy": "Cambio de proxy", "auto-restart": "Política" };
  try {
    await window.controlPlane.workerAction(index, action, payload);
    notify(`${friendly[action]} enviado a ${cards.get(index)?.dataset.name || `worker ${index}`}.`);
    await refresh();
  } catch (error) {
    notify(error.message, true);
  }
}

function createCard(worker) {
  const card = document.createElement("button");
  card.type = "button";
  card.className = "worker-tile";
  card.dataset.index = String(worker.index);
  card.dataset.name = worker.name;
  card.addEventListener("click", () => openWorkerModal(worker.index));
  grid.appendChild(card);
  cards.set(worker.index, card);
  return card;
}

function openWorkerModal(index) {
  selectedWorker = index;
  renderSelection();
  workerModal.hidden = false;
  document.body.classList.add("modal-open");
  renderWorkerDetail(workersById.get(index));
  workerModal.querySelector(".modal-close").focus();
}

function closeWorkerModal() {
  workerModal.hidden = true;
  if (worldMapModal.hidden) document.body.classList.remove("modal-open");
}

function openWorldMap() {
  worldMapModal.hidden = false;
  document.body.classList.add("modal-open");
  renderWorldMap([...workersById.values()]);
  worldMapModal.querySelector(".modal-close").focus();
}

function closeWorldMap() {
  worldMapModal.hidden = true;
  if (workerModal.hidden) document.body.classList.remove("modal-open");
}

function renderWorldMap(workers) {
  if (worldMapModal.hidden) return;
  worldMapPoints.textContent = "";
  const coordinateCounts = new Map();
  let idle = 0, busy = 0, errors = 0, pending = 0;
  workers.forEach(worker => {
    const staticLocation = locationsByIp.get(worker.public_ip) || locationsByIp.get(worker.host);
    const latitude = Number(worker.latitude ?? staticLocation?.latitude);
    const longitude = Number(worker.longitude ?? staticLocation?.longitude);
    const hasCoordinates = Number.isFinite(latitude) && Number.isFinite(longitude) && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180;
    const location = {
      latitude: hasCoordinates ? latitude : 0,
      longitude: hasCoordinates ? longitude : 0,
      city: worker.city || staticLocation?.city || "",
      country: worker.country_name || staticLocation?.country || countryText(worker.country_code),
    };
    const key = `${location.latitude},${location.longitude}`;
    const occurrence = coordinateCounts.get(key) || 0;
    coordinateCounts.set(key, occurrence + 1);
    const angle = occurrence * 2.399;
    const radius = occurrence ? 0.55 + Math.sqrt(occurrence) * 0.42 : 0;
    const x = hasCoordinates ? (location.longitude + 180) / 360 * 100 + Math.cos(angle) * radius : 2 + (pending % 48) * 2;
    const y = hasCoordinates ? (90 - location.latitude) / 180 * 100 + Math.sin(angle) * radius * 0.65 : 97 - Math.floor(pending / 48) * 2.2;
    const effective = effectiveStatus(worker);
    const state = effective === "busy" ? "busy" : (["error", "degraded", "blocked"].includes(effective) ? "error" : (effective === "idle" ? "idle" : "inactive"));
    if (state === "busy") busy += 1;
    else if (state === "error") errors += 1;
    else if (state === "idle") idle += 1;
    if (!hasCoordinates) pending += 1;
    const dot = document.createElement("button");
    dot.type = "button";
    dot.className = `map-worker-dot ${state}${hasCoordinates ? "" : " pending-location"}`;
    dot.style.left = `${x}%`;
    dot.style.top = `${y}%`;
    const place = hasCoordinates ? [location.city, location.country].filter(Boolean).join(", ") : "ubicación pendiente";
    dot.dataset.tip = `${worker.name} · ${worker.public_ip || worker.host} · ${place} · ${labels[effective] || effective}`;
    dot.setAttribute("aria-label", dot.dataset.tip);
    dot.addEventListener("click", () => { closeWorldMap(); openWorkerModal(worker.index); });
    worldMapPoints.appendChild(dot);
  });
  document.querySelector("#map-idle-count").textContent = idle;
  document.querySelector("#map-busy-count").textContent = busy;
  document.querySelector("#map-error-count").textContent = errors;
  document.querySelector("#map-pending-count").textContent = pending;
}

function renderWorkerDetail(worker) {
  if (!worker || workerModal.hidden) return;
  const fragment = template.content.cloneNode(true);
  const card = fragment.querySelector(".worker-card");
  const effective = effectiveStatus(worker);
  card.className = `worker-card ${effective}`;
  card.querySelector(".worker-number").textContent = `WORKER ${String(worker.index).padStart(2, "0")}`;
  card.querySelector(".worker-name").id = "modal-worker-title";
  card.querySelector(".worker-name").textContent = worker.name;
  card.querySelector(".status-text").textContent = labels[effective] || effective;
  card.querySelector(".proxy-address").textContent = `${worker.host}:${worker.port}`;
  card.querySelector(".public-ip").textContent = worker.public_ip || "—";
  card.querySelector(".country").textContent = [worker.city, worker.country_name || countryText(worker.country_code)].filter(Boolean).join(", ") || "—";
  card.querySelector(".worker-pid").textContent = worker.pid || "—";
  card.querySelector(".last-activity").textContent = relativeTime(worker.last_activity);
  card.querySelector(".current-job").textContent = worker.current_job || "Ninguno";
  card.querySelector(".metric-ok").textContent = worker.jobs_ok;
  card.querySelector(".metric-fail").textContent = worker.jobs_failed;
  card.querySelector(".metric-restarts").textContent = worker.restarts;
  card.querySelector(".last-log").textContent = worker.last_log || worker.last_error || "Sin actividad registrada.";
  card.querySelector(".auto-restart").checked = worker.auto_restart;
  const active = activeStates.has(worker.status);
  card.querySelector('[data-action="start"]').disabled = !motorRunning || active;
  const pauseButton = card.querySelector('[data-action="pause"]');
  pauseButton.textContent = worker.desired_state === "paused" ? "Reanudar" : "Pausar";
  pauseButton.disabled = !motorRunning || !active || ["draining", "stopping", "restarting"].includes(worker.status);
  card.querySelector('[data-action="drain"]').disabled = !active || worker.status === "draining";
  card.querySelector('[data-action="restart"]').disabled = !motorRunning || ["starting", "restarting"].includes(worker.status);
  card.querySelector('[data-action="rotate-proxy"]').disabled = !motorRunning || ["starting", "restarting"].includes(worker.status);
  card.querySelector('[data-action="stop"]').disabled = !active;
  card.querySelectorAll("button[data-action]").forEach(button => button.addEventListener("click", () => {
    let action = button.dataset.action;
    if (action === "pause" && worker.desired_state === "paused") action = "resume";
    workerAction(worker.index, action);
  }));
  card.querySelector(".auto-restart").addEventListener("change", event => workerAction(worker.index, "auto-restart", { enabled: event.target.checked }));
  workerDetail.replaceChildren(fragment);
}

function updateCard(worker) {
  workersById.set(worker.index, worker);
  const card = cards.get(worker.index) || createCard(worker);
  const effective = effectiveStatus(worker);
  card.className = `worker-tile ${effective}${selectedWorker === worker.index ? " selected" : ""}`;
  card.dataset.status = effective;
  card.dataset.desired = worker.desired_state;
  const country = countryText(worker.country_code);
  card.dataset.search = `${worker.name} ${worker.host} ${worker.port} ${worker.public_ip || ""} ${worker.country_code || ""} ${country} ${labels[effective] || effective}`.toLowerCase();
  card.textContent = String(worker.index).padStart(2, "0");
  card.title = `${worker.name} · ${labels[effective] || effective}`;
  card.setAttribute("aria-label", card.title);
  if (!workerModal.hidden && selectedWorker === worker.index) renderWorkerDetail(worker);
}

function applyWorkerFilters() {
  const query = workerSearch.value.trim().toLowerCase();
  const status = statusFilter.value;
  let visible = 0;
  for (const [index, card] of cards) {
    const worker = workersById.get(index);
    const matchesStatus = !status || (status === "failed" ? Number(worker?.jobs_failed) > 0 : effectiveStatus(worker) === status);
    const show = (!query || card.dataset.search.includes(query)) && matchesStatus;
    card.hidden = !show;
    if (show) visible += 1;
  }
  document.querySelector("#visible-count").textContent = `${visible} visibles`;
}

function renderSelection() {
  for (const [index, card] of cards) card.classList.toggle("selected", index === selectedWorker);
  const selected = selectedWorker ? cards.get(selectedWorker)?.dataset.name : null;
  document.querySelector("#event-filter").textContent = selected ? `Mostrando solo ${selected}` : "Mostrando todos los workers";
}

function renderEvents(events) {
  eventList.textContent = "";
  const filtered = selectedWorker ? events.filter((event) => event.worker_id === selectedWorker) : events;
  if (!filtered.length) {
    const empty = document.createElement("div");
    empty.className = "empty";
    empty.textContent = "Aún no hay actividad para mostrar.";
    eventList.appendChild(empty);
    return;
  }
  const fragment = document.createDocumentFragment();
  filtered.slice(-180).reverse().forEach((event) => {
    const row = document.createElement("article");
    row.className = `event ${event.level} ${event.kind}`;
    const meta = document.createElement("div");
    meta.className = "event-meta";
    const source = document.createElement("strong");
    source.textContent = event.worker_id ? cards.get(event.worker_id)?.dataset.name || `worker ${event.worker_id}` : "sistema";
    const date = document.createElement("span");
    date.textContent = new Date(event.ts).toLocaleTimeString();
    meta.append(source, date);
    const detail = document.createElement("p");
    detail.textContent = event.message;
    row.append(meta, detail);
    fragment.appendChild(row);
  });
  eventList.appendChild(fragment);
}

function updateSummary(data) {
  document.querySelector("#active-count").textContent = data.summary.active;
  document.querySelector("#total-count").textContent = `de ${data.summary.total}`;
  document.querySelector("#busy-count").textContent = data.summary.busy;
  document.querySelector("#paused-count").textContent = data.summary.paused;
  document.querySelector("#completed-count").textContent = data.summary.jobs_ok;
  document.querySelector("#failed-count").textContent = data.summary.jobs_failed;
  const failedWorkers = data.workers.filter(worker => Number(worker.jobs_failed) > 0);
  const failedNames = failedWorkers.slice(0, 3).map(worker => `${worker.name} (${worker.jobs_failed})`);
  const extraFailed = failedWorkers.length > 3 ? ` +${failedWorkers.length - 3} más` : "";
  document.querySelector("#failed-workers").textContent = failedNames.length ? `${failedNames.join(", ")}${extraFailed}` : "sin workers afectados";
  const proxyPool = data.proxy_pool || { files: [], duplicates_ignored: 0 };
  const inventory = proxyPool.inventory || { located: 0, probing: 0, partial: 0, failed: 0 };
  document.querySelector("#proxy-files-count").textContent = proxyPool.files.length;
  document.querySelector("#proxy-pool-detail").textContent = proxyPool.error
    ? proxyPool.error
    : `${data.summary.total} únicos · ${inventory.located} ubicados · ${inventory.probing} verificando${inventory.failed ? ` · ${inventory.failed} reintentos` : ""}${proxyPool.duplicates_ignored ? ` · ${proxyPool.duplicates_ignored} duplicados omitidos` : ""}`;
  const queue = data.queue || {};
  document.querySelector("#remaining-count").textContent = queue.available ? queue.remaining : "—";
  document.querySelector("#queue-detail").textContent = queue.available ? `${queue.pending} pendientes · ${queue.claimed} procesándose · ${queue.failed} fallidos` : "cola no disponible";
  const cycleTotal = queue.available ? queue.remaining + data.summary.jobs_ok + data.summary.jobs_failed : 0;
  const progress = cycleTotal ? Math.round((data.summary.jobs_ok / cycleTotal) * 100) : (queue.available && queue.remaining === 0 ? 100 : 0);
  document.querySelector("#progress-count").textContent = queue.available ? `${progress}%` : "—";
  document.querySelector("#queue-total").textContent = queue.available ? `${cycleTotal} audios en el ciclo` : "total no disponible";
  targetRange.max = data.summary.total;
  targetNumber.max = data.summary.total;
  if (document.activeElement !== targetRange && document.activeElement !== targetNumber) {
    targetRange.value = data.target_workers;
    targetNumber.value = data.target_workers;
  }
  startEngineButton.disabled = data.engine_running || data.summary.total === 0 || Boolean(proxyPool.error);
  startEngineButton.textContent = data.engine_running ? "Motor iniciado" : "Iniciar motor";
  const ttsInput = document.querySelector("#tts-api-url-input");
  if (document.activeElement !== ttsInput) {
    ttsInput.value = data.tts_api_url || "";
  }
}

function openBlockedModal() {
  blockedModal.hidden = false;
  document.body.classList.add("modal-open");
  blockedModal.querySelector(".modal-close").focus();
}

function closeBlockedModal() {
  blockedModal.hidden = true;
  if (workerModal.hidden && worldMapModal.hidden) document.body.classList.remove("modal-open");
}

async function probarProxy(endpoint, button) {
  const original = button.textContent;
  button.disabled = true;
  button.textContent = "Probando…";
  try {
    const result = await window.controlPlane.testProxy(endpoint);
    if (result.ok) notify(`Proxy ${endpoint} reactivado: pasó la prueba.`);
    else notify(`Proxy ${endpoint} sigue fallando: ${result.error || "sin detalle"}`, true);
  } catch (error) {
    notify(error.message, true);
  } finally {
    button.disabled = false;
    button.textContent = original;
    await refresh();
  }
}

async function copiarIp(endpoint, button) {
  const ip = endpoint.split(":")[0];
  try {
    await navigator.clipboard.writeText(ip);
    const original = button.textContent;
    button.textContent = "¡Copiado!";
    setTimeout(() => { button.textContent = original; }, 1500);
  } catch (error) {
    notify(`No se pudo copiar: ${error.message}`, true);
  }
}

/** Formulario inline para reemplazar un proxy con problemas por uno nuevo
 * (2026-09-06): sobrescribe la línea exacta del archivo de cuentas de donde
 * salió; no pide la contraseña vieja, solo los datos del proxy de reemplazo. */
function crearFormularioEdicion(item, onCancelar) {
  const [host, port] = item.endpoint.split(":");
  const form = document.createElement("form");
  form.className = "blocked-edit-form";

  const campoHost = document.createElement("input");
  campoHost.name = "host"; campoHost.placeholder = "Host / IP"; campoHost.value = host; campoHost.required = true;
  const campoPort = document.createElement("input");
  campoPort.name = "port"; campoPort.placeholder = "Puerto"; campoPort.value = port; campoPort.required = true;
  const campoUser = document.createElement("input");
  campoUser.name = "username"; campoUser.placeholder = "Usuario nuevo"; campoUser.required = true; campoUser.autocomplete = "off";
  const campoPass = document.createElement("input");
  campoPass.name = "password"; campoPass.type = "password"; campoPass.placeholder = "Contraseña nueva"; campoPass.required = true; campoPass.autocomplete = "new-password";

  const guardar = document.createElement("button");
  guardar.type = "submit"; guardar.className = "primary"; guardar.textContent = "Guardar";
  const cancelar = document.createElement("button");
  cancelar.type = "button"; cancelar.className = "ghost"; cancelar.textContent = "Cancelar";
  cancelar.addEventListener("click", onCancelar);

  form.append(campoHost, campoPort, campoUser, campoPass, guardar, cancelar);
  form.addEventListener("submit", async event => {
    event.preventDefault();
    guardar.disabled = true;
    guardar.textContent = "Guardando…";
    try {
      const result = await window.controlPlane.editProxy(item.endpoint, campoHost.value.trim(), campoPort.value.trim(), campoUser.value.trim(), campoPass.value);
      notify(`Proxy actualizado: ${item.endpoint} → ${result.endpoint}. El archivo de cuentas quedó sobrescrito.`);
      editingProxyEndpoint = null; // ya se guardo: dejar que el proximo refresh redibuje normal
      await refresh();
    } catch (error) {
      notify(error.message, true);
      guardar.disabled = false;
      guardar.textContent = "Guardar";
      // Se deja editingProxyEndpoint tal cual: el formulario sigue abierto con lo
      // ya escrito, para corregir y reintentar sin perder los datos.
    }
  });
  return form;
}

// Proxy cuyo formulario de edicion esta abierto ahora mismo (2026-09-06): el
// refresco automatico cada 1.5s reconstruye toda la lista desde cero, lo que
// borraba el formulario (y lo escrito) antes de que el usuario alcanzara a
// guardar. Mientras haya uno abierto, se congela el redibujado de esta lista.
let editingProxyEndpoint = null;

function renderBlockedProxies(list) {
  if (editingProxyEndpoint && blockedList.querySelector(".blocked-edit-form")) return;
  blockedBadge.textContent = String(list.length);
  blockedList.textContent = "";
  if (!list.length) {
    const empty = document.createElement("div");
    empty.className = "blocked-empty";
    empty.textContent = "Ningún proxy bloqueado ahora mismo.";
    blockedList.appendChild(empty);
    return;
  }
  const fragment = document.createDocumentFragment();
  list.forEach(item => {
    const row = document.createElement("article");
    row.className = "blocked-row";
    const top = document.createElement("div");
    top.className = "blocked-row-top";
    const info = document.createElement("div");
    const title = document.createElement("strong");
    title.textContent = item.name ? `${item.name} · ${item.endpoint}` : item.endpoint;
    const place = [item.city, item.country_name || countryText(item.country_code)].filter(Boolean).join(", ");
    const meta = document.createElement("p");
    meta.className = "meta";
    meta.textContent = `${place ? place + " · " : ""}${item.consecutive_failures} fallos seguidos · bloqueado ${relativeTime(item.blocked_at)} · ${item.blocked_reason || "sin detalle"}`;
    info.append(title, meta);
    if (item.last_test_at) {
      const testInfo = document.createElement("p");
      testInfo.className = "meta";
      testInfo.textContent = `Última prueba ${relativeTime(item.last_test_at)}: ${item.last_test_ok ? "OK" : `falló (${item.last_test_error || "sin detalle"})`}`;
      info.append(testInfo);
    }

    const actions = document.createElement("div");
    actions.className = "blocked-row-actions";
    const copyButton = document.createElement("button");
    copyButton.type = "button"; copyButton.className = "ghost"; copyButton.title = "Copiar IP"; copyButton.textContent = "Copy";
    copyButton.addEventListener("click", () => copiarIp(item.endpoint, copyButton));
    const testButton = document.createElement("button");
    testButton.type = "button"; testButton.className = "ghost"; testButton.textContent = "Probar";
    testButton.addEventListener("click", () => probarProxy(item.endpoint, testButton));
    const editButton = document.createElement("button");
    editButton.type = "button"; editButton.className = "ghost"; editButton.textContent = "Editar";
    actions.append(copyButton, testButton, editButton);
    top.append(info, actions);
    row.append(top);

    editButton.addEventListener("click", () => {
      const yaAbierto = row.querySelector(".blocked-edit-form");
      if (yaAbierto) { yaAbierto.remove(); editingProxyEndpoint = null; return; }
      editingProxyEndpoint = item.endpoint;
      const form = crearFormularioEdicion(item, () => { form.remove(); editingProxyEndpoint = null; });
      row.append(form);
      form.querySelector('input[name="username"]').focus();
    });

    fragment.appendChild(row);
  });
  blockedList.appendChild(fragment);
}

async function refreshProxyInventory() {
  const body = document.querySelector("#proxy-inventory-body");
  try {
    const files = await window.controlPlane.listProxyFiles();
    body.textContent = "";
    if (!files.length) {
      const row = document.createElement("tr");
      const cell = document.createElement("td");
      cell.colSpan = 4;
      cell.textContent = "No hay archivos de proxies cargados.";
      row.appendChild(cell);
      body.appendChild(row);
      return;
    }
    const fragment = document.createDocumentFragment();
    files.forEach(item => {
      const row = document.createElement("tr");

      const fileCell = document.createElement("td");
      fileCell.textContent = item.file;

      const accountCell = document.createElement("td");
      accountCell.textContent = item.account || "Sin cuenta registrada";

      const dateCell = document.createElement("td");
      dateCell.textContent = item.uploaded_at ? relativeTime(item.uploaded_at) : "—";

      const actionsCell = document.createElement("td");
      const deleteButton = document.createElement("button");
      deleteButton.type = "button";
      deleteButton.className = "ghost";
      deleteButton.textContent = "Eliminar";
      deleteButton.addEventListener("click", async () => {
        if (!confirm(`¿Eliminar ${item.file}? El orquestador dejará de usar sus proxies en el próximo refresh.`)) return;
        deleteButton.disabled = true;
        deleteButton.textContent = "Eliminando…";
        try {
          await window.controlPlane.deleteProxyFile(item.file);
          notify(`Archivo ${item.file} eliminado.`);
          await refreshProxyInventory();
        } catch (error) {
          notify(error.message, true);
          deleteButton.disabled = false;
          deleteButton.textContent = "Eliminar";
        }
      });
      actionsCell.appendChild(deleteButton);

      row.append(fileCell, accountCell, dateCell, actionsCell);
      fragment.appendChild(row);
    });
    body.appendChild(fragment);
  } catch (error) {
    body.textContent = "";
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 4;
    cell.textContent = `✘ ${error.message}`;
    row.appendChild(cell);
    body.appendChild(row);
  }
}

function connection(online, detail) {
  const box = document.querySelector(".connection");
  box.classList.toggle("online", online);
  box.classList.toggle("offline", !online);
  document.querySelector("#connection-label").textContent = online ? "Control plane operativo" : "Sin conexión";
  document.querySelector("#last-sync").textContent = detail;
}

async function refresh() {
  if (refreshing) return;
  refreshing = true;
  try {
    const data = await window.controlPlane.snapshot();
    motorRunning = Boolean(data.engine_running);
    const currentIndexes = new Set(data.workers.map(worker => worker.index));
    for (const [index, card] of cards) {
      if (currentIndexes.has(index)) continue;
      card.remove();
      cards.delete(index);
      workersById.delete(index);
      if (selectedWorker === index) closeWorkerModal();
    }
    data.workers.forEach(updateCard);
    renderWorldMap(data.workers);
    applyWorkerFilters();
    updateSummary(data);
    renderSelection();
    renderEvents(data.events);
    renderBlockedProxies(data.blocked_proxies || []);
    connection(true, `${motorRunning ? "Motor activo" : "Motor detenido"} · ${new Date().toLocaleTimeString()}`);
  } catch (error) {
    connection(false, error.message);
  } finally {
    refreshing = false;
  }
}

targetRange.addEventListener("input", () => { targetNumber.value = targetRange.value; });
targetNumber.addEventListener("input", () => { targetRange.value = targetNumber.value; });
document.querySelector("#apply-target").addEventListener("click", async () => {
  try {
    await window.controlPlane.fleetAction("target", { target: Number(targetNumber.value) });
    notify(`Objetivo establecido en ${targetNumber.value} workers.`);
    await refresh();
  } catch (error) { notify(error.message, true); }
});
startEngineButton.addEventListener("click", async () => {
  try { await window.controlPlane.fleetAction("start-engine"); notify("Motor iniciado: consulta de cola habilitada."); await refresh(); }
  catch (error) { notify(error.message, true); }
});
document.querySelector("#stop-all").addEventListener("click", async () => {
  if (!confirm("¿Detener inmediatamente toda la flota? Los trabajos en curso pueden quedar para reintento.")) return;
  try { await window.controlPlane.fleetAction("stop-all"); notify("Parada inmediata enviada."); await refresh(); }
  catch (error) { notify(error.message, true); }
});
document.querySelector("#tts-api-url-save").addEventListener("click", async () => {
  const input = document.querySelector("#tts-api-url-input");
  const msg = document.querySelector("#tts-api-url-message");
  const url = input.value.trim();
  if (url && !/^https?:\/\//.test(url)) {
    msg.textContent = "✘ La URL debe empezar con http:// o https://";
    return;
  }
  const button = document.querySelector("#tts-api-url-save");
  button.disabled = true; button.textContent = "Guardando…";
  try {
    await window.controlPlane.fleetAction("tts-api-url", { url });
    msg.textContent = "✔ Guardado.";
    notify("Servidor TTS actualizado.");
    await refresh();
  } catch (error) {
    msg.textContent = `✘ ${error.message}`;
    notify(error.message, true);
  } finally {
    button.disabled = false; button.textContent = "Guardar";
  }
});
document.querySelector("#proxy-file-upload").addEventListener("click", async () => {
  const input = document.querySelector("#proxy-file-input");
  const accountInput = document.querySelector("#proxy-account-input");
  const msg = document.querySelector("#proxy-upload-message");
  const file = input.files && input.files[0];
  if (!file) { notify("Elige un archivo primero.", true); return; }
  const button = document.querySelector("#proxy-file-upload");
  button.disabled = true; button.textContent = "Subiendo…";
  try {
    const result = await window.controlPlane.uploadProxyFile(file, accountInput.value.trim());
    msg.textContent = `✔ Subido como ${result.file}${result.account ? ` (cuenta: ${result.account})` : ""}. El orquestador lo recoge solo.`;
    input.value = "";
    accountInput.value = "";
    notify("Archivo de proxies subido.");
    await refresh();
    await refreshProxyInventory();
  } catch (error) {
    msg.textContent = `✘ ${error.message}`;
    notify(error.message, true);
  } finally {
    button.disabled = false; button.textContent = "Subir";
  }
});
document.querySelector("#refresh").addEventListener("click", refresh);
document.querySelector("#clear-filter").addEventListener("click", () => { selectedWorker = null; renderSelection(); refresh(); });
workerSearch.addEventListener("input", applyWorkerFilters);
statusFilter.addEventListener("change", applyWorkerFilters);
document.querySelectorAll("[data-close-modal]").forEach(element => element.addEventListener("click", closeWorkerModal));
document.querySelector("#open-world-map").addEventListener("click", openWorldMap);
document.querySelectorAll("[data-close-world-map]").forEach(element => element.addEventListener("click", closeWorldMap));
document.querySelector("#open-blocked").addEventListener("click", openBlockedModal);
document.querySelectorAll("[data-close-blocked]").forEach(element => element.addEventListener("click", closeBlockedModal));
document.querySelector("#retest-all").addEventListener("click", async () => {
  const button = document.querySelector("#retest-all");
  const original = button.textContent;
  button.disabled = true;
  button.textContent = "Probando…";
  try {
    const result = await window.controlPlane.testAllProxies();
    if (result.total === 0) notify("No hay proxies bloqueados para reintentar.");
    else notify(`Reintento masivo: ${result.reactivated} de ${result.total} reactivados (${result.still_failing} siguen fallando).`);
  } catch (error) {
    notify(error.message, true);
  } finally {
    button.disabled = false;
    button.textContent = original;
    await refresh();
  }
});
document.addEventListener("keydown", event => {
  if (event.key !== "Escape") return;
  if (!worldMapModal.hidden) closeWorldMap();
  else if (!blockedModal.hidden) closeBlockedModal();
  else if (!workerModal.hidden) closeWorkerModal();
});
const failedSummary = document.querySelector("#failed-summary");
function showFailedWorkers() {
  workerSearch.value = "";
  statusFilter.value = "failed";
  applyWorkerFilters();
  document.querySelector("#worker-grid").scrollIntoView({ behavior: "smooth", block: "start" });
}
failedSummary.addEventListener("click", showFailedWorkers);
failedSummary.addEventListener("keydown", event => {
  if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();
    showFailedWorkers();
  }
});

refresh();
refreshProxyInventory();
setInterval(refresh, 1500);
