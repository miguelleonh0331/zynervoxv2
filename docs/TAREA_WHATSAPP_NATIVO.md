# TAREA: Migrar WhatsApp (Zynerwaba) de Docker a despliegue nativo

- Fecha de emisión: 2026-10-03
- Emisor: claude-milu
- Destinatario: worker asignado al módulo `whatsapp`
- Agente de referencia obligatorio: `agents/whatsapp_AGENT.md`
- Estado: PENDIENTE DE EJECUCIÓN

---

## 0. Mantra operativo (leer antes de tocar nada)

1. **Toda modificación se trabaja sobre la copia local
   `E:\servidores\zynerdesk\proyectos\zynervoxv2`.** Esa carpeta es la fuente.
2. **Antes de empezar**, sincronizar esa carpeta:
   ```bash
   cd "E:\servidores\zynerdesk\proyectos\zynervoxv2"
   git fetch origin
   git status --short --branch     # debe decir: ## main...origin/main (sin pendientes)
   git pull --ff-only origin main  # si está detrás
   ```
   Si hay cambios locales sin commitear, **detenerse y reportar**. No continuar.
3. **Nunca editar directamente** el clon de WSL (`/home/mleon/proyectos/...`) ni el
   webroot (`/var/www/html/...`). Esos son destinos de despliegue, no fuentes.
   El flujo es: editar en Windows → commit → push → clonar/instalar en WSL.
4. **GitHub es la fuente de verdad.** Nada se despliega por SFTP, SCP ni copia manual.
5. **No tocar producción (`mirmidon`).** Esta tarea es exclusivamente local/WSL.
6. **No ejecutar cambios sin autorización explícita** del usuario (`procede`).

---

## 1. Objetivo

Eliminar la dependencia de Docker para el módulo WhatsApp y dejarlo corriendo de
forma **nativa**, con el mismo patrón que ya usan `farm` y `stt_providers`:

- Código vendorizado dentro del repo `zynervoxv2`.
- Proceso Node.js administrado por **systemd** (como `farm` hace con sus unidades).
- Base de datos en el **MySQL anfitrión** (nativo), no en un contenedor.
- Instalador propio (`installer/whatsapp.sh` reescrito) sin Docker ni Compose.
- Proxy Apache hacia `127.0.0.1:<puerto>` (esto **no cambia**, ya funciona así).

### Resultado esperado

Tras `installer/install.sh --with-whatsapp`, el sistema debe quedar:
- **0 contenedores Docker.** `docker ps` vacío.
- 1 servicio systemd activo (ej. `zynervox-whatsapp.service`).
- 1 base de datos `zynerwabav2` en el MySQL nativo del host.
- `http://localhost/zynerwabav2/` respondiendo HTTP 200.
- `modules/admin/whatsapp.php` con el SSO funcionando igual que hoy.

---

## 2. Estado actual (verificado el 2026-10-03)

### Lo que existe hoy

| Componente | Ubicación actual | Observación |
|---|---|---|
| Imagen Docker | `miguelleonh0331/zynerwabav2:2.1.0-zynervox` | 355 MB. Construida sobre `2.0.0` fijada por digest. |
| Código fuente de la app | **Solo dentro de la imagen Docker** | No existe en ningún repo. Este es el problema central. |
| Overrides | `whatsapp/overrides/` (4 archivos) | Parches aplicados sobre la imagen base vía `Dockerfile`. |
| Esquema SQL | `whatsapp/init/001-schema.sql` | 997 líneas, ~63 tablas, `CREATE TABLE IF NOT EXISTS`. Ya versionado y saneado. |
| Orquestación | `whatsapp/compose.yml` | Servicios `app` + `db` (mysql:8.4) + 2 volúmenes. **Se elimina.** |
| Instalador | `installer/whatsapp.sh` | Basado en `docker compose`. **Se reescribe.** |
| Integración PHP | `app/web/modules/admin/whatsapp.php` | SSO HMAC-SHA256. **No se toca** (ver §6). |
| Proxy Apache | `installer/apache-whatsapp.conf.template` | `ProxyPass /zynerwabav2/ → 127.0.0.1:3022`. **No se toca.** |

