# Despliegue

## Requisitos

Servidor Ubuntu con Asterisk/VICIdial funcional y `/etc/astguiclient.conf`. Hacer
backup de MariaDB y de `/etc/asterisk` antes de activar una versión nueva.

## Instalación nueva

```bash
git clone REPOSITORY_URL zynervox
cd zynervox
sudo ./installer/install.sh --dry-run
sudo ./installer/install.sh --apply-migrations
```

El modo `--dry-run` no escribe. Sin `--apply-migrations` se despliegan archivos pero
no se cambia la base. El instalador no elimina archivos desconocidos del destino.

## Verificación

```bash
sudo WEB_ROOT=/var/www/html/zynervox ./installer/check.sh
curl -I http://127.0.0.1/zynervox/
sudo asterisk -rx 'pjsip show endpoints'
```

Validar además login, permisos por empresa, publicación IVR, prueba SIP/RTP y que
los servicios existentes sigan activos. No realizar llamadas reales como smoke test.

## Datos fuera del repositorio

Respaldar/restaurar por separado MariaDB, `/var/lib/asterisk/sounds`, grabaciones,
certificados, carriers, secretos y configuraciones particulares del servidor.
