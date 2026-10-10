# DECISIONS.md

Registro append-only de decisiones técnicas importantes.

No reescribir entradas antiguas. Agregar nuevas entradas al final con
`repo_agent.py log-decision`.

## Formato

### ADR-0001 - Título de la decisión

Fecha: YYYY-MM-DD

Estado: propuesta | aceptada | reemplazada | descartada

Contexto:
Explicar el problema o situación.

Decisión:
Explicar qué se decidió.

Motivo:
Explicar por qué.

Alternativas evaluadas:
- alternativa 1

Impacto:
- módulos afectados
- contratos afectados
- riesgos

Seguimiento:
Pendientes o validaciones futuras.

### ADR-0002 - Distribución nativa portable

Fecha: 2026-09-30

Estado: aceptada

Contexto:
Zynervox depende de Asterisk, VICIdial, MariaDB, Apache y rutas del host.

Decisión:
Publicar código sanitizado e instalador idempotente para Ubuntu; mantener Asterisk y base como servicios nativos.

Motivo:
Evita una imagen pesada y conserva compatibilidad SIP/RTP con la arquitectura existente.

Alternativas evaluadas:
- Contenedor monolítico o imagen completa de máquina virtual.

Impacto:
GitHub contiene código, migraciones, contratos y scripts; datos y secretos se respaldan aparte.

Seguimiento:
Validar en un Ubuntu limpio antes de uso productivo.

### ADR-0003 - Instalación web progresiva

Fecha: 2026-09-30

Estado: aceptada

Contexto:
Un host puede carecer de VICIdial o Asterisk y aun necesitar inspeccionar la interfaz web.

Decisión:
Desplegar primero la web y tratar telefonía, migraciones y validación estricta como integraciones opcionales.

Motivo:
La ausencia de la plataforma completa no debe impedir extraer y servir los archivos web.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0004 - MariaDB aislada para instalaciones portables

Fecha: 2026-09-30

Estado: aceptada

Contexto:
Los hosts pueden tener MySQL incompatible o puertos ocupados y Zynervox requiere el esquema VICIdial.

Decisión:
Ejecutar MariaDB 10.11 en Docker, enlazada solo a localhost, con puerto libre automático, volumen persistente y esquema sin datos.

Motivo:
Mantiene compatibilidad sin reemplazar la base existente ni publicar información de producción.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0005 - Zynerwaba como servicio WhatsApp aislado

Fecha: 2026-09-30

Estado: aceptada

Contexto:
Zynervox necesita operación omnicanal y Zynerwaba ya implementa WhatsApp multiempresa sobre MySQL 8.4.

Decisión:
Integrar Zynerwaba por proxy y contrato, con imagen fijada por digest, base, credenciales y volúmenes separados de asterisk.

Motivo:
Reutiliza funciones probadas sin acoplar tablas ni comprometer compatibilidad VICIdial.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0006 - SSO firmado e interfaz WhatsApp nativa

Fecha: 2026-10-01

Estado: aceptada

Contexto:
El iframe exigía una segunda sesión y no permitía una experiencia omnicanal propia de Zynervox.

Decisión:
Mantener Zynerwaba como motor Docker y consumir su API desde una interfaz Zynervox, intercambiando identidad mediante claims HMAC de corta duración.

Motivo:
Separa responsabilidades, evita compartir contraseñas y permite evolucionar llamadas y WhatsApp sobre una experiencia unificada.

Alternativas evaluadas:
- Mantener iframe; fusionar ambos proyectos en un monolito.

Impacto:
Cambia el contrato whatsapp, el instalador, la configuración del contenedor y el portal PHP.

Seguimiento:
Validar E2E con Meta cuando existan línea y destinatario exclusivos de laboratorio.

### ADR-0007 - Separar administración y operaciones WhatsApp

Fecha: 2026-10-01

Estado: aceptada

Contexto:
El panel administrativo mezclaba configuración multiempresa con conversaciones, contactos y campañas.

