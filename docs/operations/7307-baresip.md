# Flujo operativo 7307: Baresip, early media y Bot IVR

Actualizado: 2026-10-14. Servidor: mirmidon. Prefijo operativo: **7307**.
Documento contrastado con el código desplegado, el dialplan cargado y eventos reales.

## 1. Objetivo y alcance

7307 origina llamadas de campañas Bot IVR desde un anexo SIP Baresip real, captura
el audio entrante antes del answer y utiliza Vosk para reconocer anuncios de
casilla, número inexistente o fuera de servicio. Si el destino contesta antes de
una detección válida, continúa con el flujo IVR publicado de la lista.

Se adoptó el mecanismo de origen del sistema 3006: anexo Baresip -> Asterisk ->
troncal -> destino. Se conservan las campañas, listas, detector aislado, motor
IVR y reportes de zynervoxv2; no se ejecuta el worker ni el IVR legacy de 3006.

No es el dialer MANUAL de agentes, no usa colas de agentes humanos y no corresponde
al prefijo 3306/ConfBridge. Los prefijos 3006 y 3306 permanecen independientes.

## 2. Componentes y topología

| Componente | Ubicación o función |
|---|---|
| Web y workers PHP | `/srv/www/htdocs/zynervoxv2` |
| Runtime aislado | `/var/lib/zynervoxv2/asterisk` |
| Campañas, leads, intentos y eventos v2 | Base `zynervox_core` |
| Pool de reserva compartido | `synervox.dynamic_ivr_pilot_annexes` |
| Anexos permitidos por el broker | 4002 a 4050, 49 identidades |
| Host de Baresip | `172.16.10.26` |
| Destino SIP de Asterisk usado por Baresip | `209.17.220.5` |
| Troncal conservada para 7307 | `SIP/carrier1` |
| Número enviado a la troncal | `881851<teléfono>` |
| Contexto SIP de los anexos | `test_cortar` |
| Contexto de entrada v2 | `zynervoxv2-baresip` |

`881851` es parte del número/ruta enviado a la troncal. No representa el pool
local de anexos 400x. Los peers carrier1 y outfiber apuntaban al mismo endpoint
SIP durante la investigación; eso no demuestra igualdad de todas sus opciones,
credenciales ni reglas de enrutamiento.

## 3. Recorrido completo

```text
Operador pulsa Play en una lista con origen 7307 y audios válidos
  -> list_call_service.php valida y crea el dial run
  -> list_call_launcher.py inicia un worker con lease por lista
  -> list_call_worker.php aplica el límite actual de canales
  -> reserva un anexo registrado antes de reclamar el contacto
  -> claimDialLead crea el intento y vincula campaña/lista/lead/run/call_id
  -> broker comprueba ese claim y lanza Baresip por SSH
  -> Baresip envía INVITE autenticado a 7307<teléfono>*<call_id>
  -> test_cortar redirige a zynervoxv2-baresip
  -> AGI valida el token una sola vez y la identidad del anexo
  -> tracking START y preparación de variables heredadas
  -> Dial(SIP/carrier1/881851<teléfono>,60,b(...)G(...))
     -> predial b(): OUTBOUND + MixMonitor antes de marcar
     -> early media: WAV RX -> worker preanswer -> Vosk
        -> categoría válida, aún sin answer: evidencia + Hangup AMI
        -> answer real: separación G() de origen y cliente
           -> origen sale después de quitar su handler de cierre
           -> cliente ejecuta el IVR actual y cierra su propia auditoría
  -> FINISH conserva causa SIP, estado de negocio y metadatos de grabación
  -> broker libera exclusivamente la reserva de anexo que le pertenece
```

## 4. Play, canales y selección de contactos

Play exige sesión administrativa, CSRF, pertenencia de lista/campaña, actividad,
flujo publicado, origen guardado y audios cuya firma corresponda a los datos y
proveedor actuales. Los valores del proveedor quedan capturados en el dial run.

Para 7307, si `/etc/zynervoxv2/baresip-origin.json` tiene `enabled=true`, el worker
utiliza `BotBaresipOrigin`. Los demás prefijos conservan AMI Local. AMI continúa
usándose para comprobaciones y para el Hangup del detector, aunque ya no crea el
canal Local como origen de las llamadas 7307 de este transporte.

