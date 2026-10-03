const API_BASE = "../";
const APP_BASE = location.pathname.split("/supervicion")[0].replace(/\/$/, "") + "/";
const WS_URL = (location.protocol === "https:" ? "wss:" : "ws:") + "//" + location.host + APP_BASE + "ws";
const STUN = [{ urls: "stun:stun.l.google.com:19302" }];

const el = (id) => document.getElementById(id);
const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
  "&":"&amp;", "<":"&lt;", ">":"&gt;", "\"":"&quot;", "'":"&#39;"
}[char]));

const state = {
  agents: [],
  selected: new Set(),
  sessions: new Map(),
  campaign: "",
  query: "",
  onlineOnly: false
};

async function api(path, options = {}) {
  return fetch(API_BASE + path, { credentials:"include", ...options });
}

async function requireLogin() {
  try {
    if (window.ZYNERDESK_SSO_READY) await window.ZYNERDESK_SSO_READY;
    const response = await api("api/auth/me");
    const data = await response.json();
    if (!data.user) throw new Error("no-session");
    el("whoName").textContent = `${data.user.username} (${data.user.role})`;
    return true;
  } catch {
    location.href = "../login.html";
    return false;
  }
}

async function loadCampaigns() {
  const response = await api("api/campaigns");
  if (response.status === 401) return location.href = "../login.html";
  const data = await response.json();
  const current = el("campaign").value;
  el("campaign").innerHTML = '<option value="">Todas las campa&ntilde;as</option>' +
    (data.campaigns || []).map((item) =>
      `<option value="${esc(item.campaign)}">${esc(item.campaign)} (${item.total})</option>`
    ).join("");
  el("campaign").value = current;
}

async function loadAgents() {
  try {
    const query = state.campaign ? `?campaign=${encodeURIComponent(state.campaign)}` : "";
    const response = await api("api/agents" + query);
    if (response.status === 401) return location.href = "../login.html";
    const data = await response.json();
    state.agents = data.agents || [];
    const known = new Set(state.agents.map((agent) => agent.agent_id));
    for (const id of state.selected) if (!known.has(id)) state.selected.delete(id);
    renderAgents();
  } catch {
    el("agentStat").textContent = "Error al cargar agentes";
  }
}

function agentLabel(agent) {
  return agent.tag || agent.hostname || agent.agent_id || "";
}

function visibleAgents() {
  const query = state.query.toLowerCase();
  let list = state.agents;
  if (state.onlineOnly) list = list.filter((agent) => agent.online);
  if (query) {
    list = list.filter((agent) =>
      `${agent.tag || ""} ${agent.hostname || ""} ${agent.ip_local || ""} ${agent.ip_public || ""}`.toLowerCase().includes(query)
    );
  }
  return [...list].sort((a, b) =>
    agentLabel(a).localeCompare(agentLabel(b), "es", { sensitivity: "base", numeric: true })
  );
}

function renderAgents() {
  const list = visibleAgents();
  const online = state.agents.filter((agent) => agent.online).length;
  el("agentStat").textContent = `${state.agents.length} agentes | ${online} online`;
  if (!list.length) {
    el("agentList").innerHTML = '<div class="empty">Sin agentes para mostrar.</div>';
    updateSelectionUi();
    return;
  }

  el("agentList").innerHTML = list.map((agent) => {
    const label = agent.tag || agent.hostname || agent.agent_id;
    const checked = state.selected.has(agent.agent_id);
    return `<label class="agent-row ${checked ? "selected" : ""} ${agent.online ? "" : "offline"}">
      <input type="checkbox" data-agent="${esc(agent.agent_id)}" ${checked ? "checked" : ""} ${agent.online ? "" : "disabled"}>
      <span class="state ${agent.online ? "online" : ""}"></span>
      <span class="agent-info">
        <strong>${esc(label)}</strong>
        <span>${esc(agent.hostname || agent.agent_id)} | ${esc(agent.ip_local || "sin IP")}</span>
      </span>
    </label>`;
  }).join("");
  updateSelectionUi();
}

