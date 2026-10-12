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

## 2026-10-10 — Proveedor gTTS aislado de listas y caché determinista

ARCHITECT_AGENT aprueba implementación bot_ivr y extensión de preparación
en installer/macelioai-tts.sh. Reutiliza venv gTTS propio de la web v2 (Python
3.6+), sin dependencias a CARSA. Worker recibe snapshot validado, sin conexión
SQL; caché WAV/estado bajo runtime aislado. Hash incluye texto y perfil completo;
flock por hash/lista y rename atómico controlan concurrencia. No se modifica
discador, configuración Asterisk ni tablas. No se habilitan compuestos sin
concatenación. Alcance: Bot IVR, instalador y sus contratos/pruebas.

## 2026-10-10 - bot_ivr: publication registry

Use cache-local audio_registry.sqlite (published_audio hash primary key, timestamp) for publication metadata; no new Redis service or DB permissions. WAV must be immutable after publication. Deletions must use delete_audio to unpublish first. SQLite reads metadata, so this removes WAV reads, not all filesystem I/O. Existing successful manifests can seed the index without reading WAV content.

## 2026-10-10 - core: isolated phones administration

Enable phones.php in v2 gate. Phones use zynervox_core.v2_phones with dedicated
DML grants and access audit. Keep legacy adapter unchanged outside isolation.
Add CSRF and basic validation; blank edit password preserves stored secret.
Account storage only; no Asterisk configuration generation or activation.
Schema included in isolated installation via carriers.sh. Architect scope:
Core and installer integration approved for isolated account persistence.

## 2026-10-10 - core: generate isolated SIP/PJSIP annex files

CRUD rebuilds active annexes into sip-zynervoxv2-annexos.conf and
pjsip-zynervoxv2-annexos.conf under configured runtime/modules/asterisk,
with context zynervoxv2-test, atomic per-file rename and mode 0640.
No includes or reloads. Reject config-breaking login/password characters.
Installer deploys Phones and initializes both files. DB and two files are
not a single transaction; generation errors require retry/regeneration.

## 2026-10-10 - per-list dialing prefix

Architect approval: extend zynervox_queries public repository with scoped updateListDialPrefix. Persist independent list prefix, not campaign routing or audio identity. No dialing activation.

## 2026-10-10 - call tracking

Architect approves public repository startCall/finishCall and isolated attempts table. Parent list lock serializes missing-lead creation with uploads; unique call_id ensures idempotence. Late finishes cannot overwrite newer lead status. Manual route explicitly targets list 1; prefix alone does not select a list. No automatic retries or STT.

## 2026-10-10 - CDR and event audit

Architect approves bot_ivr/query/installer audit extension. Native CDR has no backend and CEL is disabled; leave shared PBX configuration unchanged. Build v2 CDR from explicit AGI events and carry native uniqueid/linkedid. Spool protects captured events against DB failure; process/server crash before an event is captured cannot be reconstructed by this mechanism. billsec includes AMD, not just bridge speech.

Recovery checks open attempts older than five minutes against native PBX
channels. Two-minute absence grace avoids hangup callback races; mark LOST
with RECOVERY event, preserving unknown actual end/duration. A later real
finish can supply authoritative result. No recovery while journals are pending.


## 2026-10-10 - Arquitectura: origenes reutilizables por prefijo

Revision ARCHITECT_AGENT: alcance aprobado core (Carriers/DialplanOrigins, tabla y tests), admin (formulario), bot_ivr (selector y plantilla) e installer/carriers.sh. Sin cambios a otros modulos ni a sus internos. Core publica dialOrigins/extractDialOrigin; Bot IVR consume ese contrato y conserva dial_prefix existente por lista. Prefijo global unico evita elegir dos carriers para la misma ruta en zynervoxv2. La extraccion usa solamente el dialplan guardado y las rutas iniciales _<digitos>X., prioridad 1; no los contextos auxiliares. Origen eliminado/inactivo o ruta retirada no se ofrece. No activa motor de llamadas. Plantilla manual conserva fallback lista 1 pero respeta ZV2_LIST_ID recibido por el futuro worker. Cabecera fija unica; se agrupan todas las rutas principales antes de los contextos auxiliares. Aprobada extension de contratos core/admin/bot_ivr.


