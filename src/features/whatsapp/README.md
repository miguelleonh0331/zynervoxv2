# Módulo: whatsapp

## Propósito

Incorporar la operación WhatsApp a Zynervox reutilizando Zynerwaba v2 como servicio
independiente, sin mezclar su base MySQL con `asterisk` ni duplicar su lógica.

## Responsabilidad

- Mostrar el acceso WhatsApp dentro de la navegación Zynervox.
- Desplegar Zynerwaba como servicio nativo (`systemd` + MySQL del host).
- Generar credenciales locales y publicar el servicio por proxy Apache.
- Verificar servicio, salud, login, sesión y persistencia.
- Mantener el límite entre identidad Zynervox e identidad Zynerwaba.

## No responsabilidad

- Implementar WhatsApp Cloud API dentro de PHP.
- Leer o escribir directamente las tablas internas de Zynerwaba.
- Compartir credenciales o bases con VICIdial.
- Prometer SSO hasta que Zynerwaba exponga un contrato de intercambio de identidad.

## Entradas operativas

- `app/web/modules/admin/whatsapp.php`: portal integrado.
- `installer/whatsapp.sh`: ciclo de vida nativo (`init|up|status|credentials|install-proxy|remove-proxy|backup|restore|down`).
- `src/features/whatsapp/vendor/`: código fuente desplegado, ver sección siguiente.
- `whatsapp/init/001-schema.sql`: esquema versionado, 63 tablas, idempotente.
- `/zynerwabav2/`: ruta pública proxificada.

## Código vendorizado (`vendor/`)

Origen: imagen `miguelleonh0331/zynerwabav2:2.1.0-zynervox`, extraída de `/app` el
2026-10-03. Esa imagen se construyó sobre
`miguelleonh0331/zynerwabav2:2.0.0@sha256:b6e3ac4ba9115435b151482c6d8c06fbbfe96a572c77cc1e83778c3e68e4c624`
aplicando los cuatro overrides de `whatsapp/overrides/`.

Antes de vendorizar se verificó, archivo por archivo, que el contenido de la imagen
coincide exactamente con `whatsapp/overrides/` normalizando CRLF: los overrides ya
estaban fusionados en la imagen, así que `vendor/` los contiene y
`whatsapp/overrides/` deja de ser necesario.

Qué se excluyó de la copia versionada:

- `node_modules/`: se regenera con `npm ci` desde `package-lock.json`.
- `data/`: adjuntos de runtime, nunca se versionan.

Hechos verificados sobre este código (2026-10-03):

- 81 archivos, ~2,3 MB. Cero dependencias nativas: ninguna que compilar
  (`bcryptjs`, no `bcrypt`).
- `src/shared/config.js` lee toda su configuración de variables de entorno, sin
  hosts, puertos ni rutas de base de datos escritos en el código.
- Pasa `node --check` completo con Node 22 nativo (`server.js`, `src/shared/*.js`
  y los ocho `src/features/*/index.js`).
- `POST /sso/zynervox` vive en `src/features/empresas/index.js` y lee
  `ZYNERVOX_SSO_SECRET` del entorno.

Limitación conocida: `package.json` declara los scripts `db:schema`, `repo:map` y
`repo:validate` apuntando a un directorio `scripts/` que la imagen no incluye; esos
tres scripts no funcionan en la copia vendorizada y no se usan en runtime.

Aviso para quien modifique `vendor/`: `src/shared/config.js` calcula
`DB_PATH_MEDIA` a partir de `__dirname`, no de una variable de entorno, así que el
directorio de adjuntos queda fijado a `<vendor>/data/media` relativo al código
instalado. El despliegue debe resolverlo con un enlace, no cambiando configuración.

## Dependencias principales

