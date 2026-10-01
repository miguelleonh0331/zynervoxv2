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
`--install-docker` instala y habilita Docker cuando falta. En openSUSE requiere
`zypper`; en Ubuntu/Debian usa `apt`. Para el despliegue completo y aislado consulte
`INSTALL_ONE_COMMAND.md`.

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