## 2026-10-10 - Architecture review: standalone dialplan administration

ARCHITECT_AGENT review: approved core (Dialplans service/schema/parser/Carriers/gate/tests), admin (dialplan.php/sidebar/carriers.php) and installer migration. Dialplan is an independent Core record, not a carrier or a new feature module. Public API Dialplans getAll/getById/save/delete/origins. Existing Carriers::dialOrigins delegates for compatibility with Bot IVR. One numeric prefix per record, automatically derived from the main route; unique globally. Repeated identical helper contexts are emitted once; conflicting definitions are rejected. Migration copies existing carrier code, state, prefix and name transactionally before clearing carrier dialplan_entry. Legacy origin table retained; no destructive DROP. New sidebar item immediately after Troncales SIP. Generated file uses existing fixed context, no automatic PBX reload. No workers or live calls introduced. Contract extensions approved.


## 2026-10-10 - Carrier protocol metadata correction

ARCHITECT_AGENT scope: core Carriers, admin carrier display and tests. Existing v2 carrier file is included by sip.conf despite its historical pjsip filename; do not move it or change shared PBX includes for a metadata/display fix. Determine protocol from account block, persist correct metadata, preserve configuration path and legacy behavior. Contract extension approved.


## 2026-10-10 - Common contexts template

ARCHITECT_AGENT approved core parser/template, admin template view, installer normalization and example/tests. Shared contexts now belong to Core template and exist even with no active routes. Route editing cannot override the fixed definitions. Legacy helper copies must match exactly before migration strips them; preserve custom routes and fail closed for divergent helpers. User will author the next route; do not create 7305 automatically. Contract extension approved.


## 2026-10-10 - Initial IVR playback engine

ARCHITECT_AGENT approval: bot_ivr engine/service/tests/examples, core fixed contexts, zynervox_queries public call context/event allowlist, installer executable and runtime setup. Initial test scope is published flow 11 (noop, prepared personalized audio, hangup); supports linear noop/goto/create_audio/create_audio_dynamic/playback/hangup. Unsupported interactive/STT/composite/external-action nodes rejected before playback, never silently skipped. No campaign worker or Play integration in this stage; user authors two routes. Contexts distinguish AMD versus no AMD. Runtime resolves current lead variables and canonical Python-compatible hash, consults SQLite published registry, delegates actual WAV read to Asterisk playback without WAV revalidation. Public repository resolves one scoped attempt/lead/list rather than loading an entire lead list. Audit uses durable existing journals and records IVR_START/NODE/END, preserving call closure independently. No outgoing call automatically launched. Contract changes approved.

- 2026-10-10: Las dos rutas CARSA conservan lista 1 como fallback para pruebas; ZV2_LIST_ID explicito tiene prioridad. Contextos comunes permanecen centralizados. carsa_ivr (7305) queda fuera de este cambio.

- 2026-10-10: Grabacion del bot en canal remoto con mezcla bidireccional, sin opcion b porque el IVR opera antes del bridge. Identificador filtrado en nombre de archivo y cierre explicito antes de retorno.

- 2026-10-10 ARCHITECT_AGENT: Autorizada extension bot_ivr para ejecutar grafo CARSA (amd/menu/capture_stt/bridge/inject_sql/execute). Limites: adaptadores propios, secretos privados, contratos publicos IVR Builder y queries; sin dependencia interna del motor CARSA en runtime. Alcance aprobado: bot_ivr, consultas de resultados, validacion de puentes en ivr_builder e instalador. SQL de v2 aislado por defecto, destino externo sujeto a configuracion explicita. Audio de respuestas capturadas permite TTS acotado en llamada con registro hash; textos conocidos siguen prepublicados. CDR separado del resultado comercial.

