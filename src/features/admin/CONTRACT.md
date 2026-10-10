# Contrato: admin

Carriers aislado requiere nivel 9 y CSRF en todos los POST. Sus datos van a
zynervox_core.v2_carriers, con historial propio sin snapshots de secretos.
Guardar genera archivos inactivos; no implica activacion de troncales en Asterisk.

Gestiona empresas, usuarios, campañas, listas, teléfonos, grupos y carriers mediante
`app/web/modules/admin`. Debe respetar rol y empresa activa, auditar cambios y nunca
exponer secretos de telefonía en respuestas o logs.


## 2026-10-10 - Extraccion de origen

Carriers ofrece prefijos detectados en el dialplan guardado, nombre y boton Extraer prefijo. POST requiere nivel 9 y CSRF; prefijo debe estar en la troncal indicada. Editor aislado mantiene cabecera fija fuera del textarea. Extraer no modifica ni recarga el dialplan.


## 2026-10-10 - Dialplan page

modules/admin/dialplan.php offers independent list/new/edit/delete after Troncales SIP in sidebar. Level 9 and dedicated CSRF required for POST; SQL via Core public Dialplans API. Name, active state and code are editable; fixed zynervoxv2 header excluded from code. Prefix detected automatically, validated server-side and stored on save. Isolated carriers page manages troncales only; existing carrier dialplans migrated before use. No automatic PBX reload or outgoing calls.


## 2026-10-10 - Readonly call template

Dialplan page shows system contexts outside the editable field. User authors only route code; no route created automatically. Common context definitions remain fixed and are generated once.


## 2026-10-10 - Template visibility correction

User clarification supersedes readonly template display: shared system contexts are generated server-side and never displayed in the web form. Route editing unchanged. Architecture review: display-only contract correction approved.

## 2026-10-10 - PBX AMI service
Services/pbx.php requires administrator level 9 and CSRF for test/save. PbxAmi::load/validate/test/save exposes configuration to authorized server callers only. Test authenticates and logs off, never originates a call. Save requires successful login and atomically writes deployment.directory/pbx/ami.json (0640, private directory 2770). Form never renders the secret; blank password preserves it. Credentials are not imported from legacy nor stored in Git.


## 2026-10-10 - Isolated campaign dashboard

Isolated admin login destination remains modules/admin/index.php and now renders the IVR campaign graphic monitor in place, without redirecting to Bot IVR. campaign_monitor.php renders the same read-only view; authenticated levels 7/8/9 can GET campaign_monitor_api.php. Nonisolated dashboard and VICIdial monitor remain unchanged.
