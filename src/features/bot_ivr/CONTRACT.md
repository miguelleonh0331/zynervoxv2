# Contrato: bot_ivr

## Despliegue v2 aislado (2026-10-09)

Campañas/listas/flujos nuevos se crean vacíos en zynervox_core, sin copiar datos
productivos. zynervoxv2_bot_ivr recibe DML por tabla propia, nunca acceso a zynervox
ni tablas de secretos/configuración/acceso de core. Configuración central aislada
rechaza apuntar a usuario/base productivos. Launch/prebuild/TTS jobs legacy están
bloqueados 409; laboratorio legacy bloqueado. No se activa motor compartido ni se
promete marcación operativa hasta tener workers/runtime separados.

Gestiona campañas IVR, ejecución SQL, preconstrucción TTS y auditoría por nodo. La
web publica trabajos; los workers Python consumen tablas y archivos publicados. Los
tokens viven en `/etc/asterisk/synervox/secrets`, nunca en Git.

## Validación de variables por lista (2026-10-10)

list_edit.php consume audioLeads del contrato zynervox_queries y la lectura
pública ivr_builder_published_flow del flujo publicado indicado por id_flujo.
Bot IVR recorre nodos alcanzables, incluyendo ramas/reintentos/segmentos, y
resuelve cada texto con customer_name y las variables originales de extra_json.
nombre admite fallback a customer_name; numero, lead_id, list_id y campaign_id
son valores del sistema y no admiten sobrescritura por columnas importadas.
Variables ausentes/vacías, JSON inválido, textos mayores de 1000 caracteres y
marcadores sin resolver impiden preparar el lead; cero es un valor válido.
La pantalla muestra conteos y hasta cinco errores con lead/nodo/variable, sin
mostrar valores personales. No modifica status de marcación ni BD.
Variables obtenidas durante una llamada aún no se pregeneran: se reportan como
ausentes. Esta etapa valida y resuelve textos, pero no inicia jobs ni envía TTS;
Generar permanece deshabilitado hasta implementar ejecución propia por lista.


## Administración de campañas y listas (2026-10-05)

La administración nueva usa exclusivamente `zynervox_bot_campaigns`,
`zynervox_bot_lists` y `zynervox_bot_list` en la base configurada en Servicios.
`campaign_id` y `list_id` son claves autoincrementales; listas referencian
campañas y leads referencian listas mediante FK con eliminación restringida.
La creación recibe nombre y activo, sin flujo ni leads obligatorios.
El detalle permite editar nombre/activo, guardar ventana diaria
`scheduled/start_time/end_time` y crear/editar listas de esa campaña.
La conexión se administra en Servicios > Base de datos mediante core.
Sin conexión central guardada se conserva configuración legacy/bootstrap;
un fallo de core no activa fallback silencioso. IDs y motor legacy no cambian.

Las tablas y endpoints de ejecución anteriores permanecen intactos.
Las campañas del esquema nuevo todavía NO publican trabajos al motor legacy.
En esta etapa activo y horario son configuración persistida, no un disparador
de llamadas. La carga de leads se realiza en el detalle de lista; la migración
del motor se implementará por separado. No intercambiar IDs nuevos con IDs legacy.


## Metadatos ampliados de campaña (2026-10-05)

Migración aditiva 003 añade descripción, user_group, dial_method, lead_order,
dial_statuses, hopper_level, auto_dial_level, dial_timeout, dial_prefix,
campaign_cid, campaign_recording y max_channels a zynervox_bot_campaigns.
Son configuración administrativa persistida: no activan marcación, grabación
ni restricciones de acceso de user_group hasta integrar el motor/autorización.
max_channels es una extensión propia de Bot IVR. No hay dependencia ni FK a
asterisk.vicidial_campaigns; se consultó solo como referencia de campos/opciones.
Se conservan ID, nombre, flags y horarios. Formularios anteriores que omiten
metadatos nuevos deben conservar sus valores existentes.


## Apertura y carga de listas (2026-10-05)

El listado de listas muestra created_at y Abrir lista. list_edit.php recibe
list_id en id y campaign_id, comprueba pertenencia y conserva auth/CSRF.
La página carga TXT UTF-8 interpretado como CSV separado por comas: 2-10
cabeceras normalizadas a identificadores, sin nombres vacíos/repetidos y
numero obligatorio. Teléfonos conservan 1-20 dígitos, sin recortar prefijos.
Los campos adicionales se guardan sin normalización TTS en extra_json;
nombre/name/cliente/nombres alimentan customer_name (máximo 160 caracteres).

Se valida el archivo completo antes de insertar: errores de formato/cabecera
abandonan la carga y registros inválidos se contabilizan con motivos. Se
conserva la primera aparición válida de un teléfono por archivo. Otras listas
pueden repetirlos.
Importación transaccional serializada por bloqueo del padre FOR UPDATE.
La carga reemplaza todos los leads de esa lista tras validar el archivo. Archivo
sin contactos válidos aborta antes de borrar. DELETE por list_id e INSERT son
atómicos; ante fallo se conserva la base anterior. En transacción externa se usa
savepoint. Se muestran cargados/duplicados/rechazados.
Límites: 10 MB (o límite PHP menor), 50000 registros, 1000 caracteres por variable.
La página de carga muestra resultado y total, sin detalle de leads. No publica trabajos al motor legacy.


