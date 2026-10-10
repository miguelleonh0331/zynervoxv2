# CHANGELOG_AGENT.md

Historial append-only de cambios hechos por agentes.

No reescribir entradas antiguas. Agregar nuevas entradas al final con
`repo_agent.py log-change`.

## Formato

### YYYY-MM-DD HH:mm - <agente> - <módulo>

Tipo: fix | feature | refactor | docs | test | chore

Resumen:
Qué se cambió.

Motivo:
Por qué se cambió.

Archivos modificados:
- ruta/archivo

Contrato:
- sin cambios
- modificado con ADR-XXXX

Riesgos:
- ninguno conocido

### 2026-10-01 18:30 - ARCHITECT_AGENT - release,mirmidon,whatsapp

Tipo: test

Resumen:
Completar el despliegue híbrido aislado en mirmidon y aprobar web, AGC, WhatsApp, SSO, persistencia y protección de producción.

Motivo:
Conservar evidencia reproducible antes de promover la versión.

Archivos modificados:
- docs/DEPLOYMENT_MIRMIDON_2026-10-01.md
- README.md

Contrato:
- sin cambios

Riesgos:
- módulos Python opcionales pendientes para pruebas específicas de Bot IVR
- publicación de la imagen integrada en Docker Hub pendiente de autenticación

### 2026-09-30 18:54 - ARCHITECT_AGENT - transversal

Tipo: refactor

Resumen:
Crear distribución portable Zynervox v2

Motivo:
Versionar el servicio sin copiar secretos ni datos de producción

Archivos modificados:
- app/, asterisk/, installer/, docs/, src/features/

Contrato:
- sin cambios

Riesgos:
El instalador aún requiere prueba completa en un servidor limpio.

### 2026-09-30 19:04 - installer_AGENT - installer

Tipo: docs

Resumen:
Aclarar dependencia obligatoria de VICIdial

Motivo:
Cloud Perú tiene Asterisk y MySQL pero carece del esquema y astguiclient.conf

Archivos modificados:
- README.md, docs/DEPLOYMENT.md

Contrato:
- sin cambios

Riesgos:
Sin VICIdial el login y la operación no pueden probarse.

### 2026-09-30 19:20 - ARCHITECT_AGENT - installer

Tipo: feature

Resumen:
Instalador web-first con diagnóstico PARTIAL y modo strict.

Motivo:
Permitir pruebas visuales en hosts Ubuntu sin VICIdial completo.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-09-30 20:05 - ARCHITECT_AGENT - installer

Tipo: feature

Resumen:
Añadidos Compose MariaDB, esquema sanitizado y gestor de inicialización, credenciales, backup y restauración.

Motivo:
Permitir instalación reproducible sobre hosts con MySQL incompatible.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-09-30 20:05 - ARCHITECT_AGENT - core

Tipo: feature

Resumen:
Configuración de base aislada con fallback compatible a astguiclient.conf.

Motivo:
Conectar PHP a MariaDB Docker sin alterar la configuración VICIdial del host.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-09-30 21:18 - ARCHITECT_AGENT - whatsapp

Tipo: feature

Resumen:
Módulo WhatsApp, portal, stack Zynerwaba y proxy reproducible

Motivo:
Convertir Zynervox en portal omnicanal manteniendo límites de servicio

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-09-30 21:18 - ARCHITECT_AGENT - installer

Tipo: feature

Resumen:
Instalador opcional --with-whatsapp y gestor de ciclo de vida

Motivo:
Desplegar y verificar Zynerwaba desde el repositorio

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-09-30 21:23 - whatsapp_AGENT - whatsapp

Tipo: feature

Resumen:
Backup, restauración y retirada reversible del proxy

Motivo:
Completar ciclo operativo y recuperación del módulo

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-09-30 21:26 - whatsapp_AGENT - whatsapp

Tipo: test

Resumen:
Integración verificada en cloud-peru sobre commit 97e38eb

Motivo:
Confirmar HTTP, proxy, Socket.IO, login, sesión, backup, restauración y aislamiento

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-09-30 22:10 - whatsapp_AGENT - whatsapp

Tipo: test

Resumen:
Smoke test reproducible para contenedores, esquema, HTTP, login, sesión, Socket.IO y persistencia

Motivo:
Versionar la verificación que antes existía solo como artefacto temporal del laboratorio

Archivos modificados:
- src/features/whatsapp/tests/smoke.sh
- src/features/whatsapp/README.md

Contrato:
- sin cambios

Riesgos:
- el reinicio es opcional y se activa solo con WHATSAPP_TEST_RESTART=1

### 2026-09-30 22:25 - whatsapp_AGENT - whatsapp

Tipo: test

Resumen:
Gate E2E Meta documentado y verificación negativa del token del webhook en el smoke test

Motivo:
Definir evidencia suficiente para aprobar recepción, envío, estados, aislamiento e idempotencia

Archivos modificados:
- src/features/whatsapp/tests/smoke.sh
- src/features/whatsapp/tests/META_E2E.md
- src/features/whatsapp/README.md

Contrato:
- sin cambios

Riesgos:
- el E2E exige recursos Meta exclusivos de laboratorio y consentimiento del destinatario

### 2026-10-01 00:20 - ARCHITECT_AGENT - whatsapp

Tipo: fix

Resumen:
Compatibilidad de administradores y líneas con el backend Zynerwaba 2.0.0, sincronización de credencial inicial y smoke autenticado real

Motivo:
QA real detectó endpoints frontend obsoletos y un falso positivo al aceptar `/api/me` con `user:null`

Archivos modificados:
- whatsapp/overrides/views/empresas/index.html
- whatsapp/compose.yml
- installer/whatsapp.sh
- src/features/whatsapp/tests/smoke.sh
- src/features/whatsapp/README.md

Contrato:
- sin cambios; se alinea el frontend con las rutas ya declaradas por Zynerwaba

Riesgos:
- el override debe revisarse al actualizar la imagen Zynerwaba

### 2026-10-01 00:45 - ARCHITECT_AGENT - whatsapp

Tipo: fix

Resumen:
La bandeja y gestión de administradores usan `/api/my-lines`

Motivo:
QA detectó respuestas 403 al cargar líneas y crear contactos con un administrador de empresa

Archivos modificados:
- whatsapp/overrides/public/app.js
- whatsapp/overrides/views/gestion/index.html
- whatsapp/compose.yml
- src/features/whatsapp/README.md

Contrato:
- sin cambios; se consume la ruta de líneas ya definida para administradores

Riesgos:
- los overrides deben revisarse al actualizar la imagen Zynerwaba

### 2026-10-01 01:05 - ARCHITECT_AGENT - whatsapp

Tipo: fix

Resumen:
Guardia superadmin limitada a las rutas de empresas sin interceptar el resto del API

Motivo:
El router global bloqueaba `/api/my-lines`, contactos y módulos posteriores para administradores válidos

Archivos modificados:
- whatsapp/overrides/src/features/empresas/index.js
- whatsapp/compose.yml
- src/features/whatsapp/README.md

