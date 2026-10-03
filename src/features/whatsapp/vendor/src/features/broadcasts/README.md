# Módulo: broadcasts

## Propósito
Envíos masivos por plantilla: campañas, biblioteca de listas de contactos (.txt/.xlsx),
ejecución de envíos con control de estado, métricas por destinatario, bajas globales
(optout) y piloto ADB (envíos físicos desde dispositivos con token propio).

## Alcance
- Incluye: `campaigns`, `broadcasts` (envío de campaña a lista), `broadcast_recipients`
  con métricas (delivered/read/replied vía webhook), `broadcast_lists`,
  `broadcast_optouts`, piloto ADB (`adb_devices/campaigns/jobs`), pausa/reanudación,
  protección por salud roja cuando `salud_corte_automatico=1`.
- No incluye: el envío individual conversacional ni la plantilla en sí (`whatsapp`).

## Estructura
- `api/`: rutas de campañas, envíos, listas, optouts, ADB.
- `services/`: motor de ejecución de envíos, colas ADB, integración con salud.
- `models/`: acceso a las tablas del módulo.
- `tests/`: pruebas del módulo.

## Vista
`views/broadcast.html` (campañas y envíos) y `views/adb-pilot/index.html` (gestión ADB).

## Decisiones heredadas
- Un destinatario en `broadcast_optouts` (por `phone + empresa_id`) queda excluido de
  TODO envío futuro; las bajas también nacen de respuestas NO INTERESADO en conversaciones.
- Los envíos a medio ejecutar se marcan `paused` al reiniciar el servicio (nunca fantasma
  `running`): el admin decide reanudar.
- Métricas reales por destinatario ligadas por `wa_message_id` desde el webhook.
- `broadcast-stats` califica columnas agregadas para evitar `status` ambiguo (bug
  corregido en el original tras el JOIN multiempresa).
- ADB: cada dispositivo tiene token con hash y consume exclusivamente su cola.
