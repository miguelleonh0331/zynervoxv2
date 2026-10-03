# Módulo: automatizaciones

## Propósito
Automation suite: flujos automatizados, API pública con claves, webhooks firmados de
salida, conectores, WhatsApp Flows, base de conocimiento por empresa, asistencia con IA,
resúmenes, clasificación y transcripción de audio.

## Alcance
- Incluye: motor de flujos, API pública (claves con hash + rate limit), webhooks de
  salida firmados, conectores con bloqueo de destinos privados tras resolución DNS,
  conocimiento y Flows, configuración Groq/IA por empresa.
- No incluye: transcripción bajo demanda de la bandeja (vista de conversación), que
  pertenece a `conversaciones`, ni reglas automáticas simples (`conversaciones`).

## Estructura
- `api/`: rutas de flujos, claves, conectores, Flows, conocimiento, IA.
- `services/`: motor de flujos, llamadas IA, validación de destinos.
- `models/`: acceso a tablas del suite de automatización.
- `tests/`: pruebas del módulo.

## Decisiones heredadas
- Las acciones externas facturables (Meta Flow, IA, correo) requieren credenciales por
  empresa y nunca se ejecutan sin configuración explícita.
- Las claves de API pública se almacenan solo con hash; rate limit por clave.
- SSRF: bloqueo de destinos privados tras resolver DNS en cada llamada saliente.
- Los eventos de IA/automatización se emiten a salas de la empresa correspondiente.
