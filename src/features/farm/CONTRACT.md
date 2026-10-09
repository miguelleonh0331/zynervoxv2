# CONTRACT.md - farm

## Responsabilidad contractual

Exponer a administradores Zynervox la gestión autenticada de anexos SIP,
workers TTS y proxies mediante servicios systemd exclusivos en loopback.

## Entradas y salidas públicas

- Página `modules/admin/farm.php` para usuarios nivel 9.
- API de anexos y gateway de control con acciones enumeradas y CSRF.
- JSON de estado u operación sin revelar tokens internos.
- Auditoría append-only asociada al usuario Zynervox.

## Dependencias permitidas

- core

Infraestructura externa permitida: servicios systemd de la instancia Farm,
Python 3.10+, baresip, ffmpeg y puertos loopback exclusivos.

## Garantías

- Ninguna ruta Farm funciona sin sesión administrativa nivel 9.
- Los tokens viven fuera de Git y nunca llegan al navegador.
- Cada instalación usa nombres, rutas y datos propios.
- PBX y TTS externos permanecen deshabilitados por defecto.

## Prohibiciones

- No consultar tablas o contenedores de WhatsApp.
- No reutilizar servicios, datos o secretos productivos.
- No habilitar SIP/TTS externos durante un smoke test.

## Cambios de contrato

Escalar al `ARCHITECT_AGENT` y registrar decisión y cambio con Zonic.
