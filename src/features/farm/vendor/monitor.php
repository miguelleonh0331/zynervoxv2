<?php require_once __DIR__.'/auth.php'; require_auth(); ?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?=htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')?>" />
    <title>Synervox Control Plane V2</title>
    <link rel="stylesheet" href="monitor/styles.css?v=<?=filemtime(__DIR__.'/monitor/styles.css')?>" />
  </head>
  <body class="farm-native">
    <header class="topbar">
      <div>
        <p class="eyebrow">SYNERV0X · HUMAN SUPERVISION</p>
        <h1>Control Plane V2</h1>
        <p class="subtitle">Flota dinámica basada en archivos de cuentas proxy.</p>
      </div>
      <div class="connection">
        <span id="connection-dot" class="connection-dot"></span>
        <div><strong id="connection-label">Conectando</strong><span id="last-sync">Esperando al orquestador</span></div>
      </div>
    </header>

    <main>
      <section class="summary-grid">
        <article><span>Workers activos</span><strong id="active-count">0</strong><small id="total-count">de 0</small></article>
        <article><span>Procesando ahora</span><strong id="busy-count">0</strong><small>trabajos</small></article>
        <article><span>En pausa</span><strong id="paused-count">0</strong><small>workers</small></article>
        <article><span>Archivos de proxies</span><strong id="proxy-files-count">0</strong><small id="proxy-pool-detail">carpeta vigilada</small></article>
        <article><span>Completados</span><strong id="completed-count">0</strong><small>histórico</small></article>
        <article id="failed-summary" class="summary-action" tabindex="0" role="button" aria-label="Mostrar workers con fallos"><span>Fallidos</span><strong id="failed-count">0</strong><small id="failed-workers">sin workers afectados</small></article>
        <article class="queue-countdown"><span>Audios restantes</span><strong id="remaining-count">—</strong><small id="queue-detail">consultando cola</small></article>
        <article><span>Progreso actual</span><strong id="progress-count">—</strong><small id="queue-total">total del ciclo</small></article>
      </section>

      <section class="fleet-control panel">
        <div>
          <p class="panel-kicker">POLÍTICA DE FLOTA</p>
          <h2>Concurrencia deseada</h2>
          <p>Configura la flota. Ningún worker consultará la cola hasta pulsar “Iniciar motor”.</p>
        </div>
        <div class="target-control">
          <input id="target-range" type="range" min="0" max="10" value="0" />
          <input id="target-number" type="number" min="0" max="10" value="0" aria-label="Workers deseados" />
          <button id="apply-target" class="primary">Aplicar y reiniciar contadores</button>
        </div>
        <div class="fleet-actions">
          <button id="start-engine" class="engine-start">Iniciar motor</button>
          <button id="refresh" class="ghost">Actualizar</button>
          <button id="stop-all" class="danger">Parada inmediata</button>
        </div>
      </section>

      <section class="fleet-control panel">
        <div>
          <p class="panel-kicker">CUENTAS PROXY</p>
          <h2>Subir archivo</h2>
          <p>Reemplaza la carga manual por FTP/SSH. El orquestador recarga la carpeta solo, sin reiniciar nada.</p>
        </div>
        <div class="target-control">
          <input id="proxy-file-input" type="file" accept=".txt,.csv,.json" aria-label="Archivo de cuentas proxy" />
          <button id="proxy-file-upload" class="primary">Subir</button>
        </div>
        <p id="proxy-upload-message" class="subtitle"></p>
      </section>

      <div id="operation-message" class="operation-message" hidden></div>

      <section class="workspace">
        <div>
          <details class="agent-status panel" open>
          <summary class="section-heading">
            <div><h2>Estado de agentes</h2><p>Selecciona un agente para abrir su ficha completa.</p></div>
            <strong id="visible-count" class="visible-count">0 visibles</strong>
          </summary>
          <div class="map-launch">
            <button id="open-world-map" class="ghost">Mapa mundial</button>
            <button id="open-blocked" class="ghost danger-ghost">Proxies con problemas <span id="blocked-badge" class="badge">0</span></button>
          </div>
          <div class="worker-toolbar">
            <input id="worker-search" type="search" placeholder="Buscar worker, IP, proxy o país…" autocomplete="off" />
            <select id="status-filter" aria-label="Filtrar por estado">
              <option value="">Todos los estados</option>
              <option value="busy">Procesando</option>
              <option value="idle">Disponible</option>
              <option value="error">Error</option>
              <option value="degraded">Degradado</option>
              <option value="blocked">Bloqueado</option>
              <option value="failed">Con fallos</option>
              <option value="paused">Pausado</option>
              <option value="stopped">Detenido</option>
              <option value="restarting">Reiniciando</option>
            </select>
          </div>
          <div id="worker-grid" class="worker-grid" aria-live="polite"></div>
          <div class="status-legend"><span><i class="available"></i>Disponible</span><span><i class="processing"></i>Procesando</span><span><i class="failed"></i>Error</span></div>
          </details>
        </div>

        <aside class="activity-panel panel">
          <div class="activity-head">
            <div><p class="panel-kicker">AUDITORÍA</p><h2>Actividad</h2></div>
            <button id="clear-filter" class="text-button">Toda la flota</button>
          </div>
          <p id="event-filter" class="event-filter">Mostrando todos los workers</p>
          <div id="event-list" class="event-list"></div>
        </aside>
      </section>
    </main>

    <div id="worker-modal" class="worker-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modal-worker-title">
        <button class="modal-close" data-close-modal aria-label="Cerrar">&times;</button>
        <div id="worker-detail"></div>
      </section>
    </div>

    <div id="world-map-modal" class="worker-modal world-map-modal" hidden>
      <div class="modal-backdrop" data-close-world-map></div>
      <section class="modal-dialog map-dialog" role="dialog" aria-modal="true" aria-labelledby="world-map-title">
        <button class="modal-close" data-close-world-map aria-label="Cerrar">&times;</button>
        <header class="map-head">
          <div><p class="panel-kicker">DISTRIBUCIÓN GLOBAL</p><h2 id="world-map-title">Actividad por ubicación</h2></div>
          <div class="map-totals"><span><i class="idle-dot"></i><b id="map-idle-count">0</b> disponibles</span><span><i class="busy-dot"></i><b id="map-busy-count">0</b> procesando</span><span><i class="error-dot"></i><b id="map-error-count">0</b> errores</span><span><i class="pending-dot"></i><b id="map-pending-count">0</b> ubicación pendiente</span></div>
        </header>
        <div id="live-world-map" class="live-world-map">
          <img src="monitor/world-map-real.svg" alt="Mapa político mundial">
          <div id="world-map-points" class="world-map-points"></div>
        </div>
      </section>
    </div>

    <div id="blocked-modal" class="worker-modal" hidden>
      <div class="modal-backdrop" data-close-blocked></div>
      <section class="modal-dialog blocked-dialog" role="dialog" aria-modal="true" aria-labelledby="blocked-title">
        <button class="modal-close" data-close-blocked aria-label="Cerrar">&times;</button>
        <header class="map-head">
          <div><p class="panel-kicker">MANTENIMIENTO</p><h2 id="blocked-title">Proxies con problemas</h2></div>
          <button id="retest-all" class="ghost">Reintentar todos</button>
        </header>
        <p class="blocked-hint">Se bloquean solos tras 3 fallos seguidos y quedan fuera de la rotación. Se reintentan solos cada 2 minutos (los que ya conectan bien se reactivan automático); "Probar" y "Reintentar todos" hacen lo mismo al instante, sin esperar.</p>
        <div id="blocked-list" class="blocked-list"></div>
      </section>
    </div>

    <template id="worker-template">
      <article class="worker-card" tabindex="0">
        <div class="card-head">
          <div><span class="worker-number"></span><h3 class="worker-name"></h3></div>
          <span class="status-pill"><i></i><span class="status-text"></span></span>
        </div>
        <div class="route-grid">
          <div><span>Proxy</span><code class="proxy-address"></code></div>
          <div><span>IP pública</span><code class="public-ip">—</code></div>
          <div><span>País</span><code class="country">—</code></div>
          <div><span>PID</span><code class="worker-pid">—</code></div>
          <div><span>Última señal</span><code class="last-activity">—</code></div>
        </div>
        <div class="job-line"><span>Trabajo actual</span><code class="current-job">Ninguno</code></div>
        <div class="metrics">
          <div><strong class="metric-ok">0</strong><span>completados</span></div>
          <div><strong class="metric-fail">0</strong><span>fallidos</span></div>
          <div><strong class="metric-restarts">0</strong><span>reinicios</span></div>
        </div>
        <div class="last-log">Sin actividad registrada.</div>
        <label class="policy-toggle"><input class="auto-restart" type="checkbox" /><span>Reinicio automático</span></label>
        <div class="actions">
          <button data-action="start" class="primary">Iniciar</button>
          <button data-action="pause" class="ghost pause-action">Pausar</button>
          <button data-action="drain" class="ghost">Drenar</button>
          <button data-action="restart" class="ghost">Reiniciar</button>
          <button data-action="rotate-proxy" class="rotate">Cambiar proxy</button>
          <button data-action="stop" class="danger">Detener</button>
        </div>
      </article>
    </template>

    <script src="monitor/web-bridge.js?v=<?=filemtime(__DIR__.'/monitor/web-bridge.js')?>"></script>
    <script src="monitor/renderer.js?v=<?=filemtime(__DIR__.'/monitor/renderer.js')?>"></script>
  </body>
</html>
