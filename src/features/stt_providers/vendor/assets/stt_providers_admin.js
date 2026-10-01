/* stt_providers_admin — lógica de UI (CSP-safe: externalizada, sin inline handlers) */
(function () {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  const PROVIDERS = JSON.parse(document.getElementById('stt-providers-config').textContent);
  let profiles = [];
  let accounts = [];
  let activeAccountId = 0;
  let testTarget = null;

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function $(id) { return document.getElementById(id); }

  function setMsg(message, ok = true) {
    const node = $('msg');
    node.textContent = message;
    node.className = 'msg ' + (ok ? 'ok' : 'err');
  }

  function setAccountMsg(message, ok = true) {
    const node = $('account-msg');
    node.textContent = message;
    node.className = 'msg ' + (ok ? 'ok' : 'err');
  }

  async function request(action, data = null) {
    const options = {cache: 'no-store'};
    const endpoint = window.ZYNERVOX_STT_ENDPOINT || 'stt_providers_admin.php';
    let url = endpoint + '?action=' + encodeURIComponent(action);
    if (data !== null) {
      const body = new URLSearchParams({...data, csrf: CSRF});
      options.method = 'POST';
      options.headers = {'Content-Type': 'application/x-www-form-urlencoded'};
      options.body = body;
      url = endpoint;
      body.set('action', action);
    }
    const response = await fetch(url, options);
    let payload;
    try { payload = await response.json(); }
    catch (error) { throw new Error('Respuesta inválida del servidor'); }
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'Error del servidor');
    return payload;
  }

  function initProviders() {
    const select = $('f-provider');
    const selected = select.value;
    const availableProviders = [...new Map(
      profiles.map(profile => [profile.provider, profile.provider_label])
    ).entries()].sort((a, b) => a[1].localeCompare(b[1], 'es'));
    select.innerHTML = availableProviders.length
      ? availableProviders.map(([key, label]) => `<option value="${esc(key)}">${esc(label)}</option>`).join('')
      : '<option value="">No hay proveedores con leads</option>';
    if (availableProviders.some(([key]) => key === selected)) select.value = selected;
    providerChanged();
  }

  function providerChanged() {
    const key = $('f-provider').value;
    const provider = PROVIDERS[key];
    const supportsModel = Boolean(provider && provider.supports_model);
    $('model-field').hidden = !supportsModel;
    $('priority-field').hidden = !supportsModel;
    $('notes-field').hidden = !supportsModel;
    $('f-key').placeholder = provider ? provider.key_placeholder : 'API key';
    if (provider && $('edit-id').value === '0') {
      $('f-model').value = provider.default_model || '';
    }
  }

  async function loadProfiles() {
    try {
      const data = await request('list');
      profiles = data.profiles || [];
      accounts = data.accounts || [];
      initProviders();
      renderAccounts();
      populateAccountSelectors();
      renderProfiles();
    } catch (error) {
      $('profiles-body').innerHTML = `<tr><td colspan="10" class="health err">${esc(error.message)}</td></tr>`;
    }
  }

  function populateAccountSelectors() {
    const accountSelect = $('f-account');
    const filterSelect = $('account-filter');
    const selectedAccount = accountSelect.value || '0';
    const selectedFilter = filterSelect.value || 'all';
    const options = accounts.map(account =>
      `<option value="${Number(account.id)}">${esc(account.email)}${Number(account.active) === 1 ? '' : ' (inactiva)'}</option>`
    ).join('');
    accountSelect.innerHTML = '<option value="0">Sin asignar</option>' + options;
    filterSelect.innerHTML = '<option value="all">Todas las cuentas</option><option value="unassigned">Sin asignar</option>' + options;
    accountSelect.value = accounts.some(account => String(account.id) === selectedAccount) ? selectedAccount : '0';
    filterSelect.value = selectedFilter === 'unassigned' || accounts.some(account => String(account.id) === selectedFilter)
      ? selectedFilter
      : 'all';
  }

  function renderAccounts() {
    const container = $('account-list');
    if (!accounts.length) {
      container.innerHTML = '<span class="sub">Aún no hay cuentas principales.</span>';
      return;
    }
    container.innerHTML = accounts.map(account => `<div class="account-card ${Number(account.active) === 1 ? '' : 'off'}" data-account-id="${Number(account.id)}">
    <div class="account-email">${esc(account.email)}</div>
    <div class="account-meta">${esc(account.label || account.email)} · ${Number(account.api_key_count)} API Keys</div>
    <button class="small" type="button" data-action="open-account-manager" data-account-id="${Number(account.id)}">Asignar proveedores</button>
    <button class="small sec" type="button" data-action="edit-account" data-account-id="${Number(account.id)}">Editar</button>
    <button class="small sec" type="button" data-action="toggle-account" data-account-id="${Number(account.id)}">${Number(account.active) === 1 ? 'Desactivar' : 'Activar'}</button>
    <button class="small danger" type="button" data-action="delete-account" data-account-id="${Number(account.id)}">Eliminar</button>
  </div>`).join('');
  }

  async function saveAccount() {
    const id = $('account-edit-id').value;
    const email = $('account-email').value.trim();
    const label = $('account-label').value.trim();
    const notes = $('account-notes').value.trim();
    if (!email) return setAccountMsg('Falta el correo', false);
    try {
      const result = await request('account_save', {id, email, label, notes});
      const isNew = id === '0';
      setAccountMsg(id === '0' ? 'Cuenta creada' : 'Cuenta actualizada');
      resetAccountForm(false);
      await loadProfiles();
      if (isNew) openAccountManager(result.id);
    } catch (error) { setAccountMsg(error.message, false); }
  }

  function managerAccount() {
    return accounts.find(item => Number(item.id) === Number(activeAccountId));
  }

  function setManagerMessage(message, ok = true) {
    const node = $('manager-msg');
    node.textContent = message;
    node.className = 'msg ' + (ok ? 'ok' : 'err');
  }

  function openAccountManager(id) {
    const account = accounts.find(item => Number(item.id) === Number(id));
    if (!account) return;
    activeAccountId = Number(id);
    $('manager-account').textContent = `${account.label || account.email} - ${account.email}`;
    setManagerMessage('');
    const availableProviders = [...new Map(
      profiles.map(profile => [profile.provider, profile.provider_label])
    ).entries()].sort((a, b) => a[1].localeCompare(b[1], 'es'));
    $('manager-provider').innerHTML = availableProviders.length
      ? availableProviders.map(([key, label]) => `<option value="${esc(key)}">${esc(label)}</option>`).join('')
      : '<option value="">No hay proveedores con leads</option>';
    $('account-manager').hidden = false;
    populateManagerLeads();
    renderAssignedLeads();
  }

  function closeAccountManager() {
    $('account-manager').hidden = true;
    activeAccountId = 0;
  }

  function populateManagerLeads() {
    const provider = $('manager-provider').value;
    const matches = profiles.filter(profile => profile.provider === provider);
    const leadSelect = $('manager-lead');
    leadSelect.innerHTML = matches.length
      ? matches.map(profile => {
          const owner = profile.account_id === null ? 'sin asignar' : `asignado a ${profile.account_label || profile.account_email}`;
          return `<option value="${Number(profile.id)}">#${Number(profile.id)} - ${esc(profile.label)} - ${esc(owner)}</option>`;
        }).join('')
      : '<option value="">No hay leads para este proveedor</option>';
    renderLeadPreview();
  }

  function selectedManagerLead() {
    const provider = $('manager-provider').value;
    const id = Number($('manager-lead').value || 0);
    return profiles.find(profile => profile.provider === provider && Number(profile.id) === id);
  }

  function renderLeadPreview() {
    const account = managerAccount();
    const profile = selectedManagerLead();
    const preview = $('lead-preview');
    const button = $('assign-lead-button');
    if (!account || !profile) {
      preview.innerHTML = '<span class="health none">No existe un lead para seleccionar.</span>';
      button.disabled = true;
      return;
    }
    const healthClass = profile.last_status === 'error' ? 'err' : (profile.last_status ? 'ok' : 'none');
    const currentOwner = profile.account_id === null
      ? 'Sin asignar'
      : `${profile.account_label || profile.account_email} (${profile.account_email})`;
    preview.innerHTML = `<div><strong>Cuenta principal:</strong> ${esc(account.label || account.email)} - ${esc(account.email)}</div>
    <div><strong>Proveedor:</strong> ${esc(profile.provider_label)}</div>
    <div><strong>Lead:</strong> #${Number(profile.id)} - ${esc(profile.label)}</div>
    <div><strong>API key:</strong> <span class="key">${esc(profile.api_key_masked)}</span></div>
    <div><strong>Estado:</strong> ${Number(profile.active) === 1 ? 'Activo' : 'Inactivo'}</div>
    <div><strong>Salud:</strong> <span class="health ${healthClass}">${esc(profile.status_detail || 'Sin comprobar')}</span></div>
    <div><strong>Ultima comprobacion:</strong> ${esc(profile.last_checked_at || '-')}</div>
    <div><strong>Asignacion actual:</strong> ${esc(currentOwner)}</div>`;
    button.disabled = Number(profile.account_id) === Number(activeAccountId);
    button.textContent = button.disabled ? 'Ya esta asignado' : 'Confirmar asignacion';
  }

  function renderAssignedLeads() {
    const container = $('assigned-leads');
    const assigned = profiles.filter(profile => Number(profile.account_id) === Number(activeAccountId));
    if (!assigned.length) {
      container.innerHTML = '<div class="sub">Esta cuenta todavia no tiene leads asignados.</div>';
      return;
    }
    container.innerHTML = assigned.map(profile => {
      const healthClass = profile.last_status === 'error' ? 'err' : (profile.last_status ? 'ok' : 'none');
      return `<div class="assigned-row">
      <div><strong>${esc(profile.provider_label)}</strong><div class="date">Lead #${Number(profile.id)} - ${esc(profile.label)}</div></div>
      <div class="key">${esc(profile.api_key_masked)}</div>
      <div><span class="pill ${Number(profile.active) === 1 ? 'on' : 'off'}">${Number(profile.active) === 1 ? 'Activo' : 'Inactivo'}</span><div class="health ${healthClass}">${esc(profile.status_detail || 'Sin comprobar')}</div></div>
      <div class="nowrap"><button class="small" data-action="refresh-managed-lead" data-provider="${esc(profile.provider)}" data-id="${Number(profile.id)}">Actualizar</button><button class="small danger" data-action="unassign-lead" data-provider="${esc(profile.provider)}" data-id="${Number(profile.id)}">Quitar</button></div>
    </div>`;
    }).join('');
  }

  async function assignSelectedLead() {
    const profile = selectedManagerLead();
    const account = managerAccount();
    if (!profile || !account) return;
    const previousOwner = profile.account_id === null ? '' : ` Actualmente pertenece a ${profile.account_label || profile.account_email}.`;
    if (!confirm(`Asignar ${profile.provider_label} / ${profile.label} a ${account.label || account.email}?${previousOwner}`)) return;
    try {
      await request('account_assign', {account_id: activeAccountId, provider: profile.provider, id: profile.id});
      const keepAccount = activeAccountId;
      await loadProfiles();
      activeAccountId = keepAccount;
      setManagerMessage('Lead asignado correctamente');
      populateManagerLeads();
      renderAssignedLeads();
    } catch (error) { setManagerMessage(error.message, false); }
  }

  async function unassignLead(provider, id) {
    const profile = profiles.find(item => item.provider === provider && Number(item.id) === Number(id));
    if (!profile || !confirm(`Quitar ${profile.provider_label} / ${profile.label} de esta cuenta? La API key no se borrara.`)) return;
    try {
      await request('account_unassign', {account_id: activeAccountId, provider, id});
      const keepAccount = activeAccountId;
      await loadProfiles();
      activeAccountId = keepAccount;
      setManagerMessage('Asignacion eliminada; la API key se conserva');
      populateManagerLeads();
      renderAssignedLeads();
    } catch (error) { setManagerMessage(error.message, false); }
  }

  async function refreshManagedLead(provider, id, button) {
    button.disabled = true;
    try {
      await request('refresh', {provider, id});
      const keepAccount = activeAccountId;
      await loadProfiles();
      activeAccountId = keepAccount;
      populateManagerLeads();
      renderAssignedLeads();
      setManagerMessage('Estado actualizado');
    } catch (error) { setManagerMessage(error.message, false); }
    finally { button.disabled = false; }
  }

  function editAccount(id) {
    const account = accounts.find(item => Number(item.id) === Number(id));
    if (!account) return;
    $('account-edit-id').value = String(account.id);
    $('account-email').value = account.email || '';
    $('account-label').value = account.label || '';
    $('account-notes').value = account.notes || '';
    $('account-form-title').textContent = 'Editar cuenta principal';
    $('cancel-account-edit').hidden = false;
  }

  function resetAccountForm(clearMessage = true) {
    $('account-edit-id').value = '0';
    $('account-email').value = '';
    $('account-label').value = '';
    $('account-notes').value = '';
    $('account-form-title').textContent = 'Cuentas principales';
    $('cancel-account-edit').hidden = true;
    if (clearMessage) setAccountMsg('');
  }

  async function toggleAccount(id) {
    try { await request('account_toggle', {id}); await loadProfiles(); }
    catch (error) { setAccountMsg(error.message, false); }
  }

  async function deleteAccount(id) {
    const account = accounts.find(item => Number(item.id) === Number(id));
    if (!account || !confirm(`¿Eliminar la cuenta ${account.email}? Las API Keys quedarán sin asignar.`)) return;
    try {
      await request('account_delete', {id});
      setAccountMsg('Cuenta eliminada; las API Keys no fueron borradas');
      await loadProfiles();
    } catch (error) { setAccountMsg(error.message, false); }
  }

  function renderProfiles() {
    const body = $('profiles-body');
    const accountFilter = $('account-filter').value;
    const visibleProfiles = profiles.filter(profile => {
      if (accountFilter === 'all') return true;
      if (accountFilter === 'unassigned') return profile.account_id === null;
      return String(profile.account_id) === accountFilter;
    });
    if (!visibleProfiles.length) {
      body.innerHTML = '<tr><td colspan="10" class="muted-cell">No hay API Keys para este filtro.</td></tr>';
    } else {
      body.innerHTML = visibleProfiles.map(profile => {
        const healthClass = profile.last_status === 'error' ? 'err' : (profile.last_status ? 'ok' : 'none');
        const healthText = profile.status_detail || 'Sin comprobar';
        const model = profile.model || '—';
        const priority = profile.priority === null ? '—' : profile.priority;
        const actionLabel = Number(profile.active) === 1 ? 'Desactivar' : 'Activar';
        const owner = profile.account_id === null
          ? '<span class="health none">Sin asignar</span>'
          : `<div>${esc(profile.account_label || profile.account_email)}</div><div class="date">${esc(profile.account_email)}</div>`;
        return `<tr>
        <td class="provider">${esc(profile.provider_label)}</td>
        <td>${owner}</td>
        <td>${esc(profile.label)}</td>
        <td class="key">${esc(profile.api_key_masked)}</td>
        <td>${esc(model)}</td>
        <td><span class="pill ${Number(profile.active) === 1 ? 'on' : 'off'}">${Number(profile.active) === 1 ? 'Activo' : 'Inactivo'}</span></td>
        <td><div class="health ${healthClass}">${esc(healthText)}</div><div class="date">${esc(profile.last_checked_at || '—')}</div></td>
        <td class="date">${esc(profile.last_used_at || '—')}</td>
        <td>${esc(priority)}</td>
        <td class="nowrap">
          <button class="small" data-action="refresh-profile" data-provider="${esc(profile.provider)}" data-id="${Number(profile.id)}">Actualizar</button>
          <button class="small sec" data-action="test-profile" data-provider="${esc(profile.provider)}" data-id="${Number(profile.id)}">Test</button>
          <button class="small sec" data-action="edit-profile" data-provider="${esc(profile.provider)}" data-id="${Number(profile.id)}">Editar</button>
          <button class="small sec" data-action="toggle-profile" data-provider="${esc(profile.provider)}" data-id="${Number(profile.id)}">${actionLabel}</button>
          <button class="small danger" data-action="delete-profile" data-provider="${esc(profile.provider)}" data-id="${Number(profile.id)}">Eliminar</button>
        </td>
      </tr>`;
      }).join('');
    }

    const total = profiles.length;
    const active = profiles.filter(profile => Number(profile.active) === 1).length;
    const providers = new Set(profiles.map(profile => profile.provider)).size;
    const unassigned = profiles.filter(profile => profile.account_id === null).length;
    $('summary').innerHTML =
      `<span>${accounts.length} cuentas principales</span><span>${providers} proveedores</span><span>${active} API Keys activas</span><span>${total} API Keys totales</span><span>${unassigned} sin asignar</span>`;
  }

  async function saveProfile() {
    const id = $('edit-id').value;
    const account_id = $('f-account').value;
    const provider = $('f-provider').value;
    const label = $('f-label').value.trim();
    const api_key = $('f-key').value.trim();
    const model = $('f-model').value.trim();
    const priority = $('f-priority').value || '100';
    const notes = $('f-notes').value.trim();
    if (!label) return setMsg('Falta el nombre o etiqueta', false);
    if (id === '0' && !api_key) return setMsg('Falta la API key', false);
    try {
      await request('save', {id, account_id, provider, label, api_key, model, priority, notes});
      setMsg(id === '0' ? 'API Key creada' : 'API Key actualizada');
      resetForm(false);
      await loadProfiles();
    } catch (error) { setMsg(error.message, false); }
  }

  function editProfile(provider, id) {
    const profile = profiles.find(item => item.provider === provider && Number(item.id) === Number(id));
    if (!profile) return;
    $('edit-id').value = String(profile.id);
    $('f-account').value = profile.account_id === null ? '0' : String(profile.account_id);
    $('f-provider').value = profile.provider;
    $('f-provider').disabled = true;
    $('f-label').value = profile.label || '';
    $('f-key').value = '';
    $('f-key').placeholder = 'Dejar vacío para conservar la actual';
    $('f-model').value = profile.model || '';
    $('f-priority').value = profile.priority === null ? '100' : String(profile.priority);
    $('f-notes').value = profile.notes || '';
    $('form-title').textContent = 'Editar API Key de ' + profile.provider_label;
    $('cancel-edit').hidden = false;
    providerChanged();
    document.querySelector('.form-card').scrollIntoView({behavior: 'smooth', block: 'start'});
  }

  function resetForm(clearMessage = true) {
    $('edit-id').value = '0';
    $('f-account').value = '0';
    $('f-provider').disabled = false;
    $('f-label').value = '';
    $('f-key').value = '';
    $('f-priority').value = '100';
    $('f-notes').value = '';
    $('form-title').textContent = 'Nueva API Key';
    $('cancel-edit').hidden = true;
    if (clearMessage) setMsg('');
    providerChanged();
  }

  async function toggleProfile(provider, id) {
    try { await request('toggle', {provider, id}); await loadProfiles(); }
    catch (error) { setMsg(error.message, false); }
  }

  async function deleteProfile(provider, id) {
    const profile = profiles.find(item => item.provider === provider && Number(item.id) === Number(id));
    if (!profile || !confirm(`¿Eliminar ${profile.provider_label} / ${profile.label}?`)) return;
    try { await request('delete', {provider, id}); setMsg('Cuenta eliminada'); await loadProfiles(); }
    catch (error) { setMsg(error.message, false); }
  }

  async function refreshProfile(provider, id, button = null) {
    if (button) button.disabled = true;
    try { await request('refresh', {provider, id}); await loadProfiles(); return true; }
    catch (error) { setMsg(error.message, false); await loadProfiles(); return false; }
    finally { if (button) button.disabled = false; }
  }

  async function refreshAll() {
    const button = $('refresh-all');
    const activeProfiles = profiles.filter(profile => Number(profile.active) === 1);
    if (!activeProfiles.length) return setMsg('No hay cuentas activas para actualizar', false);
    button.disabled = true;
    button.textContent = 'Actualizando…';
    setMsg(`Consultando ${activeProfiles.length} cuentas…`);
    const results = await Promise.allSettled(activeProfiles.map(profile =>
      request('refresh', {provider: profile.provider, id: profile.id})
    ));
    const failed = results.filter(result => result.status === 'rejected').length;
    await loadProfiles();
    setMsg(failed ? `Actualización terminada: ${failed} con error` : 'Todos los estados fueron actualizados', failed === 0);
    button.disabled = false;
    button.textContent = 'Actualizar estados';
  }

  function openTestModal(provider, id) {
    const profile = profiles.find(item => item.provider === provider && Number(item.id) === Number(id));
    if (!profile) return;
    testTarget = {provider, id};
    $('test-sub').textContent = `${profile.provider_label} - ${profile.label}`;
    $('test-audio-file').value = '';
    $('test-msg').textContent = '';
    $('test-msg').className = 'msg';
    const resultBox = $('test-result');
    resultBox.hidden = true;
    resultBox.innerHTML = '';
    $('test-modal').hidden = false;
  }

  function closeTestModal() {
    $('test-modal').hidden = true;
    testTarget = null;
  }

  async function sendTestAudio() {
    if (!testTarget) return;
    const input = $('test-audio-file');
    const file = input.files[0];
    const msg = $('test-msg');
    const resultBox = $('test-result');
    if (!file) { msg.textContent = 'Selecciona un audio'; msg.className = 'msg err'; return; }
    if (file.size > 2 * 1024 * 1024) { msg.textContent = 'Maximo 2 MB'; msg.className = 'msg err'; return; }
    const button = $('test-send-button');
    button.disabled = true;
    msg.textContent = 'Transcribiendo... (puede tardar unos segundos)';
    msg.className = 'msg';
    resultBox.hidden = true;
    try {
      const body = new FormData();
      body.append('action', 'test_audio');
      body.append('csrf', CSRF);
      body.append('provider', testTarget.provider);
      body.append('id', testTarget.id);
      body.append('audio', file, file.name);
      const response = await fetch(window.ZYNERVOX_STT_ENDPOINT || 'stt_providers_admin.php', {method: 'POST', body, cache: 'no-store'});
      let payload;
      try { payload = await response.json(); }
      catch (error) { throw new Error('Respuesta invalida del servidor'); }
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Error del servidor');
      msg.textContent = 'Transcripcion exitosa';
      msg.className = 'msg ok';
      resultBox.hidden = false;
      resultBox.innerHTML = `<strong>Texto transcrito:</strong><div class="transcript">${esc(payload.transcript || '(vacio)')}</div>`;
    } catch (error) {
      msg.textContent = error.message;
      msg.className = 'msg err';
    } finally {
      button.disabled = false;
    }
  }

  // ---- Delegación de eventos (sin handlers inline, CSP-safe) ----
  const actions = {
    'open-account-manager': el => openAccountManager(el.dataset.accountId),
    'edit-account': el => editAccount(el.dataset.accountId),
    'toggle-account': el => toggleAccount(el.dataset.accountId),
    'delete-account': el => deleteAccount(el.dataset.accountId),
    'refresh-managed-lead': el => refreshManagedLead(el.dataset.provider, el.dataset.id, el),
    'unassign-lead': el => unassignLead(el.dataset.provider, el.dataset.id),
    'refresh-profile': el => refreshProfile(el.dataset.provider, el.dataset.id, el),
    'test-profile': el => openTestModal(el.dataset.provider, el.dataset.id),
    'edit-profile': el => editProfile(el.dataset.provider, el.dataset.id),
    'toggle-profile': el => toggleProfile(el.dataset.provider, el.dataset.id),
    'delete-profile': el => deleteProfile(el.dataset.provider, el.dataset.id),
  };

  document.addEventListener('click', event => {
    const el = event.target.closest('[data-action]');
    if (!el) return;
    const handler = actions[el.dataset.action];
    if (handler) handler(el);
  });

  document.addEventListener('change', event => {
    const el = event.target;
    if (el.id === 'f-provider') providerChanged();
    if (el.id === 'account-filter') renderProfiles();
    if (el.id === 'manager-provider') populateManagerLeads();
    if (el.id === 'manager-lead') renderLeadPreview();
  });

  function bind(id, fn) {
    const el = $(id);
    if (el) el.addEventListener('click', fn);
  }

  bind('refresh-all', refreshAll);
  bind('save-account-button', saveAccount);
  bind('cancel-account-edit', () => resetAccountForm());
  bind('save-profile-button', saveProfile);
  bind('cancel-edit', () => resetForm());
  bind('close-account-manager', closeAccountManager);
  bind('assign-lead-button', assignSelectedLead);
  bind('close-test-modal', closeTestModal);
  bind('test-send-button', sendTestAudio);

  loadProfiles();
})();
