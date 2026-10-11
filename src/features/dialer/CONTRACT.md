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

## Pendiente

Capacidad de troncal compartida con VICIdial real: decisión pospuesta
explícitamente por el usuario, el campo `carrier_id` ya existe para
configurarlo cuando se defina.
