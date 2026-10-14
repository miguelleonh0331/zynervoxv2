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

## 2026-10-10 ? bot_ivr: ?rea visual de creaci?n de audios

list_edit.php a?ade tarjeta inferior a todo el ancho con proveedor TTS,
concurrencia y bot?n Generar deshabilitado; reutiliza cat?logo del laboratorio.
No ejecuta generaci?n ni altera carga de leads. README documenta alcance visual.
# 2026-10-10 — bot_ivr: lectura y validación de variables de leads

list_audio_service.php resuelve variables por lead y valida todos los textos
alcanzables del flujo. list_edit.php presenta conteos y errores. ivr_builder
expone lectura pública del JSON publicado; zynervox_queries ofrece audioLeads
con pertenencia de campaña/lista. Pruebas cubren sustitución, valores vacíos,
JSON inválido, ciclos, ramas y aislamiento. Sin generación TTS ni cambios SQL.

Verificado y desplegado en mirmidon: cinco archivos PHP idénticos a fuente y
sintaxis válida. Pruebas list-audio.php y list-import-db.php aprobadas. Lectura
real lista 1/flujo 11: un lead listo, un texto validado, cero errores. No se
enviaron solicitudes TTS; respaldo previo de los tres archivos existentes.

## 2026-10-10 — bot_ivr: generación gTTS local y caché v2

Botón Generar inicia worker aislado con textos resueltos, hash determinista,
flock por audio/lista, WAV validado y publicación atómica. Progreso autenticado
con conteos y manifiesto lead/nodo/hash; conserva BD y estado de marcación.
macelioai-tts.sh prepara worker/directorios en futuras instalaciones. Pruebas
de payload, caché, corrupción y concurrencia; no utiliza código/runtime CARSA.

Validación real mirmidon como usuario wwwrun: lista 1/flujo 11, primera ejecución
un WAV generado y cero fallos; segunda ejecución cero generados y un reutilizado.
WAV de 1.40 s, PCM16 mono8000, almacenado bajo runtime v2 con su hash. Adaptado
a gTTS 2.2.4/Python 3.6 del venv instalado; timeout de 100 s por conversión y
terminación del grupo de procesos para evitar procesos huérfanos. No se ejecutó
el instalador ni se reiniciaron servicios.

## 2026-10-10 — bot_ivr: ventana visual de progreso

list_edit.php muestra diálogo accesible con tarjetas de generados, reutilizados,
fallidos y pendientes, barra porcentual y detalle de errores. Ver progreso abre
la ventana; se abre automáticamente al entrar con generación activa. Conserva
actualización de estado existente; oculta conteos antiguos si cambia el snapshot.
Sin cambios en generación, caché o BD.

## 2026-10-10 ? bot_ivr: progreso integrado en la p?gina

Las tarjetas y barra de progreso aparecen directamente debajo de los controles
de Creaci?n de audios. Se elimina el di?logo y sus botones; se conserva la
actualizaci?n autom?tica del estado.


## 2026-10-10 - bot_ivr: persistent audio readiness and Play gate

Play is enabled only for a completed manifest matching the current flow/leads,
zero failures, and available WAV files. Reload and polling verify readiness.
Missing files, changed inputs or active jobs block Play. Stop remains disabled;
the isolated call engine is pending. No DB migration or legacy launch.

## 2026-10-10 - bot_ivr: simplify audio console messages

Remove readiness, pending engine, concurrency explanation and successful variable
summary paragraphs. Preserve validation errors, progress cards and Play gating.

## 2026-10-10 - bot_ivr: publication registry

Worker validates new WAV once, atomically publishes it, then records its hash in a local SQLite WAL index. Reuse and Play query the index without opening WAV. Hash deletion removes the index entry first under the hash lock. Tests cover reuse without validation and regeneration after deletion.

## 2026-10-10 - bot_ivr: call origin selector

Add Origen selector before Play with extension 3006 as the initial option.
UI preparation only; no extension discovery, persistence or call launch yet.

## 2026-10-10 - bot_ivr: disable generation for ready lists

Disable Generar on initial render and progress updates when current audio
publication is complete. POST also rejects regeneration of a ready snapshot.
Changed inputs or removed publication records allow generation again.

## 2026-10-10 - bot_ivr: hide Generar when ready

Hide the generation button for ready lists on initial render and polling.
Retain server-side rejection; show it again when readiness is invalidated.

## 2026-10-10 - bot_ivr: hide generation speed when ready

Hide the generation speed label and selector alongside Generar for ready lists.
Restore both when generation is needed again.

## 2026-10-10 - bot_ivr: channels selector

Add Canales dropdown immediately before Origen, with 3 selected by default
and options 1/3/5/10/20/25/50/100. UI only, pending call engine integration.

## 2026-10-10 - bot_ivr: hide TTS preparation for ready lists

Hide the creation heading and TTS provider when ready, alongside generation
controls. Keep call controls visible; restore preparation if readiness changes.

## 2026-10-10 - bot_ivr: hide completed audio progress

Hide audio summary and complete progress section when ready, on load and polling.
Restore them if readiness changes; call controls remain visible.

## 2026-10-10 - bot_ivr: hide call buttons until audio ready

Hide Play and Stop on load and status updates until current audio publication
is complete. Restore both when ready; Stop remains disabled pending call engine.

## 2026-10-10 - core: remove PJSIP sample from carriers

Remove the example endpoint/aor/auth paragraph from the carrier form.
Configuration fields and generation behavior are unchanged.

## 2026-10-10 - core: side by side carrier configuration

Display PJSIP and dialplan editors in two equal columns, stacked on small
screens. Remove dialplan explanation/example and widen both textareas.

## 2026-10-10 - core: carrier editors 30/70

Set PJSIP/Dialplan columns to 30/70; retain mobile stacking.

## 2026-10-10 - core: isolated phones administration

Enable phones.php in v2 gate. Phones use zynervox_core.v2_phones with dedicated
DML grants and access audit. Keep legacy adapter unchanged outside isolation.
Add CSRF and basic validation; blank edit password preserves stored secret.
Account storage only; no Asterisk configuration generation or activation.
Schema included in isolated installation via carriers.sh. Architect scope:
Core and installer integration approved for isolated account persistence.

## 2026-10-10 - core: accept SIP and PJSIP phones

Allow both protocols when saving v2 phones; default new form to SIP. Account storage remains in zynervox_core.v2_phones.

## 2026-10-10 - core: generate isolated SIP/PJSIP annex files

CRUD rebuilds active annexes into sip-zynervoxv2-annexos.conf and
pjsip-zynervoxv2-annexos.conf under configured runtime/modules/asterisk,
with context zynervoxv2-test, atomic per-file rename and mode 0640.
No includes or reloads. Reject config-breaking login/password characters.
Installer deploys Phones and initializes both files. DB and two files are
not a single transaction; generation errors require retry/regeneration.

## 2026-10-10 - core: annex context zynervoxv2

Generate SIP and PJSIP annexes with context=zynervoxv2.

## 2026-10-10 - per-list dialing prefix

Replace fixed Origen selector with editable list prefix and separate CSRF-protected save action. Add migration 006 and installer registration; existing lists default empty. Allow 0-20 digits (leading zeros retained), no campaign inheritance.

## 2026-10-10 - call tracking

Add CLI-only AGI, scoped lead creation and UTC attempt history. Hangup handler closes calls including AMD cuts. Prefix 7306 manual template stored in source; target carrier TEST dialplan will be updated and generated without Asterisk restart.

## 2026-10-10 - CDR and event audit

Add CDR timings/channels/linkedid/carrier/origin and append-only call events. AGI journals events before SQL; cron replays pending records idempotently. Manual route records outbound channel, answer and AMD before finish.


## 2026-10-10 - Prefijos de origen reutilizables

Carriers permite extraer un prefijo del dialplan guardado y nombrarlo en zynervox_core.v2_dial_origins. Origen por lista pasa a dropdown; conserva dial_prefix y valida opciones disponibles con CSRF. Dialplan generado tiene cabecera zynervoxv2 fija una sola vez; editor muestra solamente cuerpo editable. Plantilla respeta lista suministrada, fallback manual 1. Instalador incluye tabla, permisos y parser. Pruebas de parsing, varios carriers, duplicados, conflictos y opciones obsoletas.


## 2026-10-10 - Independent Dialplan page

Added modules/admin/dialplan.php with listing, create/edit/delete, name, active state, code and automatic prefix detection. Sidebar Dialplan directly below Troncales SIP; isolated gate allows only this additional page. Core persists v2_dialplans; existing per-list dial_prefix remains compatible. Carrier UI no longer edits isolated dialplans; migration preserves existing 7306 code/name/state. Generation shares identical helper contexts and refuses conflicts. Installer includes schema, grants, new web files and repeatable migration. Tests cover parser, CRUD, prefix uniqueness, active filtering, context conflicts, page/CSRF and migration preservation.


## 2026-10-10 - Carrier form visibility

Carrier form appears only on new=1, an existing edit_id or a save POST. Default page keeps listing and audit. New link explicitly opens creation.


## 2026-10-10 - Actual carrier protocol

Carrier editor displays detected protocol and listing stops assuming PJSIP. Core derives SIP from peer/friend/user and PJSIP from endpoint/aor/auth, rejects mixed blocks, persists protocol on create/update. Isolated generation keeps both protocols in the existing carrier configuration path to preserve the deployed sip.conf include. Historical TEST metadata corrected without changing account code or reloading PBX.


## 2026-10-10 - Selectable carrier protocol

Carrier form uses SIP/PJSIP dropdown instead of readonly detection. Isolated CRUD persists explicit selection and rejects unknown protocols or account blocks incompatible with the choice before writing SQL. Existing callers without protocol retain detection fallback. No change to runtime file paths or PBX includes.


## 2026-10-10 - Fixed shared call contexts

DialplanOrigins owns the shared AMD, hangup and outbound definitions once at the start of the generated document, before zynervoxv2 routes. Dialplan page displays the template as readonly code; editable field contains only authored route code. Identical historical copies are removed from stored dialplans by migration; conflicting custom copies rejected instead of silently replaced. Existing route/name/state preserved; no new route or bot worker created.


## 2026-10-10 - Hide common template from web

Removed common template heading and code from Dialplan page. Generated Asterisk template unchanged; web shows only authored route field.


## 2026-10-10 - IVR engine and AMD/no-AMD test entrypoints

