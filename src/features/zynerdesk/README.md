# Módulo: zynerdesk

## Propósito

Incorporar Synervox Remoteo (supervisión remota de agentes: WebRTC, telemetría
de actividad, control de acceso) como área administrativa `Zynerdesk` del menú
de Zynervox, como servicio Docker aislado e instalable desde el mismo
repositorio central.

## Origen y versión upstream

- Imagen: `miguelleonh0331/synervox-remoteov2`
- Versión fijada: `2.0.2`
- Digest inmutable: `sha256:0c6f400c6385ca08840ec0282698a5750d21b5c85c8f41d98cf65078d272783b`
- Stack real: Node.js 22, `ws` (WebSocket), `mysql2`, `bcrypt`, `dotenv`.
- Migraciones y usuario admin inicial se aplican solos al arrancar
  (`scripts/start.js`, idempotente vía tabla `schema_migrations`).
- El frontend calcula su `BASE` desde `location.pathname` (no hay variable
  `BASE_PATH`): ya soporta montarse bajo una subruta sin tocar la imagen.

## Responsabilidad

Este módulo se encarga de:

- el stack Docker (`zynerdesk/compose.yml`) de la app + su MySQL propio;
- el instalador (`installer/zynerdesk.sh`, flag `--with-zynerdesk`);
- el proxy Apache (`installer/apache-zynerdesk.conf.template`) bajo una
  subruta propia, con WebSocket (`mod_proxy_wstunnel` + `upgrade=websocket`);
- la entrada `Zynerdesk` en el sidebar de Zynervox
  (`app/web/modules/admin/sidebar.php`), leyendo la ruta pública real desde
  `/etc/zynervox/zynerdesk.conf`.

## No responsabilidad

Este módulo no se encarga de:

- el agente Windows (`miguelleonh0331/synervox-remoteo-agent`): descarga y
  distribución del `.exe` quedan expresamente pendientes para otra etapa;
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
repositorio. Lo que sí es código propio del módulo son los artefactos de
despliegue: `zynerdesk/compose.yml`, `installer/zynerdesk.sh`,
`installer/apache-zynerdesk.conf.template` y la entrada de sidebar.

## Dependencias principales

- Docker + Docker Compose en el host.
- MySQL 8.4 propio (contenedor `db` del compose), sin compartir con otros módulos.
- Apache con `mod_proxy`, `mod_proxy_http`, `mod_proxy_wstunnel`.
- Imagen `miguelleonh0331/synervox-remoteov2@sha256:0c6f...783b`.

## Casos principales

- Admin entra a Zynervox, hace clic en `Zynerdesk` en el sidebar, navega
  (sin iframe) a la app proxyeada bajo su subruta pública.
- La app resuelve login, WebSocket y assets solos porque calculan su base
  dinámicamente desde la URL real.
- Instalación/actualización vía `installer/zynerdesk.sh init|up|install-proxy`,
  idempotente, sin perder el volumen de datos.

## Integración visual (decisión de etapa 1)

Se eligió la opción 2 del orden de preferencia del usuario: proxy inverso
bajo el mismo dominio, conservando el frontend completo del upstream durante
esta primera etapa (sin iframe). La app no comparte sesión con Zynervox
(login propio, usuario admin generado en la instalación). Integración nativa
vía API (como hace WhatsApp con SSO) queda documentada como mejora futura en
`docs/ROADMAP.md`, porque el upstream `2.0.2` no expone un mecanismo SSO
equivalente.

## Notas para agentes

Antes de modificar este módulo, leer en orden:

1. este `README.md`;
2. `CONTRACT.md`;
3. `agents/zynerdesk_AGENT.md`.