- 2026-10-10: Interactive provider verification found zero active Deepgram profiles; configured the already-running local zypad_stt gateway (active Groq profiles) for menu/capture and Vosk separately for amd. Provider credentials migrated once to private v2 configuration; no legacy runtime imports. Existing Python 3.6 gTTS environment retained; HTTPS adapters use bounded curl with credential headers through stdin (provider rejects Python client), verified Groq date extraction. SQL business results default to isolated core pending any explicit external destination choice. Queue ID stays empty until a real queue exists; no invented foreign IDs.

### 2026-10-10 - Call export metadata (ARCHITECT_AGENT)
Scope approved: bot_ivr audit/migration and zynervox_queries event persistence. Extend existing attempt rows and event payloads; no new public methods. Snapshot actual IVR identity at IVR_START. Record final file metadata once before durable FINISH journaling; keep WAV on disk, never revalidate published audio cache. Historical flow identity comes from IVR_START events, never inferred from current list configuration.

### 2026-10-10 - Lead segmentation by latest IVR status (ARCHITECT_AGENT)
Approved scope: bot_ivr graph/audit schema and zynervox_queries status persistence. Lead and attempt status share latest business outcome; dial_status remains telephone evidence. Intermediate CARSA stages, terminal nodes and SQL RESULTADO update status, guarded against older attempts. Finish/recovery preserve attained IVR status.

### 2026-10-10 - Services PBX AMI configuration (ARCHITECT_AGENT)
Approved admin/core scope: level-9 PBX form, CSRF POSTs, reusable PbxAmi config/test helper and exact IsolatedGate route. Private atomic JSON under deployment directory/pbx with mode 0640; never show/log secrets. Connection test authenticates only, no calls or Asterisk configuration changes. Credentials supplied by administrator; no legacy secret import.

### 2026-10-10 - List Play/Stop AMI worker (ARCHITECT_AGENT)
Approved bot_ivr/core/zynervox_queries scope. Persist dial runs and correlate preclaimed attempts via AMI OtherChannelId. One active run per list and an exclusive worker lease; database claim locks enforce selected simultaneous slots including existing calls. Campaign dial_statuses select eligible leads, once per run; no automatic repeat of finalized leads. Selected list channels govern this worker. Stop drains claims already launched. New AMI requests use isolated Local prefix routes and inherited list ID; credentials only from PbxAmi private storage. Audit callbacks remain authoritative for completion. No auto-launch on deployment.


## 2026-10-10 - List statistics (ARCHITECT_AGENT)

Scope: bot_ivr UI/API and public zynervox_queries repository. Add read-only listStatistics contract, no schema changes. Cards count current contacts using their latest attempt; historical errors remain visible separately. Allow lists.php, repository contract, list_edit.php, list_call_api.php and scoped test/docs. No dial worker, credentials or other modules change.


## 2026-10-10 - Lead status eligibility and AGI execution (ARCHITECT_AGENT)

Scope bot_ivr/zynervox_queries/installer. Existing eligibility remains lead.status, never dial_status. Import explicitly initializes NEW. Restore execute permission for CLI AGIs after web deployment; latest list26 AMI attempt did not execute IVR (Permission denied), so no business status exists to recover for that attempt. Do not invent historical dispositions.


## 2026-10-10 - Isolated campaign dashboard (ARCHITECT_AGENT)

Scope admin entrypoints, core isolated gate, bot_ivr monitor view and public repository. Replace isolated admin redirect with read-only graphic campaign monitor at dashboard and campaign_monitor.php. Preserve legacy dashboard/monitor behavior outside isolated deployment. New repository aggregate snapshot avoids VICIdial and per-list detail queries. Authorized roles 7/8/9; no controls, credentials, or dial changes.


## 2026-10-10 - Active campaign status summary (ARCHITECT_AGENT)

Monitor snapshot filters active campaigns and adds current lead.status grouped per campaign. Scope existing repository/view/tests/contracts. No schema, dialing or historical changes.

## 2026-10-11 - Rocky Linux 9 aislado en soundhound (ARCHITECT_AGENT)

