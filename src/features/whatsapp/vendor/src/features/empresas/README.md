# Módulo: empresas

## Propósito
Gestión multiempresa (tenants): alta, edición, estado, plan, credenciales Meta
cifradas por empresa, auditoría y administración de usuarios administradores.

## Alcance
- Incluye: CRUD de empresas, `empresa_credenciales` (AES-256-GCM con hint), prueba de
  credenciales contra Meta, `empresa_audit`, API de empresas (solo superadmin),
  creación de usuarios `admin` por empresa, gestión de líneas de WhatsApp por empresa.
- No incluye: el consumo de esas credenciales para enviar mensajes (eso es `whatsapp`),
  ni la operación de conversaciones de cada empresa.

## Estructura
- `api/`: rutas `/api/empresas`, credenciales, prueba, líneas.
- `services/`: lógica de tenants y administración de administradores.
- `models/`: consultas a `empresas`, `empresa_credenciales`, `empresa_audit`, `lines`.
- `tests/`: pruebas del módulo.

## Panel privado
`views/empresas/index.html` — panel de superadministración servido fuera de `public/`,
con edición inline de líneas (nombre, Phone Number ID, color, estado), administración
de usuarios `admin` y control del corte automático por salud (`salud_corte_automatico`).

## Decisiones heredadas
- Ninguna API de empresas devuelve secretos: solo `access_token_hint`.
- El `admin` de empresa consulta sus líneas pero no puede crearlas, editarlas ni borrarlas.
- El `superadmin` gestiona usuarios `admin` (no operadores) desde este módulo.
- La migración de arranque es idempotente: solo promueve admin→superadmin si no existe
  ningún superadmin y el admin no pertenece a ninguna empresa.
