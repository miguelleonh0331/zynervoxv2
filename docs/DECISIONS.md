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
