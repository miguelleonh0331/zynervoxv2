# Arquitectura

- `app/web`: aplicación Apache/PHP y frontend.
- `asterisk/synervox`: dialplan generado, workers Python, flujos y migraciones.
- `installer`: instalación, migración y diagnóstico.
- `database`: MariaDB 10.11 aislada, esquema sanitizado y volumen persistente.
- `/etc/zynervox/astguiclient.conf` o `/etc/astguiclient.conf`: conexión MariaDB.
- `/var/lib/asterisk/sounds`: audios, cachés y grabaciones; nunca se versiona.

La web llama workers Python bajo `/etc/asterisk/synervox`, consulta MariaDB y usa
un wrapper sudo restringido para recargar PJSIP/dialplan. Asterisk, MariaDB y Apache
permanecen servicios nativos del host, salvo la opción de ejecutar solo MariaDB
en Docker para mantener compatibilidad sin reemplazar MySQL existente.
