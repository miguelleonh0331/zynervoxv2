const BASE = window.__APP_BASE__;
const $ = (selector) => document.querySelector(selector);
const state = {
  user: null,
  contacts: [],
  users: [],
  mentionUsers: [],
  selectedMentions: [],
  mentionResults: [],
  mentionIndex: 0,
  mentionRange: null,
  folders: [],
  tags: [],
  lines: [],
  allLines: [],
  autoReplies: [],
  quickReplies: [],
  reservations: [],
  active: null,
  filter: "all",
  searchResults: [],
  searchResultIndex: -1,
  typingTimer: null,
  typingSent: false,
  transcriptionEnabled: false,
  folderFilter: "all",
  tagFilter: "all",
  lineFilter: "all",
  socket: null,
  pendingFile: null,
  previewUrl: null,
  alertContactId: null,
  audioContext: null,
  waitingLevels: new Map(),
  incomingCall: null,
  activeCall: null,
  ringInterval: null,
  activeCallTimerInterval: null,
  serviceWindowHours: 20,
  windowState: null,
};
function showLineBadges() {
  return (
    ["admin", "supervisor"].includes(state.user?.role) || state.lines.length > 1
  );
}
function isFuture(value) {
  return Boolean(value && serverDate(value).getTime() > Date.now());
}
function isClosedToday(contact) {
  if (contact.status !== "closed" || !contact.resolved_at) return false;
  return serverDate(contact.resolved_at).toDateString() === new Date().toDateString();
}
function matchesInboxFilter(contact, filter = state.filter) {
  if (filter === "mine")
    return contact.status !== "closed" && contact.owner_user_id === state.user.id;
  const bucket = operationalBucket(contact);
  if (["new", "unanswered", "in_progress", "waiting", "sla", "snoozed", "closed_today"].includes(filter))
    return bucket === filter;
  return true;
}
function operationalBucket(contact) {
  if (contact.status === "closed" || contact.workflow_status === "resolved")
    return isClosedToday(contact) ? "closed_today" : "closed";
  if (isFuture(contact.snoozed_until)) return "snoozed";
  if (contact.status === "pending" || contact.workflow_status === "waiting_customer")
    return "waiting";
  if (contact.owner_user_id == null) return "new";
  if (contact.waiting_since)
    return waitingLevel(contact) === "red" ? "sla" : "unanswered";
  return "in_progress";
}
function updateFilterCounts() {
  document.querySelectorAll("[data-filter-count]").forEach((node) => {
    const filter = node.dataset.filterCount;
    node.textContent = state.contacts.filter((c) => matchesInboxFilter(c, filter) && (filter === "snoozed" || !isFuture(c.snoozed_until))).length;
  });
}
const INBOX_FILTER_LABELS = {
  all: "Todas",
  mine: "Mías",
  new: "Nuevas",
  unanswered: "Sin responder",
  in_progress: "En curso",
  waiting: "En espera",
  sla: "SLA vencido",
  snoozed: "Pospuestas",
  closed_today: "Cerradas hoy",
};

function showConversationList() {
  stopTyping();
  state.active = null;
  state.selectedMentions = [];
  closeMentionMenu();
  closeConversationSearch();
  $("#typingIndicator").classList.add("hidden");
  $("#activeChat").classList.add("hidden");
  $("#emptyChat").classList.add("hidden");
  $("#conversationListView").classList.remove("hidden");
  $("#detailPanel").classList.add("hidden");
  $("#appView").classList.add("list-active");
  $(".chat-panel").classList.add("list-active", "mobile-active");
}

async function api(path, options = {}) {
  const response = await fetch(`${BASE}/api${path}`, {
    headers: { "Content-Type": "application/json", ...(options.headers || {}) },
    ...options,
  });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401 && path !== "/login") {
    location.reload();
    throw new Error("La sesión expiró");
  }
  if (!response.ok) {
    const error = new Error(data.error || "Error de servidor");
    error.status = response.status;
    throw error;
  }
  return data;
}
async function optionalApi(path, fallback) {
  try {
    return await api(path);
  } catch (error) {
    if (error.status === 404) return fallback;
    throw error;
  }
}
function toast(text) {
  const node = $("#toast");
  node.textContent = text;
  node.classList.add("show");
  setTimeout(() => node.classList.remove("show"), 2600);
}
function initials(name) {
  return String(name || "?")
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0])
    .join("")
    .toUpperCase();
}
function escapeHtml(value) {
  const node = document.createElement("div");
  node.textContent = value ?? "";
  return node.innerHTML;
}
function time(value) {
  if (!value) return "";
  const d = serverDate(value);
  const today = new Date();
  const yesterday = new Date(today);
  yesterday.setDate(today.getDate() - 1);
  const clock = d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  if (d.toDateString() === today.toDateString()) return clock;
  if (d.toDateString() === yesterday.toDateString()) return `Ayer ${clock}`;
  return `${d.toLocaleDateString([], { day: "2-digit", month: "2-digit", year: "numeric" })} ${clock}`;
}
function serverDate(value) {
  return new Date(
    String(value).replace(" ", "T") + (String(value).includes("Z") ? "" : "Z"),
  );
}
function waitingMinutes(contact) {
  return contact.waiting_since
    ? Math.max(
        0,
        Math.floor(
          (Date.now() - serverDate(contact.waiting_since).getTime()) / 60000,
        ),
      )
    : 0;
}
function waitingLevel(contact) {
  const minutes = waitingMinutes(contact);
  return minutes >= 5
    ? "red"
    : minutes >= 2
      ? "orange"
      : minutes >= 1
        ? "yellow"
        : "";
}
function alertsEnabled() {
  return localStorage.getItem("zynerwaba_alerts") === "1";
}
function updateAlertButton() {
  $("#notificationToggle").textContent = alertsEnabled()
    ? "Alertas activas"
    : "Activar alertas de mensajes";
  $("#notificationToggle").classList.toggle("active", alertsEnabled());
}
function playAlertSound() {
  if (!alertsEnabled()) return;
  const AudioContext = window.AudioContext || window.webkitAudioContext;
  if (!AudioContext) return;
  state.audioContext ||= new AudioContext();
  const oscillator = state.audioContext.createOscillator();
  const gain = state.audioContext.createGain();
  oscillator.frequency.setValueAtTime(740, state.audioContext.currentTime);
  oscillator.frequency.setValueAtTime(
    920,
    state.audioContext.currentTime + 0.12,
  );
  gain.gain.setValueAtTime(0.001, state.audioContext.currentTime);
  gain.gain.exponentialRampToValueAtTime(
    0.16,
    state.audioContext.currentTime + 0.02,
  );
  gain.gain.exponentialRampToValueAtTime(
    0.001,
    state.audioContext.currentTime + 0.32,
  );
  oscillator.connect(gain).connect(state.audioContext.destination);
  oscillator.start();
  oscillator.stop(state.audioContext.currentTime + 0.34);
}
function showIncomingAlert(contactId, message) {
  const contact = state.contacts.find((item) => item.id === contactId);
  const title = contact?.name || contact?.phone || "Nuevo mensaje";
  const body =
    message.body ||
    (message.type === "image" ? "Imagen recibida" : "Documento recibido");
  state.alertContactId = contactId;
  $("#alertTitle").textContent = title;
  $("#alertBody").textContent = body;
  $("#messageAlert").classList.remove("hidden");
  clearTimeout(showIncomingAlert.timer);
  showIncomingAlert.timer = setTimeout(
    () => $("#messageAlert").classList.add("hidden"),
    7000,
  );
  playAlertSound();
  if (alertsEnabled() && Notification.permission === "granted") {
    const notice = new Notification(`Synermessage: ${title}`, {
      body,
      icon: `${BASE}/zynervox.png`,
      tag: `contact-${contactId}`,
    });
    notice.onclick = () => {
      window.focus();
      openContact(contactId);
      notice.close();
    };
  }
}

