# Contrato: dialer (motor de marcación con agentes)

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

## Pendiente

Capacidad de troncal compartida con VICIdial real: decisión pospuesta
explícitamente por el usuario, el campo `carrier_id` ya existe para
configurarlo cuando se defina.
