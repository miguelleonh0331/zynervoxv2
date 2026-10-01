# Módulo: whatsapp

## Propósito

Incorporar la operación WhatsApp a Zynervox reutilizando Zynerwaba v2 como servicio
independiente, sin mezclar su base MySQL con `asterisk` ni duplicar su lógica.

## Responsabilidad

- Mostrar el acceso WhatsApp dentro de la navegación Zynervox.
- Desplegar Zynerwaba y MySQL mediante `whatsapp/compose.yml`.
- Generar credenciales locales y publicar el servicio por proxy Apache.
- Verificar imagen, salud, login, sesión y persistencia.
- Mantener el límite entre identidad Zynervox e identidad Zynerwaba.

## No responsabilidad

- Implementar WhatsApp Cloud API dentro de PHP.
- Leer o escribir directamente las tablas internas de Zynerwaba.
- Compartir credenciales o bases con VICIdial.
- Prometer SSO hasta que Zynerwaba exponga un contrato de intercambio de identidad.

## Entradas operativas

- `app/web/modules/admin/whatsapp.php`: portal integrado.
- `installer/whatsapp.sh`: ciclo de vida del stack.
- `whatsapp/compose.yml`: servicios y persistencia.
- `whatsapp/overrides/`: correcciones de compatibilidad versionadas sobre la imagen fijada.
- `/zynerwabav2/`: ruta pública proxificada.

## Dependencias principales

- Zynerwaba `2.0.0` fijada por digest.
- MySQL `8.4` con volumen independiente.
- Apache `proxy`, `proxy_http` y `headers`.
- Autenticación Zynervox para niveles 7, 8 y 9.

## Casos principales

- Administrar empresas, usuarios y líneas WhatsApp.
- Recibir y enviar mensajes desde la bandeja Zynerwaba.
- Gestionar plantillas, campañas y estados de entrega.
- Conservar datos tras recrear contenedores.

## Estado

Integración nativa administrativa: Zynervox presenta empresas, usuarios y líneas,
consume la API de Zynerwaba y establece sesión mediante claims HMAC de corta duración.
Zynerwaba permanece como motor Docker aislado y fuente de verdad de WhatsApp.
El superadministrador dispone además de una vista `Empresas` para crear tenants,
administradores, números y credenciales Meta sin abandonar Zynervox.
Conversaciones, contactos, campañas, listas y envíos pertenecen al futuro módulo
separado de Operaciones WhatsApp y no aparecen en este panel administrativo.

## Pruebas

- `cloud-peru`, ruta `/var/www/html/zynervoxv2-whatsapp-test`.
- Imagen, MySQL, 63 tablas, proxy, HTTP LAN y Socket.IO verificados.
- Login Zynervox y Zynerwaba, sesión tras reinicio, backup y restauración: OK.
- Envío/recepción Meta pendiente de configurar credenciales y líneas de prueba.

El smoke test reproducible usa las credenciales locales sin mostrarlas:

```bash
sudo WHATSAPP_TEST_PROXY_URL=http://127.0.0.1/zynerwabav2 \
  WHATSAPP_TEST_RESTART=1 \
  ./src/features/whatsapp/tests/smoke.sh
```

`WHATSAPP_TEST_RESTART=1` comprueba persistencia de sesión recreando solo el proceso
de la aplicación; omitirlo para una verificación no disruptiva. La prueba no sustituye
el E2E con Meta, que necesita una empresa, línea y destinatario exclusivos de laboratorio.
El procedimiento y la evidencia obligatoria están en `tests/META_E2E.md`.

`installer/whatsapp.sh init` sincroniza la contraseña del superadministrador con el
`.env` incluso cuando se reutiliza un volumen MySQL. El override de `Empresas`
adapta usuarios y líneas al contrato `/api/empresas/:id/...` del backend 2.0.0.
Los overrides de la bandeja y gestión consumen `/api/my-lines`, que respeta el
contexto de empresa del administrador sin exigir privilegios de superadministrador.
El override backend de `empresas` aplica `requireSuperadmin` por ruta, evitando que
su router global intercepte `/api/my-lines` y los módulos registrados después.
También publica `/api/sso/zynervox`; el secreto se genera durante `init`, se entrega
al contenedor por entorno y a PHP mediante `/etc/zynervox/whatsapp.conf` con permisos restringidos.