El worker reserva un anexo antes de reclamar un lead. Si no hay anexo disponible,
espera sin consumir contactos. `claimDialLead` aplica los estados elegibles,
excluye contactos ya intentados en esa ejecución y respeta las llamadas abiertas.
Existe una única ejecución activa por lista y un lease de proceso que evita
workers duplicados. La firma del flujo también se comprueba durante la ejecución.

El límite de llamadas procede de `list.call_channels`. Subirlo permite rellenar
nuevos huecos; bajarlo deja terminar las llamadas existentes. Stop deja de reclamar
contactos y drena las llamadas ya admitidas; no cuelga masivamente.

Si Stop gana la carrera después de reservar un anexo pero antes de reclamar el
lead, se devuelve la reserva sin consumir un contacto.

## 5. Reserva del pool y convivencia con 3006

El broker `/usr/local/libexec/zynervoxv2-baresip-origin.py` es propiedad de root y
constituye el límite de compatibilidad con el pool antiguo. PHP no recibe grants
sobre la base legacy ni copia claves SSH a su código.

Para reservar, el broker comprueba el run activo, consulta los anexos realmente
registrados y descarta aquellos que tengan un canal SIP activo. Después ejecuta
una actualización atómica limitada a una fila libre y sin reserva:

- `reserved_campaign_id = 18446744073709551615`: propietario externo reservado.
- `status = busy`.
- `locked_by`: identificador derivado del token de esta llamada.
- `locked_at`: hora de la reserva.

El valor máximo unsigned es un marcador de propiedad externa, no un ID de campaña
v2 ni de campaña legacy. Los workers de 3006 ya exigen
`reserved_campaign_id IS NULL`, por lo que no pueden reclamar estas filas.
La liberación exige coincidencia de anexo, propietario externo y token.

Hay 49 anexos permitidos y la capacidad es compartida con 3006. Configurar 25 o
100 canales no crea nuevas identidades SIP. Las reservas de anexo y las llamadas
activas son contadores distintos: un proceso Baresip puede seguir ocupando su
reserva después de que su llamada termine o se separe hacia el IVR.

## 6. Lanzamiento Baresip y correlación segura

El broker comprueba que el intento exista, pertenezca al run, tenga origen 7307,
está abierto y aún no tenga canal de origen. Lee teléfono, lista, lead y caller ID
del claim/configuración del run; no toma esos metadatos de una petición SIP libre.

El Baresip desechable utiliza `/var/lib/zypad_pool_dial/<anexo>` y autentica con
la identidad del anexo. Se conserva el servicio persistente que mantiene su
registro: no se lo detiene para marcar. El perfil de marcación usa otro puerto.

Formato de destino, con valores ilustrativos:

```text
sip:7307<teléfono>*zv2-<24 caracteres hexadecimales>@209.17.220.5
```

`baresip_context_agi.php` pide al broker un bind de uso único. Deben coincidir
el teléfono, el token, el canal `SIP/<anexo-reservado>-...` y un uniqueid nativo
válido. Un token ya utilizado o una identidad de anexo distinta se rechazan.

El `call_id` de aplicación sigue siendo el token `zv2-...`; el uniqueid y linkedid
de Asterisk se guardan como metadatos de correlación de las patas. No se mezclan
IDs de campañas v2 y legacy.

## 7. Dialplan desplegado

Se agregó esta entrada al contexto existente `[test_cortar]` de
`/etc/asterisk/extensions-test.conf`:

```asterisk
exten => _7307X.,1,Goto(zynervoxv2-baresip,${EXTEN},1)
```

El archivo generado
`/var/lib/zynervoxv2/asterisk/modules/asterisk/extensions-zynervoxv2.conf`
contiene estos contextos nuevos:

| Contexto | Responsabilidad |
|---|---|
| `zynervoxv2-baresip` | Validar token, iniciar tracking y marcar por carrier1 |
| `zynervoxv2-baresip-answer` | Separar origen/cliente y entregar el cliente al IVR |
| `zynervoxv2-baresip-caller` | Quitar el handler del origen, esperar un segundo y salir |

La instrucción principal es:

```asterisk
Dial(SIP/carrier1/881851${ZV2_PHONE},60,b(zynervoxv2-outbound^s^1)G(zynervoxv2-baresip-answer^answered^1))
```

No se ejecuta Answer() anticipadamente en el origen. La opción `b()` corre en el
canal **llamado/saliente**, antes de enviar la llamada al destino; no en la pata
origen. Allí se registra OUTBOUND y se inicia el monitor del audio entrante.