Added CLI-only ivr_engine_agi.php with published-flow loading, lead variable rendering, prepared hash lookup, bounded node execution and durable IVR events. Common template adds zynervoxv2-bot-ivr and zynervoxv2-bot-ivr-sin-amd. Repository exposes scoped ivrCallContext and accepts IVR audit events. Installer prepares private execution locks and executable AGI. Example route inherits list/lead identifiers; second prefix can use no-AMD entry. Tests exercise canonical hash, playback/hangup, unsupported nodes, scoped identity, audit and duplicate execution. Existing user routes preserved for user editing.

- 2026-10-10: Actualizadas rutas carsa_bot_amd (7306) y carsa_bot (7307) desde configs versionadas: IVR con/sin AMD, identidad de lista/lead heredada y auditoria. Publicadas en zynervox_core, regenerado extensions-zynervoxv2.conf y verificado dialplan cargado. Sin llamadas salientes de prueba.

- 2026-10-10: Dialplan oculta formulario por defecto; muestra al crear (?new=1), editar o corregir guardado. JavaScript tolera ausencia del editor. Desplegado; PHP lint correcto.

- 2026-10-10: Contextos comunes bot IVR activan MixMonitor post-answer en recordings/bot_ivr/<call_id filtrado>.wav y StopMixMonitor antes de retornar. Desplegado y contexto sin AMD verificado. Prueba real pendiente de nombre obligatorio del lead 13119898 para flow 11.

- 2026-10-10: Preparacion dirigida del lead 49 / 13119898, lista 1 flow 11: nombre de prueba Prueba y audio gTTS publicado. Llamada sin AMD 1791656150.4090 completada (IVR_END COMPLETED), grabacion WAV 22444 bytes; se conserva auditoria del intento fallido anterior. Script CLI versionado reproduce preparacion sin duplicar lead.

- 2026-10-10: Implemented/deployed six CARSA graph nodes (amd/menu/capture_stt/bridge/inject_sql/execute) via existing CLI engine; bounded graph/retry/fallback, receive-only recordings, date/raw/spoken variables and captured-response hash TTS. Added migration 009 and table-scoped grants for immutable call/node results; CDR independent. Builder permits embedded bridge phrases and exposes ID/text inputs. Private Vosk/gateway/Groq configuration installed; SQL Server optional, core selected. Validated all branches of sanitized 27-node reference, AGI protocol + durable audit/result persistence, duplicate/scoped result tests with rollback, Spanish dates on actual Python 3.6, real HTTP success/redirect rejection, real Vosk/gateway speech and Groq date extraction, published audio reuse, call-tracking/list-audio regression, PHP/JS/bash syntax. No outgoing phone calls or CRM/SMS sends in this task; existing list 1/flow 11 unchanged.

### 2026-10-10 - Clone CARSA flow into v2 builder
- Imported sanitized 02/carsa3 reference into relational bot_ivr flow tables and published JSON (27 nodes). Verified all node fields and connections; intent order normalized for comparison. Flow 11 unchanged.
- Added guarded CLI importer installer/clone-carsa3-flow.php; requires isolated deployed v2 database, refuses differing existing flow and keeps private SMS key parameterized.
- Validation: remote PHP lint, full relational round-trip, graph validation, publication checksum e7b1fac90f6f8e14aac7b644ab177c94221af2b8bff20f9e073f05c752f0cf1e.

### 2026-10-10 - IVR flow dropdown for campaign lists
- campaign_edit.php creates/edits lists with the public IVR Builder api_flows.php catalog. Labels preserve two-digit codes and names; integer values remain compatible with id_flujo validation (02 submits 2). Empty/loading/error states prevent an invalid assignment.
- Deployed source file directly to production. PHP lint and source/production comparison passed.

### 2026-10-10 - Call recordings for future export
- Migration 010 adds campaign/flow snapshots, flow code/name/version SHA256, IVR completion result and recording path/status/bytes/finalization date to zynervox_bot_call_attempts. Existing call_id, phone, list/lead IDs, channels, prefix/carrier, timestamps, duration/billsec and hangup data remain available.
- WAV files stay under recordings/bot_ivr; FINISH journals include their metadata. Historical backfill reads original IVR events and completed files; missing historical flow names/hashes remain null. Campaign lineage is retained through list ownership.
- Deployed migration and 3 PHP source files; source/production cmp, PHP lint, call-recordings DB tests, call tracking and durable AGI audit regression passed. Both list 26 recordings registered ready (319404 and 532844 bytes). No new live call initiated.

### 2026-10-10 - Persist latest IVR disposition in status
- Migration 011 widens lead/attempt status to VARCHAR(80). Graph emits RESPONDE_F1/F2 at CARSA response stages and final disposition at named hangup nodes; generic hangup nodes retain the preceding state. SQL RESULTADO also writes status atomically.
- Call finish and channel recovery preserve attained IVR status. Older attempts cannot overwrite the newest lead state; recovery remains idempotent. Telephony result remains in dial_status.
- Deployed migration and source PHP files directly. PHP lint, source/production comparison, IVR status transaction tests (stage progression, AGENDADO_VALIDAR, interrupted calls, late events, repeated recovery), graph branches, SQL results, call tracking and recording regression passed. Existing historical statuses were not rewritten; no live call launched.

### 2026-10-10 - Services PBX AMI settings
- Added modules/admin/services/pbx.php and PBX sidebar item under Services, following database page styling. Level-9 authorization and CSRF protect test/save. Fields: host, port (5038 default), username, password; blank password preserves stored secret.
- PbxAmi helper validates AMI fields, authenticates with Events off, logs off, and saves only after a successful test. Atomic private config: deployment.directory/pbx/ami.json, 0640. Installer prepares isolated private directory 2770. No production manager.conf changes or calls.
- Deployed source files directly and prepared /etc/zynervox/zynervoxv2/pbx. PHP lint, source/production comparison, fake AMI success/rejection/Logoff, field injection rejection, password preservation, private persistence/permissions, shell syntax, unauthenticated HTTP redirect and wwwrun write access passed. Real credentials remain unset for administrator configuration.


## 2026-10-10 - List Play/Stop AMI worker

Implemented and deployed list Play/Stop AMI worker, async Local originate, durable per-lead claims, channel replenishment, exclusive list lease, CSRF API and progress polling. Campaign state tokens support 80 characters. Applied schema 012 and isolated DML grants. Verified simulated AMI protocol, worker concurrency/refill/duplicates/Stop/rejection/ambiguous ACK, inherited launcher lease, live AMI Login/Ping (no Originate), PHP lint and call tracking/status/recording/flow regressions. No real contacts called during validation. List 26 contact is ANSWER while campaign 20 selects NEW.


## 2026-10-10 - List statistics

Added list statistics cards (total leads, calling, finished), status bars and latest 30 call errors with phone/call ID, state and existing cause. Reuses authenticated Play/Stop polling; refresh also when idle. No DB columns added. Deployed source files to production. Verified PHP lint/source equality, JS syntax and rollback DB fixtures for retry counting, retained errors, IVR failures, business states and campaign/list isolation.


## 2026-10-10 - Collapsed list panels

Converted list data and client upload panels to native details/summary accordions without open attributes: closed on every page load. Forms unchanged. Source copied to production; verified two collapsed panels and PHP syntax.


## 2026-10-10 - Status eligibility and AGI permission

Confirmed dial candidate SQL already filters lead.status. Made NEW explicit in bulk insert. Worker fixture now selects RESPONDE_F1 with telephony dial_status ANSWER, verifying business eligibility. Latest list26 call had Permission denied executing ivr_engine_agi.php; restored deployed CLI execute permission and persist it in Git/web deploy script. Import/status/worker fixtures and PHP/bash lint passed. No real call originated and no historical disposition rewritten.


## 2026-10-10 - Lead status summary label

Verified listStatistics groups zynervox_bot_list.status, independent of attempt dial_status. Clarified UI heading as Resumen por estado del lead. No data changes. Copied source to production and validated PHP.


## 2026-10-10 - Direct AGI execution regression

Latest list26 attempt zv2-a9be45841c511a9d2fbde794 failed exec with ENOENT because deployed ivr_engine_agi.php shebang contained CRLF. Normalized source and deployed CLI AGIs to LF, retaining 0750. Existing gitattributes already enforces LF. Changed AGI integration fixture to invoke executable directly instead of bypassing shebang via /usr/bin/php. Direct playback/audit/scope/interactive receive/result fixtures pass. No real call originated or historical state rewritten.


## 2026-10-10 - Isolated campaign dashboard

Removed isolated dashboard redirect by rendering read-only IVR campaign monitor in dashboard and existing campaign_monitor.php. Added authenticated polling API and isolated route allowance. Graphic cards show dialing state, current total/calling/finished and list progress. Uses zynervox_core repository rather than VICIdial; no schema or outgoing calls. Production copied from source. PHP/source equality, JS syntax, aggregate rollback fixtures and authenticated/anonymous isolated route tests pass.


## 2026-10-10 - Active campaign status summary

Filtered inactive campaigns from graphic monitor snapshot and added per-campaign lead.status distribution. Deployed source to production. Validated PHP/JS and monitor fixtures including inactive exclusion, empty active campaigns and AGENDADO_VALIDAR preserved independently of dial_status.

## 2026-10-11 - Instalacion soundhound y adaptacion Rocky 9

Preparado soundhound con Apache/PHP-FPM 8.3, MariaDB 10.11, FFmpeg/SoX, gTTS,
Python/PyMySQL/num2words y Asterisk 20.21/PJSIP. Instalador reconoce rutas/grupo
Rocky, configura pool PHP-FPM aislado y usa usuario web real en el diagnostico.
Cron de auditoria parametrizado; primera instalacion crea carpeta del launcher;
PBX y archivos generados conservan grupo/permisos adecuados. Politica SELinux
versionada, contextos persistentes y ACL v2 limitadas. Contrato ampliado por
aprobacion de ARCHITECT_AGENT. Pruebas: plataforma Debian/SUSE/Rocky, sintaxis
shell, login/sesion HTTP, rutas legacy/privadas 403, gate ilegible 500 con
recuperacion, aislamiento BD, PBX AMI, TTS/preview WAV y paginas autenticadas.
Reinstalacion completa final INSTALACION_OK y CHECK_RESULT=OK. Fuente Windows
conservada; instalacion por snapshot local, sin modificar el cambio preexistente
del usuario en ivr_engine_agi.php. Sin datos productivos ni llamadas reales.

## 2026-10-11 — Bot IVR: simplificar campos de campaña

