# Evidencia de despliegue: mirmidon

Fecha: 2026-10-01  
Commit probado: `f2fc548cad15cf73461c8d65b445e2ce4b952718`  
Rama: `release/zynervoxv2-deploy-test`

## Alcance

Se instaló el sistema completo como laboratorio separado de producción:

- fuente: `/opt/zynervoxv2-deploy-test-source`;
- web y AGC: `/srv/www/htdocs/zynervoxv2-deploy-test`;
- módulos Asterisk: `/etc/asterisk/synervox-deploy-test`;
- ruta WhatsApp: `/zynervoxv2-deploy-test-whatsapp`;
- proyecto Compose: `zynervoxv2-deploy-test`;
- imagen integrada local: `miguelleonh0331/zynerwabav2:2.1.0-zynervox`.

El instalador generó los secretos localmente. Ninguna contraseña, token ni dato
productivo forma parte de Git o de este informe.

## Resultado de las pruebas

| Prueba | Resultado |
| --- | --- |
| Zynervox `/zynervoxv2-deploy-test/` | HTTP 200 |
| AGC `/zynervoxv2-deploy-test/agc/zynervox.php` | HTTP 200 |
| WhatsApp `/zynervoxv2-deploy-test-whatsapp/` | HTTP 200 |
| Apache, MariaDB, Asterisk y Docker | activos |
| Contenedores app y MySQL | saludables |
| Esquema WhatsApp | 63 tablas |
| Login / SSO / sesión / Socket.IO | aprobado |
| SSO y webhook inválidos | rechazados con HTTP 403 |
| Recreación y persistencia | aprobada |

Salida final del smoke test:

```text
WHATSAPP_SMOKE_OK tables=63 login=200 sso=200 sso_invalid=403 session=200 socket=200 webhook_invalid=403 restart=1
```

`installer/check.sh` informó `CHECK_RESULT=PARTIAL` únicamente por módulos Python
opcionales ausentes. La web, el AGC y WhatsApp no dependen de esos módulos para
esta prueba. Antes de probar funciones Bot IVR se deben instalar y validar sus
dependencias específicas.

## Protección de producción

Los servicios productivos `apache2`, `mariadb` y `asterisk` continuaron activos.
Después del despliegue se verificaron los mismos hashes registrados antes de la
instalación:

```text
38f1ccf3e1cbb6a15df78802ac935009779eb7a1ec2c9730e6abdae34178fe57  /srv/www/htdocs/agc/vicidial.php
b7bcc2d12d5ee364d0a395819a08ebba71a4a70ef58f05933317e47b16377617  /etc/astguiclient.conf
```

## Observaciones

- ViciBox requirió el runtime OCI fijado y verificado que documenta
  `INSTALL_ONE_COMMAND.md`.
- El proxy Apache no depende de `mod_headers`; `BASE_PATH` entra directamente al
  contenedor.
- La imagen integrada se construyó localmente desde una base fijada por digest.
  Todavía no se publicó en Docker Hub porque el servidor no tiene credenciales
  de registro. El instalador sigue siendo reproducible porque construye la imagen.
- No se ejecutó envío real por Meta: requiere línea y destinatario de laboratorio.

## Repetición

Usar el comando de `INSTALL_ONE_COMMAND.md`. En una instalación interrumpida,
inspeccionar las rutas y agregar `ZYNERVOX_RESUME=1`; la reanudación conserva
volúmenes y datos.

## Extensión Farm y Stt Providers

Rama probada: `feature/farm-stt-modules`
Commits funcionales: `7788f74`, `dc2673b`

Artefactos instalados:

- Farm: `/opt/zynervoxv2-deploy-test-farm` y
  `/var/lib/zynervoxv2-deploy-test-farm`;
- servicios `zynervoxv2-deploy-test-farm-annex.service` y
  `zynervoxv2-deploy-test-farm-control.service`;
- puertos loopback Farm `8811` y `8766`;
- Stt Providers: base `zynervoxv2_deploy_test_stt`, cuatro tablas y usuario
  limitado a esa base;
- configuración protegida bajo `/etc/zynervox`.

Pruebas aprobadas:

- rechazo sin sesión: wrappers HTTP 302 y API STT HTTP 401;
- acceso con administrador principal: shell, Farm y STT HTTP 200;
- Farm: daemon, control plane, sesión y CSRF operativos;
- reinicio de ambos servicios Farm sin pérdida de configuración;
- creación/eliminación de cuenta y key STT sintéticas;
- la API STT nunca devolvió la key completa y la base quedó vacía al finalizar;
- WhatsApp repitió su smoke completo con persistencia;
- Apache, MariaDB, Asterisk y Docker continuaron activos;
- hashes productivos de VICIdial y `astguiclient.conf` sin cambios.

La creación real de anexos Farm queda bloqueada mientras
`ZYPAD_ASTERISK_HOST` esté vacío. Es un control de seguridad deliberado; su E2E
requiere un PBX de laboratorio autorizado. No se usaron API keys STT reales.
