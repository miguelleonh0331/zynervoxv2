# Contrato: installer

## Instalacion selectiva

--module carriers o --module=carriers ejecuta solamente carriers.sh sobre una
instalacion v2 aislada existente. Requiere Core local zynervox_core, cuenta propia
zynervoxv2_core, marcador/configuracion coherentes, PHP/MySQL/rsync preparados.
Crea v2_carriers y permisos DML por tabla sin cambiar passwords, login ni Bot.
Despliega exclusivamente includes/Carriers.php, includes/IsolatedGate.php y
modules/admin/carriers.php. Conserva datos y archivos de configuracion generados.
No instala paquetes, no despliega web completa, no agrega includes ni recarga
Asterisk. Otros nombres/combinaciones opcionales se rechazan. Full v2 incluye
esta preparacion. --dry-run muestra seleccion sin ejecutar scripts o escribir.

## Contraseña de acceso v2

En modo aislado admin también usa el secreto deseado privado por solicitud
explícita. Reinstalar sincroniza solo pass en v2_zynervox_users y admin.env,
sin afectar zynervox_users, nivel, estado o nombre. Admin inactivo/nivel distinto
produce aviso y aborta; no se eleva/reactiva automáticamente. Respaldos privados.

## Reconciliación de contraseña Bot aislado

Por solicitud explícita, cada instalación aislada aplica ALTER USER a su cuenta
Bot localhost/127.0.0.1 usando secreto privado deseado. Rechaza cuenta productiva,
archivo/base no propios o fila central ajena antes del cambio. Sincroniza bootstrap
atómico y contraseña central v2, sin cambiar permisos ni token. Preparación y
recuperación privada preceden ALTER USER; no hay atomicidad SQL transaccional.
Configuraciones no aisladas mantienen el comportamiento de conservar contraseña.

## Despliegue aislado (2026-10-09)

unattended ahora usa /etc/zynervox/zynervoxv2, usuarios zynervoxv2_core y
zynervoxv2_bot_ivr, base zynervox_core y tablas de login/configuración v2_*.
La clave Bot fija se usa solo para crear su nueva cuenta por solicitud explícita;
no rota cuentas existentes ni afecta usuario productivo. Se excluye deployment.php
del rsync. Por defecto no activa módulos opcionales; migraciones, Farm, WhatsApp,
Zynerdesk, STT y Zypad compartidos se rechazan en modo aislado. No copia/chown runtime
Asterisk productivo. Apache mod_php instala gate Directory exclusivo de v2;
Rocky 9 usa un pool PHP-FPM exclusivo con auto_prepend_file administrativo,
socket local y handler restringido al directorio de v2. No admite omitir el gate.
isolate-v2.sh prepara solo web y BD aisladas, sin lanzar llamadas o instalar módulos.

## Reporte de credenciales solicitado

credentials.sh requiere root; lee archivos existentes sin cambiarlos ni consultar
BD. install.sh lo muestra en el resumen final. Salida stdout sensible, sin log
persistente automático. Admin inicial puede haber cambiado; BD bootstrap y token
local no implican que sean la configuración vigente en ivr_deploy_config.

## Despliegue web incremental

deploy-web.sh requiere rsync y verifica su presencia antes de modificar destino.
Compara tamaño y mtime; conserva timestamps y transfiere nuevos/modificados.
No usa checksum: cambios con igual tamaño/fecha no se detectan. No usa --delete:
eliminados/renombrados en fuente permanecen en destino, limpieza explícita aparte.
Excluye runtime/, secrets/ y config/reporting_mirror.json. Los otros archivos
locales sin equivalente fuente permanecen; no protege ediciones locales del código
distribuido. Alcance app/web; otros módulos mantienen sus propios instaladores.

## Rutas multiplataforma

