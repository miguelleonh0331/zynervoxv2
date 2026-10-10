# installer

## Rocky Linux 9 (soundhound)

Instalacion v2 aislada validada con Apache/PHP-FPM 8.3, MariaDB 10.11 y Asterisk
20/PJSIP. Preparar paquetes antes de usar unattended.sh: httpd, php-fpm y las
extensiones documentadas, rsync, Python/PyMySQL/num2words, FFmpeg, SoX, ACL,
checkpolicy y herramientas SELinux. WEB_GROUP=apache; destino /var/www/html/zynervoxv2.
El instalador configura un pool exclusivo, bloquea rutas internas y aplica su
politica SELinux. Asterisk y sus credenciales AMI se preparan aparte en el host.
No requiere VICIdial para la operacion aislada. Ver docs/DEPLOYMENT_SOUNDHOUND.md.
Pruebas HTTP en el servidor de laboratorio: bash src/features/installer/tests/rocky-http.sh.
La prueba retira brevemente el permiso del gate y lo restaura para verificar
fallo cerrado; ejecutarla sin usuarios activos.

## Instalar solo Carriers (v2 ya instalado)

```bash
cd /mnt/zynervoxv2-fuente
sudo env WEB_ROOT=/srv/www/htdocs/zynervoxv2 URL_PATH=/zynervoxv2 WEB_GROUP=www bash installer/unattended.sh --module carriers
```

Solo despliega Carriers y su gate, crea zynervox_core.v2_carriers y permisos
propios. No reinstala login/Bot ni actualiza sus claves. Repetir conserva datos.
Archivos propios en /var/lib/zynervoxv2/asterisk/modules/asterisk:
pjsip-zynervoxv2.conf y extensions-zynervoxv2.conf. No agrega includes ni recarga
Asterisk. La activacion requiere revision explicita de ambos archivos y de sus
contextos/endpoints para evitar colisiones en el Asterisk compartido.
Actualmente el selector soporta carriers; otros nombres fallan sin instalar.


El resumen final muestra credenciales guardadas de admin inicial, BD bootstrap
y receptor IVR. Para consultarlas sin reinstalar: sudo bash installer/credentials.sh.
La contraseña inicial puede no ser vigente; Servicios/IVR pueden tener conexión
o token distintos del bootstrap. Salida sensible: no compartir ni capturar con
tee/logs públicos. No genera, rota ni modifica claves al consultar.

La web usa rsync incremental por tamaño/fecha: primera instalación completa,
siguientes solo nuevos/modificados. Requiere rsync preinstalado (apt-get install
rsync en Debian/Ubuntu; zypper install rsync en SUSE). No se instala automáticamente.
Revisa metadatos de todos los archivos sobre SMB, no el contenido de los intactos.
runtime/, secrets/ y config/reporting_mirror.json están excluidos. No borra archivos
retirados de fuente ni locales exclusivos. Cambios conservando tamaño y fecha
requieren actualizar mtime. No editar código distribuido en destino: puede ser
reemplazado. Los instaladores de módulos opcionales no son incrementales todavía.

Rutas por sistema: SUSE /srv/www/htdocs; Debian/Ubuntu /var/www/html.
El desatendido añade zynervoxv2, instalación directa añade zynervox.
Son defaults; Apache personalizado requiere WEB_ROOT/URL_PATH explícitos.
En mirmidon, desde la fuente compartida montada:

```bash
cd /mnt/zynervoxv2-fuente
sudo env WEB_ROOT=/srv/www/htdocs/zynervoxv2 URL_PATH=/zynervoxv2 WEB_GROUP=www bash installer/unattended.sh
```

Añadir --dry-run para comprobar rutas sin instalar. No reemplaza zynervoxv2205.
El desatendido omite instalación de paquetes por defecto; requiere dependencias
ya preparadas. No equivale a soporte de instalación automática de paquetes SUSE.

Código: `installer/`. Despliega primero la web, intenta instalar dependencias y
activa Asterisk/VICIdial solo cuando están disponibles. El diagnóstico normal acepta
estado parcial; `check.sh --strict` exige la plataforma completa.

`database/compose.yml` y `installer/database.sh` administran la base MariaDB aislada,
su esquema inicial, credenciales, configuración, backup y restauración.

## Campos de discador Bot IVR (2026-10-10)

ivr-builder-bot-db.sh incluye models/005-lead-dialer-fields.sql en su secuencia
de esquemas sobre DB_NAME configurada. A?ade cuatro campos y un ?ndice; no
lanza llamadas. Migraci?n aditiva e idempotente para MariaDB 10.6+.
