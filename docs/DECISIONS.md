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
