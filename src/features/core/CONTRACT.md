# Contrato: core

Autenticación, sesión, conexión MariaDB, auditoría y configuración común. Lee la
conexión desde `/etc/astguiclient.conf`; no almacena credenciales en el repositorio.
Los demás módulos consumen clases públicas de `app/web/includes`.
