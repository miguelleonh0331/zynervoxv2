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
