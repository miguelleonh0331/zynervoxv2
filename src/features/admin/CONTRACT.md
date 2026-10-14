# Contrato: admin

Carriers aislado requiere nivel 9 y CSRF en todos los POST. Sus datos van a
zynervox_core.v2_carriers, con historial propio sin snapshots de secretos.
Guardar genera archivos inactivos; no implica activacion de troncales en Asterisk.

Gestiona empresas, usuarios, campañas, listas, teléfonos, grupos y carriers mediante
`app/web/modules/admin`. Debe respetar rol y empresa activa, auditar cambios y nunca
exponer secretos de telefonía en respuestas o logs.


## 2026-10-10 - Extraccion de origen

Carriers ofrece prefijos detectados en el dialplan guardado, nombre y boton Extraer prefijo. POST requiere nivel 9 y CSRF; prefijo debe estar en la troncal indicada. Editor aislado mantiene cabecera fija fuera del textarea. Extraer no modifica ni recarga el dialplan.


## 2026-10-10 - Dialplan page

modules/admin/dialplan.php offers independent list/new/edit/delete after Troncales SIP in sidebar. Level 9 and dedicated CSRF required for POST; SQL via Core public Dialplans API. Name, active state and code are editable; fixed zynervoxv2 header excluded from code. Prefix detected automatically, validated server-side and stored on save. Isolated carriers page manages troncales only; existing carrier dialplans migrated before use. No automatic PBX reload or outgoing calls.


## 2026-10-10 - Readonly call template

Dialplan page shows system contexts outside the editable field. User authors only route code; no route created automatically. Common context definitions remain fixed and are generated once.


## 2026-10-10 - Template visibility correction

User clarification supersedes readonly template display: shared system contexts are generated server-side and never displayed in the web form. Route editing unchanged. Architecture review: display-only contract correction approved.

## 2026-10-10 - PBX AMI service
Services/pbx.php requires administrator level 9 and CSRF for test/save. PbxAmi::load/validate/test/save exposes configuration to authorized server callers only. Test authenticates and logs off, never originates a call. Save requires successful login and atomically writes deployment.directory/pbx/ami.json (0640, private directory 2770). Form never renders the secret; blank password preserves it. Credentials are not imported from legacy nor stored in Git.


## 2026-10-10 - Isolated campaign dashboard

Isolated admin login destination remains modules/admin/index.php and now renders the IVR campaign graphic monitor in place, without redirecting to Bot IVR. campaign_monitor.php renders the same read-only view; authenticated levels 7/8/9 can GET campaign_monitor_api.php. Nonisolated dashboard and VICIdial monitor remain unchanged.

## 2026-10-11 — Usuarios aislados (ARCHITECT_AGENT)

users.php selecciona vista interna users_isolated.php antes de cargar Users
legacy. Solo administrador nivel9, POST+CSRF, APIs públicas CoreUsers. Lista
roles existentes sin edición, crea/edita/desactiva únicamente agentes nivel1,
login inmutable, contraseñas jamás devueltas en HTML, nombres escapados. Para
operar: crear anexo SIP con igual login desde Phones, registrar MicroSIP y
asignar desde Campañas. Desactivar cuenta web requiere salir primero de colas;
no cambia configuración SIP ni asignaciones. Sin integración VICIdial habilitada.


## 2026-10-14 - Dashboard campaign Play (architect approved)

The shared monitor places percentage and Terminados below each donut and exposes center Play only to level 9. campaign_monitor_api.php GET remains read-only for levels 7/8/9; POST action=play requires administrator session, dedicated campaign_monitor_csrf, positive campaign_id and origin bot_ivr|agents. Bot campaigns must be active: start each active idle list through existing bot_list_call_start, using stored call_channels. Skip starting/running/stopping runs and report per-list failures, including partial starts. Agents use Dialer::setEngineState(RUNNING). No new repository API or direct SQL. Existing validations, audio readiness, ownership, run leases and claim concurrency continue to apply.


## 2026-10-14 - Dashboard Stop (architect approved)

Monitor POST also accepts action=stop with unchanged role/CSRF/origin/ID validation. Starting/running shows Stop, stopping disables the button. Bot IVR invokes bot_list_call_stop for each starting/running/stopping run with its exact current run_id, including runs on inactive lists/campaigns; finished runs are untouched. No active runs is a successful no-op; per-list errors remain visible. Agents use setEngineState(STOPPED). Neither path terminates ongoing calls.


## 2026-10-14 - Shared campaign monitor cache (architect approved)

Initial dashboard/monitor rendering and authenticated campaign_monitor_api GET read only the shared runtime/campaign_monitor/snapshot.json. Response keeps campaigns and adds updated_at (UTC ISO8601), age_seconds and stale (>15 seconds). No cache or invalid cache yields unavailability; no direct statistics-query fallback. A CLI-only worker holds an exclusive flock, calls existing Bot IVR and Dialer monitor APIs, and atomically renames one complete JSON every five seconds (long cycles never overlap). Any failed source/publication preserves the last good file and restarts via systemd. POST Play/Stop continues to validate current live services and does not rebuild the cache. Runtime/cache directory wwwrun:www0750, snapshot0640; worker/source root-owned.
