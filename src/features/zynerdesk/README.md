# Módulo: zynerdesk

## Propósito

Incorporar Synervox Remoteo (supervisión remota de agentes: WebRTC, telemetría
de actividad, control de acceso) como área administrativa `Zynerdesk` del menú
de Zynervox, como servicio Docker aislado e instalable desde el mismo
repositorio central.

## Origen y versión upstream

- Imagen: `ghcr.io/miguelleonh0331/synervox-remoteo`
- Revisión fijada: `24b8b44d4a3905cb493526090c0f247a81c37be6`
- Digest inmutable: `sha256:bc7393a4c0040a0068cf8321c622032e4991352fd2b08cf8167cbd45c19083f0`
- Stack real: Node.js 22, `ws` (WebSocket), `mysql2`, `bcrypt`, `dotenv`.
- Migraciones y usuario admin inicial se aplican solos al arrancar
  (`scripts/start.js`, idempotente vía tabla `schema_migrations`).
- La imagen no se construye localmente: se descarga publicada y se fija por
  digest. No usar etiquetas flotantes.

## Responsabilidad

Este módulo se encarga de:

- el stack Docker (`zynerdesk/compose.yml`) de la app + su MySQL propio;
- el instalador (`installer/zynerdesk.sh`, flag `--with-zynerdesk`);
- el proxy Apache (`installer/apache-zynerdesk.conf.template`), que publica
  los assets, la API y el WebSocket del upstream bajo una subruta propia;
- la vista integrada `app/web/modules/admin/zynerdesk.php`, que embebe el
  panel dentro del shell de Zynervox;
- la entrada `Zynerdesk` en `app/web/modules/admin/sidebar.php`.

## No responsabilidad

Este módulo no se encarga de:

- el agente Windows (`miguelleonh0331/synervox-remoteo-agent`): descarga y
  distribución del `.exe` quedan expresamente pendientes para otra etapa.
  Los enlaces de descarga del upstream se retiran de la vista embebida porque
  además esta imagen no sirve `/downloads`;
- VICIdial, WhatsApp, Farm ni Stt Providers: no comparte tablas ni credenciales
  con ningún otro módulo.

## Estructura

```text
api/        endpoints, rutas o adaptadores de entrada
services/   lógica del módulo
models/     modelos, entidades o DTOs
tests/      pruebas del módulo
```

En este módulo `api/services/models/tests` quedan vacíos a propósito: la
lógica de aplicación vive en la imagen Docker upstream, no en este
repositorio. El código propio del módulo son los artefactos de despliegue e
integración: `zynerdesk/compose.yml`, `installer/zynerdesk.sh`,
`installer/apache-zynerdesk.conf.template`,
`app/web/modules/admin/zynerdesk.php` y la entrada de sidebar.

## Cómo funciona la integración visual

El usuario rechazó el iframe y pidió que Zynerdesk se vea como el resto de
módulos: un solo sidebar, un solo encabezado y un solo scroll.

El upstream fijado no ofrece una variable de `base path`: sirve rutas
relativas al documento que las contiene. Por eso `zynerdesk.php`:

1. descarga por HTTP desde `127.0.0.1:<puerto>` la página pedida (igual que
   Farm incluye su propio cuerpo, pero por red en vez de por `require`);
2. extrae su `<body>` y lo reescribe: resuelve cada ruta relativa de
   `href`/`src` contra la carpeta del documento, normalizando `./` y `../`
   como lo haría el navegador, y la convierte en una ruta absoluta del proxy;
3. reemite los `<link rel="stylesheet">` externos de su `<head>`;
4. inyecta su CSS dentro de `@scope (.zynerdesk-native)` para no contaminar
   el shell;
5. lo envuelve en el encabezado y el sidebar de Zynervox.

Así el navegador pide los assets, la API y el WebSocket directamente al proxy
público, pero la página que los contiene es una página de Zynervox.

### Vistas

`zynerdesk.php?view=<clave>` embebe una página del upstream. Las vistas con
`tab => true` aparecen como pestañas en la cabecera; `remoteo` es contextual y
se alcanza desde el panel.

