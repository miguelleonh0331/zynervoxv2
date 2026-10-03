# Contrato: whatsapp

## Responsabilidad contractual

Proveer un acceso estable desde Zynervox a Zynerwaba y administrar su despliegue
aislado, reproducible y reversible.

## Entradas públicas

- `GET modules/admin/whatsapp.php`: interfaz nativa exclusivamente administrativa; requiere sesión Zynervox y nivel 7, 8 o 9.
- `POST /api/sso/zynervox`: intercambia claims firmados de 60 segundos por una sesión Zynerwaba.
- `GET|POST|PATCH|DELETE /api/users`: administra operadores y supervisores dentro de la empresa efectiva.
- `POST|DELETE /api/broadcast-lists`: administra listas simples de teléfonos por empresa.
- `GET /api/broadcasts`: consulta envíos y su progreso dentro de la empresa efectiva.
- La vista `Empresas` solo aparece para `superadmin` y consume el CRUD `/api/empresas/:id/...`.
- Un `admin` de empresa solo ve `Usuarios` y `Líneas`; las funciones operativas no se presentan en esta ruta.
- `installer/whatsapp.sh init|up|status|credentials|install-proxy|remove-proxy|backup|restore|down`.
- Variables generadas en `/etc/zynervox/zynervox-whatsapp.env`; nunca se versionan.

## Salidas públicas

- Ruta `/zynerwabav2/` servida por Apache hacia el puerto local elegido (`127.0.0.1:<puerto>`).
- Servicio systemd `zynervox-whatsapp.service` (proceso Node nativo), usuario de
  sistema dedicado `zynervox-whatsapp`, sin shell.
- Base de datos `zynerwabav2` en el MySQL nativo del host, usuario propio con
  `GRANT` limitado a esa base. Nunca comparte motor con `asterisk` ni `zynervox_stt`.
- Directorio de adjuntos en `/var/lib/zynervox-whatsapp/data/media`, enlazado desde
  la ruta que el código vendorizado espera (ver `README.md`, limitación `DB_PATH_MEDIA`).
- Credenciales consultables localmente mediante `credentials`.

## Errores posibles

- Node (≥18), npm, mysql, openssl o Apache ausentes.
- Ningún puerto libre en el rango asignado.
- `systemctl is-active zynervox-whatsapp` distinto de `active`.
- `apache2ctl configtest` inválido; en ese caso no se recarga Apache.
- Firma SSO inválida, vencida o sin secreto local: HTTP 403.
- Fallo de permisos entre el usuario de servicio y el directorio de adjuntos si el
  enlace a `/var/lib/zynervox-whatsapp` no existe o `ProtectSystem=strict` lo bloquea.

## Dependencias permitidas

- Contrato de autenticación Zynervox mediante `Includes\Auth`.
- Código vendorizado en `src/features/whatsapp/vendor/`, origen documentado en
  `README.md` (extraído de `miguelleonh0331/zynerwabav2:2.1.0-zynervox`,
  trazabilidad por commit de Git en vez de digest de imagen).
- Node.js nativo ≥18 administrado por systemd (mismo patrón que `farm`).
- MySQL nativo del host, base y usuario propios (mismo patrón que `stt_providers`).
- Apache como proxy de la ruta pública.

## Dependencias prohibidas

- Docker, Compose o cualquier contenedor para este módulo.
- Tablas internas de Zynerwaba desde PHP Zynervox.
- Base `asterisk` ni `zynervox_stt` para datos WhatsApp.
- Contraseñas compartidas o publicadas; SSO usa un secreto de instalación independiente.
- Código de `vendor/` editado a mano sin volver a extraer/documentar su origen.

## Garantías

- Bases, credenciales, puertos y directorios de datos permanecen separados.
- El código vendorizado queda trazado por commit de Git, no por digest de imagen.
- Los secretos se generan con `openssl` y quedan fuera de Git.
- `down`/`systemctl stop` conserva persistencia (BD y adjuntos fuera de `/opt`).
- Zynervox no recibe contraseñas Zynerwaba ni accede a sus tablas.
- La identidad se crea/actualiza en Zynerwaba solo tras validar HMAC-SHA256 y expiración.

## Cambios de contrato

Todo cambio requiere `ARCHITECT_AGENT`, una decisión en `docs/DECISIONS.md` y una
entrada en `docs/CHANGELOG_AGENT.md`.
