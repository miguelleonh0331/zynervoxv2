# ivr_builder

Código: `app/web/ivr_builder` y `asterisk/synervox/modules/flows`. Editor visual,
persistencia relacional y publicación atómica de flujos.

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
