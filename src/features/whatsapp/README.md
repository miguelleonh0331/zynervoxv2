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

Primera integración: portal y despliegue reproducible con sesiones separadas.
Siguiente incremento: SSO/API de identidad y vista omnicanal nativa en Zynervox.

## Pruebas

- `cloud-peru`, ruta `/var/www/html/zynervoxv2-whatsapp-test`.
- Imagen, MySQL, 63 tablas, proxy, HTTP LAN y Socket.IO verificados.
- Login Zynervox y Zynerwaba, sesión tras reinicio, backup y restauración: OK.
- Envío/recepción Meta pendiente de configurar credenciales y líneas de prueba.