Contrato:
- sin cambios; se restaura la autorización documentada del backend

Riesgos:
- el override debe retirarse cuando una imagen Zynerwaba posterior incluya la corrección

### 2026-10-01 07:25 - ARCHITECT_AGENT - whatsapp

Tipo: fix

Resumen:
Administradores de empresa visibles, contados y editables desde el panel de superadministración

Motivo:
Los administradores se guardaban en MySQL, pero el resumen mostraba cero y la recarga cerraba el panel

Archivos modificados:
- whatsapp/overrides/src/features/empresas/index.js
- whatsapp/overrides/views/empresas/index.html

Contrato:
- se reutilizan GET, POST, PATCH y DELETE de `/api/empresas/:id/admins`

Riesgos:
- el override debe revisarse al actualizar la imagen Zynerwaba

### 2026-10-01 07:41 - ARCHITECT_AGENT - whatsapp

Tipo: feature

Resumen:
Interfaz nativa WhatsApp con SSO firmado y consumo API

Motivo:
Unificar sesión y operación omnicanal sin acoplar bases de datos

Archivos modificados:
- app/web/modules/admin/whatsapp.php; installer/whatsapp.sh; whatsapp/compose.yml; whatsapp/overrides/src/features/empresas/index.js; src/features/whatsapp/tests/smoke.sh

Contrato:
- sin cambios

Riesgos:
La imagen base se extiende mediante overrides hasta publicar una versión consolidada

### 2026-10-01 11:48 - ARCHITECT_AGENT - whatsapp

Tipo: fix

Resumen:
Completar API multiempresa de usuarios operativos

Motivo:
La imagen 2.0.0 no publicaba /api/users aunque el frontend y la integración nativa lo consumen

Archivos modificados:
- whatsapp/overrides/src/features/empresas/index.js; src/features/whatsapp/CONTRACT.md

Contrato:
- sin cambios

Riesgos:
Override temporal hasta consolidar una imagen nueva

### 2026-10-01 11:56 - ARCHITECT_AGENT - whatsapp

Tipo: feature

Resumen:
Operación nativa de listas y envíos de campañas

Motivo:
Completar el flujo de campaña sin depender de la interfaz interna de Zynerwaba

Archivos modificados:
- app/web/modules/admin/whatsapp.php; whatsapp/overrides/src/features/empresas/index.js; src/features/whatsapp/CONTRACT.md; src/features/whatsapp/tests/smoke.sh

Contrato:
- sin cambios

Riesgos:
El inicio real exige credenciales Meta y plantilla aprobada

### 2026-10-01 12:06 - ARCHITECT_AGENT - whatsapp

Tipo: fix

Resumen:
Restaurar administración multiempresa en la interfaz nativa

Motivo:
El superadministrador necesitaba crear empresas, administradores, números y credenciales sin volver al panel interno

Archivos modificados:
- app/web/modules/admin/whatsapp.php; src/features/whatsapp/README.md; src/features/whatsapp/CONTRACT.md; docs/flows/whatsapp-omnicanal.md

Contrato:
- sin cambios

Riesgos:
Las credenciales Meta se guardan cifradas y nunca se muestran

### 2026-10-01 12:14 - ARCHITECT_AGENT - whatsapp

Tipo: refactor

Resumen:
Separar visualmente administración de operaciones WhatsApp

Motivo:
El panel actual debe contener solo configuración y gestión de identidades

Archivos modificados:
- app/web/modules/admin/whatsapp.php; src/features/whatsapp/README.md; src/features/whatsapp/CONTRACT.md; docs/ARCHITECTURE.md; docs/flows/whatsapp-omnicanal.md

Contrato:
- modificado con ADR-0007

Riesgos:
Las operaciones quedan accesibles solo por API o panel Zynerwaba hasta crear su módulo dedicado

### 2026-10-01 12:22 - ARCHITECT_AGENT - whatsapp

Tipo: refactor

Resumen:
Clarificar formularios administrativos WhatsApp

Motivo:
Los campos carecían de etiquetas y la distribución era demasiado compacta

Archivos modificados:
- app/web/modules/admin/whatsapp.php

Contrato:
- sin cambios

Riesgos:
Cambio visual sin alterar endpoints ni datos

### 2026-10-01 12:38 - ARCHITECT_AGENT - whatsapp

Tipo: docs

Resumen:
Alinear documentación de mantenimiento, flujo, roadmap, actualización y rollback con main b038cf5.

Motivo:
Preparar la reconstrucción de la imagen Docker y el siguiente release Git con trazabilidad.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 16:30 - ARCHITECT_AGENT - agent

Tipo: feature

Resumen:
Incorporar app/web/agc/zynervox.php desde Kamatera para migración y pruebas progresivas del AGC.

Motivo:
Versionar el AGC por archivos y validarlo primero en el ambiente aislado.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 16:31 - ARCHITECT_AGENT - agent

Tipo: docs

Resumen:
Documentar el primer despliegue AGC y sus tres dependencias PHP pendientes.

Motivo:
Mantener el estado de laboratorio reproducible antes de migrar el siguiente archivo.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 16:33 - ARCHITECT_AGENT - agent

Tipo: feature

Resumen:
Agregar dbconnect_mysqli.php y functions.php como dependencias iniciales de la consola AGC.

Motivo:
Resolver el primer HTTP 500 sin versionar astguiclient.conf ni credenciales.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 16:33 - ARCHITECT_AGENT - agent

Tipo: fix

Resumen:
Permitir que el AGC lea ZYNERVOX_CONFIG_FILE o /etc/zynervox/astguiclient.conf.

Motivo:
Conectar al esquema aislado de laboratorio sin versionar secretos ni copiar configuración productiva.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 16:46 - ARCHITECT_AGENT - agent

Tipo: docs

Resumen:
Registrar mirmidon como laboratorio VICIdial aislado para AGC y listar recursos visuales pendientes.

Motivo:
La prueba requiere esquema VICIdial completo sin tocar /srv/www/htdocs/agc productivo.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 17:01 - ARCHITECT_AGENT - agent,whatsapp,installer

Tipo: feature

Resumen:
Preparar release híbrido con AGC completo saneado, imagen WhatsApp integrada, Compose aislado y bootstrap de una sola orden.

Motivo:
Validar el despliegue integral en mirmidon sin tocar producción.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 18:44 - ARCHITECT_AGENT - farm

Tipo: feature

Resumen:
Integrar Farm autenticado con servicios systemd aislados.

Motivo:
Incorporar anexos y proxies al menu principal sin tocar servicios productivos.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 18:44 - ARCHITECT_AGENT - stt_providers

Tipo: feature

Resumen:
Integrar Stt Providers autenticado con base MariaDB propia.

Motivo:
Administrar proveedores STT desde Zynervox sin compartir secretos ni bases.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 18:54 - ARCHITECT_AGENT - farm,stt_providers

Tipo: test

Resumen:
Validar autenticacion, CSRF, servicios Farm, CRUD STT, enmascarado y limpieza en mirmidon.

