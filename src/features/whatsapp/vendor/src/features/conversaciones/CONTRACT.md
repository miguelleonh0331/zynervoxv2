# CONTRATO — conversaciones

## Frontera técnica
- **Ruta propia:** `src/features/conversaciones/**` — único conjunto de archivos que el agente de este módulo puede modificar.
- **Prohibido:** modificar otro módulo, `src/shared/**`, `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/MODULE_MAP.md` o convenciones generales. Cualquier necesidad externa se escala al `ARCHITECT_AGENT`.

## Interfaz pública
| Símbolo | Tipo | Descripción |
|---|---|---|
| `register(ctx)` | función | Monta API de contactos/mensajes y salas Socket.IO operativas |

### Lo que este módulo aporta al ctx (para otros módulos, vía contrato)
| Símbolo | Tipo | Descripción |
|---|---|---|
| `emitContactRefresh(contactId, patch)` | función | Refresca la bandeja de la sala correspondiente en tiempo real |
| `classifyIncoming(contactId, msg)` | función | Clasifica entrante: autoanswer / NO INTERESADO / humano; usado por `whatsapp` |
| `touchContactFromWebhook(...)` | función | Crea/actualiza contacto por mensaje entrante (wa_id + line_id únicos) |

## Endpoints HTTP (resumen; detalle en código)
| Grupo | Rutas | Auth |
|---|---|---|
| Contactos | `/api/contacts`, `/api/contacts/:id`, assign/unassign, folder, snooze, tags | agent+ con `canAccess` |
| Mensajes | `/api/contacts/:id/messages`, envío texto/media, búsqueda interna | agent+ |
| Notas | notas internas con menciones `@usuario` validadas | agent+ |
| Configuración | `/api/quick-replies`, `/api/folders`, `/api/tags`, `/api/auto-replies` | admin/supervisor |
| Etapas/SLA | contadores por etapa, pulso SLA | agent/supervisor/admin |
| Llamadas | señalización WebRTC (`/api/calls/*`) | agent+ |

## Eventos Socket.IO que emite/escucha
| Evento | Sala | Cuándo |
|---|---|---|
| `contact:refresh` y `contacts:refresh` | `empresa:<id>:admins` / `empresa:<id>:user:<uid>` | cambios de contacto/estado; singular legado y plural consumido por la bandeja actual |
| `message:new` | ídem | mensaje entrante/saliente nuevo |
| `typing` | `empresa:<id>:contact:<id>` | indicador de escritura |
| `llamada:entrada` | ídem | señalización de llamada |

## Tablas de base de datos propias
| Tabla | Escritura | Lectura |
|---|---|---|
| `contacts` | CRUD/estados | sí |
| `messages` | insert/update | sí |
| `quick_replies`, `folders`, `tags`, `contact_tags`, `auto_replies` | CRUD | sí |
| `assignment_reservations`, `assignment_audit` | CRUD/insert | sí |
| `calls` | update de estado | sí |

## Invariantes
- `contacts` único por `(phone, line_id)`; todo el modelo filtra por `empresa_id` (a través de la línea).
- `agent` solo ve contactos propios; `supervisor` solo las líneas asignadas en `user_lines`.
- `autoanswer` y bajas no generan alertas ni SLA; el siguiente entrante sí.
- Los archivos se autorizan contra la empresa del mensaje antes de servirse.