- Solicitud: retirar de campaign_edit.php grupo administrativo, método/nivel de marcación, canales máximos, tiempo/prefijo de llamada, orden de leads, hopper y grabación. Se mantienen Caller ID y Estados a marcar; el grupo restante se titula Leads.
- Alcance aprobado por ARCHITECT_AGENT: presentación de app/web/bot_ivr/campaign_edit.php; sin cambios de contrato, esquema ni servicios. El guardado existente preserva metadatos omitidos.
- Flujo autorizado: fuente local → montaje SMB /mnt/zynervoxv2-fuente → /var/www/html/zynervoxv2 en soundhound. Copia con backup privado, root:apache 0640, restorecon y recarga PHP-FPM; sin ejecutar instalador.
- Verificación: PHP lint local/remoto, renderizado sin los nueve controles y con campos conservados, SHA256 fuente/destino idéntico, login y página Bot IVR autenticados HTTP 200. No se crearon campañas ni se cambiaron datos para probar.

## 2026-10-11 — IVR Builder: Reenviar a anexo o troncal con variables

- Nodo reenviar renombrado a Reenviar; destino Anexo SIP/Troncal SIP, número plantilla y hasta 16 headers X-* o User-to-User. Autocompletado @ reutiliza variables cargadas; valores con {variable} se resuelven durante la llamada.
- Persistencia aditiva en data_json existente, recuperación en flow_load y publicación JSON; compatible con anexo/contexto/prioridad anteriores. Sin migración ni modificación de flujos existentes.
- Motor v2 admite nodo terminal: valida endpoint PJSIP, destino y variables; Dial con predial fijo aplica headers al canal llamado mediante helper AGI. Headers ausentes/inseguros o error de escritura abortan. Valores no se registran en auditoría. Preparación TTS conserva sus controles; runner admite ausencia de textos.
- Alcance y contratos aprobados por ARCHITECT_AGENT. Fuente local → montaje SMB → producción Soundhound; backup privado, permisos/ACL y etiqueta SELinux exacta del nuevo AGI. Include local-first de extensions-forwarding.conf en /etc/asterisk/extensions.conf y dialplan reload. Sin ejecutar instalador.
- Verificación: lint PHP/JS, expansión/faltantes/inyección/compatibilidad y terminal simulado; AGI con protocolo simulado; tablas temporales para round-trip y publicación atómica privada; regresión IVR existente; navegador autenticado creando/configurando nodo solo en memoria, sin guardar ni llamar; contexto predial cargado en Asterisk.
- Límite: sin llamada real ni captura de INVITE. Nuevos destinos requieren endpoint PJSIP configurado. El AGI principal conserva requisitos previos de registro de audio; su cambio preexistente no se incluye en esta tarea.

## 2026-10-11 — Soporte SIP (chan_sip) para Reenviar

- Selector SIP/PJSIP, variables múltiples en headers y conservación de headers al cambiar protocolo. Default PJSIP para nodos anteriores; metadata desconocida se preserva al guardar.
- Runtime valida peer SIP, aplica/valida headers en slots libres, marca con SIP/peer/numero y limpia solo slots propios. Rechaza escapes decodificables; slots llenos abortan antes de Dial. PJSIP conserva predial.
- Core separa configuración de troncales SIP/PJSIP, sin modificar registros ni active. Fuente local desplegada desde SMB en Soundhound con backup, permisos y SELinux Enforcing.
- chan_sip.so compilado con fuente Asterisk20.21 y opciones actuales; carga ABI correcta. /etc/asterisk/sip.conf preparado en local, UDP5061; PJSIP UDP5060 permanece. carrier1 existente cargado. Sin ejecutar instalador.
- Pruebas: normalización/variables/inyección, SIP y PJSIP simulados, slots previos/llenos; persistencia y metadata ajena en tablas temporales, publicación privada; detección de protocolos y regresión IVR; AGI PJSIP; navegador selector SIP + header {nombre}|{numero}, sin guardar flujo. Prueba interna Local real de SIPAddHeader y limpieza, sin llamadas externas; configuración de prueba retirada.
- Commit/push solo archivos de tarea; ivr_engine_agi.php preexistente se conserva fuera del commit. Entrega externa de llamadas/INVITE sigue sin probar.

## 2026-10-11 — OpenVPN Docker Soundhound

Agregados ops/vpn/docker-compose.yml y README. Instalados Docker CE/Compose y
OpenVPN por autorización del usuario, configuración desplegada desde fuente SMB.
Verificados Compose, igualdad fuente/producción, healthcheck, creación/conexión
TLS y ping de cliente temporal, revocación y limpieza. Servicios existentes
activos y SELinux Enforcing. Cambios preexistentes BotIVR/dialer no incluidos.
GUI aún pendiente; endpoint actual LAN, sin validación por Internet.

## 2026-10-11 ? Vpn administrativo desplegado sin push

Nuevo panel app/web/modules/vpn, backend/hooks/Dockerfile en ops/vpn y m?dulo
src/features/vpn. Sidebar agrega Vpn; gate permite solamente index exacto.
CRUD metadatos/certificados, descarga no-store, revocaci?n/desconexi?n, inventario,
?ltima conexi?n/estado y auditor?a con filtros. Tokens y SQLite fueraGit/SMB.
Seis pruebas locales backend: validaci?n, autenticaci?n API, lifecycle/auditor?a,
falloPKI conserva cuenta, respuesta management fragmentada y create concurrente.
LintPHP/node; pruebas producci?n Authadmin/an?nimo/rol8/CSRF/XSS, CRUD/descarga,
conexi?nVPN real de cliente temporal/hooks/ping, revocaci?n inmediata y eliminaci?n
con auditor?a persistente. Prueba inicial detect? cierre prematuro management;
corregido en fuente y redeploy, suite completa pas?. Fixtures revocados/archivados.
Navegador: sidebar, formulario/validaci?n y ausencia de errores. API solo loopback,
healthhealthy, certificados previos id?nticos, fuente/producci?n comparados;
Asterisk/httpd/php-fpm/Tailscale/firewalld activos y SELinux Enforcing.
Cambios ajenos BotIVR/dialer/PbxAmi preservados. Sidebar conserva itempreexistente.
Sin commit/push de esta tarea. ClienteWindows pendiente por decisi?n del usuario.

## 2026-10-11 — Carga ligera de Vpn

Panel inicial sin consulta ni HTML de historial. Ver auditoría abre20 registros
con filtros y páginas más antiguas/recientes. Backend separa inventory/audit,
usa cursores por id y snapshot estable con índices; mantiene auditoría íntegra.
Ocho pruebas backend pasan: incluye ausencia de SELECT audit en inventory,
páginas/filtros/validación de cursor y eventos concurrentes fuera snapshot.
PHP lint; verificación HTTP de vista inicial, límite20 y navegación/filtros.
Cambio local primero, copiado desde SMB y backend reconstruido, sin push.

## 2026-10-11 — Publicación del módulo Vpn autorizada

El usuario autoriza ahora commit y push del panel y archivos reproducibles del
servicio. Incluye código, contratos, pruebas y documentación; imágenes Docker,
PKI, perfiles, inventario/auditoría SQLite y tokens permanecen fuera de Git.
Pruebas backend8, lintPHP y node verificados; despliegue ya validado en soundhound.
Cambios ajenos en BotIVR y Dialer se excluyen.

## 2026-10-11 — Reporterí­a dialer y continuidad del motor

Reporte por campaña y fechas inclusivas en dialer_report.php, enlaces desde
campañas/configuración, KPIs y abandono por nivel con denominador humano.
Registra nivel al reservar; captura CONNECT real y duraciones; tipificación
preserva primera medición wrapup. Ocupación e históricos incompletos muestran N/D.

Pruebas en staging privado con PHP 7.4 y MySQL de mirmidon: tablas temporales
copiadas del esquema, sin alterar tablas persistentes ni originar llamadas.
Pasan conteos, porcentajes, fechas inclusivas/inválidas, campañas aisladas,
CONNECT, nulos, disposiciones, wrapup y rechazo de otro agente. HTTP pasa
renderizado y roles 7/8/9, rechaza agente/anónimo y valida fechas/campaña.

SMB remontado por el usuario y verificado. Runtime separado de fuente confirmado
por hashes. Se conserva el cambio ajeno ivr_engine_agi.php (sin diff de contenido;
Git reporta metadatos pendientes). Checklist persistente añadido. Brechas de
origination/grabación detectadas y escaladas: no declarar completo el motor.

## 2026-10-11 — Motor SIP manual, progresivo y predictivo completado

Originación real por troncal SIP, prefijo saliente explícito, guardas AMI,
MixMonitor posrespuesta, verificación de grabación, AMD/MESSAGE y hangup handler
idempotente. Capacidad y estado se verifican al reservar; MANUAL/PROGRESSIVE
reservan globalmente al agente y usan cola personal. Panel administrativo añade
inicio/parada y heartbeat/error; panel de agente refresca pendientes, conserva
pausas y usa WRAPUP automático hasta tipificar. Reintentos y correcciones de
llamadas anteriores preservan intentos posteriores del lead.

Servicio zynervox-dialer instalado, habilitado y supervisado; worker CLI único
con flock, conciliación de NOANSWER/PBX_LOST y sesiones incluso detenido.
Migraciones 008–011 aplicadas y permisos de la tabla de intervalos concedidos
a la identidad aislada. Ocupación usa tiempo observado, no estimaciones de login.

Pruebas PHP 7.4/MySQL temporal y HTTP privado cubren métricas, filtros,
permisos, CSRF, reserva, capacidad, DNC, reintentos, wrapup, huecos de sesiones
y seis ramas adaptativas más cadencia. Laboratorio autónomo con UAS SIP/RTP
Python estándar, troncal y agente loopback: MANUAL/PROGRESSIVE/ADAPTIVE con
servicio real, HUMAN/MACHINE, CONNECT, voz grabada, tipificación, ocupación,
reserva compartida, detener/drenar, NOANSWER, HANGUP, overflow/MESSAGE y canal
contestado perdido. Prueba de SIGKILL confirma reinicio; segundo worker rechazado.
Fixtures propios eliminados, auditoría y WAV/log preservados en backups.

Despliegue local→SMB→runtime separado, con backup y comparación de archivos.
README/API, herramientas de laboratorio y checklist persistente actualizados;
MODULE_MAP regenerado con repo_agent.py. Se excluye cambio ajeno de bot_ivr.
Por instrucción del usuario, solo SIP; configuración raíz PJSIP original
restaurada. Pendiente de la lista ofrecida para probar troncal externa/MicroSIP.

Corrección de carrera al reiniciar: conservar RuntimeDirectory mantiene el
inode del flock. Pasan reinicio, bloqueo del segundo worker y continuidad del
servicio. Inventario final sin llamadas ni fixtures propios; archivos desplegados
idénticos a fuente. Evidencia SIP final: dialer-sip-lab-20261011-131050.

## 2026-10-11 — Cierre del laboratorio de tres modos y diez agentes

