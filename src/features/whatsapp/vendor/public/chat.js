const BASE = window.__APP_BASE__;
const $ = (selector) => document.querySelector(selector);
const state = { user: null, contacts: [], active: null, socket: null, pendingFile: null };

async function api(path, options = {}) {
  const response = await fetch(`${BASE}/api${path}`, { headers: { 'Content-Type': 'application/json', ...(options.headers || {}) }, ...options });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401 && path !== '/login') {
    location.reload();
    throw new Error('La sesión expiró');
  }
  if (!response.ok) throw new Error(data.error || 'Error de servidor');
  return data;
}
function toast(text) { const node = $('#toast'); node.textContent = text; node.classList.add('show'); setTimeout(() => node.classList.remove('show'), 2600); }
function initials(name) { return String(name || '?').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase(); }
function escapeHtml(value) { const node = document.createElement('div'); node.textContent = value ?? ''; return node.innerHTML; }
function time(value) { if (!value) return ''; const d = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z')); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
function queryParam(name) { return new URLSearchParams(location.search).get(name); }

async function autoLoginFromQuery() {
  const params = new URLSearchParams(location.search);
  const qUser = params.get('user');
  const qPassword = params.get('password');
  let user = null;
  try { user = (await api('/me')).user; } catch { /* noop */ }
  if (!user && qUser && qPassword) {
    try { user = (await api('/login', { method: 'POST', body: JSON.stringify({ username: qUser, password: qPassword }) })).user; }
    catch (error) { $('#loginError').textContent = error.message; }
  }
  // Limpia user/password de la URL visible (no del log del servidor ni del
  // historial de la primera carga, eso ya viajó en la petición inicial).
  if (qUser || qPassword) {
    params.delete('user'); params.delete('password');
    const qs = params.toString();
    history.replaceState(null, '', `${location.pathname}${qs ? '?' + qs : ''}`);
  }
  return user;
}

async function boot() {
  const user = await autoLoginFromQuery();
  if (!user) return; // se queda en #loginView (visible por defecto)
  state.user = user;
  $('#loginView').classList.add('hidden'); $('#appView').classList.remove('hidden');
  $('#userName').textContent = user.display_name;
  connectSocket();
  await loadContacts();
  openFromQuery();
}

function openFromQuery() {
  const wantedId = (queryParam('id') || '').replace(/\D/g, '');
  if (!wantedId) return;
  const match = state.contacts.find((c) => c.phone === wantedId || c.phone.endsWith(wantedId) || wantedId.endsWith(c.phone));
  if (match) openContact(match.id);
  else toast('No tienes acceso a ese cliente o todavía no existe');
}

function connectSocket() {
  state.socket = io({ path: `${BASE}/socket.io`, transports: ['polling'] });
  state.socket.on('contacts:refresh', loadContacts);
  state.socket.on('message:new', async ({ contact_id }) => { await loadContacts(); if (state.active?.id === contact_id) await loadMessages(); });
  state.socket.on('message:status', async ({ contact_id }) => { if (state.active?.id === contact_id) await loadMessages(); });
}

async function loadContacts() {
  const activeId = state.active?.id;
  state.contacts = await api('/contacts');
  renderContacts();
  if (activeId) state.active = state.contacts.find((c) => c.id === activeId) || state.active;
}

function renderContacts() {
  const query = $('#search').value.toLowerCase();
  const rows = state.contacts.filter((c) => `${c.name || ''} ${c.phone}`.toLowerCase().includes(query));
  $('#contactList').innerHTML = rows.map((c) => `<article class="contact ${state.active?.id === c.id ? 'active' : ''}" data-id="${c.id}"><div class="avatar">${initials(c.name || c.phone)}</div><div class="contact-copy"><strong>${escapeHtml(c.name || c.phone)}</strong><span>${escapeHtml(c.last_body || 'Sin mensajes')}</span></div><time>${time(c.last_message_at)}</time></article>`).join('') || '<p class="muted" style="padding:20px">No hay conversaciones.</p>';
  document.querySelectorAll('.contact').forEach((node) => node.onclick = () => openContact(Number(node.dataset.id)));
}

async function openContact(id) {
  state.active = state.contacts.find((c) => c.id === id);
  if (!state.active) return;
  $('#listView').classList.add('hidden'); $('#chatView').classList.remove('hidden');
  $('#chatName').textContent = state.active.name || state.active.phone;
  $('#chatPhone').textContent = `+${state.active.phone}`;
  $('#chatAvatar').textContent = initials(state.active.name || state.active.phone);
  history.replaceState(null, '', `${BASE}/chat?id=${state.active.phone}`);
  await loadMessages();
}
function backToList() {
  state.active = null;
  $('#chatView').classList.add('hidden'); $('#listView').classList.remove('hidden');
  history.replaceState(null, '', `${BASE}/chat`);
  renderContacts();
}

async function loadMessages() {
  const messages = await api(`/contacts/${state.active.id}/messages`);
  $('#messageList').innerHTML = messages.map((m) => `<div class="message ${m.direction} ${m.media_path ? 'has-media' : ''}">${messageContent(m)}<footer>${time(m.created_at)}${m.direction === 'out' ? statusChecks(m.status) : ''}</footer></div>`).join('');
  scrollMessagesToBottom();
  document.querySelectorAll('#messageList img').forEach((image) => { if (!image.complete) image.addEventListener('load', scrollMessagesToBottom, { once: true }); });
}
function scrollMessagesToBottom() { const list = $('#messageList'); requestAnimationFrame(() => { list.scrollTop = list.scrollHeight; }); }
function statusChecks(status) {
  if (status === 'read') return ' <span class="checks read" title="Leído">✓✓</span>';
  if (status === 'delivered') return ' <span class="checks" title="Entregado">✓✓</span>';
  if (status === 'sent') return ' <span class="checks" title="Enviado">✓</span>';
  if (status === 'failed' || status === 'error') return ' <span class="checks failed" title="Error">!</span>';
  return '';
}
function messageContent(message) {
  const caption = message.body && !/^\[(Imagen|Documento)/.test(message.body) ? `<div class="media-caption">${escapeHtml(message.body)}</div>` : '';
  if (message.media_path && message.type === 'image') {
    const source = `${BASE}/api/media/${encodeURIComponent(message.media_path)}`;
    return `<a class="image-message" href="${source}" target="_blank"><img src="${source}" alt="${escapeHtml(message.media_name || 'Imagen')}"></a>${caption}`;
  }
  if (message.media_path && message.type === 'document') {
    const source = `${BASE}/api/media/${encodeURIComponent(message.media_path)}?download=1`;
    return `<a class="document-message" href="${source}"><span class="document-icon">▤</span><span><strong>${escapeHtml(message.media_name || 'Documento')}</strong><small>${escapeHtml(message.mime_type || 'Archivo')}</small></span><span class="download-icon">↓</span></a>${caption}`;
  }
  return escapeHtml(message.body || `[${message.type}]`);
}

$('#loginForm').onsubmit = async (event) => { event.preventDefault(); const form = new FormData(event.currentTarget); try { await api('/login', { method: 'POST', body: JSON.stringify(Object.fromEntries(form)) }); location.reload(); } catch (error) { $('#loginError').textContent = error.message; } };
$('#logout').onclick = async () => { await api('/logout', { method: 'POST' }); location.reload(); };
$('#backButton').onclick = backToList;
$('#search').oninput = renderContacts;
$('#attachButton').onclick = () => $('#fileInput').click();
$('#fileInput').onchange = () => { const file = $('#fileInput').files[0]; if (!file) return; if (file.size > 16 * 1024 * 1024) { toast('El archivo supera el límite de 16 MB'); $('#fileInput').value = ''; return; } state.pendingFile = file; toast(`Adjunto listo: ${file.name}`); };
$('#sendForm').onsubmit = async (event) => {
  event.preventDefault();
  const body = $('#messageBody').value.trim();
  if ((!body && !state.pendingFile) || !state.active) return;
  $('#sendButton').disabled = true;
  try {
    if (state.pendingFile) {
      const form = new FormData();
      form.append('contact_id', state.active.id); form.append('caption', body); form.append('file', state.pendingFile);
      const response = await fetch(`${BASE}/api/send-media`, { method: 'POST', body: form });
      const data = await response.json().catch(() => ({}));
      if (response.status === 401) { location.reload(); return; }
      if (!response.ok) throw new Error(data.error || 'No se pudo enviar el archivo');
      state.pendingFile = null; $('#fileInput').value = '';
    } else {
      await api('/send', { method: 'POST', body: JSON.stringify({ contact_id: state.active.id, body }) });
    }
    $('#messageBody').value = '';
    await loadMessages();
  } catch (error) { toast(error.message); }
  finally { $('#sendButton').disabled = false; }
};
$('#messageBody').addEventListener('keydown', (event) => { if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); $('#sendForm').requestSubmit(); } });

boot().catch((error) => console.error(error));