Motivo:
Conservar evidencia del despliegue aislado antes de promover.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 21:57 - ARCHITECT_AGENT - farm,stt_providers

Tipo: docs

Resumen:
Documentar backup, restauracion y retiro seguro de ambos modulos.

Motivo:
Completar el procedimiento de mantenimiento del despliegue portable.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 22:04 - ARCHITECT_AGENT - farm

Tipo: fix

Resumen:
Se eliminaron los iframe de Farm y STT y se integraron vistas nativas aisladas.

Motivo:
Corregir ancho, navegación y scroll duplicados en el panel administrativo.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-01 22:17 - ARCHITECT_AGENT - farm

Tipo: fix

Resumen:
Las vistas administrativas nativas ahora ocupan todo el ancho disponible.

Motivo:
El contenedor flexible conservaba ancho automático y dejaba una franja vacía a la derecha.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-02 08:16 - zynerdesk_AGENT - zynerdesk

Tipo: feature

Resumen:
Alta del modulo zynerdesk: compose.yml, installer/zynerdesk.sh, proxy Apache, flag --with-zynerdesk, entrada de sidebar. Stack de prueba viejo synervox-remoteo-test eliminado.

Motivo:
Integrar supervision remota (Synervox Remoteo 2.0.2, digest fijado) como area Zynerdesk de Zynervox, reproducible desde GitHub.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-02 10:35 - ARCHITECT_AGENT - zynerdesk

Tipo: fix

Resumen:
Zynerdesk se embebe en el shell de Zynervox mediante composicion server-side con lista blanca de vistas (panel, supervicion, usuarios, remoteo); documentacion del modulo, contrato, agente, arquitectura y flujo alineados con lo implementado.

Motivo:
La navegacion al proxy sacaba al operador del panel; ademas la documentacion describia un enfoque de integracion que no es el que quedo en codigo.

Archivos modificados:
- (sin archivos indicados)

Contrato:
- sin cambios

Riesgos:
- ninguno conocido

### 2026-10-02 11:14 - ARCHITECT_AGENT - zynerdesk

Tipo: feature

Resumen:
Integrar SSO automático desde la sesión administrativa Zynervox

Motivo:
Eliminar el segundo formulario de login sin desactivar la seguridad propia de Zynerdesk

Archivos modificados:
- app/web/modules/admin/zynerdesk.php,installer/zynerdesk.sh,zynerdesk/compose.yml,src/features/zynerdesk/README.md,src/features/zynerdesk/CONTRACT.md,docs/ROADMAP.md

Contrato:
- sin cambios

Riesgos:
La integración depende de que el secreto HMAC coincida entre PHP y el contenedor; el login directo queda como fallback

### 2026-10-02 11:17 - zynerdesk_AGENT - zynerdesk

Tipo: fix

Resumen:
Actualizar instalaciones existentes al digest SSO sin recrear secretos ni volúmenes

Motivo:
El .env persistente conservaba la imagen anterior aunque el repositorio fijara un digest nuevo

Archivos modificados:
- installer/zynerdesk.sh

Contrato:
- modificado con ADR-0014

Riesgos:
La actualización depende de acceso de lectura al registro GHCR

### 2026-10-02 11:18 - zynerdesk_AGENT - zynerdesk

Tipo: fix

Resumen:
Alinear cookie SSO con el despliegue HTTP de laboratorio

Motivo:
SameSite=None sin Secure es rechazado por navegadores y Secure no funciona sobre HTTP

Archivos modificados:
- zynerdesk/compose.yml,installer/zynerdesk.sh,src/features/zynerdesk/README.md

Contrato:
- modificado con ADR-0014

Riesgos:
Producción HTTPS debe cambiar ZYNERDESK_COOKIE_SECURE a 1

### 2026-10-02 17:40 - ARCHITECT_AGENT - transversal

Tipo: docs

Resumen:
Documentar la copia local oficial y el flujo de publicación y despliegue sin SFTP,
SCP, copias manuales ni ediciones directas en servidores.

Motivo:
Mantener GitHub y las imágenes inmutables como fuentes únicas de toda instalación
y evitar diferencias no versionadas entre entornos.

Archivos modificados:
- README.md
- docs/DEPLOYMENT.md
- docs/DECISIONS.md
- docs/CHANGELOG_AGENT.md

Contrato:
- sin cambios; decisión registrada en ADR-0015

Riesgos:
- la ruta local documentada es específica del entorno Windows del propietario

### 2026-10-03 09:52 - whatsapp_AGENT - whatsapp

Tipo: refactor

Resumen:
Vendorizar codigo de Zynerwaba (extraido de la imagen 2.1.0-zynervox) en src/features/whatsapp/vendor/ como Paso 1-2 de la migracion a nativo

Motivo:
El codigo solo existia dentro de la imagen Docker; sin esto no hay forma de versionarlo ni de correr el servicio sin contenedor