Cuando contesta el destino, `G()` entrega las patas a prioridades consecutivas.
El origen salta a su contexto de salida, elimina su hangup handler y termina.
El cliente establece `ZV2_ANSWERED=1` y `DIALSTATUS=ANSWER`, instala su propio
handler de cierre y ejecuta `zynervoxv2-bot-ivr-sin-amd` mediante Gosub.
Así, liberar el origen no finaliza prematuramente el tracking ni el IVR del cliente.

La plantilla versionada está en `app/web/includes/DialplanOrigins.php`; editar
solo el archivo generado perdería los cambios al regenerar desde la aplicación.
La activación se hizo con `dialplan reload`, sin reiniciar Asterisk.

## 8. Captura y clasificación pre-answer

`preanswer_agi.php` se ejecuta en el canal saliente y crea:

- Grabación completa: `recordings/bot_ivr/<call_id>.wav`, relativa al runtime.
- Recepción: `bot_ivr/ivr_calls/<call_id>/preanswer_rx.wav`.

El monitor se instala antes de marcar y no exige que el canal está puenteado.
El servicio `zynervoxv2-preanswer.service` supervisa hasta 12 trabajos Vosk.
Usa snapshots de PCM mono, 16 bits, 8000 Hz; avance por cursor, ventana máxima de
seis segundos y solapamiento de dos segundos. Para una llamada abierta espera
aproximadamente dos segundos de audio nuevo antes de transcribir.

El clasificador normaliza texto y aplica vocabulario por frases/palabras:

| Categoría | Estado persistido |
|---|---|
| `casilla` | `PREANSWER_CASILLA` |
| `numero_no_existe` | `PREANSWER_NUMERO_NO_EXISTE` |
| `fuera_servicio` | `PREANSWER_FUERA_SERVICIO` |

La evidencia se registra como PREANSWER_START, PREANSWER_CAPTURE,
PREANSWER_RESULT y PREANSWER_DONE. Una detección válida intenta Hangup por AMI
solo si la llamada todavía es apta para corte pre-answer y su canal no está Up.
El resultado conserva texto, proveedor, categoría, cursor y confirmación del corte.

Los resultados tardíos no deben colgar una llamada ya contestada ni sobrescribir
el estado de un intento posterior del mismo contacto. Un cierre sin answer puede
recibir clasificación tardía del audio pendiente, respetando esas protecciones.

El vocabulario actual es amplio: no exige siempre una frase completa. Por
ello una categoría es el resultado del detector y debe poder auditarse con texto
y grabación; no constituye por sí sola prueba definitiva de la condición de red.

## 9. Answer e IVR posterior

Al registrar ANSWER, el procesamiento pre-answer deja de intentar cortar esa
llamada. La grabación completa se reutiliza si el predial ya la había iniciado.

No se invoca el `AMD()` nativo de Asterisk en esta ruta. Eso no significa que se
asuma siempre una persona: el flujo publicado puede contener su propio nodo
`AMD_DETECTER`, que usa STT después de contestar y puede terminar en AMD_HANGUP.

`ivr_engine_agi.php` recibe lista, lead y call_id, carga el flujo publicado,
reproduce audios y ejecuta nodos de captura, menús y resultados según ese flujo.
La selección de proveedor y los eventos IVR siguen perteneciendo al motor v2.

Se corrigió `call_tracking_agi.php start` para publicar `__ZV2_LEAD_ID`.
La escritura anterior sin los dos guiones bajos quitaba la herencia, dejando
el lead vacío en el canal saliente. La identidad queda ahora disponible al IVR.

## 10. Cierre, estados y reportes

`zynervoxv2-call-finish` llama al tracking de cierre con call_id, DIALSTATUS,
resultado AMD si existe, causa de colgado y marca de answer. Se conservan la
clasificación alcanzada, la auditoría y los metadatos de la grabación finalizada.

| Dato | Significado |
|---|---|
| `status` | Estado del contacto/intento, incluida clasificación o gestión IVR |
| `dial_status` | Resultado telefónico, por ejemplo NOANSWER, CHANUNAVAIL o ANSWER |
| `hangup_cause` | Causa de colgado reportada por Asterisk |
| `answered_at` | Momento real de answer, nulo si no contestó |
| `recording_status/bytes/path` | Estado y ubicación de la grabación al cierre |
| `ivr_result` | Finalización del motor IVR cuando se ejecutó |

