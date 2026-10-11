# Contrato: dialer (motor de marcación con agentes)

## 2026-10-11 — Tipificación de intentos MANUAL sin respuesta (ARCHITECT_AGENT)

Migración015 añade disposition_agent_user como dueño administrativo capturado
al reservar exclusivamente MANUAL. Los pendientes, datos del cliente, bloqueo
de nuevas reservas y autorización de setDisposition utilizan
COALESCE(agent_user,disposition_agent_user). La identidad conversacional real
prevalece. El dueño se conserva aunque cambie el modo de campaña posteriormente.
Sin respuesta se liberan reserva/canal y se conserva resultado de fallo,
agent_user/answered_at/bridged_at/duration_talk/grabación NULL. Ahora el dueño
manual debe tipificar antes de marcar de nuevo; esa espera no fabrica duración
conversacional de wrapup ni muestras AHT. Pausa manual/logout conservan intención.
Reportería conversacional sigue usando agent_user, sin ampliar modos automáticos.

Esta sección sustituye solo la exclusión de tipificación preanswer de la sección
MANUAL anterior. No hay backfill genérico basado en modo actual: recuperación
histórica únicamente por IDs con evidencia de origen MANUAL y respaldo previo.

## 2026-10-11 — Sesión operativa, pausas globales y Colgar (ARCHITECT_AGENT)

`agentLogin(user)` incorpora idempotentemente a las campañas activas asignadas,
incluidas las STOPPED. El panel inicia esa operación mediante POST automático
autenticado con CSRF, nunca desde GET/poll. Quita controles manuales join/leave.
`agentLogout(user)` guarda intención offline antes de retirar colas principales
y personales y cerrar intervalos/pausas. AMI parcial conserva esa intención y
devuelve error reintentable; el worker/closer no reincorporan ni reactivan al
agente offline. Cerrar pestaña no sustituye la acción explícita Cerrar sesión.

Migración013 crea estado operativo global, catálogo y eventos de pausa global
con open_slot UNIQUE por agente. Migración014 concede permisos exclusivamente
a esas tablas al runtime aislado. Catálogo admin9: crear/editar/activar/desactivar,
sin borrar historia. La etiqueta se copia al evento y duración se registra desde
solicitud hasta reanudación/logout; no se suman campañas compartidas.
`agentPauseAll(user,typeId|null)` guarda intención durable antes de AMI.
Prioridad: pausa manual, pendiente de tipificación, reserva, disponibilidad.
Recargar/tipificar preserva la pausa manual. Desactivar un motivo no impide
terminar una pausa existente. Durante un intento reservado se pausan principales
pero se mantiene personal para terminar PROGRESSIVE; al cerrar se aplica pausa
allí. Pausar no cuelga. El trabajo de una llamada abierta conserva intervalo
ACTIVE hasta su cierre, aunque el agente haya solicitado pausa.

La reserva MANUAL/PROGRESSIVE consulta intención offline/pausa bajo lock global
del agente dentro de claimLead; un snapshot WAITING obsoleto no consume lead ni
intento. Conteo de disponibilidad también respeta intención durable. Las pausas
anteriores sin código global conservan su bloqueo al reentrar/cerrar/tipificar.

`agentHangup(user,callId)` admite únicamente llamada abierta de agent_user o
reserved_agent_user autenticado. Busca su ChannelId exacto en canales actuales
de AMI, valida nombre de canal y envía Hangup. No acepta usuario/canal enviados
por navegador ni cierra BD anticipadamente: el handler PBX conserva grabación,
liberación de cupo y tipificación. RATIO/ADAPTIVE antes de asignación no son
llamadas propias de un agente. Acciones HTTP aplican Auth y POST+CSRF.

## 2026-10-11 — MANUAL conecta primero al agente (ARCHITECT_AGENT)

