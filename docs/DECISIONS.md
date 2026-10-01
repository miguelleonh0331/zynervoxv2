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
