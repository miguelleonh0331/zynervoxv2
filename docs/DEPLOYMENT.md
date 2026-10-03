# Despliegue

## Política de origen y publicación

- Preparar, documentar y probar cada cambio en una copia local controlada por Git.
- Publicar los cambios aprobados en GitHub antes de instalar en un servidor.
- Instalar desde un tag o commit de GitHub, o desde una imagen Docker inmutable
  fijada por digest.
- No transferir código mediante SFTP, SCP ni copias manuales, y no editarlo
  directamente en el servidor.
- Limitar la intervención posterior al despliegue a verificaciones de versión,
  salud y funcionamiento. Toda corrección debe volver al flujo local y publicarse.
- Mantener secretos, credenciales y datos operativos fuera de Git; los instaladores
  los generan o los reciben como configuración externa.

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
`--install-docker` instala y habilita Docker cuando falta — lo sigue
requiriendo `--with-whatsapp` en esta rama; `--with-farm`, `--with-stt-providers`
y `--with-zynerdesk` son nativos (`systemd` + MySQL del host, ADR-0016) y no lo
necesitan. En openSUSE requiere `zypper`; en Ubuntu/Debian usa `apt`. Para el
despliegue completo y aislado consulte `INSTALL_ONE_COMMAND.md`.

Para instalar los tres módulos administrativos junto con la web:

```bash
sudo ./installer/install.sh --skip-packages --install-docker \
  --with-whatsapp --with-farm --with-stt-providers --with-zynerdesk
```

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

El gestor construye la imagen integrada sobre Zynerwaba `2.0.0` fijada por digest,
levanta MySQL 8.4 y selecciona el primer puerto local libre desde `3022`. Los
volúmenes se prefijan con `COMPOSE_PROJECT_NAME` y no se comparten con
MariaDB/VICIdial ni con otra instalación.

`init` genera `ZYNERVOX_SSO_SECRET`; `install-proxy` instala el mismo valor en
`/etc/zynervox/whatsapp.conf` con acceso limitado a `root:www-data`. Repetir ambos
comandos al actualizar una instalación anterior para activar la sesión única.
El proxy usa `ProxyPass`/`ProxyPassReverse` y no exige `mod_headers`; `BASE_PATH`
se entrega directamente al contenedor.

Validar `/zynerwabav2/`, login, sesión, empresas, líneas, recepción y envío antes
de promover una versión. `down` retira contenedores y conserva ambos volúmenes.
Usar `remove-proxy` para retirar la ruta Apache sin borrar datos.

### Actualización segura de WhatsApp

1. Trabajar en una rama y mantener `main` desplegable.
2. Ejecutar `backup` y guardar el commit actual y el digest de la imagen activa.
3. Construir la nueva imagen con una etiqueta inmutable; nunca reutilizar una
   etiqueta publicada. Publicarla cuando exista autenticación segura al registro.
4. Actualizar etiqueta y digest juntos en `whatsapp/compose.yml`.
5. Desplegar primero en un servidor de laboratorio aislado.
6. Ejecutar `smoke.sh`, validar roles en navegador y completar `META_E2E.md` cuando
   existan credenciales Meta de prueba.
7. Etiquetar Git y Docker con la misma versión solo después de aprobar los gates.

### Rollback y recuperación

- No borrar volúmenes durante una actualización o rollback.
- Restaurar el commit anterior con `git revert` o una nueva rama basada en el tag
  estable; no reescribir el historial compartido.
- Restaurar en `whatsapp/compose.yml` la etiqueta y el digest anteriores, ejecutar
  `installer/whatsapp.sh up` y repetir el smoke test.
- Si existe corrupción o migración incompatible, restaurar el respaldo con
  `installer/whatsapp.sh restore /ruta/whatsapp.sql.gz` antes de habilitar tráfico.
- Reiniciar únicamente el stack WhatsApp. Los servicios y carpetas productivos
  ajenos a esta integración quedan fuera del procedimiento.

### Gate de publicación