Esta sección sustituye el flujo posanswer por Queue únicamente para MANUAL.
MANUAL origina primero el anexo del agente reservado. Tras su respuesta,
Dial conecta el destino validado al reservar, transmitiendo timbrado y early
media disponibles. MANUAL omite Queue y AMD; PROGRESSIVE, ADAPTIVE y RATIO
conservan su flujo. El panel indica que debe contestarse el softphone y que
MANUAL no utiliza AMD, incluso si la campaña tiene esa opción habilitada.

answered_at representa exclusivamente la respuesta del cliente. El hook U
inicia y verifica MixMonitor después de esa respuesta; opción b graba solo
el puente. Un fallo de tracking o grabación aborta el puente. bridged_at
conserva su precisión preliminar al ejecutar el hook, sin evento CONNECT.
channel_uniqueid conserva el ChannelId del agente durante todo el intento;
no se reemplaza por el identificador de la pierna cliente. El handler de
grabación detiene MixMonitor en esa pierna; el del agente cierra idempotentemente.

Sin respuesta del cliente se libera la reserva y recicla el lead, sin
fabricar conversación, agente atribuido ni wrapup. DIALSTATUS explícito
permite BUSY/NOANSWER/CONGESTION/CHANUNAVAIL; una cancelación sin estado fiable
se registra como HANGUP. Si el agente nunca responde, el reconciliador conserva
NOANSWER solo tras confirmar ausencia del ChannelId. Conversación terminada
conserva COMPLETED, tipificación y wrapup; RECORDING_ERROR/PBX_LOST mantienen
su significado. La reserva global sigue evitando llamadas dobles entre campañas.
Cambiar dial_method exige motor STOPPED y ningún intento abierto bajo lock de
campaña, para conservar el contexto mientras el agente/cliente todavía timbra.

## 2026-10-11 — Selección de salida existente (ARCHITECT_AGENT)

Migración 012 permite carrier_id NULL y añade selected_dialplan_id nullable.
`createCampaignFromDialplan(name,id,active)` y `availableDialplans()` consumen
la API pública Dialplans::outboundRoute; la UI elige una salida SIP existente.
selected_dialplan_id es compartido y distinto de dialplan_id, callback propio
posanswer. El prefijo interno se asigna automáticamente sin reutilizar el
prefijo público. Eliminar campaña conserva su salida compartida.
Las campañas legacy sin selección mantienen la resolución pública de Carriers.
Cambiar salida exige STOPPED y ninguna llamada abierta bajo lock de campaña.
Iniciar valida que la ruta esté activa y sea compatible. Originate usa solo
endpoint/prefijo de salida, preservando AMD, Queue y grabación propios.
La reserva rechaza un snapshot de ruta/configuración obsoleto bajo el mismo
lock, sin consumir intentos, antes de originar con esa configuración.

## Alcance y arquitectura (2026-10-11)

Motor de marcación saliente **con agentes humanos**, separado de `bot_ivr` (que es
sin agente). Convive con el VICIdial real y con bot_ivr; no los reemplaza ni
comparte tablas con ellos.

Toda tabla vive en `zynervox_core`, prefijo `v2_dialer_`, mismo patrón que
`v2_carriers`/`v2_phones`. Ninguna clase de este módulo puede apuntar a la BD
legacy (`Database::getInstance()`); solo `Database::getCoreInstance()` y solo si
`Config::get('CORE_DB_database') === 'zynervox_core'` — si no, se construye de
cero, nunca se asume otra base.

Agente = usuario Zynervox existente (`Includes\Auth`) con fila propia en
`v2_phones` (extension = login). No hay tabla `v2_dialer_agents`: la identidad
vive en el login, la asignación campaña↔agente en `v2_dialer_agent_campaigns`
(la fija el admin, no el agente).

**Sin ARI/Stasis.** Bridging y asignación de llamada a agente usan `Queue()` y
`AMD()` nativos de Asterisk (AMI Originate hacia un contexto
`Answer→AMD→Queue(v2dialer_<campaign_id>)`), no un proceso propio escuchando
eventos de canal. Un agente puede ser miembro de varias colas (varias campañas)
a la vez; Asterisk ya evita que le lleguen dos llamadas simultáneas.

