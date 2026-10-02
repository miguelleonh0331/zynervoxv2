# Instalación integral en un servidor nuevo

## Requisitos previos

Verificar **antes** de lanzar el comando, porque el instalador se detiene en el
primer módulo que no pueda completar:

| Requisito | Para qué | Si falta |
|---|---|---|
| Apache con `mod_proxy`, `mod_proxy_http`, `mod_proxy_wstunnel` | Publicar WhatsApp y Zynerdesk | El proxy no se habilita |
| PHP con `curl`, `json`, `mbstring`, `mysqli`, `pdo_mysql`, `session`, `xml`, `zip` | Web y vista integrada de Zynerdesk | `check.sh` devuelve `FAIL` |
| Docker + Docker Compose | WhatsApp y Zynerdesk | Ambos módulos fallan |
| **Python 3.10 o superior** | **Farm** | **`farm.sh` aborta la instalación completa** |
| VICIdial/Asterisk y `/etc/astguiclient.conf` | Operación telefónica | La web instala y el diagnóstico queda `PARTIAL` |

> **Aviso de orden de instalación.** `install.sh` ejecuta los módulos en
> secuencia bajo `set -e`: WhatsApp → Farm → Stt Providers → Zynerdesk. Si un
> módulo opcional falla, los siguientes no se instalan. En servidores con
> Python anterior a 3.10, lanzar el instalador **sin** `--with-farm`, o
> Zynerdesk no llegará a instalarse. Es el caso real del laboratorio mirmidon,
> que trae Python 3.6.

## Resultado

El despliegue híbrido instala:

- la web Zynervox y el AGC completo en el host;
- componentes propios de Asterisk en una ruta configurable;
- Zynerwaba y MySQL en contenedores, con imagen, red y volúmenes aislados;
- proxy Apache y secreto SSO generado durante la instalación.
- Farm con servicios systemd, datos y puertos loopback exclusivos;
- Stt Providers con base y usuario MariaDB exclusivos;
- Zynerdesk y su MySQL en contenedores, con imagen fijada por digest, proxy
  propio con WebSocket y la vista integrada en el panel.

No incluye bases, contactos, grabaciones ni credenciales productivas. El AGC lee
la conexión VICIdial desde `/etc/astguiclient.conf` o
`/etc/zynervox/astguiclient.conf`.

## Instalación en un servidor nuevo

Elegir una referencia publicada (`ZYNERVOX_REF`) y un prefijo de instancia
propio. Todos los nombres de ruta, proyecto Compose y servicio derivan de ese
prefijo, de modo que dos instalaciones pueden convivir en el mismo host sin
colisionar.

```bash
INSTANCIA=zynervox            # prefijo de esta instalación
REF=v2.3.0-rc1                # tag publicado a desplegar

curl -fsSL "https://raw.githubusercontent.com/miguelleonh0331/zynervoxv2/${REF}/installer/bootstrap.sh" | \
sudo env \
  ZYNERVOX_REF="$REF" \
  ZYNERVOX_SOURCE_DIR="/opt/${INSTANCIA}-source" \
  WEB_ROOT="/var/www/html/${INSTANCIA}" \
  URL_PATH="/${INSTANCIA}" \
  ASTERISK_ROOT="/etc/asterisk/${INSTANCIA}" \
  WHATSAPP_BASE_PATH_OVERRIDE="/${INSTANCIA}-whatsapp" \
  WHATSAPP_COMPOSE_PROJECT_OVERRIDE="${INSTANCIA}-whatsapp" \
  FARM_INSTANCE_OVERRIDE="${INSTANCIA}-farm" \
  STT_INSTANCE_OVERRIDE="${INSTANCIA}-stt" \
  ZYNERDESK_BASE_PATH_OVERRIDE="/${INSTANCIA}-zynerdesk" \
  ZYNERDESK_COMPOSE_PROJECT_OVERRIDE="${INSTANCIA}-zynerdesk" \
  bash
```

En openSUSE/ViciBox la raíz web suele ser `/srv/www/htdocs/...` en vez de
`/var/www/html/...`. Si el servidor no tiene Python 3.10+, editar la última
línea de `installer/bootstrap.sh` para quitar `--with-farm` (ver aviso de
arriba).

Al terminar, recoger las credenciales generadas y guardarlas fuera del
repositorio:

```bash
sudo /opt/${INSTANCIA}-source/installer/whatsapp.sh credentials
sudo /opt/${INSTANCIA}-source/installer/zynerdesk.sh credentials
```

