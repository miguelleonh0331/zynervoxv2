# Contrato: zynervox_queries

## Responsabilidad contractual

Capa compartida PHP para conexión y repositorios por motor/módulo. Primer consumidor:
administración nueva Bot IVR. Aprobada por ARCHITECT_AGENT el 2026-10-05.

## Entradas públicas

`app/web/zynervox_queries/factory.php` es entrada pública. Namespace ZynervoxQueries:
- `engine(array $config): string`: mysql/mariadb; ausente equivale mysql.
- `connect(array $config): PDO`: engine, server, port (1-65535), database, user, password.
- `botIvrRepository(array $config, ?PDO $connection=null): BotIvrRepository`.
  La conexión opcional permite transacciones compartidas/pruebas, solo driver mysql.

La conexión común no fija nombre de base ni guarda secretos. Cada consumidor
impone su política (Bot IVR consume la configuración pública de core).
Esta capa no selecciona fallback ni almacena configuración.
PDO solo aparece en la frontera de conexión/infraestructura, no en páginas nuevas.

## Salidas públicas

`contracts/BotIvrRepository.php` define:
- campaigns(): array de campañas con todos los campos y lists_count.
- campaign(id): array de campos persistidos de campaña; inexistente produce error.
- createCampaign(name, active): ID entero generado; sin listas ni leads.
- updateCampaign(id, values): void; todos los campos administrativos validados por consumidor.
- lists(campaignId): array de listas con leads_count.
- createList(campaignId, name, active): ID entero; padre existente.
- updateList(campaignId, listId, name, active): void; pertenencia obligatoria.
- list(listId, campaignId): array de campos y campaign_name; pertenencia obligatoria.
- leadCount(listId): entero no negativo; consumidor valida pertenencia antes.
- importLeads(listId, campaignId, parsed): saved/duplicates/rejected/errors.
  parsed contiene rows (phone, customer_name, extra), duplicates, rejected, errors,
  ya validado por parser del consumidor antes de iniciar escritura.

Se preservan nombres/tipos devueltos por PDO MySQL de la implementación previa;
IDs/conteos públicos explícitos son int, filas son arrays asociativos. Esta etapa
no normaliza fechas ni cambia zona horaria/valores existentes.

## Garantías

SQL parametrizado en mysql/bot_ivr; identificadores dinámicos se limitan a lista fija.
MySQL/MariaDB comparten adaptador. Ninguna selección dinámica de archivo desde HTTP.
Carga: rechaza rows vacío antes de escribir; FOR UPDATE de lista perteneciente a
campaña, DELETE únicamente por list_id e INSERT por lotes. Deduplicación dentro
del archivo, JSON sin normalización TTS; reemplaza todos los contactos de esa lista.
Si importLeads abre transacción, confirma éxito y revierte error. Si recibe conexión
con transacción existente, usa savepoint: libera al éxito o revierte al savepoint
ante error, manteniendo la transacción externa. Fallos conservan contactos previos.
No borra tablas ni ejecuta migraciones o llamadas telefónicas.

## Errores posibles

RuntimeException por configuración/motor/inexistencia/pertenencia; PDOException
por errores de conexión/SQL. El consumidor traduce error de conexión antes de mostrar.
Motores desconocidos se rechazan antes de conectar o reemplazar secretos.

## Dependencias permitidas

- PHP 7.4+ y PDO MySQL; esquema administrativo nuevo de Bot IVR según su contrato.

## Dependencias prohibidas

- Implementación interna de Bot IVR, otros módulos, Python MultiBase o workers.
- Credenciales incrustadas, SQL arbitrario recibido por HTTP, migraciones automáticas.

## Extensión

Cada módulo añade contrato y carpeta por motor; cada nuevo motor requiere adapter,
esquema, bloqueos/identidad/transacciones y suite equivalente antes de habilitarse.
SQL Server y PostgreSQL no están implementados ni ofrecidos como opciones.
Cambios contractuales requieren aprobación arquitectónica y registro en DECISIONS/CHANGELOG.


## Extensión de flujo por lista (2026-10-05)

createList y updateList incorporan cuarto/quinto argumento opcional ?int flowId.
createList guarda id_flujo nullable; updateList con null conserva valor existente.
ID no-null debe ser positivo. list()/lists() incluyen id_flujo. Migración 004 es
aditiva y deja existentes NULL, sin FK externa. No verifica existencia/publicación
IVR Builder ni accede al módulo; generación de audio no se implementa en esta extensión.

## Lectura de leads para audio (2026-10-10)

`audioLeads(listId, campaignId): array` comprueba pertenencia de lista/campaña y
devuelve lead_id, list_id, phone, customer_name y extra_json de esa lista, ordenados
por lead_id. Consulta preparada con filtro de campaña y lista. No modifica datos,
no interpreta JSON ni expone consultas genéricas; Bot IVR valida las variables.

