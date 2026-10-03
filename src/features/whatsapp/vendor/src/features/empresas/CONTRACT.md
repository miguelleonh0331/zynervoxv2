# CONTRATO — empresas

## Frontera técnica
- **Ruta propia:** `src/features/empresas/**` — único conjunto de archivos que el agente de este módulo puede modificar.
- **Prohibido:** modificar otro módulo, `src/shared/**`, `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/MODULE_MAP.md` o convenciones generales. Cualquier necesidad externa se escala al `ARCHITECT_AGENT`.

## Interfaz pública
| Símbolo | Tipo | Descripción |
|---|---|---|
| `register(ctx)` | función | Monta la API de empresas, líneas y usuarios admin |

### Lo que este módulo aporta al ctx (para otros módulos, vía contrato)
| Símbolo | Tipo | Descripción |
|---|---|---|
| `credentialsForLine(line)` | función | Devuelve credenciales Meta descifradas para la línea (token, app_secret, verify_token, graph_version, app_id); lanza si no existen |
| `empresaAudit(empresaId, actorId, accion, detalle)` | función | Registra en `empresa_audit` sin valores secretos |

## Endpoints HTTP (todos requieren `superadmin`)
| Método | Ruta | Descripción |
|---|---|---|
| GET / POST | `/api/empresas` | Listar / crear empresas |
| GET / PATCH | `/api/empresas/:id` | Detalle / editar empresa |
| PUT | `/api/empresas/:id/credenciales` | Guardar credenciales Meta cifradas |
| POST | `/api/empresas/:id/probar` | Probar credenciales contra Graph API |
| GET / POST / PATCH / DELETE | `/api/empresas/:id/lines` | Líneas de WhatsApp por empresa |
| GET / POST / PATCH / DELETE | `/api/empresas/:id/admins` | Usuarios `admin` por empresa |

## Eventos Socket.IO que emite/escucha
| Evento | Sala | Cuándo |
|---|---|---|
| (ninguno) | — | Este módulo es administrativo, no emite tiempo real |

## Tablas de base de datos propias
| Tabla | Escritura | Lectura |
|---|---|---|
| `empresas` | CRUD | sí |
| `empresa_credenciales` | CRUD (siempre cifrado) | sí |
| `empresa_audit` | insert | sí |
| `lines` | CRUD por superadmin | sí |
| `users` (rol admin) | CRUD por superadmin | sí |

## Invariantes
- Ninguna respuesta devuelve secretos en claro; solo `access_token_hint` (últimos caracteres).
- Las credenciales se cifran con `src/shared/crypto.js` (AES-256-GCM, clave en `CREDENTIALS_KEY`).
- `X-Empresa-Id` obligatorio para superadmin en operaciones tenant (400 si falta).
- Las líneas son recursos de solo lectura para el rol `admin` de empresa.
