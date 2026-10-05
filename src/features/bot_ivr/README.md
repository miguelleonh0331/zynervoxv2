# bot_ivr

Código web en `app/web/bot_ivr` y workers en
`asterisk/synervox/modules/bot_ivr`. Opera campañas, TTS, colas y eventos IVR.


## Conexión configurable

En `index.php`, el administrador puede configurar servidor, puerto, base de datos,
usuario y contraseña mediante «Configurar conexión a base de datos».
Se prueba la conexión antes de guardar. La contraseña vacía conserva la actual.
La web y los workers leen `/etc/asterisk/synervox/secrets/bot_ivr_db.json`;
sin ese archivo conservan la configuración general existente.
El directorio debe existir y permitir escritura al usuario PHP. El archivo se
crea con permisos 0640: los workers deben poder leerlo mediante el grupo del
usuario PHP. Las credenciales nunca se guardan en el repositorio.
Los procesos ya iniciados conservan su conexión hasta terminar: cambiar la
configuración con las campañas detenidas.