async function boot() {
  const { user, service_window_hours: serviceWindowHours } = await api("/me");
  if (!user) return;
  state.user = user;
  if (serviceWindowHours) state.serviceWindowHours = serviceWindowHours;
  if (user.role === "superadmin") {
    location.href = `${BASE}/empresas`;
    return;
  }
  $("#loginView").classList.add("hidden");
  $("#appView").classList.remove("hidden");
  $("#userName").textContent = user.display_name;
  if (user.role === "admin") {
    $("#adminTab").classList.remove("hidden");
    $("#healthTab").classList.remove("hidden");
    $("#broadcastTab").classList.remove("hidden");
    $("#physicalSendTab").classList.remove("hidden");
    $("#adminAssignWrap").classList.remove("hidden");
    await loadLines();
    await Promise.all([loadUsers(), loadAutoReplies()]);
  } else if (user.role === "supervisor") {
    $("#adminAssignWrap").classList.remove("hidden");
  } else {
    $("#poolFilter")?.classList.add("hidden");
    $("#folderFilterField").classList.add("hidden");
    $("#folderSelectWrap").classList.add("hidden");
  }
  updateAlertButton();
  connectSocket();
  await Promise.all([
    loadClassifications(),
    loadContacts(),
    loadMetrics(),
    loadReservations(),
    loadQuickReplies(),
    loadMentionUsers(),
  ]);
  setInterval(updateWaitingAlerts, 15000);
}
function connectSocket() {
  state.socket = io({ path: `${BASE}/socket.io`, transports: ["polling"] });
  state.socket.on("contacts:refresh", loadContacts);
  state.socket.on("contact:update", loadContacts);
  state.socket.on("message:new", async ({ contact_id, message }) => {
    await loadContacts();
    if (state.active?.id === contact_id) await loadMessages();
    if (message?.direction === "in" && !message.is_auto_response && !message.is_optout_response)
      showIncomingAlert(contact_id, message);
  });
  state.socket.on("message:classification", async ({ contact_id }) => {
    await loadContacts();
    if (state.active?.id === contact_id) await loadMessages();
  });
  state.socket.on("message:status", async ({ contact_id }) => {
    if (state.active?.id === contact_id) await loadMessages();
  });
  state.socket.on("typing:start", (data) => {
    if (data.user_id === state.user.id || data.contact_id !== state.active?.id) return;
    $("#typingIndicator").textContent = `${data.display_name || data.user_name || "Otro agente"} está escribiendo…`;
    $("#typingIndicator").classList.remove("hidden");
  });
  state.socket.on("typing:stop", (data) => {
    if (data.user_id !== state.user.id && data.contact_id === state.active?.id)
      $("#typingIndicator").classList.add("hidden");
  });
  state.socket.on("classifications:refresh", async () => {
    await loadClassifications();
    await loadContacts();
  });
  state.socket.on("auto-replies:refresh", loadAutoReplies);
  state.socket.on("reservations:refresh", async () => {
    await loadReservations();
    renderContacts();
  });
  state.socket.on("lines:refresh", async () => {
    await loadClassifications();
    if (state.user.role === "admin") {
      await loadLines();
      await loadUsers();
    }
  });
  state.socket.on("call:incoming", (data) => showIncomingCall(data));
  state.socket.on("call:claimed", (data) => {
    if (state.incomingCall?.call_id === data.call_id) hideIncomingCall();
  });
  state.socket.on("call:ended", (data) => {
    if (state.incomingCall?.call_id === data.call_id) hideIncomingCall();
    if (state.activeCall?.call_id === data.call_id) endActiveCallUI();
  });
}
function playRingTone() {
  const AudioContext = window.AudioContext || window.webkitAudioContext;
  if (!AudioContext) return;
  state.audioContext ||= new AudioContext();
  const oscillator = state.audioContext.createOscillator();
  const gain = state.audioContext.createGain();
  oscillator.frequency.setValueAtTime(480, state.audioContext.currentTime);
  oscillator.frequency.setValueAtTime(
    620,
    state.audioContext.currentTime + 0.3,
  );
  gain.gain.setValueAtTime(0.001, state.audioContext.currentTime);
  gain.gain.exponentialRampToValueAtTime(
    0.22,
    state.audioContext.currentTime + 0.02,
  );
  gain.gain.exponentialRampToValueAtTime(
    0.001,
    state.audioContext.currentTime + 0.8,
  );
  oscillator.connect(gain).connect(state.audioContext.destination);
  oscillator.start();
  oscillator.stop(state.audioContext.currentTime + 0.85);
}
function startRingTone() {
  stopRingTone();
  playRingTone();
  state.ringInterval = setInterval(playRingTone, 1600);
}
function stopRingTone() {
  if (state.ringInterval) clearInterval(state.ringInterval);
  state.ringInterval = null;
}
function showIncomingCall(data) {
  state.incomingCall = data;
  $("#callBannerName").textContent = data.name || `+${data.phone}`;
  $("#callBannerPhone").textContent = `+${data.phone}`;
  $("#callBanner").classList.remove("hidden");
  startRingTone();
}
function hideIncomingCall() {
  state.incomingCall = null;
  $("#callBanner").classList.add("hidden");
  stopRingTone();
}
function showActiveCallBar(label) {
  $("#activeCallName").textContent = `En llamada con ${label}`;
  $("#activeCallBar").classList.remove("hidden");
  state.activeCallTimerInterval = setInterval(() => {
    if (!state.activeCall) return;
    const seconds = Math.floor(
      (Date.now() - state.activeCall.startedAt) / 1000,
    );
    const mm = String(Math.floor(seconds / 60)).padStart(2, "0");
    const ss = String(seconds % 60).padStart(2, "0");
    $("#activeCallTimer").textContent = `${mm}:${ss}`;
  }, 1000);
}
function endActiveCallUI() {
  if (state.activeCallTimerInterval)
    clearInterval(state.activeCallTimerInterval);
  state.activeCallTimerInterval = null;
  if (state.activeCall) {
    try {
      state.activeCall.pc?.close();
    } catch {
      /* noop */
    }
    state.activeCall.stream?.getTracks().forEach((track) => track.stop());
  }
  state.activeCall = null;
  $("#activeCallBar").classList.add("hidden");
  $("#activeCallTimer").textContent = "00:00";
  $("#remoteAudio").srcObject = null;
}
function waitForIceGathering(pc, timeoutMs = 5000) {
  if (pc.iceGatheringState === "complete") return Promise.resolve();
  return new Promise((resolve) => {
    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      clearTimeout(timeout);
      pc.removeEventListener("icegatheringstatechange", onStateChange);
      resolve();
    };
    const onStateChange = () => {
      if (pc.iceGatheringState === "complete") finish();
    };
    const timeout = setTimeout(finish, timeoutMs);
    pc.addEventListener("icegatheringstatechange", onStateChange);
  });
}
async function answerIncomingCall() {
  const call = state.incomingCall;
  if (!call) return;
  hideIncomingCall();
  try {
    const pc = new RTCPeerConnection({
      iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
    });
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    stream.getTracks().forEach((track) => pc.addTrack(track, stream));
    pc.ontrack = (event) => {
      $("#remoteAudio").srcObject = event.streams[0];
    };
    await pc.setRemoteDescription({ type: "offer", sdp: call.sdp });
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    await waitForIceGathering(pc);
    await api(`/calls/${call.call_id}/answer`, {
      method: "POST",
      body: JSON.stringify({ sdp: pc.localDescription.sdp }),
    });
    state.activeCall = {
      call_id: call.call_id,
      contact_id: call.contact_id,
      pc,
      stream,
      startedAt: Date.now(),
    };
    showActiveCallBar(call.name || `+${call.phone}`);
    await loadContacts();
  } catch (error) {
    toast(`No se pudo contestar la llamada: ${error.message}`);
  }
}
async function rejectIncomingCall() {
  const call = state.incomingCall;
  if (!call) return;
  hideIncomingCall();
  try {
    await api(`/calls/${call.call_id}/reject`, { method: "POST" });
  } catch (error) {
    toast(error.message);
  }
}
async function hangupActiveCall() {
  if (!state.activeCall) return;
  const callId = state.activeCall.call_id;
  endActiveCallUI();
  try {
    await api(`/calls/${callId}/hangup`, { method: "POST" });
  } catch (error) {
    toast(error.message);
  }
}
async function loadClassifications() {
  const data = await optionalApi("/classifications", {
    folders: [],
    tags: [],
    lines: state.allLines,
  });
  state.folders = data.folders;
  state.tags = data.tags;
  state.lines = data.lines || [];
  renderFolderFilters();
  renderTagFilters();
  renderLineFilters();
  renderClassificationAdmin();
  populateContactLineSelect();
  if (state.active) {
    renderContactClassifications();
    updateChatLineBadge();
  }
}
async function loadLines() {
  if (state.user?.role !== "admin") return;
  state.allLines = await api("/my-lines");
  renderLineAdmin();
  populateUserFormLines();
}
async function loadMetrics() {
  const m = await optionalApi("/metrics", {
    open: state.contacts.filter((contact) => contact.status !== "closed").length,
    unassigned: state.contacts.filter((contact) => contact.owner_user_id == null).length,
    sentToday: 0,
    receivedToday: 0,
  });
  $("#metrics").innerHTML = [
    ["Abiertos", m.open],
    ["Sin asignar", m.unassigned],
    ["Enviados", m.sentToday],
    ["Recibidos", m.receivedToday],
  ]
    .map(
      ([label, value]) =>
        `<div class="metric"><strong>${value}</strong><span>${label}</span></div>`,
    )
    .join("");
}
async function loadContacts() {
  const activeId = state.active?.id ?? null;
  state.contacts = await api("/contacts");
  state.active = activeId
    ? state.contacts.find((contact) => contact.id === activeId) || null
    : null;
  updateFilterCounts();
  renderContacts();
  updateWaitingAlerts();
  await loadMetrics();
}
async function loadQuickReplies() {
  state.quickReplies = await optionalApi("/quick-replies", []);
  renderQuickReplyOptions();
  if (state.user?.role === "admin")
    $("#quickReplyAdminList").innerHTML =
      state.quickReplies
        .map(
          (r) =>
            `<div class="classification-row"><span><strong>${escapeHtml(r.label)}</strong><small>${escapeHtml(r.category)} · ${escapeHtml(r.body)}</small></span></div>`,
        )
        .join("") || '<p class="muted">Sin respuestas rápidas.</p>';
}
function renderQuickReplyOptions() {
  const query = ($("#quickReplySearch")?.value || "").trim().toLowerCase(),
    select = $("#quickReplySelect");
  select.innerHTML =
    '<option value="">Respuesta rápida…</option>' +
    state.quickReplies
      .map((r, i) => ({ r, i }))
      .filter(
        ({ r }) =>
          !query ||
          `${r.category} ${r.label} ${r.body}`.toLowerCase().includes(query),
      )
      .map(
        ({ r, i }) =>
          `<option value="${i}">${escapeHtml(r.category)} · ${escapeHtml(r.label)}</option>`,
      )
      .join("");
}
async function loadMentionUsers() {
  state.mentionUsers = await optionalApi("/mention-users", state.users);
  if (state.user?.role === "supervisor") {
    $("#ownerSelect").innerHTML =
      '<option value="">Sin asignar</option>' +
      state.mentionUsers
        .filter((u) => u.role === "agent" && u.active !== false)
        .map(
          (u) =>
            `<option value="${u.id}">${escapeHtml(u.display_name)}</option>`,
        )
        .join("");
  }
}
function mentionSearchValue(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase();
}
function closeMentionMenu() {
  state.mentionResults = [];
  state.mentionRange = null;
  $("#mentionMenu").classList.add("hidden");
  $("#internalNoteBody").setAttribute("aria-expanded", "false");
}
function renderMentionMenu() {
  const menu = $("#mentionMenu");
  if (!state.mentionResults.length) return closeMentionMenu();
  menu.innerHTML = state.mentionResults
    .map(
      (user, index) =>
        `<button type="button" class="mention-option ${index === state.mentionIndex ? "active" : ""}" role="option" aria-selected="${index === state.mentionIndex}" data-mention-index="${index}"><span><strong>${escapeHtml(user.display_name)}</strong><small>@${escapeHtml(user.username)} · ${user.role === "admin" ? "Administrador" : user.role === "supervisor" ? "Supervisor" : "Agente"}</small></span><i class="presence-dot ${user.online ? "online" : ""}" title="${user.online ? "Conectado" : "Desconectado"}"></i></button>`,
    )
    .join("");
  menu.classList.remove("hidden");
  $("#internalNoteBody").setAttribute("aria-expanded", "true");
  menu.querySelectorAll("[data-mention-index]").forEach(
    (button) =>
      (button.onmousedown = (event) => {
        event.preventDefault();
        selectMention(Number(button.dataset.mentionIndex));
      }),
  );
}
function updateMentionMenu() {
  const input = $("#internalNoteBody"),
    cursor = input.selectionStart,
    text = input.value.slice(0, cursor),
    match = text.match(/(?:^|\s)@([^@\n]*)$/);
  if (!match) return closeMentionMenu();
  const query = mentionSearchValue(match[1].trim()),
    start = cursor - match[1].length - 1;
  state.mentionRange = { start, end: cursor };
  state.mentionResults = state.mentionUsers
    .filter(
      (user) =>
        !query ||
        mentionSearchValue(`${user.display_name} ${user.username}`).includes(
          query,
        ),
    )
    .slice(0, 8);
  state.mentionIndex = 0;
  renderMentionMenu();
}
function selectMention(index) {
  const user = state.mentionResults[index],
    range = state.mentionRange,
    input = $("#internalNoteBody");
  if (!user || !range) return;
  const token = `@${user.display_name} `;
  input.value =
    input.value.slice(0, range.start) + token + input.value.slice(range.end);
  const cursor = range.start + token.length;
  input.setSelectionRange(cursor, cursor);
  if (!state.selectedMentions.some((item) => item.id === user.id))
    state.selectedMentions.push(user);
  closeMentionMenu();
  input.focus();
}
async function loadConversationDetails() {
  if (!state.active) return;
  const [notes, timeline] = await Promise.all([
    optionalApi(`/contacts/${state.active.id}/internal-notes`, []),
    optionalApi(`/contacts/${state.active.id}/timeline`, []),
  ]);
  $("#internalNoteList").innerHTML =
    notes
      .map(
        (n) =>
          `<div><strong>${escapeHtml(n.user_name || "Sistema")}</strong><small>${escapeHtml(n.body)} · ${serverDate(n.created_at).toLocaleString()}</small></div>`,
      )
      .join("") || '<small class="muted">Sin notas internas.</small>';
  $("#conversationTimeline").innerHTML =
    timeline
      .slice(0, 20)
      .map(
        (e) =>
          `<div><small>${escapeHtml(e.actor_name || "Sistema")} · ${escapeHtml(e.event_type)} · ${serverDate(e.created_at).toLocaleString()}</small></div>`,
      )
      .join("") || '<small class="muted">Sin eventos.</small>';
}
function updateWaitingAlerts() {
  if (!state.user) return;
  const waiting = state.contacts
    .filter((contact) => contact.owner_user_id != null && operationalBucket(contact) !== "snoozed" && waitingLevel(contact))
    .sort((a, b) => serverDate(a.waiting_since) - serverDate(b.waiting_since));
  const alert = $("#waitingAlert");
  if (!waiting.length) {
    alert.className = "waiting-alert hidden";
    return;
  }
  const oldest = waiting[0];
  const level = waitingLevel(oldest);
  const minutes = waitingMinutes(oldest);
  alert.className = `waiting-alert ${level}`;
  $("#waitingAlertTitle").textContent =
    `${waiting.length} cliente${waiting.length === 1 ? "" : "s"} esperando`;
  $("#waitingAlertBody").textContent =
    `${oldest.name || oldest.phone}: ${minutes} min sin respuesta humana`;
  alert.dataset.contactId = oldest.id;
  for (const contact of waiting) {
    const current = waitingLevel(contact);
    const previous = state.waitingLevels.get(contact.id);
    if (current !== previous) {
      state.waitingLevels.set(contact.id, current);
      if (alertsEnabled()) playAlertSound();
    }
  }
  renderContacts(false);
}
function renderContacts(refreshAlert = true) {
  const query = $("#search").value.toLowerCase();
  const rows = state.contacts.filter((c) => {
    if (!matchesInboxFilter(c)) return false;
    if (state.filter !== "snoozed" && isFuture(c.snoozed_until)) return false;
    if (state.folderFilter === "none" && c.folder_id != null) return false;
    if (
      state.folderFilter !== "all" &&
      state.folderFilter !== "none" &&
      c.folder_id !== Number(state.folderFilter)
    )
      return false;
    if (
      state.tagFilter !== "all" &&
      !(c.tags || []).some((tag) => tag.id === Number(state.tagFilter))
    )
      return false;
    if (state.lineFilter !== "all" && c.line_id !== Number(state.lineFilter))
      return false;
    return `${c.name || ""} ${c.phone} ${c.lead_id || ""}`
      .toLowerCase()
      .includes(query);
  });
  const visibleReservations =
    state.user.role === "agent" && state.tagFilter === "all"
      ? state.reservations
          .filter(
            (item) =>
              !item.fulfilled_at &&
              (state.lineFilter === "all" ||
                item.line_id === Number(state.lineFilter)) &&
              `${item.phone} ${item.lead_id || ""}`.includes(query),
          )
      : [];
  const reservationRows = visibleReservations
          .map(
            (item) =>
              `<article class="contact reserved-contact" title="El chat se habilitará cuando el cliente escriba"><div class="avatar">R</div><div class="contact-copy"><strong>+${escapeHtml(item.phone)}</strong><span>Reservado, esperando mensaje</span><div class="contact-labels"><small class="reservation-pill">Acceso exclusivo</small>${showLineBadges() && item.line_name ? `<small class="line-pill" style="--label-color:${item.line_color}">${escapeHtml(item.line_name)}</small>` : ""}${item.lead_id ? `<small class="lead-pill">Lead ${escapeHtml(item.lead_id)}</small>` : ""}</div></div><time>${time(item.created_at)}</time></article>`,
          )
          .join("");
  const contactRows = rows
    .map((c) => {
      const level = waitingLevel(c);
      return `<article class="contact ${state.active?.id === c.id ? "active" : ""} ${level ? `wait-${level}` : ""}" data-id="${c.id}" role="button" tabindex="0"><div class="avatar">${initials(c.name || c.phone)}</div><div class="contact-copy"><strong>${escapeHtml(c.name || c.phone)}</strong><span>${escapeHtml(c.last_body || "Sin mensajes")}</span><div class="contact-labels">${level ? `<small class="wait-pill">${waitingMinutes(c)} min esperando</small>` : ""}${showLineBadges() && c.line_name ? `<small class="line-pill" style="--label-color:${c.line_color}">${escapeHtml(c.line_name)}</small>` : ""}${c.folder_name ? `<small class="folder-pill" style="--label-color:${c.folder_color}">${escapeHtml(c.folder_name)}</small>` : ""}${(c.tags || []).map((tag) => `<small class="tag-pill" style="--label-color:${tag.color}">${escapeHtml(tag.name)}</small>`).join("")}${c.owner_name ? `<small class="owner-pill">${escapeHtml(c.owner_name)}</small>` : ""}</div></div><time>${time(c.last_message_at)}</time></article>`;
    })
    .join("");
  const visibleCount = rows.length + visibleReservations.length;
  const filterLabel = INBOX_FILTER_LABELS[state.filter] || "Conversaciones";
  $("#conversationListTitle").textContent = filterLabel;
  $("#conversationListSummary").textContent = `${visibleCount} ${visibleCount === 1 ? "conversación" : "conversaciones"}`;
  $("#contactList").innerHTML =
    reservationRows + contactRows ||
    `<div class="conversation-empty"><strong>No hay conversaciones en ${escapeHtml(filterLabel.toLowerCase())}</strong><span>Prueba otra carpeta o modifica los filtros de clasificación.</span></div>`;
  document
    .querySelectorAll("#contactList .contact[data-id]")
    .forEach((node) => {
      const open = () => openContact(Number(node.dataset.id));
      node.onclick = open;
      node.onkeydown = (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          open();
        }
      };
    });
}
async function loadAutoReplies() {
  if (state.user?.role !== "admin") return;
  state.autoReplies = await optionalApi("/auto-replies", []);
  renderAutoReplies();
}
function renderAutoReplies() {
  $("#autoReplyList").innerHTML =
    state.autoReplies
      .map(
        (reply) =>
          `<div class="auto-reply-row"><div><strong>${escapeHtml(reply.name)}</strong><small>${escapeHtml(reply.body)}</small><div class="auto-button-preview"><span>${escapeHtml(reply.button_1_title)}</span><span>${escapeHtml(reply.button_2_title)}</span></div></div><button type="button" data-auto-toggle="${reply.id}" class="${reply.enabled ? "enabled" : ""}">${reply.enabled ? "Activo" : "Activar"}</button><button type="button" data-auto-edit="${reply.id}">Editar</button><button type="button" class="danger-link" data-auto-delete="${reply.id}">Eliminar</button></div>`,
      )
      .join("") || '<p class="muted">No hay mensajes automáticos.</p>';
  document.querySelectorAll("[data-auto-toggle]").forEach(
    (button) =>
      (button.onclick = async () => {
        const reply = state.autoReplies.find(
          (item) => item.id === Number(button.dataset.autoToggle),
        );
        await api(`/auto-replies/${reply.id}`, {
          method: "PATCH",
          body: JSON.stringify({ enabled: !reply.enabled }),
        });
        await loadAutoReplies();
      }),
  );
  document.querySelectorAll("[data-auto-edit]").forEach(
    (button) =>
      (button.onclick = async () => {
        const reply = state.autoReplies.find(
          (item) => item.id === Number(button.dataset.autoEdit),
        );
        const name = prompt("Nombre de la respuesta", reply.name);
        if (!name) return;
        const body = prompt("Speech de bienvenida", reply.body);
        if (!body) return;
        const button1 = prompt(
          "Texto del botón positivo",
          reply.button_1_title,
        );
        if (!button1) return;
        const button2 = prompt(
          "Texto del botón negativo",
          reply.button_2_title,
        );
        if (!button2) return;
        await api(`/auto-replies/${reply.id}`, {
          method: "PATCH",
          body: JSON.stringify({
            name,
            body,
            button_1_title: button1,
            button_2_title: button2,
          }),
        });
        await loadAutoReplies();
      }),
  );
  document.querySelectorAll("[data-auto-delete]").forEach(
    (button) =>
      (button.onclick = async () => {
        if (!confirm("¿Eliminar esta respuesta automática?")) return;
        await api(`/auto-replies/${button.dataset.autoDelete}`, {
          method: "DELETE",
        });
        await loadAutoReplies();
      }),
  );
}
async function loadReservations() {
  if (!state.user) return;
  state.reservations = await optionalApi("/assignment-reservations", []);
  if (state.user.role === "admin") renderReservations();
  else renderContacts();
}
function renderReservations() {
  const agents = state.users.filter(
    (user) => user.role === "agent" && user.active,
  );
  $("#reservationList").innerHTML =
    state.reservations
      .map((item) => {
        const fulfilled = Boolean(item.fulfilled_at);
        const options = agents
          .map(
            (agent) =>
              `<option value="${agent.id}" ${agent.id === item.owner_user_id ? "selected" : ""}>${escapeHtml(agent.display_name)} (@${escapeHtml(agent.username)})</option>`,
          )
          .join("");
        return `<div class="reservation-row ${fulfilled ? "fulfilled" : "pending"}"><div><strong>+${escapeHtml(item.phone)}</strong> ${item.line_name ? `<small class="line-pill" style="--label-color:${item.line_color}">${escapeHtml(item.line_name)}</small>` : ""}<small>${escapeHtml(item.contact_name || (fulfilled ? "Cliente registrado" : "Todavía no escribió"))}</small>${item.lead_id ? `<span class="reservation-lead">Lead ID: ${escapeHtml(item.lead_id)}</span>` : ""}</div><span class="reservation-status">${fulfilled ? "Registrado" : "Pendiente"}</span><select data-reservation-owner="${item.id}" ${fulfilled ? "disabled" : ""}>${options}</select><div class="reservation-meta"><span>${escapeHtml(item.actor || "api")}</span><time>${serverDate(item.created_at).toLocaleString()}</time></div>${fulfilled ? `<button type="button" data-open-reservation="${item.contact_id}">Abrir cliente</button>` : `<button type="button" class="danger-link" data-cancel-reservation="${item.id}">Cancelar</button>`}</div>`;
      })
      .join("") || '<p class="muted">No existen reservas API.</p>';
  document.querySelectorAll("[data-reservation-owner]").forEach(
    (select) =>
      (select.onchange = async () => {
        await api(
          `/assignment-reservations/${select.dataset.reservationOwner}`,
          {
            method: "PATCH",
            body: JSON.stringify({ owner_user_id: select.value }),
          },
        );
        await loadReservations();
        toast("Reserva reasignada");
      }),
  );
  document.querySelectorAll("[data-cancel-reservation]").forEach(
    (button) =>
      (button.onclick = async () => {
        if (!confirm("¿Cancelar esta reserva?")) return;
        await api(
          `/assignment-reservations/${button.dataset.cancelReservation}`,
          { method: "DELETE" },
        );
        await loadReservations();
        toast("Reserva cancelada");
      }),
  );
  document.querySelectorAll("[data-open-reservation]").forEach(
    (button) =>
      (button.onclick = () => {
        $("#adminView").classList.add("hidden");
        openContact(Number(button.dataset.openReservation));
      }),
  );
}
async function openContact(id) {
  stopTyping();
  state.active = state.contacts.find((c) => c.id === id);
  if (!state.active) return;
  state.selectedMentions = [];
  $("#internalNoteBody").value = "";
  closeMentionMenu();
  api(`/contacts/${state.active.id}/opened`, {
    method: "POST",
    body: "{}",
  }).catch(() => {});
  $("#emptyChat").classList.add("hidden");
  $("#conversationListView").classList.add("hidden");
  $("#activeChat").classList.remove("hidden");
  $("#detailPanel").classList.remove("hidden");
  $("#appView").classList.remove("list-active");
  $(".chat-panel").classList.remove("list-active");
  $(".chat-panel").classList.add("mobile-active");
  $("#chatName").textContent = state.active.name || state.active.phone;
  $("#chatPhone").textContent = `+${state.active.phone}`;
  $("#chatAvatar").textContent = initials(
    state.active.name || state.active.phone,
  );
  $("#contactStatus").value =
    state.active.status === "closed"
      ? "resolved"
      : state.active.workflow_status ||
        (state.active.status === "pending" ? "pending" : "in_progress");
  $("#detailName").value = state.active.name || "";
  $("#detailLeadId").value = state.active.lead_id || "";
  $("#detailNotes").value = state.active.notes || "";
  if (["admin", "supervisor"].includes(state.user.role))
    $("#ownerSelect").value = state.active.owner_user_id || "";
  updateChatLineBadge();
  renderSnoozeStatus();
  closeConversationSearch();
  renderContactClassifications();
  renderContacts();
  await Promise.all([loadMessages(), loadConversationDetails()]);
}
async function loadMessages() {
  const messages = await api(`/contacts/${state.active.id}/messages`);
  $("#messageList").innerHTML = messages
    .map(
      (m) =>
        `<div class="message ${m.direction} ${m.media_path ? "has-media" : ""} ${m.is_auto_response ? "autoanswer-message" : ""} ${m.is_optout_response ? "optout-message" : ""}" data-message-id="${m.id}">${m.is_auto_response ? '<span class="autoanswer-label">autoanswer</span>' : ""}${m.is_optout_response ? '<span class="optout-label">no interesado</span>' : ""}${messageContent(m)}<footer>${["admin", "supervisor"].includes(state.user.role) && m.direction === "in" && !m.is_optout_response ? `<button type="button" class="autoanswer-toggle" data-autoanswer-id="${m.id}" data-autoanswer-enabled="${m.is_auto_response ? "1" : "0"}">${m.is_auto_response ? "Desmarcar autoanswer" : "Marcar autoanswer"}</button>` : ""}${m.user_name ? escapeHtml(m.user_name) + " · " : ""}${time(m.created_at)}${m.direction === "out" ? statusChecks(m.status) : ""}</footer></div>`,
    )
    .join("");
  document.querySelectorAll("[data-autoanswer-id]").forEach((button) => {
    button.onclick = async () => {
      button.disabled = true;
      try {
        await api(`/messages/${button.dataset.autoanswerId}/autoanswer`, {
          method: "PATCH",
          body: JSON.stringify({ enabled: button.dataset.autoanswerEnabled !== "1" }),
        });
        await Promise.all([loadContacts(), loadMessages()]);
      } catch (error) {
        toast(error.message);
        button.disabled = false;
      }
    };
  });
  refreshServiceWindow().catch(() => {});
  scrollMessagesToBottom();
  document.querySelectorAll("#messageList img").forEach((image) => {
    if (!image.complete)
      image.addEventListener("load", scrollMessagesToBottom, { once: true });
  });
  document.querySelectorAll("[data-transcribe-message]").forEach((button) => {
    button.onclick = async () => {
      button.disabled = true;
      button.textContent = "Transcribiendo…";
      try {
        const data = await api("/ai/transcribe", { method: "POST", body: JSON.stringify({ message_id: Number(button.dataset.transcribeMessage) }) });
        const transcript = document.querySelector(`[data-transcript-for="${button.dataset.transcribeMessage}"]`);
        transcript.textContent = data.text;
        transcript.classList.remove("hidden");
        button.textContent = data.cached ? "Transcripción guardada" : "Transcripción lista";
      } catch (error) {
        button.textContent = "Transcribir";
        toast(error.message);
      } finally { button.disabled = false; }
    };
  });
}
function scrollMessagesToBottom() {
  const list = $("#messageList");
  requestAnimationFrame(() => {
    list.scrollTop = list.scrollHeight;
  });
}
function statusChecks(status) {
  if (status === "read")
    return ' <span class="checks read" title="Leído">✓✓</span>';
  if (status === "delivered")
    return ' <span class="checks" title="Entregado">✓✓</span>';
  if (status === "sent")
    return ' <span class="checks" title="Enviado">✓</span>';
  if (status === "failed" || status === "error")
    return ' <span class="checks failed" title="Error">!</span>';
  return "";
}
function messageContent(message) {
  const caption =
    message.body && !/^\[(Imagen|Documento)/.test(message.body)
      ? `<div class="media-caption">${escapeHtml(message.body)}</div>`
      : "";
  if (message.media_path && message.type === "image") {
    const source = `${BASE}/api/media/${encodeURIComponent(message.media_path)}`;
    return `<a class="image-message" href="${source}" target="_blank"><img src="${source}" alt="${escapeHtml(message.media_name || "Imagen")}"></a>${caption}`;
  }
  if (message.media_path && message.type === "document") {
    const source = `${BASE}/api/media/${encodeURIComponent(message.media_path)}?download=1`;
    return `<a class="document-message" href="${source}"><span class="document-icon">▤</span><span><strong>${escapeHtml(message.media_name || "Documento")}</strong><small>${escapeHtml(message.mime_type || "Archivo")}</small></span><span class="download-icon">↓</span></a>${caption}`;
  }
  if (message.media_path && (message.type === "audio" || String(message.mime_type || "").startsWith("audio/"))) {
    const source = `${BASE}/api/media/${encodeURIComponent(message.media_path)}`;
    return `<div class="audio-message"><audio controls preload="metadata" src="${source}"></audio><button type="button" class="transcribe-button" data-transcribe-message="${message.id}">${message.transcript ? "Transcripción" : "Transcribir"}</button><div class="audio-transcript ${message.transcript ? "" : "hidden"}" data-transcript-for="${message.id}">${message.transcript ? escapeHtml(message.transcript) : ""}</div></div>${caption}`;
  }
  return escapeHtml(message.body || `[${message.type}]`);
}
async function loadUsers() {
  state.users = await api("/users");
  $("#ownerSelect").innerHTML =
    '<option value="">Sin asignar</option>' +
    state.users
      .filter((u) => u.role === "agent" && u.active)
      .map(
        (u) => `<option value="${u.id}">${escapeHtml(u.display_name)}</option>`,
      )
      .join("");
  const activeLines = state.allLines.filter((line) => line.active);
  $("#userList").innerHTML = state.users
    .map((u) => {
      const managed = ["agent", "supervisor"].includes(u.role);
      const roleLabel =
        u.role === "admin"
          ? "Administrador"
          : u.role === "supervisor"
            ? "Supervisor"
            : "Operador";
      return `<div class="user-row"><div><strong>${escapeHtml(u.display_name)}</strong><small>@${escapeHtml(u.username)} · ${roleLabel} · ${u.contact_count} clientes</small>${managed && activeLines.length ? `<div class="line-checkboxes" data-user-lines="${u.id}">${activeLines.map((line) => `<label style="--label-color:${line.color}" class="${u.lines.some((ul) => ul.id === line.id) ? "checked" : ""}"><input type="checkbox" value="${line.id}" ${u.lines.some((ul) => ul.id === line.id) ? "checked" : ""}> ${escapeHtml(line.name)}</label>`).join("")}</div>` : ""}</div><span class="user-state ${u.active ? "active" : ""}">${u.active ? "Activo" : "Bloqueado"}</span>${managed ? `<button type="button" data-edit-user="${u.id}">Editar</button><button data-toggle="${u.id}" data-active="${u.active}">${u.active ? "Bloquear" : "Activar"}</button>` : '<small class="muted">Asignado por superadministrador</small>'}</div>`;
    })
    .join("");
  document.querySelectorAll("[data-toggle]").forEach(
    (button) =>
      (button.onclick = async () => {
        await api(`/users/${button.dataset.toggle}`, {
          method: "PATCH",
          body: JSON.stringify({ active: button.dataset.active !== "1" }),
        });
        await loadUsers();
      }),
  );
  document
    .querySelectorAll("[data-edit-user]")
    .forEach(
      (button) =>
        (button.onclick = () => openUserEdit(Number(button.dataset.editUser))),
    );
  document.querySelectorAll("[data-user-lines]").forEach((box) =>
    box.querySelectorAll("input[type=checkbox]").forEach(
      (input) =>
        (input.onchange = async () => {
          const lineIds = [...box.querySelectorAll("input:checked")].map((el) =>
            Number(el.value),
          );
          input.closest("label").classList.toggle("checked", input.checked);
          try {
            await api(`/users/${box.dataset.userLines}`, {
              method: "PATCH",
              body: JSON.stringify({ line_ids: lineIds }),
            });
            toast("Líneas del usuario actualizadas");
          } catch (error) {
            toast(error.message);
          }
        }),
    ),
  );
}
function renderFolderFilters() {
  const select = $("#folderFilterSelect");
  select.innerHTML =
    '<option value="all">Todas las carpetas</option><option value="none">Sin clasificar</option>' +
    state.folders
      .map(
        (folder) =>
          `<option value="${folder.id}">${escapeHtml(folder.name)}</option>`,
      )
      .join("");
  select.value = String(state.folderFilter);
  select.onchange = () => {
    state.folderFilter = select.value;
    showConversationList();
    renderContacts();
  };
}
function renderTagFilters() {
  const select = $("#tagFilterSelect");
  select.innerHTML =
    '<option value="all">Todas las etiquetas</option>' +
    state.tags
      .map(
        (tag) => `<option value="${tag.id}">${escapeHtml(tag.name)}</option>`,
      )
      .join("");
  select.value = String(state.tagFilter);
  select.onchange = () => {
    state.tagFilter = select.value;
    showConversationList();
    renderContacts();
  };
}
function renderLineFilters() {
  const field = $("#lineFilterField");
  if (state.lines.length <= 1) {
    field.classList.add("hidden");
    state.lineFilter = "all";
    return;
  }
  field.classList.remove("hidden");
  const select = $("#lineFilterSelect");
  select.innerHTML =
    '<option value="all">Todas las líneas</option>' +
    state.lines
      .map(
        (line) =>
          `<option value="${line.id}">${escapeHtml(line.name)}</option>`,
      )
      .join("");
  select.value = String(state.lineFilter);
  select.onchange = () => {
    state.lineFilter = select.value;
    showConversationList();
    renderContacts();
  };
}
function populateContactLineSelect() {
  const wrap = $("#contactLineWrap");
  const select = $("#contactLineSelect");
  if (state.lines.length <= 1) {
    wrap.classList.add("hidden");
    return;
  }
  wrap.classList.remove("hidden");
  select.innerHTML = state.lines
    .map(
      (line) => `<option value="${line.id}">${escapeHtml(line.name)}</option>`,
    )
    .join("");
}
function updateChatLineBadge() {
  const badge = $("#chatLine");
  if (!state.active || !showLineBadges() || !state.active.line_name) {
    badge.classList.add("hidden");
    return;
  }
  badge.textContent = state.active.line_name;
  badge.style.setProperty(
    "--label-color",
    state.active.line_color || "#25d366",
  );
  badge.classList.remove("hidden");
}
function renderLineAdmin() {
  if (!state.user || state.user.role !== "admin") return;
  $("#lineAdminList").innerHTML =
    state.allLines
      .map(
        (line) =>
          `<div class="line-row"><i style="--label-color:${line.color}"></i><span>${escapeHtml(line.name)} <small class="muted">${line.active ? "Disponible" : "Inactiva"}</small></span></div>`,
      )
      .join("") ||
    '<p class="muted">El superadministrador todavía no registró líneas.</p>';
}
function populateUserFormLines() {
  const box = $("#userFormLines");
  if (!box) return;
  const activeLines = state.allLines.filter((line) => line.active);
  box.innerHTML =
    activeLines
      .map(
        (line) =>
          `<label style="--label-color:${line.color}"><input type="checkbox" value="${line.id}"> ${escapeHtml(line.name)}</label>`,
      )
      .join("") || '<span class="muted">Crea primero una línea/campaña.</span>';
  box
    .querySelectorAll("input[type=checkbox]")
    .forEach(
      (input) =>
        (input.onchange = () =>
          input.closest("label").classList.toggle("checked", input.checked)),
    );
}
function generatePassword(length = 12) {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789";
  const bytes = new Uint32Array(length);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (n) => chars[n % chars.length]).join("");
}
function updateUserEditLinesVisibility() {
  $("#userEditLinesWrap").classList.remove("hidden");
}
function openUserEdit(id) {
  const user = state.users.find((u) => u.id === id);
  if (!user) return;
  const form = $("#userEditForm");
  form.elements.display_name.value = user.display_name;
  form.elements.username.value = user.username;
  form.elements.role.value = user.role;
  form.elements.password.value = "";
  $("#userEditMsg").textContent = "";
  form.dataset.userId = id;
  const activeLines = state.allLines.filter((line) => line.active);
  $("#userEditLines").innerHTML =
    activeLines
      .map(
        (line) =>
          `<label style="--label-color:${line.color}" class="${user.lines.some((ul) => ul.id === line.id) ? "checked" : ""}"><input type="checkbox" value="${line.id}" ${user.lines.some((ul) => ul.id === line.id) ? "checked" : ""}> ${escapeHtml(line.name)}</label>`,
      )
      .join("") || '<span class="muted">No hay líneas activas.</span>';
  $("#userEditLines")
    .querySelectorAll("input[type=checkbox]")
    .forEach(
      (input) =>
        (input.onchange = () =>
          input.closest("label").classList.toggle("checked", input.checked)),
    );
  updateUserEditLinesVisibility();
  $("#userEditDialog").showModal();
}
function renderContactClassifications() {
  if (!state.active) return;
  $("#folderSelect").innerHTML =
    '<option value="">Sin clasificar</option>' +
    state.folders
      .map(
        (folder) =>
          `<option value="${folder.id}">${escapeHtml(folder.name)}</option>`,
      )
      .join("");
  $("#folderSelect").value = state.active.folder_id || "";
  const selected = new Set((state.active.tags || []).map((tag) => tag.id));
  $("#contactTags").innerHTML =
    state.tags
      .map(
        (tag) =>
          `<button type="button" data-contact-tag="${tag.id}" class="${selected.has(tag.id) ? "selected" : ""}" style="--label-color:${tag.color}">${escapeHtml(tag.name)}</button>`,
      )
      .join("") ||
    '<span class="muted">El administrador todavía no creó etiquetas.</span>';
  document.querySelectorAll("[data-contact-tag]").forEach(
    (button) =>
      (button.onclick = async () => {
        const tagId = Number(button.dataset.contactTag);
        const method = button.classList.contains("selected")
          ? "DELETE"
          : "POST";
        await api(`/contacts/${state.active.id}/tags/${tagId}`, { method });
        await loadContacts();
        state.active = state.contacts.find(
          (contact) => contact.id === state.active.id,
        );
        renderContactClassifications();
      }),
  );
}
function classificationRows(items, resource) {
  return (
    items
      .map(
        (item) =>
          `<div class="classification-row"><i style="--label-color:${item.color}"></i><span>${escapeHtml(item.name)}</span><button type="button" data-edit-${resource}="${item.id}">Editar</button><button type="button" class="danger-link" data-delete-${resource}="${item.id}">Eliminar</button></div>`,
      )
      .join("") || '<p class="muted">Aún no hay elementos.</p>'
  );
}
function renderClassificationAdmin() {
  if (!state.user || state.user.role !== "admin") return;
  $("#folderAdminList").innerHTML = classificationRows(
    state.folders,
    "folders",
  );
  $("#tagAdminList").innerHTML = classificationRows(state.tags, "tags");
  for (const [resource, items, label] of [
    ["folders", state.folders, "carpeta"],
    ["tags", state.tags, "etiqueta"],
  ]) {
    document.querySelectorAll(`[data-edit-${resource}]`).forEach(
      (button) =>
        (button.onclick = async () => {
          const item = items.find(
            (entry) =>
              entry.id ===
              Number(
                button.dataset[
                  `edit${resource[0].toUpperCase() + resource.slice(1)}`
                ],
              ),
          );
          const name = prompt(`Nuevo nombre de la ${label}`, item.name);
          if (!name) return;
          await api(`/${resource}/${item.id}`, {
            method: "PATCH",
            body: JSON.stringify({ name, color: item.color }),
          });
          await loadClassifications();
        }),
    );
    document.querySelectorAll(`[data-delete-${resource}]`).forEach(
      (button) =>
        (button.onclick = async () => {
          const id = Number(
            button.dataset[
              `delete${resource[0].toUpperCase() + resource.slice(1)}`
            ],
          );
          if (
            !confirm(
              `¿Eliminar esta ${label}? Los clientes y conversaciones se conservarán.`,
            )
          )
            return;
          await api(`/${resource}/${id}`, { method: "DELETE" });
          await loadClassifications();
          await loadContacts();
        }),
    );
  }
}

