# Contrato: bot_ivr

Gestiona campañas IVR, ejecución SQL, preconstrucción TTS y auditoría por nodo. La
web publica trabajos; los workers Python consumen tablas y archivos publicados. Los
tokens viven en `/etc/asterisk/synervox/secrets`, nunca en Git.


## Administración de campañas y listas (2026-10-05)

La administración nueva usa exclusivamente `zynervox_bot_campaigns`,
`zynervox_bot_lists` y `zynervox_bot_list` en la base `zynervox`.
`campaign_id` y `list_id` son claves autoincrementales; listas referencian
campañas y leads referencian listas mediante FK con eliminación restringida.
La creación recibe nombre y activo, sin flujo ni leads obligatorios.
El detalle permite editar nombre/activo, guardar ventana diaria
`scheduled/start_time/end_time` y crear/editar listas de esa campaña.
La conexión se guarda mediante el botón obligatorio; no existe fallback.

Las tablas y endpoints de ejecución anteriores permanecen intactos.
Las campañas del esquema nuevo todavía NO publican trabajos al motor legacy.
En esta etapa activo y horario son configuración persistida, no un disparador
de llamadas. La carga de leads y la migración del motor se implementarán
por separado. No intercambiar IDs nuevos con IDs legacy.


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