Archivos modificados:
- src/features/whatsapp/vendor/** .gitattributes .gitignore src/features/whatsapp/README.md src/features/whatsapp/CONTRACT.md

Contrato:
- modificado con ADR-0016

Riesgos:
DB_PATH_MEDIA en vendor/config.js se calcula desde __dirname, no desde entorno; requiere symlink en el instalador hacia /var/lib/zynervox-whatsapp para no romper con ProtectSystem=strict

### 2026-10-03 10:12 - whatsapp_AGENT - whatsapp

Tipo: refactor

Resumen:
Completar migracion whatsapp docker->nativo: installer/whatsapp.sh reescrito, check.sh, smoke.sh y agente actualizados; 11 criterios de aceptacion de TAREA_WHATSAPP_NATIVO.md verificados en ciclo borrar/clonar/instalar desde feat/whatsapp-nativo

Motivo:
Cerrar ADR-0016: eliminar Docker/Compose del modulo, unificar con el patron systemd+MySQL nativo de farm/stt_providers

Archivos modificados:
- installer/whatsapp.sh installer/check.sh whatsapp/.env.example src/features/whatsapp/tests/smoke.sh agents/whatsapp_AGENT.md whatsapp/compose.yml(eliminado) whatsapp/Dockerfile(eliminado) whatsapp/overrides/(eliminado)

Contrato:
- modificado con ADR-0016

Riesgos:
Rama feat/whatsapp-nativo publicada, fusionada a main el 2026-10-03 tras validar los 11 criterios en WSL (zynervox-borrar). Pendiente revisar en servidores productivos reales (no se toco mirmidon).

### 2026-10-03 10:49 - zynerdesk_AGENT - zynerdesk

Tipo: refactor

Resumen:
Vendorizar codigo de Synervox Remoteo (extraido de ghcr.io/miguelleonh0331/synervox-remoteo) en src/features/zynerdesk/vendor/, primer paso de la migracion a nativo

Motivo:
El codigo solo existia dentro de la imagen Docker; sin esto no hay forma de versionarlo ni de correr el servicio sin contenedor

Archivos modificados:
- src/features/zynerdesk/vendor/** src/features/zynerdesk/README.md src/features/zynerdesk/CONTRACT.md

Contrato:
- modificado con ADR-0017

Riesgos:
Modulo validado en mirmidon (produccion) en su forma Docker actual; esta rama no toca mirmidon, solo WSL de pruebas

### 2026-10-03 10:59 - zynerdesk_AGENT - zynerdesk

Tipo: refactor

Resumen:
Cerrar migracion zynerdesk docker->nativo: 6 pruebas de aceptacion verificadas en zynervoxv1 (WSL) - docker vacio, systemd activo, 14 tablas migradas, proxy 200, SSO HMAC real 200 con identidad correcta, sesion persiste tras reinicio del servicio

Motivo:
Cerrar ADR-0017 zynerdesk: confirmar que la migracion funciona end-to-end, no solo que arranca

Archivos modificados:
- docs/CHANGELOG_AGENT.md

Contrato:
- modificado con ADR-0017

Riesgos:
check.sh --strict sigue en FAIL solo por Asterisk local ausente (gap preexistente, no relacionado). Rama feat/zynerdesk-nativo publicada, fusionada a main el 2026-10-03. Probado en WSL zynervoxv1, no en mirmidon (produccion sigue en Docker).

### 2026-10-03 23:18 - whatsapp_AGENT - whatsapp

Tipo: fix

Resumen:
Corregir la ruta relativa del helper criptografico usado por el webhook GET de Meta y agregar una prueba de regresion con verify token cifrado.

Motivo:
El modulo cargaba `../shared/crypto` desde `src/features/whatsapp`, ruta inexistente. El error se ocultaba en `decryptVerify()` y todo challenge valido terminaba en HTTP 403.

Archivos modificados:
- src/features/whatsapp/vendor/src/features/whatsapp/index.js
- src/features/whatsapp/tests/webhook-verify-token.test.js
- src/features/whatsapp/README.md
- docs/CHANGELOG_AGENT.md

Contrato:
- sin cambios

Riesgos:
- cambio localizado a la verificacion GET; la recepcion POST y el envio a Graph API no cambian

### 2026-10-03 23:32 - whatsapp_AGENT - whatsapp

Tipo: fix

Resumen:
Resolver dinamicamente los callbacks tardios de conversaciones y salud desde el webhook POST de Meta.

Motivo:
`whatsapp` se registra antes que `conversaciones` y `salud`; al desestructurar `classifyIncoming` y `applyWebhookHealthUpdate` durante el registro, ambos quedaban permanentemente `undefined` y los POST de Meta se aceptaban con HTTP 200 sin crear mensajes.

Archivos modificados:
- src/features/whatsapp/vendor/src/features/whatsapp/index.js
- src/features/whatsapp/tests/webhook-late-handlers.test.js
- src/features/whatsapp/README.md
- docs/CHANGELOG_AGENT.md

Contrato:
- sin cambios

Riesgos:
- no cambia el orden de modulos ni las APIs; los callbacks se consultan en `ctx` al procesar cada evento

### 2026-10-03 23:38 - whatsapp_AGENT - whatsapp

Tipo: fix

Resumen:
Propagar `timestampMs` desde `classifyIncoming` hasta `insertIncoming` en las rutas de texto, opt-out y multimedia.

Motivo:
El webhook real alcanzaba conversaciones, pero la insercion fallaba con `timestampMs is not defined` porque `insertIncoming` usaba una variable fuera de alcance.

Archivos modificados:
- src/features/whatsapp/vendor/src/features/conversaciones/index.js
- src/features/whatsapp/tests/conversaciones-timestamp.test.js
- src/features/whatsapp/README.md
- docs/CHANGELOG_AGENT.md

Contrato:
- sin cambios

Riesgos:
- cambio limitado a propagar la fecha ya recibida; no altera clasificacion ni permisos
### 2026-10-04 00:10 - whatsapp_AGENT - whatsapp

Tipo: fix

Resumen:
Compatibilizar la bandeja moderna con el backend vendorizado disponible: las
funciones auxiliares ausentes degradan solo ante HTTP 404 y el envío de texto
usa `POST /contacts/:id/messages`.

Motivo:
El arranque de administradores se detenía en `/auto-replies` antes de cargar
contactos; otros roles acumulaban 404 en clasificaciones, métricas, reservas,
menciones y respuestas rápidas. Además, el formulario llamaba `/send`, ruta no
implementada por el backend.

Archivos modificados:
- src/features/whatsapp/vendor/public/app.js
- src/features/whatsapp/vendor/public/index.html
- src/features/whatsapp/tests/inbox-legacy-backend-compat.test.js
- src/features/whatsapp/README.md
- docs/CHANGELOG_AGENT.md

Contrato:
- la bandeja principal y el envío de texto siguen funcionales con el backend
  actual; errores distintos de 404 siguen propagándose

Riesgos:
- las funciones auxiliares cuyo backend aún no existe permanecen vacías o
  inactivas, sin bloquear contactos y mensajes
### 2026-10-04 00:25 - whatsapp_AGENT - whatsapp

Tipo: fix

Resumen:
Restaurar actualización en tiempo real y alertas de mensajes entrantes en la
bandeja mediante eventos Socket.IO compatibles.

Motivo:
El backend emitía `contact:refresh`, mientras el frontend escuchaba
`contacts:refresh`; además, el guardado entrante nunca emitía `message:new`,
evento necesario para refrescar el chat activo y disparar la alerta sonora.

Archivos modificados:
- src/features/whatsapp/vendor/src/features/conversaciones/index.js
- src/features/whatsapp/vendor/src/features/conversaciones/CONTRACT.md
- src/features/whatsapp/tests/conversaciones-realtime.test.js
- src/features/whatsapp/README.md
- docs/CHANGELOG_AGENT.md

Contrato:
- conserva `contact:refresh` y añade `contacts:refresh` por compatibilidad
- emite `message:new` únicamente después de persistir el mensaje

Riesgos:
- los fallos de Socket.IO permanecen fail-open y no interrumpen la persistencia

### 2026-10-06 12:52 - CLAUDE_AGENT - farm

Tipo: feature

Resumen:
Inventario de cuentas Gmail para archivos de proxies subidos (proxy_files.php nuevo, UI de carga con campo cuenta y tabla con eliminar)

Motivo:
El usuario usa mas de 3 cuentas Gmail para generar cuentas Webshare y necesitaba rastrear de que cuenta viene cada archivo .txt subido, ademas de poder eliminarlos desde la web

Archivos modificados:
- src/features/farm/vendor/proxy_files.php, src/features/farm/vendor/proxy_upload.php, src/features/farm/vendor/monitor.php, src/features/farm/vendor/monitor/renderer.js, src/features/farm/vendor/monitor/web-bridge.js, src/features/farm/vendor/monitor/styles.css, app/web/modules/admin/farm.php

Contrato:
- sin cambios

Riesgos:
ninguno conocido; capa aditiva via sidecar .inventory.json que orchestrator.py ignora (solo lee *.txt), no cambia el parseo ni el modelo de datos existente

### 2026-10-06 12:52 - CLAUDE_AGENT - farm

Tipo: fix

Resumen:
orchestrator.py purga proxies inactivos del pool sin exigir detener el motor completo

Motivo:
La regla original retenia CUALQUIER proxy removido del disco mientras el motor estuviera encendido sin distinguir si estaba en uso, obligando a un stop completo (cortando workers activos) solo para limpiar credenciales ya inactivas

Archivos modificados:
- src/features/farm/vendor/services/control-plane/orchestrator.py

Contrato:
- sin cambios

Riesgos:
ninguno conocido; verificado con harness aislado (Orchestrator.__new__ + stubs de Store) cubriendo purga con motor encendido, no-purga si busy, no-purga con proceso vivo, purga total con motor apagado (comportamiento original preservado) y caso sin removidos. Desplegado en docker_converxa con el motor detenido y 0 workers activos

### 2026-10-06 13:59 - CLAUDE_AGENT - farm

Tipo: feature

Resumen:
Backoff progresivo de polling (3-6-12-30-60s) en workers TTS y auto-stop del motor tras 111s de inactividad confirmada

Motivo:
Con varias decenas/cientos de workers activos, el polling fijo cada 3s a tts_jobs_api.php generaba carga constante de fondo en mirmidon incluso con la cola vacia. El usuario pidio una escalera de reintento que suba hasta 60s y que, al agotarse sin trabajo, detenga todo el motor (requiere reinicio manual) en vez de seguir consultando indefinidamente

Archivos modificados:
- src/features/farm/vendor/services/control-plane/pc_tts_worker_proxy.py, src/features/farm/vendor/services/control-plane/orchestrator.py

Contrato:
- sin cambios

Riesgos:
El auto-stop se valida de forma centralizada en el orquestador (cola realmente vacia segun mirmidon Y ningun worker busy), no por el autorreporte de un worker individual, para no arriesgar cortar una generacion en curso. Verificado con harnesses aislados (Orchestrator.__new__ y ejecucion directa de run_worker con api_call/sleep mockeados): secuencia de sleeps exacta 3-6-12-30-60-60-60, reset a 3 tras un job real, y 6 escenarios del watchdog (busy bloquea, cola no confirmada bloquea, no dispara antes de tiempo, dispara al umbral, reset limpio en start/stop). Desplegado en docker_converxa, servidor de pruebas

### 2026-10-06 15:09 - CLAUDE_AGENT - farm

Tipo: fix

Resumen:
apply_target() ya no revienta con 'list index out of range' al iniciar el motor tras reducir el pool de proxies

Motivo:
La tabla SQLite workers es append-only (ensure_workers solo inserta/actualiza, nunca borra). Al bajar el pool real de 200 a 10 proxies, apply_target() seguia iterando TODAS las filas historicas (hasta indice 200) para elegir candidatos aleatorios, y _is_blocked(index) intentaba self.workers[index-1] con un indice fantasma fuera de rango. Se reprodujo en vivo al pulsar Iniciar motor en docker_converxa tras cargar solo 10 proxies Webshare

Archivos modificados:
- src/features/farm/vendor/services/control-plane/orchestrator.py

Contrato:
- sin cambios

Riesgos:
Bug preexistente, no introducido por los cambios de esta sesion (purga/backoff). Verificado con harness aislado que reproduce el escenario exacto (200 filas SQLite historicas, 10 workers reales): antes del fix synthetic test habria fallado con IndexError via _is_blocked, despues de el fix resume los 10 sin error y el caso normal (target menor al pool) sigue repartiendo resumed/drained correctamente

### 2026-10-07 19:25 - zynerdesk_AGENT - zynerdesk

Tipo: feature

Resumen:
Agrega retiro reversible de equipos y actualización segura de Zynerdesk 2.0.3

Motivo:
Ocultar clientes inactivos de la vista principal sin borrar historial y desplegar el módulo sin recrear su base.

Archivos modificados:
- src/features/zynerdesk/vendor/src/app.js
- src/features/zynerdesk/vendor/src/features/agents/index.js
- src/features/zynerdesk/vendor/web/index.html
- src/features/zynerdesk/vendor/migrations/015_retired_agents.sql
- src/features/zynerdesk/vendor/tests/agents-retirement.test.js
- installer/zynerdesk.sh
- src/features/zynerdesk/CONTRACT.md
- src/features/zynerdesk/README.md
- docs/ARCHITECTURE.md
- docs/STACK.md
- docs/DEPLOYMENT.md
- docs/MODULE_MAP.md

Contrato:
- modificado con ADR-0018

Riesgos:
La migración añade columnas y FK sin borrar datos; upgrade genera backup y rollback automático del runtime.

### 2026-10-09 10:26 - ARCHITECT_AGENT - agent y contratos modulares

Tipo: fix

Resumen:
Consolida marca, CRM demo y WhatsApp integrado; elimina auto-login compartido; normaliza contratos y clasifica pendientes.

Motivo:
Usuario autoriza commits, merges y push de todo el trabajo pendiente de v2.

Archivos modificados:
- app/web/agc/zynervox.php
- docs/LOCAL_WORK_AUDIT.md
- .gitignore
- docs/MODULE_MAP.md
- src/features/farm/CONTRACT.md
- src/features/stt_providers/CONTRACT.md
- src/features/whatsapp/CONTRACT.md
- src/features/zynerdesk/CONTRACT.md

Contrato:
- modificado con ADR-0019

Riesgos:
- ninguno conocido

## 2026-10-05 — bot_ivr: conexión configurable

- Botón y formulario de conexión en app/web/bot_ivr/index.php para administradores.
- Servidor, puerto, base de datos, usuario y contraseña; CSRF y prueba antes de guardar.
- Persistencia atómica fuera de la raíz web en secrets/bot_ivr_db.json (0640).
- Adaptador propio app/web/bot_ivr/db.php consumido por los endpoints del módulo;
  runtime_store.py lee la misma configuración y conserva el fallback previo.
- No cambia CONTRACT.md ni la configuración compartida de otros módulos.
- Validación: lint PHP del módulo, sintaxis Python, pruebas de fallback/override
  del worker y rechazo PHP de puertos/parámetros inv?lidos. Sin BD real disponible
  en esta copia local; no se verifica conexión productiva ni despliegue.


## 2026-10-05 - Despliegue Bot IVR en mirmidon

- Desplegado con zynertools en /srv/www/htdocs/zynervoxv2205/bot_ivr/.
- Worker: /etc/asterisk/zynervoxv2205/modules/bot_ivr/runtime_store.py.
- Adaptacion de la ruta de secretos en las copias desplegadas: /etc/asterisk/zynervoxv2205/secrets/bot_ivr_db.json.
- Directorio de secretos creado wwwrun:www 0750; nuevo db.php root:www 0640.
- Archivos previos cotejados contra dbc993b y respaldados por push.py como .bak.20261005-*.
- Verificacion remota: lint PHP completo, sintaxis Python, permisos como wwwrun, SELECT 1 con conexion actual, HTTP 302 al login y render del boton en sesion administrativa CLI.
- No se cambian credenciales ni se reinician servicios; formulario de guardado pendiente de uso con las credenciales elegidas por el administrador.


## 2026-10-05 - Bot IVR: creación de base zynervox en mirmidon

- Creada zynervox con 11 tablas vacías: campañas, cola local, resultados,
  resumen de estado, procesos, eventos, construcciones/archivos de audio y
  ejecuciones/variables/eventos de llamada. SQL reproducible bajo bot_ivr/models.
- Base fija en el botón; PHP y worker bloquean configuración ausente y otras bases.
- No se guardan credenciales fuera del botón. Configuración aún pendiente del administrador.
- Desplegados db.php, index.php y runtime_store.py con backups .bak.20261005-1648*.
- Verificación: PHP lint, Python AST, rechazo de configuración ausente/base incorrecta,
  inserción/listado de campaña con rollback y render administrativo del botón/campo fijo.
- Base demo intacta; sin procesos activos de los workers inspeccionados ni reinicios.

## 2026-10-05 - Bot IVR: usuario propio en mirmidon

- Creadas cuentas zynervox_bot_ivr@localhost y @127.0.0.1 con contraseña propia.
- Permisos SELECT, INSERT, UPDATE, DELETE exclusivamente sobre zynervox.*.
- Conexión TCP 127.0.0.1 y creación/lectura de campaña verificadas con rollback.
- Credenciales entregadas al administrador; guardado por botón pendiente.
- No cambia estructura ni datos de negocio; integración en instalador pendiente.

## 2026-10-05 - Bot IVR: administración nueva de campañas y listas

- Aplicada 002-campaign-lists.sql en mirmidon: tres tablas nuevas, FK e índices,
  sin borrar tablas anteriores ni importar datos de demo.
- index.php: listado inicial, botón Crear campaña, ID automático/nombre/activo
  en formulario separado y redirección al detalle. Sin requisito de flujo ni archivo.
- campaign_edit.php: editar campaña, activación/bloqueo y alta/edición de listas
  vinculadas a campaign_id; bloqueo de edición cruzada entre campañas.
- campaigns_page.php comparte sesión/CSRF/configuración obligatoria; repositorio
  campaigns_service.php usa exclusivamente el nuevo esquema.
- Despliegue de cuatro archivos con backups de pantallas previas .bak.20261005-1727*.
- Pruebas SQL transaccionales: ID/activo, horarios, listas y pertenencia, FK;
  lint PHP y render CLI: listado, formulario, detalle, CSRF y escape XSS.
- Datos de prueba revertidos. Carga de leads/ejecución de horarios/motor e
  integración en instalador quedan fuera de esta etapa.

## 2026-10-05 - Bot IVR: ordenar detalle de campaña

- Formulario compacto por filas: etiquetas izquierda, campos derecha y botón
  Guardar centrado; estilo alternado azul siguiendo referencia del administrador.
- Activo y horario diario usan selectores Sí/No; valor 0 se persiste desactivado.
- Listado debajo del formulario; creación de lista en diálogo desde botón junto al listado.
- Ajuste CSS local para evitar el fondo oscuro global de th y controles desalineados.
- Verificado render HTML de campaña 8 en agent-browser, captura escritorio y móvil,
  apertura del diálogo y prueba SQL con rollback para persistencia de No.
- Sin cambios de esquema ni datos existentes; despliegue con backups automáticos.

## 2026-10-05 - Bot IVR: ancho completo y metadatos de campaña

- Detalle en dos columnas sobre todo el ancho disponible; datos generales/horarios
  y parámetros de marcación. Totales de listas/leads y fecha de creación visibles.
- Migración 003-campaign-details.sql: 12 campos propios con referencia VICIdial;
  aplicada dos veces verificando preservación de ID/nombre/flags/horarios/fechas.
- Campos nuevos validan longitud, opciones y rangos; consultas preparadas y HTML
  escapado. Envíos de formularios anteriores preservan metadatos no enviados.
- Desplegados campaign_edit.php y campaigns_service.php con backups 20261005-1803*.
- Pruebas: lint PHP, persistencia de campos, rechazo de valores inválidos sin
  guardado parcial, compatibilidad de formularios, listas/FK y rollback;
  render real de campaña 8 en navegador, ancho completo y dos/una columna.
- Configuración de marcación/grabación/grupo aún no controla motor ni ACL;
  sin cambios en tablas legacy, otras bases ni datos de campaña existente.

## 2026-10-05 - Bot IVR: distribución y paleta Zynervox

- Reorganizado detalle en cuatro grupos dentro de dos columnas: datos generales,
  horarios, marcación y leads/grabación; listado de listas debajo a ancho completo.
- Colores exclusivamente de variables de layout.css: encabezados oscuros,
  tarjetas blancas, filas blancas/grises y naranja para navegación y acciones.
- Guardado alineado a la derecha; resumen de listas/leads junto al listado;
  navegación y diálogos homologados a la paleta del producto.
- Verificación visual del HTML real de campaña 8 en navegador: cuatro grupos,
  dos columnas en escritorio, una en pantalla estrecha; colores calculados
  correctos, creación de lista y configuración de conexión abren correctamente.
- PHP lint correcto y campos únicos conservados. Despliegue con backups
  20261005-1814*/1815*. No cambia esquema ni lógica de persistencia.

