# Flujo: soporte remoto Zynerdesk

## Actores

- Admin Zynervox (sesión nivel 9+), navegador.
- Contenedor `zynerdesk-app` (Synervox Remoteo, Node.js, puerto interno `4011`).
- MySQL propio del contenedor `db` (base `syner_remoteo`).
- Apache (proxy HTTP + WebSocket).
- Agente Windows (`synervox-remoteo-agent`): **pendiente**, no implementado en
  esta etapa.

## Disparador

Admin hace clic en `Zynerdesk` en el sidebar de Zynervox.

## Pasos

1. Sidebar resuelve la ruta pública real leyendo
   `/etc/zynervox/zynerdesk.conf` (`ZYNERDESK_BASE_PATH`); si falta, usa
   `/zynerdesk` por defecto.
2. Navegación completa (sin iframe) a `${ZYNERDESK_BASE_PATH}/`.
3. Apache proxyea la petición a `127.0.0.1:${ZYNERDESK_PORT}` (`ProxyPass`
   con `nocanon` y `upgrade=websocket`).
4. La app sirve `login.html`; el admin se autentica con credenciales propias
   del contenedor (no hay SSO con la sesión Zynervox en esta etapa).
5. Tras login, la app calcula su `BASE` desde `location.pathname` y abre
   WebSocket a `${ZYNERDESK_BASE_PATH}/ws`, proxyeado igual que el HTTP.
6. El panel lista agentes/equipos y permite iniciar sesión de supervisión
   remota (WebRTC) desde el propio frontend del upstream.
7. Alta de un equipo nuevo requeriría el agente Windows instalado en esa
   máquina: **este paso queda marcado como pendiente**, no se implementa ni
   se descarga el `.exe` en esta etapa.

## Estados

- `login` → `panel` (credenciales válidas) / `login` con error (inválidas).
- `panel` → `sesión remota activa` (equipo con agente conectado) — fuera de
  alcance validar en esta etapa, porque no hay agentes Windows desplegados.

## Errores

- Proxy caído o puerto equivocado → Apache devuelve 502/503.
- WebSocket no upgradea → revisar `mod_proxy_wstunnel` habilitado y la línea
  `upgrade=websocket` en `installer/apache-zynerdesk.conf.template`.
- MySQL no disponible al arrancar → contenedor `app` reintenta 30 veces (60s)
  y luego falla (`scripts/start.js` del upstream).

## Observabilidad

- `docker compose -p <proyecto> logs app` / `logs db` (sin credenciales en
  los logs, según `CONTRACT.md`).
- `installer/zynerdesk.sh status` y `installer/check.sh` (busca
  `/etc/zynervox/zynerdesk.conf`).
- `curl -fsS http://127.0.0.1:<puerto>/login.html` para salud HTTP directa.

## Rutas de código

- `app/web/modules/admin/sidebar.php` (entrada de menú).
- `zynerdesk/compose.yml`, `installer/zynerdesk.sh`,
  `installer/apache-zynerdesk.conf.template`.
- `src/features/zynerdesk/README.md`, `CONTRACT.md`.

## Pruebas

- Instalación: `installer/zynerdesk.sh init` + `install-proxy`, luego
  `installer/check.sh` sin fallos.
- Acceso no autenticado a la app → debe pedir login (no expone panel).
- Persistencia: reiniciar el stack (`zynerdesk.sh down` + `up`) y confirmar
  que el usuario admin y la sesión de base siguen intactos.
- Agente Windows: sin pruebas en esta etapa (pendiente de alcance futuro).