Core/dialer aprobados por ARCHITECT_AGENT. Desbloqueadas cinco páginas exactas
en Apache aislado, conservando allowlist existente. Ficha en vivo con variables
propias; AGI Queue Gosub y closer preservan agente/fecha sin acceso a queue_log.
Buscador administrativo de grabaciones integrado al reporte, filtros y cursor,
reproducción autenticada WAV con Range, no-store y guardas de ruta.

PASS: motor/SQL temporal/HTTP, AMD MACHINE, NOANSWER, hangup, overflow/MESSAGE,
reserva compartida, inicio/parada y conciliación. Tres modos con diez llamadas
simultáneas y diez agentes, treinta HUMAN completadas, treinta grabaciones con
ambas señales, variables antes del hangup, nueve SALE/un RETRY por campaña,
estados e intentos, KPIs y ocupación. Apache real valida páginas, roles y audio;
navegador reproduce WAV de 40.62 s y filtra una grabación por teléfono.

Simulador SIP estándar admite re-INVITE, puertos dinámicos y evidencia atómica;
harness repetible conserva historial y usa prefijo por campaña. Checklist,
contratos, API, README e informe de validación actualizados. Despliegue selectivo
local→SMB→runtime con backups; pruebas guardadas en /var/backups/zynervoxv2.
Sin cambios en clientes reales, PJSIP ni bot_ivr; cambio preexistente excluido.

## 2026-10-11 — Presentación de configuración del marcador

Módulo dialer: dialer_campaign_edit.php organiza los 24 campos en cinco
secciones (conexión, estrategia, ajuste predictivo, contestadores/abandono y
leads/atención). Estado del motor destacado, botones uniformes, etiquetas
asociadas a campos, opciones con nombres legibles conservando valores,
formulario y tablas adaptados a pantallas pequeñas. CSS limitado a esta página;
sin cambios de motor, DB, contratos o estilos compartidos.

Despliegue selectivo con lint PHP 7.4, backup y comparación fuente/runtime.
Revisión de navegador a 1440×1000 y 390×844: cinco secciones, 24 campos,
cero campos sin etiqueta y sin desbordamiento horizontal; formulario válido.
Capturas privadas .codex-temp/campaign-ui-desktop.png y campaign-ui-mobile.png.
Regresión HTTP/SQL mediante run-reporting.sh. No se iniciaron llamadas ni se
modificaron valores de campaña durante la revisión visual.

## 2026-10-11 — Cargar base y eliminación de campañas

Botón dialer_list_leads.php renombrado a Cargar base y mensaje Base cargada;
conserva advertencia visible de reemplazo. campaigns.php explica eliminación
irreversible y exige confirmación+CSRF. deleteCampaign permite historial cerrado,
borra hijos en orden transaccional y rechaza motor RUNNING/llamadas abiertas.
Conserva WAV, datos ajenos y DNC globales; borra DNC propias sin ampliar ámbito.
Limpieza de membresías/dialplan/colas específica; fallo PBX posterior informa
campaña eliminada con limpieza pendiente y se audita.

Pruebas run-deletion.sh con FK reales: borrado completo, guards, rollback SQL,
aislamiento de campaña/DNC, conservación WAV, retiro de ambas colas y error PBX
posterior. BD descartable eliminada. Regresión engine/reporting y HTTP: Cargar
base, permisos y POST sin CSRF/confirmación. Despliegue selectivo con backup/lint
comparación y reinicio de worker. No se borraron campañas existentes del usuario.

Validación desplegada: navegador en at.zynervox.site muestra Cargar base y
confirmaciones completas en las siete campañas existentes. Prueba real con
campaña propia sintética #54 STOPPED e historial cerrado: eliminación completa,
dialplan retirado y reload PBX PASS. Todas las campañas anteriores conservadas;
sesión temporal revocada. Backup dialer-delete-fix-20261011-145604.

## 2026-10-11 — Pestañas de configuración de campaña

Módulo dialer: las cuatro tarjetas de dialer_campaign_edit.php se organizan en
Configuración, Listas, Disposiciones y Agentes asignados. Un panel visible,
contador de elementos, enlaces GET con tab validado y mejora JS sin recarga.
Conserva valores sin guardar al cambiar de pestaña y selección al recargar.
POST selecciona pestaña de la acción, también en errores; datos de la campaña
se consultan separadamente para que un error no oculte todas las tarjetas.
Accesibilidad tab/tabpanel/aria-selected, foco y flechas/Home/End. Diseño móvil
con pestañas en dos columnas. Sin cambios del motor, DB ni contratos.

PASS HTTP temporal: cuatro paneles exclusivos, fallback de tab inválido,
crear lista/disposición conserva pestaña y CSRF inválido mantiene Agentes.
Fixture de listas temporal también para página campaign, evitando escribir
listas operativas en estas pruebas. Navegador real at.zynervox.site a 1440×1000
y 390×844: cuatro pestañas, 24 campos, un panel visible, navegación de teclado,
texto sin guardar intacto durante cambios, recarga mantiene selección y sin
desbordamiento móvil. Backup dialer-campaign-ui-20261011-150939; lint/comparación
fuente-runtime y sesiones propias revocadas. No se enviaron formularios reales.

## 2026-10-11 — Listas al comienzo de las pestañas

Orden actualizado a Listas, Configuración, Disposiciones y Agentes asignados;
selección explícita y comportamiento de formularios conservados. PHP lint,
comparación runtime/fuente y navegador real PASS (orden y panel Listas).
Backup dialer-campaign-ui-20261011-151251; sesión temporal propia revocada.
Consulta operativa verificada: Iniciar motor habilita únicamente la campaña
actual; claimLead reúne leads elegibles de todas sus listas activas y aplica
orden global, horarios, intentos y DNC. PROGRESSIVE/ADAPTIVE/RATIO automáticos;
MANUAL requiere siguiente lead desde el panel del agente.

## 2026-10-11 — Selección de dialplan existente para motor humano

Completada revisión del trabajo pendiente: UI elige salida SIP existente,
callback privado automático, bloqueo de cambio RUNNING/con llamadas abiertas,
conservación de dialplans compartidos y rechazo de snapshot obsoleto al reservar.
API y contratos core/dialer aprobados por ARCHITECT_AGENT. Regresiones remotas
run-routing.sh, run-engine.sh y run-reporting.sh PASS en mirmidon; routing/engine
repetidas tras corrección de concurrencia. Fixtures TEMPORARY y runtime staging,
sin llamadas ni modificación de campañas productivas. Migración 012, copia al
runtime y validación web real pendientes de autorización de despliegue.

## 2026-10-11 — Despliegue y validación de selección de dialplan

Código e968522 desplegado en /srv/www/htdocs/zynervoxv2: Dialer.php,
Dialplans.php, campaigns.php y dialer_campaign_edit.php. Migración 012 aplicada
a zynervox_core; carrier_id y selected_dialplan_id nullable confirmados.
Sintaxis PHP y comparación fuente/runtime PASS; worker reiniciado y activo.
Respaldo: /var/backups/zynervoxv2/dialer-selected-route-20261011-161538.

Navegador real en https://at.zynervox.site/zynervoxv2/: campaña propia #56
DIALER_ROUTE_VALIDATION_20261011_1620 creada sin leads/agentes, prefijo interno
automático, selección 7→1 guardada, inicio MANUAL sin leads, intento 1→6 rechazado
RUNNING conservando 1, parada y cambio a 6 guardado. Selector muestra únicamente
carsa_bot/carsa_bot_amd/carsa_ivr; callbacks internos excluidos. Runtime resuelve
SIP/carrier1/881851000001 para teléfono sintético, sin ejecutar Originate.
Campaña #56 y callback retirados por API pública; rutas compartidas comparadas
antes/después e intactas. Sesión activa de prueba revocada y cookies locales
retiradas. Estado final: worker activo, 0 RUNNING y 0 llamadas abiertas.

No se cargaron leads ni se efectuaron llamadas. Certificación con proveedor SIP
externo/MicroSIP físico y alta de agentes desde web siguen pendientes. Reversión
de PHP desde respaldo debe considerar las campañas que ya usan la nueva ruta;
no eliminar selected_dialplan_id ni forzar carrier_id NOT NULL sin conciliarlas.

## 2026-10-11 — Usuarios web v2 y login de agente

Resuelto users.php 403 por integración legacy: ruta aislada con administrador9,
CSRF y CRUD de agentes nivel1 vía CoreUsers. Login numérico inmutable, hash de
contraseña nueva, campo secreto vacío al editar, contraseña vacía conserva hash;
roles superiores protegidos. Auth revalida active/rol de sesión contra v2 y
regenera ID al login; nivel1 abre dialer_agent.php en aislado.

run-users.sh PASS: validación/duplicados, roles/hash/plaintext, protección admin,
contraseña vacía, login inactivo, rotación sesión; HTTP admin-only, refresco rol,
CSRF, vista directa bloqueada, escape HTML, formularios sin secretos y redirect.
run-reporting.sh PASS tras adaptar solo fixture users TEMPORARY. PHP lint PASS.
Despliegue en mirmidon con respaldo inicial
/var/backups/zynervoxv2/core-users-20261011-163404. Navegador real: Users abre,
usuario temporal 7986101101 creado, login redirige a Panel de agente, edición y
desactivación revocan sesión. Cuenta temporal y sesión administrativa propias
retiradas; credenciales locales temporales eliminadas. No se creó anexo ni se
alteró asignación/campaña55, que permanece carsa0001 STOPPED, 0 llamadas abiertas.
Limitación: desactivar cuenta web no elimina membresía SIP; salir de cola primero.

## 2026-10-11 — Timbrado y early media en MANUAL

MANUAL origina primero el anexo reservado y luego Dial directo al cliente,
sin Queue/AMD. Mensaje del panel pide contestar softphone. answered_at y
MixMonitor b comienzan exclusivamente después de respuesta del cliente;
UID padre preservado, cierre idempotente y reserva global conservados.
Correcciones de revisión ARCHITECT_AGENT: modo solo cambia STOPPED sin
intentos abiertos bajo lock, y cierre concurrente con answer usa fallback.

PHP/Python lint, run-engine.sh, run-routing.sh y run-reporting.sh PASS.
Laboratorio final SIP/RTP real en loopback PASS: MANUAL/progresivo/adaptativo,
ringback RTP y 183/audio recibidos por agente antes de 200, grabación y
tiempos sin early media, AMD HUMAN/MACHINE en modos automáticos, MESSAGE,
ocupado, cliente/agente sin respuesta, cancelación, reserva multicampaña,
tipificación/wrapup, parada/drain y recuperación PBX_LOST.