Teléfono de agente: softphone SIP externo (MicroSIP) sobre el anexo de
`v2_phones`, no WebRTC — el panel de agente es un dashboard (login/pausa/
tipificación vía acciones AMI `QueueAdd`/`QueuePause`/`QueueRemove`), no maneja
audio.

Grabación: obligatoria, arranca después del answer (no graba el timbrado).

Sin Redis en el MVP: fases 1-3 funcionan solo con MySQL + eventos/consultas AMI;
se evalúa agregar Redis si el polling se vuelve cuello de botella, no antes.

## Fases

1. Modelo de datos + CRUD admin (este commit: solo esquema).
2. Conectividad: contexto dialplan Answer→AMD→Queue, una llamada suelta.
3. Hopper + originator modo RATIO, reserva atómica de leads.
4. Panel de agente (login/pausa/tipificación vía AMI).
5. AMD real + drop_action + abandono medido desde eventos de cola.
6. Modo ADAPTIVE (histéresis, banda muerta, clamp min/max).
7. Reportería y KPIs.

## Fase 4 (2026-10-11) — Queue + panel de agente

`app_queue.so` estaba deshabilitado en `modules.conf` (`noload`, boilerplate
de stock, no una decisión deliberada contra el motor) y nunca se había usado
en este Asterisk: se habilitó y persiste (comentado el `noload`). Cuenta AMI
`zynervox_watcher_ami` ampliada con permiso `agent` (QueueAdd/Remove/Pause,
además de `call,originate,command` ya otorgados en fases previas).

`Dialer::syncDialplan()`/`syncQueues()` regeneran dialplan (vía
`Includes\Dialplans`, un plan por campaña, nombre fijo
`v2dialer_campaign_<id>`) y `queues-zynervoxv2.conf` (un bloque por campaña
activa, strategy `leastrecent`) en cada create/update/toggle/delete de
campaña, y SIEMPRE disparan el reload correspondiente por AMI — regenerar el
archivo solo (lo que hace `Carriers::regenerateAndReload()` en modo aislado)
no alcanza, hay que recargar explícito o el cambio queda inactivo (mismo
motivo por el que existe el botón "Dialplan reload" en Servicios > PBX).

`amd_action_machine=MESSAGE` queda pendiente (sin audio configurado, cuelga
igual que HANGUP). Panel de agente (`dialer_agent.php`) permite
entrar/pausar/salir de cola y tipificar, pero tipificar depende de que
`v2_dialer_call_log.agent_user`/`ended_at` se llenen — eso es el *closer*
real de Fase 5, todavía no existe.

Verificado por AMI sin agente real conectado: cola creada y recargada,
dialplan activo real (`Queue("...", "v2dialer_N,,,,5")` en log), agente
entra/aparece en `queue show`/pausa/sale, desborda y cuelga a los 5s exactos
sin agente disponible. **No verificado**: que la cola realmente timbre y
bridee a un agente humano — requiere un MicroSIP registrado y en línea, que
solo el usuario puede proveer.

## Fase 5 (2026-10-11) — Closer real, sin listener de eventos AMI

En vez de un proceso separado escuchando eventos AMI (lo que proponía el
manual original), el dialplan generado por `syncDialplan()` llama a
`modules/admin/dialer_call_tracking_agi.php` en cada paso (answered, amd,
closed) — mismo patrón que `bot_ivr/call_tracking_agi.php`, ya probado en
producción. El AGI llama a `Dialer::markAnswered/markAmd/closeCall/
closeMachine`, que reemplazan la aproximación por ventana de tiempo de
`inFlightCount()` (Fase 3) por cierre real.

`closeCall()` determina abandono vs. completado por `${QUEUESTATUS}` (TIMEOUT/
FULL/JOINEMPTY/LEAVEEMPTY/JOINUNAVAIL/LEAVEUNAVAIL → `DROPPED`, agrupados sin
distinguir matices por ahora). El agente que atendió se busca parseando
`/var/log/asterisk/queue_log` (evento `CONNECT`) por `${UNIQUEID}`, no por un
evento AMI en vivo — best-effort, nunca bloquea el cierre si no lo encuentra.
MACHINE (AMD) nunca cuenta como abandono, tal como exige el manual: libera el
lead directo a reciclado de 24h sin tocar `termination_reason=DROPPED`.