## 2026-10-10 - per-list dialing prefix

updateListDialPrefix(listId,campaignId,prefix) updates the scoped list after ownership validation. Prefix allows empty or 1-20 decimal digits; preserves leading zeros. list()/lists() expose dial_prefix.

## 2026-10-10 - call tracking

startCall(listId,phone,callId) atomically finds/creates a list lead and records/counts one attempt. finishCall closes idempotently, storing dial/AMD/hangup details and latest lead status. Existing outer transactions use a savepoint.

## 2026-10-10 - CDR and event audit

recordCallEvent(callId,type,eventId,epoch,data) inserts append-only event once and enriches attempt CDR. Caller uniqueid is call_id; outbound uniqueid stored separately, linked_id correlates legs. Event source timestamps UTC, received_at distinguishes replay. Duration from START to FINISH; billsec ANSWER to FINISH (includes AMD).

Recovery checks open attempts older than five minutes against native PBX
channels. Two-minute absence grace avoids hangup callback races; mark LOST
with RECOVERY event, preserving unknown actual end/duration. A later real
finish can supply authoritative result. No recovery while journals are pending.


## 2026-10-10 - IVR execution context

ivrCallContext(listId,leadId,callId) returns one lead with campaign_id/id_flujo only when list/lead match the open tracked attempt and no IVR_END exists. recordCallEvent also accepts IVR_START, IVR_NODE and IVR_END; existing CDR semantics unchanged.

## 2026-10-10 - IVR business results (architect approved)

saveIvrResult(callId,nodeId,fields): void verifies an open attempt, derives list/lead scope from that attempt, and inserts immutable fields_json/result in zynervox_bot_ivr_results. Same call/node/payload is idempotent; different payload or closed/nonexistent attempt is rejected. Public result mapping receives prepared data only, never arbitrary SQL. No CDR or lead disposition changes. Migration 009 and account DML grants required.

## Call export metadata (2026-10-10)
Attempt rows snapshot campaign_id and flow_id at start. IVR_START persists actual flow_code/name and SHA256 of serialized executed flow. IVR_END stores ivr_result. FINISH journal persists recording_path/status/bytes/finalized_at after MixMonitor closes. Status ready means finalized nonempty file at that time, not continuous disk verification. Files remain on disk; historical names/hashes are left null when absent from original events.

## Latest attained IVR status (2026-10-10)
Lead and attempt status are VARCHAR(80). IVR_NODE action=status updates the attained business stage; saveIvrResult applies RESULTADO. CARSA interesado/interesado_nivel2 map to RESPONDE_F1/F2; named terminal nodes provide final disposition. Generic hangup/fin/final nodes do not create a business state. Finish and recovery preserve an attained state, while dial_status keeps telephony outcome. Updates to lead status only apply when this is its newest attempt.


## 2026-10-10 - List Play/Stop AMI worker

BotIvrRepository exposes createDialRun, dialRun, transitionDialRun, claimDialLead and rejectDialClaim. Migration 012 persists executions and links attempts via dial_run_id. Claims lock run/list/lead, count open list attempts and exclude contacts already attempted in this execution. Unique active_list_id prevents concurrent executions. CALL cannot be a selectable status.


## 2026-10-10 - List statistics

listStatistics(listId,campaignId): array validates list/campaign ownership and returns total/calling/finished/states/errors. Latest attempt determines contact progress; current lead.status supplies distribution. Historical errors include FAIL/UNAV/CONG/LOST, unavailable/congested dial statuses, IVR ERROR and recovery/AMI rejection, ordered newest first, capped at 30. No mutation.


## 2026-10-10 - Isolated campaign dashboard

campaignMonitor(): array aggregates existing campaign/list rows, latest dial run per list and latest attempt per current contact. Returns campaign state and contact counters with scoped list progress/channels/error. No schema, AMI or status mutation. No credential/config_json/phone/recording disclosure.


## 2026-10-10 - Active campaign status summary

campaignMonitor now returns active campaigns only (campaign.active=1), including states grouped from current zynervox_bot_list.status across campaign lists. State summaries never use attempt dial_status. No leads/empty active campaigns yield empty state arrays.


## 2026-10-12 - Campaign deactivation (architect approved)

deactivateCampaign(id): void sets only campaign.active=0 (plus automatic updated_at), preserving all other fields and children. Positive existing campaign required. Rejects starting/running/stopping dial runs and open calls, excluding recovered/lost attempts. Takes only the campaign row lock; activity is read after the lock without reversing producer lock order. Requires its own transaction and rejects an already-active connection transaction to avoid a stale activity snapshot. Repeated deactivation is idempotent when idle. No schema changes, filesystem operations or calls. Existing APIs remain unchanged.