## 2026-10-05 - Bot IVR: fecha de lista, apertura y carga TXT

- Fecha de creación y botón Abrir lista en listado de campaña.
- list_edit.php: datos de lista, formulario TXT/plantilla y tabla de leads paginada;
  tema Zynervox, botón de conexión y vínculo para volver a campaña.
- list_service.php: parser UTF8/cabeceras flexibles, variables raw, dedupe dentro
  del archivo y contra lista existente; inserción transaccional por lotes y
  protección de pertenencia campaign_id/list_id.
- Pruebas con rollback: BOM/UTF8/cabeceras, contadores, valores guardados,
  recarga sin duplicar, listas independientes y rechazo de campaña ajena.
- Lint PHP correcto; render real y selección de archivo verificados en navegador.
- Desplegados tres archivos con backups del detalle previo; sin modificar esquema.
- Motor/TTS sigue separado; carga no inicia llamadas ni modifica otras listas.


## 2026-10-05 — Carga de listas: resultado sin detalle de leads
- Por solicitud del usuario se retira la tabla de contactos y su paginación de list_edit.php.
- Se conserva el formulario, el total de leads y el mensaje de resultado con cargados, duplicados y rechazados.
- Desplegado en mirmidon; PHP lint correcto.


## 2026-10-05 — Consultas compartidas PHP e integración Bot IVR

