<?php require_once __DIR__.'/auth.php'; require_auth(); ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:">
<meta name="csrf-token" content="<?=htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')?>">
<title>Zypad Pool - anexos</title>
<link rel="stylesheet" href="monitor/styles.css?v=<?=filemtime(__DIR__.'/monitor/styles.css')?>">
<style>
/* Solo lo especifico de esta pagina; la paleta (colores, panel, botones,
   summary-grid, worker-toolbar) viene de monitor/styles.css -- misma
   identidad visual que el panel de proxies. Sin estilos "style=" inline:
   la CSP de esta pagina (style-src 'self') los bloquea igual que un
   <script> inline -- todo va aca o en clases. */
main{max-width:1100px;margin:0 auto;padding:20px;display:grid;gap:16px}
.annex-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px}
.annex-table th{color:var(--muted);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:8px}
.annex-table td{padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:middle}
.annex-table tr:last-child td{border-bottom:none}
.reg-pill{display:inline-block;padding:3px 9px;border-radius:99px;font-size:10.5px;font-weight:700}
.reg-pill.on{background:rgba(67,212,147,.15);color:var(--green)}
.reg-pill.error{background:rgba(255,100,119,.15);color:var(--red)}
.reg-pill.off{background:rgba(148,163,184,.15);color:var(--muted)}
.reg-detail{display:block;margin-top:2px;color:var(--muted);font-size:10px}
.annex-actions{display:flex;gap:6px;flex-wrap:wrap}
.annex-actions button{padding:6px 10px;font-size:11px}
/* .target-control es una grilla fija de 3 columnas pensada para el control
   deslizante de concurrencia del panel de proxies; con formularios de 3-4
   campos rompe el layout. Fila simple propia para estos formularios. */
.row{display:flex;gap:14px;flex-wrap:wrap;align-items:end}
.row label{display:grid;gap:4px;font-size:11px;color:var(--muted)}
/* La paleta activa de este panel es la clara "ZynerVox" (segundo :root de
   styles.css sobreescribe la oscura sin condicion) -- mismos colores que
   usa alli para inputs, no los de la paleta oscura de base. */
.row input{background:#fffdf9;border:1px solid #d8cabd;border-radius:8px;color:#30313d;padding:8px 10px;min-width:150px}
#msg{display:none;padding:10px 14px;border-radius:10px;font-size:12px;margin-bottom:4px}
#msg.ok{display:block;background:rgba(67,212,147,.12);color:var(--green);border:1px solid rgba(67,212,147,.3)}
#msg.err{display:block;background:rgba(255,100,119,.12);color:var(--red);border:1px solid rgba(255,100,119,.3)}
.panel-pad{padding:18px}
.flota-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.flota-head h2{margin:0}
.flota-toolbar{margin-top:14px}
</style>
</head>
<body class="farm-native">
<header class="topbar">
  <div>
    <p class="eyebrow">SYNERV0X · HUMAN SUPERVISION</p>
    <h1>Zypad Pool -- anexos reales</h1>
    <p class="subtitle">Anexos SIP (baresip, un proceso/puerto por anexo). El destino SIP queda deshabilitado hasta configurarlo explícitamente.</p>
  </div>
</header>

<main>
  <div id="msg"></div>

  <section class="summary-grid">
    <article><span>Anexos del pool</span><strong id="sum-total">0</strong><small>creados</small></article>
    <article><span>Encendidos</span><strong id="sum-on">0</strong><small>servicio activo</small></article>
    <article><span>Registrados</span><strong id="sum-reg">0</strong><small>SIP registrado</small></article>
    <article id="sum-err-card" class="summary-action" tabindex="0" role="button" aria-label="Filtrar por error de registro"><span>Con error de registro</span><strong id="sum-err">0</strong><small>ver detalle abajo</small></article>
  </section>

  <section class="fleet-control panel">
    <div>
      <p class="panel-kicker">CREAR ANEXO</p>
      <h2>Alta individual</h2>
    </div>
    <div class="row">
      <label>Anexo (4000-4999)
        <input id="newAgent" inputmode="numeric" maxlength="4" placeholder="4000">
      </label>
      <label>Password SIP
        <input id="newPassword" placeholder="Por defecto = anexo">
      </label>
      <button id="btnCreate" class="primary">Crear (queda apagado)</button>
    </div>
  </section>

  <section class="fleet-control panel">
    <div>
      <p class="panel-kicker">CREAR POR LOTES</p>
      <h2>Alta masiva</h2>
    </div>
    <div class="row">
      <label>Desde
        <input id="rangeFrom" inputmode="numeric" maxlength="4" placeholder="4000">
      </label>
      <label>Hasta
        <input id="rangeTo" inputmode="numeric" maxlength="4" placeholder="4010">
      </label>
      <label>Password (opcional)
        <input id="rangePassword" placeholder="= cada anexo">
      </label>
      <button id="btnCreateRange" class="primary">Crear lote (max 50, quedan apagados)</button>
    </div>
  </section>

  <section class="panel panel-pad">
    <div class="flota-head">
      <div>
        <p class="panel-kicker">FLOTA DE ANEXOS</p>
        <h2>Anexos del pool</h2>
      </div>
      <div class="fleet-actions">
        <button id="btnStartAll" class="engine-start">Iniciar todos</button>
        <button id="btnStopAll" class="danger">Detener todos</button>
        <button id="btnRefresh" class="ghost">Actualizar</button>
      </div>
    </div>
    <div class="worker-toolbar flota-toolbar">
      <input id="annexSearch" type="search" placeholder="Buscar anexo…" autocomplete="off">
      <select id="annexFilter" aria-label="Filtrar por estado">
        <option value="">Todos los estados</option>
        <option value="on">Registrados</option>
        <option value="error">Con error de registro</option>
        <option value="off">Apagados</option>
      </select>
    </div>
    <table class="annex-table">
      <thead><tr><th>Anexo</th><th>Servicio</th><th>Registro SIP</th><th>Acciones</th></tr></thead>
      <tbody id="tbody"><tr><td colspan="4">Cargando...</td></tr></tbody>
    </table>
  </section>
</main>

<script src="annexes.js?v=<?=filemtime(__DIR__.'/annexes.js')?>"></script>
</body>
</html>
