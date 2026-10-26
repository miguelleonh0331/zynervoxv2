# Contrato: core

## 2026-10-11 — Logout del panel de agente (ARCHITECT_AGENT)

En modo aislado, una sesión que haya abierto dialer_agent usa logout.php por
POST con dialer_csrf. El entrypoint invoca la API pública Dialer::agentLogout
antes de Auth::logout. Una salida PBX parcial conserva la sesión y muestra
reintento, sin fingir que se retiró de todas las colas. Sidebars admin/sup
renderizan formulario POST para esas sesiones. Sesiones sin panel de agente
y modo legacy conservan su navegación de logout. Auth/Phones/PbxAmi internos
no cambian. El ingreso operativo automático pertenece al panel/módulo dialer.

## 2026-10-11 — Salida SIP para motor humano (ARCHITECT_AGENT)

`Dialplans::outboundRoute(id)` devuelve dialplan_id, name, dial_prefix,
protocol=SIP, endpoint y outbound_prefix de una ruta activa simple. Rechaza
callbacks v2dialer_campaign_, destinos dinámicos, múltiples Dial, sustitución
ambigua y aplicaciones no reconocidas. Extrae únicamente endpoint y prefijo;
el consumidor no ejecuta los hooks IVR presentes en la ruta original.
`sipRoute(row)` publica la misma validación sin consultar la base.
La propiedad y edición del dialplan siguen siendo del core.

## Carriers aislado

Carriers usa getCoreInstance exclusivamente en zynervox_core, tabla v2_carriers.
Audita operacion e ID en v2_access_log, sin configuracion ni secretos. Genera
archivos propios en runtime/modules/asterisk mediante reemplazo atomico por
archivo; no agrega includes ni ejecuta recargas en modo aislado. La generacion
de los dos archivos no constituye una transaccion conjunta. Legacy conserva
su integracion VICIdial. IsolatedGate permite solo la pagina Carriers exacta.

## Aislamiento v2 (2026-10-09)

Deployment local config/deployment.php (sin secretos, generado por instalador)
selecciona /etc/zynervox/zynervoxv2. No carga astguiclient ni JSON legacy en este
modo; sin bootstrap propio falla cerrado. Login usa v2_zynervox_users, sesión
ZYNERVOXV2 y cookie scoped; acceso se audita en v2_access_log. Configuración IVR
usa v2_ivr_deploy_config, sin modificar singleton usado por producción.
Database::getInstance bloquea integración VICIdial compartida. IsolatedGate,
auto_prepend_file del Directory Apache propio, permite solo endpoints v2 soportados.

## Configuración central de despliegue (2026-10-09)

Database::getBotIvrConfig() publica la conexión compartida de campañas e IVR.
Prioridad: ivr_deploy_config; si no tiene host, JSON legacy existente y bootstrap.
Un fallo de la base core no cambia silenciosamente de conexión. DeploymentConfig
valida campos y CSRF. Servicios > Base de datos requiere administrador nivel 9;
la API conserva contraseñas vacías/enmascaradas y nunca devuelve el secreto.


Autenticación, sesión, conexión MariaDB, auditoría y configuración común. Lee la
conexión desde `ZYNERVOX_CONFIG_FILE`, `/etc/zynervox/astguiclient.conf` o, como
compatibilidad, `/etc/astguiclient.conf`. No almacena credenciales en el repositorio.
Los demás módulos consumen clases públicas de `app/web/includes`.


## 2026-10-10 - Origenes de marcacion

Carriers::extractDialOrigin(carrierId,prefix,name) registra/renombra en v2_dial_origins solo prefijos del dialplan persistido. Prefijo unico global; FK carrier con borrado en cascada. Carriers::dialOrigins() publica exclusivamente dial_prefix,name,carrier_id de carriers activos cuya ruta aun exista; no expone configuracion ni secretos. DialplanOrigins analiza rutas principales _<1..20 digitos>X., prioridad 1 y conserva ceros iniciales. Generacion aislada usa cabecera fija unica zynervoxv2 y coloca rutas de todos los carriers antes de contextos auxiliares; sin recarga automatica.


## 2026-10-10 - Standalone dialplans (supersedes carrier-origin UI)

Dialplans::getAll/getById/save/delete/origins use isolated zynervox_core.v2_dialplans. Save validates name, state, one main numeric prefix and shared helper contexts, then generates runtime files without PBX reload. Prefix unique, preserves leading zeros. origins exposes id/name/prefix for active records; Carriers::dialOrigins delegates to it. Historical extractDialOrigin and v2_dial_origins remain compatibility artifacts, no longer used by admin UI. Dialplans survive carrier deletion/inactivation; SIP carrier availability remains a separate call-time concern. Identical helper definitions emitted once; differing definitions under one context rejected.


