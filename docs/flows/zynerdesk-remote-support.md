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

1. `modules/admin/zynerdesk.php` exige sesión administrativa de Zynervox
   (`Auth::checkAccess(9)`); sin ella redirige al login del panel.
2. Lee `/etc/zynervox/zynerdesk.conf` para obtener la ruta pública
   (`ZYNERDESK_BASE_PATH`) y el puerto loopback (`ZYNERDESK_PORT`).
3. Descarga server-side la página de la vista pedida desde
   `127.0.0.1:${ZYNERDESK_PORT}` (por defecto `index.html`).
4. Reescribe sus rutas relativas hacia el proxy público, reemite las hojas de
   estilo externas de su `<head>`, confina su CSS en
   `@scope (.zynerdesk-native)` y lo envuelve en el sidebar y el encabezado
   de Zynervox. Sin iframe.
5. Ya en el navegador, la página pide assets, API y WebSocket al proxy, que
   Apache enruta a `127.0.0.1:${ZYNERDESK_PORT}` (`ProxyPass` con `nocanon` y
   `upgrade=websocket`).
6. Si no hay sesión del upstream, su propia API responde 401 y la app lleva a
   su `login.html` en la ruta del proxy; el admin se autentica con las
   credenciales del contenedor (no hay SSO en esta etapa).
7. El panel lista agentes/equipos. `Supervisión múltiple` y `Usuarios` son
   pestañas de la misma vista integrada; `Remotear` abre el equipo concreto
   en la vista contextual `?view=remoteo&agent=...`, también dentro del shell.
8. Alta de un equipo nuevo requeriría el agente Windows instalado en esa
   máquina: **este paso queda marcado como pendiente**, no se implementa ni
   se descarga el `.exe` en esta etapa.

## Estados

- `sin sesión Zynervox` → redirección al login del panel.
- `sin sesión upstream` → la app embebida ofrece su propio login.
- `panel` → `sesión remota activa` (equipo con agente conectado) — fuera de
  alcance validar en esta etapa, porque no hay agentes Windows desplegados.

## Errores

- Upstream caído, puerto mal resuelto o PHP sin `curl` → la vista muestra el
  bloque `integration-error` sin romper el resto del panel.
- Proxy caído o puerto equivocado → Apache devuelve 502/503 en los assets y
  la API, con la página del shell ya renderizada.
- WebSocket no upgradea → revisar `mod_proxy_wstunnel` habilitado y la línea
  `upgrade=websocket` en `installer/apache-zynerdesk.conf.template`.
- MySQL no disponible al arrancar → contenedor `app` reintenta 30 veces (60s)
  y luego falla (`scripts/start.js` del upstream).
- Vista con estilos rotos o mapa descuadrado tras actualizar la imagen →
  revisar las trampas conocidas en `src/features/zynerdesk/README.md`;
  comparar primero contra `http://127.0.0.1:<puerto>/` directo.

## Observabilidad

- `docker compose -p <proyecto> logs app` / `logs db` (sin credenciales en
  los logs, según `CONTRACT.md`).
- `installer/zynerdesk.sh status` y `installer/check.sh` (busca
  `/etc/zynervox/zynerdesk.conf`).
- `curl -fsS http://127.0.0.1:<puerto>/login.html` para salud HTTP directa.

## Rutas de código

- `app/web/modules/admin/zynerdesk.php` (vista integrada y reescritura).
- `app/web/modules/admin/sidebar.php` (entrada de menú).
- `zynerdesk/compose.yml`, `installer/zynerdesk.sh`,
  `installer/apache-zynerdesk.conf.template`.
- `src/features/zynerdesk/README.md`, `CONTRACT.md`.

## Pruebas

- Instalación: `installer/zynerdesk.sh init` + `install-proxy`, luego
  `installer/check.sh` sin fallos.
- Las cuatro vistas (`panel`, `supervicion`, `usuarios`, `remoteo`) responden
  y rechazan el acceso sin sesión Zynervox.
- API del upstream sin sesión → 401 (`/api/agents`).
- WebSocket upgradea extremo a extremo → 101 por la ruta pública.
- Navegación: ningún enlace del panel sale del shell ni abre otra ventana.
- Persistencia: reiniciar el stack (`zynerdesk.sh down` + `up`) y confirmar
  que el usuario admin sigue intacto.
- Agente Windows: sin pruebas en esta etapa (pendiente de alcance futuro).
