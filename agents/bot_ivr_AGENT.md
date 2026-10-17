# bot_ivr_AGENT.md

## Rol

Agente especialista del módulo `bot_ivr` **y del constructor de flujos
`ivr_builder`**: campañas automáticas, listas, generación de audio TTS, motor
IVR en llamada, editor visual de flujos y su publicación.

Los dos van juntos por decisión fundada: `bot_ivr` llama a `ivr_builder` desde
siete archivos, comparten la configuración de base por contrato explícito, y
el nodo «Reenviar» lo ejecuta un AGI que vive en `bot_ivr`. Un especialista
limitado al editor escalaría en la mitad de los casos: el síntoma aparece en
el editor y la causa está en el motor.

`ivr_builder` conserva su propio `CONTRACT.md` —es el único del repositorio
que nombra firmas de función y excepciones— y hay que respetarlo igual.

## Contexto principal

1. `src/features/bot_ivr/CONTRACT.md` — 43 secciones, append-only
2. `src/features/ivr_builder/CONTRACT.md` — firmas de API
3. `docs/AUDITORIA_2026-10-17.md` — divergencias de este módulo

## Dónde vive el código

```text
app/web/bot_ivr/                  web, servicios, AGI del motor IVR
app/web/ivr_builder/              editor visual, API de flujos, publicación
asterisk/synervox/modules/bot_ivr/  workers Python
asterisk/synervox/modules/flows/published/  destino de los JSON (vacío en Git)
src/features/bot_ivr/models/      migraciones y units systemd
src/features/ivr_builder/tests/   forwarding, store, export, AGI
```

Trampa de nombres: `app/web/ivr_builder/api_flows.php` y compañía llaman a
`bot_ivr_db()` de `app/web/lib/db.php` y consultan tablas `bot_ivr_flows*`.
**Eso es del editor, no del módulo bot_ivr.** La migración
`001_relational_ivr_flows.sql` también es del editor.

## Puede modificar

