# CONTRACT.md - zynerdesk

## Responsabilidad contractual

Publicar el panel de supervisión remota Synervox Remoteo dentro del shell de
Zynervox, como servicio nativo aislado (`systemd` + MySQL del host) con
persistencia propia, accesible desde el sidebar, sin iframe y sin tocar otros
módulos.

## Entradas públicas

- `modules/admin/zynerdesk.php?view=<panel|supervicion|usuarios|remoteo>`:
  vista integrada. Exige sesión administrativa de Zynervox nivel 9. `view`
  desconocida cae a `panel`; no se acepta ninguna ruta fuera de la lista
  blanca `$ZYNERDESK_VIEWS`.
- `${ZYNERDESK_BASE_PATH}/`: ruta pública del proxy Apache hacia
  `127.0.0.1:${ZYNERDESK_PORT}`. Sirve los assets, la API y el login del
  upstream, que la página embebida consume desde el navegador.
- `${ZYNERDESK_BASE_PATH}/ws`: WebSocket, proxyeado con `upgrade=websocket`.
- `${ZYNERDESK_BASE_PATH}/api/admin/agents/<id>/retire`: retiro lógico,
  reversible e idempotente de un equipo. Requiere sesión con rol `admin`.
- `${ZYNERDESK_BASE_PATH}/api/admin/agents/<id>/restore`: restaura un equipo
  retirado. Requiere sesión con rol `admin`.
- `${ZYNERDESK_BASE_PATH}/api/agents?retired=1`: lista de equipos retirados,
  disponible solo para administradores. La lista normal excluye retirados.
- `/etc/zynervox/zynerdesk.conf`: contrato de configuración entre el
  instalador y la web. Claves `ZYNERDESK_BASE_PATH`, `ZYNERDESK_PORT` y
  `ZYNERVOX_SSO_SECRET`. Lo
  escribe `installer/zynerdesk.sh install-proxy` con permisos
  `root:<grupo web> 0640`.

## Salidas públicas

- Entrada `Zynerdesk` en `app/web/modules/admin/sidebar.php`.
- Servicio systemd `zynervox-zynerdesk.service` (proceso Node nativo), usuario
  de sistema dedicado `zynervox-zynerdesk`, sin shell.
- `installer/zynerdesk.sh` con las acciones `init`, `up`, `status`,
  `upgrade`, `credentials`, `install-proxy`, `remove-proxy`, `backup`,
  `restore`, `down`. `upgrade` respalda la base, reemplaza solo el runtime del
  módulo y revierte automáticamente el código si la salud posterior falla.

## Errores posibles

- `ZYNERDESK_PORT` ocupado al instalar → `zynerdesk.sh init` falla antes de
  levantar el servicio (escaneo de puertos libres 4100-4199).
- MySQL no disponible → `scripts/start.js` reintenta 30 veces (60s) antes de
  fallar; el servicio no queda `active` si no logra conectar.
- Proxy Apache mal configurado → `apache2ctl configtest` bloquea el reload.
- Backend caído o puerto mal escrito en la configuración → la vista muestra
  `integration-error` en vez de romper la página de Zynervox.
- `/etc/zynervox/zynerdesk.conf` ausente → se usa `/zynerdesk` por defecto y
  no se puede resolver el puerto, de modo que la vista queda en el mismo
  estado de error controlado.

## Dependencias permitidas

Este módulo puede depender de:

- core

El módulo usa código históricamente vendorizado en `src/features/zynerdesk/vendor/`,
cuyo origen base está documentado en `README.md` y que desde ADR-0018 se mantiene
directamente, con trazabilidad por commits de este repositorio.

Infraestructura externa permitida: Node.js nativo ≥18 administrado por systemd,
MySQL nativo del host con base y usuario propios, Apache como proxy de la ruta
pública (`mod_proxy`, `mod_proxy_http`, `mod_proxy_wstunnel`) y PHP con `curl`
para traer el panel server-side.

## Dependencias prohibidas

Este módulo no debe depender de:

- Docker, Compose o cualquier contenedor para este módulo;
- tablas o esquema de VICIdial, WhatsApp, Farm o Stt Providers;
- credenciales de otro módulo;
- implementación interna de otros módulos;
- módulos no declarados en "Dependencias permitidas";
- dependencias externas no aprobadas por `ARCHITECT_AGENT`;
- reemplazos o nuevas importaciones de `vendor/` sin documentar su origen.

## Garantías

Este módulo garantiza que:

- el código vendorizado queda trazado por commit de Git, no por digest de imagen;
- la base `syner_remoteo` y el directorio de datos persisten entre
  actualizaciones (`zynerdesk.sh up` no recrea como `init`);
- el puerto del host queda enlazado solo a `127.0.0.1`;
- las migraciones son idempotentes (tabla `schema_migrations`);
- la vista embebida no altera el código vendorizado al servir la página: toda
  la adaptación ocurre en memoria;
- retirar un equipo no borra su registro ni su historial, no se revierte por
  nuevos reportes del agente y puede deshacerse explícitamente por un admin;
- la sesión administrativa Zynervox se intercambia por una sesión Zynerdesk
  mediante un token HMAC efímero; nunca se comparte la contraseña;
- el CSS del panel queda confinado en `@scope (.zynerdesk-native)` y no
  altera el resto del shell.

## Prohibiciones

Este módulo no debe:

- modificar otros módulos;
- acceder a datos ajenos sin pasar por su contrato;
- romper compatibilidad sin una decisión registrada;
- incorporar el instalador `.exe` del agente Windows (pendiente, fuera de
  alcance de esta etapa).

## Propiedad de datos

La base `syner_remoteo` y sus datos pertenecen exclusivamente a este
módulo. Ningún otro módulo los lee ni los escribe, y este módulo no consulta
datos de los demás.

## Cambios de contrato

Cualquier cambio en este archivo debe:

1. escalar a `ARCHITECT_AGENT`;
2. registrarse en `docs/DECISIONS.md` (`repo_agent.py log-decision`);
3. registrar el impacto en `docs/CHANGELOG_AGENT.md` (`repo_agent.py log-change`).