$("#loginForm").onsubmit = async (event) => {
  event.preventDefault();
  const form = new FormData(event.currentTarget);
  try {
    state.user = (
      await api("/login", {
        method: "POST",
        body: JSON.stringify(Object.fromEntries(form)),
      })
    ).user;
    location.reload();
  } catch (error) {
    $("#loginError").textContent = error.message;
  }
};
$("#logout").onclick = async () => {
  await api("/logout", { method: "POST" });
  location.reload();
};
$("#notificationToggle").onclick = async () => {
  localStorage.setItem("zynerwaba_alerts", alertsEnabled() ? "0" : "1");
  if (
    alertsEnabled() &&
    "Notification" in window &&
    Notification.permission === "default"
  )
    await Notification.requestPermission();
  if (alertsEnabled()) playAlertSound();
  updateAlertButton();
  toast(alertsEnabled() ? "Alertas activadas" : "Alertas desactivadas");
};
$("#messageAlert").onclick = () => {
  $("#messageAlert").classList.add("hidden");
  if (state.alertContactId) openContact(state.alertContactId);
};
$("#waitingAlert").onclick = () => {
  const id = Number($("#waitingAlert").dataset.contactId);
  if (id) openContact(id);
};
$("#answerCall").onclick = answerIncomingCall;
$("#rejectCall").onclick = rejectIncomingCall;
$("#hangupCall").onclick = hangupActiveCall;
$("#search").oninput = renderContacts;
function stopTyping() {
  clearTimeout(state.typingTimer);
  if (state.typingSent && state.active && state.socket) state.socket.emit("typing:stop", { contact_id: state.active.id });
  state.typingSent = false;
}
function notifyTyping() {
  if (!state.active || !state.socket) return;
  if (!$("#messageBody").value.trim()) return stopTyping();
  if (!state.typingSent) {
    state.socket.emit("typing:start", { contact_id: state.active.id });
    state.typingSent = true;
  }
  clearTimeout(state.typingTimer);
  state.typingTimer = setTimeout(stopTyping, 4000);
}
function renderSnoozeStatus() {
  const active = state.active && isFuture(state.active.snoozed_until);
  $("#snoozeStatus").classList.toggle("hidden", !active);
  if (active) $("#snoozeStatusText").textContent = `Pospuesta hasta ${serverDate(state.active.snoozed_until).toLocaleString()}`;
  $("#snoozeContact").textContent = active ? "Cambiar fecha" : "Posponer";
}
async function snoozeContact() {
  if (!state.active) return;
  $("#snoozeValidation").textContent = "";
  $("#snoozeCustomDate").value = "";
  $("#snoozeDialog").showModal();
}
async function scheduleSnooze(until) {
  if (!state.active || !until || Number.isNaN(until.getTime()) || until <= new Date()) {
    $("#snoozeValidation").textContent = "Selecciona una fecha y hora futuras.";
    return;
  }
  await api(`/contacts/${state.active.id}/snooze`, { method: "POST", body: JSON.stringify({ until: until.toISOString() }) });
  $("#snoozeDialog").close();
  toast("Conversación pospuesta");
  await loadContacts();
  state.active = state.contacts.find((c) => c.id === state.active.id) || state.active;
  renderSnoozeStatus();
}
async function unsnoozeContact() {
  if (!state.active) return;
  await api(`/contacts/${state.active.id}/snooze`, { method: "DELETE" });
  state.active.snoozed_until = null;
  renderSnoozeStatus();
  await loadContacts();
  toast("Conversación reactivada");
}
function closeConversationSearch() {
  $("#conversationSearch").classList.add("hidden");
  $("#conversationSearchToggle").setAttribute("aria-expanded", "false");
  document.querySelectorAll(".message.search-hit").forEach((node) => node.classList.remove("search-hit"));
  state.searchResults = []; state.searchResultIndex = -1;
}
function showSearchResult(index) {
  if (!state.searchResults.length) return;
  state.searchResultIndex = (index + state.searchResults.length) % state.searchResults.length;
  const result = state.searchResults[state.searchResultIndex];
  $("#searchResultCount").textContent = `${state.searchResultIndex + 1} de ${state.searchResults.length}`;
  document.querySelectorAll(".message.search-hit").forEach((node) => node.classList.remove("search-hit"));
  document.querySelectorAll("[data-note-result]").forEach((node) => node.classList.toggle("active", Number(node.dataset.noteResult) === state.searchResultIndex));
  if (result.source === "message") {
    const node = document.querySelector(`[data-message-id="${result.id}"]`);
    if (node) { node.classList.add("search-hit"); node.scrollIntoView({ behavior: "smooth", block: "center" }); }
  } else document.querySelector(`[data-note-result="${state.searchResultIndex}"]`)?.scrollIntoView({ behavior: "smooth", block: "nearest" });
}
async function searchConversation(event) {
  event.preventDefault();
  const q = $("#conversationSearchInput").value.trim();
  if (q.length < 2) return toast("Escribe al menos 2 caracteres");
  state.searchResults = await api(`/contacts/${state.active.id}/search?q=${encodeURIComponent(q)}`);
  $("#conversationSearchNotes").innerHTML = state.searchResults.map((r, index) => ({r,index})).filter(({r}) => r.source !== "message").map(({r,index}) => `<button type="button" data-note-result="${index}"><strong>${escapeHtml(r.author || "Nota interna")}</strong><span>${escapeHtml(r.body || r.transcript || "")}</span><small>${serverDate(r.created_at).toLocaleString()}</small></button>`).join("");
  $("#conversationSearchNotes").querySelectorAll("[data-note-result]").forEach((button) => button.onclick = () => showSearchResult(Number(button.dataset.noteResult)));
  $("#conversationSearchNotes").classList.toggle("hidden", !$("#conversationSearchNotes").innerHTML);
  $("#searchResultCount").textContent = `${state.searchResults.length} resultados`;
  if (state.searchResults.length) showSearchResult(0);
}
document.querySelectorAll(".filters button").forEach(
  (button) =>
    (button.onclick = () => {
      document
        .querySelectorAll(".filters button")
        .forEach((b) => b.classList.remove("active"));
      button.classList.add("active");
      state.filter = button.dataset.filter;
      showConversationList();
      renderContacts();
    }),
);
// --- Ventana de servicio de WhatsApp (control de costo) ---
// Meta solo acepta texto libre mientras la ventana esté abierta. El servidor es
// quien decide y bloquea; esto es únicamente la capa visible para el operador.
async function refreshServiceWindow() {
  if (!state.active) {
    state.windowState = null;
    return renderServiceWindow();
  }
  const contactId = state.active.id;
  const info = await api(`/contacts/${contactId}/window`);
  if (state.active?.id !== contactId) return;
  state.windowState = info;
  renderServiceWindow();
}
function windowElapsedText(value) {
  if (!value) return "";
  const hours = (Date.now() - serverDate(value).getTime()) / 3600000;
  if (hours < 48) return `hace ${Math.floor(hours)} h`;
  return `hace ${Math.floor(hours / 24)} días`;
}
function renderServiceWindow() {
  const notice = $("#windowNotice");
  const granted = $("#windowGranted");
  if (!notice || !granted) return;
  const info = state.windowState;
  const hours = info?.hours || state.serviceWindowHours;
  const blocked = Boolean(state.active && info && !info.open && !info.override);
  const authorized = Boolean(state.active && info && !info.open && info.override);
  notice.classList.toggle("hidden", !blocked);
  granted.classList.toggle("hidden", !authorized);
  if (blocked) {
    $("#windowNoticeTitle").textContent = info.never_opened
      ? "Este cliente todavía no ha escrito"
      : `Ventana de ${hours} h cerrada`;
    $("#windowNoticeBody").textContent = info.never_opened
      ? "Sin mensaje entrante no hay ventana gratuita. Escribir ahora exige una plantilla aprobada y genera costo."
      : `Último mensaje del cliente ${windowElapsedText(info.last_inbound_at)}. Escribir ahora exige una plantilla aprobada y genera costo.`;
  }
  if (authorized) {
    $("#windowGrantedText").textContent = "Envío autorizado por esta vez. Se consume con el próximo mensaje y genera costo.";
  }
  const disabled = blocked;
  ["#messageBody", "#sendButton", "#attachButton", "#quickReplySelect", "#quickReplySearch", "#emojiButton"].forEach((selector) => {
    const node = $(selector);
    if (node) node.disabled = disabled;
  });
  $("#messageBody").placeholder = disabled
    ? `Bloqueado: la ventana de ${hours} h está cerrada`
    : "Escribe un mensaje";
  $("#sendForm").classList.toggle("window-blocked", disabled);
}
async function authorizeServiceWindow() {
  if (!state.active) return;
  const hours = state.windowState?.hours || state.serviceWindowHours;
  const confirmed = window.confirm(
    `Han pasado más de ${hours} h desde el último mensaje del cliente.\n\n` +
      "Enviar ahora obliga a usar una plantilla aprobada de WhatsApp y ESO SE FACTURA.\n\n" +
      "¿Autorizas un único envío como excepción?",
  );
  if (!confirmed) return;
  const reason = window.prompt("Motivo de la excepción (queda registrado):", "") || "";
  $("#authorizeWindow").disabled = true;
  try {
    await api(`/contacts/${state.active.id}/window-override`, {
      method: "POST",
      body: JSON.stringify({ reason: reason.trim() }),
    });
    await refreshServiceWindow();
    $("#messageBody").focus();
    toast("Autorizado: un solo envío");
  } catch (error) {
    toast(error.message);
  } finally {
    $("#authorizeWindow").disabled = false;
  }
}
async function cancelServiceWindowOverride() {
  if (!state.active) return;
  try {
    await api(`/contacts/${state.active.id}/window-override`, { method: "DELETE" });
    await refreshServiceWindow();
    toast("Autorización cancelada");
  } catch (error) {
    toast(error.message);
  }
}
$("#authorizeWindow").onclick = () => authorizeServiceWindow();
$("#cancelWindowOverride").onclick = () => cancelServiceWindowOverride();

