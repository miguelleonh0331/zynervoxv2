# Continuidad del motor de marcación — mirmidon

Actualizado: 2026-10-11. Desarrollo completado para SIP; manual, progresivo y
predictivo probados con SIP/RTP real en loopback. La certificación de la troncal
externa y del softphone del usuario corresponde a la próxima prueba controlada.

## Rutas y forma de trabajo

- Fuente Windows: `E:\servidores\zynerdesk\proyectos\zynervoxv2`, rama `main`.
- SMB: `//100.70.0.45/zynervoxv2` → `/mnt/zynervoxv2-fuente` en el VPS.
- Runtime web: `/srv/www/htdocs/zynervoxv2`, copia independiente del SMB.
- Artefactos generados: `/var/lib/zynervoxv2/asterisk/modules/asterisk`.
- Base exclusiva: `zynervox_core`; datos del motor en `v2_dialer_*`.
- Grabaciones: `/var/spool/asterisk/monitor/zynervoxv2/dialer/call-ID.wav`.
- Worker: `/etc/systemd/system/zynervox-dialer.service`, usuario `wwwrun:www`.
- Editar local, probar desde SMB, desplegar únicamente archivos revisados con
  backup y comparación de contenido; reiniciar el worker cuando cambie PHP.
  Después de validar el despliegue, commit y push selectivos a `origin/main`.
- Generar configuración Asterisk por las APIs del core; no editarla a mano.
  Preservar y excluir el cambio ajeno `app/web/bot_ivr/ivr_engine_agi.php`.

## Fases y checklist de implementación

- [x] Fase 1: esquema, CRUD administrativo, listas, disposiciones y asignación.
- [x] Fase 2: origen SIP a troncal real; callback solo tras respuesta, no Answer
  local simulado. Timeout, correlación y parámetros de AMI validados.
- [x] Fase 3: reserva transaccional, capacidad dura, intentos, DNC, horarios
  locales y llamadas largas; RATIO conserva nivel fijo.
- [x] Fase 4: panel de agente, login/pausa/salida idempotentes, cola personal,
  WRAPUP automático, primera tipificación observada y reintento con demora.
- [x] Fase 5: AMD HUMAN/MACHINE sobre RTP, MESSAGE con audio existente,
  abandono, CONNECT real, MixMonitor posrespuesta y cierres idempotentes.
- [x] Fase 6: ADAPTIVE, muestra mínima, denominador humano, histéresis,
  límites y cadencia. PROGRESSIVE explícito con un agente reservado por intento;
  MANUAL solamente por acción del agente. Reserva global entre campañas.
- [x] Fase 7: reporte por campaña/fechas inclusivas, KPIs, abandono por nivel,
  ocupación con intervalos observados y N/D para cobertura histórica incompleta.
- [x] Operación: RUNNING/STOPPED, heartbeat/error, servicio persistente,
  reinicio supervisado, exclusión de workers duplicados y conciliación incluso
  detenido. Canal ausente contestado se recupera como PBX_LOST sin inventar AHT.
- [x] Migraciones 008–011 aplicadas; permisos aislados y AGI ejecutable.
- [x] Lint PHP 7.4, SQL temporal, permisos/CSRF HTTP y laboratorio SIP/RTP.
- [x] Despliegue selectivo desde SMB con backups y comparación de fuente/runtime.
- [x] Reporterías publicadas en `72dabc8`; cierre del motor versionado y publicado
  en `origin/main` (consultar `git log -1 --oneline` para su hash).

El estado anterior del checklist queda sustituido por estas pruebas. Las fases
están completas como desarrollo y laboratorio SIP; no equivalen a una prueba
con clientes, proveedor comercial, NAT externo o MicroSIP físico.

## Pruebas reproducibles

Ejecutar en mirmidon, desde fuente montada:

```bash
bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-engine.sh
bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-reporting.sh
bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-sip-lab.sh
DIALER_LAB_CONFIRM=SERVICE_RESTART bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-service.sh
```

Los dos primeros usan tablas TEMPORARY por conexión. SIP y reinicio requieren
PBX inicialmente sin llamadas; usar en laboratorio. SIP crea troncal, agente y
campañas exclusivos, llama solo a loopback, limpia fixtures y conserva auditoría
más WAV/log en `/var/backups/zynervoxv2/dialer-sip-lab-*`.

Última prueba completa SIP: llamadas 200–206; MANUAL 200, PROGRESSIVE 201,
ADAPTIVE 202 atendidas, HUMAN, CONNECT y grabación real; MACHINE 203,
NOANSWER 204, HANGUP 205, overflow/MESSAGE 206. También pasó recuperación de
canal contestado ausente. Las filas de laboratorio se eliminaron al finalizar.
Evidencia: `/var/backups/zynervoxv2/dialer-sip-lab-20261011-131050`.
Verificación final: montaje presente, servicio activo/habilitado, 0 llamadas
abiertas, 0 canales activos y ausencia de anexo/troncal/campañas del laboratorio.
La prueba de reinicio conserva el inode del bloqueo; un segundo worker sale
con código 1. `RuntimeDirectoryPreserve=yes` evita perder el bloqueo al reintentar.

Backups del despliegue: `/var/backups/zynervoxv2/dialer-engine-*`,
`dialer-service-*`, `Dialer-*.php`; reportería inicial
`dialer-reporting-20261011-070933`. Verificar versión publicada con
`git log -1 --oneline` y `git rev-parse HEAD origin/main`.

## Retomar y próxima prueba

1. Leer este archivo, `src/features/dialer/README.md` y `CONTRACT.md`.
2. Verificar montaje, servicio activo/habilitado, campañas/errores y canales
   existentes antes de iniciar un nuevo laboratorio.
3. Usar solo SIP, según decisión del usuario. La configuración raíz PJSIP fue
   restaurada a su copia original; no se activan includes ni transportes nuevos.
4. Cuando llegue la lista del usuario, cargar un CSV `numero,nombre`, números
   con código de país, en campaña separada. Validar la ruta de la troncal SIP y
   el anexo conectado. Empezar con pocos destinos y concurrencia máxima 1.
5. Comprobar audio en ambos sentidos, Caller ID, grabación y tipificación;
   después ampliar y observar abandono/nivel predictivo.

Para detener nuevas llamadas: botón Detener en Configurar; las llamadas abiertas
terminan normalmente. Consultar `journalctl -u zynervox-dialer` y el reporte.
Rollback de código por `git revert` del commit correspondiente y despliegue
selectivo con backup; no revertir migraciones borrando datos operativos.