- Nuevo módulo zynervox_queries: conexión/factory común, contrato de repositorio,
  consultas mysql/bot_ivr separadas en campañas/listas/leads y reutilizadas por MariaDB.
- Administración nueva elimina SQL de páginas/services; conserva parser/validación,
  propiedad transaccional, deduplicación y pertenencia de listas.
- Botón obligatorio añade selector MySQL/MariaDB; config previa compatible,
  otros motores rechazados antes de conectar/guardar. Workers sin cambios.
- Pruebas en staging mirmidon: lint PHP, lectura equivalente, CRUD/horarios/metadatos,
  carga/duplicados/pertenencia/FK/rollback, secretos sin cambios tras rechazos.
- Sin cambios de esquema ni migración de datos. MODULE_MAP regenerado por herramienta.
- Desplegado en mirmidon bajo /srv/www/htdocs/zynervoxv2205/zynervox_queries; verificación posterior correcta de páginas, selector, pertenencia/CSRF, repositorios y rollback de importación fallida.


## 2026-10-05 — Reemplazar base de lista al cargar

- Carga ahora reemplaza contactos de lista en transacción, deduplica nuevo archivo
  y conserva base anterior ante archivo sin filas válidas o fallo de inserción.
- Savepoint protege reemplazo dentro de transacciones externas.
- UI informa sustitución y botón Reemplazar base; resultado Base reemplazada.
- Pruebas staging correctas: reemplazo, archivo vacío, reupload, fallo tras DELETE
  revertido, pertenencia y aislamiento. Fixtures revertidos con rollback.