| Vista | Página upstream | Pestaña |
|---|---|---|
| `panel` (por defecto) | `index.html` | sí |
| `supervicion` | `supervicion/index.html` | sí |
| `usuarios` | `admin.html` | sí |
| `remoteo` | `remoteo.html` | no, contextual |

Los enlaces del upstream que apuntan a una de estas páginas se reescriben de
vuelta al shell, y se les retira `target="_blank"`, para que la navegación no
se salga del panel. Cualquier otra ruta va al proxy.

Para agregar una vista basta con sumarla a `$ZYNERDESK_VIEWS`: la resolución
de rutas es genérica y no requiere reglas nuevas.

### Trampas conocidas del upstream

Documentadas porque volverán a aparecer al actualizar la imagen:

- **`leaflet.css` vive en el `<head>`.** Al embeber solo el `<body>` se perdía
  y los tiles del mapa quedaban sin `position:absolute`, descuadrados. Por eso
  se reemiten los `<link>` del head conservando `integrity` y `crossorigin`.
- **La paleta se declara en `:root` y el fondo en `body`.** Dentro de
  `@scope` ninguno de los dos coincide con un elemento del scope, así que las
  variables quedaban sin definir y la vista se veía lavada. Ambos se reescriben
  a `:scope`.
- **`supervicion/app.js` fija su base con
  `location.pathname.split("/supervicion")`**, que embebido no existe. Ese
  script se trae y se inyecta en línea con `API_BASE` y `APP_BASE` apuntando a
  la ruta real del proxy, o su API y su WebSocket quedan rotos.
- **El query no se normaliza junto con la ruta.** `Remotear` identifica al
  equipo con `?agent=...`; resolver `../` es cosa del path y los parámetros se
  conservan aparte.

Si una actualización del upstream cambia alguno de estos supuestos, el síntoma
aparece en la vista embebida, no en el contenedor: comparar siempre contra
`http://127.0.0.1:<puerto>/` directo antes de tocar el rewrite.

## Dependencias principales

- Docker + Docker Compose en el host.
- MySQL 8.4 propio (contenedor `db` del compose), sin compartir con otros módulos.
- Apache con `mod_proxy`, `mod_proxy_http`, `mod_proxy_wstunnel`.
- PHP con `curl` (la vista integrada descarga el upstream server-side).
- Imagen `ghcr.io/miguelleonh0331/synervox-remoteo@sha256:bc7393a4c0040a0068cf8321c622032e4991352fd2b08cf8167cbd45c19083f0`.

## Casos principales

- Admin entra a Zynervox, hace clic en `Zynerdesk` y ve el panel dentro del
  shell, con pestañas para Panel, Supervisión múltiple y Usuarios.
- Desde el panel abre `Remotear` sobre un equipo concreto, sin salir de
  Zynervox.
- Instalación/actualización vía `installer/zynerdesk.sh init|up|install-proxy`,
  idempotente, sin perder el volumen de datos.

## Autenticación

Inicio de sesión único, sin credenciales compartidas:

- `zynerdesk.php` exige sesión administrativa de Zynervox (`Auth::checkAccess(9)`);
- genera claims nivel 9 firmados con `ZYNERVOX_SSO_SECRET` y vencimiento de 60 segundos;
- el upstream intercambia la firma en `POST /api/auth/zynervox-sso` por su cookie
  `sid` normal, preservando RBAC, API y WebSocket autenticados;
- el login directo de Zynerdesk permanece como acceso de recuperación.

En instalaciones HTTP de laboratorio se usa `ZYNERDESK_COOKIE_SECURE=0` con
`ZYNERDESK_COOKIE_SAME_SITE=Lax`. En producción HTTPS debe usarse `Secure=1`.

## Notas para agentes

Antes de modificar este módulo, leer en orden:

1. este `README.md`;
2. `CONTRACT.md`;
3. `agents/zynerdesk_AGENT.md`.
