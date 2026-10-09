# CONTRACT.md - stt_providers

## Responsabilidad contractual

Exponer a administradores Zynervox el CRUD, asignación, verificación y prueba de
cuentas/API keys STT sobre un esquema MariaDB exclusivo.

## Entradas y salidas públicas

- Página `modules/admin/stt_providers.php` para usuarios nivel 9.
- Acciones JSON y multipart documentadas por el módulo upstream.
- UI nativa dentro del panel y JSON con claves siempre enmascaradas.
- Cuatro tablas propias en la base configurada fuera de Git.

## Dependencias permitidas

- core

Infraestructura externa permitida: PHP 7.4+ con PDO MySQL, cURL, JSON y sesiones,
y MariaDB/MySQL con usuario limitado a su base.

## Garantías

- UI y API rechazan solicitudes sin sesión administrativa nivel 9.
- Toda mutación exige CSRF.
- No se versionan ni imprimen API keys o credenciales reales.
- No se modifican tablas VICIdial ni la base de WhatsApp.

## Prohibiciones

- No usar la base productiva `asterisk` para pruebas.
- No compartir credenciales con Farm o WhatsApp.

## Cambios de contrato

Escalar al `ARCHITECT_AGENT` y registrar decisión y cambio con Zonic.