El escalamiento arquitectonico aprobo cambios limitados a installer, sus pruebas,
contrato y documentacion de stack/despliegue. Mantener mod_php en Debian/SUSE;
Rocky usa PHP-FPM exclusivo con prepend administrativo obligatorio y handler
limitado a v2. SELinux conserva Enforcing y usa tipos propios de runtime,
configuracion y dos AGI, con ACL de rutas v2 para Asterisk en vez de pertenencia
al grupo Apache. El diagnostico aislado no exige VICIdial ni configura datos
legacy ficticios. Fuente Windows/SMB elegida expresamente por el usuario;
despliegue con snapshot comprimido para evitar latencia por archivo. No publica
automaticamente esta excepcion como politica general de produccion. Sin cambios
internos a modulos funcionales, sin llamadas reales ni carriers prellenados.

## 2026-10-11 — Reenviar con variables y headers SIP

ARCHITECT_AGENT aprobó alcance ivr_builder + bot_ivr, contratos y predial mínimo.
Se conserva tipo reenviar y compatibilidad con destinos por contexto. Configuración
forwarding se guarda en data_json existente, evitando migraciones. Salida PJSIP
con predial fijo y helper AGI aplica headers al canal llamado; solo X-* y
User-to-User, variables resueltas antes de marcar, sin valores en auditoría.
Despliegue autorizado fuente local → SMB → Soundhound, sin ejecutar instalador.

## 2026-10-11 — SIP en Reenviar y Soundhound

ARCHITECT_AGENT aprobó ampliar ivr_builder/bot_ivr/core: protocolo SIP/PJSIP,
metadata existente conservada y archivos de troncales separados. Carga de peer
ya activo autorizada sin cambiar active ni hacer pruebas de llamadas externas.
chan_sip20 compilado con fuente/opciones actuales, UDP5061 sin conflicto con
PJSIP5060. Headers SIP mediante slots heredados, verificados y limpiados solo
si fueron creados por el nodo; no limpieza global de headers.

## 2026-10-11 — Base OpenVPN Docker para Vpn

ARCHITECT_AGENT aprobó alcance ops/vpn y documentación de despliegue; sin PHP,
sidebar, GUI ni instalador. Servicio CLI hwdsl2/openvpn-server fijado por digest,
volumen persistente privado y endpoint LAN explícito. GUI futura requiere
contrato/API restringido; nunca dar socket Docker a Apache. Perfiles y claves
fuera de Git/SMB. Acceso Internet requiere IP pública/DNS y NAT verificados.

## 2026-10-11 ? Panel administrativo Vpn

ARCHITECT_AGENT aprob? nuevo m?dulo vpn, integraci?n m?nima sidebar/gate y backend
Python stdlib/SQLite dentro del contenedor OpenVPN. API loopback127.0.0.1:18081
con token privado, sin Docker socket en PHP. Authnivel9/CSRF; actor desde sesi?n.
Certificados/PKI autoridad, inventario reconciliado. CN inmutable y no reutilizable,
borrado l?gico despu?s de revocar. Management desconecta sesiones; confirmaci?n
se lee antes de cerrar canal. Hooks Unixsocket auditan CN/IP, peerUIDroot/nobody.
Historial solo desde habilitaci?n; auditor?a append-only l?gico, no immutable root.
Usuario cambia flujo para esta tarea: fuente/verificaci?n/despliegue, SIN PUSH.

## 2026-10-11 — Auditoría Vpn bajo demanda

ARCHITECT_AGENT aprueba separar lectura de inventario e historial. inventory
no consulta/devuelve audit; acción audit paginada por id20 registros, índices
por actor/target/action, filtros exactos y snapshot estable. Sin conteo global
ni OFFSET. Vista inicial sin tabla histórica; usuario abre Ver auditoría y
navega en ambos sentidos. Se mantienen todos los eventos, sin push.

## 2026-10-11 — Motor dialer: auditoría de continuidad y Fase 7

