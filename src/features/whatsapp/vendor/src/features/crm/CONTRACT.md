# CONTRATO — crm

## Frontera técnica
- **Ruta propia:** `src/features/crm/**` — único conjunto de archivos que el agente de este módulo puede modificar.
- **Prohibido:** modificar otro módulo, `src/shared/**`, `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/MODULE_MAP.md` o convenciones generales. Cualquier necesidad externa se escala al `ARCHITECT_AGENT`.

## Interfaz pública
| Símbolo | Tipo | Descripción |
|---|---|---|
| `register(ctx)` | función | Monta la API del business suite (CRM, tareas, segmentos, reportes) |

## Endpoints HTTP (bajo `/api/suite/*`, aislados por empresa y rol)
| Grupo | Descripción |
|---|---|
| Oportunidades | CRUD, pipeline, archivar/reactivar |
| Tareas | CRUD y cierre con auditoría |
| Campos/segmentos | definición y evaluación dinámica |
| Importaciones | CSV/XLSX a contactos/oportunidades |
| Seguimientos | programación y ejecución |
| Reportes | métricas y envío programado por correo |

## Eventos Socket.IO que emite/escucha
| Evento | Sala | Cuándo |
|---|---|---|
| `suite:refresh` | `empresa:<id>:admins` | cambios en oportunidades/tareas |

## Tablas de base de datos propias
| Tabla | Escritura | Lectura |
|---|---|---|
| Suite CRM (oportunidades, tareas, campos, segmentos, importaciones, seguimientos, reportes, auditoría) | CRUD | sí |

## Invariantes
- Toda fila del suite lleva `empresa_id`; nunca se mezclan tenants.
- El archivado no borra datos: es reversible por recreación del registro.
- Reportes por correo requieren SMTP configurado; sin él, se omiten con registro en auditoría.
