# MODULE_MAP.md

Archivo autogenerado por `repo_agent.py map`. No editar a mano.

Última generación: 2026-10-09 13:43

## Módulos

| Módulo | Ruta | Agente | Propósito | Contrato |
|---|---|---|---|---|
| admin | src/features/admin | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/admin/CONTRACT.md |
| agent | src/features/agent | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/agent/CONTRACT.md |
| bot_ivr | src/features/bot_ivr | bot_ivr_AGENT.md | (sin descripción) | src/features/bot_ivr/CONTRACT.md |
| core | src/features/core | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/core/CONTRACT.md |
| farm | src/features/farm | farm_AGENT.md | (sin descripción) | src/features/farm/CONTRACT.md |
| installer | src/features/installer | installer_AGENT.md | (sin descripción) | src/features/installer/CONTRACT.md |
| ivr_builder | src/features/ivr_builder | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/ivr_builder/CONTRACT.md |
| reporting | src/features/reporting | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/reporting/CONTRACT.md |
| stt_providers | src/features/stt_providers | _DEFAULT_MODULE_AGENT.md | (sin descripción) | src/features/stt_providers/CONTRACT.md |
| telephony | src/features/telephony | telephony_AGENT.md | (sin descripción) | src/features/telephony/CONTRACT.md |
| whatsapp | src/features/whatsapp | whatsapp_AGENT.md | Incorporar la operación WhatsApp a Zynervox reutilizando Zynerwaba v2 como servicio | src/features/whatsapp/CONTRACT.md |
| zynerdesk | src/features/zynerdesk | zynerdesk_AGENT.md | Incorporar Synervox Remoteo (supervisión remota de agentes: WebRTC, telemetría | src/features/zynerdesk/CONTRACT.md |
| zynervox_queries | src/features/zynervox_queries | _DEFAULT_MODULE_AGENT.md | Centralizar conexión PHP/PDO y consultas por motor y módulo, con contratos de repositorio. | src/features/zynervox_queries/CONTRACT.md |

## Dependencias entre módulos

| Módulo | Depende de | Estado |
|---|---|---|
| farm | core | ok |
| stt_providers | core | ok |
| whatsapp | core | ok |
| zynerdesk | core | ok |
| zynervox_queries | PHP 7.4+ y PDO MySQL; esquema administrativo nuevo de Bot IVR según su contrato | módulo inexistente |
