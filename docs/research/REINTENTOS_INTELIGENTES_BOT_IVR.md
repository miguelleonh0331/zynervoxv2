# Investigación y traspaso: reintentos inteligentes de Bot IVR

Fecha: 2026-10-14. Estado: documento de investigación; funcionalidad pendiente.
Proyecto fuente: `E:\servidores\zynerdesk\proyectos\zynervoxv2`.
Caso de referencia: `/bot_ivr/campaign_edit.php?id=50`, campaña CARSA.
Origen de referencia: 7307 mediante Baresip. No confundir con el dialer de agentes.

## 1. Encargo para el siguiente agente

Investigar, diseñar y comprobar la compatibilidad de un motor de reintentos
automáticos con pesos por estado. Generar primero un documento de análisis
implementable, con rutas, cambios necesarios, migraciones, pruebas, despliegue y
rollback. La implementación e instalación serán una etapa posterior autorizada.

Este archivo entrega un mapa comprobado en el código local. No certifica que el
VPS conserve exactamente la misma revisión cuando se retome el trabajo. Antes de
instalar, contrastar fuente, esquema, servicios y archivos realmente desplegados.

El usuario quiere que el sistema siga buscando contacto efectivo automáticamente:
seleccionar leads según sus estados y pesos, esperar antes de volver a llamarlos,
respetar límites y abandonar el ciclo cuando corresponda. No quiere duplicar leads,
borrar historial ni reconstruir los contactos como NEW para poder reintentarlos.

## 2. Alcance y decisiones todavía abiertas

Confirmado por el usuario:

- Pesos por estado, por ejemplo NEW, NO_RESPONDE_F1 y AMD_HANGUP.
- Reintentos automáticos en ciclos, buscando contacto efectivo.
- El Dashboard debe continuar usando la caché compartida recientemente instalada.
- Otro agente realizará la investigación detallada y la instalación.

Propuestas técnicas anteriores, todavía sin valores definitivos aprobados:

- Selección proporcional entre estados elegibles; evitar que la prioridad estricta
  deje indefinidamente sin atención a los estados de menor peso.
- Por estado: habilitado, peso, espera y máximo de reintentos.
- Por número/contacto: límite global de intentos y límite en una ventana temporal.
- Horarios y zona horaria explícitos; citas solicitadas por el cliente separadas
  del reciclado automático general.
- Campaña en espera cuando haya reintentos futuros; finalizada solo si ya no hay
  trabajo elegible ni futuro dentro de sus reglas.

El siguiente agente debe resolver y documentar: límites por lead o por teléfono,
alcance entre campañas/empresas, definición de contacto efectivo, estados finales,
valores iniciales, tratamiento de errores técnicos y semántica de cambios en vivo.
No presentar cifras propuestas como prácticas universales ni como límites legales.

## 3. Punto de partida Git y reglas del proyecto

Al preparar este documento, HEAD es `e968736` en main, cinco commits locales por
delante de origin/main. Los cambios recientes del Dashboard y su caché están en
esos commits. El remoto configurado es:
`https://github.com/miguelleonh0331/zynervoxv2.git`.

Existe un cambio previo ajeno en `app/web/includes/IsolatedGate.php`: conservarlo;
no restaurarlo, incluirlo en commits propios ni desplegarlo por esta tarea.
Verificar nuevamente estas condiciones: son una fotografía, no una garantía futura.

Leer antes de cambiar código, desde la raíz del proyecto:

1. `AGENTS.md` y `docs/MODULE_MAP.md`.
2. `agents/ARCHITECT_AGENT.md`: el cambio cruza módulos y probablemente contratos.
3. `src/features/bot_ivr/README.md`, `CONTRACT.md` y `agents/bot_ivr_AGENT.md`.
4. `src/features/zynervox_queries/README.md` y `CONTRACT.md`.
5. `src/features/admin/README.md`, `CONTRACT.md` y
   `agents/_DEFAULT_MODULE_AGENT.md`, si cambia monitor/API.
6. `src/features/telephony/CONTRACT.md` solo si aparece un impacto en telefonía.

Registrar decisiones y cambios mediante append en `docs/DECISIONS.md` y
`docs/CHANGELOG_AGENT.md`. No editar a mano `docs/MODULE_MAP.md`. Escalar contratos
y límites de módulos según ARCHITECT_AGENT; no delegar a otros agentes por defecto.