### Análisis de viabilidad (ya realizado — no repetir)

Se extrajo `/app` de la imagen con `docker cp` y se inspeccionó:

```
/app
├── server.js            (9.2 KB — entrypoint Express/Socket.IO)
├── package.json         (deps: express, mysql2, socket.io, bcryptjs, multer,
│                         express-session, read-excel-file)
├── package-lock.json
├── src/
│   ├── shared/          (config.js, db.js, crypto.js, sessionStore.js,
│   │                     moduleContext.js)
│   └── features/        (auth, empresas, whatsapp, crm, conversaciones,
│                         broadcasts, automatizaciones, salud)
│                        — cada uno con index.js, api/, models/, services/,
│                          tests/, README.md, CONTRACT.md
├── views/               (salud, plantillas, gestion, empresas, adb-pilot,
│                         broadcast.html)
├── public/              (app.js, chat.js, chat.html, index.html, CSS, imágenes)
└── data/media/          (directorio de adjuntos — runtime, NO versionar)
```

**Conclusiones clave:**
- ~1 MB de código fuente real (sin `node_modules`, que son 19 MB y se regeneran).
- **Cero dependencias nativas que compilar.** Todo es JS puro. Node 18+ basta.
- Ya usa arquitectura modular Zonic (`src/features/<módulo>/` con CONTRACT.md),
  igual que `zynervoxv2`. Encaja naturalmente.