**Gotcha real encontrado y corregido:** un AGI nuevo subido por `push.py`
no llega ejecutable (sftp no fija el bit +x en archivos nuevos) — Asterisk
lo ignora con "Permission denied" sin tumbar la llamada, pero el cierre
nunca corre. Cualquier AGI nuevo necesita `chmod 750` explícito tras el
primer deploy.

Verificado end to end en mirmidon (sin agente real): 2 leads marcados,
originados, `AMD()` corrió de verdad (`NOTSURE` por falta de audio real,
tratado como humano según el manual), entraron a `Queue()`, desbordaron a
los 4s exactos (`drop_call_seconds`), `call_log` quedó con `answered_at`,
`amd_result`, `ended_at`, `termination_reason=DROPPED`, y los leads
volvieron a `NEW` con `next_eligible_at` +5 min. No probado con datos
reales: `closeMachine()` (requiere audio real para que AMD diga MACHINE) y
la correlación de agente vía `queue_log` CONNECT (requiere un agente real
conectado, mismo pendiente de Fase 4).

## Fase 6 (2026-10-11) — Modo ADAPTIVE

`Dialer::recalculateDialLevel()` implementa el algoritmo del manual §8.2 tal
cual: histéresis (sube solo si abandono < objetivo*0.8), banda muerta (no
toca nada entre el 80% y el 100% del objetivo), clamp a
`adaptive_min_level`/`adaptive_max_level`, no mueve nada sin
`adaptive_min_sample` del día. Estadística diaria por `CURDATE()` sobre
`v2_dialer_call_log` (equivalente MySQL del `stats:{YYYYMMDD}` de Redis del
diseño original).

`Dialer::availableAgentsCount()` cuenta agentes parseando `queue show
<name>` (sin evento AMI en vivo, mismo compromiso que Fase 4/5). El
originator (`dialer_originator_worker.php`) en modo ADAPTIVE recalcula nivel
y agentes cada tick y usa `min(max_concurrent_calls, agentes × nivel)` como
tope efectivo — el tope duro de la campaña manda siempre, igual que exige el
manual. RATIO/MANUAL no cambiaron (siguen con el tope fijo de Fase 3).

Verificado con datos sintéticos (inserciones directas a `call_log`, sin
gastar llamadas reales): sube, baja, banda muerta exacta en el objetivo,
clamp en ambos extremos — los 6 casos pasaron. Conteo de agentes verificado
con la extensión real 10000: `LOGGED_IN` cuenta igual pausado o no,
`WAITING`/`NON_PAUSED` respetan pausa; sin softphone conectado el estado real
es "Unavailable" (no "Not in use"), así que `WAITING` da 0 — correcto dado el
estado real del dispositivo, no un bug de parseo.

## Pendiente

Capacidad de troncal compartida con VICIdial real: decisión pospuesta
explícitamente por el usuario, el campo `carrier_id` ya existe para
configurarlo cuando se defina.

## Corrección de continuidad (2026-10-11, aprobada ARCHITECT_AGENT)

La auditoría de Fase 7 detectó que el origen Local previo podía ejecutar
Answer/AMD/Queue sin llamar al cliente. Esa prueba no demostraba salida real.
El módulo origina ahora directamente el endpoint SIP/PJSIP de la troncal
mediante AMI rawAction Originate, con Context/Exten/Priority posanswer,
Timeout y ChannelId correlacionado. No modifica PbxAmi ni la telefonía de bots.

Migración 008 añade outbound_prefix, drop_message y amd_message.
dial_prefix conserva el prefijo del callback generado; outbound_prefix es el
prefijo del número requerido por la troncal. NULL bloquea salida hasta una
configuración explícita; cadena vacía autoriza no agregar prefijo. Resolver
endpoint solo desde la configuración pública de Carriers y rechazar ambigüedad.

