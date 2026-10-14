# 7307: origen Baresip y detección pre-answer

Estado: habilitado en mirmidon el 2026-10-14. Fuente: este repositorio;
despliegue PHP en `/srv/www/htdocs/zynervoxv2`. No es el dialer MANUAL de agentes.

## Recorrido vigente

```text
Play de una lista con origen 7307
  -> list_call_worker.php
  -> reserva atómica de un anexo registrado del pool 4002..4050
  -> claimDialLead (campaña/lista/contacto y call_id aleatorio)
  -> broker valida el claim y lanza Baresip en 172.16.10.26
  -> INVITE autenticado: 7307<teléfono>*<call_id> a 209.17.220.5
  -> test_cortar -> zynervoxv2-baresip
  -> bind de uso único: token, anexo, teléfono y uniqueid
  -> tracking START; sin Answer() anticipado
  -> Dial(SIP/carrier1/881851<teléfono>,60,b(preanswer)G(answer-split))
     -> MixMonitor RX antes de marcar -> worker aislado -> Vosk
     -> si reconoce casilla/número inválido: resultado y Hangup AMI
     -> si contesta: separación G(), como en 3006
        -> origen quita su hangup handler y termina
        -> cliente conserva tracking e IVR actuales y registra FINISH
  -> libera exclusivamente la reserva propia al terminar Baresip
```

El prefijo 881851 sigue siendo parte del destino de la troncal. No identifica
los anexos 400x. La presencia de un canal no garantiza audio remoto: early media
se confirma mediante SIP/RTP y muestras reales, nunca solo por el tamaño del header WAV.

Los demás prefijos mantienen su origen AMI Local. El origen Local de 7307 queda
disponible para rollback al deshabilitar la configuración privada. Las campañas,
listas, IVR, reportes y detector de 3006 no se migraron ni reemplazaron.

## Límite de compatibilidad con el pool antiguo

`/usr/local/libexec/zynervoxv2-baresip-origin.py` es root-owned y recibe un JSON
acotado por stdin; sudo permite solamente su ruta fija, sin argumentos extra.
La aplicación PHP no recibe permisos SQL sobre la base antigua ni copia claves.

El broker reserva exclusivamente `synervox.dynamic_ivr_pilot_annexes` usando
`reserved_campaign_id=18446744073709551615` como propietario externo y un token
por llamada en `locked_by`. No es un ID de campaña ni se crea una campaña legacy.
Las reservas de 3006 ya requieren `reserved_campaign_id IS NULL`, por lo que
no pueden reclamar esas filas. La liberación exige anexo, propietario y token
coincidentes. No se tocan sus contactos, workers ni servicios SIP persistentes.

Hay 49 anexos habilitados y se comparte la capacidad con 3006. Si todos están
reservados, v2 espera sin consumir leads. Subir canales no crea nuevos anexos.
Cada Baresip utiliza su perfil separado `/var/lib/zypad_pool_dial/<anexo>` y la
identidad SSH existente, con host key verificada. Una conexión incierta no se
reintenta: conserva la reserva hasta agotar la vida remota acotada y confirmar
ausencia del canal del anexo en Asterisk. El timer reconcilia cada 30 segundos.

## Archivos y activación

- PHP: `baresip_origin.php`, `baresip_context_agi.php`, `list_call_worker.php`,
  `list_call_service.php` y `call_tracking_agi.php`.
- Generador de contextos: `app/web/includes/DialplanOrigins.php`.
- Broker: `asterisk/synervox/modules/bot_ivr/baresip_origin.py`.
- Configuración privada: `/etc/zynervoxv2/baresip-origin.json`, root:www 0640.
- Leases/logs privados: runtime `bot_ivr/baresip_leases`, root 0700; retención
  de un día para leases liberados. Evidencia SQL conserva el historial.
- Recuperación: `zynervoxv2-baresip-sweep.timer` y su servicio oneshot.

`installer/bot-ivr-baresip.sh <árbol-staged>` instala con modos explícitos y
respaldo; exige que no existan ejecuciones v2 activas. La primera configuración
se instala con `enabled=false`. Es un adaptador para las dependencias existentes
de mirmidon, no un instalador autónomo para cualquier servidor.