- `src/shared/config.js` lee la configuración de **variables de entorno**
  (`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `PORT`, `BASE_PATH`,
  `SESSION_SECRET`, `CREDENTIALS_KEY`, `ZYNERVOX_SSO_SECRET`, etc.). No hay hosts
  ni puertos quemados en el código → **systemd puede inyectarlas igual que Docker.**

**Veredicto: migración viable sin reescribir lógica de negocio.**

---

## 3. Alcance

### Entra en alcance

- Extraer el código de la imagen Docker y vendorizarlo en el repo.
- Fusionar los 4 overrides en el código vendorizado (ver §5, paso 3).
- Reescribir `installer/whatsapp.sh` para despliegue nativo.
- Crear unidad systemd para el proceso Node.
- Migrar la BD al MySQL anfitrión.
- Eliminar `whatsapp/compose.yml` y el `Dockerfile`.
- Actualizar `README.md` y `CONTRACT.md` del módulo.
- Actualizar `installer/check.sh` para verificar el servicio nativo.
- Probar el ciclo completo: borrar → clonar → instalar → verificar.

### NO entra en alcance

- Modificar la lógica de negocio de Zynerwaba (features, API, vistas).
- Tocar `app/web/modules/admin/whatsapp.php` salvo que una prueba lo exija.
- Tocar los módulos `farm`, `stt_providers`, `bot_ivr` ni `zynerdesk`.
- Configurar credenciales reales de Meta ni hacer E2E con WhatsApp Cloud API.
- Tocar `mirmidon` ni ningún servidor de producción.
- Publicar la imagen Docker en ningún registro (se abandona ese camino).

---

## 4. ⚠️ Advertencia crítica: esto es un CAMBIO DE CONTRATO

`src/features/whatsapp/CONTRACT.md` declara hoy, de forma explícita:

```
## Dependencias permitidas
- Imagen integrada `miguelleonh0331/zynerwabav2:2.1.0-zynervox`, construida
  sobre `miguelleonh0331/zynerwabav2:2.0.0` fijada por digest.

## Salidas públicas
- Servicios Compose `app` y `db`, nombrados y aislados por `COMPOSE_PROJECT_NAME`.
- Volúmenes `whatsapp_data` y `whatsapp_mysql`, prefijados por el proyecto Compose.

## Garantías
- La imagen se verifica por digest.
```

Esta tarea **invalida todo eso**. Según `agents/whatsapp_AGENT.md`, el worker
**DEBE escalar al `ARCHITECT_AGENT`** cuando «necesita cambiar `CONTRACT.md`» o
cuando «la tarea afecta arquitectura general, stack o despliegue». Ambas aplican.

**Acción obligatoria antes de escribir código:**
1. Leer `agents/ARCHITECT_AGENT.md`.
2. Registrar la decisión en `docs/DECISIONS.md` (motivo: unificar el modelo de
   despliegue con `farm`/`stt_providers`, eliminar MySQL duplicado, quitar la
   dependencia de Docker, y recuperar el código fuente que hoy solo vive en una
   imagen binaria).
3. Registrar el cambio en `docs/CHANGELOG_AGENT.md`.
4. Reescribir `CONTRACT.md` y `README.md` del módulo reflejando el modelo nativo.

### Pérdidas conscientes que hay que aceptar y documentar

| Se pierde | Mitigación |
|---|---|
| Aislamiento de proceso del contenedor | Usuario systemd dedicado + permisos restrictivos (igual que `farm`). |
| Verificación por digest de imagen | El código queda versionado en Git: trazabilidad por commit en vez de digest. |
| BD WhatsApp aislada en su propio motor | BD `zynerwabav2` separada dentro del MySQL del host, con usuario propio y GRANT limitado a esa base (igual que `zynervox_stt`). **Nunca mezclar con `asterisk`** — el CONTRACT ya lo prohíbe y eso se mantiene. |
| `docker compose down/up` como ciclo de vida | `systemctl start/stop/restart` equivalente. |

---

## 5. Pasos de ejecución

### Paso 0 — Preparación

```bash
cd "E:\servidores\zynerdesk\proyectos\zynervoxv2"
git fetch origin && git status --short --branch
git checkout -b feat/whatsapp-nativo
```

Trabajar en rama, **no directo sobre `main`**. Otro agente (farm) trabaja en el
mismo working tree: coordinar antes de hacer commits, el índice de git es único.

### Paso 1 — Extraer el código de la imagen Docker

Desde WSL (la imagen ya está construida localmente):

```bash
docker create --name wa_extract miguelleonh0331/zynerwabav2:2.1.0-zynervox
docker cp wa_extract:/app /tmp/wa_src
docker rm wa_extract
```

Limpiar antes de vendorizar (**no versionar basura**):

```bash
rm -rf /tmp/wa_src/node_modules      # se regenera con npm ci
rm -rf /tmp/wa_src/data              # directorio runtime de adjuntos
```

Conservar `package-lock.json` (es lo que garantiza instalación reproducible).

### Paso 2 — Vendorizar en el repo

Copiar a la copia local Windows, siguiendo el patrón exacto de `farm` y
`stt_providers` (ambos usan `src/features/<mod>/vendor/`):

```
src/features/whatsapp/vendor/
├── server.js
├── package.json
├── package-lock.json
├── src/
├── views/
└── public/
```

Añadir a `.gitignore` si no está ya cubierto:
```
src/features/whatsapp/vendor/node_modules/
src/features/whatsapp/vendor/data/
```

Documentar en `src/features/whatsapp/README.md` el origen exacto, igual que hizo
`farm` («Origen: `https://github.com/...`, commit `7026874...`»). Aquí sería:
«Origen: imagen `miguelleonh0331/zynerwabav2:2.1.0-zynervox`, extraída el
2026-10-03; base `2.0.0` digest `sha256:b6e3ac4ba9115435b151482c6d8c06fbbfe96a572c77cc1e83778c3e68e4c624`».

### Paso 3 — Fusionar los overrides

`whatsapp/Dockerfile` aplica 4 overrides sobre la imagen base:

```
overrides/public/app.js                    → /app/public/app.js
overrides/src/features/empresas/index.js   → /app/src/features/empresas/index.js
overrides/views/empresas/index.html        → /app/views/empresas/index.html
overrides/views/gestion/index.html         → /app/views/gestion/index.html
```

**Importante:** el `docker cp` del Paso 1 se hizo sobre la imagen `2.1.0-zynervox`,
que **ya tiene los overrides aplicados**. Verificar con `diff` que el contenido
vendorizado coincide con `whatsapp/overrides/`:

```bash
diff /tmp/wa_src/public/app.js whatsapp/overrides/public/app.js
diff /tmp/wa_src/src/features/empresas/index.js whatsapp/overrides/src/features/empresas/index.js
# etc.
```

Si coinciden → los overrides ya están fusionados, se puede **eliminar
`whatsapp/overrides/`** (deja de tener sentido sin Dockerfile).
Si NO coinciden → detenerse y reportar: significa que la imagen local está
desactualizada respecto al repo.

### Paso 4 — Reescribir `installer/whatsapp.sh`

Tomar como plantilla `installer/farm.sh` (ya hace exactamente esto: usuario
dedicado + systemd + config en `/etc/zynervox/`) y `installer/stt-providers.sh`
(ya hace: crear BD + usuario MySQL + importar schema).

El nuevo script debe:

1. **Validar dependencias nativas:** `node` (≥18), `npm`, `mysql`, `openssl`.
   Fallar con mensaje claro si falta alguna. **Ya no requiere Docker.**
2. **Crear usuario de sistema** dedicado (ej. `zynervox-whatsapp`), sin shell.
3. **Crear BD + usuario MySQL** en el host:
   ```sql
   CREATE DATABASE IF NOT EXISTS zynerwabav2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER IF NOT EXISTS 'zynerwabav2'@'127.0.0.1' IDENTIFIED BY '<generado>';
   GRANT ALL PRIVILEGES ON `zynerwabav2`.* TO 'zynerwabav2'@'127.0.0.1';
   ```
   ⚠️ **Usar `127.0.0.1`, NO `localhost`.** Bug ya encontrado y corregido en
   `stt-providers.sh` (commit `007e445`): `localhost` fuerza socket Unix, y en
   WSL sin systemd el socket queda `0700 mysql:mysql` → `Permission denied`.
4. **Importar el esquema:** `mysql ... < whatsapp/init/001-schema.sql`
   (idempotente, usa `CREATE TABLE IF NOT EXISTS`).
5. **Instalar la app:** copiar `vendor/` a `/opt/zynervox-whatsapp/` y ejecutar
   `npm ci --omit=dev` ahí.
6. **Generar `/etc/zynervox/zynervox-whatsapp.env`** (modo `0640 root:www-data`)
   con las mismas variables que hoy inyecta Compose:
   `PORT`, `BASE_PATH`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_NAME`, `DB_USER`,
   `DB_PASSWORD`, `SESSION_SECRET`, `CREDENTIALS_KEY`, `INITIAL_ADMIN_USER`,
   `INITIAL_ADMIN_PASSWORD`, `ZYNERVOX_SSO_SECRET`.
   ⚠️ **Mismo bug que `stt-providers.sh`:** si se genera el `.env` y luego se usan
   esas variables en el mismo script, hay que hacer `source` del archivo después
   de escribirlo. Bajo `set -u` falla con `unbound variable` (commit `45245b2`).
7. **Crear unidad systemd** `zynervox-whatsapp.service`:
   ```ini
   [Service]
   Type=simple
   User=zynervox-whatsapp
   WorkingDirectory=/opt/zynervox-whatsapp
   EnvironmentFile=/etc/zynervox/zynervox-whatsapp.env
   ExecStart=/usr/bin/node server.js
   Restart=on-failure
   # Endurecimiento (copiar criterio de farm.sh):
   NoNewPrivileges=true
   PrivateTmp=true
   ProtectSystem=strict
   ReadWritePaths=/var/lib/zynervox-whatsapp
   ```
   El directorio de adjuntos (`data/media`) va en `/var/lib/zynervox-whatsapp/`,
   no dentro de `/opt`.
8. **Sincronizar el superadmin inicial** (hoy lo hace `sync_initial_admin()` vía
   `docker compose exec`). Reimplementar como `node -e` directo o script auxiliar.
9. **Mantener subcomandos** del contrato: `init|up|status|credentials|install-proxy|
   remove-proxy|backup|restore|down`, pero reimplementados:
   - `status` → `systemctl is-active`
   - `up`/`down` → `systemctl start`/`stop`
   - `backup`/`restore` → `mysqldump`/`mysql` directos contra el host
   - `install-proxy`/`remove-proxy` → **sin cambios**, ya funcionan.

### Paso 5 — Limpiar lo que deja de aplicar

```
whatsapp/compose.yml      → eliminar
whatsapp/Dockerfile       → eliminar
whatsapp/.dockerignore    → eliminar
whatsapp/overrides/       → eliminar (ya fusionado, ver Paso 3)
whatsapp/init/001-schema.sql → CONSERVAR (ahora lo usa el instalador nativo)
whatsapp/.env.example     → actualizar (quitar COMPOSE_PROJECT_NAME,
                            ZYNERWABA_IMAGE, WHATSAPP_DB_ROOT_PASSWORD)
```

Revisar también `installer/install.sh`: la función `install_whatsapp()` llama a
`whatsapp.sh init && whatsapp.sh install-proxy` — probablemente siga sirviendo,
pero verificar que no haya lógica de `--install-docker` colgando solo de WhatsApp.

### Paso 6 — Actualizar `installer/check.sh`

Hoy no verifica WhatsApp. Añadir, siguiendo el patrón de `CHECK_FARM`:

```bash
if [[ "$CHECK_WHATSAPP" == 1 ]]; then
  systemctl is-active --quiet zynervox-whatsapp.service || { echo "FALTA servicio activo: zynervox-whatsapp"; fail=1; }
  [[ -f /etc/zynervox/zynervox-whatsapp.env ]] || { echo "FALTA configuración WhatsApp"; fail=1; }
fi
```

### Paso 7 — Actualizar documentación del módulo

- `src/features/whatsapp/CONTRACT.md` — reescribir §Dependencias, §Salidas,
  §Garantías y §Errores posibles conforme al modelo nativo.
- `src/features/whatsapp/README.md` — actualizar §Responsabilidad,
  §Dependencias principales, §Estado y §Pruebas.
- `src/features/whatsapp/tests/smoke.sh` — adaptar: hoy reinicia el contenedor
  (`WHATSAPP_TEST_RESTART=1`), debe pasar a `systemctl restart`.
- `agents/whatsapp_AGENT.md` — la regla «Mantener imagen, digest y esquema
  sincronizados» ya no aplica; sustituir.
- `docs/DECISIONS.md` y `docs/CHANGELOG_AGENT.md` — registrar (ver §4).

---

## 6. ⚠️ Riesgo principal: el SSO debe seguir funcionando

`app/web/modules/admin/whatsapp.php` firma claims con HMAC-SHA256 usando
`ZYNERVOX_SSO_SECRET` (leído de `/etc/zynervox/whatsapp.conf`) y los canjea
contra `POST /api/sso/zynervox` del backend Node.

**El secreto debe seguir siendo idéntico en ambos lados.** Hoy: Compose lo pasa
por entorno al contenedor, y `install-proxy` lo escribe en `whatsapp.conf`.
Mañana: debe ir en el `EnvironmentFile` de systemd **y** en `whatsapp.conf`, con
el mismo valor.

Si se rompe, el síntoma es **HTTP 403 en el handshake** y la página queda en
«Conectando de forma segura…» para siempre. Probarlo explícitamente (§7, prueba 6).

Otro punto de atención: el backend usa `express-session` con
`cookie.path = BASE_PATH` y `app.set('trust proxy', 1)`. Como el proxy Apache no
cambia, debería funcionar igual — pero **verificar que la sesión persiste**, no
solo que responde 200.

---

## 7. Criterios de aceptación

El trabajo está terminado cuando **todas** estas pruebas pasan desde cero
(borrar → clonar desde GitHub → instalar → verificar):

| # | Prueba | Resultado esperado |
|---|---|---|
| 1 | `docker ps -a` | **Vacío.** Ningún contenedor. |
| 2 | `docker images` | Sin `zynerwabav2` ni `mysql:8.4` (opcional: limpiar). |
| 3 | `systemctl is-active zynervox-whatsapp` | `active` |
| 4 | `mysql -e "SHOW TABLES FROM zynerwabav2" \| wc -l` | ~63 tablas |
| 5 | `curl -I http://localhost/zynerwabav2/` | HTTP 200 |
| 6 | **Login Zynervox → `modules/admin/whatsapp.php`** | Carga el panel, SSO OK, **sin** quedarse en «Conectando…» |
| 7 | Reiniciar servicio, recargar panel | La sesión persiste (no re-login) |
| 8 | `installer/whatsapp.sh backup /tmp/wa.sql.gz` | Genera el dump sin Docker |
| 9 | `installer/whatsapp.sh credentials` | Muestra usuario/clave admin |
| 10 | `installer/check.sh --strict` (con `CHECK_WHATSAPP=1`) | Sin fallos nuevos. **Solo** debe fallar por Asterisk ausente (gap ya conocido y aceptado). |
| 11 | `farm` y `stt_providers` | **Siguen funcionando.** No se rompió nada ajeno. |

### Ciclo de prueba a usar (ya validado en esta sesión)

```bash
# 1. Borrar
rm -rf /home/mleon/proyectos/zynervox-borrar /var/www/html/zynervox-borrar /etc/zynervox

# 2. Clonar desde GitHub (rama de trabajo)
git clone -b feat/whatsapp-nativo https://github.com/miguelleonh0331/zynervoxv2.git \
  /home/mleon/proyectos/zynervox-borrar

# 3. Instalar
cd /home/mleon/proyectos/zynervox-borrar
WEB_ROOT=/var/www/html/zynervox-borrar URL_PATH=/zynervox-borrar \
  sudo bash installer/install.sh --skip-packages --with-whatsapp --with-stt-providers --apply-migrations

# 4. Recrear astguiclient.conf (el instalador NO lo genera — limitación conocida)
#    Formato: VARDB_server => 127.0.0.1 / VARDB_port => 3306 /
#             VARDB_database => asterisk / VARDB_user => zynervox_app / VARDB_pass => <...>
#    Permisos: root:www-data 0640
```

---

## 8. Notas operativas del entorno WSL

Aprendizajes de esta sesión que **ahorran tiempo de depuración**:

1. **`localhost` vs `127.0.0.1` en MySQL:** siempre `127.0.0.1`. Con `localhost`,
   PDO/mysql2 fuerzan socket Unix y `/run/mysqld` queda `0700 mysql:mysql`.
2. **`/etc/zynervox/` NO es persistente de forma confiable:** si la VM de WSL se
   apaga por completo, ese directorio se pierde (ya pasó dos veces hoy). Los datos
   de MySQL **sí** sobreviven. Si al retomar la web da HTTP 500, revisar primero
   si `astguiclient.conf` existe.
3. **Hay otro agente trabajando en `farm`,** sobre el mismo working tree de git y
   el mismo WSL. **Coordinar antes de:** reiniciar/apagar la VM de WSL, reiniciar
   Apache o MySQL, borrar `/etc/zynervox/` completo, o hacer commit/push.
4. **No reiniciar la VM de WSL** (`wsl --shutdown`) sin avisar: tumba Apache,
   MySQL y la config de ambos agentes.
5. **Quoting en WSL desde Windows:** los heredocs y comillas anidadas se corrompen
   al pasar por capas de shell. Escribir scripts `.sh` en un archivo y ejecutarlos
   (`bash /mnt/e/.../script.sh`) en vez de pasar comandos largos inline.

---

## 9. Entregable esperado del worker

Al finalizar, reportar:

1. Archivos creados, modificados y eliminados.
2. Resultado de **cada una** de las 11 pruebas de §7 (no un resumen: el resultado
   concreto de cada una).
3. Cambios aplicados a `CONTRACT.md` y justificación.
4. Entradas añadidas a `docs/DECISIONS.md` y `docs/CHANGELOG_AGENT.md`.
5. Riesgos detectados y limitaciones que queden abiertas.
6. Rama y commits generados (**sin hacer merge a `main` sin autorización**).

---

## 10. Autorizaciones

- Este documento **describe** el trabajo; **no lo autoriza**.
- El worker necesita `procede` explícito del usuario antes de ejecutar cambios.
- `procede` cubre solo el alcance de §3, nada más.
- Reiniciar o detener servicios compartidos (Apache, MySQL, la VM de WSL)
  requiere autorización **específica y separada**, aunque ya exista `procede`.