El callback inicia MixMonitor después de respuesta real, antes de AMD/Queue,
y guarda su recording_path. Hangup handler propio detiene grabación y cierra
idempotentemente, sin convertir MACHINE en abandono al ejecutar dos cierres.
Las conversaciones abiertas cuentan hasta ended_at, sin ventana temporal.
La reserva serializa por campaña y comprueba capacidad, actividad, máximo de
intentos, DNC y horario local del lead (gmt_offset) dentro de la transacción.
RATIO/ADAPTIVE respetan agentes × nivel y el máximo duro; MANUAL solo se inicia
por un agente asignado, con anexo disponible y CSRF desde el panel.

El worker reconcilia intentos sin answer tras timeout + 5 segundos únicamente
cuando la consulta de canales confirma que el ChannelId ya no existe.
NOANSWER significa sin respuesta registrada al reconciliar, sin atribuir
BUSY ni una causa SIP. Una respuesta AMI perdida conserva el cupo hasta esa
comprobación; nunca libera una conversación contestada por tiempo transcurrido.
El worker CLI es acotado (--ticks/--wait), no un servicio persistente todavía.

MESSAGE requiere un audio relativo, legible y existente bajo sounds, sin
extensión, enlaces ni escapes. HANGUP no necesita audio. La reportería muestra
ocupación N/D hasta instrumentar tiempo logueado; duración histórica desconocida
no se reemplaza por cero. La validación final de voz/bridge/troncal requiere un
agente MicroSIP y un destino de laboratorio autorizado.

## Cierre operativo ampliado (2026-10-11, aprobado ARCHITECT_AGENT)

Esta sección sustituye los pendientes y límites operativos históricos anteriores.
MANUAL origina por decisión del agente asignado; PROGRESSIVE reserva un agente
WAITING por intento, nivel efectivo 1; ADAPTIVE es el predictivo por abandono;
RATIO conserva nivel fijo. Cola personal y agent_slot UNIQUE evitan reservas
manuales/progresivas dobles entre campañas. Mientras hay reserva se pausa al
agente en colas principales con DIALER_RESERVED, conservando su cola personal.
Solo se revierte esa pausa automática; nunca una pausa manual.

Migraciones 009–011 añaden estado RUNNING/STOPPED (default STOPPED), heartbeat,
error visible, reserva de agente, intervalos observados y cadencia adaptativa.
Una unidad systemd única supervisa el worker PHP; flock rechaza duplicados.
El panel modifica BD mediante POST+CSRF y no ejecuta shell ni systemctl.
La reserva relee estado RUNNING bajo lock. Detener no cuelga conversaciones.
La conciliación sigue activa estando detenido y recupera NOANSWER/PBX_LOST
solo después de confirmar que no existe su ChannelId; PBX_LOST no fabrica AHT.

Queue usa MemberName=interfaz para correlación CONNECT verificable. Pausa
WRAPUP automática en todas las campañas al terminar conversación. Tipificación
reanuda solo esa pausa cuando no quedan pendientes; pausa manual se conserva.
Reentrar no pausa una llamada que aún timbra. Retirar asignación quita membresía
principal y personal idempotentemente. Disposición retryable recicla el lead
con su demora, sin alterar intentos posteriores de ese lead.

Los intervalos ACTIVE/PAUSED/WRAPUP se actualizan tras AMI confirmado. El worker
reconcilia membresía; huecos mayores de 15 s cierran en última observación +5 s
para no contar indisponibilidad como login. Ocupación por campaña = conversación
más wrapup observado / tiempo ACTIVE+WRAPUP observado, recortado al periodo y
sin duplicar intervalos del agente. Ausencia de sesiones o hueco relevante en
el trabajo de cualquier agente produce cobertura parcial y ocupación N/D.
Wrapup usa intervalos reales; no se infiere todo el tiempo hasta una tipificación
tardía ni se trunca trabajo observado arbitrariamente. No hay reporte agregado
multicampaña; sus denominadores no deben sumarse si comparten agentes.

