# CONTRATO — automatizaciones

## Frontera técnica
- **Ruta propia:** `src/features/automatizaciones/**` — único conjunto de archivos que el agente de este módulo puede modificar.
- **Prohibido:** modificar otro módulo, `src/shared/**`, `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/MODULE_MAP.md` o convenciones generales. Cualquier necesidad externa se escala al `ARCHITECT_AGENT`.

## Interfaz pública
| Símbolo | Tipo | Descripción |
|---|---|---|
| `register(ctx)` | función | Monta API de flujos/IA/conectores y sus disparadores |

### Lo que este módulo aporta al ctx (para otros módulos, vía contrato)
| Símbolo | Tipo | Descripción |
|---|---|---|
| `runFlowsForMessage(msg, contacto)` | función | Punto de entrada para que `conversaciones` dispare flujos con cada entrante |

## Endpoints HTTP
| Grupo | Auth | Descripción |
|---|---|---|
| `/api/flows/*` | admin+ | CRUD de flujos por empresa |
| `/api/public-keys/*` | admin+ | Claves de API pública (hash) |
| `/api/v1/*` (pública con clave + rate limit) | clave | Consumo externo controlado |
| `/api/connectors/*` | admin+ | Conectores y webhooks firmados de salida |
| `/api/knowledge/*`, `/api/flows-forms/*` | admin+ | Conocimiento y WhatsApp Flows |
| `/api/ai/*` | admin+ | Configuración IA/Groq por empresa |

## Eventos Socket.IO que emite/escucha
| Evento | Sala | Cuándo |
|---|---|---|
| `automation:ejecutada` | `empresa:<id>:admins` | resultado de flujo o IA |

## Tablas de base de datos propias
| Tabla | Escritura | Lectura |
|---|---|---|
| Suite de automatización (flujos, claves, conectores, conocimiento, ejecuciones, configuración IA) | CRUD | sí |

## Invariantes
- Toda llamada saliente valida el destino resuelto (anti-SSRF) y lleva firma HMAC cuando aplica.
- Los secretos de IA/Groq se cifran con `src/shared/crypto.js`; nunca en claro en respuestas.
- Un flujo nunca modifica tablas de otro módulo directamente: solo vía `ctx` (contratos).
