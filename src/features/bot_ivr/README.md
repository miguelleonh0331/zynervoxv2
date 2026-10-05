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

La ventana diaria se almacena; su ejecución automática y la carga de leads
quedan para otra etapa. La cuenta de BD y contraseña compartida acordada con
el administrador aún requieren integración en el instalador (sin secretos en Git).

Prueba SQL con datos revertidos:
`php src/features/bot_ivr/tests/campaigns-db.php /ruta/web/bot_ivr`