## 4. Rutas exactas y correspondencia local/VPS

Todas las rutas relativas de las tablas siguientes parten de:

```text
LOCAL = E:\servidores\zynerdesk\proyectos\zynervoxv2
WEB   = /srv/www/htdocs/zynervoxv2
RUNTIME = /var/lib/zynervoxv2/asterisk
```

`LOCAL\app\web\bot_ivr\campaign_edit.php` corresponde a
`WEB/bot_ivr/campaign_edit.php`. Esta equivalencia aplica a todo `app/web/`.
El servidor conocido es mirmidon; la base aislada es `zynervox_core`.
La configuración real de instalación se obtiene de `WEB/config/deployment.php`;
no imprimir su contenido privado ni copiar credenciales al documento.

### 4.1. Pantalla y configuración de campaña

| Archivo local relativo | Qué investigar |
|---|---|
| `app/web/bot_ivr/campaign_edit.php` | Pantalla objetivo, formulario de campaña, etiquetas «Estados a marcar», altas/edición de listas y POST con CSRF. |
| `app/web/bot_ivr/campaigns_page.php` | Autenticación administrativa, token CSRF y helpers compartidos de la página. |
| `app/web/bot_ivr/campaigns_service.php` | `BOT_CAMPAIGN_FIELDS`, validación de dial_statuses y guardado de horario/metadatos. |
| `app/web/zynervox_queries/mysql/bot_ivr/campaigns.php` | `campaign`, `updateCampaign`, `deactivateCampaign` y agregación del monitor. |
| `app/web/zynervox_queries/mysql/bot_ivr/lists.php` | Listas por campaña, pertenencia, canales persistentes y estadísticas por lista. |

No asumir que un estado aparece en el catálogo de sugerencias de la interfaz:
el campo actual acepta códigos válidos escritos manualmente. Identificar estados
reales del flujo publicado y de los intentos antes de proponer su catálogo.

### 4.2. Play, selección, worker y cola

| Archivo local relativo | Puntos exactos |
|---|---|
| `app/web/bot_ivr/list_edit.php` | Play/Stop por lista, canales y preparación de audios. |
| `app/web/bot_ivr/list_call_api.php` | POST play/stop/save_channels, admin/CSRF, pertenencia y respuesta pública. |
| `app/web/bot_ivr/list_call_service.php` | `bot_list_call_configuration`, `bot_list_call_start`, `bot_list_call_status`, `bot_list_call_stop`, `bot_list_call_public`. |
| `app/web/bot_ivr/list_call_worker.php` | `bot_list_call_loop`: relleno de canales, condición de finalización, ping, reservas y drenado al parar. |
| `app/web/zynervox_queries/mysql/bot_ivr/dial_runs.php` | `dialCandidates`, `createDialRun`, `dialRun`, `claimDialLead`, `transitionDialRun`, `rejectDialClaim`. Principal selección SQL. |
| `app/web/zynervox_queries/contracts/BotIvrRepository.php` | Contrato público; declarar aquí cambios de operaciones antes de consumirlas. |
| `app/web/zynervox_queries/mysql/bot_ivr/repository.php` | Composición de los traits del adaptador. |
| `app/web/zynervox_queries/factory.php` | Resolución del repositorio/conexión; no introducir SQL directo en páginas. |
| `asterisk/synervox/modules/bot_ivr/list_call_launcher.py` | Lanzador separado y lease por lista; no está en app/web/bot_ivr/. |
| `installer/list-call-worker.sh` | Instalación del lanzador, directorios y permisos de dial_runs. |

El lanzador se despliega en `RUNTIME/modules/bot_ivr/list_call_launcher.py`.
Los leases/trabajos están en `RUNTIME/bot_ivr/call_jobs`.
No confundir este camino con los launch_campaign.php/stop_campaign.php legacy:
leer `app/web/config/Config.php` y el aislamiento antes de reutilizar endpoints.

### 4.3. Resultados, cierre y eventos tardíos

