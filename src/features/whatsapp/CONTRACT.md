# Contrato: whatsapp

## Responsabilidad contractual

Proveer un acceso estable desde Zynervox a Zynerwaba y administrar su despliegue
aislado, reproducible y reversible.

## Entradas públicas

- `GET modules/admin/whatsapp.php`: requiere sesión Zynervox y nivel 7, 8 o 9.
- `installer/whatsapp.sh init|up|status|credentials|install-proxy|down`.
- Variables generadas en `whatsapp/.env`; nunca se versionan.

## Salidas públicas

- Ruta `/zynerwabav2/` servida por Apache hacia el puerto local elegido.
- Contenedores `zynervox-whatsapp-app` y `zynervox-whatsapp-db`.
- Volúmenes `zynervox_whatsapp_data` y `zynervox_whatsapp_mysql`.
- Credenciales consultables localmente mediante `credentials`.

## Errores posibles

- Docker, Compose, curl o Apache ausentes.
- Ningún puerto libre entre 3022 y 3099.
- Imagen o base no saludables.
- `apache2ctl configtest` inválido; en ese caso no se recarga Apache.
- Login independiente requerido mientras no exista SSO contractual.

## Dependencias permitidas

- Contrato de autenticación Zynervox mediante `Includes\Auth`.
- Imagen `miguelleonh0331/zynerwabav2:2.0.0` y digest publicado.
- MySQL 8.4 y el esquema saneado versionado.
- Apache como proxy de la ruta pública.

## Dependencias prohibidas

- Tablas internas de Zynerwaba desde PHP Zynervox.
- Base `asterisk` para datos WhatsApp.
- Credenciales compartidas o publicadas.
- Imágenes móviles sin etiqueta y digest.

## Garantías

- Bases, credenciales, puertos y volúmenes permanecen separados.
- La imagen se verifica por digest.
- Los secretos se generan con `openssl` y quedan fuera de Git.
- `down` conserva persistencia.
- El portal no evita la autenticación propia de Zynerwaba.

## Cambios de contrato

Todo cambio requiere `ARCHITECT_AGENT`, una decisión en `docs/DECISIONS.md` y una
entrada en `docs/CHANGELOG_AGENT.md`.
