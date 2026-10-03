# CONTRATO — auth

## Frontera técnica
- **Ruta propia:** `src/features/auth/**` — único conjunto de archivos que el agente de este módulo puede modificar.
- **Prohibido:** modificar otro módulo, `src/shared/**`, `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/MODULE_MAP.md` o convenciones generales. Cualquier necesidad externa se escala al `ARCHITECT_AGENT`.

## Interfaz pública
| Símbolo | Tipo | Descripción |
|---|---|---|
| `register(ctx)` | función | Monta rutas de sesión y devuelve middlewares de acceso al ctx compartido |

### Lo que este módulo aporta al ctx (para otros módulos, vía contrato)
| Símbolo | Tipo | Descripción |
|---|---|---|
| `requireUser` | middleware | Exige sesión válida; puebla `req.user` (id, role, empresa_id, display_name) |
| `requireAdmin` | middleware | Exige `role` en (`admin`, `superadmin`) |
| `requireSupervisor` | middleware | Exige `role` en (`supervisor`, `admin`, `superadmin`) |
| `requireSuperadmin` | middleware | Exige `role === 'superadmin'` |
| `canAccess(user, contact)` | función | Regla de visibilidad por rol/owner/líneas asignadas |
| `resolveEmpresaId(req)` | función | Empresa efectiva de la petición; `null` para superadmin sin `X-Empresa-Id` |

## Endpoints HTTP
| Método | Ruta (bajo BASE_PATH) | Auth | Descripción |
|---|---|---|---|
| POST | `/api/login` | pública | Login con usuario/contraseña |
| POST | `/api/logout` | sesión | Cierra la sesión |
| GET | `/api/me` | sesión | Datos del usuario autenticado + líneas asignadas |

## Tablas de base de datos propias
| Tabla | Escritura | Lectura |
|---|---|---|
| `users` | bootstrap, cambio de propio | sí |
| `sessions` | store de express-session | sí |
| `user_lines` | no (lo gestionan otros módulos) | sí |
| `app_meta` | clave `bootstrap_superadmin` | sí |

## Invariantes
- Un `superadmin` de plataforma nunca tiene `empresa_id`.
- Sin contexto de empresa, un superadmin recibe `400` en operaciones tenant.
- La cookie de sesión se limita a `BASE_PATH` y su nombre deriva de la app.
- Las contraseñas solo se almacenan con hash bcrypt.