function updateSelectionUi() {
  const count = state.selected.size;
  el("selectionCount").textContent = `${count} seleccionado${count === 1 ? "" : "s"}`;
  el("startView").textContent = `Ver seleccionados (${count})`;
  el("startView").disabled = count === 0;
}

function agentById(id) {
  return state.agents.find((agent) => agent.agent_id === id);
}

class RemoteSession {
  constructor(agent) {
    this.agent = agent;
    this.ws = null;
    this.pc = null;
    this.control = false;
    this.card = el("screenTemplate").content.firstElementChild.cloneNode(true);
    this.video = this.card.querySelector(".stage > video");
    this.overlay = this.card.querySelector(".control-overlay");
    this.hint = this.card.querySelector(".screen-hint");
    this.status = this.card.querySelector(".screen-status");
    this.controlButton = this.card.querySelector(".control-btn");
    this.card.querySelector(".screen-name strong").textContent = agent.tag || agent.hostname || agent.agent_id;
    this.card.querySelector(".screen-name small").textContent = agent.hostname || agent.agent_id;

    // ---- Camara web: se carga sola en cuanto el agente la soporte, sin
    // esperar clic. El boton solo sirve para que el admin la apague/prenda
    // manualmente; si otro admin ya la esta viendo, no se compite por ella
    // (se muestra "en uso" y no se reintenta sola).
    this.cameraPc = null;
    this.cameraState = "stopped"; // stopped|starting|viewing|stopping|error|occupied
    this.cameraAdminAllowed = false;
    this.cameraAgentCapable = false;
    this.cameraEnabled = true; // preferencia del admin para esta tarjeta
    this.cameraButton = this.card.querySelector(".camera-btn");
    this.cameraBox = this.card.querySelector(".cam-box");
    this.cameraVideo = this.cameraBox.querySelector("video");

    this.wireActions();
  }

  setStatus(text) {
    this.status.textContent = text;
  }

