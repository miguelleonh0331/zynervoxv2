# Continuidad del motor de marcación — mirmidon

Actualizado: 2026-10-11. Meta activa: completar el motor para agentes humanos,
incluyendo pruebas y publicación, conforme al handoff y al contrato `dialer`.

## Rutas y procedimiento

- Fuente: `E:\servidores\zynerdesk\proyectos\zynervoxv2`, rama `main`.
- SMB: `//100.70.0.45/zynervoxv2` → `/mnt/zynervoxv2-fuente`.
- Runtime web: `/srv/www/htdocs/zynervoxv2`, copia independiente del SMB.
- Editar local, probar en staging/fixtures temporales, copiar solo archivos
  revisados desde SMB con backup, verificar hashes y runtime, commit/push selectivo.
- Nunca transferir con SFTP ni editar artefactos Asterisk generados a mano.
- Datos: `zynervox_core`, exclusivamente `v2_dialer_*`.
- Cambios ajenos en `app/web/bot_ivr/ivr_engine_agi.php`: preservar y excluir.

## Checklist

- [x] Leer contrato, reglas y handoff; revisar cambios preexistentes.
- [x] Remontar SMB: usuario ejecutó `/root/zynervox-mount-source.sh`.
- [x] Verificar montaje CIFS y lectura de fuente; confirmar runtime separado.
- [x] Aprobar alcance Fase 7 con ARCHITECT_AGENT, sin nuevas dependencias.
- [x] Implementar reporte administrativo, fechas inclusivas y enlaces por campaña.
- [x] Corregir abandono por nivel: mismo denominador humano que el KPI general.
- [x] Corregir timestamp CONNECT; medir conversación/espera y primera tipificación.
- [x] Probar SQL sobre esquema real con tablas temporales y staging privado:
  conteos, fechas, nulos, campañas, CONNECT, disposiciones, wrapup y permisos de llamada.
- [x] Validar renderizado y acceso HTTP de reportería.
- [x] Desplegar archivos de reportería por SMB, backup y hashes.
- [ ] Commit/push revisado; anotar hash y verificación del remoto.
- [ ] Auditar originación real del worker contra la troncal de campaña.
- [ ] Verificar grabación post-answer: el origen actual no contiene MixMonitor.
- [ ] Completar/validar MANUAL, RATIO y ADAPTIVE de punta a punta.
- [ ] Resolver acciones MESSAGE sin audio configurado: no declarar implementadas.
- [ ] Probar cierre sin respuesta, colgado temprano, abandono, MACHINE y agente.
- [ ] Validar MicroSIP, audio, bridge, grabación y tipificación con laboratorio autorizado.
- [ ] Medir ocupación cuando exista tiempo logueado; mientras tanto N/D explícito.

## Hallazgos y límites

La inspección estática posterior al handoff muestra que el worker origina un
canal Local cuyo contexto solo ejecuta Answer/AMD/Queue. No hay selección de
troncal ni Dial al cliente en ese flujo. Las pruebas sintéticas anteriores no
demuestran marcación saliente de campañas. Se escaló al ARCHITECT_AGENT antes
de extender la implementación. No declarar completo el motor por haber
completado únicamente los KPIs.

La ocupación no dispone de denominador de tiempo logueado. El reporte muestra
N/D; AHT y espera usan solo muestras con duraciones observadas, sin fabricar
ceros para el histórico. Conversión utiliza las marcas del catálogo actual.

Pendiente del usuario: anexo MicroSIP conectado y destino de laboratorio
autorizado para la prueba humana. No llamar a clientes reales.

Backup reportería: `/var/backups/zynervoxv2/dialer-reporting-20261011-070933`.
Prueba reproducible en mirmidon: `bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-reporting.sh`.
