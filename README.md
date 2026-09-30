# Zynervox v2

Paquete portable para instalar la interfaz de call center Zynervox sobre un
servidor Ubuntu que ya dispone de Asterisk/VICIdial, su esquema base completo y
`/etc/astguiclient.conf`. Asterisk y MySQL sin las tablas VICIdial no son suficientes.
Si esas integraciones faltan, el instalador conserva la web y reporta estado parcial.

## Instalación

```bash
sudo ./installer/install.sh --dry-run
sudo ./installer/install.sh --apply-migrations
```

Para una vista LAN sin alias, haga coincidir destino y URL:

```bash
sudo WEB_ROOT=/var/www/html/zynervoxv2-test URL_PATH=/zynervoxv2-test ./installer/install.sh
```

Use `--skip-packages` para copiar y publicar la web sin instalar paquetes ni provocar
reinicios indirectos de servicios existentes.

Variables opcionales: `WEB_ROOT`, `ASTERISK_ROOT` y `URL_PATH`. El instalador no
incluye contraseñas, bases, audios, grabaciones ni datos de producción.

## MariaDB aislada

La base compatible puede ejecutarse sin reemplazar MySQL del host:

```bash
./installer/database.sh init
./installer/database.sh credentials
./installer/database.sh install-config
sudo WEB_ROOT=/var/www/html/zynervox URL_PATH=/zynervox ./installer/install.sh --skip-packages
```

El contenedor escucha solo en `127.0.0.1`, usando el primer puerto libre desde
`3307`. Las contraseñas se generan en
`database/.env`, excluido de Git. El esquema contiene 364 tablas y crea un usuario
administrador nuevo; no incluye datos ni credenciales de Kamatera.

La copia de preparación en Kamatera vive en `/var/www/html/zynervoxv2` y no está
conectada a Apache ni Asterisk. Producción continúa en `/var/www/html/zynervox`.