  send(message) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) this.ws.send(JSON.stringify(message));
  }

  sendControl(control) {
    if (this.control) this.send({ type:"control:input", agentId:this.agent.agent_id, control });
  }

  wireActions() {
    this.controlButton.addEventListener("click", () => {
      this.control = !this.control;
      this.controlButton.textContent = `Control ${this.control ? "ON" : "OFF"}`;
      this.controlButton.classList.toggle("on", this.control);
      this.card.classList.toggle("controlling", this.control);
      if (this.control) this.overlay.focus();
    });
    this.card.querySelector(".expand-btn").addEventListener("click", (event) => {
      const expanded = this.card.classList.toggle("expanded");
      event.currentTarget.textContent = expanded ? "Reducir" : "Ampliar";
    });
    this.card.querySelector(".close-btn").addEventListener("click", () => removeSession(this.agent.agent_id));
    this.cameraButton.addEventListener("click", () => {
      this.cameraEnabled = !this.cameraEnabled;
      this.updateCameraButton();
      if (this.cameraEnabled) {
        this.maybeStartCamera();
      } else if (this.cameraState !== "stopped") {
        this.send({ type:"camera:stop", agentId:this.agent.agent_id });
        this.setCameraStatus("stopping", "");
      }
    });

    const point = (event) => {
      const rect = this.overlay.getBoundingClientRect();
      return { x:event.clientX - rect.left, y:event.clientY - rect.top, width:rect.width, height:rect.height };
    };
    this.overlay.addEventListener("mousemove", (event) => this.sendControl({ type:"mouse", action:"move", ...point(event) }));
    this.overlay.addEventListener("mousedown", (event) => this.sendControl({ type:"mouse", action:"down", button:event.button, ...point(event) }));
    this.overlay.addEventListener("mouseup", (event) => this.sendControl({ type:"mouse", action:"up", button:event.button, ...point(event) }));
    this.overlay.addEventListener("contextmenu", (event) => event.preventDefault());
    this.overlay.addEventListener("wheel", (event) => {
      event.preventDefault();
      this.sendControl({ type:"mouse", action:"wheel", deltaX:event.deltaX, deltaY:event.deltaY });
    }, { passive:false });
    this.overlay.addEventListener("keydown", (event) => {
      event.preventDefault();
      this.sendControl({ type:"keyboard", action:"keydown", key:event.key, code:event.code });
    });
  }

  connect() {
    this.ws = new WebSocket(WS_URL);
    this.ws.addEventListener("open", () => {
      this.setStatus("Senalizacion OK | esperando agente");
      this.send({ type:"session:join", agentId:this.agent.agent_id });
    });
    this.ws.addEventListener("error", () => this.setStatus("Error de senalizacion"));
    this.ws.addEventListener("close", () => {
      if (state.sessions.has(this.agent.agent_id)) this.setStatus("Desconectado");
      this.closeCameraLocal();
    });
    this.ws.addEventListener("message", async (event) => {
      let message;
      try { message = JSON.parse(event.data); } catch { return; }
      if (message.type === "agent:offline") {
        this.hint.textContent = "El agente no esta conectado.";
        this.setStatus("Agente sin conexion");
        return;
      }
      if (message.type === "session:ready") {
        this.cameraAdminAllowed = Boolean(message.cameraAdminAllowed);
        this.cameraAgentCapable = Boolean(message.cameraAgentCapable);
        this.updateCameraAvailability();
        return;
      }
      if (message.type === "agent:capabilities") {
        this.cameraAgentCapable = Boolean(message.cameraAgentCapable);
        this.updateCameraAvailability();
        return;
      }
      if (message.type === "webrtc:offer") { await this.acceptOffer(message); return; }
      if (message.type === "webrtc:ice-candidate" && this.pc && message.candidate) {
        this.pc.addIceCandidate(message.candidate).catch(() => {});
        return;
      }
      if (message.type === "camera:offer") { await this.acceptCameraOffer(message); return; }
      if (message.type === "camera:ice-candidate" && this.cameraPc && message.candidate) {
        this.cameraPc.addIceCandidate(message.candidate).catch(() => {});
        return;
      }
      if (message.type === "camera:status") {
        this.setCameraStatus(message.state || "stopped", message.reason || "");
        if (["stopped", "error"].includes(message.state)) this.closeCameraLocal();
        return;
      }
      if (message.type === "camera:ended") {
        this.closeCameraLocal();
        this.setCameraStatus(message.state === "error" ? "error" : "stopped", message.error || message.reason || "");
        return;
      }
      if (message.type === "camera:error") {
        this.closeCameraLocal();
        const occupied = /activa/i.test(message.detail || "");
        this.setCameraStatus(occupied ? "occupied" : "error", message.detail || "");
        return;
      }
    });
  }

  // ---- Camara web (auto-carga si esta disponible, sin competir) ----

  updateCameraAvailability() {
    const available = this.cameraAdminAllowed && this.cameraAgentCapable;
    this.cameraButton.style.display = available ? "" : "none";
    if (!available) {
      this.cameraBox.style.display = "none";
      return;
    }
    this.updateCameraButton();
    this.maybeStartCamera();
  }

  updateCameraButton() {
    this.cameraButton.textContent = "Camara: " + (this.cameraEnabled ? "ON" : "OFF");
    this.cameraButton.classList.toggle("on", this.cameraEnabled);
    this.cameraButton.classList.toggle("off-manual", !this.cameraEnabled);
  }

  maybeStartCamera() {
    if (!this.cameraEnabled || !this.cameraAdminAllowed || !this.cameraAgentCapable) return;
    if (!["stopped", "error"].includes(this.cameraState)) return;
    this.setCameraStatus("starting", "Cargando camara automaticamente");
    this.send({ type:"camera:start", agentId:this.agent.agent_id });
  }

  setCameraStatus(state, detail) {
    this.cameraState = state;
    const label = this.cameraBox.querySelector(".cam-label");
    if (state === "viewing") {
      this.cameraBox.style.display = "block";
      label.textContent = "Camara";
    } else if (state === "starting") {
      this.cameraBox.style.display = "block";
      label.textContent = "Camara: cargando...";
    } else if (state === "occupied") {
      this.cameraBox.style.display = "block";
      label.textContent = "Camara: en uso por otro admin";
    } else if (state === "error") {
      this.cameraBox.style.display = "block";
      label.textContent = "Camara: " + (detail || "error");
    } else {
      this.cameraBox.style.display = "none";
    }
  }

  closeCameraLocal() {
    try { if (this.cameraPc) this.cameraPc.close(); } catch {}
    this.cameraPc = null;
    this.cameraVideo.srcObject = null;
  }

  async acceptCameraOffer(message) {
    this.closeCameraLocal();
    this.cameraPc = new RTCPeerConnection({ iceServers:STUN });
    this.cameraPc.addEventListener("track", (event) => {
      this.cameraVideo.srcObject = event.streams[0];
      this.cameraVideo.play().catch(() => {});
      this.setCameraStatus("viewing", "");
    });
    this.cameraPc.addEventListener("icecandidate", (event) => {
      if (event.candidate) this.send({ type:"camera:ice-candidate", agentId:this.agent.agent_id, candidate:event.candidate });
    });
    this.cameraPc.addEventListener("connectionstatechange", () => {
      const current = this.cameraPc && this.cameraPc.connectionState;
      if (current === "failed" || current === "disconnected") this.setCameraStatus("error", `WebRTC ${current}`);
    });
    try {
      await this.cameraPc.setRemoteDescription(message.offer);
      const answer = await this.cameraPc.createAnswer();
      await this.cameraPc.setLocalDescription(answer);
      this.send({ type:"camera:answer", agentId:this.agent.agent_id, answer });
    } catch {
      this.setCameraStatus("error", "No se pudo iniciar la camara");
    }
  }

  async acceptOffer(message) {
    this.setStatus("Negociando video...");
    if (this.pc) this.pc.close();
    this.pc = new RTCPeerConnection({ iceServers:STUN });
    this.pc.addEventListener("track", (event) => {
      this.video.srcObject = event.streams[0];
      this.video.play().catch(() => {});
      this.card.classList.add("streaming");
      this.setStatus("Pantalla en vivo");
    });
    this.pc.addEventListener("connectionstatechange", () => {
      const current = this.pc && this.pc.connectionState;
      if (current === "failed" || current === "disconnected") this.setStatus(`WebRTC ${current}`);
    });
    this.pc.addEventListener("icecandidate", (event) => {
      if (event.candidate) this.send({ type:"webrtc:ice-candidate", agentId:this.agent.agent_id, candidate:event.candidate });
    });
    try {
      await this.pc.setRemoteDescription(message.offer);
      const answer = await this.pc.createAnswer();
      await this.pc.setLocalDescription(answer);
      this.send({ type:"webrtc:answer", agentId:this.agent.agent_id, answer });
    } catch {
      this.setStatus("No se pudo iniciar el video");
    }
  }

  close() {
    try {
      if (this.cameraState !== "stopped" && this.ws && this.ws.readyState === WebSocket.OPEN) {
        this.send({ type:"camera:stop", agentId:this.agent.agent_id });
      }
    } catch {}
    try { if (this.pc) this.pc.close(); } catch {}
    this.closeCameraLocal();
    try { if (this.ws) this.ws.close(); } catch {}
    this.video.srcObject = null;
    this.card.remove();
  }
}