## Capa PHP compartida de consultas (2026-10-05)

Administración nueva consume contrato público BotIvrRepository de zynervox_queries
mediante factory; SQL reside en mysql/bot_ivr (también MariaDB). Páginas y servicios
consumen operaciones; validaciones de negocio y parser TXT quedan en Bot IVR.
Configuración engine=mysql|mariadb, ausente equivale mysql para instalaciones previas.
Bot IVR conserva base zynervox, secretos fuera de Git y botón obligatorio. Legacy
mantiene carsa_db(): PDO usando conector común compatible. Workers no cambian.
Otros motores no se habilitan hasta adapter/esquema/pruebas; no migración de datos.


## Flujo por lista (2026-10-05)

Migración 004-list-flow.sql añade zynervox_bot_lists.id_flujo BIGINT UNSIGNED NULL.
Listas existentes conservan NULL. Formularios nuevos de creación/edición piden
entero positivo 1..PHP_INT_MAX y muestran el ID en campaña y detalle de lista.
Formularios antiguos que omiten el campo conservan el ID actual; creación antigua
sin campo permite NULL. Campo enviado vacío/inválido no modifica lista.
ID referencia conceptual al flujo futuro IVR Builder, sin FK ni lectura de sus
internos: guardar ID no certifica existencia/publicación ni genera audios.


## Laboratorio de audio independiente (2026-10-05)

GET/POST audio_lab.php ofrece texto -> proveedor TTS -> WAV reproducible en la
misma página. Sin campañas/listas/leads/flujos/BD/jobs ni llamadas. Reutiliza
sesión admin, CSRF, navegación y botón de conexión; no abre conexión BD para audio.
Texto UTF8 1..1000, proveedores allowlist gtts/rga, español y voz1.3 fijos.
Registro de proveedores en audio_lab_service.php y ejecución en script propio
Python audio_lab_generate.py; futuros proveedores de otra red requieren adaptador
server-side, no URL/credenciales desde formulario. No dependencia CARSA.
Ejecución proc_open argv + JSON stdin, timeout90s local/180s RGA; error sanitizado y validación WAV
RIFF/WAVE, 100bytes..10MB. Runtime fuera webroot, nombre random32hex, asociado a sesión.
action=audio&id transmite únicamente archivo de sesión autenticada; desconocido404,
Content-Type audio/wav y nosniff/no-store. Historial cinco; expulsados se eliminan.
No hay purga periódica de sesiones abandonadas en este POC.


## Proveedor RGA del laboratorio (2026-10-06)

RGA (Remote Generation Audio), clave rga, usa POST /v1/audio/speech del gateway
configurado en secrets/audio_lab_rga.json, relativo al despliegue Asterisk.
Config endpoint/token privada fuera webroot/Git; formulario no acepta URLs/token.
JSON input/language=es/speed=1.3/format=wav; Bearer server-side. El WAV se devuelve
sin volver a acelerar localmente. No fallback a gTTS local.
requests stream con timeout conexión5/lectura145 y deadline150; proceso PHP180s
corta y limpia parciales. allow_redirects=False evita reenviar Bearer; límite10MB
durante lectura y validación WAV PCM16 mono8000Hz con frames no vacíos.
Errores400/401/502/503/red/formato se traducen a mensajes fijos sin respuesta
remota, token, proxy o trace. Ownership/historial/reproductor permanecen iguales.
Solo clienteRGA: no modifica gateway, Farm, BD, campañas o workers.


## Campos de preparaci?n del discador (2026-10-10)

Aplicar models/005-lead-dialer-fields.sql a la base configurada de Bot IVR
(mirmidon: zynervox_core); la migraci?n no fija USE ni modifica VICIdial.
A?ade status VARCHAR(6) NOT NULL DEFAULT 'NEW', called_count INT UNSIGNED
NOT NULL DEFAULT 0, last_call_at DATETIME NULL y next_call_at DATETIME NULL.
NEW identifica un contacto sin intento; called_count cuenta intentos de marcaci?n.
Las fechas deben escribirse en UTC. El futuro motor define las transiciones de
estado y la pol?tica de reintentos; ning?n campo dispara llamadas por s? mismo.
?ndice idx_bot_list_dial_queue: list_id, status, next_call_at, lead_id.

Migraci?n aditiva e idempotente para MariaDB 10.6, sin borrar leads. Los contactos
existentes y nuevas importaciones reciben NEW/0/NULL/NULL. Reemplazar base conserva
su sem?ntica: elimina leads de esa lista y crea contactos nuevos con valores
iniciales; no conserva su historial ni sus estados anteriores. No reemplazar una
lista mientras el futuro motor la procesa. Historial por intento y reserva
concurrente de leads quedan pendientes de implementar con el motor.

DDL realiza commit impl?cito; no se revierte mediante ROLLBACK. Ejecutar por
administraci?n, nunca desde list_edit.php. Verificar con la prueba existente:
php src/features/bot_ivr/tests/list-import-db.php /ruta/web/bot_ivr
