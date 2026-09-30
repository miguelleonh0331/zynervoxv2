# Contrato: core

Autenticación, sesión, conexión MariaDB, auditoría y configuración común. Lee la
conexión desde `ZYNERVOX_CONFIG_FILE`, `/etc/zynervox/astguiclient.conf` o, como
compatibilidad, `/etc/astguiclient.conf`. No almacena credenciales en el repositorio.
Los demás módulos consumen clases públicas de `app/web/includes`.