function startSelected() {
  const selected = new Set(state.selected);
  for (const id of [...state.sessions.keys()]) if (!selected.has(id)) removeSession(id);
  for (const id of selected) {
    if (state.sessions.has(id)) continue;
    const agent = agentById(id);
    if (!agent || !agent.online) continue;
    const session = new RemoteSession(agent);
    state.sessions.set(id, session);
    el("screenGrid").appendChild(session.card);
    session.connect();
  }
  updateMonitorUi();
}

function removeSession(id) {
  const session = state.sessions.get(id);
  if (!session) return;
  session.close();
  state.sessions.delete(id);
  updateMonitorUi();
}

function updateMonitorUi() {
  const grid = el("screenGrid");
  const welcome = grid.querySelector(".welcome");
  if (welcome) welcome.remove();
  const count = state.sessions.size;
  el("videowall").disabled = count === 0;
  el("monitorStat").textContent = count ? `${count} pantalla${count === 1 ? "" : "s"} conectando/en vivo` : "Sin sesiones activas";
  if (!count) {
    grid.innerHTML = '<div class="welcome"><strong>Selecciona dos o mas agentes</strong><span>Elige una campa&ntilde;a, marca los equipos y pulsa "Ver seleccionados".</span></div>';
    if (document.body.classList.contains("videowall")) exitVideowall();
  } else if (document.body.classList.contains("videowall")) {
    updateVideowallGrid();
  }
}