$("#sendForm").onsubmit = async (event) => {
  event.preventDefault();
  stopTyping();
  const body = $("#messageBody").value.trim();
  if ((!body && !state.pendingFile) || !state.active) return;
  $("#sendButton").disabled = true;
  try {
    if (state.pendingFile) {
      const form = new FormData();
      form.append("contact_id", state.active.id);
      form.append("caption", body);
      form.append("file", state.pendingFile);
      const response = await fetch(`${BASE}/api/send-media`, {
        method: "POST",
        body: form,
      });
      const data = await response.json().catch(() => ({}));
      if (response.status === 401) {
        location.reload();
        return;
      }
      if (!response.ok)
        throw new Error(data.error || "No se pudo enviar el archivo");
      clearPendingFile();
    } else {
      await api(`/contacts/${state.active.id}/messages`, {
        method: "POST",
        body: JSON.stringify({ body }),
      });
    }
    $("#messageBody").value = "";
    await loadMessages();
  } catch (error) {
    toast(error.message);
    await refreshServiceWindow().catch(() => {});
  } finally {
    $("#sendButton").disabled = false;
    renderServiceWindow();
  }
};
$("#messageBody").addEventListener("keydown", (event) => {
  if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
    event.preventDefault();
    $("#sendForm").requestSubmit();
  }
});
$("#messageBody").addEventListener("input", notifyTyping);
$("#snoozeContact").onclick = () => snoozeContact().catch((error) => toast(error.message));
$("#unsnoozeContact").onclick = () => unsnoozeContact().catch((error) => toast(error.message));
$("#cancelSnooze").onclick = () => $("#snoozeDialog").close();
document.querySelectorAll("[data-snooze-minutes]").forEach((button) => button.onclick = () => scheduleSnooze(new Date(Date.now() + Number(button.dataset.snoozeMinutes) * 60000)).catch((error) => toast(error.message)));
$("[data-snooze-tomorrow]").onclick = () => { const until = new Date(); until.setDate(until.getDate() + 1); until.setHours(9, 0, 0, 0); scheduleSnooze(until).catch((error) => toast(error.message)); };
$("#saveCustomSnooze").onclick = () => scheduleSnooze(new Date($("#snoozeCustomDate").value)).catch((error) => toast(error.message));
$("#conversationSearchToggle").onclick = () => { $("#conversationSearch").classList.toggle("hidden"); $("#conversationSearchToggle").setAttribute("aria-expanded", String(!$("#conversationSearch").classList.contains("hidden"))); $("#conversationSearchInput").focus(); };
$("#conversationSearchClose").onclick = closeConversationSearch;
$("#conversationSearch").onsubmit = (event) => searchConversation(event).catch((error) => toast(error.message));
$("#searchPrevious").onclick = () => showSearchResult(state.searchResultIndex - 1);
$("#searchNext").onclick = () => showSearchResult(state.searchResultIndex + 1);
function clearPendingFile() {
  if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
  state.pendingFile = null;
  state.previewUrl = null;
  $("#fileInput").value = "";
  $("#attachmentPreview").classList.add("hidden");
  $("#attachmentPreview").innerHTML = "";
}
function showPendingFile(file) {
  clearPendingFile();
  state.pendingFile = file;
  const isImage = file.type.startsWith("image/");
  if (isImage) state.previewUrl = URL.createObjectURL(file);
  $("#attachmentPreview").innerHTML =
    `${isImage ? `<img src="${state.previewUrl}" alt="Vista previa">` : '<span class="preview-document">▤</span>'}<span><strong>${escapeHtml(file.name)}</strong><small>${(file.size / 1024 / 1024).toFixed(2)} MB</small></span><button id="removeAttachment" type="button" aria-label="Quitar archivo">×</button>`;
  $("#attachmentPreview").classList.remove("hidden");
  $("#removeAttachment").onclick = clearPendingFile;
  $("#messageBody").focus();
}
$("#attachButton").onclick = () => $("#fileInput").click();
$("#fileInput").onchange = () => {
  const file = $("#fileInput").files[0];
  if (!file) return;
  if (file.size > 16 * 1024 * 1024) {
    toast("El archivo supera el límite de 16 MB");
    $("#fileInput").value = "";
    return;
  }
  showPendingFile(file);
};
const emojiCategories = {
  faces: {
    label: "Caras y personas",
    icon: "😊",
    items: [
      ["😀", "feliz sonrisa"],
      ["😃", "feliz sonrisa"],
      ["😄", "alegre sonrisa"],
      ["😁", "sonrisa dientes"],
      ["😆", "risa"],
      ["😅", "risa sudor"],
      ["😂", "lágrimas risa"],
      ["🤣", "carcajada"],
      ["😊", "sonrisa amable"],
      ["😇", "ángel"],
      ["🙂", "sonrisa"],
      ["😉", "guiño"],
      ["😍", "enamorado amor"],
      ["🥰", "amor corazones"],
      ["😘", "beso"],
      ["😋", "sabroso"],
      ["😎", "lentes genial"],
      ["🤔", "pensando duda"],
      ["🤗", "abrazo"],
      ["🤭", "sorpresa"],
      ["😴", "dormido"],
      ["😢", "triste lágrima"],
      ["😭", "llanto"],
      ["😡", "enojado"],
      ["🥳", "fiesta"],
      ["🤯", "sorprendido"],
    ],
  },
  gestures: {
    label: "Gestos",
    icon: "👍",
    items: [
      ["👍", "bien aprobar sí"],
      ["👎", "mal no"],
      ["👌", "ok perfecto"],
      ["✌️", "victoria paz"],
      ["🤞", "suerte"],
      ["🤟", "amor"],
      ["🤘", "rock"],
      ["👏", "aplausos"],
      ["🙌", "celebrar"],
      ["🙏", "gracias por favor"],
      ["🤝", "acuerdo saludo"],
      ["💪", "fuerza"],
      ["👋", "hola adiós"],
      ["☝️", "arriba"],
      ["👉", "derecha"],
      ["👈", "izquierda"],
      ["✍️", "escribir"],
      ["👀", "ver ojos"],
    ],
  },
  animals: {
    label: "Animales y naturaleza",
    icon: "🐶",
    items: [
      ["🐶", "perro"],
      ["🐱", "gato"],
      ["🐭", "ratón"],
      ["🐰", "conejo"],
      ["🦊", "zorro"],
      ["🐻", "oso"],
      ["🐼", "panda"],
      ["🐨", "koala"],
      ["🦁", "león"],
      ["🐮", "vaca"],
      ["🐷", "cerdo"],
      ["🐸", "rana"],
      ["🐵", "mono"],
      ["🐔", "gallina"],
      ["🐧", "pingüino"],
      ["🐦", "pájaro"],
      ["🦋", "mariposa"],
      ["🌸", "flor"],
      ["🌹", "rosa"],
      ["🌞", "sol"],
      ["⭐", "estrella"],
      ["🌈", "arcoiris"],
    ],
  },
  food: {
    label: "Comida y bebida",
    icon: "🍕",
    items: [
      ["🍎", "manzana"],
      ["🍓", "fresa"],
      ["🍉", "sandía"],
      ["🍌", "banana"],
      ["🍇", "uvas"],
      ["🥑", "aguacate"],
      ["🍔", "hamburguesa"],
      ["🍕", "pizza"],
      ["🌭", "hot dog"],
      ["🍟", "papas"],
      ["🌮", "taco"],
      ["🍰", "torta pastel"],
      ["🎂", "cumpleaños torta"],
      ["🍫", "chocolate"],
      ["☕", "café"],
      ["🍺", "cerveza"],
      ["🍷", "vino"],
      ["🥂", "brindis"],
    ],
  },
  travel: {
    label: "Viajes y lugares",
    icon: "🚗",
    items: [
      ["🚗", "auto coche vehículo"],
      ["🚕", "taxi"],
      ["🚌", "bus"],
      ["🏍️", "moto"],
      ["✈️", "avión viaje"],
      ["🚀", "cohete"],
      ["🚢", "barco"],
      ["🏠", "casa"],
      ["🏢", "oficina edificio"],
      ["🏥", "hospital"],
      ["🏦", "banco"],
      ["🏖️", "playa"],
      ["🗺️", "mapa"],
      ["📍", "ubicación"],
      ["⏰", "reloj alarma"],
      ["🌍", "mundo"],
    ],
  },
  objects: {
    label: "Objetos",
    icon: "💡",
    items: [
      ["📱", "teléfono móvil"],
      ["☎️", "teléfono llamada"],
      ["📞", "llamar teléfono"],
      ["💻", "computadora"],
      ["⌨️", "teclado"],
      ["📷", "cámara foto"],
      ["🎥", "video"],
      ["💡", "idea luz"],
      ["📄", "documento"],
      ["📎", "adjunto"],
      ["✉️", "correo"],
      ["📧", "email"],
      ["📅", "calendario"],
      ["🔒", "seguro candado"],
      ["🔑", "llave"],
      ["💰", "dinero"],
      ["💳", "tarjeta pago"],
      ["🎁", "regalo"],
    ],
  },
  symbols: {
    label: "Símbolos",
    icon: "❤️",
    items: [
      ["❤️", "amor corazón rojo"],
      ["🧡", "corazón naranja"],
      ["💛", "corazón amarillo"],
      ["💚", "corazón verde"],
      ["💙", "corazón azul"],
      ["💜", "corazón morado"],
      ["💔", "corazón roto"],
      ["✅", "correcto listo"],
      ["❌", "incorrecto no"],
      ["⚠️", "alerta"],
      ["❓", "pregunta"],
      ["❗", "importante"],
      ["💯", "cien perfecto"],
      ["🔥", "fuego"],
      ["✨", "brillo"],
      ["🎉", "celebración"],
      ["🔔", "campana"],
      ["➡️", "derecha siguiente"],
    ],
  },
};
let activeEmojiCategory = "recent";
function recentEmojis() {
  try {
    return JSON.parse(localStorage.getItem("zynerwaba_recent_emojis") || "[]");
  } catch {
    return [];
  }
}
function rememberEmoji(emoji) {
  const recent = [
    emoji,
    ...recentEmojis().filter((item) => item !== emoji),
  ].slice(0, 24);
  localStorage.setItem("zynerwaba_recent_emojis", JSON.stringify(recent));
}
function allEmojiItems() {
  return Object.values(emojiCategories).flatMap((category) => category.items);
}
function renderEmojiGrid() {
  const query = ($("#emojiSearch")?.value || "").trim().toLocaleLowerCase("es");
  let label = "Recientes",
    items = recentEmojis().map((emoji) => [emoji, "reciente"]);
  if (query) {
    label = "Resultados";
    items = allEmojiItems().filter(([, keywords]) => keywords.includes(query));
  } else if (activeEmojiCategory !== "recent") {
    const category = emojiCategories[activeEmojiCategory];
    label = category.label;
    items = category.items;
  }
  $("#emojiGrid").innerHTML =
    items
      .map(
        ([emoji]) =>
          `<button type="button" role="menuitem" data-emoji="${emoji}">${emoji}</button>`,
      )
      .join("") || '<p class="emoji-empty">No hay emojis aquí.</p>';
  $("#emojiSectionLabel").textContent = label;
  document
    .querySelectorAll(".emoji-tab")
    .forEach((tab) =>
      tab.classList.toggle(
        "active",
        tab.dataset.category === activeEmojiCategory && !query,
      ),
    );
}
function buildEmojiPicker() {
  const tabs = [
    ["recent", "🕘"],
    ...Object.entries(emojiCategories).map(([key, value]) => [key, value.icon]),
  ];
  $("#emojiPicker").innerHTML =
    `<div class="emoji-search"><span>⌕</span><input id="emojiSearch" type="search" placeholder="Buscar emoji" autocomplete="off"></div><div class="emoji-tabs">${tabs.map(([key, icon]) => `<button type="button" class="emoji-tab" data-category="${key}" title="${key === "recent" ? "Recientes" : emojiCategories[key].label}">${icon}</button>`).join("")}</div><div id="emojiSectionLabel" class="emoji-section-label"></div><div id="emojiGrid" class="emoji-grid"></div>`;
  $("#emojiSearch").oninput = renderEmojiGrid;
  document.querySelectorAll(".emoji-tab").forEach(
    (tab) =>
      (tab.onclick = () => {
        activeEmojiCategory = tab.dataset.category;
        $("#emojiSearch").value = "";
        renderEmojiGrid();
      }),
  );
  renderEmojiGrid();
}
buildEmojiPicker();
$("#emojiButton").onclick = () => {
  const picker = $("#emojiPicker");
  picker.classList.toggle("hidden");
  if (!picker.classList.contains("hidden")) {
    renderEmojiGrid();
    setTimeout(() => $("#emojiSearch").focus(), 0);
  }
};
$("#emojiPicker").addEventListener("click", (event) => {
  const button = event.target.closest("[data-emoji]");
  if (!button) return;
  const emoji = button.dataset.emoji;
  const input = $("#messageBody");
  const start = input.selectionStart;
  input.value =
    input.value.slice(0, start) + emoji + input.value.slice(input.selectionEnd);
  input.selectionStart = input.selectionEnd = start + emoji.length;
  rememberEmoji(emoji);
  renderEmojiGrid();
  input.focus();
});
$("#sendButton").onclick = () =>
  toast("Mantén Enter para enviar texto. Grabación de voz próximamente.");
