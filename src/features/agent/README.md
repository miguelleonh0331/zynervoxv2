# agent

Código: `app/web/modules/agente` y `app/web/agc/zynervox.php`. Consola
VICIdial/webphone, sesión, marcado manual, estados, formularios y acciones de llamada.

`app/web/agc/zynervox.php` inicia la migración progresiva del AGC existente en
Kamatera. Sus dependencias vecinas se incorporarán de forma controlada; hasta
entonces la ruta de laboratorio puede informar archivos AGC faltantes.

Estado inicial de laboratorio (`ad1306e`): desplegado y accesible por Apache,
pero responde HTTP 500 porque todavía faltan `dbconnect_mysqli.php`,
`functions.php` y `options.php`. No copiar esos archivos sin revisar primero sus
dependencias y secretos.
