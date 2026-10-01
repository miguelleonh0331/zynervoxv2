# Instalación integral en un servidor nuevo

## Resultado

El despliegue híbrido instala:

- la web Zynervox y el AGC completo en el host;
- componentes propios de Asterisk en una ruta configurable;
- Zynerwaba y MySQL en contenedores, con imagen, red y volúmenes aislados;
- proxy Apache y secreto SSO generado durante la instalación.
- Farm con servicios systemd, datos y puertos loopback exclusivos;
- Stt Providers con base y usuario MariaDB exclusivos.

No incluye bases, contactos, grabaciones ni credenciales productivas. El AGC lee
la conexión VICIdial desde `/etc/astguiclient.conf` o
`/etc/zynervox/astguiclient.conf`.

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
  bash
```

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

## Retirada segura

Usar `installer/whatsapp.sh down` para detener la prueba conservando volúmenes.
No borrar rutas ni volúmenes hasta respaldar y confirmar que pertenecen al nombre
de proyecto de laboratorio. La prueba no autoriza modificar `/srv/www/htdocs/agc`.