## 2026-10-05 — Asignación de flujo por lista

- Migración 004 añade id_flujo nullable a zynervox_bot_lists en zynervox.
- Crear/Modificar lista guarda ID positivo; tabla de campaña y detalle lo muestran.
- Repositorio compartido extiende métodos con argumento opcional conservando formularios previos.
- Sin cambios de contactos, workers o IVR Builder. Pruebas creación/edición,
  IDs inválidos y preservación por omisión con fixtures revertidos.


## 2026-10-05 — Trazado y muestra de gTTS
- Localizado proveedor macelioai de CARSA y generador Python gTTS/ffmpeg/sox.
- Prueba real aislada con texto ficticio: WAV PCM16 mono 8kHz de 7.51 segundos.
- MP3 de escucha descargado al espejo local; sin cambios de listas ni llamadas.
- Documentado trazado en README Bot IVR; integración de generación por lista pendiente.


## 2026-10-05 — Prueba de audios desde texto en web

- Creada audio_lab.php con proveedor gTTS, texto, Crear audio, reproductor y descarga.
- Servicio/sessionownership y script propio gTTS/ffmpeg/sox, venv aislado en mirmidon.
- Enlace Prueba de audios en navegación; mantiene botón Configurar conexión BD.
- Pruebas PHP/Python, generación bajo wwwrun, HTTPPOST→redirect→WAV PCM16mono8k,
  ID404, CSRF y sesión ajena; navegador playback readyState4 sin errores.
- Sin modificaciones de BD/CARSA/campañas/listas ni llamadas. Desplegado mirmidon.


## 2026-10-06 — Proveedor RGA en Prueba de audios

- Selector RGA Remote Generation Audio y adapter Python HTTP del gateway.
- Token en secrets fuera Git, permisos0640 root:www acordes a Apache de mirmidon.
- Envía voz1.3; validación WAV monoPCM16 8kHz, streamingacotado/redirectsdeshabilitados.
- Historial identifica proveedor; mensajes específicos de error sin datos sensibles.
- Tests aislados5correctos; generación real remota79KB, POSTHTTP→redirect→stream
  y navegador reproduciendo4.246s, readyState4 sin error.
- Health actual reportó190candidatos; ninguna modificación del pool/gateway.

## 2026-10-07 — Actualización acotada de Bot IVR en mirmidon

- Autorización: usuario indicó «procede» para actualizar solo Bot IVR.
- Fuente local: trabajo/bot_ivr, commit 21030ff; sin cambios en código fuente.
- Desplegados 12 PHP distintos bajo /srv/www/htdocs/zynervoxv2205/bot_ivr mediante zynertools push con backups .bak.20261007-1736xx.
- index.php y campaign_edit.php pasan a la administración actual de campañas/listas; endpoints legacy consumen db.php dedicado. Rutas runtime adaptadas a zynervoxv2205.
- Los 14 PHP restantes, los 7 archivos de zynervox_queries y los 8 workers ya coinciden con la fuente tras normalizar saltos de línea y rutas propias del despliegue. No se modifican otros módulos, workers, credenciales ni bases.
- Validación: lint PHP local (26) y remoto correcto; render de index.php con sesión de prueba en memoria muestra Crear campaña, Configurar conexión y Prueba de audios; petición HTTP sin sesión devuelve 302.
- Limitación operativa confirmada: MySQL rechaza al usuario configurado zynervox_bot_ivr con error 1045. No es posible verificar campañas ni esquema con esa conexión; requiere corregir configuración/permisos por separado.
- Reversión: recuperar copias en .codex-temp/bot-ivr-update/before/bot_ivr y subir mediante zynertools; backups remotos conservados.

## 2026-10-07 — Restablecimiento de acceso MySQL de Bot IVR en mirmidon

- Autorización explícita del usuario: procede para restablecer contraseña de zynervox_bot_ivr.
- Ejecutado script preparado localmente mediante zynertools script.py; ALTER USER para localhost y 127.0.0.1 sincroniza con la configuración existente, sin guardar credenciales locales.
- Permisos SELECT/INSERT/UPDATE/DELETE sobre zynervox conservados; sin cambios de esquema, datos ni archivos de configuración.
- Verificación: coincidencia de contraseña YES en ambas cuentas; conexión a zynervox correcta; repositorio lee 1 campaña; interfaz renderizada sin error de acceso; lint PHP remoto correcto.

## 2026-10-07 — Redespliegue de Bot IVR tras reversión detectada

- Validación previa: los 12 PHP desplegados habían vuelto a coincidir exactamente con las copias anteriores a la actualización.
- A petición del usuario, redesplegados los mismos 12 PHP actuales del commit local 21030ff, con adaptación de rutas runtime. Nuevos backups .bak.20261007-1828xx y .bak.20261007-1829xx.
- Propietario y permisos de archivos restaurados desde sus backups tras la transferencia.
- Verificación: hashes 12/12 correctos, lint remoto y render actual correctos, conexión zynervox y repositorio de campañas correctos. Conteos de solo lectura: 1 campaña, 2 listas, 37330 leads.
- Sin cambios de contraseñas, cuentas MySQL, configuración, esquema o datos; no se publican commits ni push.
- La causa de la reversión anterior sigue sin identificar.

### 2026-10-09 10:31 - ARCHITECT_AGENT - bot_ivr farm zynervox_queries agent

Tipo: chore

Resumen:
Integra ramas pendientes de v2 y conserva historial; resuelve conflictos documentales append-only y regenera mapa.

Motivo:
Usuario solicita que fuente y GitHub queden actualizados; sin despliegue ni migraciones productivas.

Archivos modificados:
- docs/LOCAL_WORK_AUDIT.md
- docs/DECISIONS.md
- docs/CHANGELOG_AGENT.md
- docs/MODULE_MAP.md

Contrato:
- sin cambios

Riesgos:
Pruebas de BD aislada y generacion real gTTS pendientes antes de despliegue.

### 2026-10-09 11:02 - root - installer