Por ejemplo, PREANSWER_NUMERO_NO_EXISTE puede conservar CHANUNAVAIL o NOANSWER
como resultado telefónico. No se convierten indiscriminadamente todos los
CONG/UNAV en números inexistentes. Los reportes leen las tablas v2, entre ellas
`zynervox_bot_call_attempts`, `zynervox_bot_call_events` y `zynervox_bot_ivr_results`.

## 11. Liberación y recuperación

El broker mantiene leases JSON y logs privados en
`/var/lib/zynervoxv2/asterisk/bot_ivr/baresip_leases`.

- Salida SSH limpia: cierra la reserva si el canal SIP del anexo ya no está activo.
- Resultado SSH incierto: no vuelve a marcar; conserva la reserva en cuarentena.
- Baresip se lanza con `-t 60`; la espera local SSH tiene límite de 75 segundos.
- Una reserva incierta se reconsidera al superar 90 segundos desde su creación,
  y solo se libera al comprobar ausencia del canal del anexo en Asterisk.
- `zynervoxv2-baresip-sweep.timer` reconcilia cada 30 segundos, incluso sin worker.
- Las reservas propias sin ledger se recuperan con la misma comprobación de edad
  y ausencia; no se liberan filas de otro propietario o token.
- Los leases liberados y sus logs se purgan después de un día desde su creación.

Si no llegó a crearse un canal SIP y el origen falla definitivamente, el worker
puede cerrar el claim con DIAL_REJECTED. La auditoría existente también mantiene
la recuperación de intentos abiertos cuando faltó un cierre fiable.

## 12. Archivos principales y configuración

| Archivo o ruta | Función |
|---|---|
| `app/web/bot_ivr/list_call_service.php` | Validación de Play y creación del run |
| `app/web/bot_ivr/list_call_worker.php` | Canales, claims y transporte Baresip |
| `app/web/bot_ivr/baresip_origin.php` | Cliente PHP del broker |
| `app/web/bot_ivr/baresip_context_agi.php` | Bind y variables heredadas |
| `app/web/bot_ivr/call_tracking_agi.php` | Eventos, identidad y cierre |
| `app/web/includes/DialplanOrigins.php` | Plantilla de contextos comunes |
| `asterisk/synervox/modules/bot_ivr/baresip_origin.py` | Fuente del broker |
| `/usr/local/libexec/zynervoxv2-baresip-origin.py` | Broker instalado, root:root 0755 |
| `/etc/zynervoxv2/baresip-origin.json` | Activación, anexos, leases y referencia a llave; root:www 0640 |
| `/etc/sudoers.d/zynervoxv2-baresip-wwwrun` | Permiso al comando fijo del broker |
| `zynervoxv2-baresip-sweep.service/.timer` | Recuperación de reservas |
| `preanswer_agi.php`, `preanswer_service.php`, `preanswer_worker.php` | Captura y detección aislada |
| `ivr_engine_agi.php`, `ivr_graph_service.php`, `ivr_graph_io.php` | Ejecución IVR v2 |
| `installer/bot-ivr-baresip.sh` | Instalación de fuentes, permisos y timer |

El directorio de leases debe ser root:root 0700. El broker rechaza configuración
o directorio escribibles por grupo/otros. sudo permite su ruta fija sin argumentos
adicionales; las solicitudes tienen tamaño y operaciones acotados.

Se reutiliza la identidad SSH existente y se exige host key conocida. No se
incluyen contraseñas, llaves privadas ni configuración de proveedores en este documento.
El instalador es un adaptador para las dependencias existentes de mirmidon; no
provisiona por sí solo una granja Baresip en un servidor nuevo.

## 13. Validación real y límites conocidos

### Prueba aislada de migración

Se utilizó campaña QA 60/lista 68. Después de corregir la identidad heredada,
la llamada `zv2-b542376a70ed098aa544cebe` cerró PREANSWER_CASILLA, con
`dial_status=NOANSWER`, `answered_at=NULL`, `hangup_ok=true` y WAV de 136044 bytes.
La campaña QA se desactivó y el anexo volvió libre y registrado.

### Ejecución productiva con 25 canales

