# Stack soportado

- ViciBox/openSUSE Leap 15.5 o Ubuntu 24.04 LTS, arquitectura amd64.
- Apache 2.4 y PHP 8.3 con curl, mbstring, mysqli/PDO MySQL, XML y ZIP.
- MariaDB 10.11 y esquema VICIdial/Asterisk.
- Docker Compose opcional para aislar MariaDB en un puerto local desde `3307`.
- Zynerwaba base 2.0.0 por digest; imagen integrada objetivo
  `miguelleonh0331/zynerwabav2:2.1.0-zynervox`, Node.js/Express/Socket.IO y
  MySQL 8.4 en Docker.
- Asterisk 20 con PJSIP.
- Python 3 con PyMySQL y num2words.
- JavaScript nativo; Node.js solo para el componente legado `vicidial-js`.
- Farm: Python 3.10+, systemd, baresip, ffmpeg y PHP, sin Docker.
- Stt Providers: PHP 7.4+, PDO MySQL y cURL sobre MariaDB nativa aislada.
- Zynerdesk: imagen `ghcr.io/miguelleonh0331/synervox-remoteo` fijada por
  digest `sha256:0025bfc1902bb20bfc879b56862bacf08dafaf5717913592bc32ecb6e0627ba4`,
  Node.js 22, WebSocket (`ws`) y MySQL 8.4 propio en Docker. Apache proxyea
  la ruta pública (`mod_proxy_http` + `mod_proxy_wstunnel`).

El despliegue es híbrido: web, AGC, PHP y Asterisk viven en el host; Zynerwaba y
Zynerdesk y sus respectivas MySQL usan proyectos Compose aislados con
volúmenes propios, cada uno con su propio proxy Apache.
