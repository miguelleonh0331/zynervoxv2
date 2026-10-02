# Arquitectura

- `app/web`: aplicación Apache/PHP y frontend.
- `asterisk/synervox`: dialplan generado, workers Python, flujos y migraciones.
- `installer`: instalación, migración y diagnóstico.
- `database`: MariaDB 10.11 aislada, esquema sanitizado y volumen persistente.
- `whatsapp`: Zynerwaba y MySQL 8.4 aislados, esquema propio y persistencia separada.
- `farm`: panel PHP y dos servicios systemd loopback para anexos y proxies.
- `stt_providers`: panel PHP y esquema MariaDB propio para cuentas/API keys STT.
- `zynerdesk`: Synervox Remoteo (supervisión remota) y MySQL 8.4 aislados,
  imagen fijada por digest, esquema y persistencia propios.
- `/etc/zynervox/astguiclient.conf` o `/etc/astguiclient.conf`: conexión MariaDB.
- `/var/lib/asterisk/sounds`: audios, cachés y grabaciones; nunca se versiona.

La web llama workers Python bajo `/etc/asterisk/synervox`, consulta MariaDB y usa
un wrapper sudo restringido para recargar PJSIP/dialplan. Asterisk, MariaDB y Apache
permanecen servicios nativos del host, salvo la opción de ejecutar solo MariaDB
en Docker para mantener compatibilidad sin reemplazar MySQL existente.

WhatsApp se integra como servicio externo por contrato: Apache publica
`/zynerwabav2/` hacia un puerto local y Zynervox presenta un panel administrativo
nativo que consume su API. Las operaciones de conversaciones y campañas tendrán
una ruta separada. Un intercambio HMAC de corta duración convierte la sesión Zynervox
en una sesión Zynerwaba sin compartir contraseñas. Ningún módulo PHP consulta las
tablas internas de Zynerwaba; el contenedor continúa siendo la fuente de verdad.

Farm y Stt Providers se renderizan como vistas nativas sin iframe y reutilizan la
sesión administrativa de Zynervox. Farm delega
privilegios a servicios locales aislados; Stt Providers usa un usuario MariaDB
limitado a su base. Ninguno vive dentro del Docker de WhatsApp.

Zynerdesk → MySQL propio → agentes Windows futuros: Zynerdesk se publica como
servicio Docker aislado (`zynerdesk/compose.yml`), proxyeado por Apache bajo
su propia subruta (HTTP y WebSocket). A diferencia de WhatsApp, el upstream
`2.0.2` no ofrece SSO ni `BASE_PATH`; el sidebar de Zynervox solo enlaza
(navegación completa, sin iframe) hacia la app, que resuelve login, assets y
WebSocket por sí sola porque calcula su ruta base desde `location.pathname`.
El agente Windows que se conecta a Zynerdesk (`synervox-remoteo-agent`) queda
fuera de alcance de esta etapa: solo se documenta la relación futura.
