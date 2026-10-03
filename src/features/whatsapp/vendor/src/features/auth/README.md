# Módulo: auth

## Propósito
Autenticación de usuarios, sesiones persistentes y control de acceso por rol.
Define la identidad sobre la que se apoya el aislamiento multiempresa.

## Alcance
- Incluye: login/logout, `express-session` con store MySQL propio, hash de contraseñas
  (bcryptjs), middlewares `requireUser`/`requireAdmin`/`requireSuperadmin`, resolución de
  contexto de empresa (`X-Empresa-Id` para superadmin), API `/api/me`, `/api/login`,
  `/api/logout`, bootstrap idempotente del superadmin inicial y del usuario `api`.
- No incluye: gestión de usuarios (empresas/crm según operación), ni permisos sobre
  recursos operativos — esos viven en sus módulos y consumen este módulo vía contrato.

## Estructura
- `api/`: rutas HTTP de autenticación.
- `services/`: lógica de sesión, roles y bootstrap.
- `models/`: consultas MySQL a `users`, `sessions`, `user_lines`.
- `tests/`: pruebas del módulo.

## Roles soportados
`superadmin` (sin empresa), `admin`, `supervisor`, `agent` (con `empresa_id`).

## Decisiones heredadas (del proyecto original)
- El bootstrap del superadmin es idempotente y se marca en `app_meta`
  (clave `bootstrap_superadmin`) para no promover admins en cada arranque
  (incidente 2026-08-01 del original).
- Segunda barrera: solo es promovible un admin sin `empresa_id`.
- Invariante: un `superadmin` nunca tiene `empresa_id` (se alerta si se detecta corrupción).
- Las sesiones viven en la base de datos (store propio sobre MySQL), no en memoria:
  sobreviven reinicios.
