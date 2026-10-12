<?php
require_once __DIR__ . '/../../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

// MVP (2026-10-12): el servicio real (pool de proxies + generacion TTS) vive
// en docker_converxa, fuera de zynervoxv2. Esta pagina solo es la vitrina
// administrativa; el navegador del admin habla WebSocket DIRECTO contra ese
// servicio (no hay API intermedia en PHP todavia -- el servicio no tiene
// autenticacion propia, por eso esta pagina esta detras de Auth nivel 9).
$audioWsUrl = 'ws://172.16.10.26:8900';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Servicios / Zyner Vox Audio Creator - Zynervox</title>
    <link rel="stylesheet" href="../layout.css">
    <style>
        .ac-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.6rem; margin-top: 0.6rem; }
        .ac-stat { font-size: 1.6rem; font-weight: 700; }
        .ac-stat-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; }
        .ac-badge { display: inline-block; padding: 2px 10px; border-radius: 2px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .ac-badge.up { background: #d1fae5; color: #047857; }
        .ac-badge.down { background: #fee2e2; color: #b91c1c; }
        .ac-badge.unknown { background: var(--glass); color: var(--text-muted); }
        .ac-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
        .ac-row label { min-width: 90px; margin: 0; }
        textarea#ac-text { width: 100%; min-height: 80px; font-family: inherit; font-size: 0.85rem; }
        .ac-msg { font-size: 0.75rem; margin-top: 0.4rem; min-height: 1em; }
        .ac-msg.error { color: #b91c1c; }
        .ac-msg.ok { color: #047857; }
        .ac-upload-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-top: 0.4rem; }
        .ac-upload-row input[type="text"] { width: 220px; }
        table.ac-table { width: 100%; border-collapse: collapse; margin-top: 0.6rem; font-size: 0.78rem; }
        table.ac-table th, table.ac-table td { text-align: left; padding: 0.35rem 0.5rem; border-bottom: 1px solid var(--glass); }
        .ac-search { width: 100%; max-width: 320px; margin-bottom: 0.6rem; }
        .ac-proxy-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(30px, 1fr)); gap: 4px; }
        .ac-proxy-cell { width: 100%; aspect-ratio: 1; border-radius: 3px; cursor: default; }
        .ac-proxy-cell.disponible { background: #d1d5db; }
        .ac-proxy-cell.procesando { background: #f59e0b; }
        .ac-proxy-cell.bloqueado { background: #ef4444; }
        .ac-legend { display: flex; gap: 1rem; font-size: 0.72rem; color: var(--text-muted); margin-top: 0.5rem; align-items: center; }
        .ac-legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
        .ac-modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 50; align-items: center; justify-content: center; }
        .ac-modal-backdrop.open { display: flex; }
        .ac-modal { background: #1b2333; border-radius: 6px; padding: 1rem; width: min(900px, 92vw); max-height: 85vh; overflow: auto; }
        .ac-modal.ac-map-dialog { width: min(1600px, calc(100vw - 24px)); }
        .ac-modal h3 { color: #fff; margin: 0 0 0.6rem; }
        .ac-modal-close { float: right; cursor: pointer; color: #fff; font-size: 1.1rem; }
        .ac-map-head { display: flex; align-items: end; justify-content: space-between; gap: 30px; flex-wrap: wrap; margin-bottom: 0.6rem; }
        .ac-map-totals { display: flex; gap: 15px; flex-wrap: wrap; color: var(--text-muted); font-size: 10px; }
        .ac-map-totals span { display: flex; align-items: center; gap: 5px; }
        .ac-map-totals i { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; box-shadow: 0 0 7px currentColor; }
        .ac-map-totals .proc-dot { background: #f59e0b; }
        .ac-map-totals .block-dot { background: #ef4444; }
        .ac-map-totals .pending-dot { background: #6b7280; }
        .ac-live-map { position: relative; width: 100%; height: min(calc(100vh - 125px), calc((100vw - 62px) / 2)); min-height: 420px; overflow: hidden; border: 1px solid #203049; border-radius: 13px; background: linear-gradient(#09111c,#0a1320); }
        .ac-live-map::before { content: ""; position: absolute; inset: 0; background-image: linear-gradient(rgba(65,87,119,.08) 1px,transparent 1px), linear-gradient(90deg, rgba(65,87,119,.08) 1px, transparent 1px); background-size: 8.333% 16.666%; }
        .ac-live-map > img, .ac-map-points { position: absolute; inset: 0; width: 100%; height: 100%; }
        .ac-live-map > img { object-fit: fill; opacity: .96; filter: drop-shadow(0 0 12px #172b45); }
        .ac-map-dot { position: absolute; z-index: 3; width: 8px; height: 8px; padding: 0; translate: -50% -50%; border: 1px solid rgba(255,255,255,.65); border-radius: 50%; background: #d1d5db; box-shadow: 0 0 5px rgba(209,213,219,.65); }
        .ac-map-dot.procesando { background: #f59e0b; box-shadow: 0 0 8px #f59e0b, 0 0 18px #f59e0b; animation: ac-map-pulse .9s ease-in-out infinite; }
        .ac-map-dot.bloqueado { background: #ef4444; box-shadow: 0 0 10px #ef4444; animation: ac-map-error .65s ease-in-out infinite alternate; }
        .ac-map-dot::after { content: attr(data-tip); position: absolute; left: 50%; bottom: 14px; translate: -50% 0; display: none; min-width: max-content; padding: 5px 7px; border: 1px solid #30435f; border-radius: 6px; background: #07101c; color: #dce8fb; font-size: 9px; pointer-events: none; }
        .ac-map-dot:hover { z-index: 8; scale: 1.65; }
        .ac-map-dot:hover::after { display: block; scale: .61; }
        @keyframes ac-map-pulse { 50% { scale: 1.7; opacity: .65; } }
        @keyframes ac-map-error { to { opacity: .35; } }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../sidebar.php'; renderSidebar('audio_create', '../../../'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 0.5rem;">
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700;">Servicios / Zyner Vox Audio Creator</h1>
                <p style="color: var(--text-muted); font-size: 0.75rem;">
                    Generacion TTS vía pool de proxies rotativo (MVP, WebSocket directo) — docker_converxa
                </p>
            </div>
        </header>

        <div class="ac-grid">
            <div class="card">
                <h2>Estado del servicio</h2>
                <p><span id="ac-status" class="ac-badge unknown">Verificando...</span></p>
                <p style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.4rem;">
                    <code><?= htmlspecialchars($audioWsUrl, ENT_QUOTES, 'UTF-8') ?></code>
                </p>
            </div>

            <div class="card">
                <h2>Pool de proxies</h2>
                <div class="ac-grid" style="grid-template-columns: repeat(3, 1fr); margin-top: 0;">
                    <div><div id="ac-total" class="ac-stat">-</div><div class="ac-stat-label">Cargados</div></div>
                    <div><div id="ac-available" class="ac-stat">-</div><div class="ac-stat-label">Disponibles</div></div>
                    <div><div id="ac-blocked" class="ac-stat">-</div><div class="ac-stat-label">Bloqueados</div></div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top: 0.6rem;">
            <h2>Generar audio de prueba</h2>
            <div class="ac-row">
                <label for="ac-text">Texto</label>
            </div>
            <textarea id="ac-text" placeholder="Hola, tienes una deuda de 120 soles"></textarea>
            <div class="ac-row" style="margin-top: 0.5rem;">
                <label for="ac-lang">Idioma</label>
                <input type="text" id="ac-lang" value="es" style="width: 60px;">
                <label for="ac-speed" style="min-width: 60px;">Velocidad</label>
                <input type="number" id="ac-speed" value="1.3" step="0.1" min="0.5" max="2.0" style="width: 70px;">
                <button type="button" id="ac-generate" class="btn-action">Generar</button>
            </div>
            <p id="ac-msg" class="ac-msg"></p>
            <audio id="ac-player" controls style="display:none; margin-top: 0.5rem; width: 100%;"></audio>
        </div>

        <div class="card" style="margin-top: 0.6rem;">
            <h2>Cuentas proxy</h2>
            <p style="font-size: 0.72rem; color: var(--text-muted);">Sube un archivo TXT (una cuenta por línea). El servicio recarga el pool solo, sin reiniciar.</p>
            <div class="ac-upload-row">
                <input type="text" id="ac-upload-account" placeholder="Cuenta (ej. gmail2)">
                <input type="file" id="ac-upload-file" accept=".txt">
                <button type="button" id="ac-upload-btn" class="btn-action">Subir</button>
            </div>
            <p id="ac-upload-msg" class="ac-msg"></p>
            <table class="ac-table">
                <thead><tr><th>Archivo</th><th>Cuenta</th><th>Subido</th><th></th></tr></thead>
                <tbody id="ac-files-body"><tr><td colspan="4">Cargando...</td></tr></tbody>
            </table>
        </div>

        <div class="card" style="margin-top: 0.6rem;">
            <h2>Estado de proxies</h2>
            <div class="ac-row" style="justify-content: space-between;">
                <input type="text" id="ac-proxy-search" class="ac-search" placeholder="Buscar IP, puerto o país...">
                <button type="button" id="ac-map-btn" class="btn-action">Mapa mundial</button>
            </div>
            <div id="ac-proxy-grid" class="ac-proxy-grid"></div>
            <div class="ac-legend">
                <span><span class="ac-legend-dot" style="background:#d1d5db;"></span>Disponible</span>
                <span><span class="ac-legend-dot" style="background:#f59e0b;"></span>Procesando</span>
                <span><span class="ac-legend-dot" style="background:#ef4444;"></span>Bloqueado</span>
            </div>
        </div>
    </main>

    <div class="ac-modal-backdrop" id="ac-map-backdrop">
        <div class="ac-modal ac-map-dialog">
            <span class="ac-modal-close" id="ac-map-close">&times;</span>
            <header class="ac-map-head">
                <div>
                    <p style="color:#f59e0b; letter-spacing:.16em; font-size:10px; font-weight:800; margin:0 0 4px;">DISTRIBUCIÓN GLOBAL</p>
                    <h3 style="margin:0;">Actividad por ubicación</h3>
                </div>
                <div class="ac-map-totals">
                    <span><i></i><b id="ac-map-avail">0</b> disponibles</span>
                    <span><i class="proc-dot"></i><b id="ac-map-proc">0</b> procesando</span>
                    <span><i class="block-dot"></i><b id="ac-map-block">0</b> bloqueados</span>
                    <span><i class="pending-dot"></i><b id="ac-map-pending">0</b> ubicación pendiente</span>
                </div>
            </header>
            <div class="ac-live-map">
                <img src="world-map-real.svg" alt="Mapa mundial">
                <div id="ac-map-points" class="ac-map-points"></div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var WS_URL = <?= json_encode($audioWsUrl) ?>;
        var statusEl = document.getElementById('ac-status');
        var totalEl = document.getElementById('ac-total');
        var availEl = document.getElementById('ac-available');
        var blockedEl = document.getElementById('ac-blocked');
        var textEl = document.getElementById('ac-text');
        var langEl = document.getElementById('ac-lang');
        var speedEl = document.getElementById('ac-speed');
        var genBtn = document.getElementById('ac-generate');
        var msgEl = document.getElementById('ac-msg');
        var player = document.getElementById('ac-player');

        function setMsg(text, kind) {
            msgEl.textContent = text || '';
            msgEl.className = 'ac-msg' + (kind ? ' ' + kind : '');
        }

        function setStatus(up) {
            statusEl.textContent = up ? 'Disponible' : 'Sin respuesta';
            statusEl.className = 'ac-badge ' + (up ? 'up' : 'down');
        }

        // Conexion corta: abre, pide stats, cierra. Simple de mas para dejar
        // un socket persistente aqui (esta vista solo consulta cada 5s).
        function refreshStats() {
            var ws;
            try {
                ws = new WebSocket(WS_URL);
            } catch (e) {
                setStatus(false);
                return;
            }
            var timeout = setTimeout(function () { try { ws.close(); } catch (e) {} }, 4000);
            ws.onopen = function () {
                ws.send(JSON.stringify({ action: 'stats' }));
            };
            ws.onmessage = function (ev) {
                clearTimeout(timeout);
                try {
                    var data = JSON.parse(ev.data);
                    if (data.ok) {
                        setStatus(true);
                        totalEl.textContent = data.total;
                        availEl.textContent = data.available;
                        blockedEl.textContent = data.blocked;
                    }
                } catch (e) { /* ignore */ }
                ws.close();
            };
            ws.onerror = function () {
                clearTimeout(timeout);
                setStatus(false);
            };
        }

        genBtn.addEventListener('click', function () {
            var text = textEl.value.trim();
            if (!text) {
                setMsg('Escribe un texto primero.', 'error');
                return;
            }
            genBtn.disabled = true;
            setMsg('Generando...', '');
            player.style.display = 'none';

            var ws = new WebSocket(WS_URL);
            ws.binaryType = 'arraybuffer';
            var timeout = setTimeout(function () {
                setMsg('Tiempo de espera agotado.', 'error');
                genBtn.disabled = false;
                try { ws.close(); } catch (e) {}
            }, 90000);

            ws.onopen = function () {
                ws.send(JSON.stringify({
                    text: text,
                    lang: langEl.value.trim() || 'es',
                    speed: parseFloat(speedEl.value) || 1.3
                }));
            };
            ws.onmessage = function (ev) {
                clearTimeout(timeout);
                genBtn.disabled = false;
                if (ev.data instanceof ArrayBuffer) {
                    var blob = new Blob([ev.data], { type: 'audio/wav' });
                    player.src = URL.createObjectURL(blob);
                    player.style.display = 'block';
                    setMsg('Listo (' + ev.data.byteLength + ' bytes).', 'ok');
                } else {
                    try {
                        var data = JSON.parse(ev.data);
                        setMsg('Error: ' + (data.error || 'desconocido'), 'error');
                    } catch (e) {
                        setMsg('Respuesta invalida del servicio.', 'error');
                    }
                }
                ws.close();
            };
            ws.onerror = function () {
                clearTimeout(timeout);
                genBtn.disabled = false;
                setMsg('Error de conexion con el servicio.', 'error');
            };
        });

        // Abre un WS corto, manda una accion JSON, resuelve con la respuesta
        // y cierra. Reutilizado por stats/pool/files/upload/delete.
        function callAction(payload, timeoutMs) {
            return new Promise(function (resolve, reject) {
                var ws;
                try {
                    ws = new WebSocket(WS_URL);
                } catch (e) {
                    reject(e);
                    return;
                }
                var timer = setTimeout(function () {
                    try { ws.close(); } catch (e) {}
                    reject(new Error('timeout'));
                }, timeoutMs || 8000);
                ws.onopen = function () { ws.send(JSON.stringify(payload)); };
                ws.onmessage = function (ev) {
                    clearTimeout(timer);
                    try { resolve(JSON.parse(ev.data)); } catch (e) { reject(e); }
                    ws.close();
                };
                ws.onerror = function (e) { clearTimeout(timer); reject(e); };
            });
        }

        var proxyGridEl = document.getElementById('ac-proxy-grid');
        var proxySearchEl = document.getElementById('ac-proxy-search');
        var filesBodyEl = document.getElementById('ac-files-body');
        var uploadAccountEl = document.getElementById('ac-upload-account');
        var uploadFileEl = document.getElementById('ac-upload-file');
        var uploadBtn = document.getElementById('ac-upload-btn');
        var uploadMsgEl = document.getElementById('ac-upload-msg');
        var mapBtn = document.getElementById('ac-map-btn');
        var mapBackdrop = document.getElementById('ac-map-backdrop');
        var mapClose = document.getElementById('ac-map-close');
        var mapPointsEl = document.getElementById('ac-map-points');
        var mapAvailEl = document.getElementById('ac-map-avail');
        var mapProcEl = document.getElementById('ac-map-proc');
        var mapBlockEl = document.getElementById('ac-map-block');
        var mapPendingEl = document.getElementById('ac-map-pending');

        var lastPoolItems = [];

        function renderProxyGrid() {
            var filter = proxySearchEl.value.trim().toLowerCase();
            var html = '';
            lastPoolItems.forEach(function (item) {
                var haystack = (item.endpoint + ' ' + (item.country || '') + ' ' + (item.country_code || '')).toLowerCase();
                if (filter && haystack.indexOf(filter) === -1) return;
                var title = item.endpoint + (item.country ? ' — ' + item.country : '') + ' (' + item.status + ')';
                html += '<div class="ac-proxy-cell ' + item.status + '" title="' + title.replace(/"/g, '&quot;') + '"></div>';
            });
            proxyGridEl.innerHTML = html || '<p style="font-size:0.75rem;color:var(--text-muted);">Sin proxies que coincidan.</p>';
        }

        function refreshPool() {
            callAction({ action: 'pool' }, 6000).then(function (data) {
                if (data.ok) {
                    lastPoolItems = data.items || [];
                    renderProxyGrid();
                }
            }).catch(function () { /* se ignora, stats ya marca "sin respuesta" */ });
        }

        function formatUploadedAt(ts) {
            var diffHours = Math.max(0, Math.round((Date.now() / 1000 - ts) / 3600));
            return 'hace ' + diffHours + 'h';
        }

        function renderFiles(items) {
            if (!items.length) {
                filesBodyEl.innerHTML = '<tr><td colspan="4">Sin archivos cargados.</td></tr>';
                return;
            }
            var html = '';
            items.forEach(function (it) {
                html += '<tr>' +
                    '<td>' + it.name + '</td>' +
                    '<td>' + it.account + '</td>' +
                    '<td>' + formatUploadedAt(it.uploaded_at) + '</td>' +
                    '<td><button type="button" class="btn-action ac-delete-file" data-name="' + it.name.replace(/"/g, '&quot;') + '">Eliminar</button></td>' +
                    '</tr>';
            });
            filesBodyEl.innerHTML = html;
            filesBodyEl.querySelectorAll('.ac-delete-file').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('¿Eliminar ' + btn.getAttribute('data-name') + '?')) return;
                    callAction({ action: 'delete_file', name: btn.getAttribute('data-name') }, 8000)
                        .then(function () { refreshFiles(); refreshPool(); refreshStats(); })
                        .catch(function () { alert('Error al eliminar.'); });
                });
            });
        }

        function refreshFiles() {
            callAction({ action: 'files' }, 6000).then(function (data) {
                if (data.ok) renderFiles(data.items || []);
            }).catch(function () {
                filesBodyEl.innerHTML = '<tr><td colspan="4">Sin respuesta del servicio.</td></tr>';
            });
        }

        uploadBtn.addEventListener('click', function () {
            var account = uploadAccountEl.value.trim();
            var file = uploadFileEl.files[0];
            if (!account || !file) {
                uploadMsgEl.textContent = 'Indica una cuenta y selecciona un archivo.';
                uploadMsgEl.className = 'ac-msg error';
                return;
            }
            var reader = new FileReader();
            reader.onload = function () {
                uploadBtn.disabled = true;
                uploadMsgEl.textContent = 'Subiendo...';
                uploadMsgEl.className = 'ac-msg';
                callAction({
                    action: 'upload',
                    account: account,
                    filename: file.name,
                    content: reader.result
                }, 15000).then(function (data) {
                    uploadBtn.disabled = false;
                    if (data.ok) {
                        uploadMsgEl.textContent = 'Listo.';
                        uploadMsgEl.className = 'ac-msg ok';
                        uploadAccountEl.value = '';
                        uploadFileEl.value = '';
                        refreshFiles();
                        refreshPool();
                        refreshStats();
                    } else {
                        uploadMsgEl.textContent = 'Error: ' + (data.error || 'desconocido');
                        uploadMsgEl.className = 'ac-msg error';
                    }
                }).catch(function () {
                    uploadBtn.disabled = false;
                    uploadMsgEl.textContent = 'Error de conexión al subir.';
                    uploadMsgEl.className = 'ac-msg error';
                });
            };
            reader.readAsText(file);
        });

        proxySearchEl.addEventListener('input', renderProxyGrid);

        // Mapa real (mismo asset/formula que modules/admin/farm.php -> monitor):
        // proyeccion equirectangular lon/lat -> % de posicion sobre world-map-real.svg.
        function renderMap() {
            mapPointsEl.textContent = '';
            var avail = 0, proc = 0, blocked = 0, pending = 0;
            lastPoolItems.forEach(function (item) {
                var hasCoords = item.lat !== null && item.lat !== undefined && item.lon !== null && item.lon !== undefined;
                if (item.status === 'procesando') proc++;
                else if (item.status === 'bloqueado') blocked++;
                else avail++;
                if (!hasCoords) { pending++; return; }
                var x = (item.lon + 180) / 360 * 100;
                var y = (90 - item.lat) / 180 * 100;
                var dot = document.createElement('div');
                dot.className = 'ac-map-dot ' + item.status;
                dot.style.left = x + '%';
                dot.style.top = y + '%';
                dot.dataset.tip = item.endpoint + (item.country ? ' — ' + item.country : '') + ' (' + item.status + ')';
                mapPointsEl.appendChild(dot);
            });
            mapAvailEl.textContent = avail;
            mapProcEl.textContent = proc;
            mapBlockEl.textContent = blocked;
            mapPendingEl.textContent = pending;
        }

        mapBtn.addEventListener('click', function () {
            renderMap();
            mapBackdrop.classList.add('open');
        });
        mapClose.addEventListener('click', function () { mapBackdrop.classList.remove('open'); });
        mapBackdrop.addEventListener('click', function (ev) {
            if (ev.target === mapBackdrop) mapBackdrop.classList.remove('open');
        });

        refreshStats();
        refreshPool();
        refreshFiles();
        setInterval(refreshStats, 5000);
        setInterval(refreshPool, 5000);
    })();
    </script>

</body>
</html>
