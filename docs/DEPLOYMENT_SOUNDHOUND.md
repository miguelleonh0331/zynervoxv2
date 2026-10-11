# soundhound: Zynervox v2 aislado

Fecha: 2026-10-11. Servidor nuevo Rocky Linux 9, SSH por alias zynertools
soundhound, LAN 192.168.3.22 y Tailscale 100.113.29.10 (hostname SrvPredic).

## Rutas

- Fuente de trabajo: E:\servidores\zynerdesk\proyectos\zynervoxv2, PC 100.70.0.45.
- Fuente SMB: /mnt/zynervoxv2-fuente; montaje manual, no persistente al reiniciar.
- Copia local para despliegue: /opt/zynervoxv2-source-soundhound.
- Web: /var/www/html/zynervoxv2; URL http://100.113.29.10/zynervoxv2/.
- Configuracion: /etc/zynervox/zynervoxv2; runtime /var/lib/zynervoxv2/asterisk.
- /srv/www/htdocs/zynervoxv2 enlaza a la web para las rutas AGI existentes.

Se transfirio una copia comprimida de los directorios necesarios, excluyendo
secrets, runtime, venvs, .env y configuraciones locales. La copia evita las
consultas SMB por archivo que ralentizaron el despliegue. Editar en Windows;
actualizar la copia de despliegue antes de instalar cambios. La web desplegada
no depende del montaje SMB para servir peticiones ni de que el PC siga conectado.

## Servicios y paquetes

Apache 2.4.62, PHP 8.3.33, MariaDB 10.11.18, Python 3.9 y 3.11, FFmpeg 7.1,
SoX 14.4, gTTS en venv web. Streams oficiales Rocky php:8.3 y mariadb:10.11;
CRB/EPEL para multimedia y dependencias. PyMySQL desde RPM; num2words via pip
para el Python del sistema. No se reemplazo /usr/bin/python3.

Asterisk 20.21.0 se compilo desde descarga oficial con pjproject/jansson bundled.
SHA-256 del tar: 13fd6e8f1fbb19a3174af82a388dd72c87eb2c92d32fca83c4d51bfab03f686a.
Servicio systemd asterisk, usuario propio, PJSIP UDP 5060, AMI solo 127.0.0.1:5038.
No pertenece al grupo apache; ACL limitadas a rutas v2. MariaDB escucha solo
127.0.0.1:3306 mediante /etc/my.cnf.d/zynervox-local.cnf.

httpd, php-fpm, mariadb, asterisk, crond y tailscaled habilitados al arranque.
Firewall permite HTTP desde el PC Tailscale 100.70.0.45; no se abrieron puertos
de base/AMI ni acceso SIP/RTP publico. Configuracion y claves AMI son privadas.
PJSIP incluye archivos propios generados; no hay carriers, anexos ni destinos
configurados. No se realizaron llamadas reales ni se instalo VICIdial legacy.

## Instalador

```bash
cd /opt/zynervoxv2-source-soundhound
sudo env WEB_ROOT=/var/www/html/zynervoxv2 URL_PATH=/zynervoxv2 WEB_GROUP=apache \
  bash installer/unattended.sh
```

Salida final contiene secretos: ejecutar en terminal privada. La instalacion
real guardo la salida con modo 0600 en /root/zynervox-install/install.log.
Acceso inicial admin; consultar credenciales en terminal root, sin copiarlas al repo:

```bash
sudo bash /opt/zynervoxv2-source-soundhound/installer/credentials.sh
```

Rocky usa /etc/php-fpm.d/zynervoxv2.conf, socket /run/php-fpm/zynervoxv2.sock y
/etc/httpd/conf.d/zynervoxv2-isolated.conf. El prepend obligatorio usa el gate
existente sin modificarlo. SELinux conserva Enforcing; tipos propios
zynervox_runtime_t, zynervox_conf_t y zynervox_agi_exec_t evitan dar ejecucion
a toda la web. Solo los dos AGI ejecutables usan el ultimo tipo.

## Validacion

Login HTTP valido/invalido y cookie scoped ZYNERVOXV2; legacy y archivos privados
devuelven 403; gate ilegible devuelve 500 y recupera 200 al restaurarse. Prueba
de BD aislada verifica tablas vacias, grants limitados y fallback bloqueado.
AMI prueba login/logoff sin origination. TTS por HTTP autenticado produce WAV
y permite preview. PBX, Carriers, Phones, IVR y campañas responden autenticadas.
PJSIP UDP y contexto zynervoxv2 cargados; diagnostico aislado --strict: OK.
Validacion repetida de instalacion para conservar esquemas y configuracion.

Los cambios del instalador no agregan includes ni recargan PBX automaticamente;
la activacion inicial se preparo exclusivamente en este servidor nuevo.

## Ampliación SIP — 2026-10-11

chan_sip compilado desde la misma fuente Asterisk 20.21.0 y cargado sin reemplazar
el binario ni reiniciar el servicio. Se habilitó en menuselect.makeopts y se
retiró noload de modules.conf. Archivo fuente sip-soundhound.conf copiado
local-first a /etc/asterisk/sip.conf; chan_sip escucha UDP5061, PJSIP conserva
UDP5060. Anexos SIP deben indicar puerto5061 al registrar en Soundhound.
No se añadieron reglas públicas de firewall. chan_sip corresponde a Asterisk20;
no trasladar este módulo binario a otra versión/compilación de Asterisk.

Incluye runtime/modules/asterisk/sip-zynervoxv2.conf y sip-zynervoxv2-annexos.conf.
Carriers genera SIP/PJSIP por separado; se cargó carrier1 previamente activo,
sin cambiar active ni crear registros. Configuración y contraseñas de troncales
siguen fuera de Git. El selector Reenviar guarda protocolo, peer/endpoint,
destino y headers con variables del flujo. Predial PJSIP permanece separado.
Prueba interna Local verificó SIPAddHeader, lectura de slots heredados y limpieza
sin tocar un header previo; contexto temporal de prueba retirado. Sin llamadas
externas ni prueba de entrega de INVITE al proveedor.
