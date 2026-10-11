# OpenVPN y panel Vpn en Soundhound

Panel web: /zynervoxv2/modules/vpn/index.php, administrador nivel9.
Inventario CRUD, perfiles .ovpn, revocación/desconexión, auditoría y última conexión.
PHP reutiliza autenticación de Zynervox. API y hooks viven en el contenedor;
no se expone Docker socket ni token al navegador.

Fuente ops/vpn sobre SMB /mnt/zynervoxv2-fuente. Producción /opt/zynervoxv2-vpn.
Dockerfile deriva de hwdsl2/openvpn-server fijado por digest. El mismo volumen
zynervoxv2-vpn-data preserva PKI, perfiles y SQLite administrativo (admin/).
Tokens privados separados: /etc/zynervox/zynervoxv2/vpn/api-token (PHP,
root:apache0640) y /opt/zynervoxv2-vpn/api-token (Docker, root0600, bind :ro,Z).
Nunca guardar tokens, claves, PKI, perfiles o cookies en Git/SMB.

Red: VPN UDP192.168.3.22:1194, API TCP127.0.0.1:18081. Management solo dentro
contenedor127.0.0.1:7505. PoolVPN10.8.0.0/24 y bridge172.30.119.0/24.
Endpoint actual LAN; accesoInternet requiere IP pública/DNS y NAT1194 verificados.
Upstream usa túnel completo. No modifica configuración del clienteWindows.

```sh
cd /opt/zynervoxv2-vpn
docker compose ps
docker compose logs --tail=30
```

Administrar por el panel para registrar actor/IP/resultado; operaciones CLI
root quedan fuera de auditoría administrativa, aunque se reconcilia estado PKI.
No reutilizar identificadores. Eliminar revoca y oculta; conserva historial.
Status actualizado cada5s. Historial empieza al activar hooks, sin fechas falsas.
Hooks fallan cerrado al no poder auditar una nueva conexión.
Audit append-only en aplicación/triggers, no inmutable frente a root.

Verificación local: python src/features/vpn/tests/backend_test.py.
Prueba privada de despliegue: src/features/vpn/tests/deployment_test.py, requiere
root y contraseña admin por entorno privado; crea/revoca cliente temporal.

Despliegue: verificar fuente, respaldar configuración/web/PKI, copiar explícitamente
Dockerfile/admin/compose desde SMB a /opt, construir imagen y compose up -d;
copiar app/web/modules/vpn, sidebar y gate a producción, restorecon/reloadPHP.
Construcción de nueva imagen reinicia el contenedorVPN brevemente; no recrear PKI.
Rollback: restaurar compose anterior respaldado y compose up -d; conserva volumen.
Detener sin borrar datos: docker compose stop. Nunca down --volumes.

El inventario inicial no carga historial. Ver auditoría abre consultas de20
registros por página, con filtros y navegación estable por id. Actualizar
historial inicia una nueva consulta para mostrar eventos posteriores.