ARCHITECT_AGENT aprueba trabajar exclusivamente en el runtime del módulo dialer
(Dialer.php, páginas dialer/campaigns, worker y AGI), pruebas del módulo y
documentación append-only. No se modifican bot_ivr, VICIdial, PbxAmi, Carriers ni
Dialplans. Despliegue selectivo autorizado desde SMB a la raíz web separada,
con backups, hashes y pruebas. El usuario reactivó el objetivo del motor completo.

Fórmulas de reportería: abandono humano / respuestas humanas, idéntico por nivel;
contacto = respuestas humanas / intentos; ASR = contestadas / intentos;
conversión = ventas marcadas como contacto / contactos tipificados usando el
catálogo actual. MACHINE queda fuera de abandono. AHT usa conversación + primera
tipificación observadas, espera usa duración respuesta→CONNECT; los históricos
sin duraciones no inventan ceros. Ocupación N/D hasta medir tiempo logueado.

La auditoría del origen real detecta que el worker previo origina Local hacia
Answer/AMD/Queue sin Dial a troncal ni MixMonitor. Las fases anteriores quedan
como pruebas sintéticas, no prueba del motor saliente completo. ARCHITECT_AGENT
aprueba reparar origination completa mediante rawAction existente, tracking
idempotente, grabación post-answer, límites reales y modo manual en dialer;
aprueba aclarar CONTRACT conservando AMI/Queue/AMD, MySQL y MicroSIP.

## 2026-10-11 — Cierre del motor SIP y operación persistente

ARCHITECT_AGENT aprobó ampliar exclusivamente dialer: modo PROGRESSIVE, estado
RUNNING/STOPPED, reservas globales de agentes, intervalos observados, migraciones
008–011 y worker propio supervisado. No se modifica implementación compartida
ni bot_ivr. Endpoint de salida se obtiene de Carriers por su contrato público;
Queue y AMD siguen siendo nativos de Asterisk, sin ARI, Redis ni listener nuevo.

Cada reserva relee estado/capacidad bajo lock; agent_slot UNIQUE impide ocupar
al mismo agente en dos llamadas MANUAL/PROGRESSIVE. Cola personal entrega al
agente reservado; DIALER_RESERVED pausa solo sus colas principales. Se preservan
pausas manuales. CONNECT conserva la interfaz como MemberName para atribuir
agente y conversación. WRAPUP pausa automáticamente hasta tipificación pendiente
resuelta. Reintentos por disposición usan demora y no reciclan intentos nuevos
al corregir una llamada vieja.

MixMonitor obligatorio inicia posrespuesta antes de AMD. Fallo de grabación
impide entrega al agente. MESSAGE exige audio existente; el cupo se conserva
hasta terminar playback. El closer es idempotente. Conciliación confirma ausencia
del ChannelId en PBX; NOANSWER y PBX_LOST no fabrican duraciones. La unidad PHP
wwwrun:www usa flock, restart y heartbeat; el panel solo hace POST+CSRF a BD.

Ocupación por campaña usa conversación más WRAPUP observado sobre intervalos
ACTIVE+WRAPUP; pausas quedan fuera y huecos de observación se conservan. Cobertura
insuficiente produce N/D. No sumar denominadores de campañas que comparten
agentes. ADAPTIVE mantiene histéresis y clamp con cadencia mínima de 10 s.

El usuario decidió usar únicamente SIP. Se revierte la activación exploratoria
de includes PJSIP restaurando configuración raíz y permisos originales. No se
certifica PJSIP ni se modifica su transporte. La prueba externa siguiente usará
lista del usuario, campaña separada y concurrencia inicial 1; el laboratorio
SIP/RTP no sustituye validación del proveedor, NAT y softphone físico.

La prueba final de reinicio encontró una carrera entre arranque del servicio y
un worker manual: recrear RuntimeDirectory podía cambiar el inode del bloqueo.
Se conserva el directorio con RuntimeDirectoryPreserve=yes. La prueba espera
que MainPID posea el flock antes de lanzar el duplicado, comprueba el mismo
inode tras SIGKILL y limita temporalmente el proceso de prueba.

## 2026-10-11 — Variables en vivo y audios administrados; aprobación ARCHITECT_AGENT