## Comando de laboratorio mirmidon

Ejecutar como una sola orden sobre una ruta que no exista:

```bash
curl -fsSL https://raw.githubusercontent.com/miguelleonh0331/zynervoxv2/v2.2.0-rc2/installer/bootstrap.sh | \
sudo env \
  ZYNERVOX_REF=v2.2.0-rc2 \
  ZYNERVOX_SOURCE_DIR=/opt/zynervoxv2-deploy-test-source \
  WEB_ROOT=/srv/www/htdocs/zynervoxv2-deploy-test \
  URL_PATH=/zynervoxv2-deploy-test \
  ASTERISK_ROOT=/etc/asterisk/synervox-deploy-test \
  WHATSAPP_BASE_PATH_OVERRIDE=/zynervoxv2-deploy-test-whatsapp \
  WHATSAPP_COMPOSE_PROJECT_OVERRIDE=zynervoxv2-deploy-test \
  FARM_INSTANCE_OVERRIDE=zynervoxv2-deploy-test-farm \
  STT_INSTANCE_OVERRIDE=zynervoxv2-deploy-test-stt \
  ZYNERDESK_BASE_PATH_OVERRIDE=/zynervoxv2-deploy-test-zynerdesk \
  ZYNERDESK_COMPOSE_PROJECT_OVERRIDE=zynervoxv2-deploy-test-zynerdesk \
  bash
```

Zynerdesk se puede omitir quitando la bandera `--with-zynerdesk` de
`installer/bootstrap.sh` (instalación sin Zynerdesk queda soportada).

El bootstrap se detiene si la ruta fuente o la ruta web ya existen. Nunca limpia
ni sobrescribe automáticamente una instalación previa.

Si una ejecución se interrumpe, inspeccionar primero sus rutas y reanudar el mismo
comando agregando `ZYNERVOX_RESUME=1`. La reanudación exige que la fuente sea un
clon Git válido y no elimina datos, volúmenes ni archivos ajenos.

## Artefactos y aislamiento

- Fuente: rama o tag indicado por `ZYNERVOX_REF`.
- Imagen local: `miguelleonh0331/zynerwabav2:2.1.0-zynervox`.
- Base de imagen fijada por digest dentro de `whatsapp/Dockerfile`.
- Proyecto Compose: valor de `WHATSAPP_COMPOSE_PROJECT_OVERRIDE`.
- Puerto: primer puerto loopback libre entre 3022 y 3099.
- Secretos: `whatsapp/.env`, modo privado y excluido de Git.
- Persistencia: volúmenes Compose exclusivos del nombre del proyecto.
- Farm: `/opt/<instancia>`, `/var/lib/<instancia>` y dos unidades systemd.
- Stt Providers: base/usuario propios y configuración protegida en `/etc/zynervox`.
- Zynerdesk: imagen fijada por digest, puerto loopback libre entre 4100 y
  4199, secretos en `zynerdesk/.env` (excluido de Git), configuración pública
  en `/etc/zynervox/zynerdesk.conf`.
- ViciBox/openSUSE: se instala `runc` oficial 1.5.2 bajo un SHA-256 fijado y se
  valida antes de crear contenedores. Docker usa el runtime explícito
  `vicibox-runc`; la configuración anterior queda en
  `/etc/docker/daemon.json.pre-zynervox` para rollback.
  Fuente: `https://github.com/opencontainers/runc/releases/tag/v1.5.2`.

## Gates de aprobación

1. `installer/check.sh` no informa fallos.
2. Login Zynervox y página AGC responden desde la nueva ruta.
3. Smoke WhatsApp aprueba login, SSO, sesión, socket y persistencia.
4. Contenedores se recrean sin perder datos.
5. Rutas, procesos y servicios productivos conservan estado y hashes.
6. El E2E Meta se ejecuta solo con empresa, línea y destinatario de laboratorio.
7. Farm rechaza acceso sin sesión y sus dos servicios responden en loopback.
8. Stt Providers rechaza acceso sin sesión y devuelve un listado válido autenticado.
9. Zynerdesk: contenedores `app`/`db` saludables, ruta pública responde, login
   y WebSocket propios funcionan, `Zynerdesk` visible en el sidebar sin iframe.

## Retirada segura

Usar `installer/whatsapp.sh down` para detener la prueba conservando volúmenes.
No borrar rutas ni volúmenes hasta respaldar y confirmar que pertenecen al nombre
de proyecto de laboratorio. La prueba no autoriza modificar `/srv/www/htdocs/agc`.
