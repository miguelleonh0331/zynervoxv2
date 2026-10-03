# Módulo: conversaciones

## Propósito
Núcleo operativo: bandeja de contactos y mensajes, asignación a agentes, etapas
(Nuevas/Sin responder/En curso/En espera/SLA vencido/Pospuestas/Cerradas), notas
internas con menciones, autoanswer, bajas (optout), posposición, indicador de
escritura, búsqueda, transcripciones y tiempo real por Socket.IO.

## Alcance
- Incluye: contactos, mensajes, media de mensajes, quick_replies, carpetas y etiquetas,
  respuestas automáticas, assignment_reservations/audit, llamadas, estados y SLA,
  autoanswer y NO INTERESADO, transcripción Groq.
- No incluye: envío por plantillas masivas (`broadcasts`), ni llamadas a Graph API
  para enviar mensajes (`whatsapp`).

## Estructura
- `api/`: rutas de contactos, mensajes, notas, etiquetas, carpetas, asignación, SLA.
- `services/`: clasificación de mensajes, estados, SLA laboral, round-robin, presencia.
- `models/`: consultas a `contacts`, `messages`, tablas asociadas.
- `tests/`: pruebas del módulo.

## Decisiones heredadas
- El primer entrante de texto >10 palabras por contacto es `autoanswer` (máx. 1 por
  contacto, persistente, idempotente al historial): no genera alertas ni cuenta como
  respuesta humana para SLA.
- `No me interesa` / `No estoy interesado(a)` → etiqueta NO INTERESADO, cierra la
  conversación y registra baja para futuros envíos.
- Conversaciones `closed` nunca generan `waiting_since`.
- Etapas mutuamente excluyentes; `Todos` y `Mías` son vistas, no etapas.
- La bandeja muestra hora para hoy, `Ayer HH:mm` para ayer y fecha completa antes.
- `waiting_since` usa el último entrante humano posterior a la última salida humana.
- Media nuevo se guarda en `data/media/<empresa_id>/` y se autoriza contra la empresa.
- Transcripciones por mensaje (evita cobros Groq repetidos), API Key cifrada por empresa.