Alcance autorizado core/dialer: cinco páginas exactas en IsolatedGate y entrada
de audio a través del reporte. Apache real devolvía 403 antes de la corrección;
el servidor HTTP privado no detectaba esa diferencia. Preservar allowlist
versionada, incluida Vpn; sin wildcard ni endpoints CLI accesibles por HTTP.

Queue Gosub en canal del agente registra asignación antes del puente: evita
depender de queue_log, que wwwrun no puede leer. Se valida Phones público,
reserva y asignación con lock; CONNECT sigue siendo referencia definitiva,
conservando callback si falta el log. Sin listener, permisos ampliados ni tabla
nueva. Ficha propia y variables como texto. Audio solo WAV cerrado en ruta fija,
sin symlinks/rutas de usuario, Auth 7/8/9, streaming Range y sesión liberada.

Por indicación del usuario las atenciones reales serán posteriores al laboratorio.
Archivo recibido inspeccionado por formato/contadores: mil filas, no cargado
ni llamado. Diez agentes y destinos SIP loopback, datos sintéticos compatibles
con las seis columnas. Historial STOPPED retenido para búsqueda web; agentes y
secretos temporales retirados. Predictivo nivel 1 para diez entregas; regulación
adaptativa comprobada separadamente en pruebas transaccionales. No certificar
sobreoferta ni proveedor/NAT/softphone físico mediante esta muestra.

## 2026-10-11 — Borrado definitivo solicitado por el usuario; ARCHITECT_AGENT

La restricción anterior bloqueaba toda campaña con llamadas y la FK RESTRICT
impedía borrar listas con leads. Se permite borrar historial cerrado mediante
POST+CSRF y confirmación explícita de datos afectados. No se ejecuta eliminación
sobre campañas existentes para probar la mejora. Lock de campaña serializa con
reservas; RUNNING o llamada abierta impiden borrar. Orden de borrado respeta FK,
transacción con rollback. Conservar WAV físicos y advertir que su índice web se
elimina. Borrar DNC propias para evitar ON DELETE SET NULL, que las convertiría
accidentalmente en exclusiones globales; preservar DNC globales y ajenas.

Capturar interfaces antes del commit; retirar solo membresías de esa campaña,
regenerar dialplan/colas por APIs públicas y recargar explícitamente. Error PBX
posterior se informa como limpieza pendiente, sin revertir ficticiamente la BD.
Sin migraciones ni cambios a otros módulos. Pruebas con FK reales en esquema
descartable, PBX adaptado sin conexión ni llamadas y regresión HTTP.

## 2026-10-11 — Salida SIP seleccionable por campaña (ARCHITECT_AGENT)

Aprobado cruce core/dialer: API pública Dialplans::outboundRoute devuelve la
salida telefónica simple, sin ejecutar hooks IVR. selected_dialplan_id comparte
la salida existente; dialplan_id conserva propiedad exclusiva del callback.
Prefijo interno automático, compatibilidad legacy y cambio STOPPED sin llamadas
abiertas. Reserva compara snapshot bajo lock para rechazar rutas obsoletas sin
consumir intentos. No tocar bot_ivr, VICIdial, credenciales ni activar PJSIP.
Migración 012 validada en tablas TEMPORARY; despliegue productivo pendiente.

## 2026-10-11 — Alta de agentes y acceso al marcador aislado

ARCHITECT_AGENT aprueba cruce core/admin: CoreUsers, Auth, IsolatedGate,
users.php y vista interna users_isolated.php. Mantener ruta legacy y crear
exclusivamente nivel1 en tabla v2; no reutilizar template vicidial_users ni
Phones::createFromUser, que crea PJSIP automáticamente. Anexo SIP separado por
API/UI existente. Hash para contraseñas nuevas con compatibilidad histórica;
regenerar sesión y revalidar active/rol por request aislado. Otros roles solo
listados para proteger administración existente. Sin migración ni cambios PBX.
Ampliación puntual de fixture report-router.php: tabla users TEMPORARY para
que la regresión HTTP pruebe identidades reales bajo el nuevo contrato Auth.
No editar implementación Dialer, bot_ivr, campañas, credenciales ni PJSIP.