## 2026-10-10 - Carrier protocol detection

Carriers::protocol(array) derives SIP from type=peer/friend/user, PJSIP from endpoint/aor/auth, ignores comments and rejects mixed blocks. Isolated CRUD persists detected metadata; generation includes active SIP/PJSIP records at the existing compatible path. No changes to PBX includes or automatic reload.


## 2026-10-10 - Explicit protocol choice

Isolated Carriers CRUD accepts protocol SIP/PJSIP explicitly; selection is authoritative and validated against recognized account type. Contradictory/mixed blocks fail before SQL. Omitted protocol retains detection compatibility. File routing unchanged. Architecture approval: local contract amendment within existing core/admin scope.


## 2026-10-10 - Common system contexts

DialplanOrigins::template publishes immutable zynervoxv2-amd/call-finish/outbound then the existing main HEADER. render emits that template once before every authored route. body removes identical historical copies of protected contexts and rejects differing definitions. Other helper contexts retain existing merge validation. Template currently preserves deployed AGI path and AMD/audit behavior.


## 2026-10-10 - IVR answer entrypoints

Common template includes zynervoxv2-bot-ivr (AMD then engine unless MACHINE) and zynervoxv2-bot-ivr-sin-amd (ANSWER event then engine). Both run on the called leg and return GOSUB_RESULT=CONTINUE after engine exit. Caller routes must inherit ZV2_LIST_ID/ZV2_LEAD_ID/ZV2_CALL_ID. Template remains hidden in web; route authoring unchanged.

## 2026-10-10 - PBX AMI service
Services/pbx.php requires administrator level 9 and CSRF for test/save. PbxAmi::load/validate/test/save exposes configuration to authorized server callers only. Test authenticates and logs off, never originates a call. Save requires successful login and atomically writes deployment.directory/pbx/ami.json (0640, private directory 2770). Form never renders the secret; blank password preserves it. Credentials are not imported from legacy nor stored in Git.


## 2026-10-10 - List Play/Stop AMI worker

AmiClient uses private PbxAmi credentials, bounded requests and ActionID correlation. Async Originate targets Local/<prefix><phone>@zynervoxv2/n, inherits list/lead IDs and assigns OtherChannelId to the reserved attempt ID. Credentials are never stored in dial runs or passed on the command line.


## 2026-10-10 - Isolated campaign dashboard

IsolatedGate permits modules/admin/campaign_monitor.php and campaign_monitor_api.php; session and role authorization remain enforced by page/API. No legacy table access enabled.

## 2026-10-11 — Archivos por protocolo de carrier

Carriers::regenerateAndReload en despliegue aislado genera archivos separados:
pjsip-zynervoxv2.conf para PJSIP y sip-zynervoxv2.conf para SIP, según detección
pública protocol. El dialplan conserva todas las troncales activas. No cambia
registros ni active, ni recarga automáticamente al guardar. El servidor debe
incluir cada archivo desde la configuración del driver correspondiente.


## 2026-10-11 ? Entrada administrativa Vpn

IsolatedGate permite ?nicamente modules/vpn/index.php; este m?dulo aplica su
propio control de sesi?n nivel9 y CSRF. Sidebar administrativo agrega Vpn,
preservando items existentes. Integraci?n por Auth/Config p?blicos, sin cambios
al motor PBX/DB ni acceso Apache al socketDocker. Aprobado ARCHITECT_AGENT.

## 2026-10-11 — Entrada del marcador aislado (ARCHITECT_AGENT)

IsolatedGate permite exactamente modules/admin/campaigns.php,
dialer_campaign_edit.php, dialer_list_leads.php, dialer_agent.php y
dialer_report.php (estos cuatro también bajo modules/admin/).
Cada página conserva Auth y POST+CSRF para mutaciones. Grabaciones se consultan
por dialer_report.php?view=recordings; vista interna, worker y AGI siguen
bloqueados por HTTP. Sin permisos wildcard ni acceso legacy.
Se sincronizó runtime con la allowlist versionada, preservando Vpn; no se
modificó dicho módulo. Prueba de integración real:
src/features/dialer/tests/apache-web.php, ejecutada por run-ten-agents.sh.

## 2026-10-11 — Agentes web v2 (ARCHITECT_AGENT)

