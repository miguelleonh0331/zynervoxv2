# MODULE_MAP.md

Archivo autogenerado por `repo_agent.py map`. No editar a mano.

Última generación: 2026-10-01 18:44

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

## Dependencias entre módulos

| Módulo | Depende de | Estado |
|---|---|---|
| farm | Sesión administrativa de `Includes\Auth` | módulo inexistente |
| farm | Servicios systemd de la instancia Farm | módulo inexistente |
| farm | Python 3.10+, baresip, ffmpeg y puertos loopback exclusivos | módulo inexistente |
| stt_providers | Sesión administrativa de `Includes\Auth` | módulo inexistente |
| stt_providers | PHP 7.4+ con PDO MySQL, cURL, JSON y sesiones | módulo inexistente |
| stt_providers | MariaDB/MySQL con usuario limitado a su base | módulo inexistente |
| whatsapp | Contrato de autenticación Zynervox mediante `Includes\Auth` | módulo inexistente |
| whatsapp | Imagen integrada `miguelleonh0331/zynerwabav2:2.1.0-zynervox`, construida | módulo inexistente |
| whatsapp | MySQL 8.4 y el esquema saneado versionado | módulo inexistente |
| whatsapp | Apache como proxy de la ruta pública | módulo inexistente |
