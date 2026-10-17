# installer_AGENT.md

## Rol

Agente especialista del módulo `installer`: instalación, despliegue y
configuración del entorno. Decide dónde vive cada cosa, con qué dueño y con
qué permisos, y escribe el marcador que define si el despliegue está aislado.

Es el único módulo con presencia completa y verificada en `zynervoxv2`.

## Contexto principal

1. `src/features/installer/CONTRACT.md` — append-only, con fechas
2. `src/features/installer/README.md`
3. `docs/AUDITORIA_2026-10-17.md` — deriva entre fuente y producción

## Dónde vive el código

```text
installer/install.sh            orquestador
installer/unattended.sh         instalación desatendida v2
installer/isolate-v2.sh         prepara web y base aisladas
installer/platform.sh           resuelve rutas por distribución
installer/isolated-env.sh       rutas y cuentas del entorno aislado
installer/deploy-web.sh         rsync de app/web al docroot
installer/deployment-marker.sh  escribe config/deployment.php
installer/isolated-apache.sh    gate Apache / PHP-FPM
installer/apply-schema.py       aplica migraciones idempotentes
installer/credentials.sh        muestra credenciales existentes
installer/check.sh              verificación posterior
installer/*.sh                  un script por módulo
installer/*.template            plantillas de Apache y PHP-FPM
src/features/installer/tests/   platform, central-database, credentials, rocky-http
```

## Puede modificar