Despliegue por zynertools, fuente E:/servidores/zynerdesk/proyectos/zynervoxv2,
runtime /srv/www/htdocs/zynervoxv2, callback regenerado por API y worker reiniciado
solo tras verificar cero llamadas abiertas. Respaldo original:
/var/backups/zynervoxv2/dialer-manual-first-20261011-173343.
Respaldo de actualización final:
/var/backups/zynervoxv2/dialer-manual-first-20261011-173801.
Evidencia de audio y peer.log:
/var/backups/zynervoxv2/dialer-sip-lab-20261011-173839.
Verificación final: fuente/runtime iguales, worker active sin errores recientes,
campaña55 carsa0001 MANUAL RUNNING, SIP5000 OK195ms/disponible, intento317
conserva COMPLETED y tipificación01. Cero llamadas abiertas y cero fixtures
propios de campañas/anexos/troncal. No se llamaron números externos reales.
La comprobación del proveedor y MicroSIP físicos corresponde a la siguiente
prueba del usuario. Rollback: revert del commit de esta tarea, despliegue de
esos tres PHP, syncDialplan por API y reinicio worker con cero llamadas abiertas.

## 2026-10-11 — Ingreso automático y controles globales del agente

Panel de agente ingresa por POST+CSRF a todas las campañas activas asignadas.
Se eliminan botones entrar/salir de cola; Pausar/Reanudar es global, con catálogo
administrable por nivel9, historial y duración desde la solicitud. Recargar,
bootstrap y tipificación conservan pausa. Una llamada reservada conserva su
cola personal hasta cierre; los intervalos permanecen ACTIVE mientras conversa.
Estado durable bloquea nuevas reservas durante pausa/logout incluso si AMI aún
muestra disponibilidad. Colgar valida propiedad y UID/canal exactos, solicita
AMI Hangup y deja el cierre al handler PBX. Logout POST+CSRF retira membresías;
fallo parcial conserva intención offline y sesión web para reintento.
Cerrar pestaña/expirar sesión no reemplaza el logout explícito.

Contratos core/dialer revisados por ARCHITECT_AGENT. Migraciones013 y014 añaden
catálogo, estado e historial y permisos limitados a sus tres tablas para runtime.
Migración inicial conserva agentes operativos existentes sin sobrescribir offline.
Pruebas controles/HTTP PASS: multicampaña, persistencia, historial, catálogo admin,
CSRF, escape, aislamiento Hangup, reserva/pausa concurrente y logout parcial.
Regresiones engine/routing/reporting/core-users y PHP lint PASS.
Laboratorio SIP/RTP loopback PASS: MANUAL/PROGRESSIVE/ADAPTIVE, pausa durante
intento reservado, cierre/tipificación conservan pausa, Hangup timbrando y hablando,
AMD HUMAN/MACHINE/MESSAGE automático y ringback/early media manual conservados.
Fixtures propios retirados; no se realizaron llamadas externas a clientes.

Despliegue por zynertools desde E:/servidores/zynerdesk/proyectos/zynervoxv2 a
/srv/www/htdocs/zynervoxv2, con cero llamadas abiertas antes de copiar y reinicio
del worker; sin cambios al dialplan generado en esta tarea.
Respaldo: /var/backups/zynervoxv2/agent-controls-20261011-180141.
Evidencia: /var/backups/zynervoxv2/dialer-sip-lab-20261011-180339.
Verificación final: cinco PHP fuente/runtime idénticos, worker active sin errores
recientes, SIP5000 OK196ms y en colas55, campaña55 carsa0001 MANUAL RUNNING AMD0,
intento317 COMPLETED/tipificación01, catálogo3, usuario5000 logged_in1 sin pausa,
cero llamadas abiertas y cero campañas/estado/pausas de fixtures propios.
Rollback: restaurar cinco PHP del respaldo y reiniciar worker sin llamadas abiertas;
conservar tablas aditivas e historial, sin eliminar datos nuevos de usuarios.

## 2026-10-11 — Presentación del panel de agente

Panel dialer_agent: estilos locales delimitados al panel, controles de 44px,
selector y Pausar alineados, Colgar rojo, estado con texto e indicador de color
(disponible/pausado/tipificación/llamando/conversación), tarjetas de campaña con
modo y estado, mensajes legibles, foco visible y adaptación a pantallas pequeñas.
Se retira logout duplicado del contenido; se conserva el POST+CSRF del sidebar.
No se modifica el motor, los contratos, las tablas ni los menús compartidos.

PHP lint y JavaScript node --check PASS. run-agent-controls.sh PASS con controles,
reporting/variables y HTTP agente/admin; regresión comprueba una sola salida por
rol, bootstrap, CSRF, permisos de catálogo, escape y propiedad de Hangup.
No se realizó revisión visual en navegador en esta tarea.
Despliegue de un PHP por zynertools, fuente/runtime iguales y sin reiniciar servicios.
Worker active y SIP5000 OK195ms. Respaldo para restauración del mismo PHP:
/var/backups/zynervoxv2/agent-ui-20261011-183821/dialer_agent.php.

## 2026-10-11 — Consola de agente inspirada en distribución VICIdial

Panel organizado en dos columnas: controles de llamada a la izquierda e
información del cliente al centro; tipificación y campañas en bloques inferiores.
Marcar usa un único formulario con selector de campañas MANUAL activas asignadas,
conservando POST, campaign_id y CSRF. Pausar/Reanudar y Colgar mantienen acciones
y permisos existentes. Cajas con bordes definidos y fondos visibles: marcar naranja,
pausa ámbar, reanudar/disponible verde, Colgar rojo y cliente/campañas azul claro.
Cliente dispone de campos separados teléfono/nombre/campaña/estado y una caja
vacía visible sin llamada; datos y variables se actualizan con textContent.
Catálogo administrativo al final; se conserva única salida en el sidebar.
Estilos delimitados al panel y adaptación a pantallas pequeñas; sin cambios backend.

PHP lint, node --check, estructura de formularios sin anidamiento y regresiones
run-agent-controls.sh PASS (controles, reporting/variables y HTTP agente/admin,
CSRF, permisos, escape, propiedad Hangup y salida única). Sin revisión visual
mediante navegador. Despliegue de un PHP sin reiniciar servicios, fuente/runtime
idénticos, worker active y SIP5000 OK201ms. Respaldo para restauración:
/var/backups/zynervoxv2/agent-ui-20261011-185119/dialer_agent.php.

## 2026-10-11 — Navegación CRM/WhatsApp exclusiva del agente

Cuentas operativas con nivel menor de7 ya no renderizan el sidebar de supervisión
en dialer_agent. El panel ocupa todo el ancho y ofrece CRM como área actual y
WhatsApp deshabilitada, marcada Próximamente, sin URL de acceso. Logout único
POST+CSRF en el encabezado para agentes. GTR7/supervisor8/admin9 conservan menú.
Revisados entrypoints campaigns/users/reports/filters/monitor/whatsapp/campaign_monitor:
sus guards existentes exigen7/8/9; no se modifica política de acceso ni backend.
README actualizado. No se modifican contratos ni menús compartidos.

PHP lint, JavaScript node --check, estructura de formularios y run-agent-controls.sh
PASS. HTTP verifica agente sin sidebar/links administrativos, CRM actual,
WhatsApp disabled, logout único, admin/supervisor conservan navegación y permisos,
bootstrap, CSRF, catálogo y Hangup. Sin revisión visual en navegador.
Despliegue de un PHP por zynertools, fuente/runtime idénticos, sin reiniciar servicios.
Usuario5000 confirmado nivel1, SIP5000 OK222ms, worker active.
Respaldo para restaurar panel anterior:
/var/backups/zynervoxv2/agent-ui-20261011-190219/dialer_agent.php.

## 2026-10-11 — Tipificación de fallos y cancelaciones MANUAL

Migración015 añade disposition_agent_user como dueño de tipificación capturado
exclusivamente al reservar MANUAL. Pendiente, cliente cerrado, autorización y
bloqueo de nuevas reservas respetan COALESCE(agent_user,disposition_agent_user),
sin perder dueño al cambiar modo de campaña. Fallos no inventan agente conectado,
answer, bridge, grabación, conversación ni duración de wrapup. La pausa manual
se conserva; otras modalidades y métricas conversacionales mantienen su criterio.
Panel muestra intento/resultado y la recarga para mostrar pendientes solo se
pospone cuando se edita la propia disposición. Contrato/decisión aprobados por
ARCHITECT_AGENT; README actualizado, sin cambios core ni dialplan generado.

PHP lint y node --check PASS. Controles/HTTP PASS: fallos visibles y tipificables,
otro agente/código ajeno rechazados, bloqueo de siguientes reservas, dueño tras
cambio de modo, pausa conservada y automática no atribuida por reserva.
Engine/routing/reporting/core-users PASS. Se corrigió una prueba temporal de
rutas que restauraba active pero no su updated_at antes de comparar la fila;
no modifica rutas persistentes. Tanda conjunta alcanzó timeout del transporte;
comprobaciones restantes ejecutadas separadamente y aprobadas.
SIP/RTP real loopback PASS: NOANSWER/BUSY/cancelación/AMI Hangup timbrando ahora
exigen disposición MANUAL; conversación/PROGRESSIVE/ADAPTIVE/AMD/early media,
reservas y grabaciones conservados. Fixtures propios retirados; sin llamadas
externas de prueba. Evidencia: /var/backups/zynervoxv2/dialer-sip-lab-20261011-192938.

Despliegue por zynertools de Dialer.php/panel y migración con cero llamadas abiertas,
respaldo PHP/filas con nombres de columnas, reinicio worker para cargar dueño nuevo.
Recuperación limitada a359/361/362/363, campaign55/reserved5000/closed/no agente/no
código/no dueño, con evidencia Asterisk manual_ready/manual_closed de cada ID.
No se eligieron disposiciones automáticamente. Respaldo:
/var/backups/zynervoxv2/manual-dispositions-20261011-192415.
Verificación final: fuente/runtime iguales, worker active sin errores recientes,
SIP5000 OK210ms en DIALER_WRAPUP, pendiente/cliente363 visibles; cuatro recuperados
con dueño5000, agente/timestamps/talk/wrapup NULL y resultados originales; campaña55
MANUAL RUNNING AMD0, cero llamadas abiertas y cero fixtures propios.
Rollback: revert del commit y restaurar dos PHP/reiniciar worker sin llamadas;
conservar columna/historial aditivos y tipificaciones nuevas, sin restauración
masiva de filas que pueda sobrescribir decisiones posteriores del usuario.

## 2026-10-11 — Autodial MANUAL después de tipificar, por campaña seleccionada

