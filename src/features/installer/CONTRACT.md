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
Asterisk productivo. Apache mod_php instala gate Directory exclusivo de v2.
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

El gestor `installer/database.sh` crea MariaDB 10.11 en Docker, importa únicamente
el esquema versionado, genera credenciales locales y mantiene los datos en un volumen.
