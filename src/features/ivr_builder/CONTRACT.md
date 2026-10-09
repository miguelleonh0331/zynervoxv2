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
