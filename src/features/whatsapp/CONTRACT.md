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
- Variables generadas en `whatsapp/.env`; nunca se versionan.

## Salidas públicas

- Ruta `/zynerwabav2/` servida por Apache hacia el puerto local elegido.
- Servicios Compose `app` y `db`, nombrados y aislados por `COMPOSE_PROJECT_NAME`.
- Volúmenes `whatsapp_data` y `whatsapp_mysql`, prefijados por el proyecto Compose.
- Credenciales consultables localmente mediante `credentials`.

## Errores posibles

- Docker, Compose, curl o Apache ausentes.
- Ningún puerto libre entre 3022 y 3099.
- Imagen o base no saludables.
- `apache2ctl configtest` inválido; en ese caso no se recarga Apache.
- Firma SSO inválida, vencida o sin secreto local: HTTP 403.

## Dependencias permitidas

- Contrato de autenticación Zynervox mediante `Includes\Auth`.
- Imagen integrada `miguelleonh0331/zynerwabav2:2.1.0-zynervox`, construida
  sobre `miguelleonh0331/zynerwabav2:2.0.0` fijada por digest.
- MySQL 8.4 y el esquema saneado versionado.
- Apache como proxy de la ruta pública.

## Dependencias prohibidas

- Tablas internas de Zynerwaba desde PHP Zynervox.
- Base `asterisk` para datos WhatsApp.
- Contraseñas compartidas o publicadas; SSO usa un secreto de instalación independiente.
- Imágenes móviles sin etiqueta y digest.

## Garantías

- Bases, credenciales, puertos y volúmenes permanecen separados.
- La imagen se verifica por digest.
- Los secretos se generan con `openssl` y quedan fuera de Git.
- `down` conserva persistencia.
- Zynervox no recibe contraseñas Zynerwaba ni accede a sus tablas.
- La identidad se crea/actualiza en Zynerwaba solo tras validar HMAC-SHA256 y expiración.

## Cambios de contrato

Todo cambio requiere `ARCHITECT_AGENT`, una decisión en `docs/DECISIONS.md` y una
entrada en `docs/CHANGELOG_AGENT.md`.