Lista 56/campaña 50; run `6a1b8ed21b6ce47187a34fa0cdf0d111`, iniciado el
2026-10-14 a las 16:41:56 UTC. Se observaron 25 llamadas activas simultáneas,
clasificación pre-answer y entradas al IVR con identidad de lead correcta.

Corte a las 11:45:19 del servidor, equivalente a 16:45:19 UTC:

| Métrica | Observación |
|---|---:|
| Intentos iniciados | 178 |
| Cerrados | 155 |
| Activos en ese instante | 23 |
| PREANSWER_CASILLA | 27 |
| PREANSWER_NUMERO_NO_EXISTE | 56 |
| Entradas IVR | 37 |
| Errores IVR | 0 |

Una lectura posterior a las 11:49:12 del servidor mantenía el run en running,
25 canales configurados, 61 casillas, 122 números inexistentes y cero errores IVR.
Son cortes durante la ejecución, no resultados finales de toda la campaña.

Persisten algunos CONG/UNAV sin clasificación. Se comprobaron ejemplos CONG con
unos 32 segundos de muestras no silenciosas, pero sin transcripción útil de Vosk.
Por tanto no es correcto atribuir todos los casos a ausencia de RTP del carrier:
la causa requiere revisar señalización, grabación y reconocimiento por llamada.
`no_audio` del adaptador no demuestra por sí solo que un WAV carezca de muestras.

El corte de 25 canales valida esa concurrencia observada; no certifica 40 o 100.
La categoría fuera_servicio tiene pruebas unitarias/SQL, pero no quedó validada
con una locución real del carrier durante los cortes descritos.

Pasaron pruebas de broker, reservas y tokens, plantilla/orígenes, detector y
contrato SQL, concurrencia y Stop, auditoría AGI, herencia del lead y ejecución
CLI del IVR. Las regresiones SQL usaron rollback o limpieza de fixtures.

### Incidente del contacto de prueba en la lista productiva

El lead 3150 fue incorporado sin nombre por una prueba manual anterior a la
migración. Invalidaba la firma/preparación y ocultaba Play. A petición del usuario
se eliminó únicamente ese lead de la lista 56, conservando su auditoría y respaldo
privado. La lista volvió a 3000 contactos y la validación de los audios anteriores
pasó sin regeneración. `list_edit.php` no fue modificado en esta migración.

Generar se deshabilita si faltan variables obligatorias: no puede construir un
texto TTS válido a partir de un nombre vacío. Play exige que los audios están
válidos para los datos actuales; ambos controles responden a validaciones distintas.

## 14. Comprobaciones operativas sin originar llamadas

```bash
asterisk -rx 'dialplan show zynervoxv2-baresip'
asterisk -rx 'dialplan show zynervoxv2-baresip-answer'
asterisk -rx 'sip show peer 4002'
systemctl is-active zynervoxv2-preanswer.service
systemctl is-active zynervoxv2-baresip-sweep.timer
```

En SQL, filtrar los intentos/eventos por run_id y call_id. Revisar el estado de
negocio junto a dial_status y answered_at. No usar solo queue show para este Bot
IVR ni interpretar un peer registrado como prueba de que una campaña lo utiliza.

## 15. Activación y rollback

La configuración privada está habilitada para 7307. Las nuevas ejecuciones con
ese prefijo usan Baresip; para operar se utiliza el Play habitual con audios listos.
No es necesario ejecutar un comando manual por cada llamada.

Para instalar en un entorno equivalente: preparar fuentes en local, desplegar con
backup y modos explícitos, comprobar dependencias/pool, incluir los contextos
comunes y la entrada test_cortar, validar y recargar dialplan. El instalador crea
la configuración inicialmente con enabled=false. Activar solo con runs detenidos.
Una transferencia SFTP puede alterar permisos: restablecer root:www 0640 antes
de activar la configuración.

Rollback del transporte: detener nuevas ejecuciones, drenar llamadas, preparar
localmente la configuración con enabled=false y desplegarla con sus permisos.
El siguiente worker vuelve al origen AMI Local que se conserva. El cambio no
resucita ni reintenta automáticamente llamadas anteriores.

No eliminar reservas mientras exista un origen/canal activo. Para retirar la
integración completa, recuperar primero los leases y restaurar solamente los
backups de PHP/dialplan de esta migración; después recargar dialplan. No restaurar
archivos de otras tareas ni reiniciar Asterisk para actualizar este documento.
