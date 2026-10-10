# Contrato: core

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