Implementado/desplegado con autorización "procede" y revisión ARCHITECT_AGENT.
Migración016 opt-in OFF y estado seleccionado/token/due por agente. Configurar
campaña muestra opción; panel persiste selector con POST+CSRF, indicador y cuenta
atrás. Primera llamada manual; primera tipificación de origen MANUAL programa
siguiente a3s. Worker revalida selección/asignación/sesión/pausa/cupos y consume
solicitud al reservar lead. Duplicados/correcciones nunca rearman. Cambiar selección,
pausar/salir, STOP/deactivar/deshabilitar/cambiar modo/retirar asignación cancela.
Sin lead/anexo/capacidad y solicitudes atrasadas >30s se cancelan, sin llamada
latente. MANUAL mantiene anexo primero, early media y bypass AMD. Otros modos
conservan lógica. Datos y cambios de bot_ivr fuera de alcance.

Validación: PHP lint; pruebas aisladas manual-autodial, engine, routing, deletion,
agent-controls unit+HTTP (selector, CSRF y asignación); revisión final sin bloqueo.
Fixtures de tablas temporales/esquema desechable, PBX falso y runtime de prueba.
SIP/RTP loopback real: worker continuó386→387 tras disposición, agente79996,
AMD NULL, grabación77484bytes. MANUAL/PROGRESSIVE/ADAPTIVE, ringback, early media,
noanswer/busy/hangup, Colgar y MACHINE/MESSAGE también pasaron; fixtures retiradas.
Evidencia: /var/backups/zynervoxv2/dialer-sip-lab-20261011-200021.

Despliegue: respaldo PHP/config con complete-insert en
/var/backups/zynervoxv2/manual-autodial-20261011-145147; columnas aditivas y tres PHP
copiados local-first desde fuente SMB, propietario/permisos preservados; reinicio
worker tras confirmar cero llamadas/canales. Verificación final fuente=runtime,
worker active sin errores, SIP5000 OK200ms, cero llamadas/tokens/fixtures; campaña55
MANUAL RUNNING manual_autodial_enabled0 AMD0. Autodial real se habilita en Configurar.
Rollback: revertir PHP y reiniciar worker sin llamadas; mantener columnas aditivas,
limpiar tokens antes de revertir; no restaurar masivamente decisiones nuevas.

Nueva petición pendiente de "procede" según instrucción del usuario: reporte por
lista cargada y reconciliación de cifras. Análisis de carsa0001: lista50 "00001",
1000leads,10intentos,2contestadas/completadas,7tipificadas y3sin código. Contactos
actuales2 usan COMPLETED+is_contact; total tipificaciones no aparece. Propuesta:
selector Todas/lista aplicado a totales/desgloses, tipificadas totales separadas
de contactos, resultados PBX y disposiciones. Sin cambios al reporte aún.

## 2026-10-11 — Reporte filtrado por lista y conciliación de llamadas

Usuario autorizó continuar con "procede"; ARCHITECT_AGENT aprobó alcance/contrato
y revisión final sin bloqueadores. Selector Todas/lista cargada, incluidas
inactivas, validación campaña y escape de etiquetas. KPIs, porcentajes, AHT,
abandono por nivel y resultados/disposiciones comparten el mismo filtro EXISTS.
Snapshot REPEATABLE READ mantiene consistencia entre bloques con llamadas en curso.
Nuevos indicadores: números únicos, cerradas, total tipificado y cerradas sin
código. Tablas PBX y disposición con totales; las ecuaciones permiten conciliar.
Contactos/ventas conservan COMPLETED+catálogo actual. Ocupación por lista N/D,
sin atribuir tiempos de sesión de campaña a una lista. Sin cambio de esquema,
importación, FK, reservas, autodial ni datos de llamadas. FK al lead RESTRICT
confirmada en runtime y cero huérfanos en campaña55.

Pruebas: lint dos PHP; report-lists sobre TEMPORARY tables (varias listas,
inactivas/vacías/ajenas, intentos repetidos, fechas, porcentajes y conciliación),
run-reporting HTTP (selector, roles7/8/9, agentes/anónimos rechazados, IDs inválidos,
etiquetas escapadas, fecha, paneles/tabs/CSRF y búsqueda/rangos de grabaciones),
run-engine (MANUAL/PROGRESSIVE/ADAPTIVE y métricas anteriores). Todas pasaron;
servidor HTTP privado y fixture WAV retirados. Sin browser ni llamadas de prueba.

Despliegue zynertools local-first de Dialer.php y dialer_report.php; propietario
y permisos preservados; source=runtime. Respaldo PHP:
/var/backups/zynervoxv2/report-lists-20261011-151901.
Worker siguió active sin reinicio. Verificación live del reporte55/list50:
14intentos=14cerradas+0abiertas; 14cerradas=11tipificadas+3sin código;7números únicos,
2contestadas/contactos. Resultados HANGUP11+COMPLETED2+CHANUNAVAIL1=14;
disposiciones NoContacto9+contacto2=11. Nuevos intentos del usuario durante trabajo
explican aumento frente al análisis previo10/7; no se alteró historia.
Campaña55 MANUAL RUNNING autodial1 AMD0 worker_errorNULL (flag activado por usuario).
Rama main; base8312990. Rollback seguro: git revert del commit de este cambio,
volver a copiar los dos PHP; sin restaurar BD ni interrumpir llamadas.


## 2026-10-12 - Bot IVR: desactivar y pestañas por estado

Usuario autorizó con procede. index.php añade Desactivar junto a Modificar, confirmación, POST admin/CSRF, validación de ID y pestañas Campañas activas/inactivas. deactivateCampaign agrega operación pública con active=0, transacción propia y rechazo de marcación/llamadas abiertas; conserva listas, leads, metadatos, auditoría y archivos. ARCHITECT_AGENT aprobó cruce bot_ivr/zynervox_queries. Sin migración ni reinicio de servicios.

Pruebas aprobadas: lint de 3PHP; campaign-tabs.php (filtros, selección, acciones, escaping, confirmación, CSRF, IDs, redirect); campaign-deactivate-db.php (tablas TEMP, estados starting/running/stopping, llamadas abiertas, conservación, aislamiento, idempotencia, rechazo de nueva marcación, reactivación y transacción externa); campaigns-db.php (rollback). La prueba fuente inicial cargó configuración legacy y falló; la regresión externa revirtió mediante finally. Se corrigió el runner para exigir configuración aislada de runtime y usar fixtures TEMP; no se cambiaron credenciales/permisos ni configuración. No se verificó apariencia en navegador ni se originaron llamadas.

Desplegados 3PHP por zynertools desde fuente local/montada a /srv/www/htdocs/zynervoxv2, con permisos conservados y cmp. Backup /var/backups/zynervoxv2/bot-campaign-deactivate-20261012-071548. Todas las pruebas repetidas contra runtime pasaron; zynervox-dialer.service activo. Git baseline main 311f3a7; ivr_engine_agi.php preexistente permanece ajeno al cambio.


## 2026-10-12 - Bot IVR: botón de emergencia Detener audios

Usuario autorizó procede. list_edit.php añade botón rojo durante generación; POST a list_audio_status.php verifica admin, CSRF, pertenencia y token. list_audio_jobs.php expone job_id/lease activo y ejecuta helper privado. Worker añade barrera de lanzamiento, control lock independiente y cancelación síncrona del árbol propio mediante cmdline/UID/starttime; no acepta PID del browser ni señales amplias. Conserva caché publicada, limpia input/WAV parciales propios y publica stopped solo tras cierre/lease libre. ARCHITECT_AGENT aprobó alternativa y contrato, sin otros módulos ni SQL.

Pruebas: 6 de caché; 3 integraciones con árboles reales simulados y HTTP PHP (gTTS/conversión, descendientes, otra lista, caché compartida, reanudación/token/PID obsoletos, descongelado tras fallo, cancelación al lanzar, permisos, CSRF, scope, body/JSON inválido y no exposición de errores PDO). Integraciones repetidas con wwwrun y venv Python3.6.15 en staging privado porque el montaje fuente no admite lectura de wwwrun; no se cambiaron permisos del montaje. PHP lint, validación JavaScript con node --check, list-audio.php, diff --check y comparación fuente/runtime aprobadas. Apariencia en navegador no revisada. No TTS remoto, audios productivos nuevos ni llamadas desde estas pruebas.

Desplegados 3PHP y worker en /srv/www/htdocs/zynervoxv2/bot_ivr y /var/lib/zynervoxv2/asterisk/modules/bot_ivr, con backup /var/backups/zynervoxv2/list-audio-stop-20261012-074948. Comprobación previa sin generadores activos; sin reinicios ni migración. Lectura lista56 tras despliegue: generación inactiva, 917 audios reutilizados y 1847 fallidos en su última ejecución; no se modificaron esos registros. zynervox-dialer.service activo. Base Git main 8830424; cambio preexistente ivr_engine_agi.php excluido. Reversión: git revert del commit y redespliegue de esos cuatro archivos sin generadores activos.


## 2026-10-12 - Botón Agregar junto a Proveedor TTS

Usuario autorizó procede para añadir exclusivamente el botón. list_edit.php coloca Agregar a la derecha del selector mediante flex, type=button para no enviar el formulario. Sin manejador, formulario adicional, SQL ni cambios contractuales. Desplegado en mirmidon con backup /var/backups/zynervoxv2/tts-provider-button-20261012-075528, permisos conservados y fuente/runtime idénticos. PHP lint y diff --check aprobados; no revisión visual en navegador. Base main e6fec6a; cambio preexistente ivr_engine_agi.php excluido.


## 2026-10-12 - Agregar proveedores TTS WebSocket

Botón Agregar en list_edit ahora abre formulario nombre/URL y guarda en catálogo BD para otras listas. Generación admite gTTS y proveedores WS registrados, selección separa caché y se captura por ejecución para reproducción estática/dinámica. Migración013 y SELECT/INSERT tabla propia aplicados en zynervox_core; websockets9.1 instalado con permisos wwwrun en venv aislado. Contratos revisados por ARCHITECT_AGENT.

Desplegados 10 PHP y 2 workers sin llamadas/generadores activos ni reinicio Asterisk. Backup /var/backups/zynervoxv2/tts-providers-20261012-101829. PHP lint/cmp, 12 pruebas Python WS/caché/stop, perfiles PHP/Python, payload y motores lineal/gráfico pasan; catálogo SQL probado con rollback antes/después de despliegue. Audio real desde wwwrun: 73890 bytes, PCM16 mono8000, 4.615375s. No se procesó lista completa ni se lanzó llamada; sin comprobación visual en navegador. Baseline 4f85360; rollback revert del commit de esta entrega y restauración de sus 12 archivos con motores inactivos. Conservar tabla aditiva y dependencia al revertir, para preservar datos registrados.