function updateVideowallGrid() {
  const count = Math.max(1, state.sessions.size);
  const viewportRatio = window.innerWidth / Math.max(1, window.innerHeight);
  const screenRatio = 16 / 9;
  const columns = Math.max(1, Math.ceil(Math.sqrt(count * viewportRatio / screenRatio)));
  const rows = Math.ceil(count / columns);
  el("screenGrid").style.setProperty("--wall-cols", columns);
  el("screenGrid").style.setProperty("--wall-rows", rows);
}

async function enterVideowall() {
  if (!state.sessions.size) return;
  for (const session of state.sessions.values()) {
    session.control = false;
    session.controlButton.textContent = "Control OFF";
    session.controlButton.classList.remove("on");
    session.card.classList.remove("controlling", "expanded");
    session.card.querySelector(".expand-btn").textContent = "Ampliar";
  }
  document.body.classList.add("videowall");
  updateVideowallGrid();
  try {
    if (!document.fullscreenElement) await document.documentElement.requestFullscreen();
  } catch {
    // The layout still fills the browser if fullscreen permission is denied.
  }
}

async function exitVideowall() {
  document.body.classList.remove("videowall");
  try {
    if (document.fullscreenElement) await document.exitFullscreen();
  } catch {}
}

el("agentList").addEventListener("change", (event) => {
  const checkbox = event.target.closest("input[data-agent]");
  if (!checkbox) return;
  if (checkbox.checked) state.selected.add(checkbox.dataset.agent);
  else state.selected.delete(checkbox.dataset.agent);
  renderAgents();
});

el("campaign").addEventListener("change", () => {
  state.campaign = el("campaign").value;
  state.selected.clear();
  loadAgents();
});
el("search").addEventListener("input", () => {
  state.query = el("search").value.trim();
  renderAgents();
});
el("onlineOnly").addEventListener("change", () => {
  state.onlineOnly = el("onlineOnly").checked;
  renderAgents();
});
el("selectOnline").addEventListener("click", () => {
  for (const agent of visibleAgents()) if (agent.online) state.selected.add(agent.agent_id);
  renderAgents();
});
el("clearSelection").addEventListener("click", () => {
  state.selected.clear();
  renderAgents();
});
el("startView").addEventListener("click", startSelected);
el("videowall").addEventListener("click", enterVideowall);
el("exitVideowall").addEventListener("click", exitVideowall);
el("columns").addEventListener("change", () => {
  const grid = el("screenGrid");
  grid.classList.remove("cols-2", "cols-3", "cols-4");
  if (el("columns").value !== "auto") grid.classList.add("cols-" + el("columns").value);
});
document.addEventListener("fullscreenchange", () => {
  if (!document.fullscreenElement) document.body.classList.remove("videowall");
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && document.body.classList.contains("videowall")) exitVideowall();
});
window.addEventListener("resize", () => {
  if (document.body.classList.contains("videowall")) updateVideowallGrid();
});
window.addEventListener("beforeunload", () => {
  for (const session of state.sessions.values()) session.close();
});

(async () => {
  if (!(await requireLogin())) return;
  await Promise.all([loadCampaigns(), loadAgents()]);
  setInterval(loadAgents, 3000);
})();
