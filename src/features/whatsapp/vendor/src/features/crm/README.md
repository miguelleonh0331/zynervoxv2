# Módulo: crm

## Propósito
Business suite comercial: CRM de contactos/oportunidades con pipeline, tareas, campos
personalizados, segmentos, importación CSV/XLSX, seguimientos, campañas programadas,
métricas, reportes por correo y auditoría con retención.

## Alcance
- Incluye: oportunidades (crear/reactivar/archivar sin borrar historial), tareas,
  campos y segmentos, importaciones, seguimientos, reportes.
- No incluye: los mensajes de conversación (eso es `conversaciones`) ni los envíos
  masivos (`broadcasts`), aunque se apoya en ambos vía contrato.

## Estructura
- `api/`: rutas del business suite.
- `services/`: lógica de pipeline, segmentos y reportes.
- `models/`: acceso a tablas del suite.
- `tests/`: pruebas del módulo.

## Vista
`views/gestion/index.html` — consola adaptable de operación, CRM, automatización,
formularios, integraciones e IA (servida por este módulo junto a `automatizaciones`).

## Decisiones heredadas
- Archivar una oportunidad la saca del pipeline sin eliminar contacto, conversaciones ni
  auditoría; recrear una oportunidad para el mismo contacto la reactiva.
- Importaciones y reportes respetan empresa, rol y líneas asignadas al supervisor.
- La auditoría del suite tiene retención configurable por empresa.
