# CONTRATO — broadcasts

## Frontera técnica
- **Ruta propia:** `src/features/broadcasts/**` — único conjunto de archivos que el agente de este módulo puede modificar.
- **Prohibido:** modificar otro módulo, `src/shared/**`, `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/MODULE_MAP.md` o convenciones generales. Cualquier necesidad externa se escala al `ARCHITECT_AGENT`.

## Interfaz pública
| Símbolo | Tipo | Descripción |
|---|---|---|
| `register(ctx)` | función | Monta API de campañas/envíos/listas/ADB y el motor de ejecución |

### Lo que este módulo aporta al ctx (para otros módulos, vía contrato)
| Símbolo | Tipo | Descripción |
|---|---|---|
| `runBroadcast(broadcastId)` | función | Ejecuta un envío pendiente usando `whatsapp.sendMeta` por destinatario |
| `markOptout(phone, empresaId, motivo)` | función | Registra baja global (la usa `conversaciones` en NO INTERESADO y la baja manual) |
| `onDeliveryReceipt(waMessageId, estado)` | función | Actualiza métricas de destinatario desde el webhook (delivered/read/replied) |

## Endpoints HTTP (solo admin, salvo indicación)
| Grupo | Rutas |
|---|---|
| Campañas | `/api/campaigns` CRUD, upload de header, activar/archivar |
| Envíos | `/api/broadcasts` crear/pausar/reanudar/cancelar, `/api/broadcast-stats` |
| Listas | `/api/broadcast-lists` CRUD, subir .txt/.xlsx, contactos |
| Bajas | `/api/broadcast-optouts` listar/añadir/quitar |
| ADB | `/api/adb/devices|campaigns|jobs` + endpoints por token de dispositivo |

## Eventos Socket.IO que emite/escucha
| Evento | Sala | Cuándo |
|---|---|---|
| `broadcast:progreso` | `empresa:<id>:admins` | avance de envíos en ejecución |

## Tablas de base de datos propias
| Tabla | Escritura | Lectura |
|---|---|---|
| `campaigns`, `broadcasts`, `broadcast_recipients` | CRUD/update | sí |
| `broadcast_lists`, `broadcast_list_contacts` | CRUD | sí |
| `broadcast_optouts` | insert/delete | sí |
| `adb_devices`, `adb_campaigns`, `adb_jobs` | CRUD | sí |

## Invariantes
- Destinatario único por envío: `UNIQUE(broadcast_id, phone)`.
- Nunca se envía a un teléfono en optout de la empresa; nunca a líneas con salud roja si
  el corte automático está activado.
- Solo `admin`/`superadmin` crean campañas; los dispositivos ADB solo consumen su cola.
- Al reiniciar el servicio, los envíos `running` pasan a `paused` (idempotente).