Validación autónoma: clientes SIP/RTP en loopback, troncal y agente exclusivos,
HUMAN/MACHINE reales, bridge CONNECT, MixMonitor, tipificación, cupos entre
campañas, inicio/parada, MESSAGE y supervisión. La equivalencia de protocolo no
certifica un softphone físico ni proveedor/NAT externo específico.

## Alcance de protocolo decidido por el usuario (2026-10-11)

La operación y validación actual usan solo SIP. Se conserva compatibilidad
del formato PJSIP existente, pero no se activa su configuración del core.
La configuración raíz PJSIP quedó restaurada a su copia original. La siguiente
validación externa utilizará números proporcionados por el usuario, una campaña
separada y concurrencia máxima 1 para comprobar troncal y softphone.

## 2026-10-11 — Ficha en vivo y búsqueda de grabaciones (ARCHITECT_AGENT)

`agentPanelState(user)` añade `lead`, obtenido por `agentCurrentLead(user)`:
únicamente su llamada abierta (agent_user/reserved_agent_user) o su última
llamada cerrada pendiente de tipificar, con nombre y custom_fields decodificados
en variables. El panel renderiza valores mediante textContent, sin HTML.

`markBridged(callId, channel)` se ejecuta por AGI CLI desde el Gosub de Queue
(argumento 8, en canal del agente). Resuelve extensión/protocolo por Phones
público, valida llamada abierta contestada, asignación y reserva, y conserva
la identidad/fecha ya registradas bajo lock. Se ejecuta antes del puente:
bridged_at es preliminar; CONNECT tiene prioridad al cerrar. El closer conserva
el callback cuando queue_log no está disponible, sin ampliar permisos del log.

`recordings(campaignId,since,until,phone,agent,beforeId)` devuelve llamadas
cerradas con grabación, filtros preparados, cien filas y cursor por call_id.
No devuelve recording_path. `recording(callId)` es una API de servidor: devuelve
file/size únicamente para un WAV cerrado, regular, legible, >=44 bytes, sin
symlink, realpath igual a RECORDING_DIRECTORY/call-ID.wav.

HTTP usa exclusivamente dialer_report.php?view=recordings, Auth 7/8/9.
La vista dialer_recordings.php se incluye internamente; su acceso HTTP directo
queda bloqueado. Audio por ID con Range único, HEAD, 206/416, no-store y sesión
liberada antes de streaming; no se admiten rutas proporcionadas por clientes.
Sin migraciones: columnas existentes. Aprobado cruce puntual core/dialer para
allowlist exacta, conservando las entradas previamente versionadas.

## 2026-10-11 — Eliminación definitiva de campañas (ARCHITECT_AGENT)

`deleteCampaign(id)` admite historial cerrado. Bajo lock de la campaña, rechaza
RUNNING y cualquier llamada abierta; borra en una transacción call_log, leads
de sus listas, listas, disposiciones, asignaciones, intervalos, DNC propias y
campaña. DNC globales/ajenas intactas: no convertir DNC propias a NULL porque
eso ampliaría su alcance a todas las campañas. WAV físicos conservados; al
borrar call_log dejan de ser consultables en la búsqueda web.

La interfaz exige POST+CSRF y confirm_delete=1 con confirmación explícita que
identifica ID/nombre y datos afectados. No borra campañas al desplegar la mejora.
Captura interfaces antes de quitar asignaciones; elimina solo membresías de
la cola principal/personal de esa campaña, publica el dialplan mediante API
pública con reload explícito y regenera colas. Limpieza PBX posterior al commit:
si falla, audita CAMPAIGN_DELETE_PBX_PENDING y avisa que la campaña ya fue
eliminada, sin fingir rollback de BD. SQL fallido revierte todos sus borrados.
Sin migraciones; dependencias existentes. Aprobado ARCHITECT_AGENT.
