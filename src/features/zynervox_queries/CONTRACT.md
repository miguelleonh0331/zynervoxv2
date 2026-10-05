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
impone su política (Bot IVR exige zynervox). No existe fallback a otra configuración.
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
Carga: FOR UPDATE de lista perteneciente a campaña, deduplicación por lista,
inserción por lotes, JSON sin normalización TTS. No sobrescribe contactos existentes.
Si importLeads abre transacción, confirma éxito y revierte error. Si recibe conexión
con transacción existente, no hace commit/rollback: la propiedad sigue en llamador.
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
