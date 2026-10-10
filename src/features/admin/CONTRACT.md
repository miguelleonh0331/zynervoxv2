# Contrato: admin

Carriers aislado requiere nivel 9 y CSRF en todos los POST. Sus datos van a
zynervox_core.v2_carriers, con historial propio sin snapshots de secretos.
Guardar genera archivos inactivos; no implica activacion de troncales en Asterisk.

Gestiona empresas, usuarios, campañas, listas, teléfonos, grupos y carriers mediante
`app/web/modules/admin`. Debe respetar rol y empresa activa, auditar cambios y nunca
exponer secretos de telefonía en respuestas o logs.


## 2026-10-10 - Extraccion de origen

Carriers ofrece prefijos detectados en el dialplan guardado, nombre y boton Extraer prefijo. POST requiere nivel 9 y CSRF; prefijo debe estar en la troncal indicada. Editor aislado mantiene cabecera fija fuera del textarea. Extraer no modifica ni recarga el dialplan.
