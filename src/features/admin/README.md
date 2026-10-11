# admin

Código: `app/web/modules/admin`. Administración multiempresa de usuarios, campañas,
listas, carriers, teléfonos, pausas, disposiciones y configuración.

### 2026-10-10 - Services PBX AMI settings
- Added modules/admin/services/pbx.php and PBX sidebar item under Services, following database page styling. Level-9 authorization and CSRF protect test/save. Fields: host, port (5038 default), username, password; blank password preserves stored secret.
- PbxAmi helper validates AMI fields, authenticates with Events off, logs off, and saves only after a successful test. Atomic private config: deployment.directory/pbx/ami.json, 0640. Installer prepares isolated private directory 2770. No production manager.conf changes or calls.
- Deployed source files directly and prepared /etc/zynervox/zynervoxv2/pbx. PHP lint, source/production comparison, fake AMI success/rejection/Logoff, field injection rejection, password preservation, private persistence/permissions, shell syntax, unauthenticated HTTP redirect and wwwrun write access passed. Real credentials remain unset for administrator configuration.

## Crear agentes del marcador v2

En Usuarios, administrador crea un agente con login numérico (por ejemplo 10001),
nombre, contraseña web y cuenta activa. Crear su extensión/login SIP igual en
Anexos/Teléfonos, registrar MicroSIP y asignarlo en Campañas → Agentes asignados.
El login web del agente abre Panel de agente; entrar a cola es una acción aparte.
Editar conserva contraseña si se deja vacía. Salir de colas antes de desactivar
cuenta: el bloqueo web no elimina la configuración de telefonía.
Validación: bash src/features/core/tests/run-users.sh, fixtures TEMPORARY y
servidor HTTP de staging. No origina llamadas ni modifica usuarios persistentes.
