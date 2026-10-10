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


## Consultas compartidas PHP

Administración nueva usa app/web/zynervox_queries/factory.php y el contrato
BotIvrRepository; SQL agrupado por motor y módulo. MySQL/MariaDB disponibles en
Configurar conexión a base de datos. Config previa sin engine sigue funcionando.
La carga solo muestra resultado/conteo; workers y motor legacy quedan para después.
Ver src/features/zynervox_queries/README.md para añadir módulos/motores y pruebas.


## Reemplazo de base por lista (2026-10-05)

Reemplazar base valida TXT antes de escribir y sustituye exclusivamente los leads
de la lista abierta. Duplicados se descartan dentro del nuevo archivo. Archivos
sin filas válidas conservan la base anterior. Bloqueo del padre, DELETE por list_id
e INSERT comparten transacción; fallo revierte; transacción externa usa savepoint.
No modifica metadatos de lista/campaña ni otras listas. Prueba list-import-db.php
verifica reemplazo, reupload, actualización de teléfono retenido, archivo vacío,
fallo tras borrar con rollback y aislamiento entre listas; datos de prueba revertidos.


## Asignar flujo de IVR Builder a lista

Aplicar models/004-list-flow.sql tras 001-003. Crear lista y Modificar permiten
indicar id_flujo positivo. Listas existentes muestran Sin asignar hasta guardarlo.
Guardar ID no ejecuta audio ni verifica publicación; integración de generación
por lista queda pendiente y deberá consumir el flujo por contrato público.
Prueba: php src/features/bot_ivr/tests/list-flow-db.php /ruta/web/bot_ivr (rollback).

## Prueba gTTS de CARSA (2026-10-05)

Trazado confirmado: campaign_console.php opción macelioai (gTTS gratis) →
launch_campaign_prebuild.php → services/dynamic_ivr/prebuild_campaign_audios.py →
services/dynamic_ivr/tts_providers.py → services/tts/generate_macelioai_wav.py.
Base de referencia: /srv/www/htdocs/synervox/encuestas/carsa. Python:
venvs/gtts_env/bin/python. Generador recibe --text, --output, --lang es y --speed 1.3.
Usa gTTS tld=com, slow=False, timeout=25; MP3 temporal, ffmpeg a WAV PCM
8000 Hz mono s16 y sox tempo. workers de prebuild controlan concurrencia,
no la velocidad de voz. Referencia CARSA no es dependencia instalada de Bot IVR.

Prueba aislada ejecutó generador real con texto ficticio, sin BD ni llamadas.
Salida /tmp/zynervox_gtts_demo_20261005.wav: 7.51 s, 8000 Hz, mono, PCM16.
Copia MP3 para escucha /tmp/zynervox_gtts_demo_20261005.mp3. Archivos descargados
al espejo local zynertools. Próxima integración debe copiar/adaptar generador sin
rutas CARSA, dependencias propias y jobs por listas con id_flujo; no reutilizar IDs legacy.

## Laboratorio de audio: prueba desde texto

Entrar por Prueba de audios en navegación Bot IVR o bot_ivr/audio_lab.php. Elegir
gTTS, escribir hasta1000 caracteres y Crear audio. Historial de cinco resultados
con reproductor y Descargar WAV. Español, velocidad1.3, PCM16 mono8000Hz.
Sin campañas/leads/flujo ni conexión SQL para generar. Sesión admin y CSRF obligatorios.

Instalación (rutas adaptar según despliegue):
- Python3.11: crear venv /etc/asterisk/synervox/venvs/audio_lab y pip install -r
  src/features/bot_ivr/models/audio-lab-requirements.txt (gTTS2.5.4 probado).
- Instalar ffmpeg/sox host y script asterisk/synervox/modules/bot_ivr/audio_lab_generate.py.
- Crear /var/lib/asterisk/synervox/bot_ivr/audio_lab, propietario usuario PHP, modo0700.
- Desplegar audio_lab.php/audio_lab_service.php y navegación campaigns_page.php.
En mirmidon synervox en rutas de runtime se adapta a zynervoxv2205.
Audios modo0600 fuera webroot, accesibles por endpoint autenticado. Cinco audios
por sesión; sesiones abandonadas no tienen purga periódica en esta prueba.

Proveedor separado mediante registro PHP y ejecutor Python. Para otro servidor,
crear adaptador explícito y configuración privada, conservando formulario/resultado;
RGA ya dispone de adaptador real; no se permiten URLs arbitrarias desde el formulario.
Prueba php src/features/bot_ivr/tests/audio-lab.php /ruta/web/bot_ivr con usuario PHP.
Verificación mirmidon: POST HTTP real, redirect, streamWAV, desconocido404,
CSRF, validaciones y generación con venvpropio; navegador reproduce sin error.


## RGA — Remote Generation Audio

