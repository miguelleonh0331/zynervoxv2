# CONTRACT.md - zynerdesk

## Responsabilidad contractual

Publicar el panel de supervisión remota Synervox Remoteo bajo una subruta del
despliegue de Zynervox, como servicio Docker aislado con persistencia propia,
accesible desde el sidebar, sin iframe y sin tocar otros módulos.

## Entradas públicas

- Ruta pública HTTP(S): `${ZYNERDESK_BASE_PATH}/` (ej. en pruebas
  `/zynervoxv2-deploy-test-zynerdesk/`), proxyeada por Apache a
  `127.0.0.1:${ZYNERDESK_PORT}`.
- WebSocket: `${ZYNERDESK_BASE_PATH}/ws`, proxyeado con `upgrade=websocket`.
- Login propio de la app (usuario/contraseña generados en la instalación,
  tabla `users` de su propia base `syner_remoteo`). No hay SSO con la sesión
  de Zynervox en esta etapa.
- Config leída por el sidebar de Zynervox: `/etc/zynervox/zynerdesk.conf`
  (clave `ZYNERDESK_BASE_PATH`).

## Salidas públicas

- Entrada `Zynerdesk` visible en `app/web/modules/admin/sidebar.php`.
- Contenedores `app` y `db` con healthcheck propios.

## Errores posibles

- `ZYNERDESK_PORT` ocupado al instalar → `zynerdesk.sh init` falla antes de
  levantar el stack (escaneo de puertos libres 4100-4199).
- MySQL no disponible → `scripts/start.js` reintenta 30 veces (60s) antes de
  fallar; el contenedor `app` no arranca si no logra conectar.
- Proxy Apache mal configurado → `apache2ctl configtest` bloquea el reload
  (ver `installer/zynerdesk.sh install-proxy`).
- `/etc/zynervox/zynerdesk.conf` ausente → sidebar cae al valor por defecto
  `/zynerdesk` (puede no coincidir con la ruta real instalada).

## Dependencias permitidas

Este módulo puede depender de:

- Docker y Docker Compose del host;
- MySQL 8.4 propio (volumen `zynerdesk_mysql`, no compartido);
- Apache como proxy de la ruta pública (`mod_proxy`, `mod_proxy_http`,
  `mod_proxy_wstunnel`);
- la imagen publicada `miguelleonh0331/synervox-remoteov2` fijada por digest.

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
- el volumen `zynerdesk_data` y `zynerdesk_mysql` persisten entre
  actualizaciones (`zynerdesk.sh up` no usa `--force-recreate` como `init`);
- el puerto del host queda enlazado solo a `127.0.0.1`;
- las migraciones son idempotentes (tabla `schema_migrations` propia del
  upstream).

## Prohibiciones

Este módulo no debe:

- modificar otros módulos;
- acceder a datos ajenos sin pasar por su contrato;
- romper compatibilidad sin una decisión registrada;
- incorporar el instalador `.exe` del agente Windows (pendiente, fuera de
  alcance de esta etapa).

## Cambios de contrato

Cualquier cambio en este archivo debe:

1. escalar a `ARCHITECT_AGENT`;
2. registrarse en `docs/DECISIONS.md` (`repo_agent.py log-decision`);
3. registrar el impacto en `docs/CHANGELOG_AGENT.md` (`repo_agent.py log-change`).