Decisión:
Reservar modules/admin/whatsapp.php para empresas, administradores, agentes, líneas y credenciales; crear las operaciones en una ruta independiente posteriormente.

Motivo:
Mantener responsabilidades claras y navegación coherente por rol.

Alternativas evaluadas:
- Conservar una sola pantalla con todas las funciones.

Impacto:
Cambia la navegación del módulo WhatsApp sin eliminar APIs ni datos operativos.

Seguimiento:
Diseñar e implementar el módulo Operaciones WhatsApp en una tarea separada.

### ADR-0008 - Versionado coordinado de Git e imagen WhatsApp

Fecha: 2026-10-01

Estado: aceptada

Contexto:
La integración todavía aplica overrides versionados sobre una imagen base y debe convertirse en un artefacto reproducible.

Decisión:
Publicar la próxima imagen Zynerwaba con etiqueta y digest inmutables, vinculados al mismo tag de Git, después de smoke, validación de roles, rollback y gate Meta cuando corresponda.

Motivo:
Evita deriva entre código, contenedor y despliegues, y permite recuperar una versión exacta.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0009 - Release híbrido instalable y aislamiento por proyecto Compose

Fecha: 2026-10-01

Estado: aceptada

Contexto:
Zynervox necesita desplegarse en servidores con VICIdial existente y ejecutar WhatsApp sin montar parches de runtime ni colisionar con otros contenedores.

Decisión:
Distribuir web y AGC completos por Git, construir Zynerwaba desde un Dockerfile con base fijada por digest y aislar nombres, redes y volúmenes mediante COMPOSE_PROJECT_NAME.

Motivo:
Permite una instalación reproducible de una sola ejecución, conserva los servicios del host y elimina la deriva entre overrides e imagen.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0010 - Integrar Farm y Stt Providers fuera del Docker WhatsApp

Fecha: 2026-10-01

Estado: aceptada

Contexto:
Zynervox requiere dos modulos administrativos de repositorios independientes.

Decisión:
Versionar snapshots saneados, reutilizar la sesion Zynervox y desplegar Farm con systemd y STT con MariaDB aislada.

Motivo:
Evita mezclar ciclos de vida, secretos y persistencia con el contenedor WhatsApp.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0011 - Vistas administrativas nativas para Farm y STT

Fecha: 2026-10-01

Estado: aceptada

Contexto:
Los iframe anidados reducían el área útil y duplicaban navegación y scroll.

Decisión:
Renderizar Farm y Stt Providers directamente dentro del layout administrativo, con CSS aislado y endpoints configurables.

Motivo:
Mantiene una sola interfaz Zynervox sin alterar servicios ni almacenamiento aislado.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0012 - Zynerdesk como stack Docker aislado, integrado por proxy inverso sin iframe

Fecha: 2026-10-02

Estado: aceptada

Contexto:
Se requiere incorporar Synervox Remoteo (supervision remota) como modulo Zynerdesk del panel Zynervox, probado primero en Mirmidon, sin tocar produccion.

Decisión:
Zynerdesk se instala como servicio Docker propio (compose.yml + MySQL propio), publicado bajo subruta via proxy inverso Apache (incluye WebSocket), con entrada en el sidebar. Se elimino el stack de prueba standalone synervox-remoteo-test (sin datos) para reemplazarlo por este modulo integrado.

Motivo:
El upstream 2.0.2 no expone SSO ni BASE_PATH por variable, pero su frontend ya calcula la ruta base desde location.pathname, por lo que un proxy inverso simple funciona sin iframe y sin modificar la imagen. Integracion nativa via API queda para una etapa futura.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0013 - Zynerdesk se embebe por composicion server-side, no por navegacion al proxy

Fecha: 2026-10-02

Estado: aceptada