| Archivo local relativo | Riesgo o responsabilidad |
|---|---|
| `app/web/zynervox_queries/mysql/bot_ivr/calls.php` | `startCall`, `finishCall`, `recordCallEvent`, `ivrLeadStatus`, `observeCallPresence`, resultados IVR y protección del intento más reciente. |
| `app/web/zynervox_queries/mysql/bot_ivr/preanswer.php` | `savePreanswerResult`: puede corregir un resultado después del cierre si la clasificación llega tarde. |
| `app/web/bot_ivr/call_tracking_agi.php` | Identidad heredada del lead e integración con eventos del dialplan. |
| `app/web/bot_ivr/ivr_engine_service.php` | Ejecución IVR y contrato de entrada/snapshot. |
| `app/web/bot_ivr/ivr_graph_service.php` | Estados por nodos del flujo; distinguir resultados intermedios y finales. |
| `app/web/bot_ivr/preanswer_service.php` | Clasificación STT y resultados PREANSWER_*. |
| `app/web/bot_ivr/preanswer_worker.php` | Consumo de detecciones pendientes. |
| `installer/call-audit.sh` | Despliegue del cierre/recuperación de llamadas; seguir sus referencias antes de modificar recuperación. |

### 4.4. Transporte 7307 y anexos: preservar

Leer `docs/operations/7307-baresip.md` y, en el VPS, el documento operativo
`RUNTIME/modules/asterisk/7307.md`.

| Fuente local | Despliegue o uso |
|---|---|
| `app/web/bot_ivr/baresip_origin.php` | Adaptador PHP de reservas y origination. |
| `app/web/bot_ivr/baresip_context_agi.php` | Bind SIP de un solo uso contra intento reservado. |
| `asterisk/synervox/modules/bot_ivr/baresip_origin.py` | Broker instalado en `/usr/local/libexec/zynervoxv2-baresip-origin.py`. |
| `app/web/includes/DialplanOrigins.php` | Generador de contextos compartidos. |
| `installer/bot-ivr-baresip.sh` | Instalador existente; exige runs detenidos. No ejecutarlo para añadir únicamente reglas de reintento. |

Configuración privada: `/etc/zynervoxv2/baresip-origin.json`.
Leases: `RUNTIME/bot_ivr/baresip_leases`.
Recuperación: `zynervoxv2-baresip-sweep.service` y `.timer`.
Anexos permitidos: 4002–4050, compartidos con el sistema 3006 mediante reservas
atómicas en `synervox.dynamic_ivr_pilot_annexes`. El PHP aislado no tiene permisos
legacy; solo el broker privilegiado opera esa frontera.

Dialplan cargado relacionado: `/etc/asterisk/extensions-test.conf`, entrada de
7307 en test_cortar, y `RUNTIME/modules/asterisk/extensions-zynervoxv2.conf`.
No es necesario cambiar SIP, carrier1/881851, audio o dialplan para añadir pesos.
Si se concluye lo contrario, justificarlo y revisar el impacto antes de proceder.

### 4.5. Dashboard y caché compartida recién instalada

Leer primero `docs/operations/campaign-monitor-cache.md`.

| Fuente local | Función |
|---|---|
| `app/web/bot_ivr/campaign_monitor_view.php` | Presentación compartida, Play/Stop, estados y freshness. |
| `app/web/modules/admin/campaign_monitor_api.php` | GET de caché; POST live de Play/Stop por campaña. |
| `app/web/modules/admin/campaign_monitor_cache.php` | Lectura/publicación atómica y caducidad. |
| `app/web/modules/admin/campaign_monitor_worker.php` | Único proceso que agrega estadísticas cada 5 segundos. |
| `src/features/admin/models/zynervoxv2-campaign-monitor.service` | Unidad de systemd. |
| `installer/campaign-monitor.sh` | Instalación reproducible sobre el layout existente de mirmidon. |

Servicio activo documentado: `zynervoxv2-campaign-monitor.service`.
Snapshot: `RUNTIME/campaign_monitor/snapshot.json`; lock: `worker.lock`.
Conservar GET y render inicial sin fallback de consultas agregadas a BD.
Los controles de marcación y sus permisos siguen siendo operaciones live.

## 5. Comportamiento comprobado que condiciona el diseño

### 5.1. Elegibilidad actual

`dialCandidates` exige:

1. Lead de la lista del run y estado dentro de `config_json.statuses`.
2. `next_call_at IS NULL` o fecha ya vencida en UTC.
3. Ningún intento de ese lead dentro del mismo dial_run_id.
4. Ningún intento abierto no resuelto, salvo exclusiones actuales de recuperación.