## 2026-10-11 — MANUAL conecta al agente antes de marcar

ARCHITECT_AGENT aprueba cambio exclusivo de dialer, sin migración ni nueva
dependencia. AMI Originate llama al anexo reservado; Dial directo transmite
timbrado y early media. MANUAL omite Queue/AMD para conservar el audio desde
el comienzo. PROGRESSIVE/ADAPTIVE/RATIO mantienen destino primero.

La ruta y timeout se validan al originar y viajan como variables heredadas;
el ChannelId conserva la pierna agente. U posanswer registra respuesta real
del cliente, inicia/verifica MixMonitor b y atribuye el puente por canal agente.
Fallo de tracking/grabación aborta el puente. El handler de grabación corre en
la pierna cliente, y el del agente cierra y libera reserva idempotentemente.
Sin respuesta cliente no se fabrican conversación, agente atribuido ni wrapup.

Cambiar dial_method exige STOPPED sin llamadas abiertas bajo lock, para no
eliminar el contexto de un intento que todavía timbra. Si answered_at cambia
mientras cierra el padre, fallback al closer contestado conserva el cierre.
Alcance: Dialer.php, tracking AGI, dialer_agent.php, pruebas, README/CONTRACT y
registros. Prohibido tocar implementaciones core, bot_ivr, VICIdial o activar
PJSIP. Artefactos Asterisk se regeneran exclusivamente por API pública.

## 2026-10-11 — Controles operativos de agente y catálogo de pausas

ARCHITECT_AGENT aprueba cruce core/dialer por APIs públicas. Bootstrap
automático POST+CSRF al entrar al panel, todas las campañas activas asignadas
aunque motor STOPPED; logout aislado POST+CSRF retira principales/personales
antes de destruir sesión. Sidebars cambian únicamente esa salida de sesiones
del marcador. Auth, drivers SIP, PbxAmi y bot_ivr no se modifican.

Estado global durable y eventos de pausa con open_slot UNIQUE por agente;
catálogo admin9 con activar/desactivar, etiqueta histórica copiada. El motivo
y tiempo solicitado se conservan al recargar/tipificar; duración global no
suma campañas compartidas. Pausa no cuelga y personal permanece disponible
durante reserva progresiva ya iniciada. Llamada abierta conserva intervalo
ACTIVE hasta cierre; después se aplica pausa sobre disponibilidad/tipificación.
Las pausas históricas no reconocidas conservan su bloqueo. El estado offline
evita reactivación por closer/worker; fallo AMI muestra intención pendiente.

claimLead bloquea estado del agente y rechaza pausa/logout durable aun cuando
PBX muestre WAITING obsoleto, sin consumir intento. Hangup solo para llamada
propia abierta, correlación exacta ChannelId/canal actual obtenido de AMI;
ningún canal viene del navegador ni se cierra BD anticipadamente. Catálogo,
tres tablas nuevas y permisos propios por migraciones013–014, sin datos legacy.
Cerrar pestaña sigue sin equivaler a Cerrar sesión.

## 2026-10-11 — Dueño administrativo de tipificación MANUAL

ARCHITECT_AGENT aprobó migración015 con disposition_agent_user capturado en
claimLead exclusivamente MANUAL. Pendiente/cliente/autorización/reserva usan
COALESCE(agent_user,disposition_agent_user), con prioridad de agente conectado.
No se atribuye conversación a una reserva que terminó antes de respuesta;
answered_at, bridged_at, duration_talk, grabación y duration_wrapup siguen NULL.
La espera de tipificación bloquea siguientes intentos pero no añade muestra AHT.
Identidad conversacional y reportería conservan agent_user; los modos automáticos
no adquieren dueño administrativo por el solo hecho de reservar un agente.
Snapshot futuro independiente del modo actual; no hay backfill genérico.