Contexto:
ADR-0012 dejo abierta la forma de integracion visual y se asumio publicar el frontend del upstream por proxy. Al probarlo, el panel salia del shell de Zynervox: se perdia el sidebar y Supervision multiple y Remotear abrian fuera del panel. El upstream 2.0.2 no expone SSO ni una variable de base path y sirve rutas relativas al documento.

Decisión:
modules/admin/zynerdesk.php descarga la pagina del upstream por HTTP server-side, resuelve sus rutas relativas de href/src contra la carpeta del documento, reemite las hojas de estilo externas de su head, confina su CSS en @scope y la embebe en el shell. Las paginas visibles se declaran en una lista blanca de vistas (panel, supervicion, usuarios, remoteo); los enlaces del upstream hacia ellas se reescriben de vuelta al shell y pierden target=_blank. El proxy Apache se conserva para assets, API y WebSocket.

Motivo:
Es la unica forma de cumplir un solo sidebar, encabezado y scroll sin iframe y sin modificar la imagen del upstream. La resolucion de rutas es generica, de modo que una vista nueva no exige reglas de reescritura propias, y la adaptacion ocurre en memoria al servir la pagina, nunca editando el contenedor.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
(pendiente)

Seguimiento:
(ninguno)

### ADR-0014 - SSO firmado entre Zynervox y Zynerdesk

Fecha: 2026-10-02

Estado: aceptada

Contexto:
La integración visual Zynerdesk exigía un segundo login aunque el operador ya estaba autenticado como administrador Zynervox.

Decisión:
Zynervox emite claims nivel 9 HMAC-SHA256 por 60 segundos y Zynerdesk los intercambia por su sesión sid normal. El login directo se conserva para recuperación.

Motivo:
Evita compartir contraseñas y mantiene protegidos API, RBAC y WebSocket.

Alternativas evaluadas:
- Quitar autenticación; compartir contraseña administrativa.

Impacto:
Contrato HTTP Zynerdesk, configuración del instalador, Compose y vista integrada.

Seguimiento:
Publicar imagen inmutable, fijar digest y validar E2E en mirmidon.

### ADR-0015 - Desarrollo local y despliegue exclusivo desde artefactos publicados

Fecha: 2026-10-02

Estado: aceptada

Contexto:
Las copias manuales y las ediciones directas en servidores crean diferencias no
trazables entre el repositorio, las imágenes y las instalaciones.

Decisión:
Usar `E:\servidores\zynerdesk\proyectos\zynervoxv2` como copia local oficial del
propietario para desarrollo, reconstrucción, pruebas y documentación. Publicar
todo cambio aprobado en GitHub y desplegar servidores exclusivamente desde un tag
o commit publicado, o desde una imagen Docker inmutable fijada por digest. No usar
SFTP, SCP, copias manuales ni edición directa de código en servidores. Las pruebas
previas son locales; después del despliegue solo se valida el artefacto instalado.

Motivo:
Mantener una única fuente de verdad, permitir reconstrucciones reproducibles y
conservar trazabilidad y rollback por Git o digest.

Alternativas evaluadas:
- Copiar archivos modificados directamente al servidor.
- Corregir el código sobre una instalación ya desplegada.

Impacto:
Flujo transversal de desarrollo, publicación y despliegue. No cambia contratos ni
lógica de aplicación. Secretos y datos operativos continúan fuera de Git.

Seguimiento:
Verificar en cada despliegue el commit, tag o digest instalado y registrar el
resultado de las pruebas remotas.

### ADR-0016 - Migrar WhatsApp (Zynerwaba) de Docker a despliegue nativo

Fecha: 2026-10-03

Estado: aceptada

Contexto:
El codigo de Zynerwaba solo existia dentro de la imagen Docker miguelleonh0331/zynerwabav2:2.1.0-zynervox, sin repo propio. farm y stt_providers ya migraron a un patron nativo (vendor/ + systemd + MySQL del host) y whatsapp quedaba como la unica excepcion Docker, duplicando MySQL y sin trazabilidad por commit.