El orden actual es UP/DOWN/RANDOM; RANDOM usa RAND(), no pesos. El claim se realiza
dentro de una transacción con bloqueos y comprobación del límite de canales.

Para permitir reintentos dentro de un run largo hay que rediseñar la condición 3,
con una barrera equivalente basada en intento anterior, fecha y límites. Eliminar
simplemente el NOT EXISTS produciría riesgo de rellamada inmediata y bucles.

### 5.2. Finalización y espera

El worker finaliza cuando no tiene llamadas activas y pending es cero. Ese pending
cuenta candidatos disponibles ahora; no representa reintentos programados futuros.
`bot_list_call_start` también falla si no hay pending inmediato.

Diseñar por separado: disponibles ahora, futuros pendientes, bloqueados por límite
y terminados. La campaña debe poder iniciarse/esperar con trabajo futuro cuando
esa sea la política, sin reclamar leads ni reservar anexos antes de tiempo.

No añadir un estado persistido waiting sin estudiar:

- La columna generada `active_list_id` y su índice único en migración 012.
- `transitionDialRun`, `dialMutationGuard`, Play/Stop y desactivación de campañas.
- Validaciones del broker Baresip, que dependen de estados activos conocidos.
- UI, monitor y consumidores que reconocen starting/running/stopping.

Una opción a evaluar es conservar state=running y añadir información pública de
espera/próxima fecha, preservando las exclusiones de concurrencia existentes.

### 5.3. Contadores y semántica de cierre

`startCall` incrementa called_count y last_call_at al crear el intento; no mide
contactos efectivos. No reinterpretar ese contador silenciosamente.
`finishCall` es idempotente frente a cierres repetidos y protege el estado actual
del lead contra resultados de intentos anteriores.

El preanswer puede finalizar su clasificación después del cierre. Programar dos
veces desde distintos callbacks, o ignorar ese cambio tardío, puede dejar una
próxima llamada incompatible con el resultado definitivo. Diseñar una decisión de
reintento idempotente ligada a attempt_id, con revisión solo del último intento.
Un IVR_NODE intermedio no debe disparar una próxima llamada antes del cierre.

No equiparar ANSWER con contacto efectivo. Verificar el significado real de
NO_RESPONDE_F1 y AMD_HANGUP en el flujo publicado; no asumir que el primero significa
que el teléfono nunca fue contestado ni que el segundo demuestra una máquina.

### 5.4. Política capturada y modificaciones

El servicio captura statuses, orden, flujo, firma de audio, TTS y CID en config_json.
Antes de trabajar, el worker compara la configuración actual con ese snapshot.
Añadir reglas exige definir su versión, defaults para runs antiguos y momento de
aplicación. Cambiar un array sin compatibilidad puede abortar el arranque.

Hoy la página guarda scheduled/start_time/end_time, pero el camino inspeccionado
de configuración/claim/loop no aplica una ventana de campaña. Verificar el VPS y
todos los consumidores; no asumir que mostrar esos campos garantiza su ejecución.

## 6. Base de datos: qué inspeccionar y qué no asumir

Migraciones existentes, bajo `LOCAL/src/features/bot_ivr/models/`:

| Archivo | Inspección necesaria |
|---|---|
| `002-campaign-lists.sql` | Padres campaña/lista/leads, pertenencia e importación. |
| `003-campaign-details.sql` | dial_statuses, lead_order, horario/metadatos de campaña. |
| `005-lead-dialer-fields.sql` | called_count, last_call_at, next_call_at e índice `(list_id,status,next_call_at,lead_id)`. |
| `007-call-attempts.sql` | Intentos, identidad call_id y orden attempt_id. |
| `008-call-audit.sql` | Eventos, cierre y auditoría. |
| `009-ivr-results.sql` | Resultados del flujo. |
| `011-ivr-status.sql` | Ampliación de status a VARCHAR(80); no tomar el VARCHAR(6) original como esquema final. |
| `012-dial-runs.sql` | Estado, config_json, FK lógica de intentos, índice activo único por lista. |
| `014-list-call-channels.sql` | Canales persistentes; el límite activo pertenece a la lista. |

Consultar de forma controlada el esquema efectivo de:

