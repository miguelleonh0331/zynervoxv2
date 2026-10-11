# Contrato: ivr_builder

## Despliegue v2 aislado (2026-10-09)

Usa conexión propia core y tabla v2_ivr_deploy_config. Publicación local, audios y
token receptor pertenecen a /var/lib/zynervoxv2/asterisk y configuración v2.
Publicar desde v2 aislado no envía flujos al API Asterisk productivo; almacena
JSON propio. No altera dialplan ni activa llamadas. Sesión separada de producción.

## Configuración de BD (2026-10-09)

La conexión se administra en Servicios > Base de datos y se comparte con Bot IVR.
El engranaje mantiene configuración de Asterisk. Escrituras de configuración
requieren sesión administrativa y token CSRF; lectura enmascara secretos.


Edita flujos relacionales y publica JSON atómico para Asterisk. Un flujo publicado
debe conservar hash, nodos y transiciones válidas. No inicia campañas ni llamadas.

## Lectura pública para Bot IVR (2026-10-10)

`app/web/ivr_builder/published_flow.php` expone
`ivr_builder_published_flow(int $flowId): array`: lee únicamente el JSON publicado
del código 01..99 bajo el runtime configurado. Valida identidad, JSON, nodos y
entrada; inexistencia, publicación inválida o archivo mayor de 10 MB generan
RuntimeException. No publica, modifica BD ni activa llamadas. El consumidor
valida las transiciones y variables antes de preparar textos personalizados.

## 2026-10-10 - Embedded bridge phrase (architect approved)

In isolated v2, a bridge can use its own nonempty bridge_text with bridge_id; an external legacy bridge catalog is not required. The consumer prebuilds this text with its own provider/cache. Existing catalog behavior and all target/CSRF validations remain. Legacy deployments still require their catalog.

## 2026-10-11 — Nodo Reenviar

Conserva el tipo reenviar. forwarding contiene mode (anexo/trunk/dialplan),
endpoint PJSIP fijo, number con {variable} para troncal y headers [{name,value}].
Los nodos anteriores sin forwarding conservan anexo/contexto/prioridad mediante
modo dialplan, sin headers. Nuevos destinos anexo/trunk requieren v2 aislado.
Headers X-* o User-to-User, únicos, hasta 16; plantillas usan las variables de la
lista o capturadas antes en el flujo. Se validan después de resolverlas; ausentes,
controles, metacaracteres y destinos no disponibles se rechazan. No se envían
credenciales SIP ni se registran valores de headers en auditoría.

API pública forwarding.php: ivr_forwarding_config(array $node): array valida;
ivr_forwarding_render(array $node,array $variables): array resuelve. Bot IVR
consume esta API. Metadata persistida en data_json existente, sin migración;
flow_load la recupera y el JSON publicado contiene forwarding.

Predial fijo zynervoxv2-forward-headers ejecuta forward_headers_agi.php en el
canal PJSIP llamado para aplicar headers. Su fuente está en
asterisk/synervox/modules/asterisk/extensions-forwarding.conf; debe copiarse a
runtime/modules/asterisk e incluirse desde /etc/asterisk/extensions.conf.
El helper requiere permiso de ejecución y etiqueta SELinux zynervox_agi_exec_t.
No se publican modificaciones de flujos existentes automáticamente.

## 2026-10-11 — Protocolo SIP adicional en Reenviar

forwarding.protocol acepta SIP/PJSIP; ausente conserva PJSIP. La metadata
ajena a forwarding se preserva en data_json. SIP usa peer configurado, validado
con SIPPEER, y Dial SIP/peer/numero (troncal) o SIP/peer (anexo). SIPAddHeader
recibe headers resueltos; el runner verifica cada slot nuevo y limpia solo esos
slots al salir, sin borrar headers anteriores. Se rechazan escapes con barra
invertida en valores SIP para evitar controles decodificados por chan_sip.
PJSIP mantiene su predial. Cambiar protocolo en el editor conserva headers.
Variables múltiples pueden combinarse en un valor, por ejemplo {nombre}|{numero}.