Decisión:
Extraer el codigo de la imagen, vendorizarlo en src/features/whatsapp/vendor/, reescribir installer/whatsapp.sh sin Docker/Compose (usuario systemd dedicado, BD zynerwabav2 en el MySQL nativo del host) y eliminar whatsapp/compose.yml, Dockerfile y overrides/ (ya fusionados en la imagen, verificado por hash).

Motivo:
Unificar el modelo de despliegue con farm/stt_providers, eliminar un MySQL duplicado, quitar la dependencia de Docker y recuperar el codigo fuente que hoy solo vive en una imagen binaria.

Alternativas evaluadas:
- Mantener Docker y solo versionar el Dockerfile+overrides (no recupera el codigo fuente real); publicar la imagen en un registro con digest fijado (no resuelve la falta de repo).

Impacto:
modulo whatsapp (CONTRACT.md reescrito); ningun otro modulo se modifica; riesgo de permisos con ProtectSystem=strict por DB_PATH_MEDIA fijado en vendor/config.js, mitigado con symlink hacia /var/lib/zynervox-whatsapp

Seguimiento:
Los 11 criterios de aceptacion de docs/TAREA_WHATSAPP_NATIVO.md seccion 7 se validaron en WSL (zynervox-borrar) antes de fusionar a main el 2026-10-03.

### ADR-0017 - Migrar Zynerdesk (Synervox Remoteo) de Docker a despliegue nativo

Fecha: 2026-10-03

Estado: aceptada

Contexto:
El codigo de Synervox Remoteo solo existia dentro de la imagen ghcr.io/miguelleonh0331/synervox-remoteo, sin repo propio versionado. farm y whatsapp ya migraron al patron nativo (vendor/ + systemd + MySQL del host); zynerdesk quedaba como el ultimo modulo Docker, duplicando MySQL y sin trazabilidad por commit. Esta instancia esta validada en mirmidon (produccion) en su forma Docker actual.

Decisión:
Extraer el codigo de la imagen, vendorizarlo en src/features/zynerdesk/vendor/, reescribir installer/zynerdesk.sh sin Docker/Compose (usuario systemd dedicado, BD syner_remoteo en el MySQL nativo del host, scripts/start.js como entrypoint real ya que migra y arranca en un solo proceso) y eliminar zynerdesk/compose.yml.

Motivo:
Unificar el modelo de despliegue con farm/whatsapp, eliminar un MySQL duplicado, quitar la dependencia de Docker y recuperar el codigo fuente que hoy solo vive en una imagen binaria.

Alternativas evaluadas:
- Mantener Docker y solo versionar zynerdesk/compose.yml (no recupera el codigo fuente real); publicar la imagen propia con digest fijado (no resuelve la falta de repo).

Impacto:
modulo zynerdesk (CONTRACT.md reescrito); ningun otro modulo se modifica; bcrypt nativo requiere prebuild linux-x64 glibc (verificado disponible, sin necesidad de compilar); mirmidon sigue en Docker hasta que se decida migrar produccion por separado, fuera de alcance de este ADR

Seguimiento:
Validado en WSL (zynervoxv1) con 6/6 pruebas de aceptacion (docker vacio, systemd activo, 14 tablas migradas, proxy 200, SSO HMAC real 200, sesion persiste tras restart) antes de fusionar a main el 2026-10-03. Migrar mirmidon requiere autorizacion explicita y separada.

### ADR-0018 - Mantener Zynerdesk en repo y retirar equipos de forma reversible

Fecha: 2026-10-07

Estado: aceptada

Contexto:
Zynerdesk fue importado desde una imagen sin repositorio upstream propio; la regla de snapshot inmutable impedía corregirlo. Los equipos antiguos saturaban la vista y debían ocultarse sin perder historial.

Decisión:
El código bajo src/features/zynerdesk/vendor pasa a mantenerse directamente en zynervoxv2 conservando su procedencia histórica. Los equipos se retiran lógicamente mediante campos de auditoría, endpoints admin idempotentes y restauración explícita. Las actualizaciones usan upgrade con backup y nunca init.

