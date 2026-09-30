# Arquitectura

- `app/web`: aplicación Apache/PHP y frontend.
- `asterisk/synervox`: dialplan generado, workers Python, flujos y migraciones.
- `installer`: instalación, migración y diagnóstico.
- `database`: MariaDB 10.11 aislada, esquema sanitizado y volumen persistente.
- `whatsapp`: Zynerwaba y MySQL 8.4 aislados, esquema propio y persistencia separada.
- `/etc/zynervox/astguiclient.conf` o `/etc/astguiclient.conf`: conexión MariaDB.
- `/var/lib/asterisk/sounds`: audios, cachés y grabaciones; nunca se versiona.

La web llama workers Python bajo `/etc/asterisk/synervox`, consulta MariaDB y usa
un wrapper sudo restringido para recargar PJSIP/dialplan. Asterisk, MariaDB y Apache
permanecen servicios nativos del host, salvo la opción de ejecutar solo MariaDB
en Docker para mantener compatibilidad sin reemplazar MySQL existente.

WhatsApp se integra como servicio externo por contrato: Apache publica
`/zynerwabav2/` hacia un puerto local y Zynervox aporta navegación y autorización
de entrada. Ningún módulo PHP consulta las tablas internas de Zynerwaba. La primera
versión conserva sesiones independientes; SSO y bandeja nativa requieren un contrato
de identidad/API posterior.