Selector del laboratorio incluye gTTS local y RGA. RGA envía texto al gateway
172.16.10.26:8820/v1/audio/speech con es,1.3,wav. No convierte fallos a gTTS local.
Instalar configuración según models/audio-lab-rga.example.json en
/etc/asterisk/synervox/secrets/audio_lab_rga.json (adaptar nombre despliegue).
Token real solo en secretos del servidor, nunca Git/browser; permisos0640 root:grupoPHP.
En mirmidon grupoApache es www (runuser CLI puede usar wwwrun, no es el mismo grupo).
Archivo de configuración en /etc/asterisk/zynervoxv2205/secrets/audio_lab_rga.json.
requests2.34.2 ya estaba instalado en el venv de gTTS, ahora dependencia explícita.

No seguir redirects; descarga streaming≤10MB y validación monoPCM16 8kHz. PHP
180s, solicitud conexión5/lectura145 con deadline150. Traduce errores remotos a
mensajes fijos. Historial identifica proveedor de cada audio y mantiene ownership.
Health puede mostrar candidatos sin garantizar generación. Al 2026-10-06,
prueba desde mirmidon logró generación RGA real y HTTPPOST→WAV reproducible;
manual temporal anterior indicaba fallos de proxies, no representa esta prueba.
Suite aislada sin credenciales: python tests/test_audio_lab_rga.py /ruta/audio_lab_generate.py.


## Campos de preparación del discador (2026-10-10)

Aplicar models/005-lead-dialer-fields.sql a la base configurada de Bot IVR
(mirmidon: zynervox_core); la migración no fija USE ni modifica VICIdial.
A?ade status VARCHAR(6) NOT NULL DEFAULT 'NEW', called_count INT UNSIGNED
NOT NULL DEFAULT 0, last_call_at DATETIME NULL y next_call_at DATETIME NULL.
NEW identifica un contacto sin intento; called_count cuenta intentos de marcación.
Las fechas deben escribirse en UTC. El futuro motor define las transiciones de
estado y la política de reintentos; ningún campo dispara llamadas por s? mismo.
índice idx_bot_list_dial_queue: list_id, status, next_call_at, lead_id.

Migración aditiva e idempotente para MariaDB 10.6, sin borrar leads. Los contactos
existentes y nuevas importaciones reciben NEW/0/NULL/NULL. Reemplazar base conserva
su semántica: elimina leads de esa lista y crea contactos nuevos con valores
iniciales; no conserva su historial ni sus estados anteriores. No reemplazar una
lista mientras el futuro motor la procesa. Historial por intento y reserva
concurrente de leads quedan pendientes de implementar con el motor.

DDL realiza commit impl?cito; no se revierte mediante ROLLBACK. Ejecutar por
administración, nunca desde list_edit.php. Verificar con la prueba existente:
php src/features/bot_ivr/tests/list-import-db.php /ruta/web/bot_ivr

## ?rea de creación de audios por lista (2026-10-10)

list_edit.php muestra una tarjeta a todo el ancho debajo de datos/carga.
Proveedor TTS reutiliza catálogo del laboratorio (gtts/rga), predeterminado RGA.
Velocidad 1/3/10/25/60/100x indica concurrencia, predeterminado 25x. Generar
permanece deshabilitado con mensaje visible hasta conectar un generador por lista.
No guarda ajustes, publica jobs, genera archivos ni llama endpoints legacy.

## Validación previa de variables (2026-10-10)

Al abrir list_edit.php se lee el flujo publicado mediante el contrato público
de IVR Builder y los leads por audioLeads del repositorio. La sección inferior
muestra leads listos y errores (máximo cinco ejemplos). La sustitución estricta
en list_audio_service.php mantiene variables del TXT, detecta ausentes/vacías y
no envía al proveedor textos incompletos. En flujo 11, {nombre} proviene del lead.
Prueba pura: php src/features/bot_ivr/tests/list-audio.php /ruta/web/bot_ivr.
Prueba de lectura SQL/aislamiento: list-import-db.php (rollback de datos de prueba).
Generación, progreso y caché por lista siguen pendientes; no se usa CARSA.

## Generación local de listas con gTTS (2026-10-10)

Generar ahora inicia list_audio_worker.py en segundo plano para los textos
validados. gTTS -> MP3 -> ffmpeg PCM16/8000Hz/mono -> sox tempo1.3. Caché aislada
en <runtime>/sounds/cache/ivr_builder/gtts, WAV por SHA256 de texto/perfil.
Se reutilizan archivos válidos; archivos corruptos se regeneran. Lock por hash
y publicación atómica evitan duplicados/archivos parciales. Progreso refresca
sin recargar la página: generados, reutilizados, fallidos y procesados.

Requisitos: venv web venvs/gtts_env con gTTS, /usr/bin/ffmpeg y /usr/bin/sox;
worker instalado en <runtime>/modules/bot_ivr/list_audio_worker.py. Caché y
<runtime>/bot_ivr/audio_jobs necesitan escritura del usuario PHP y permisos de
grupo para Asterisk. macelioai-tts.sh prepara esos destinos en instalaciones
futuras. Despliegues de cambios copian archivos y preparan directorios; no
requieren reinstalar el servicio ni reiniciar Asterisk.

Pruebas: list-audio.php (variables/payload) y list-audio-cache.py (hash, reutilización,
corrupción, concurrencia y limpieza de parciales). Sin llamadas ni SQL en worker.
Generación compuesta/pausas, RGA y motor de reproducción quedan pendientes.
