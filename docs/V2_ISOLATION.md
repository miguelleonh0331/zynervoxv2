# V2 aislado de producción

Producción usa zynervox y también tablas compartidas en zynervox_core.
No basta cambiar el nombre de base: el singleton y login deben aislarse.

## Recursos propios

- Web: /srv/www/htdocs/zynervoxv2 (SUSE).
- Configuración: /etc/zynervox/zynervoxv2, sin fallback productivo.
- Base: zynervox_core. Usuario Bot: zynervoxv2_bot_ivr, DML por tablas propias.
- Login/configuración: v2_zynervox_users, v2_ivr_deploy_config, v2_access_log.
- Usuario core: zynervoxv2_core, sin privilegios globales en la base.
- Runtime de archivos: /var/lib/zynervoxv2/asterisk.
- Apache: Directory scoped auto_prepend_file IsolatedGate; bloquea AGC/legacy.
- Sesión: ZYNERVOXV2, cookie scoped a la URL propia.

Campañas y flujos arrancan vacíos. No se copian contactos/credenciales/datos
productivos. Las claves existentes no se rotan. La cuenta Bot nueva utiliza
la clave fija solicitada; no se documenta el secreto en este archivo.
Se conserva en archivo privado /etc/zynervox/zynervoxv2/bot-password (root 0600),
fuera de Git. Primera instalación en otro host requiere preparar ese archivo
o suministrar BOT_IVR_DB_PASSWORD privadamente.
El usuario solicitó reconciliar la contraseña Bot existente.
En modo aislado la reinstalación aplica el secreto privado a las dos cuentas
locales y sincroniza bootstrap/configuración central v2. No altera cuentas de
producción. Si falla tras ALTER USER, consultar el directorio privado de
recuperación indicado: ALTER USER no tiene rollback transaccional.

## Estado de llamadas

Crear campañas/listas y flujos escribe en core. Publicar guarda JSON aislado.
Marcación/prebuild/TTS workers legacy están bloqueados, no reconectados a producción.
Preparar workers, Python compatible, transporte SIP y dialplan propios antes de
habilitar llamadas. La UI antigua VICIdial/AGC no está disponible en este modo.
Laboratorio legacy bloqueado; audio IVR usa carpetas propias.

## Operación

El instalador desatendido usa aislamiento por defecto y omite módulos opcionales.
Para preparar únicamente web/BD:

```bash
sudo env WEB_ROOT=/srv/www/htdocs/zynervoxv2 URL_PATH=/zynervoxv2 WEB_GROUP=www bash installer/isolate-v2.sh
```

El gate Apache se configura con isolated-apache.sh desde entorno isolated-env.sh.
Es requisito de seguridad para bloquear endpoints legacy que no consumen core.
unattended/install lo configura; isolate-v2 solo prepara web/BD y debe ejecutarse
con gate ya habilitado antes de exponer el sitio.

Consultar acceso inicial sin reinstalar:

```bash
sudo bash installer/credentials.sh
```

Salida sensible. Producción mantiene sus archivos, cuentas y singleton anteriores.
