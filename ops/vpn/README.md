# OpenVPN en Soundhound

Servicio base CLI para el futuro módulo Vpn. La GUI aún no está implementada.
Imagen upstream: https://github.com/hwdsl2/docker-openvpn
La imagen de producción se fija por digest en docker-compose.yml.

Producción: /opt/zynervoxv2-vpn/docker-compose.yml.
Fuente: /mnt/zynervoxv2-fuente/ops/vpn (SMB de la carpeta Windows).
Datos privados persistentes: volumen Docker zynervoxv2-vpn-data.
Puerto: 192.168.3.22:1194/udp. Endpoint inicial LAN; no es acceso Internet.
Pool de clientes: 10.8.0.0/24; red Docker: 172.30.119.0/24.
Esta configuración upstream usa túnel completo para los clientes.
Para uso remoto por Internet definir IP pública/DNS y NAT UDP1194;
actualizar el endpoint de los perfiles existentes y futuros sin regenerar PKI.

Operación como root:

```sh
cd /opt/zynervoxv2-vpn
docker compose ps
docker exec zynervoxv2-openvpn ovpn_manage --listclients
docker exec zynervoxv2-openvpn ovpn_manage --addclient agente01
docker exec zynervoxv2-openvpn ovpn_manage --revokeclient agente01 -y
```

Exportar perfiles solamente a un destino privado, con umask 077:

```sh
umask 077
docker exec zynervoxv2-openvpn ovpn_manage --exportclient agente01 > /root/agente01.ovpn
```

No guardar perfiles, claves ni PKI en Git o en la carpeta SMB. No dar al usuario
Apache acceso al socket Docker. La futura GUI requiere una interfaz administrativa
restringida y autenticada; no editar manualmente el contenedor en ejecución.
Para detener sin borrar datos: docker compose stop. Nunca down --volumes.