```text
src/features/installer/**
installer/**
docs/DEPLOYMENT.md  (sólo con autorización explícita; ver abajo)
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
app/web/**
src/features/<otro_módulo>/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

`docs/DEPLOYMENT.md` está en la lista de bloqueados del resto de los agentes.
Para este módulo es su documentación natural, pero sigue requiriendo
autorización: escalar antes de tocarlo.

## Promesas vigentes del contrato

1. `--module carriers` ejecuta **sólo** ese script sobre una instalación v2
   aislada existente; cualquier otro nombre o combinación se rechaza. En ese
   modo despliega exactamente tres archivos, no instala paquetes, no despliega
   la web completa y **no recarga Asterisk**.
2. `--dry-run` muestra la selección sin ejecutar nada ni escribir.
3. **Contraseña de admin aislado:** reinstalar sincroniza sólo el hash en
   `v2_zynervox_users` y en el `.env`, sin tocar la tabla legacy, el nivel, el
   estado ni el nombre. Un admin inactivo o de otro nivel **aborta**, nunca se
   eleva automáticamente.
4. **Bot aislado:** `ALTER USER` sólo sobre su cuenta local, rechazando la
   cuenta productiva o una fila central ajena. Sin atomicidad SQL
   transaccional: está declarado.
5. `deployment.php` **se excluye del rsync**.
6. Por defecto no activa módulos opcionales. Migraciones, Farm, WhatsApp,
   Zynerdesk, STT y Zypad **se rechazan en modo aislado**.
7. No copia ni hace `chown` del runtime Asterisk productivo.
8. Apache con mod_php instala un gate `Directory` exclusivo de v2; Rocky usa
   un pool PHP-FPM exclusivo con `auto_prepend_file`. **No se admite omitir el
   gate.**
9. `credentials.sh` requiere root, **lee sin cambiar** y no consulta la base;
   su salida va por stdout sin log persistente.
10. `deploy-web.sh` verifica `rsync` antes de modificar el destino; compara
    tamaño y mtime, conserva timestamps, **sin checksum y sin `--delete`**;
    excluye `runtime/`, `secrets/` y la configuración del espejo de reportes;
    y restaura 0750 en los dos AGI tras copiar.
11. `platform.sh` resuelve por `ID`/`ID_LIKE`: SUSE a `/srv/www/htdocs` y
    grupo `www`; Debian a `/var/www/html` y `www-data`; Rocky a
    `/var/www/html` y `apache`. Las variables explícitas tienen prioridad y un
    sistema desconocido exige `WEB_ROOT`.
12. `unattended.sh` conserva datos y configuración al repetir; `--fresh` se
    rechaza; `--update-source` exige `main` limpio y avance fast-forward.
13. Credenciales aleatorias persistentes **sin `DROP`**; DML por tabla; nunca
    sobrescribe secretos, datos, audios ni grabaciones.
14. Las migraciones requieren **autorización explícita**.

## Qué consumo

`/etc/os-release`, `git`, `rsync`, `realpath`, python3 y cliente MySQL. Los
archivos privados de contraseña con modo 0600 obligatorio.

## Quién me consume

Toda la web, a través del marcador que escribe `deployment-marker.sh`:
`IsolatedGate`, `Database`, `Carriers`, `Dialplans` y `PbxAmi` leen
`Config::deployment('isolated')`.

Y los demás módulos lo citan como su vía de despliegue.

## Reglas específicas del módulo

- **No agregar `--delete` ni checksum a `deploy-web.sh`.** El docroot acumula
  hoy archivos que no existen en la fuente: respaldos, assets retirados y una
  aplicación completa (`modules/admin/stt_providers_app/`) de la que el repo
  **no tiene el código** pero que una página versionada sí carga. `--delete`
  la borraría y rompería esa pantalla.
- **Un archivo de 136 bytes decide el aislamiento.** Mover
  `config/deployment.php` o renombrar la clave `isolated` hace que el gate
  deje de aplicar, que `Database` deje de bloquear la integración VICIdial
  productiva y que `Carriers` cambie de tabla. Los tres a la vez.
- **El aislamiento se decide por el sufijo de la ruta.** `install.sh` activa
  el entorno aislado por el simple hecho de que `WEB_ROOT` termine en
  `/zynervoxv2`. Tocar `platform.sh` para «normalizar» la ruta convierte una
  instalación aislada en compartida **sin ningún aviso**, y rehabilita
  módulos que debía rechazar.
- **Nombres tatuados con otra instancia.** `credentials.sh` e `isolate-v2.sh`
  leen archivos llamados `zynervoxv2205-bot_ivr.conf` y
  `zynervoxv2205-ivr-receiver.conf`, y así están desplegados dentro del
  directorio de `zynervoxv2`. Si alguien los «corrige», el script deja de
  encontrarlos y reporta configuración no disponible **en silencio**, sin
  abortar.
- **Re-ejecutar el `chown -R`/`chmod` masivo** revierte permisos retocados a
  mano y, si cambia el grupo web, PHP deja de leer `/etc/zynervox/*.conf`. La
  degradación es silenciosa.
- `deploy-web.sh` sólo restaura 0750 en los AGI bajo `bot_ivr/`. Cualquier
  ejecutable nuevo fuera de esa ruta queda 0640 y falla al ser invocado por
  Asterisk.
- **Los `init` de los módulos opcionales hacen `DROP DATABASE` con nombre
  fijo.** No se ejecutan en este servidor sin autorización explícita y
  consciente: destruyen datos vivos aunque se pase otro nombre de instancia.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| Qué hace una página, una clase o un endpoint | el especialista del área | «¿Qué archivos hay que desplegar y con qué permisos?» |
| La lista blanca del despliegue aislado | `zynervox_nucleo` | «¿Esta ruta ya está en `IsolatedGate` o hay que darla de alta?» |
| Migraciones de esquema | el especialista dueño de la tabla | «¿La migración ya está numerada en `models/`?» |
| El servicio del discador o del monitor | `zynervox_discador` / `zynervox_panel` | «¿Requiere reinicio del worker tras desplegar?» |

## Debe escalar al ARCHITECT_AGENT si

- el cambio afecta el marcador de despliegue o la decisión de aislamiento;
- necesita ejecutar una migración o un `init` de módulo;
- necesita tocar `docs/DEPLOYMENT.md`;
- necesita modificar otro módulo o un `CONTRACT.md`;
- el cambio puede alterar permisos o propiedad en el docroot.

## Salida esperada

Archivos modificados, cambio realizado, motivo, pruebas ejecutadas, impacto
sobre el contrato, riesgos, y **qué quedó pendiente de autorización**
(reinicios, migraciones, recargas).