Motivo:
Permite mantenimiento trazable, conserva datos y evita que un cliente que vuelva a reportar reaparezca sin decisión administrativa.

Alternativas evaluadas:
- Borrado físico de agentes e historial: descartado por pérdida de evidencia.
- Ocultamiento solo en el navegador: descartado por no ser consistente entre usuarios.

Impacto:
Modifica contrato, fuente, migraciones, UI e instalador de zynerdesk; no agrega dependencias ni toca datos de otros módulos.

Seguimiento:
Validar despliegue de upgrade, RBAC, retiro, persistencia del retiro tras reportes y restauración.

### ADR-0019 - Consolidar pendientes locales de v2 sin contrasenas compartidas

Fecha: 2026-10-09

Estado: aceptada

Contexto:
Seis archivos locales sin commit y artefactos de agentes requieren consolidacion; ARCHITECT_AGENT reviso contratos y autenticacion.

Decisión:
Normalizar core como dependencia modular; separar infraestructura externa; conservar interfaz del agente y cargar WhatsApp con autenticacion existente y ruta relativa; preservar artefactos locales fuera de distribucion.

Motivo:
Evitar publicar contrasenas y cambios especificos de una instalacion; conservar historial sin alterar produccion.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
agent, contratos farm/stt_providers/whatsapp/zynerdesk, mapa generado, documentacion y exclusiones de artefactos.

Seguimiento:
(ninguno)

### 2026-10-05 - Bot IVR: base zynervox y configuración obligatoria

Aprobación ARCHITECT_AGENT: alcance exclusivo de bot_ivr y despliegue de sus
copias. Base fija zynervox; configuración guardada por el botón existente;
sin fallback a Config.php ni astguiclient.conf. Preservar carsa_initial_survey
y tablas auxiliares locales. No cambiar ivr_builder ni su conexión.
Contrato existente conservado. DDL propio en models/001-zynervox.sql, sin
cargar datos de demo. Aislamiento multiempresa y ejecución de llamadas no
forman parte de esta etapa de creación de campañas.


### 2026-10-05 - Bot IVR: campañas, listas y leads con esquema propio

ARCHITECT_AGENT aprueba migración aditiva: zynervox_bot_campaigns(campaign_id),
zynervox_bot_lists(list_id,campaign_id), zynervox_bot_list(lead_id,list_id).
FK ON DELETE RESTRICT; conservar tablas y endpoints legacy sin mezclar IDs.
Listado inicial y creación sin flujo/carga de leads; detalle con listas y horarios.
Contrato actualizado con aprobación arquitectónica. No modifica otros módulos.
Motor legacy no consume campañas nuevas; no activar ejecución hasta migración
posterior. Horarios se guardan como ventana diaria sin despachar llamadas.
La cuenta propia y contraseña común acordada se integrarán al instalador en
una etapa posterior; credenciales nunca se incluyen en estos documentos.


### 2026-10-05 - Bot IVR: ampliar metadatos con referencia VICIdial

ARCHITECT_AGENT autoriza migración 003 y extensión del contrato dentro de bot_ivr.
Referencia read-only: columnas reales de asterisk.vicidial_campaigns en mirmidon.
Añadir 12 campos administrativos mediante ALTER aditivo/idempotente; conservar
campaña existente y timestamps. max_channels es propio de Bot IVR. Método y
 grabación restringidos al subconjunto documentado de opciones reales; estados
normalizados sin delimitadores legacy. Nada de FK ni dependencia de otros módulos.
Formulario de ancho completo con dos columnas sin duplicar atributos.
No conectar metadatos al motor ni usar user_group como ACL en esta etapa.


### 2026-10-05 - Bot IVR: carga de leads desde el detalle de lista

