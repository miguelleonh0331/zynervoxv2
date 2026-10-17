# Motor de marcación con agentes

Implementación SIP + PHP 7.4 + MySQL + Asterisk AMI/AMD/Queue. Identidades y anexos
se consumen por las APIs públicas del core; datos operativos en `zynervox_core`,
tablas `v2_dialer_*`. Contrato: [CONTRACT.md](CONTRACT.md).

## Uso

1. Crear campaña desde `modules/admin/campaigns.php` y abrir Configurar.
2. Seleccionar MANUAL, PROGRESSIVE o ADAPTIVE (predictivo). RATIO mantiene nivel fijo.
3. Elegir un dialplan de salida SIP existente y configurar Caller ID.
   El prefijo interno se asigna automáticamente; endpoint y prefijo saliente
   se extraen del dialplan elegido. Cargar lista y disposiciones.
4. Asignar agentes con anexo activo y softphone registrado. Cada agente entra
   automáticamente a todas sus campañas activas al abrir `modules/admin/dialer_agent.php`.
   El panel ofrece Pausar/Reanudar global con motivo y Colgar para llamada propia.
   Cuentas de agente (niveles menores de 7) usan CRM sin menú de gestión;
   WhatsApp aparece como pestaña deshabilitada hasta habilitar acceso.
   Cerrar sesión está en el encabezado. GTR/supervisor/admin conservan su menú.
   Admin9 crea/edita/desactiva motivos en "Tipos de pausa" del mismo panel.
5. Iniciar motor desde Configurar. En MANUAL, el agente elige campaña y pulsa siguiente lead;
   los otros modos los ejecuta `zynervox-dialer.service`.
   MANUAL llama primero al softphone: contestarlo para marcar al cliente y
   escuchar timbrado y anuncios previos a respuesta enviados por la troncal.
   MANUAL no utiliza AMD; la grabación empieza al responder el cliente.
   Activar "Autodial MANUAL después de tipificar" en Configurar → Estrategia
   permite continuar a los 3 segundos de guardar tipificación en la campaña
   seleccionada. OFF por defecto. Cambiar campaña, pausar, salir o detener cancela
   esa continuación. Sin lead/anexo/capacidad se detiene y se retoma con el botón.
6. En MANUAL, tipificar también intentos cortados, sin respuesta o fallidos antes
   de marcar nuevamente. El panel conserva el cliente y el resultado del intento.
   Al terminar conversación, tipificar para salir de wrapup. Pausa manual se
   conserva; cerrar navegador no equivale a salir de cola.
   Cerrar sesión retira todas las membresías; fallos PBX permiten reintentar.
7. Detener nuevas llamadas permite terminar conversaciones ya iniciadas.

La reserva de lead comprueba actividad, estado RUNNING, horario del lead,
DNC, intentos y capacidad. MANUAL/PROGRESSIVE reservan un agente globalmente
y MANUAL conecta directamente su anexo; PROGRESSIVE lo entrega por su cola
personal. Durante la reserva se aparta de colas
predictivas. El máximo de canales siempre se verifica dentro de la transacción.
La grabación comienza tras respuesta; un fallo al iniciarla impide entregar
al agente. MESSAGE exige audio existente bajo sounds.

## Operación en mirmidon

- Fuente Windows: `E:\servidores\zynerdesk\proyectos\zynervoxv2`.
- SMB: `/mnt/zynervoxv2-fuente`; runtime separado: `/srv/www/htdocs/zynervoxv2`.
- Unidad: `/etc/systemd/system/zynervox-dialer.service`; usuario `wwwrun`, grupo `www`.
- Estado: `systemctl status zynervox-dialer`; logs: `journalctl -u zynervox-dialer`.
- Grabaciones: `/var/spool/asterisk/monitor/zynervoxv2/dialer/call-ID.wav`.
- Migraciones administrativas: `models/008` a `011`; `010` concede únicamente
  permisos de la nueva tabla a la identidad aislada de mirmidon.
- Instalación de unidad: `bash /mnt/zynervoxv2-fuente/src/features/dialer/services/install-mirmidon.sh`.
- Después de copiar nuevas clases PHP, reiniciar únicamente el worker para
  que cargue esa versión. Esto no cuelga los canales de Asterisk.

El servicio reconcilia llamadas y sesiones aun con campañas STOPPED.
Canal sin respuesta desaparecido: NOANSWER. Canal contestado desaparecido
sin handler: PBX_LOST, sin inventar duración de conversación.

## Pruebas y continuidad