```sql
SELECT VERSION();
SHOW CREATE TABLE zynervox_core.zynervox_bot_campaigns;
SHOW CREATE TABLE zynervox_core.zynervox_bot_lists;
SHOW CREATE TABLE zynervox_core.zynervox_bot_list;
SHOW CREATE TABLE zynervox_core.zynervox_bot_dial_runs;
SHOW CREATE TABLE zynervox_core.zynervox_bot_call_attempts;
SHOW CREATE TABLE zynervox_core.zynervox_bot_call_events;
```

Revisar índices, collation de estados, UTC, generated columns, engine real,
permisos restringidos y compatibilidad MySQL/MariaDB. No asumir SKIP LOCKED u otras
funciones según una versión no comprobada. EXPLAIN debe preceder a cambios de
selección; EXPLAIN ANALYZE ejecuta trabajo y debe evaluarse antes de usarlo en vivo.

No ejecutar automáticamente los SQL iniciales ni reinicializar tablas. Crear una
migración aditiva versionada, según el esquema y las convenciones encontrados.
No otorgar acceso a bases legacy ni permisos globales para resolver un grant faltante.

`importLeads` reemplaza los contactos de la lista y reinicia sus campos de
marcación: investigar `app/web/zynervox_queries/mysql/bot_ivr/leads.php`. Si el límite
se define por teléfono entre campañas/importaciones, no basta con called_count
del lead actual, porque cambian los IDs y pueden existir teléfonos repetidos.

## 7. Investigación funcional y algoritmo propuesto

Proponer y justificar una primera versión pequeña con estas etapas:

1. Filtrar elegibilidad: activo, estado habilitado, fecha vencida, horario,
   límites de intentos y ausencia de llamada abierta.
2. Seleccionar un estado entre los disponibles usando sus pesos.
3. Dentro del estado, elegir por fecha vencida/antigüedad y desempate estable.
4. Reclamar de forma atómica y generar un call_id único.
5. Al cerrar, conservar el intento y decidir la próxima fecha o salida del ciclo.

Evaluar weighted round-robin u otra selección ponderada explicable que no ordene
toda la tabla por RAND() en cada canal. Resolver quién conserva los créditos del
selector cuando hay varias listas/workers de una campaña; pesos por campaña no
deben convertirse accidentalmente en una proporción diferente por lista.

Ejemplo conceptual, no valores definitivos: NEW=100, NO_RESPONDE_F1=60,
AMD_HANGUP=20. Con todos disponibles, proporción aproximada 5:3:1. Un estado sin
candidatos vencidos no debe bloquear los demás; peso cero implica exclusión.
Documentar reparto bajo concurrencia, reinicio y llegada continua de nuevos leads.

Decidir si max intentos significa total de llamadas o reintentos posteriores al
primero. Establecer límite global que no se reinicie al alternar resultados,
reiniciar el worker o pulsar Play. Separar fallos técnicos de contacto fallido.
Ante subida de CONG/UNAV, considerar pausa/circuito técnico con recuperación
controlada; nunca convertirlos automáticamente en número inexistente.

No reintentar una origination con confirmación AMI/SSH perdida hasta resolver la
presencia de la llamada. El comportamiento actual conserva claims ambiguos para
auditoría y no debe perderse por el nuevo bucle.

## 8. Compatibilidad y eficiencia: preguntas obligatorias

El informe del siguiente agente debe contestar con evidencia:

| Tema | Comprobación exigida |
|---|---|
| Opt-in y rollback | ¿Campañas sin reglas nuevas conservan exactamente un intento por run? |
| Persistencia | ¿Cómo sobreviven reglas, próximas fechas y límites a reinicios? |
| Estados | ¿Cuáles son intermedios, efectivos, definitivos y reintentables? |
| Cambios tardíos | ¿Cómo se corrige o cancela una agenda cuando llega PREANSWER después de FINISH? |
| Bloqueos | ¿Se conserva un orden de locks compatible entre claim, cierre, política y recovery? |
| Citas | ¿Se prioriza una devolución solicitada sin mezclarla con llamadas automáticas? |
| Canales/anexos | ¿Se mantienen límites live por lista y reservas globales del pool? |
| Mismo teléfono | ¿Qué pasa si aparece en dos listas/campañas o vuelve a importarse? |
| Espera | ¿Cómo se calcula el próximo vencimiento sin escanear todos los leads cada segundo? |
| Stop | ¿Interrumpe la espera con rapidez, evita nuevas reservas y deja drenar llamadas? |
| Horario | ¿Se comprueba antes del claim y al vencer una espera, con zona horaria explícita? |
| Snapshots | ¿Rules/config antiguas siguen decodificándose sin romper el worker? |
| Audios | ¿Se reutiliza preparación válida sin cambiar firmas/TTS por una regla de reintento? |
| Monitor | ¿Se publican espera/próxima fecha a través del único worker de caché, sin SQL desde navegadores? |
| Progreso | ¿Se separan contactos únicos, intentos acumulados, reintentos futuros y contactos efectivos? |