Tipo: feature

Resumen:
Integracion selectiva v3: Servicios BD, CSRF, bootstrap compartido, instalador desatendido/idempotente y pruebas aisladas.

Motivo:
Solicitud de integracion v3 en v2 sin perder mejoras existentes.

Archivos modificados:
- app/web,installer,src/features/*/CONTRACT.md,docs/V3_INTEGRATION_TEST.md

Contrato:
- sin cambios

Riesgos:
Prueba manual del usuario pendiente; no migracion automatica de bases legacy ni push.

### 2026-10-09 11:10 - root - installer

Tipo: fix

Resumen:
Rutas multiplataforma SUSE/Debian en install, unattended y check; prioridad de overrides.

Motivo:
mirmidon usa /srv/www/htdocs; aprobado ARCHITECT_AGENT.

Archivos modificados:
- installer/platform.sh,installer/install.sh,installer/unattended.sh,installer/check.sh,src/features/installer

Contrato:
- sin cambios

Riesgos:
Sin cambios remotos; paquetes SUSE preinstalados requeridos.

### 2026-10-09 11:21 - root - installer

Tipo: fix

Resumen:
Progreso por etapa y archivo durante copia SMB; errores con linea/codigo sin imprimir comandos ni secretos.

Motivo:
Instalador permanecia silencioso durante copia de fuente remota.

Archivos modificados:
- installer/unattended.sh,installer/install.sh

Contrato:
- sin cambios

Riesgos:
Cambios aplican en siguiente ejecucion; no reiniciar instalacion activa.

### 2026-10-09 11:27 - root - installer

Tipo: feature

Resumen:
Despliegue web incremental rsync tamaño/mtime sin borrado; excluye runtime/secretos/config local; pruebas cero transferencias y un cambio.

Motivo:
Acelerar despliegues sobre SMB sin recopiar toda la web; aprobado ARCHITECT_AGENT.

Archivos modificados:
- installer/deploy-web.sh,installer/install.sh,src/features/installer

Contrato:
- sin cambios

Riesgos:
Requiere rsync; mismos tamaño/mtime no detectados; retirados permanecen; módulos opcionales no incrementales.

### 2026-10-09 11:37 - root - installer

Tipo: fix

Resumen:
apply-schema compatible Python3.6: universal_newlines y PIPE en lugar de text/capture_output; regresion argumentos legacy e idempotencia columnas.

Motivo:
Error reproducido en Python3.6 de ViciBox.

Archivos modificados:
- installer/apply-schema.py,src/features/installer/tests/apply-schema.py

Contrato:
- sin cambios

Riesgos:
No cambios BD durante pruebas mock.

### 2026-10-09 11:47 - root - installer

Tipo: feature

Resumen:
Resumen final credenciales root-only: admin inicial, BD bootstrap y token local; consulta manual sin reinstalacion ni rotacion.

Motivo:
Restauracion solicitada expresamente por usuario; ARCHITECT_AGENT aprobado.

Archivos modificados:
- installer/credentials.sh,installer/install.sh,installer/zynervox-core.sh,src/features/installer

Contrato:
- sin cambios

Riesgos:
Salida sensible; credenciales iniciales no garantizan valores vigentes; sin log automatico.

### 2026-10-09 12:14 - root - core

Tipo: feature

Resumen:
V2 aislado aplicado en mirmidon: configuracion propia, tablas login/config v2, cuenta Bot exclusiva, campañas vacias, guard Apache y llamadas legacy409.

Motivo:
Solicitud explicita de proteger produccion zynervox.

Archivos modificados:
- app/web,installer,src/features/core,src/features/bot_ivr,src/features/ivr_builder,src/features/installer,docs/V2_ISOLATION.md

Contrato:
- sin cambios

Riesgos:
Motor llamadas nuevo pendiente; credenciales/admin v2 propios; no push.

### 2026-10-09 12:17 - root - installer

Tipo: chore

Resumen:
Clave fija solicitada permanece en archivo privado Bot root0600 fuera de Git; codigo no contiene el valor.

Motivo:
Evitar publicar secreto al actualizar GitHub.

Archivos modificados:
- installer/isolated-env.sh,installer/ivr-builder-bot-db.sh,docs/V2_ISOLATION.md

Contrato:
- sin cambios

Riesgos:
Instalacion nueva necesita secreto privado; no se rotan cuentas existentes.

### 2026-10-09 12:23 - root - installer

Tipo: feature

Resumen:
Reconciliar contraseña cuenta Bot aislada existente: ALTER USER local + bootstrap atomico + fila v2; preflight y recuperación privada.

Motivo:
Usuario pide if exists update password; aprobado ARCHITECT_AGENT.

Archivos modificados:
- installer/ivr-builder-bot-db.sh,src/features/installer/tests/central-database.sh,src/features/installer/CONTRACT.md,docs/V2_ISOLATION.md

Contrato:
- sin cambios

Riesgos:
Rotacion no transaccional; fallo requiere recuperacion privada. No ejecutado remoto.

### 2026-10-09 13:02 - root - installer

Tipo: fix

Resumen:
Admin v2 reconcilia secreto privado y login redirige a controlador bot_ivr/index.php, no helper vacio campaigns_page.php.

Motivo:
Usuario solicita misma contraseña admin y reporta pagina en blanco.

Archivos modificados:
- installer/isolated-env.sh,installer/zynervox-core.sh,app/web/modules/admin/index.php,app/web/bot_ivr/campaigns_page.php,src/features/installer

Contrato:
- sin cambios

Riesgos:
Fuente corregida; requiere reejecucion instalador por usuario; sin cambios remotos en este turno.

## 2026-10-09: Carriers aislado e instalacion selectiva

- Modulos: core, admin, installer; alcance aprobado por ARCHITECT_AGENT.
- Carriers aislado usa zynervox_core.v2_carriers, auditoria sin secretos,
  validacion estructurada, CSRF y generacion atomica por archivo sin reload.
- --module carriers/--module=carriers evita web completa, passwords y login/Bot;
  preflight requiere destino propio existente, crea tabla/grants y tres archivos.
- Contratos core/admin/installer y README actualizados; ADR-0022.
- Pruebas MySQL efimero: CRUD, archivos inactivos, auditoria, CSRF,
  reejecucion conserva datos/configuracion/archivos fuera allowlist.
- No se modifican includes ni configuraciones Asterisk de produccion.

## 2026-10-10 ? bot_ivr: campos de discador

Migraci?n 005 a?ade status, called_count, last_call_at, next_call_at e ?ndice
por lista/estado/reintento. README y CONTRACT documentan UTC, defaults,
reemplazo de base y l?mites; prueba list-import-db verifica defaults y aislamiento
al reemplazar. No a?ade ejecuci?n de llamadas ni altera otras tablas.

Validaci?n mirmidon: migraci?n aplicada dos veces, defaults NEW/0/NULL/NULL
verificados, siete campos originales del lead existente intactos; prueba PHP
de importaci?n/reemplazo/rollback aprobada. Instalador incluye migraci?n 005.