## 2026-10-12 - Catálogo TTS Bot IVR (ARCHITECT_AGENT aprobado)

BotIvrRepository añade ttsProviders(): array de provider_id/name/endpoint ordenado por nombre/ID, y createTtsProvider(name,endpoint): int. Datos en zynervox_bot_tts_providers, esquema aislado de Bot IVR. Consumidor valida nombre/URL/protocolo y exige admin+CSRF; SQL parametrizado y URL duplicada produce RuntimeException sin detalles SQL. No actualizar ni eliminar proveedores.

ivrCallContext añade dial_config_json (nullable), tomado del dial run de la llamada mediante LEFT JOIN run_id/list_id. El consumidor captura proveedor por ejecución y conserva gTTS para runs históricos sin snapshot TTS. Métodos existentes mantienen firmas y campos previos.

## 2026-10-13 - Lectura de última llamada por lead

latestLeadCalls(int listId,int campaignId,int limit=50,int offset=0,?int leadId=null): array verifica lista/campaña y devuelve contactos actuales ordenados por lead_id con el intento de mayor attempt_id de esa misma lista/lead mediante LEFT JOIN. Incluye leads sin intentos; no filtra por disponibilidad de grabación ni recurre a intentos previos. Campos: lead_id/customer_name/phone/status y attempt_id/call_id/started_at/finished_at/dial_status nullable. limit 1..100 y offset no negativo; filtro opcional de lead conserva scope. Solo lectura, sin nueva tabla/índice ni cambios de llamadas. Extensión aprobada mediante revisión ARCHITECT_AGENT del agente principal.

## 2026-10-13 - Filtro de leads con gestión

latestLeadCalls añade bool managedOnly=false como sexto argumento opcional; leadCount añade el mismo argumento como segundo parámetro. true aplica status distinto de NEW antes de paginación/conteo. Defaults preservan firmas compatibles y resultados de otros consumidores. No exige intento ni WAV y no altera el criterio de último intento. Aprobado en revisión ARCHITECT_AGENT del agente principal.


## 2026-10-13 - Filtro de grabaciones por tipificacion

latestLeadCalls agrega septimo argumento opcional ?string status=null: cuando esta presente aplica igualdad parametrizada sobre l.status antes de ORDER/LIMIT/OFFSET. Compatible con managedOnly y filtro de lead. null mantiene comportamiento anterior. Aprobado en revision ARCHITECT_AGENT del agente principal.


## 2026-10-13 - Canales persistentes por lista

updateListCallChannels(listId,campaignId,channels): void valida opciones, bloquea lista perteneciente a campana en callAtomic, rechaza run starting/running/stopping y actualiza solo call_channels. list/lists exponen call_channels via campos existentes. createDialRun verifica canales contra call_channels bajo bloqueo del padre y rechaza configuracion desactualizada. Requiere migracion 014. Aprobado en revision ARCHITECT_AGENT del agente principal.


## 2026-10-13 - Live dialing channels

updateListCallChannels now permits active runs and locks only the scoped list. dialRun and campaignMonitor expose current list.call_channels for active runs; finished runs retain initial channels snapshot. claimDialLead checks current list.call_channels under its existing list lock, including already open calls. No run-lock update from the setting operation, avoiding reversed lock order.


## 2026-10-13 - Additional dialing channel choices

updateListCallChannels and createDialRun accept 30,35,40 in addition to prior channel options. Signatures, list scope, locking and live limits unchanged. Approved by ARCHITECT_AGENT review of primary agent.


## 2026-10-13 - Preanswer tracking contract

preanswerCall(callId) returns scoped origin-7307 attempt including last capture byte cursor. pendingPreanswerCalls(limit) selects recent unanswered tracked captures fairly, including just-closed attempts, excluding done/results/recovery. savePreanswerResult locks attempt, rejects answered/non-7307/recovered, persists one category/text evidence and returns true only for an open call eligible for a guarded AMI hangup; late closed result updates only latest lead. Event types PREANSWER_START/CAPTURE/RESULT/DONE added. finishCall preserves preanswer classification when never answered, with original dial_status/cause unchanged. No new table/database. Approved by primary ARCHITECT_AGENT review.


## 2026-10-16 - Exact phone search (architect approved)

searchLeadsByPhone(int listId,int campaignId,string phone): array validates 1–20 decimal digits and list/campaign ownership. Bound equality on current lead phone, scoped list/campaign join, ordered lead_id, maximum 100 rows. Returns lead_id, phone, customer_name, status and extra_json, including NEW contacts; empty array for no match. Read-only, preserves leading zeros and rejects wildcards.
