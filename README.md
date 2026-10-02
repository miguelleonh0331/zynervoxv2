# Zynervox v2

Paquete portable para instalar la interfaz de call center Zynervox sobre un
servidor Ubuntu que ya dispone de Asterisk/VICIdial, su esquema base completo y
`/etc/astguiclient.conf`. Asterisk y MySQL sin las tablas VICIdial no son suficientes.
Si esas integraciones faltan, el instalador conserva la web y reporta estado parcial.

Incluye los módulos administrativos WhatsApp, Farm y Stt Providers. WhatsApp se
ejecuta en Docker; Farm y Stt Providers se integran en el host y reutilizan el
login principal de Zynervox.

## Desarrollo y fuente de verdad

La copia local oficial de trabajo en Windows está ubicada en:

```text
E:\servidores\zynerdesk\proyectos\zynervoxv2
```

Esta carpeta se utiliza para desarrollo, reconstrucción, pruebas, documentación
y control de versiones. La ruta es una referencia del entorno del propietario;
otros colaboradores pueden clonar el repositorio en otra ubicación.

Todo cambio se prepara y valida localmente antes de publicarse. GitHub es la
fuente de verdad del código distribuido: las instalaciones en servidores parten
de un tag o commit publicado, o de una imagen Docker inmutable fijada por digest.
No se despliega código mediante SFTP, SCP, copias manuales ni edición directa en
el servidor. Después de instalar solo se realizan verificaciones remotas; cualquier
corrección vuelve al flujo local, Git y publicación. Los secretos y datos operativos
permanecen fuera del repositorio.

## Instalación

```bash
sudo ./installer/install.sh --dry-run
sudo ./installer/install.sh --apply-migrations
```

Para una vista LAN sin alias, haga coincidir destino y URL:

```bash
sudo WEB_ROOT=/var/www/html/zynervoxv2-test URL_PATH=/zynervoxv2-test ./installer/install.sh
```

Use `--skip-packages` para copiar y publicar la web sin instalar paquetes ni provocar
reinicios indirectos de servicios existentes.

Variables opcionales: `WEB_ROOT`, `ASTERISK_ROOT` y `URL_PATH`. El instalador no
incluye contraseñas, bases, audios, grabaciones ni datos de producción.

La instalación integral de una sola orden, compatible con Ubuntu y ViciBox/openSUSE,
está documentada en `docs/INSTALL_ONE_COMMAND.md`. Puede instalar Docker cuando se
usa `--install-docker`; nunca reemplaza una ruta web existente. La ejecución
validada en Mirmidon está registrada en
`docs/DEPLOYMENT_MIRMIDON_2026-10-01.md`.

## MariaDB aislada

La base compatible puede ejecutarse sin reemplazar MySQL del host:

```bash
./installer/database.sh init
./installer/database.sh credentials
./installer/database.sh install-config
sudo WEB_ROOT=/var/www/html/zynervox URL_PATH=/zynervox ./installer/install.sh --skip-packages
```

El contenedor escucha solo en `127.0.0.1`, usando el primer puerto libre desde
`3307`. Las contraseñas se generan en
`database/.env`, excluido de Git. El esquema contiene 364 tablas y crea un usuario
administrador nuevo; no incluye datos ni credenciales de Kamatera.

La copia de preparación en Kamatera vive en `/var/www/html/zynervoxv2` y no está
conectada a Apache ni Asterisk. Producción continúa en `/var/www/html/zynervox`.

## WhatsApp omnicanal

Zynerwaba se ejecuta como servicio independiente con MySQL 8.4, sin compartir la
base `asterisk`. La imagen `miguelleonh0331/zynerwabav2:2.1.0-zynervox` se
construye desde el Dockerfile del repositorio y ya contiene los parches; no usa
overrides montados en runtime. Sus secretos se generan localmente en
`whatsapp/.env`, excluido de Git.

```bash
sudo ./installer/whatsapp.sh init
sudo ./installer/whatsapp.sh install-proxy
sudo ./installer/whatsapp.sh credentials
sudo ./installer/whatsapp.sh backup /ruta/whatsapp.sql.gz
```

También puede instalar web y WhatsApp en una sola ejecución:

```bash
sudo WEB_ROOT=/var/www/html/zynervox URL_PATH=/zynervox \
  ./installer/install.sh --skip-packages --with-whatsapp
```

Apache publica Zynerwaba bajo `/zynerwabav2/`; el botón **WhatsApp** de Zynervox
abre esa bandeja. Las sesiones permanecen separadas en esta primera integración.
`remove-proxy` retira la publicación Apache y `down` conserva los volúmenes.

## Zynerdesk (soporte remoto)

Synervox Remoteo se ejecuta como servicio Docker independiente con MySQL 8.4
propio. La imagen `ghcr.io/miguelleonh0331/synervox-remoteo` se descarga publicada,
fijada por digest (nunca por etiqueta flotante); no se construye localmente.
Sus secretos se generan en `zynerdesk/.env`, excluido de Git.

```bash
sudo ./installer/zynerdesk.sh init
sudo ./installer/zynerdesk.sh install-proxy
sudo ./installer/zynerdesk.sh credentials
sudo ./installer/zynerdesk.sh backup /ruta/zynerdesk.sql.gz
```

También puede instalar web y Zynerdesk en una sola ejecución:

```bash
sudo WEB_ROOT=/var/www/html/zynervox URL_PATH=/zynervox \
  ./installer/install.sh --skip-packages --with-zynerdesk
```

Apache publica Zynerdesk bajo su propia subruta (HTTP + WebSocket); el ítem
**Zynerdesk** del sidebar abre esa app con navegación completa, sin iframe.
No hay sesión única con Zynervox en esta primera etapa (login propio del
contenedor). El agente Windows (`synervox-remoteo-agent`) queda pendiente,
fuera de alcance. `remove-proxy` retira la publicación Apache y `down`
conserva los volúmenes.
