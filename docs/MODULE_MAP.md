# MODULE_MAP.md

Archivo autogenerado por `repo_agent.py map`. No editar a mano.

Última generación: 2026-09-30 21:18

## Módulos

| Módulo | Ruta | Agente | Propósito | Contrato |
|---|---|---|---|---|
| admin | src/features/admin | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/admin/CONTRACT.md |
| agent | src/features/agent | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/agent/CONTRACT.md |
| bot_ivr | src/features/bot_ivr | bot_ivr_AGENT.md | (sin descripción) | src/features/bot_ivr/CONTRACT.md |
| core | src/features/core | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/core/CONTRACT.md |
| installer | src/features/installer | installer_AGENT.md | (sin descripción) | src/features/installer/CONTRACT.md |
| ivr_builder | src/features/ivr_builder | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/ivr_builder/CONTRACT.md |
| reporting | src/features/reporting | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/reporting/CONTRACT.md |
| telephony | src/features/telephony | telephony_AGENT.md | (sin descripción) | src/features/telephony/CONTRACT.md |
| whatsapp | src/features/whatsapp | whatsapp_AGENT.md | Incorporar la operación WhatsApp a Zynervox reutilizando Zynerwaba v2 como servicio | src/features/whatsapp/CONTRACT.md |

## Dependencias entre módulos

| Módulo | Depende de | Estado |
|---|---|---|
| whatsapp | Contrato de autenticación Zynervox mediante `Includes\Auth` | módulo inexistente |
| whatsapp | Imagen `miguelleonh0331/zynerwabav2:2.0.0` y digest publicado | módulo inexistente |
| whatsapp | MySQL 8.4 y el esquema saneado versionado | módulo inexistente |
| whatsapp | Apache como proxy de la ruta pública | módulo inexistente |
