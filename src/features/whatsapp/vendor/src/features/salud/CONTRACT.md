# CONTRATO — salud

## Frontera técnica
- **Ruta propia:** `src/features/salud/**` — único conjunto de archivos que el agente de este módulo puede modificar.
- **Prohibido:** modificar otro módulo, `src/shared/**`, `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/MODULE_MAP.md` o convenciones generales. Cualquier necesidad externa se escala al `ARCHITECT_AGENT`.

## Interfaz pública
| Símbolo | Tipo | Descripción |
|---|---|---|
| `register(ctx)` | función | Monta API y sondeador periódico de salud |

### Lo que este módulo aporta al ctx (para otros módulos, vía contrato)
| Símbolo | Tipo | Descripción |
|---|---|---|
| `lineSemaphoro(lineId)` | función | Devuelve `verde|ambar|rojo|sin_datos` de la última medición de la línea (lo consume `broadcasts`) |
| `applyWebhookHealthUpdate(evento)` | función | Recibe actualizaciones de calidad/estado desde `whatsapp` en tiempo real |

## Endpoints HTTP
| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| GET | `/api/salud` | admin/supervisor | Semáforo de las líneas de la empresa |
| GET | `/api/salud/:phoneNumberId` | admin/supervisor | Detalle e histórico por número |
| POST | `/api/salud/:phoneNumberId/sondear` | admin | Sondeo manual con cooldown |
| POST | `/api/salud/alertas/:id/resolver` | admin | Cerrar alerta |
| GET | `/api/salud/global` | superadmin | Rejilla global ordenada por gravedad |

## Eventos Socket.IO que emite/escucha
| Evento | Sala | Cuándo |
|---|---|---|
| `salud:update` | `empresa:<id>` | nueva medición o alerta |

## Tablas de base de datos propias
| Tabla | Escritura | Lectura |
|---|---|---|
| `numero_salud` | insert por sondeo/webhook | sí |
| `salud_alertas` | insert/resolver | sí |

## Invariantes
- `semaforo ∈ (verde, ambar, rojo, sin_datos)`; derivado solo de campos oficiales Meta.
- Alertas `nombre_pendiente`/`plantilla_pausada` se auto-resuelven al arrancar si ya no aplican.
- El sondeo nunca crea cortes por sí mismo; solo registra estado y alertas.