ARCHITECT_AGENT autoriza nuevo detalle/importador y actualización del contrato.
Fecha created_at ya existente, sin cambio de esquema. Validar pertenencia de
list_id/campaign_id y sesión/CSRF. TXT UTF8, 2-10 columnas, numero obligatorio;
variables raw en extra_json sin TTS. Dedupe por archivo/lista, conservar datos
existentes y bloquear padre FOR UPDATE para serializar cargas concurrentes.
Se valida archivo completo antes de INSERT y se reportan filas rechazadas.
Sin dependencia de otros módulos ni publicación de trabajos legacy.


## 2026-10-05 — Capa compartida zynervox_queries en PHP

- Estado: aceptada. Aprobada por ARCHITECT_AGENT /root/php_queries_arch.
- Contexto: usuario autoriza procede para separar consultas por motor/módulo;
  workers se posponen. Web PHP/PDO; MultiBase Python es referencia arquitectónica.
- Decisión: conexión/factory y contrato BotIvrRepository compartidos; mysql/bot_ivr
  contiene SQL, MariaDB comparte implementación, validaciones quedan en consumidor.
- Compatibilidad: base zynervox, botón obligatorio, config sin engine equivale mysql;
  acceso PDO legacy y workers intactos. SQLServer/PostgreSQL se rechazan por ahora.
- Alcance: capa nueva y admin Bot IVR, pruebas, contratos y documentación.
  Prohibido cambiar otros módulos, tablas, datos o workers.
- Motivo: mantener PHP sin servicio extra ni duplicación SQL en dos lenguajes;
  extensión a motores exige schema/transacciones/bloqueos y pruebas, no solo un DSN.


## 2026-10-05 — Carga reemplaza base de lista

- Estado: aceptada; solicitada explícitamente por usuario y aprobada por
  ARCHITECT_AGENT /root/php_queries_arch.
- Decisión: validar archivo primero y rechazar cero filas válidas; bloquear padre,
  verificar campaña, DELETE por list_id e INSERT atómicos. Deduplicación del archivo.
- Errores: rollback propio o savepoint si hay transacción externa; preservar base anterior.
- Impacto: contrato Bot IVR/compartido, repositorio leads, UI Reemplazar base y pruebas.
  Sin cambios de esquema, workers, metadatos u otras listas.


## 2026-10-05 — id_flujo en listas Bot IVR

- Aceptada por ARCHITECT_AGENT /root/php_queries_arch ante definición del usuario:
  cada lista referenciará un flujo de IVR Builder para audios futuros.
- Columna BIGINT UNSIGNED nullable, migración 004 aditiva, sin FK a otro módulo.
- Formularios nuevos requieren entero positivo representable por PHP; omitido en
  formulario anterior conserva valor, listas previas permanecen sin asignar.
- No certifica publicación/existencia ni implementa generación o modifica builder.


## 2026-10-05 — Laboratorio independiente texto a audio

- Aceptada por ARCHITECT_AGENT /root/php_queries_arch; usuario redefine prueba
  independiente sin listas/flujo, aprueba voz gTTS1.3 y autoriza implementación.
- PHP página/servicio propios, Python ejecutor y venv propio gTTS2.5.4; no usar
  dependencias CARSA. Registro explícito extensible a proveedor de otra red futuro.
- WAV fuera webroot, ownership sesión, CSRF/admin, texto<=1000, argv+JSONstdin,
  timeout90s, resultados validados, historial cinco y reproductor nativo.
- No BD, jobs, llamadas, workerslegacy ni conexión compartida nueva.


## 2026-10-06 — RGA como proveedor remoto de laboratorio

- Aceptada por ARCHITECT_AGENT /root/php_queries_arch a solicitud del usuario.
- Cliente síncrono de gateway del manual; credenciales privadas, endpoint fijo
  server-side, Bearer, JSON y WAV. Selector rga separado de gtts sin fallback.
- requests sin redirects, descarga acotada, validación formato, timeout PHP180s
  y errores sanitizados; conservar ownership e historial.