La versión no se promueve si falla el smoke test, el aislamiento multiempresa, el
inicio de sesión por SSO o el rollback ensayado. El E2E Meta puede quedar pendiente
solo en versiones de laboratorio; una versión declarada operativa exige completar
`src/features/whatsapp/tests/META_E2E.md`.

## Farm

`installer/farm.sh` instala el snapshot versionado de `anexos-proxys`, genera una
instancia systemd propia y elige puertos loopback libres. Los tokens, anexos,
proxies y auditoría viven fuera del repositorio. `ZYPAD_ASTERISK_HOST` y
`TTS_API_URL` quedan vacíos: el despliegue no contacta producción por defecto.

Variables principales: `FARM_INSTANCE`, `FARM_PYTHON`, `FARM_ANNEX_PORT` y
`FARM_CONTROL_PORT`. Python debe ser 3.10 o superior. Verificar ambos servicios,
sus endpoints locales y la ruta autenticada `modules/admin/farm.php`.

## Stt Providers

`installer/stt-providers.sh` crea una base y usuario MariaDB exclusivos, aplica
las cuatro tablas del módulo y genera su configuración fuera de Git. La UI y API
exigen una sesión Zynervox nivel 9. Variables principales: `STT_INSTANCE`,
`STT_DB_NAME` y `STT_DB_USER`.

No introducir API keys reales en pruebas de instalación. Validar listado vacío,
CSRF, rechazo sin sesión y persistencia con datos sintéticos.

## Zynerdesk

Servicio nativo desde ADR-0016 (ver `docs/DECISIONS.md`): sin Docker ni
Compose, proceso Node administrado por `systemd`, BD en el MySQL del host.
Requiere `node` (≥18), `npm`, `mysql` y `openssl` instalados en el servidor —
el instalador falla con un mensaje claro si falta alguno.

```bash
sudo ./installer/zynerdesk.sh init
sudo ./installer/zynerdesk.sh install-proxy
sudo ./installer/zynerdesk.sh status
sudo ./installer/zynerdesk.sh backup /ruta/zynerdesk.sql.gz
```

`init` vendoriza `src/features/zynerdesk/vendor/` en `/opt/zynervox-zynerdesk`,
ejecuta `npm ci`, crea la base `syner_remoteo` y su usuario propio en el MySQL
nativo (nunca comparte motor con `asterisk` ni otros módulos), y escanea el
primer puerto loopback libre entre `4100` y `4199`. El entrypoint real es
`scripts/start.js`: aplica las migraciones pendientes, crea/actualiza el admin
inicial (mínimo 12 caracteres) y recién entonces levanta el servidor — todo en
un solo proceso idempotente en cada arranque del servicio. La configuración
vive en `/etc/zynervox/zynervox-zynerdesk.env` (`0600 root`).

`install-proxy` publica `/etc/zynervox/zynerdesk.conf` (ruta pública) con
permisos `root:www-data 0640` y habilita `mod_proxy_wstunnel` para el
WebSocket (`/ws`). El SSO con Zynervox intercambia una sesión administrativa
Zynervox por un token HMAC efímero (`ZYNERVOX_SSO_SECRET`, generado en
`init`); ver `src/features/zynerdesk/README.md` para el detalle. El login
directo de Zynerdesk sigue existiendo como acceso de recuperación.

`down` detiene el servicio y conserva la base y los datos. Usar `remove-proxy`
para retirar la ruta Apache sin borrar datos.

### Rollback Zynerdesk

- No borrar la base `syner_remoteo` durante una actualización.
- Restaurar el commit anterior con `git revert` o una nueva rama basada en el
  tag estable; no reescribir el historial compartido. Volver a correr `init`
  para reinstalar `vendor/` desde ese commit.
- Si una migración resulta incompatible, restaurar con
  `installer/zynerdesk.sh restore /ruta/zynerdesk.sql.gz` antes de habilitar
  tráfico.
- Reiniciar únicamente `zynervox-zynerdesk.service`; no afecta WhatsApp, Farm,
  Stt Providers ni VICIdial.