Se excluyen cambios preexistentes/ajenos: ivr_engine_agi.php, modules/admin/sidebar.php y modules/admin/services/audio_create.php. No modificar ni incluir en commit.

### Validación final del formulario TTS (2026-10-12)

tts-provider-form.php renderiza y envía la página real con colaboradores privados: valida modal, escape de nombres, registro, selección tras redirect y rechazo de CSRF/URL/nombre/pertenencia inválidos. Pasó sin SQL ni llamadas. Se comprobó UTF8 del nombre antes de guardar y se actualizó únicamente list_edit.php con lint/cmp. Sin validación visual en navegador. IsolatedGate.php también contiene cambios ajenos concurrentes y queda excluido de esta entrega.

## 2026-10-12 - Importación puntual de caché CARSA para lista 56

Usuario autorizó copiar y registrar audios existentes omitiendo la validación WAV. Operación aprobada por ARCHITECT_AGENT, limitada a campaña 50/lista 56/TTS_26 (ws:5, ws://172.16.10.26:8900). Prompts resueltos por APIs públicas del repositorio/flujo publicado, snapshot verificado contra catálogo y manifiesto bajo control/lease de lista. Sin generación, llamadas ni cambios de código/contrato.

Origen /var/lib/asterisk/sounds/voicebot/cache/ivr_builder/macelioai (resuelve a /usr/share/asterisk/sounds/voicebot/cache/ivr_builder/macelioai); destino /var/lib/zynervoxv2/asterisk/sounds/cache/ivr_builder/gtts. Traducción SHA256 legacy macelioai|es|1.3|texto al hash de perfil WS vigente. Copia atómica bajo lock por hash, root:www 0640, INSERT SQLite después de copia; fuentes y destinos publicados conservados. Sin comprobación de formato/corrupción WAV conforme instrucción; runtime conserva validación existente.

39000 prompts, 2764 hashes únicos: 837 copiados y registros añadidos (172920754 bytes), 275 ya publicados, 1651 sin fuente coincidente y 1 destino sin registro preservado. Resultado: 1112 requeridos publicados, 1652 pendientes. Verificación de existencia/registro e identidad posterior, sin escucha ni certificación de contenido. Auditoría con hashes sin textos en /var/backups/zynervoxv2/carsa-cache-import-list56-20261012-183130-4b4976/report.json y copied.jsonl. La lista permanece detenida; relanzar con TTS_26 reutiliza los importados que pasen la validación habitual. Revertir documentación no deshace la importación; para revertir datos usar el journal y eliminar solo archivos/registros añadidos, con generadores detenidos.

## 2026-10-12 - Buscar audios macelioai antes de generar

Baseline 61056e7. Usuario autorizó recuperación automática temporal; ARCHITECT_AGENT aprobó alcance/contrato bot_ivr. ensure_audio consulta fuente legacy solo para perfiles equivalentes autorizados en configuración privada opcional legacy_macelioai.json, calcula hash antiguo con texto normalizado, valida fuente regular/no symlink/WAV completo y publica copia bajo hash actual/SQLite como reused. Ausente/corrupto continúa generación habitual. Preparación por lista y IO dinámico cubiertos sin cambiar llamadas, firmas, hashes, esquema ni contadores.

Desplegados worker y configuración ws:5/TTS_26 en Mirmidón tras comprobar ausencia de generadores/runs/llamadas aisladas. Backup /var/backups/zynervoxv2/legacy-macelioai-20261012-134305. 14 pruebas de recuperación/caché/WebSocket pasan bajo wwwrun/Python3.6. Verificación real privada con prompt de lista56 copió217152bytes idénticos al origen, publicó hash nuevo y reutilizó segunda consulta, con generador prohibido y 0 peticiones remotas. Sin generación de lista, llamadas ni reinicio Asterisk; servicio dialer activo. Configuración opcional documentada con ejemplo, fuentes conservadas. Rollback: revertir commit de esta entrega, restaurar worker respaldado y retirar/restaurar configuración, con motores detenidos; copias publicadas siguen disponibles.

## 2026-10-12 - Selector de generación: conservar valor y escalas cada cinco

Baseline a6305a6. Se eliminó25x seleccionado fijo: último workers del job es fallback y localStorage guarda borrador por cuenta/campaña/lista, restaurado al recargar/cambiar proveedor. Job activo muestra workers real y bloquea selección. Opciones1,3y5..100 cada5 coinciden en UI/validaciónPHP/workerPython. Estado público/snapshotstarting añadenworkers; sin SQL/hashes/firmas ni alteración de ejecución activa. Contrato aprobado por ARCHITECT_AGENT.

Desplegados list_edit.php, list_audio_jobs.php y list_audio_worker.py sin generadores/runs/llamadas aisladas activos, con cmp/lint y backup /var/backups/zynervoxv2/audio-worker-scales-20261012-145409. PHPpayload y formulario real privado pasan en Linux antes/después de deploy; 4 tests WS/read_job pasan como wwwrun/Python3.6. NodeVM sobre HTML generado real verifica cambio/guardado/reload/proveedor, scope, valor obsoleto, storage bloqueado y job activo; no verificación visual en navegador. Intento local PHP Windows no aplicable por /dev/null y mbstring ausente; comprobaciones oficiales en Linux correctas. Sin llamadas, audios reales nuevos ni reinicios; dialer activo. Rollback: revert del commit y restaurar tres archivos respaldados con motores detenidos; preferencias locales con valores no soportados se ignoran.

## 2026-10-13 - Última grabación por lead desde cada lista

Baseline 0c5df33. Bot IVR añade enlace Escuchar últimas llamadas y página paginada de 50 contactos con su último intento, tipificación y reproductor privado. latestLeadCalls se incorpora al contrato compartido y a CallQueries: scope campaña/lista/lead, una fila por contacto, sin historial ni fallback cuando el último intento no tiene audio. Endpoint comprueba que attempt_id siga siendo el último; admin nivel 9, no-store, HEAD y rangos. Resolver privado admite archivos históricos sin guiones generados por FILTER, excluye symlinks y rutas fuera del directorio. Auditoría futura reutiliza el resolver; no reescribe intentos históricos. Revisión ARCHITECT_AGENT y contratos documentados; sin esquema, llamadas ni reinicios.

Validación: sintaxis PHP de siete archivos, fixture SQLite de último intento/lead sin llamada/paginación/aislamiento y lectura MariaDB real como wwwrun. Página 57 muestra exactamente un lead y el WAV de su última llamada (68.844 bytes, 4,3 segundos). agent-browser confirmó navegación desde list_edit, reproducción real, página 2 de lista 56 con 50 filas sin audio, HEAD 200, rangos 206/RIFF, rango inválido 416 e intentos antiguos/otra lista 404. Rol 8 y anónimo no reciben audio. Sesión de QA temporal eliminada y navegador cerrado. Despliegue local-first desde SMB; respaldo /var/backups/zynervoxv2/lead-recordings-20261013-172534. Rollback: revert del commit, restaurar cuatro archivos existentes respaldados y retirar los tres archivos nuevos (call_recordings.php, list_recording.php, list_recordings.php). No se lanzaron llamadas ni se generó audio de clientes durante la validación.

## 2026-10-13 - Ocultar NEW en últimas grabaciones

Baseline 6f68566. list_recordings activa managedOnly en latestLeadCalls y leadCount; ambas consultas filtran status distinto de NEW antes de paginación/conteo. Interfaz indica leads con gestión y adapta el estado vacío. Parámetro opcional false conserva consumidores, selección del último intento y endpoint privado de audio. Revisión ARCHITECT_AGENT y contratos registrados. Sin cambios de estados/esquema ni llamadas.

Validación: fixture SQLite verifica exclusión de NEW aunque tenga historia, total filtrado, paginación, último intento y compatibilidad de lecturas sin filtro. Lint local/producción de cuatro archivos; MariaDB y render PHP real como wwwrun muestran 96 gestionados de 3000, 50 filas en primera página y ningún NEW. Sesiones temporales de render eliminadas. Despliegue desde SMB, respaldo /var/backups/zynervoxv2/managed-recordings-20261013-183827. Rollback: revert y restaurar los cuatro archivos respaldados; no modificar la base ni las grabaciones.


## 2026-10-13 - Filtro de grabaciones por tipificacion

list_recordings agrega selector de tipificacion con conteos de estados actuales excepto NEW. Filtra por lead.status antes de paginar, conserva seleccion al actualizar/navegar y restablece pagina al aplicar. Mantiene una ultima llamada por lead y Sin audio. Consultas preparadas; firma previa compatible.


## 2026-10-13 - Retirar tabla de intentos con error

list_edit.php elimina el bloque Ultimos 30 intentos con error y su renderizado JavaScript/estilos exclusivos. Conserva contadores, resumen de estados y avisos de fallo al actualizar estadisticas. No modifica datos ni contrato de listStatistics.


## 2026-10-13 - Canales persistentes por lista

Preparado guardado de canales por lista en BD, API save_channels y carga del selector desde call_channels. Bloqueo durante motor activo y guardado pendiente; revierte seleccion visual ante error, valida coincidencia de canales al iniciar. Migracion 014 pendiente de aplicar en produccion. No se originaron llamadas.


## 2026-10-13 - Canales por lista desplegados en mirmidon

Autorizacion procede recibida. Migracion 014 aplicada a zynervox_core con motores detenidos; archivos copiados desde SMB. Backup /var/backups/zynervoxv2/list-call-channels-20261013-142513 (archivos y tabla de listas). Lista 56 inicializada en 25 canales. Verificacion HTTP con sesion temporal: guardar 10, recargar API/pagina y seleccion 10; invalidos 400, CSRF 403; restaurar 25 y recargar. Prueba MariaDB en transaccion confirma bloqueo de guardado con run activo y rollback sin retener run. Hashes locales/productivos coinciden. Sin llamadas originadas ni cambios de campaign.max_channels; sesion QA destruida.


## 2026-10-13 - Live dialing channels

Prepared locally: live channel changes, persisted per list; active claim checks authoritative locked setting; UI preserves selected value during save; active monitor reports current limit. Production deployment pending explicit procede for this action.

Validation: local SQLite repository suite verifies live increases/decreases, claim limit, preserved active attempts, scope/rollback/options/stale Play and historical snapshot. Four PHP lints, inline JavaScript syntax and selector pending-save regression passed. Long-lived PHP workers load code at startup: live behavior requires a run started after deployment. No production changes or calls made.


## 2026-10-13 - Live channels deployed on mirmidon

User authorized procede. Deployed four PHP files from SMB source, linted temporary copies and verified source/production equality; backup /var/backups/zynervoxv2/live-channels-20261013-143918. No migration or service restart. Native MariaDB transaction fixture with real production worker and mocked AMI passed 3 -> 5 -> 1 limits, refill/drain, completion and existing worker regressions; rollback removed fixture data. HTTPS authenticated API save and rendered selector verified, QA session destroyed. List 56 retained its current value 20; lists 57/58 retained 3. Zero active runs before/after; no actual calls originated. Added live-limit worker regression to existing test.


## 2026-10-13 - SQL Server destination prepared

User requested IVR SQL destination 172.16.10.19:1433, database BD_ANALISIS, table dbo.GESTIONES_BOT. Prepared private local configuration under app/web/.codex-temp/sqlserver (Git ignored), retaining existing IVR provider settings and switching result_destination to sqlserver. Credentials excluded from Git and logs. TCP connection verified. V2 Python 3.6.15 lacks pymssql; PyPI publishes compatible 2.2.11 CPython 3.6 Linux wheels. Remote plan: back up private configuration, install pymssql 2.2.11 in v2 gtts_env, validate supplied login/table/INSERT permission and atomically copy private configuration from SMB into /etc/zynervox/zynervoxv2/ivr_engine.json root:www 0640. Activation awaits explicit procede for SQL action. No remote writes or SQL inserts performed; no retroactive export.


## 2026-10-13 - SQL Server IVR destination activated

User authorized procede. Installed binary pymssql 2.2.11 in v2 Python 3.6 gtts_env. Validated supplied login to BD_ANALISIS, dbo.GESTIONES_BOT existence, INSERT permission and all 16 flow columns. Synthetic real INSERT and SELECT passed within a transaction, then rollback confirmed zero retained test rows. Backed up private IVR settings at /var/backups/zynervoxv2/sqlserver-20261013-152012; copied prepared configuration from SMB and published atomically to /etc/zynervox/zynervoxv2/ivr_engine.json root:www 0640. PHP runtime confirms sqlserver destination enabled; wwwrun Python login and INSERT permission confirmed. No active dial runs at validation, so live-call export remains to be observed on next call reaching inject_sql. Existing IVR provider configuration preserved. Removed local private staging file. Credentials never committed. No historical result export performed.


## 2026-10-13 - Scheduled lead statuses highlighted

Prepared local bot_ivr/list_edit.php visual change: AGENDADO_VALIDAR and AGENDADO rows in lead status summary receive soft green background, green left marker and green progress bar. Shared statistics rendering applies highlight both initially and on polling. No data, status or dialing behavior changes. Production deployment pending explicit procede for this action.


## 2026-10-13 - Scheduled status highlight deployed

User authorized procede. Deployed bot_ivr/list_edit.php from SMB, preserving owner/mode and validating PHP syntax and source/production equality. Backup /var/backups/zynervoxv2/green-statuses-20261013-153631. Authenticated production HTTPS page returned 200 and includes green row background/progress styling plus exact AGENDADO_VALIDAR/AGENDADO status condition in shared initial/polling renderer. Temporary QA session destroyed. No dialing or SQL configuration changes.


## 2026-10-13 - Additional dialing channel choices

Prepared locally: add 30,35,40 call channels to selector, Play API, persistent list save and run creation. Existing test exercises each new value at creation and during active runs, with rollback. Remote deployment pending explicit procede for this action.


## 2026-10-13 - Additional channel choices deployed

User authorized procede. Deployed four PHP files from SMB with owner/mode preserved, PHP lint and source/production equality. Backup /var/backups/zynervoxv2/channel-options-20261013-154300. Production MariaDB transaction fixture passed save/create/live changes for 30,35,40; all fixture data rolled back without workers or calls. Authenticated HTTPS page returned 200 with all three options and current selection 25 retained. QA session destroyed. No schema changes or service restarts.


## 2026-10-13 - Preanswer implementation prepared

Added origin-7307 predial MixMonitor capture and core-only watcher. Public repository operations and PREANSWER events implement classification/late-closure reconciliation while preserving SIP causes and latest-lead protection. Actual ANSWER rejects late provider results and corrects late preanswer status; guarded AMI hangup additionally checks live channel state. Supervisor bounds provider concurrency to 12 and uses per-call leases, six-second windows, private temporary WAVs and durable capture journals. Full call recording includes early media and is reused after answer. New systemd service is isolated from legacy watcher; no schema changes. Unit coverage: growing WAV headers, bounded windows and categories. Core origin parsing regression passed after normalizing template line endings. Native DB and controlled PBX verification pending deployment.


## 2026-10-13 - Preanswer deployed and verified on mirmidon

Authorized procede. Backed up code and original runtime dialplan at /var/backups/zynervoxv2/preanswer-20261013-162032. Deployed PHP from SMB, installed/enabled zynervoxv2-preanswer.service, updated only common runtime helpers and reloaded dialplan. No schema migration or Asterisk restart. Native MariaDB core-only test passed categories, original SIP result retention, dedup, answered/DIALSTATUS guards, late closed results, newest-lead protection, wrong prefix, recovery and pending selection; fixtures rolled back. Existing call-worker and dial-origin tests passed.

Controlled SIP/RTP localhost peer sent SIP 183 with synthetic speech: Vosk recognized el numero marcado no existe from 32768 PCM bytes before answer; guarded AMI hangup succeeded, call closed PREANSWER_NUMERO_NO_EXISTE with answered_at NULL and original NOANSWER retained. Second peer sent SIP 200; call finished ANSWER with no PREANSWER_RESULT. Initial executable check exposed CRLF in the new AGI shebang; corrected source to LF and reran successfully. No calls to carriers/customers and no SQL Server rows inserted by tests. QA campaign/list/events and runtime recordings removed; synthetic recordings/logs archived in backup. Temporary QA dialplan contexts removed. Final source/production files, service and clean runtime dialplan matched; service active, no repeated crashes and zero channels after verification. Real carrier locutions remain to be observed on subsequent user calls.

Final HTTP guard verification found that a shebang before strict_types causes HTTP 500 under mod_php. Final entry points now start with PHP and the common helper invokes the AGI explicitly through /usr/bin/php; both CLI URLs return 404. Repeated controlled SIP 183 test with final invocation again recognized el numero marcado no existe, applied PREANSWER_NUMERO_NO_EXISTE before answer and confirmed guarded hangup. Follow-up QA data/routes/files removed and synthetic evidence archived. Production list API returned 200 with correct total.

Bounded window review: queued captures now start at the first announcement and advance using the stored byte cursor with two-second overlap, so high concurrency cannot discard the opening phrase. Transient provider errors retain the cursor and retry within the existing 180-second pending window; closed calls finalize only after available audio has been consumed. Growing/queued WAV regression passed.

## 2026-10-14 - Migrate 7307 origin to Baresip (authorized procede)

Added a root-owned fixed-command broker, isolated PHP transport and one-use SIP context binder. Enabled 7307 in mirmidon via private configuration; other prefixes retain AMI Local. Broker atomically coordinates registered 4002..4050 annexes with existing 3006 reservations through an external-owner sentinel, without legacy grants to PHP or legacy worker edits. Handles pool exhaustion before lead claims, Stop races, guarded release, uncertain SSH quarantine and periodic recovery. New predial/answer-split common contexts and one inbound test_cortar route preserve carrier1/881851 and independent callee IVR tracking. Fixed START publishing __ZV2_LEAD_ID so outbound legs keep scoped identity. Installer applies explicit ownership/modes, scoped sudo and recovery timer; docs include rollback.

Verified eight isolated broker scenarios, generated dialplan/roundtrip and origin catalog, preanswer unit/DB cases, worker concurrency/live channels/Stop/exhaustion/race, durable AGI audit/inherited identity, and actual CLI IVR playback/interactive regression. SQL fixtures rolled back or cleaned. First QA start was safely blocked by uploaded configuration permissions before a SIP attempt; corrected root:www0640. Two external calls to one controlled contact in QA campaign60/list68: first confirmed 183/early-media capture and exposed the inherited-lead defect; second closed PREANSWER_CASILLA, NOANSWER, answered_at NULL, hangup_ok true, WAV136044 bytes. Campaign deactivated; annex returned free/NULL, registered OK. No bulk campaign launched. Read-only Play preflight on production list56 identified missing nombre for lead3150; retained the original data for correction, no fabricated customer information. Native 40-call load and other carrier announcement categories remain untested.

## 2026-10-14 - Remove accidental test contact from list56

User explicitly requested deletion of test lead3150. Verified its one finished manual test call1791992778.8130, empty customer_name/extra_json, exact list56/campaign50 scope and no active run. Backed up the row privately to /var/backups/zynervoxv2/lead-3150-cleanup/lead-3150.json; deleted only that lead transactionally. Original call attempt/events remain unchanged. List returns to3000 contacts. Actual configuration validation as wwwrun now passes with original ready audio signature and origin7307: no regeneration or page code change required. Generate was disabled because unresolved nombre prevents constructing audio text; Play was hidden because that made readiness false.


## 2026-10-14 - Campaign Play inside dashboard donut

Moved completion percentage/Terminados below each donut. Administrator-only center Play starts Bot IVR active lists with saved channels, skips busy lists and reports per-list validation errors; agent campaigns reuse engine start. Running/stopping/pending buttons disabled; accessible labels and keyboard focus retained. Existing monitor endpoint accepts POST with admin session, CSRF, strict origin/action/ID; GET unchanged. Isolated fixture tests passed for auth, CSRF, inputs, read-only GET, stored list scope/channels, busy/inactive skipping, partial errors and agent routing; PHP syntax checks passed. Tests never start real workers or calls.

Deployment: updated only the shared monitor view and existing admin monitor endpoint on mirmidon after confirming both matched repository HEAD. Private SFTP backups retained; restored root:www 0644. Remote PHP lint, source SHA256 equality and unauthenticated POST rejection passed. Browser fixture verified caption outside donut, Play click feedback and disabled running state. No production campaign or call was launched for validation.


## 2026-10-14 - Replace running dashboard Play with Stop

Donut center now shows a red square Stop during starting/running, disabled while stopping. Stops new calls through existing list stop service or agent engine STOPPED, allowing live calls to finish. Tests passed for both controls including authorization/CSRF, exact run IDs, active runs of inactive lists, completed-run exclusion, partial errors and idempotent empty stop.


## 2026-10-14 - Centered animated dashboard controls

Replaced font-dependent Play/Stop glyphs with fixed-viewBox SVG icons centered in flex buttons. Added hover/press transitions and a soft running Stop pulse, disabled for reduced-motion users and during stopping. Presentation only; campaign control API and call behavior unchanged.
