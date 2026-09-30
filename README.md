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

La copia de preparación en Kamatera vive en `/var/www/html/zynervoxv2` y no está
conectada a Apache ni Asterisk. Producción continúa en `/var/www/html/zynervox`.