platform.sh usa ID/ID_LIKE: SUSE predetermina /srv/www/htdocs y www;
Debian/Ubuntu /var/www/html y www-data. No inspecciona Apache personalizado.
WEB_ROOT, WEB_DOCUMENT_ROOT, URL_PATH y WEB_GROUP explícitos tienen prioridad.
Rocky/RHEL predetermina /var/www/html y apache. Rocky 9 se valida con paquetes
preparados y --skip-packages; no se promete provision automatica de paquetes.
install/check usan zynervox; unattended usa zynervoxv2. Sistemas desconocidos
requieren WEB_ROOT o WEB_DOCUMENT_ROOT. --skip-packages permite usar dependencias
preinstaladas sin instalar paquetes ni cambiar repositorios del sistema.

## Integración v3 (2026-10-09)

unattended.sh conserva datos/configuración al repetir; --fresh se rechaza.
--update-source exige main limpio y avance fast-forward. --dry-run no escribe.
Con MySQL local disponible crea esquemas propios sin DROP y credenciales aleatorias
persistentes. Instalaciones nuevas comparten base core con usuarios distintos y
permisos DML por tabla para Bot IVR y STT. Configuración antigua se conserva;
JSON legacy sin bootstrap exige migración explícita, no cambio silencioso.
Python3 y cliente MySQL son necesarios para aplicar esquemas idempotentes.


Instala primero la interfaz web de forma repetible sobre Ubuntu. La ausencia de
Asterisk, MySQL o VICIdial produce una instalación parcial utilizable para inspección,
sin cancelar el despliegue de archivos. `check.sh --strict` exige todas las
integraciones, `--dry-run` no escribe y las migraciones requieren autorización
explícita. `--skip-packages` evita cambios al sistema. No incorpora ni sobrescribe
secretos, datos, audios o grabaciones.

## Preparación gTTS para listas (2026-10-10)

macelioai-tts.sh conserva el venv gTTS existente y añade copia del worker propio
list_audio_worker.py bajo ASTERISK_ROOT/modules/bot_ivr. Prepara directorios
bot_ivr/audio_jobs y sounds/cache/ivr_builder/gtts con escritura de grupo web
y setgid (2770). No borra audios, inicia jobs, toca CARSA ni reinicia servicios.

El gestor `installer/database.sh` crea MariaDB 10.11 en Docker, importa únicamente
el esquema versionado, genera credenciales locales y mantiene los datos en un volumen.


## 2026-10-10 - Dialplan administration deployment

carriers.sh also installs standalone Dialplans, sidebar and exact gate entry, creates v2_dialplans with Core DML grants, runs migrate-dialplans.php. Migration uses deployed Core configuration, moves carrier plans transactionally and generates the same runtime file. Repeated migration does not duplicate records. Aborts before clearing routes when more than one prefix or differing existing code prevents a safe migration. Does not reload PBX.


## 2026-10-10 - List Play/Stop AMI worker

list-call-worker.sh installs migration 012, isolated bot account DML grants, private call_jobs directory and Python launcher. ivr-builder-bot-db.sh invokes it for isolated deployments.


## 2026-10-10 - Status eligibility and AGI permission

deploy-web.sh restores 0750 on call_tracking_agi.php and ivr_engine_agi.php after copying web source. Both CLI entrypoints are tracked executable in Git.

## 2026-10-11 - Rocky 9 aislado

El instalador conserva mod_php en Debian/SUSE y añade PHP-FPM exclusivo en
Rocky. HTTP bloquea config/includes/venvs/runtime/secrets, endpoints legacy y
peticiones cuando el gate no se puede leer. Cron de auditoria usa WEB_ROOT;
workers y configuracion PBX usan WEB_GROUP. La primera instalacion prepara el
directorio del launcher. Archivos PBX generados conservan grupo web y modo 0640.
El diagnostico aislado comprueba sus bootstraps propios, sin exigir VICIdial.
rocky-selinux.sh instala una politica versionada con tipos propios para runtime,
configuracion y exactamente dos AGI; mantiene SELinux activo. Asterisk recibe
ACL de rutas v2 si existe, sin pertenecer al grupo Apache global. No inicia
llamadas, crea carriers ni modifica configuracion general de Asterisk.
