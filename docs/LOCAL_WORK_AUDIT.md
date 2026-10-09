# Consolidacion de pendientes locales de v2

Fecha: 2026-10-09.

La fuente oficial conserva los cambios locales del agente: marca Zynervox,
favicon, CRM de demostracion y panel WhatsApp integrado. WhatsApp carga su ruta
publica relativa y usa la autenticacion existente. Se elimina el intento de
auto-login con contrasena compartida y el cierre forzado de otra sesion.

Los contratos de farm, stt_providers, whatsapp y zynerdesk separan dependencias
modulares de infraestructura externa. La dependencia modular es core; los
requisitos de servicios y aislamiento no cambian. ARCHITECT_AGENT aprobo esta
normalizacion y el alcance de autenticacion del agente.

## Archivos de trabajo local

RESPUESTA.md, .claude, .codex-temp y claude-permissions.patch son artefactos de
trabajo, no componentes de la distribucion. Se conservan localmente y se
excluyen de Git. No se eliminaron archivos ni ramas.

El manual MANUAL_GATEWAY_TTS_TEMPORAL.md describe un gateway operativo temporal
con datos de acceso; se conserva en el respaldo local y no se publica como
documentacion portable del producto. La ubicacion de secretos y la configuracion
del servicio deben consultarse en el entorno correspondiente.

Los cinco archivos diferentes del espejo .codex-temp/bot-ivr-update/bot_ivr
solo reemplazan rutas synervox por rutas de la instancia zynervoxv2205:
audio_lab_service.php, db.php, launch_campaign.php,
launch_campaign_prebuild.php y tts_jobs_api.php. No se trasladan estas rutas
especificas a la fuente distribuida. Los otros 21 archivos coinciden con el
codigo versionado del worktree.

## Historial de pruebas

Las ramas trabajo/core y trabajo/telephony contienen seis commits de pruebas y
sus reversiones. Cada secuencia termina con el mismo contenido previo a las
pruebas. Se conserva y publica ese historial en sus ramas; no se incorpora como
funcionalidad de main.

## Respaldo y despliegue

Se verifico un respaldo local completo de la fuente, un bundle del historial
Git y archivos pendientes de los worktrees mediante lectura de archivos y SHA256.
Ubicacion: E:\backups\zynervoxv2-20261009-102107.

La integracion corresponde a v2. No incorpora v3 ni ejecuta instaladores,
migraciones de bases de datos o cambios de servicios productivos. La carpeta
SMB montada en mirmidon es la misma fuente Windows; actualizar Git no equivale
a desplegar sus archivos en la instalacion productiva.

## Integracion y validacion final

Se integran a main la rama del agente, trabajo/farm y trabajo/bot_ivr.
trabajo/ivr_builder y trabajo/zynerdesk ya estaban integradas en origin/main.
Los esquemas SQL de bot_ivr y deteccion quedan versionados sin ejecutarse.
La colision documental ADR-0018 se resuelve conservando la decision publicada
de Zynerdesk y asignando ADR-0019 a la nueva consolidacion. Se conservan completas
las contribuciones append-only de las ramas y se regenera MODULE_MAP.

Validacion: 32 archivos PHP pasan lint; annexes.js pasa node --check;
audio_lab_generate.py, runtime_store.py y zypad_annex pasan compilacion;
las cinco pruebas aisladas del adaptador RGA pasan; la funcion WhatsApp pasa
comprobaciones de ruta relativa, carga unica, ausencia de credenciales y
ausencia de logout forzado. repo_agent.py validate y git diff --check pasan.

No se ejecutan campaigns-db.php, list-import-db.php, list-flow-db.php ni
connection-db.php: requieren una base y configuracion de secretos aisladas
que no estan disponibles en este entorno. No se ejecuta la generacion real
de audio-lab.php: requiere rutas, venv Linux y generacion gTTS de la instalacion.
Estas validaciones de integracion siguen pendientes antes de desplegar;
no se utilizo ninguna base productiva para suplirlas.