`tests/console/run-console.sh`: consola del agente (`app/web/modules/agc`) sin
navegador ni base de datos. Levanta el marcado real de `crm.php` en un mini-DOM
y ejecuta el `crm.js` real contra un discador simulado: cartera, ciclo manual
progresivo, cascada de tipificación, rellamada y aislamiento del entorno. No
toca MariaDB, Asterisk ni el despliegue; se puede correr con el motor en marcha.
`tests/run-reporting.sh`: HTTP y SQL con tablas TEMPORARY por conexión.
`tests/run-routing.sh`: selección SIP, prefijos, callbacks automáticos, salida
compartida conservada y rechazo de cambios RUNNING/con llamadas abiertas o de
reservas con configuración obsoleta. Migración 012 aplicada solo a fixtures.
`tests/run-engine.sh`: campos de rutas SIP/PJSIP, reserva, capacidad, DNC y cierres.
`tests/run-agent-controls.sh`: SQL/HTTP TEMPORARY y PBX en memoria, ingreso
multicampaña, pausa durable, historial, reserva durante pausa, logout parcial,
propiedad de Hangup, CSRF y permisos del catálogo. Migraciones013–014 agregan
catálogo/estado/pausas y sus permisos runtime, sin alterar tablas legacy.
`tests/sip-lab.php` + `sip-lab-peer.py`: SIP/RTP local, tres modos, AMD,
grabación, puente, tipificación, parada y MESSAGE. Incluye pruebas de MANUAL
agente primero, 183/RTP previo a respuesta, ocupado,
agente/cliente sin respuesta, cancelación y liberación de reservas. También
verifica que el audio previo no inicia la grabación ni los tiempos del cliente.
`tests/run-sip-lab.sh` prepara audio, ejecuta el laboratorio con el servicio y
limpia el audio de prueba.
Requiere `DIALER_LAB_CONFIRM=LOOPBACK_SIP`, un PBX inicialmente sin llamadas,
audio de `tests/fixtures/lab-message.wav` instalado temporalmente como
`sounds/dialer-lab/message.wav`. Con `DIALER_LAB_USE_SERVICE=1`, prueba el worker
persistente. Usa identificadores exclusivos y elimina sus propios fixtures.
`cleanup-sip-lab.php` permite recuperar exclusivamente fixtures huérfanos.
`DIALER_LAB_CONFIRM=SERVICE_RESTART bash tests/run-service.sh` verifica reinicio
del supervisor y rechazo de un segundo worker. Solo ejecutar en laboratorio.

Alcance decidido por el usuario: solo SIP por ahora. PJSIP conserva formato
de originación compatible, sin activar includes ni certificar una llamada;
su prueba opcional exige `DIALER_LAB_TEST_PJSIP=1` y activación previa del core.

No confundir pruebas de protocolo en loopback con certificación de una
troncal comercial, NAT externo o un dispositivo MicroSIP concreto.
Estado, evidencias y checklist: `docs/CHECKLIST_MOTOR_MARCACION_MIRMIDON.md`.

## Ficha y grabaciones

La ficha del agente muestra en vivo nombre, destino y variables de la carga,
incluidos id_contacto, monto, local y direccion. Solo devuelve su llamada.
Reporte → Buscar grabaciones filtra fechas/teléfono/agente, permite escuchar
WAV autenticado y paginar de cien en cien. Roles 7/8/9; agentes sin acceso.
Endpoint: dialer_report.php?view=recordings&id=CAMPAÑA.

`tests/run-ten-agents.sh` prueba diez agentes SIP simultáneos en cada modo,
variables durante el puente, RTP bidireccional, treinta WAV con las dos señales,
estados/tipificación/reintentos, KPIs y Apache real. Requiere PBX sin llamadas
ni campañas RUNNING. Solo destinos loopback y teléfonos sintéticos. Retiene
historia STOPPED y WAV para revisar en web; elimina agentes/asignaciones y
credenciales temporales y deja su troncal inactiva. Es repetible conservando
historial, con prefijos exclusivos por campaña.
Informe: docs/VALIDACION_MOTOR_MARCACION_SIP.md.

## Eliminar campañas y cargar bases

Eliminar solicita confirmación de ID/nombre y borrado definitivo de listas,
leads, historial, disposiciones, asignaciones y DNC propias. Detener primero
el motor y esperar llamadas abiertas. Para conservar reportería, usar Desactivar.
Los WAV físicos se conservan, pero sin historial dejan de aparecer en la web.
Si aparece limpieza PBX pendiente, revisar registro; la eliminación BD ya ocurrió.
El botón Cargar base conserva la operación documentada de reemplazar la lista.

`tests/run-deletion.sh`: esquema descartable con estructura y FK reales, sin
copiar datos operativos. Prueba eliminación, rechazo RUNNING/llamadas abiertas,
rollback SQL, DNC global/ajenas, campaña y agente compartido, WAV y limpieza PBX
simulada (incluido fallo posterior al commit). Siempre retira su propia BD.
`run-reporting.sh` comprueba la etiqueta y rechazo POST sin CSRF/confirmación.

Prueba PBX real sin llamadas: `DIALER_LAB_CONFIRM=OWN_DELETE_FIXTURE php
/mnt/zynervoxv2-fuente/src/features/dialer/tests/live-deletion.php`. Requiere PBX
sin canales y troncal de laboratorio DIALER_TEN_SIP inactiva/loopback. Crea y
elimina solo su campaña nueva STOPPED con historia sintética, verifica retiro
del dialplan y conservación de campañas existentes. No borra campañas previas.

## Pestañas de campaña

Configurar organiza las tarjetas en Configuración, Listas, Disposiciones y
Agentes asignados. El parámetro tab permite abrir una sección directamente
(configuration/lists/dispositions/agents); la acción POST conserva su sección,
incluso si falla. Cambiar pestañas no envía formularios ni pierde lo escrito.
Teclado: flechas izquierda/derecha, Home/End y Tab para entrar al contenido.
Los enlaces también funcionan sin JavaScript.

Prueba aislada de continuación/cancelación: `tests/run-manual-autodial.sh`.
La prueba SIP loopback incluye continuidad del worker y grabación real.

Reporte (`modules/admin/dialer_report.php`): elegir Todas las listas o Lista cargada
(incluidas inactivas), fechas y Ver reporte. Se muestran intentos, números únicos,
cerradas, total tipificado, sin tipificar, resultados PBX y disposiciones.
Todos los desgloses usan el mismo filtro; ocupación por lista permanece N/D.
Prueba aislada: `tests/run-report-lists.sh`; HTTP/roles/validación: `tests/run-reporting.sh`.