El dialplan generado debe incluir los contextos de la plantilla actual. En
`[test_cortar]` de `/etc/asterisk/extensions-test.conf` se agrega solo:

```asterisk
exten => _7307X.,1,Goto(zynervoxv2-baresip,${EXTEN},1)
```

Validar sintaxis, permisos, sudo y pruebas antes de `dialplan reload`. No reiniciar
Asterisk. Activar `enabled=true` únicamente con ejecuciones detenidas. Una subida
SFTP puede alterar los permisos: instalar configuración con root:www 0640 después
de transferirla. El broker rechaza archivos escribibles por grupo/otros.

Para operar, usar el Play habitual de una lista con origen 7307 y audios listos.
No requiere ejecutar scripts nuevos desde la web ni modificar el flujo publicado.

## Corrección de identidad heredada

`call_tracking_agi.php start` ahora publica `__ZV2_LEAD_ID`, manteniendo la
herencia hacia el canal saliente. Antes, al escribir `ZV2_LEAD_ID` sin prefijo,
quitaba esa herencia y el IVR podía recibir un lead vacío. Se comprobó el
fallo en la primera llamada de migración y se añadió una regresión del protocolo
AGI. Esta corrección también conserva la identidad en los otros orígenes.

## Evidencia de validación, 2026-10-14

Campaña QA 60, lista 68, un contacto controlado; campaña desactivada al terminar.
Hubo un inicio bloqueado por permisos antes de crear un intento SIP y dos
llamadas externas al mismo contacto durante la validación.

1. `zv2-a9a0030db19b107fbcec62b0`: origen `SIP/4002-00000bcc`, destino
   `SIP/carrier1-00000bcd`; recibió 183 y RX pre-answer. WAV 190124 bytes.
   La transferencia al IVR expuso el lead sin herencia; se corrigió después.
2. `zv2-b542376a70ed098aa544cebe`: origen `SIP/4002-00000bd0`, destino
   `SIP/carrier1-00000bd1`; `PREANSWER_RESULT.category=casilla`,
   texto reconocido «hola deja tu mensaje en la que», `hangup_ok=true`.
   Resultado `PREANSWER_CASILLA`, `dial_status=NOANSWER`, `answered_at=NULL`,
   WAV 136044 bytes. Inicio 16:27:41 UTC, cierre 16:27:50 UTC.

La segunda llamada valida audio, STT, clasificación y corte antes del answer.
No demuestra todas las categorías ni una carga real de 40 llamadas simultáneas.
No convertir automáticamente CONG/UNAV en número inexistente: conservar
señalización y evidencia del detector como datos distintos.

Pruebas: ocho casos del broker sin red; plantilla de dialplan y roundtrip;
parser de orígenes; detector y contrato SQL con rollback; worker con concurrencia,
cambios de canales, Stop, pool agotado y carrera de reserva; protocolo/auditoría
AGI con identidad heredada. Las pruebas SQL no marcan teléfonos.

La regresión del motor CLI confirmó playback, finalización/interrupción,
identidad, captura interactiva y persistencia sin llamadas externas adicionales.
El anexo 4002 volvió a free/NULL y se mantuvo registrado; no quedaron reservas
externas ni ejecuciones QA activas. Fuentes desplegadas verificadas contra el staged.

La lectura previa al Play de la lista productiva 56 detectó un bloqueo de datos
independiente: el lead 3150 carece de `nombre`, requerido por el flujo 2. No se
inventó ese dato ni se eliminó el contacto. Corregirlo y preparar los audios antes
de relanzar esa lista; la campaña QA utilizó datos completos y terminó desactivada.

## Rollback

Detener nuevas ejecuciones v2 y dejar terminar las llamadas. Preparar localmente
una copia de la configuración con `enabled=false`, transferirla e instalarla
root:www 0640. El próximo worker vuelve a AMI Local; la plantilla conserva
esa ruta. No eliminar reservas mientras haya un Baresip/canal activo.

Para retirar todo, tras drenar y recuperar leases, restaurar los respaldos
del PHP/dialplan de esta migración y recargar dialplan. No restaurar versiones
de otras tareas ni sobrescribir modificaciones posteriores. Revertir el commit
de esta migración conserva el historial local; no cambia producción por sí solo.