document.addEventListener("click", (event) => {
  if (!event.target.closest(".emoji-wrap"))
    $("#emojiPicker").classList.add("hidden");
});
$("#saveDetails").onclick = async () => {
  if (!state.active) return;
  const payload = {
    name: $("#detailName").value,
    notes: $("#detailNotes").value,
  };
  if (["admin", "supervisor"].includes(state.user.role))
    payload.owner_user_id = $("#ownerSelect").value || null;
  await api(`/contacts/${state.active.id}`, {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
  toast("Ficha guardada");
  await loadContacts();
  state.active = state.contacts.find((c) => c.id === state.active.id);
};
$("#contactStatus").onchange = async () => {
  if (!state.active) return;
  const status = $("#contactStatus").value;
  let reason = "";
  if (status === "resolved") {
    reason = prompt("Motivo de cierre");
    if (!reason) {
      $("#contactStatus").value =
        state.active.status === "closed"
          ? "resolved"
          : state.active.workflow_status || "in_progress";
      return;
    }
  }
  await api(`/contacts/${state.active.id}/transition`, {
    method: "POST",
    body: JSON.stringify({ status, reason }),
  });
  await loadContacts();
  state.active = state.contacts.find((c) => c.id === state.active.id);
  await loadConversationDetails();
  toast("Estado actualizado");
};
$("#ownerSelect").onchange = () => $("#saveDetails").click();
$("#internalNoteBody").oninput = updateMentionMenu;
$("#internalNoteBody").onkeydown = (event) => {
  if (state.mentionResults.length) {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      state.mentionIndex =
        (state.mentionIndex + 1) % state.mentionResults.length;
      renderMentionMenu();
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      state.mentionIndex =
        (state.mentionIndex - 1 + state.mentionResults.length) %
        state.mentionResults.length;
      renderMentionMenu();
    } else if (event.key === "Enter") {
      event.preventDefault();
      selectMention(state.mentionIndex);
    } else if (event.key === "Escape") {
      event.preventDefault();
      closeMentionMenu();
    }
  }
};
$("#internalNoteBody").onblur = () => setTimeout(closeMentionMenu, 120);
$("#addInternalNote").onclick = async () => {
  if (!state.active) return;
  const body = $("#internalNoteBody").value.trim();
  if (!body) return;
  const mention_user_ids = state.selectedMentions
    .filter((user) => body.includes(`@${user.display_name}`))
    .map((user) => user.id);
  await api(`/contacts/${state.active.id}/internal-notes`, {
    method: "POST",
    body: JSON.stringify({ body, mention_user_ids }),
  });
  $("#internalNoteBody").value = "";
  state.selectedMentions = [];
  closeMentionMenu();
  await loadConversationDetails();
  toast(
    mention_user_ids.length
      ? "Nota añadida y mención enviada"
      : "Nota interna añadida",
  );
};
$("#quickReplySearch").oninput = renderQuickReplyOptions;
$("#quickReplySelect").onchange = async () => {
  const selected = $("#quickReplySelect").value;
  if (selected === "") return;
  const reply = state.quickReplies[Number(selected)];
  if (!reply) return;
  $("#messageBody").value = reply.body
    .replaceAll("{cliente}", state.active?.name || state.active?.phone || "")
    .replaceAll("{asesor}", state.user?.display_name || "")
    .replaceAll("{empresa}", state.user?.empresa_name || "");
  if (reply.media_path) {
    try {
      const response = await fetch(
        `${BASE}/api/quick-replies/${reply.id}/media`,
      );
      if (!response.ok) throw new Error("No se pudo cargar el adjunto");
      const blob = await response.blob();
      showPendingFile(
        new File([blob], reply.media_name || "adjunto", {
          type: reply.mime_type || blob.type,
        }),
      );
    } catch (error) {
      toast(error.message);
    }
  }
  $("#quickReplySelect").value = "";
  $("#messageBody").focus();
};
$("#folderSelect").onchange = async () => {
  if (!state.active) return;
  await api(`/contacts/${state.active.id}/folder`, {
    method: "PATCH",
    body: JSON.stringify({ folder_id: $("#folderSelect").value || null }),
  });
  await loadContacts();
  state.active = state.contacts.find(
    (contact) => contact.id === state.active.id,
  );
  renderContactClassifications();
  toast("Carpeta actualizada");
};
$("#releaseContact").onclick = async () => {
  if (!state.active) return;
  await api(`/contacts/${state.active.id}/release`, { method: "POST" });
  showConversationList();
  await loadContacts();
};
$("#backToConversationList").onclick = () => {
  showConversationList();
  renderContacts();
};
$("#backToInboxFolders").onclick = () => {
  $(".chat-panel").classList.remove("mobile-active");
};
$("#newContact").onclick = () => $("#contactDialog").showModal();
$("#createContact").onclick = async (event) => {
  event.preventDefault();
  const form = new FormData($("#contactForm"));
  try {
    const { contact } = await api("/contacts", {
      method: "POST",
      body: JSON.stringify(Object.fromEntries(form)),
    });
    $("#contactDialog").close();
    await loadContacts();
    openContact(contact.id);
  } catch (error) {
    toast(error.message);
  }
};
$("#userEditRole").onchange = updateUserEditLinesVisibility;
$("#generatePassword").onclick = () => {
  $("#userEditPassword").value = generatePassword();
  $("#userEditPassword").select();
};
$("#saveUserEdit").onclick = async (event) => {
  event.preventDefault();
  const form = $("#userEditForm");
  const userId = form.dataset.userId;
  const payload = {
    display_name: form.elements.display_name.value,
    username: form.elements.username.value,
    role: form.elements.role.value,
  };
  if (["agent", "supervisor"].includes(form.elements.role.value))
    payload.line_ids = [
      ...$("#userEditLines").querySelectorAll("input:checked"),
    ].map((input) => Number(input.value));
  if (form.elements.password.value)
    payload.password = form.elements.password.value;
  try {
    await api(`/users/${userId}`, {
      method: "PATCH",
      body: JSON.stringify(payload),
    });
    $("#userEditDialog").close();
    await loadUsers();
    toast("Usuario actualizado");
  } catch (error) {
    $("#userEditMsg").textContent = error.message;
  }
};
$("#broadcastTab").onclick = () => window.open(`${BASE}/broadcast`, "_blank");
$("#healthTab").onclick = () => window.open(`${BASE}/salud`, "_blank");
$("#physicalSendTab").onclick = () =>
  window.open(`${BASE}/adb-pilot`, "_blank");
$("#gestionTab").onclick = () => window.open(`${BASE}/gestion`, "_blank");
async function loadConversationSettings() {
  if (state.user?.role !== "admin") return;
  const data = await api("/conversation-settings"),
    form = $("#conversationSettingsForm"),
    s = data.settings;
  for (const key of [
    "max_active_default",
    "sla_first_minutes",
    "sla_waiting_minutes",
    "business_start",
    "business_end",
    "timezone",
    "audit_retention_days",
  ])
    form.elements[key].value = s[key] ?? "";
  form.elements.auto_assign.checked = Boolean(s.auto_assign);
  form.elements.auto_reopen.checked = Boolean(s.auto_reopen);
  form.elements.sla_auto_reassign.checked = Boolean(s.sla_auto_reassign);
  let days = [];
  try {
    days = JSON.parse(s.campaign_days_json || "[]");
  } catch {}
  for (let day = 0; day < 7; day++)
    form.elements[`day_${day}`].checked = days.includes(day);
  form.elements.supervisor_user_id.innerHTML =
    '<option value="">Sin supervisor</option>' +
    (data.supervisors || [])
      .map(
        (u) => `<option value="${u.id}">${escapeHtml(u.display_name)}</option>`,
      )
      .join("");
  form.elements.supervisor_user_id.value = s.supervisor_user_id || "";
  $("#operatorCapacityList").innerHTML = data.users.length
    ? data.users
        .map(
          (u) =>
            `<article class="operator-capacity" data-capacity-user="${u.id}"><div class="operator-capacity-head"><span class="operator-avatar" aria-hidden="true">${escapeHtml((u.display_name || "?").trim().charAt(0).toUpperCase())}</span><div><strong>${escapeHtml(u.display_name)}</strong><small><i class="presence-dot ${u.online ? "online" : ""}"></i>${u.active_conversations} ${u.active_conversations === 1 ? "conversación activa" : "conversaciones activas"} · ${u.online ? "Conectado" : "Desconectado"}</small></div></div><div class="operator-capacity-controls"><label class="setting-field"><span>Límite individual</span><input name="capacity_${u.id}" type="number" min="1" value="${u.max_active ?? ""}" placeholder="Predeterminado"></label><label class="setting-switch compact"><span><strong>Disponible</strong><small>Puede recibir asignaciones</small></span><input name="available_${u.id}" type="checkbox" ${u.available ? "checked" : ""}><i aria-hidden="true"></i></label></div></article>`,
        )
        .join("")
    : '<div class="operator-empty"><strong>No hay operadores activos</strong><span>Crea un operador para configurar su capacidad.</span></div>';
}
function selectAdminPanel(name) {
  document
    .querySelectorAll("[data-admin-panel]")
    .forEach((panel) =>
      panel.classList.toggle("active", panel.dataset.adminPanel === name),
    );
  document.querySelectorAll("[data-admin-target]").forEach((button) => {
    const active = button.dataset.adminTarget === name;
    button.classList.toggle("active", active);
    button.setAttribute("aria-current", active ? "page" : "false");
  });
  const content = $(".admin-content");
  if (content) content.scrollTop = 0;
}
document
  .querySelectorAll("[data-admin-target]")
  .forEach(
    (button) =>
      (button.onclick = () => selectAdminPanel(button.dataset.adminTarget)),
  );
async function loadTranscriptionSettings() {
  if (state.user?.role !== "admin") return;
  const data = await api("/transcription/settings"), form = $("#transcriptionSettingsForm");
  form.elements.model.value = data.model || "whisper-large-v3-turbo";
  form.elements.language.value = data.language || "auto";
  form.elements.enabled.checked = Boolean(data.enabled);
  state.transcriptionEnabled = Boolean(data.enabled);
  $("#groqStatus").textContent = data.configured ? (data.enabled ? "Configurado · activo" : "Configurado · inactivo") : "Sin configurar";
  $("#groqStatus").classList.toggle("configured", Boolean(data.configured));
}
function installAgentToolsManual() {
  if ($("#manual-herramientas-agente")) return;
  const section = document.createElement("article");
  section.id = "manual-herramientas-agente"; section.className = "manual-section";
  section.innerHTML = `<header><span>3A</span><div><h4>Herramientas del agente</h4><p>Controles diarios para atender con rapidez y sin duplicar trabajo.</p></div></header><dl class="manual-fields"><div><dt>Bandeja personal</dt><dd>Filtra conversaciones nuevas, pendientes, vencidas, pospuestas o cerradas.</dd></div><div><dt>Posponer</dt><dd>Oculta temporalmente un chat y lo devuelve al vencer o cuando el cliente escribe.</dd></div><div><dt>Indicador de escritura</dt><dd>Avisa cuando otro integrante redacta en el mismo chat.</dd></div><div><dt>Búsqueda</dt><dd>Localiza mensajes y notas internas del cliente abierto.</dd></div><div><dt>Transcripción</dt><dd>Convierte audios de WhatsApp en texto bajo demanda.</dd></div><div><dt>API Key Groq</dt><dd>Clave cifrada que nunca vuelve a mostrarse.</dd></div><div><dt>Modelo / idioma</dt><dd>Selecciona Whisper y detección automática o español.</dd></div><div><dt>Activar / probar</dt><dd>Habilita la función y comprueba la conexión.</dd></div></dl>`;
  $("#manual-conversaciones").after(section);
}
installAgentToolsManual();
$("#adminTab").onclick = async () => {
  if (state.user?.role !== "admin") return;
  $("#adminView").classList.remove("hidden");
  selectAdminPanel("users");
  await loadLines();
  await Promise.all([
    loadUsers(),
    loadClassifications(),
    loadAutoReplies(),
    loadQuickReplies(),
    loadConversationSettings(),
    loadTranscriptionSettings(),
  ]);
  await loadReservations();
};
$("#closeAdmin").onclick = () => $("#adminView").classList.add("hidden");
$("#refreshReservations").onclick = loadReservations;
$("#userForm").onsubmit = async (event) => {
  event.preventDefault();
  const formNode = event.currentTarget;
  const form = new FormData(formNode);
  const payload = Object.fromEntries(form);
  payload.line_ids = [
    ...formNode.querySelectorAll("#userFormLines input:checked"),
  ].map((input) => Number(input.value));
  try {
    await api("/users", { method: "POST", body: JSON.stringify(payload) });
    formNode.reset();
    formNode
      .querySelectorAll("#userFormLines label")
      .forEach((label) => label.classList.remove("checked"));
    $("#userFormMsg").textContent = "Usuario creado";
    await loadUsers();
  } catch (error) {
    $("#userFormMsg").textContent = error.message;
  }
};
if ($("#lineForm"))
  $("#lineForm").onsubmit = async (event) => {
    event.preventDefault();
    toast("Solo el superadministrador puede registrar líneas");
  };
for (const [formId, resource] of [
  ["folderForm", "folders"],
  ["tagForm", "tags"],
])
  $("#" + formId).onsubmit = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await api("/" + resource, {
        method: "POST",
        body: JSON.stringify(Object.fromEntries(new FormData(form))),
      });
      form.reset();
      await loadClassifications();
    } catch (error) {
      toast(error.message);
    }
  };
