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