- Cambios limitados a laboratorio/proveedor/configuraciónsecretaprivada.
  Sin cambios Farm, gateway, BD, campañas o workers.

### ADR-0020 - Integracion selectiva v3: Servicios BD y despliegue conservador

Fecha: 2026-10-09

Estado: aceptada

Contexto:
Usuario solicita v3 dentro de v2; aprobacion ARCHITECT_AGENT cross-modulos.

Decisión:
Conexion IVR/Bot administrada desde Servicios, esquemas nuevos en core con usuarios separados; conservar legacy y mejoras v2.

Motivo:
Evitar duplicidad de configuracion y perdida de datos al reinstalar.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
Contratos core, bot_ivr, ivr_builder, stt_providers, installer y zynervox_queries actualizados.

Seguimiento:
Usuario prueba interfaz/despliegue antes de commit y push; migracion legacy explicita.

### ADR-0021 - Aislar v2 de produccion compartida sin copiar datos

Fecha: 2026-10-09

Estado: aceptada

Contexto:
zynervox productiva y configuracion core compartida; usuario confirma usuario exclusivo y campañas vacias.

Decisión:
Config por deployment; tablas core v2_*; usuarios propios DML por tabla; sesiones y gate Apache propios; bloquear motor legacy.

Motivo:
Cambiar solo BD o contraseña afectaria producción mediante singleton y runtime compartidos.

Alternativas evaluadas:
- (ninguna registrada)

Impacto:
core, bot_ivr, ivr_builder, installer y admin index; no datos/cuentas/config prod modificados.

Seguimiento:
Preparar motor llamadas independiente; no activar workers/SIP productivos.

## ADR-0022: Carriers Core y despliegue selectivo (2026-10-09)

ARCHITECT_AGENT aprueba adaptar Carriers aislado a zynervox_core.v2_carriers,
auditoria metadata v2_access_log, nivel 9 y CSRF. Genera archivos inactivos
en runtime propio; sin include, sudoers ni reload del Asterisk compartido.
Legacy conserva su conexion. --module carriers exige instalacion v2 existente
y despliega lista explicita de tres archivos, tabla y permisos de su cuenta Core.
No ejecuta instalacion completa, passwords, login ni Bot. Instalacion full v2
incluye Carriers. Activar troncales exige revision operativa separada.

## 2026-10-10 ? Preparaci?n del discador Bot IVR

Intervenci?n ARCHITECT_AGENT: aprobada extensi?n aditiva del contrato bot_ivr,
limitada a su esquema, documentaci?n y prueba de importaci?n. Se adaptan status
y called_count de vicidial_list; last_call_at usa UTC y next_call_at programa
reintentos. No se copian campos personales redundantes ni se modifica VICIdial,
core, zynervox_queries o el motor. Base seleccionada al ejecutar SQL; importaci?n
conserva contrato y usa defaults. Responsable de continuaci?n: bot_ivr.

### Extensi?n de alcance: integraci?n de instalaci?n

ARCHITECT_AGENT autoriza adem?s installer/ivr-builder-bot-db.sh y README de
installer para incluir migraci?n 005 en despliegues futuros. Contrato del
instalador sin cambios; se separa ALTER del ?ndice para compatibilidad con el
helper apply-schema.py y reaplicaci?n cuando las columnas ya existen.
# 2026-10-10 — Validación de variables para audios por lista

Intervención ARCHITECT_AGENT: aprobadas extensiones públicas de bot_ivr,
zynervox_queries e ivr_builder para leer leads y publicación sin acoplarse
a internos de CARSA. Alcance permitido: servicios de validación/página de
Bot IVR, método audioLeads en repositorio y lectura pública published_flow.
Contratos y pruebas documentan las fronteras. Prohibido modificar CARSA,
credenciales, marcación o el generador legacy. No añade dependencias externas.
Responsable de continuación: bot_ivr; generación real por lista pendiente.