```text
src/features/bot_ivr/**
src/features/ivr_builder/**
app/web/bot_ivr/**
app/web/ivr_builder/**
asterisk/synervox/modules/bot_ivr/**
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
app/web/includes/**
app/web/modules/**
app/web/zynervox_queries/**
src/features/<otro_módulo>/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

## Promesas vigentes — bot_ivr

El contrato es append-only con nueve cadenas de sustitución ya reconciliadas.
Lo vigente:

1. **Aislamiento:** sólo tablas propias en la base configurada. Nunca la base
   `zynervox` productiva ni tablas de secretos, configuración o acceso de core.
2. Los endpoints legacy de lanzamiento y de trabajos TTS deben seguir
   devolviendo **409** por `Config::requireLegacyRuntime()`.
3. **Todo SQL pasa por el contrato público `BotIvrRepository`.** Sin SQL
   propio en el módulo.
4. Secretos y tokens sólo en el directorio privado, nunca en Git ni en
   formularios.
5. Validación de variables por lista: `nombre` admite fallback a
   `customer_name`; `numero`, `lead_id`, `list_id` y `campaign_id` **no se
   sobrescriben** con columnas importadas; texto de más de 1000 caracteres,
   JSON inválido o marcador sin resolver impiden preparar el lead; **el cero
   es un valor válido**.
6. La pantalla muestra conteos y hasta 5 errores con lead, nodo y variable,
   **sin valores personales**.
7. Generar audio exige sesión admin, CSRF, pertenencia campaña/lista, flujo
   publicado y `workers` en {1, 3} ∪ {5..100 paso 5}.
8. **El hash de audio** es SHA256 del JSON canónico del texto normalizado más
   un perfil fijo. La concurrencia no lo cambia; gTTS conserva sus hashes
   históricos.
9. Publicación atómica con `flock` por hash, rename, 0640 y validación WAV
   PCM16 mono 8000 ≤10 MiB. Corrupto se regenera. **Sin fallback entre
   proveedores.**
10. El archivo de progreso guarda el mapa lead/nodo/hash **sin textos ni datos
    de contacto**; el input privado vive fuera del webroot y se borra al
    terminar.
11. El estado de generación nunca devuelve PID, starttime, textos ni rutas.
12. Detener audios exige CSRF, `job_id` y token exacto de ejecución; la
    identidad del proceso se verifica por `/proc`, UID, cmdline y starttime.
    **Jamás el PID enviado por el navegador, jamás `pkill`.**
13. Importación TXT: 2–10 cabeceras normalizadas sin vacías ni repetidas,
    `numero` obligatorio de 1–20 dígitos **sin recortar prefijos**, límites de
    10 MB / 50000 filas / 1000 caracteres. Se valida el archivo **completo**
    antes de escribir; un archivo sin filas válidas no borra nada.
14. Reemplazar base resetea estados y no conserva historial. No reemplazar una
    lista mientras su motor la procesa.
15. Migraciones aditivas e idempotentes, ejecutadas por administración y
    **nunca desde `list_edit.php`**: el DDL no revierte por ROLLBACK.
16. El origen de llamada por lista sale **sólo** de `Carriers::dialOrigins()`;
    una opción no registrada se rechaza y **no borra el prefijo anterior**.
17. Play exige origen guardado disponible, firma de audio lista y flujo sin
    cambios (`flow_sha256`). Un worker desacoplado por lista. Stop impide
    nuevas reservas y drena las activas. Un ACK ambiguo de AMI **retiene** la
    reserva hasta la recuperación por auditoría.
18. Motor IVR: **sólo CLI**, exige despliegue aislado, flujo publicado y
    hashes en SQLite **read-only**. Sin validar WAV ni generar TTS en tiempo
    de llamada. Grafo acíclico alcanzable de hasta 100 nodos. Las variables
    capturadas no sobrescriben identificadores de sistema.
19. Credenciales de los proveedores de voz sólo en el JSON privado, nunca en
    publicaciones de flujo, Git o auditoría. Las URL sólo sustituyen valores
    de query contra una lista blanca exacta de host y path.
20. `inject_sql` sólo sobre campos de la lista permitida, idempotente por
    llamada y nodo.
21. Auditoría: journals privados atómicos, replay cada minuto, borrado sólo
    tras commit. **START es fail-closed.** La recuperación marca `LOST` tras
    5 minutos con 2 de gracia y nunca con journals pendientes.
22. El estado de negocio es independiente de `dial_status` y sólo se aplica si
    el intento es el más nuevo del lead.
23. Grabaciones: nivel 9, `realpath` acotado al directorio, sin symlinks,
    `no-store`.
24. Desactivar una campaña se rechaza con marcación activa o llamadas
    abiertas.

## Promesas vigentes — ivr_builder

1. En v2 aislado usa conexión propia de core y tabla de despliegue propia.
   **Publicar no envía flujos al API Asterisk productivo**: almacena su JSON.
   No altera dialplan ni activa llamadas.
2. La conexión se administra en Servicios › Base de datos y **se comparte con
   Bot IVR**. Dependencia bidireccional real.
3. Un flujo publicado conserva hash, nodos y transiciones válidas.
4. Lectura pública: `ivr_builder_published_flow(int $flowId): array`, sólo el
   JSON publicado de código `01..99`. Inexistencia, publicación inválida o
   archivo de más de 10 MB lanzan `RuntimeException`.
5. Nodo Reenviar: `ivr_forwarding_config(array $node): array` y
   `ivr_forwarding_render(array $node, array $variables): array`. Headers
   `X-*` o `User-to-User`, únicos, máximo 16. **No se envían credenciales SIP
   ni se registran valores de headers en auditoría.**
6. `forwarding.protocol` acepta SIP o PJSIP; ausente conserva PJSIP. El runner
   verifica cada slot nuevo y **limpia sólo esos slots** al salir, sin borrar
   headers anteriores. Se rechazan escapes con barra invertida en valores SIP.

## Qué consumo

| Módulo | Qué |
|---|---|
| `core` | `Database::getBotIvrConfig/Instance`, `Carriers::dialOrigins`, `PbxAmi`/`AmiClient`, `Auth`, `Config::deployment` y el gate 409 |
| `zynervox_queries` | **todo el SQL**, vía `botIvrRepository()` |
| `admin` | el sidebar y el helper de caché del monitor |
| externos | venv gTTS, workers Python, broker root por sudo para Baresip, JSON privado del motor, varias units systemd |

Tablas legacy que el código web todavía consulta: `synervox_campaigns`,
`synervox_campaign_processes`, `carsa_initial_survey`, `synervox_bot_agents`.

## Quién me consume

- `modules/admin/campaign_monitor_api.php` y `campaign_monitor_worker.php`
  requieren `bot_ivr/db.php` y `list_call_service.php` **por nombre de
  función**.
- `modules/admin/index.php` y `campaign_monitor.php` incluyen la vista del
  monitor, que vive acá.
- El dialplan generado por core incrusta **rutas absolutas** a
  `bot_ivr/ivr_engine_agi.php`, `call_tracking_agi.php` y
  `forward_headers_agi.php`.
- `IsolatedGate` permite **cualquier** ruta bajo `bot_ivr/` e `ivr_builder/`:
  dos comodines.
- Pruebas de otros módulos congelan mis nombres de función.

## Reglas específicas del módulo

Los cinco cambios que parecen inocentes y rompen todo:

1. **Añadir, quitar o renombrar una clave del array de configuración del
   run.** El worker lo compara con igualdad estricta contra el de la base al
   arrancar: si divergen, **falla Play en todas las listas**. Ya pasó una vez;
   hay un commit que lo arregla.
2. **Tocar la lista de canales permitidos en un solo lugar.** Está duplicada
   **cuatro veces**: `list_call_api.php`, el repositorio, `list_edit.php` y
   `modules/admin/campaign_monitor_api.php`.
3. **Retocar el texto normalizado, el perfil TTS o el JSON canónico del
   hash.** Un espacio o el orden de las claves invalida **toda la caché
   publicada**: Play queda bloqueado en cada lista hasta regenerar, y los runs
   históricos dejan de resolver sus audios.
4. **Renombrar `db.php`, `list_call_service.php` o sus funciones.** Están
   cableadas por `require` y por nombre desde admin, desde el scheduler y
   desde los stubs de las pruebas de admin.
5. **Cambiar el nombre, la ruta o el orden de argumentos de un AGI.** Están
   incrustados en el dialplan generado y en los `.conf` del servidor. Romperlo
   deja llamadas sin tracking —y `START` es fail-closed— **sin ningún error
   visible en la web**. Exige regenerar y recargar dialplan.

### Divergencias conocidas

- **El motor de reintentos inteligentes está implementado de punta a punta y
  no tiene sección de contrato.** Migración 015, tablas propias, lógica en
  cinco archivos. El contrato sigue diciendo que «queda pendiente de
  implementar».
- **El horario de campaña sí dispara llamadas**, por un timer cada 15
  segundos, mientras el contrato afirma que es «configuración persistida, no
  un disparador».
- **«Play requiere canales enviados iguales a los guardados» se valida sólo en
  JavaScript.** Un POST directo con otro valor permitido arranca la marcación.
- Siete endpoints del módulo escriben en tablas legacy **sin** el gate 409 que
  sí tienen sus hermanos.
- Las secciones del laboratorio de audio describen una función desactivada en
  v2 aislado, mientras el enlace se sigue publicando.
- El README dice que la base «queda fija en `zynervox`»; en aislado no es así,
  y los workers Python legacy abortan por eso.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| El SQL, los repositorios, la forma de `campaignMonitor()` | `zynervox_consultas` | «¿Esta firma ya existe en `BotIvrRepository` o hay que contratarla?» |
| La conexión, el gate 409, la lista blanca, sesión | `zynervox_nucleo` | «¿Qué devuelve `getBotIvrConfig()` acá y qué rutas permite el gate?» |
| Dialplan, contextos, troncales, el origen de llamada | `zynervox_telefonia` | «¿Puedo pedir este contexto sin romper la plantilla común?» |
| El monitor en pantalla, la caché, Play/Stop desde el dashboard | `zynervox_panel` | «¿Qué acciones acepta hoy el API del monitor y con qué CSRF?» |
| Marcación con agentes, campañas de tipo `agents` | `zynervox_discador` | «¿`Dialer::campaignMonitor()` devuelve la misma forma que la mía?» |
| Instalar workers, units, permisos del venv | `zynervox_instalador` | «¿Qué instala y con qué dueño y modo?» |

## Debe escalar al ARCHITECT_AGENT si

- necesita cambiar el hash de audio o el formato de publicación;
- necesita contratar el motor de reintentos o el scheduler de horario;
- necesita cambiar una firma que otro módulo consume por nombre;
- necesita modificar otro módulo o un `CONTRACT.md`;
- necesita crear una tabla o columna;
- no está claro si el cambio pertenece a `bot_ivr` o a `ivr_builder`.

## Salida esperada

Archivos modificados, cambio realizado, motivo, pruebas ejecutadas, impacto
sobre el contrato, riesgos. Si se tocó un AGI o el dialplan, indicar si quedó
recarga pendiente.
