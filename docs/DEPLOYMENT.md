# Despliegue

## Requisitos

Servidor Ubuntu. Para operación completa requiere Asterisk/VICIdial funcional,
tablas base `vicidial_*`, `phones` y `/etc/astguiclient.conf`. Asterisk/MySQL solos
no satisfacen este requisito. Respaldar MariaDB y `/etc/asterisk` antes de activar.

## Instalación nueva

```bash
git clone REPOSITORY_URL zynervox
cd zynervox
sudo ./installer/install.sh --dry-run
sudo ./installer/install.sh --apply-migrations
```

`--skip-packages` permite desplegar solo los archivos sin alterar paquetes del host.

Sin `--apply-migrations` no se cambia la base. Si falta VICIdial, la web se instala
y el diagnóstico devuelve `PARTIAL`. No crear tablas parciales: autenticación,
campañas, agentes y telefonía dependen del esquema completo y sus datos iniciales.

## Verificación

```bash
sudo WEB_ROOT=/var/www/html/zynervox ./installer/check.sh
sudo WEB_ROOT=/var/www/html/zynervox ./installer/check.sh --strict
curl -I http://127.0.0.1/zynervox/
sudo asterisk -rx 'pjsip show endpoints'
```

Validar login, permisos, IVR, SIP/RTP y servicios existentes. No realizar llamadas
reales como smoke test.

## Datos fuera del repositorio

Respaldar/restaurar por separado MariaDB, `/var/lib/asterisk/sounds`, grabaciones,
certificados, carriers, secretos y configuración particular del servidor.

## MariaDB aislada

Si el host no tiene una MariaDB/VICIdial compatible:

```bash
./installer/database.sh init
./installer/database.sh install-config
```

Se crea `database/.env`, se levanta `zynervox-mariadb` en el primer puerto local
libre desde `3307`, se importa el esquema vacío y se genera un administrador con
contraseña aleatoria.

## WhatsApp

```bash
sudo ./installer/whatsapp.sh init
sudo ./installer/whatsapp.sh install-proxy
sudo ./installer/whatsapp.sh status
sudo ./installer/whatsapp.sh backup /ruta/whatsapp.sql.gz
```

El gestor descarga Zynerwaba `2.0.0` por digest, levanta MySQL 8.4 y selecciona el
primer puerto local libre desde `3022`. Los volúmenes `zynervox_whatsapp_mysql` y
`zynervox_whatsapp_data` no se comparten con MariaDB/VICIdial.

`init` genera `ZYNERVOX_SSO_SECRET`; `install-proxy` instala el mismo valor en
`/etc/zynervox/whatsapp.conf` con acceso limitado a `root:www-data`. Repetir ambos
comandos al actualizar una instalación anterior para activar la sesión única.

Validar `/zynerwabav2/`, login, sesión, empresas, líneas, recepción y envío antes
de promover una versión. `down` retira contenedores y conserva ambos volúmenes.
Usar `remove-proxy` para retirar la ruta Apache sin borrar datos.
