# vpn

Panel administrativo de OpenVPN integrado en Zynervox, visible como Vpn.
Web: app/web/modules/vpn. Backend y hooks en imagen derivada: ops/vpn/admin.
API privada loopback sin acceso PHP al socket Docker.

Inventario: identificador permanente de certificado, nombre, correo, referencia,
notas. Crear, editar metadatos, descargar perfil, revocar y borrar l?gicamente.
SQLite privado guarda inventario, ?ltima conexi?n/desconexi?n y auditor?a.
Importa certificados existentes excluyendo el servidor. No inventa historial.

Prueba local: python src/features/vpn/tests/backend_test.py.
Leer CONTRACT y agents/_DEFAULT_MODULE_AGENT.md. Consume Core por Auth/Config
p?blicos. Sin cambios en BotIVR/dialer ni ejecuci?n del instalador.