$("#autoReplyForm").onsubmit = async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const data = Object.fromEntries(new FormData(form));
  data.enabled = data.enabled === "on";
  try {
    await api("/auto-replies", { method: "POST", body: JSON.stringify(data) });
    form.reset();
    form.elements.button_1_title.value = "Me interesa";
    form.elements.button_2_title.value = "No me interesa";
    await loadAutoReplies();
    toast("Plantilla interactiva creada");
  } catch (error) {
    toast(error.message);
  }
};
$("#quickReplyForm").onsubmit = async (event) => {
  event.preventDefault();
  const form = event.currentTarget,
    data = new FormData(form);
  const response = await fetch(`${BASE}/api/quick-replies/upload`, {
    method: "POST",
    body: data,
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) return toast(result.error || "No se pudo crear", true);
  form.reset();
  form.elements.category.value = "General";
  await loadQuickReplies();
  toast("Respuesta rápida creada");
};
$("#conversationSettingsForm").onsubmit = async (event) => {
  event.preventDefault();
  const form = event.currentTarget,
    data = Object.fromEntries(new FormData(form));
  data.auto_assign = form.elements.auto_assign.checked;
  data.auto_reopen = form.elements.auto_reopen.checked;
  data.sla_auto_reassign = form.elements.sla_auto_reassign.checked;
  data.campaign_days = [0, 1, 2, 3, 4, 5, 6].filter(
    (day) => form.elements[`day_${day}`].checked,
  );
  data.users = [...form.querySelectorAll("[data-capacity-user]")].map(
    (label) => {
      const id = Number(label.dataset.capacityUser);
      return {
        id,
        max_active: form.elements[`capacity_${id}`].value,
        available: form.elements[`available_${id}`].checked,
      };
    },
  );
  await api("/conversation-settings", {
    method: "PUT",
    body: JSON.stringify(data),
  });
  await loadConversationSettings();
  toast("Configuración guardada");
};
$("#transcriptionSettingsForm").onsubmit = async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  await api("/transcription/settings", { method: "PUT", body: JSON.stringify({ api_key: form.elements.api_key.value, model: form.elements.model.value, language: form.elements.language.value, enabled: form.elements.enabled.checked }) });
  form.elements.api_key.value = "";
  await loadTranscriptionSettings();
  toast("Configuración Groq guardada de forma cifrada");
};
$("#testTranscription").onclick = async () => {
  const button = $("#testTranscription"); button.disabled = true; button.textContent = "Probando…";
  try { await api("/transcription/test", { method: "POST", body: "{}" }); toast("Conexión con Groq correcta"); }
  catch (error) { toast(error.message); }
  finally { button.disabled = false; button.textContent = "Probar conexión"; }
};
boot().catch((error) => console.error(error));
