# bot_ivr

Código web en `app/web/bot_ivr` y workers en
`asterisk/synervox/modules/bot_ivr`. Opera campañas, TTS, colas y eventos IVR.


## Conexión configurable

En `index.php`, el administrador puede configurar servidor, puerto,
usuario y contraseña; la base queda fija en `zynervox` mediante «Configurar conexión a base de datos».
Se prueba la conexión antes de guardar. La contraseña vacía conserva la actual.
La web y los workers leen `/etc/asterisk/synervox/secrets/bot_ivr_db.json`;
sin ese archivo bloquean la operación y solicitan configurar la conexión desde el botón.
El directorio debe existir y permitir escritura al usuario PHP. El archivo se
crea con permisos 0640: los workers deben poder leerlo mediante el grupo del
usuario PHP. Las credenciales nunca se guardan en el repositorio.
Los procesos ya iniciados conservan su conexión hasta terminar: cambiar la
configuración con las campañas detenidas.


## Base propia para campañas

`models/001-zynervox.sql` crea `zynervox` y once tablas vacías de Bot IVR,
sin importar datos ni credenciales de CARSA. Conserva la cola local
`carsa_initial_survey` y las tablas auxiliares de procesos, audios y llamadas.
Se ejecuta por administración; el formulario solamente prueba y guarda conexión.
La base `synervox` de la demo no se modifica. PHP y workers rechazan otra base
sin recurrir a configuración general. El guardado operativo debe realizarse
mediante «Configurar conexión a base de datos» antes de crear campañas.
En mirmidon la ruta desplegada de secretos usa `/etc/asterisk/zynervoxv2205/`.
El selector de flujos sigue consumiendo la API existente de `ivr_builder`.
Esta etapa no añade aislamiento multiempresa ni valida el motor de llamadas.


## Campañas → listas → leads

Aplicar `models/001-zynervox.sql` y después `models/002-campaign-lists.sql`.
La segunda migración es aditiva y no elimina datos legacy.

- `zynervox_bot_campaigns`: campaign_id, name, active y ventana diaria
  scheduled/start_time/end_time (activación/bloqueo), más timestamps.
- `zynervox_bot_lists`: list_id, campaign_id, name, active y timestamps.
- `zynervox_bot_list`: lead_id, list_id, phone, customer_name, extra_json y timestamps.

`index.php` muestra primero campañas, con botón Crear campaña. El formulario
se abre aparte (`?view=create`) y pide nombre/activo; genera ID automáticamente
sin adjuntar leads ni exigir flujo. Redirige a `campaign_edit.php?id=...`, donde
se guardan horarios y se crean/editan listas propias. Los tres repositorios
usan solo el esquema nuevo; no invocan procesos legacy.
Las FK restringen eliminaciones de padres con hijos. Formularios protegidos
por sesión de administrador, CSRF y consultas preparadas; salida HTML escapada.
La configuración de conexión sigue disponible desde ambas pantallas.

La ventana diaria se almacena; su ejecución automática queda para otra etapa.
La carga de leads está disponible desde el detalle de cada lista. La cuenta de BD y contraseña compartida acordada con
el administrador aún requieren integración en el instalador (sin secretos en Git).

Prueba SQL con datos revertidos:
`php src/features/bot_ivr/tests/campaigns-db.php /ruta/web/bot_ivr`


## Detalle de ancho completo en dos columnas

Aplicar `models/003-campaign-details.sql` tras 001 y 002. Es aditiva e idempotente
(MariaDB ADD COLUMN IF NOT EXISTS). La referencia inspeccionada fue
`asterisk.vicidial_campaigns` en mirmidon; no se modifica esa tabla ni su código.
El detalle usa todo el ancho del contenido: izquierda datos generales/horarios,
derecha parámetros de marcación. Se apila en pantallas de hasta 1050 px.
Listas y leads se muestran como totales calculados, sin duplicar datos guardados.

Nuevos campos: campaign_description(255), user_group(20), dial_method,
lead_order, dial_statuses(255), hopper_level, auto_dial_level,
dial_timeout, dial_prefix(20), campaign_cid(20), campaign_recording, max_channels.
Las opciones de método y grabación son un subconjunto de las opciones reales
VICIdial; orden de leads usa el subconjunto propio DOWN/UP/RANDOM.
Los estados se guardan como códigos únicos mayúsculos de 1-6 caracteres,
separados por espacios, sin delimitadores legacy (por ejemplo NEW NA B).
Validación propia: hopper 0-1000000, nivel automático 0-20 con hasta dos decimales,
timeout 1-255 segundos, canales 1-1000; prefijo/caller ID aceptan +, dígitos, * y #.
Campos desconocidos no se interpolan en SQL y no se guardan.
Se preservan metadatos al enviar un formulario anterior sin estos campos.
Estos parámetros no controlan todavía el motor ni permisos de grupos.


## Abrir lista y cargar su base

campaign_edit.php muestra fecha de creación y botón Abrir lista para cada lista.
list_edit.php?id=<list_id>&campaign_id=<campaign_id> presenta los datos de la
lista, formulario TXT, plantilla autenticada y leads paginados (50 por página).
La carga solo se hace dentro de una lista, nunca al crear la campaña.

list_service.php valida UTF-8/BOM, CSV de 2-10 columnas y numero obligatorio;
normaliza cabeceras y guarda valores originales en extra_json. Detecta filas
inválidas y reporta motivos (máximo cinco ejemplos), duplicados en archivo y
existentes en la lista. No elimina datos ni normaliza TTS. Dos listas distintas
pueden contener el mismo número. Procesa hasta 50000 registros/10 MB, sujeto a
upload_max_filesize/post_max_size del servidor (mirmidon: 2M/8M al verificar).
Importación usa bloqueo transaccional de la lista, consultas por lotes y
inserciones por lotes de 100. Configuración mediante botón sigue obligatoria.

Prueba con datos de prueba revertidos:
`php src/features/bot_ivr/tests/list-import-db.php /ruta/web/bot_ivr`
