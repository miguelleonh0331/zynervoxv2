# admin

Código: `app/web/modules/admin`. Administración multiempresa de usuarios, campañas,
listas, carriers, teléfonos, pausas, disposiciones y configuración.

### 2026-10-10 - Services PBX AMI settings
- Added modules/admin/services/pbx.php and PBX sidebar item under Services, following database page styling. Level-9 authorization and CSRF protect test/save. Fields: host, port (5038 default), username, password; blank password preserves stored secret.
- PbxAmi helper validates AMI fields, authenticates with Events off, logs off, and saves only after a successful test. Atomic private config: deployment.directory/pbx/ami.json, 0640. Installer prepares isolated private directory 2770. No production manager.conf changes or calls.
- Deployed source files directly and prepared /etc/zynervox/zynervoxv2/pbx. PHP lint, source/production comparison, fake AMI success/rejection/Logoff, field injection rejection, password preservation, private persistence/permissions, shell syntax, unauthenticated HTTP redirect and wwwrun write access passed. Real credentials remain unset for administrator configuration.
