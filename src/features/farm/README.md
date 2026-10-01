# Módulo: Farm

Administra el pool de anexos SIP y la flota de workers/proxies desde la sesión
administrativa de Zynervox.

- `vendor/`: snapshot saneado de `anexos-proxys`.
- `vendor/auth.php`: puente con la sesión Zynervox y CSRF propio.
- `installer/farm.sh`: instala la web y dos servicios systemd aislados.
- `app/web/modules/admin/farm.php`: shell visual dentro del menú principal.

Puertos, rutas, usuario systemd y tokens se generan por instancia. SIP y TTS
externos quedan vacíos hasta configurarlos expresamente. El módulo no administra
usuarios ni comparte datos o contenedores con WhatsApp.

Origen: `https://github.com/miguelleonh0331/anexos-proxys`, commit
`7026874d7431ad2bf8ee063fd015b2ad825adb40`.
