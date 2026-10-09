# Contrato: admin

Carriers aislado requiere nivel 9 y CSRF en todos los POST. Sus datos van a
zynervox_core.v2_carriers, con historial propio sin snapshots de secretos.
Guardar genera archivos inactivos; no implica activacion de troncales en Asterisk.

Gestiona empresas, usuarios, campañas, listas, teléfonos, grupos y carriers mediante
`app/web/modules/admin`. Debe respetar rol y empresa activa, auditar cambios y nunca
exponer secretos de telefonía en respuestas o logs.