Recuperación histórica aprobada para IDs359,361,362,363 exclusivamente, con
campaign55/reserved5000, ended_at existente, agent_user/disposición/dueño NULL.
Evidencia: registros Asterisk manual_ready/manual_closed de los cuatro IDs.
Respaldo previo de filas y PHP obligatorio; sin seleccionar tipificaciones por usuario.
Otros historiales sin evidencia permanecen intactos. La UI muestra número y
resultado del intento y solo pospone recarga si se está editando su disposición.

## 2026-10-11 — Autodial MANUAL opt-in por campaña

Usuario autorizó implementación con "procede" tras precisar que la campaña
seleccionada por el agente determina la continuación. ARCHITECT_AGENT aprobó
migración016 y API/UI/worker del módulo dialer. Flag OFF por defecto; primera
llamada explícita, continuación única a 3 segundos de primera tipificación de
origen MANUAL probado por disposition_agent_user. Selección durable por agente,
sin activación al login o resume. Cambio/pausa/logout/STOP/deshabilitación/retiro
cancelan. Solicitud sin lead/anexo/capacidad o atrasada >30 segundos se consume
sin quedar latente. Reserva y consumo son atómicos; error AMI incierto conserva
cupo para conciliación. Sin dependencia nueva ni cambios a modos automáticos.

Locks campaña→estado de agente→llamada→lead; chequeo global de trabajo es lectura
normal posterior al lock de estado. Revisión arquitectónica descartó FOR UPDATE
en predicado global OR+COALESCE para evitar bloquear llamadas de agentes ajenos.
Fuente se trabaja en E:\servidores\zynerdesk\proyectos\zynervoxv2; runtime mirmidon
por zynertools con respaldo previo y columnas aditivas. Flag de campaña55 queda OFF;
únicas llamadas de verificación en fixtures loopback autorizadas.

## 2026-10-11 — Reporte por lista sin migración

Autorización explícita del usuario: "puedes seguir con la modificación de reporte,
procede". ARCHITECT_AGENT aprobó filtro opcional en kpis/abandonByLevel, API de
desgloses y snapshot global. Runtime confirma FK call_log→lead RESTRICT y cero
huérfanos en campaña55; asociación a lista se consulta mediante EXISTS sin copiar
columnas, modificar importación ni alterar FK. Listas inactivas siguen consultables.

Intentos=cerradas+abiertas; cerradas=tipificadas+cerradas sin tipificar. Tipificación
de fallo no crea conversación: contactos/ventas siguen exigiendo COMPLETED y
catálogo actual. Los desgloses de resultado PBX y disposición comparten lista y
fechas; un snapshot REPEATABLE READ mantiene consistencia durante actividad.
Ocupación por lista N/D, sin inventar asignación de sesión por lista. Totales
por campaña mantienen comportamiento anterior y nuevas claves son aditivas.
Despliegue de dos PHP, sin reinicio del worker ni modificaciones a datos de llamadas.


## 2026-10-12 - Desactivar campañas Bot IVR

El usuario sustituyó eliminación por Desactivar junto a Modificar y dos pestañas activas/inactivas; autorizó con procede. Se reutiliza active, sin migración ni borrado. ARCHITECT_AGENT aprobó ampliar BotIvrRepository con deactivateCampaign y el alcance bot_ivr/zynervox_queries. La operación usa transacción propia y bloqueo exclusivo de campaña, con snapshot posterior para rechazar marcación activa y llamadas abiertas sin invertir locks de productores. Se conservan hijos, auditoría y archivos; no requiere detener TTS ni reiniciar servicios.


## 2026-10-12 - Cancelación de audios Bot IVR

Usuario pidió botón de emergencia y autorizó procede. ARCHITECT_AGENT aprobó helper de detención síncrono en el worker existente, con token por trabajo, control lock independiente y barrera de publicación de identidad. Se conserva pipeline/cache normal y se termina únicamente el árbol propio verificado, evitando PID enviados por browser, señales amplias y carrera entre pestaña vieja y nueva generación. Estado stopped solo después de cierre y lease libre; errores descongelan supervivientes verificados. UI/endpoints conservan admin+CSRF+pertenencia. No afecta telefonía ni otros módulos.