CoreUsers::getAll/getByUser publica solo user/full_name/user_level/active desde
zynervox_core.v2_zynervox_users; no contraseñas. saveAgent(input,existingUser)
crea nivel 1 explícito o edita exclusivamente nivel 1 con login numérico inmutable,
nombre validado, estado Y/N y contraseña nueva de 8–72 bytes mediante hash.
Contraseña vacía al editar conserva el valor existente; crear exige contraseña.
Auditoría CORE_AGENT_CREATE/UPDATE contiene únicamente login y es transaccional.
Sin migración, borrado de usuarios, teléfonos automáticos ni edición de admins.
Auth admite password_verify para hashes y hash_equals para valores históricos;
login exitoso regenera sesión. En aislado, checkAccess revalida cuenta activa,
rol/nombre contra la BD y falla cerrado si no se puede verificar. Desactivar
revoca acceso web al siguiente request, sin retirar membresías SIP/Queue.
Nivel 1 aislado redirige a modules/admin/dialer_agent.php; legacy conserva
modules/agente/index.php. IsolatedGate habilita únicamente users.php; la vista
users_isolated.php sigue bloqueada por HTTP directo.


## 2026-10-13 - Isolated preanswer detection

Common DialplanOrigins outbound helper invokes preanswer capture AGI, which scopes itself to tracked origin 7307. No-AMD answered helper reuses existing MixMonitor when preanswer monitor exists, avoiding truncation of early media. Generated carrier routes unchanged. Approved by primary ARCHITECT_AGENT review.


## 2026-10-16 - Meet demo entrypoint (architect approved)

IsolatedGate allows exactly modules/admin/meet.php. The page enforces administrator level 9 through Auth; no wildcard permissions or legacy integrations are enabled.


## 2026-10-16 - Zynerdesk isolated entrypoint (architect approved)

IsolatedGate permits exactly modules/admin/zynerdesk.php. The existing page retains Auth::checkAccess(9), fixed view selection and signed short-lived SSO; backend/proxy configuration is unchanged. Service-control routes remain blocked.


## 2026-10-26 — Perfiles de acceso web (ARCHITECT_AGENT)

Reemplaza la cláusula de saveAgent de 2026-10-11. CoreUsers::saveUser(input,existingUser)
administra exclusivamente los niveles 1 agente, 7 GTR y 8 supervisor, declarados en
CoreUsers::levels(); el nivel 9 queda fuera de la web y se sigue dando de alta por SQL.
El nivel llega por entrada y se valida contra esa lista blanca: ausente vale 1 y cualquier
otro valor se rechaza, de modo que un POST no puede fabricar un administrador. Nivel 1
conserva login de 1 a 20 dígitos que debe coincidir con su anexo SIP; niveles 7 y 8 usan
login de 3 a 20 caracteres en minúscula, iniciado por letra, con letras, dígitos, punto,
guion o guion bajo, porque no tienen anexo. Login y nivel son inmutables al editar: editar
exige que el nivel almacenado esté en la lista blanca y coincida con el enviado, así las
cuentas de administrador siguen protegidas y no se alteran privilegios de una cuenta viva.
Nombre validado, estado Y/N, contraseña nueva de 8–72 bytes mediante hash; contraseña vacía
al editar conserva el valor existente y crear exige contraseña. Auditoría
CORE_AGENT_CREATE/UPDATE contiene únicamente el login y es transaccional. Sin migración,
borrado de usuarios ni teléfonos automáticos. En aislado los niveles 7 y 8 redirigen a
modules/admin/campaign_monitor.php, que ya exige 7/8/9 y reserva el control del motor al
nivel 9; modules/sup/ no se agrega a IsolatedGate y su sidebar oculta en aislado los
destinos no permitidos. Prueba: src/features/core/tests/run-users.sh.


## 2026-10-26 — Alcance de campañas por supervisor (ARCHITECT_AGENT)

v2_core_user_campaigns(user,origin,campaign_id) guarda qué campañas del monitor
puede ver una cuenta de nivel 7 u 8; origin vale agents o bot_ivr y campaign_id
apunta a la tabla de ese origen, por eso no lleva clave foránea. Sin filas el
usuario no ve ninguna campaña: el alcance cierra por defecto. Los niveles 1 y 9
no tienen alcance; guardar una cuenta de nivel 1 borra el suyo. La selección
llega en input['campaigns'] como claves origin:campaign_id, se valida contra la
lista de orígenes, se deduplica, se limita a 200 entradas y se escribe en la
misma transacción que la cuenta, de modo que una selección inválida no deja al
usuario a medio guardar. campaign_monitor_cache_read() es la puerta única de
lectura del snapshot y aplica el recorte según la sesión; los niveles distintos
de 7 y 8 y los procesos sin sesión leen el snapshot completo mediante
campaign_monitor_cache_read_all(). Las acciones de arranque y parada del monitor
siguen exigiendo nivel 9. Prueba: src/features/core/tests/run-users.sh.
