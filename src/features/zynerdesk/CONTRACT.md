# CONTRACT.md - zynerdesk

## Responsabilidad contractual

Publicar el panel de supervisión remota Synervox Remoteo dentro del shell de
Zynervox, como servicio Docker aislado con persistencia propia, accesible desde
el sidebar, sin iframe y sin tocar otros módulos.

## Entradas públicas

- `modules/admin/zynerdesk.php?view=<panel|supervicion|usuarios|remoteo>`:
  vista integrada. Exige sesión administrativa de Zynervox nivel 9. `view`
  desconocida cae a `panel`; no se acepta ninguna ruta fuera de la lista
  blanca `$ZYNERDESK_VIEWS`.
- `${ZYNERDESK_BASE_PATH}/`: ruta pública del proxy Apache hacia
  `127.0.0.1:${ZYNERDESK_PORT}`. Sirve los assets, la API y el login del
  upstream, que la página embebida consume desde el navegador.
- `${ZYNERDESK_BASE_PATH}/ws`: WebSocket, proxyeado con `upgrade=websocket`.
- `/etc/zynervox/zynerdesk.conf`: contrato de configuración entre el
  instalador y la web. Claves `ZYNERDESK_BASE_PATH`, `ZYNERDESK_PORT` y
  `ZYNERVOX_SSO_SECRET`. Lo
  escribe `installer/zynerdesk.sh install-proxy` con permisos
  `root:<grupo web> 0640`.

## Salidas públicas

- Entrada `Zynerdesk` en `app/web/modules/admin/sidebar.php`.
- Contenedores `app` y `db` con healthcheck propios.
- `installer/zynerdesk.sh` con las acciones `init`, `up`, `status`,
  `credentials`, `install-proxy`, `remove-proxy`, `backup`, `restore`, `down`.

## Errores posibles

- `ZYNERDESK_PORT` ocupado al instalar → `zynerdesk.sh init` falla antes de
  levantar el stack (escaneo de puertos libres 4100-4199).
- MySQL no disponible → `scripts/start.js` reintenta 30 veces (60s) antes de
  fallar; el contenedor `app` no arranca si no logra conectar.
- Proxy Apache mal configurado → `apache2ctl configtest` bloquea el reload.
- Upstream caído o puerto mal escrito en la configuración → la vista muestra
  `integration-error` en vez de romper la página de Zynervox.
- `/etc/zynervox/zynerdesk.conf` ausente → se usa `/zynerdesk` por defecto y
  no se puede resolver el puerto, de modo que la vista queda en el mismo
  estado de error controlado.

## Dependencias permitidas

Este módulo puede depender de:

- la sesión administrativa de Zynervox mediante `Includes\Auth`;
- Docker y Docker Compose del host;
- MySQL 8.4 propio (volumen `zynerdesk_mysql`, no compartido);
- Apache como proxy de la ruta pública (`mod_proxy`, `mod_proxy_http`,
  `mod_proxy_wstunnel`);
- PHP con `curl`, para traer el upstream server-side;
- la imagen publicada `ghcr.io/miguelleonh0331/synervox-remoteo` fijada por digest.

## Dependencias prohibidas

Este módulo no debe depender de:

- tablas o esquema de VICIdial, WhatsApp, Farm o Stt Providers;
- credenciales de otro módulo;
- implementación interna de otros módulos;
- módulos no declarados en "Dependencias permitidas";
- dependencias externas no aprobadas por `ARCHITECT_AGENT`.

## Garantías

Este módulo garantiza que:

- el contenedor `app` no se construye localmente: se descarga por digest fijo
  desde el registro publicado;
- los volúmenes `zynerdesk_mysql` y `zynerdesk_data` persisten entre
  actualizaciones (`zynerdesk.sh up` no recrea como `init`);
- el puerto del host queda enlazado solo a `127.0.0.1`;
- las migraciones son idempotentes (tabla `schema_migrations` del upstream);
- la vista embebida no altera el documento del upstream en el contenedor: toda
  la adaptación ocurre en memoria, al servir la página;
- la sesión administrativa Zynervox se intercambia por una sesión Zynerdesk
  mediante un token HMAC efímero; nunca se comparte la contraseña;
- el CSS del upstream queda confinado en `@scope (.zynerdesk-native)` y no
  altera el resto del panel.

## Prohibiciones

Este módulo no debe:

- modificar otros módulos;
- acceder a datos ajenos sin pasar por su contrato;
- romper compatibilidad sin una decisión registrada;
- editar archivos dentro del contenedor para adaptarlo al despliegue;
- incorporar el instalador `.exe` del agente Windows (pendiente, fuera de
  alcance de esta etapa).

## Propiedad de datos

La base `syner_remoteo` y sus volúmenes pertenecen exclusivamente a este
módulo. Ningún otro módulo los lee ni los escribe, y este módulo no consulta
datos de los demás.

## Cambios de contrato

Cualquier cambio en este archivo debe:

1. escalar a `ARCHITECT_AGENT`;
2. registrarse en `docs/DECISIONS.md` (`repo_agent.py log-decision`);
3. registrar el impacto en `docs/CHANGELOG_AGENT.md` (`repo_agent.py log-change`).