El contador terminado actual usa el intento más reciente del contacto. Al volver
a llamarlo puede pasar de terminado a en curso; eso no significa que se haya
perdido el intento anterior. Diseñar etiquetas y métricas coherentes con un ciclo
continuo, evitando porcentajes que se interpreten como éxito comercial.

La caché compartida elimina consultas repetidas por navegador, no las consultas
del worker de marcación. Optimizar la cola de reintentos sigue siendo necesario.
No usar una proyección de estadísticas por eventos como requisito: el usuario
eligió expresamente mantener el reporte mediante caché compartida.

## 9. Pruebas existentes que debe revisar el agente

Rutas bajo `LOCAL`; ejecutar en un entorno compatible y aislado, no a ciegas sobre
datos de campaña 50. Revisar primero fixtures y requisitos de cada archivo.

| Ruta | Cobertura relevante |
|---|---|
| `src/features/bot_ivr/tests/list-call-worker.php` | Un intento por lead/run, concurrencia, canales live, Stop, pool agotado y claims ambiguos. |
| `src/features/bot_ivr/tests/call-audit-agi.php` | Seguimiento AGI e identidad heredada. |
| `src/features/bot_ivr/tests/preanswer-db.php` | Clasificación y resultados tardíos. |
| `src/features/bot_ivr/tests/campaign-deactivate-db.php` | Desactivación con runs/llamadas abiertas. |
| `src/features/bot_ivr/tests/list-import-db.php` | Reemplazo de lista y reinicio de campos de marcación. |
| `src/features/bot_ivr/tests/campaigns-db.php` | Validación y persistencia de campaña. |
| `src/features/bot_ivr/tests/campaign-monitor-db.php` | Semántica de contactos y monitor. |
| `src/features/bot_ivr/tests/baresip-origin.py` | Reservas de anexos y broker, sin llamadas externas. |
| `src/features/bot_ivr/tests/baresip-dialplan.php` | Contextos/generador, sin necesidad de modificarlos. |
| `src/features/zynervox_queries/tests/list-call-channels.php` | Persistencia y control de capacidad. |
| `src/features/admin/tests/campaign-play.php` | Play/Stop de ambas familias, CSRF y GET sin agregación SQL. |
| `src/features/admin/tests/campaign-cache.php` | Snapshot compartido, antigüedad y conservación tras fallo. |
| `src/features/admin/tests/ivr-monitor.php` | Render y API; admite usuario admin activo por variable de entorno para smoke test. |

Casos nuevos mínimos del futuro desarrollo:

- 200 leads: cada primer intento se registra una vez; solo los estados habilitados
  se reagendan; no se llama de nuevo antes de next_call_at.
- No hay elegibles ahora, sí futuros: espera sin finalizar ni consumir anexo.
- Se agota el límite: sale del ciclo incluso si cambia entre NO_RESPONDE y AMD.
- Mix ponderado verificable y atención a estados de menor peso.
- Dos workers/campañas compiten: ningún claim simultáneo del mismo contacto;
  aplicar también la política definida para teléfonos repetidos.
- Stop durante espera, claim y llamada activa; reanudación sin reset de límites.
- FINISH/PREANSWER/IVR duplicados o desordenados; un cierre antiguo no modifica
  el estado ni la agenda correspondientes al intento nuevo.
- Error de red ambiguo: no origina una segunda llamada por un timeout.
- Reinicio, caída entre claim y agenda, política antigua/ausente y horario cruzando
  medianoche. Pruebas de tiempo con reloj controlado, sin esperas reales de horas.
- Importación posterior, métricas de caché y regresión del modo sin reintentos.

## 10. Investigación externa y referencias iniciales

Consultar nuevamente las fuentes: las funcionalidades de proveedores cambian.
Separar prácticas documentadas de decisiones específicas propuestas para Zynervox.

