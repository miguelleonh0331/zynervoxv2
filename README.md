# Zynervox v2

Paquete portable para instalar la interfaz de call center Zynervox sobre un
servidor Ubuntu que ya dispone de Asterisk/VICIdial y `/etc/astguiclient.conf`.

## Instalación

```bash
sudo ./installer/install.sh --dry-run
sudo ./installer/install.sh --apply-migrations
```

Variables opcionales: `WEB_ROOT`, `ASTERISK_ROOT` y `URL_PATH`. El instalador no
incluye contraseñas, bases, audios, grabaciones ni datos de producción.

La copia de preparación en Kamatera vive en `/var/www/html/zynervoxv2` y no está
conectada a Apache ni Asterisk. Producción continúa en `/var/www/html/zynervox`.
