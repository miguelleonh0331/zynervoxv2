# agent

Código: `app/web/modules/agente` y `app/web/agc/zynervox.php`. Consola
VICIdial/webphone, sesión, marcado manual, estados, formularios y acciones de llamada.

`app/web/agc/zynervox.php` inicia la migración progresiva del AGC existente en
Kamatera. Sus dependencias vecinas se incorporarán de forma controlada; hasta
entonces la ruta de laboratorio puede informar archivos AGC faltantes.

Estado inicial de laboratorio (`ad1306e`): desplegado y accesible por Apache,
pero respondió HTTP 500 porque faltaban `dbconnect_mysqli.php` y `functions.php`.
`options.php` es opcional y tampoco existe en el AGC original de Kamatera.
`dbconnect_mysqli.php` obtiene la conexión desde `/etc/astguiclient.conf`; ese
archivo y sus credenciales permanecen fuera de Git. En instalaciones Zynervox
también admite `ZYNERVOX_CONFIG_FILE` y `/etc/zynervox/astguiclient.conf`.