- Genesys, controles de intentos y recalls por resultado:
  https://help.genesys.cloud/articles/configure-attempt-limits-entry/
- Amazon Connect, reglas de reintento, límites y ventanas de comunicación:
  https://docs.aws.amazon.com/connect/latest/adminguide/guided-campaign-builder.html
- Amazon Connect, prioridades y limitaciones de AMD:
  https://docs.aws.amazon.com/connect/latest/adminguide/outbound-campaign-best-practices.html

Estas referencias fundamentan separar prioridad, espera, límites y clasificación.
No justifican un número universal de llamadas ni hacen infalible a AMD/STT.
No migrar a esos servicios: se investiga el diseño para el motor propio existente.

## 11. Plan de trabajo y entregable requerido del otro agente

### Fase A: investigación sin cambios productivos

1. Leer reglas/contratos y verificar Git, rutas y revisiones local/remota.
2. Trazar un intento completo de la campaña de referencia con auditoría existente,
   sin originar llamadas adicionales ni insertar contactos de prueba en su lista.
3. Inspeccionar esquema, índices, permisos, versiones y servicios con lecturas
   acotadas. No ejecutar agregaciones costosas repetidas como diagnóstico.
4. Resolver las preguntas funcionales y de compatibilidad de este documento.
5. Diseñar contratos, política, algoritmo, migración, locks y recuperación.

Crear como resultado propuesto:
`LOCAL/docs/research/REINTENTOS_INTELIGENTES_ANALISIS_COMPATIBILIDAD.md`.
Ese archivo aún no existe por esta tarea; lo redactará el siguiente agente.

Debe contener: revisión investigada y fecha; hallazgos con archivo/función/línea;
diagrama de estados; decisiones confirmadas y pendientes; modelo de datos;
matriz de cambios por ruta; compatibilidad de runs anteriores; estrategia para
clasificaciones tardías; plan de pruebas; consultas/índices; impacto en caché,
canales y pool; instalación detallada, observabilidad, rollback y criterios de éxito.

### Fase B: desarrollo y pruebas aisladas

Solo tras resolver el análisis y contar con autorización para implementar.
Cambios pequeños por módulo, contratos aprobados y migración aditiva. No alterar
7307, carrier, datos de leads, flujos publicados ni credenciales para facilitar tests.
No asumir que la autorización para escribir este documento autoriza llamadas reales.

### Fase C: instalación controlada

Seguir las herramientas de soporte remoto y el flujo local-first del entorno.
Skill conocida: `C:/Users/Diego/CodexProfiles/SkillsCompartidas/zynertools/SKILL.md`.
Leerla al retomar: cambios remotos requieren autorización acorde a sus reglas.
No revelar llaves, contraseñas, tokens SIP/AMI/TTS ni configuraciones privadas.

Antes del despliegue: backup de archivos y esquema, revisión de runs/canales,
drenado de las listas afectadas y compatibilidad de workers activos. No desplegar
un cambio de config_json/selección con workers antiguos ejecutándose sin demostrar
compatibilidad. Instalar grants mínimos y dependencias comprobadas, si existen.

Activar inicialmente la nueva política deshabilitada. Validar la migración y modo
anterior, después una campaña/lista de QA separada con datos autorizados. No usar
la campaña 50/lista productiva para insertar pruebas. Las llamadas externas y el
ensayo de carga deben tener autorización específica y límites observables.

Rollback debe desactivar la política nueva y restaurar fuentes compatibles sin
borrar intentos, agendas ni auditoría. Definir qué pasa con los reintentos futuros
antes de volver al modo antiguo, que también filtra next_call_at.

No reiniciar Asterisk ni regenerar su dialplan si el cambio se limita a políticas,
selección y agenda. No hacer push a GitHub por inferencia: informar commits y
pendientes y respetar la autorización vigente para publicar.

## 12. Criterio de éxito final

El sistema selecciona contactos elegibles según pesos explicables, espera entre
intentos, respeta límites y horarios, se recupera tras reinicios y se detiene con
el botón existente. No duplica llamadas, no monopoliza anexos durante esperas,
no pierde intentos ni reclasifica resultados antiguos sobre contactos nuevos.
El Dashboard conserva su lectura compartida y muestra progreso comprensible.
Campañas sin la nueva política mantienen el comportamiento previo.

Este documento no instala el motor ni activa reintentos en la campaña 50.
