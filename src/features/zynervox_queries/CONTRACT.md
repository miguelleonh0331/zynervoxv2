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
