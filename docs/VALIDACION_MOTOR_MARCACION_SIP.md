# Validación final del marcador SIP — mirmidon

Fecha: 2026-10-11. Desarrollo y laboratorio completados para MANUAL,
PROGRESSIVE y ADAPTIVE (predictivo). Rama `main` del repositorio
https://github.com/miguelleonh0331/zynervoxv2.

## Matriz de aceptación

| Comprobación | Manual | Progresivo | Predictivo |
| --- | --- | --- | --- |
| Diez agentes SIP y diez llamadas simultáneas | PASS | PASS | PASS |
| AMD HUMAN, puente real y conversación >15 s | 10/10 | 10/10 | 10/10 |
| Variables del cliente visibles durante la conversación | 10/10 | 10/10 | 10/10 |
| Audio RTP en ambos sentidos | PASS | PASS | PASS |
| WAV MixMonitor con las dos señales de audio | 10/10 | 10/10 | 10/10 |
| Lead CONTACTED y un intento por llamada completada | 10/10 | 10/10 | 10/10 |
| SALE / RETRY con demora de 30 min | 9 / 1 | 9 / 1 | 9 / 1 |
| Reporte: diez humanas, cero abandonos, nueve ventas | PASS | PASS | PASS |
| Ocupación con intervalos observados | PASS | PASS | PASS |
| Búsqueda y descarga autenticada en Apache | PASS | PASS | PASS |

Primera ejecución aprobada: campañas 44/45/46, llamadas 248–277.
Evidencia: `/var/backups/zynervoxv2/dialer-ten-agents-20261011-135424`.
Ocupación: 93.18%, 90.91%, 90.91%; AHT: 41, 40, 40 s.
Pico observado: diez llamadas y diez agentes distintos por modo.
JSON y WAV se conservan en el VPS; credenciales y datos de clientes no van a Git.

Repetición final del comando completo `run-ten-agents.sh`: PASS, campañas
51/52/53, llamadas 286–315. Evidencia:
`/var/backups/zynervoxv2/dialer-ten-agents-20261011-140644`.
Ocupación 92.50%, 93.02%, 93.02%; AHT 40.7, 40, 40 s.
También pasaron análisis de los treinta WAV y Apache real. Inventario posterior:
montaje presente, servicio activo/habilitado, fuente/runtime iguales, cero
canales, llamadas abiertas, campañas RUNNING, agentes/asignaciones de prueba y
troncal de laboratorio activa. Sesiones temporales propias revocadas.

El laboratorio predictivo usa nivel 1 para comprobar diez entregas simultáneas
sin provocar abandonos. Las pruebas del motor comprueban por separado subida,
bajada, muestra mínima, denominador humano, histéresis, cadencia y límites.
Esta muestra no certifica la sobreoferta ni la tasa de contacto de producción.

## Web y ficha del cliente

Entrada: `https://45.226.69.24.nip.io/zynervoxv2/`.
Campañas → Reporte → **Buscar grabaciones** permite filtrar fechas inclusivas,
teléfono y agente. Ejemplo:
`modules/admin/dialer_report.php?view=recordings&id=44`.
El mismo endpoint con `audio=ID` entrega el WAV autenticado, sin publicar rutas
del servidor. Se validaron descarga completa, Range 206, rango sufijo, rechazo
416 de rangos inválidos y rechazo de rutas ajenas. Paginación: cien registros.

Un navegador real reprodujo una grabación de 40.62 s y avanzó su posición;
el filtro `99000901` devolvió una sola fila. También mostró la ficha en vivo
con `id_contacto`, `nombre`, `monto`, `local`, `direccion`.
Capturas privadas: `.codex-temp/agent-live.png` y `recordings-web.png`.
Roles 7/8/9 consultan reportes/audio; anónimo redirige a login, nivel 1 rechazado.
El panel devuelve solo la llamada propia, actual o pendiente de tipificar;
las variables se insertan como texto, incluido contenido HTML.

## Correcciones comprobadas

- Apache aislado bloqueaba el marcador con 403. El core permite cinco páginas
  exactas; workers, AGI y vista interna de grabaciones siguen bloqueados por HTTP.
- `wwwrun` no lee `queue_log`. Queue ejecuta Gosub en el agente al responder:
  AGI registra identidad y tiempo preliminar antes del puente; CONNECT conserva
  prioridad al cerrar. Probado sin acceso al log, sin ampliar sus permisos.
- Simulador SIP atiende re-INVITE y publica evidencia RTP atómicamente.

## Reproducir

En mirmidon, con montaje presente, sin llamadas ni campañas RUNNING:

```bash
bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-engine.sh
bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-reporting.sh
bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-sip-lab.sh
bash /mnt/zynervoxv2-fuente/src/features/dialer/tests/run-ten-agents.sh
```

Los dos primeros usan tablas TEMPORARY. Laboratorio individual: MACHINE,
NOANSWER, hangup temprano, overflow/MESSAGE, reserva global, parada/drenaje y
recuperación de canal perdido. Última regresión: llamadas 278–283, fixtures
retirados y auditoría conservada. El de diez agentes usa solo loopback y retiene
campañas STOPPED para inspección web, retira agentes/asignaciones y desactiva
su troncal. Puede repetirse sin borrar historial anterior.
`verify-lab-audio.py` comprueba treinta WAV PCM mono 8 kHz con ambas frecuencias;
`apache-web.php` prueba Apache real y elimina sus propias sesiones temporales.

## Alcance y continuidad

`carga01.txt`: inspección de formato, mil filas, números de nueve dígitos,
sin duplicados/vacíos; cabecera `id_contacto,nombre,numero,monto,local,direccion`.
`numero` es el destino y las demás columnas se conservan como variables.
No se cargaron ni llamaron clientes reales. Atención del usuario, softphone,
NAT y proveedor SIP quedan para su prueba posterior. Operación actual solo SIP.

Leer checklist, README/CONTRACT del módulo y registros append-only de decisiones
y cambios. Editar en Windows, probar por SMB, desplegar selectivamente con
backup/comparación y después commit/push a `origin/main`. Consultar versión con
`git log -1 --oneline`; revertir código con `git revert`, conservando datos.
