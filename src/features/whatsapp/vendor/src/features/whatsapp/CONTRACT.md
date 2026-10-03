# CONTRATO — whatsapp

## Frontera técnica
- **Ruta propia:** `src/features/whatsapp/**` — único conjunto de archivos que el agente de este módulo puede modificar.
- **Prohibido:** modificar otro módulo, `src/shared/**`, `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/MODULE_MAP.md` o convenciones generales. Cualquier necesidad externa se escala al `ARCHITECT_AGENT`.

## Interfaz pública
| Símbolo | Tipo | Descripción |
|---|---|---|
| `register(ctx)` | función | Monta webhook y API de plantillas |

### Lo que este módulo aporta al ctx (para otros módulos, vía contrato)
| Símbolo | Tipo | Descripción |
|---|---|---|
| `sendMeta({ line, payload })` | función | Envía un payload a Graph API con las credenciales de la línea y devuelve la respuesta normalizada |
| `downloadMetaMedia(mediaId, line)` | función | Descarga media de Meta y la persiste en `data/media/<empresa_id>/` |
| `assertSendable(contact, user)` | función | Valida ventana de servicio; lanza si requiere override de un solo uso |

## Endpoints HTTP
| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| GET | `/webhook/meta` | verify_token | Validación de suscripción de Meta |
| POST | `/webhook/meta` | firma X-Hub-Signature-256 | Recepción de eventos; delega entrantes a `conversaciones` |
| GET/POST | `/api/plantillas`, detalle, sync | admin+ | Biblioteca y sincronización de plantillas por empresa |

## Eventos Socket.IO que emite/escucha
| Evento | Sala | Cuándo |
|---|---|---|
| `plantilla:estado` | `empresa:<id>` | cambio de estado de plantilla (webhook o sync) |
| `message:status` | ídem | estados enviados/entregado/leído/fallido |

## Tablas de base de datos propias
| Tabla | Escritura | Lectura |
|---|---|---|
| `plantillas` | sync/create | sí |
| `window_overrides` | crear/consumir | sí |
| `lines`, `empresa_credenciales` | no | sí (vía `empresas.credentialsForLine`) |

## Invariantes
- Ningún mensaje entra a la base por este módulo: el entrante lo persiste `conversaciones`
  vía `classifyIncoming`/`touchContactFromWebhook` (fuente única de verdad).
- Firma inválida → 401, comparación en tiempo constante, cuerpo crudo.
- Los secretos de línea salen siempre cifrados en reposo; en memoria solo lo imprescindible.
- Reenvío a terceros (Synaptix) desactivado por defecto; configurable por empresa.