- Node.js nativo ≥18, administrado por `systemd` (mismo patrón que `farm`).
- MySQL nativo del host, base `zynerwabav2` y usuario propios (mismo patrón que `stt_providers`).
- Apache `proxy`, `proxy_http` y `headers`.
- Autenticación Zynervox para niveles 7, 8 y 9.

## Casos principales

- Administrar empresas, usuarios y líneas WhatsApp.
- Recibir y enviar mensajes desde la bandeja Zynerwaba.
- Gestionar plantillas, campañas y estados de entrega.
- Conservar datos tras reinstalar o reiniciar el servicio.

## Estado

Integración nativa administrativa: Zynervox presenta empresas, usuarios y líneas,
consume la API de Zynerwaba y establece sesión mediante claims HMAC de corta duración.
Zynerwaba corre como proceso Node nativo bajo `systemd`, fuente de verdad de WhatsApp,
sin Docker. El código vive versionado en `vendor/` (ver sección anterior); la
trazabilidad es por commit de Git, no por digest de imagen.
El superadministrador dispone además de una vista `Empresas` para crear tenants,
administradores, números y credenciales Meta sin abandonar Zynervox.
Conversaciones, contactos, campañas, listas y envíos pertenecen al futuro módulo
separado de Operaciones WhatsApp y no aparecen en este panel administrativo.

## Pruebas

- WSL (`zynervox-borrar`), ciclo completo migrado a nativo validado 2026-10-03.
- Servicio systemd, MySQL nativo, 63 tablas, proxy y login verificados tras borrar
  y reinstalar desde GitHub. Ver `docs/TAREA_WHATSAPP_NATIVO.md` §7 para el detalle
  de los 11 criterios de aceptación.
- `node src/features/whatsapp/tests/webhook-verify-token.test.js` reproduce la
  verificación GET de Meta con credenciales cifradas y evita regresiones en la
  resolución del módulo criptográfico.
- `node src/features/whatsapp/tests/webhook-late-handlers.test.js` verifica que
  el POST firmado resuelva los callbacks de conversaciones y salud aunque esos
  módulos se registren después de `whatsapp`.
- `node src/features/whatsapp/tests/inbox-legacy-backend-compat.test.js` evita
  que rutas opcionales ausentes aborten el arranque de la bandeja y comprueba
  que el envío de texto use el endpoint existente de mensajes del contacto.
- `node src/features/whatsapp/tests/conversaciones-timestamp.test.js` verifica
  que la fecha del evento entrante se propague hasta la inserción del mensaje.
- `node src/features/whatsapp/tests/conversaciones-realtime.test.js` verifica
  el refresco compatible de contactos y `message:new` en las salas de
  administradores y del propietario para actualizar la bandeja y sus alertas.
- Envío/recepción Meta pendiente de configurar credenciales y líneas de prueba.

El smoke test reproducible usa las credenciales locales sin mostrarlas:

```bash
sudo WHATSAPP_TEST_PROXY_URL=http://127.0.0.1/zynerwabav2 \
  WHATSAPP_TEST_RESTART=1 \
  ./src/features/whatsapp/tests/smoke.sh
```

`WHATSAPP_TEST_RESTART=1` comprueba persistencia de sesión reiniciando el servicio
systemd; omitirlo para una verificación no disruptiva. La prueba no sustituye
el E2E con Meta, que necesita una empresa, línea y destinatario exclusivos de laboratorio.
El procedimiento y la evidencia obligatoria están en `tests/META_E2E.md`.

`installer/whatsapp.sh init` sincroniza la contraseña del superadministrador contra
la base nativa en cada instalación. Las rutas `Empresas`, `/api/my-lines` y
`/api/sso/zynervox` llegaron ya integradas al vendorizar el código (antes vivían
como overrides sobre la imagen, verificados por hash antes de fusionarlos). El
secreto SSO se genera durante `init`, se entrega al proceso Node por
`EnvironmentFile` de systemd y a PHP mediante `/etc/zynervox/whatsapp.conf` con
permisos restringidos.
