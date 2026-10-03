# Módulo: auth

## Propósito

Describir qué hace este módulo.

## Responsabilidad

Este módulo se encarga de:

- pendiente.

## No responsabilidad

Este módulo no se encarga de:

- pendiente.

## Estructura

```text
api/        endpoints, rutas o adaptadores de entrada
services/   lógica del módulo
models/     modelos, entidades o DTOs
tests/      pruebas del módulo
```

## Dependencias principales

- pendiente.

## Casos principales

- pendiente.

## Integración con Zynervox

Cuando Zynerdesk se abre desde Zynervox, la sesión se crea mediante
`POST /api/auth/zynervox-sso`. Las vistas no muestran controles propios de
cierre de sesión: la sesión principal y su salida pertenecen a Zynervox.

El endpoint `POST /api/auth/logout` y `login.html` se conservan únicamente
para acceso directo de recuperación y compatibilidad operativa.

## Notas para agentes

Antes de modificar este módulo, leer en orden:

1. este `README.md`;
2. `CONTRACT.md`;
3. `agents/auth_AGENT.md` si existe, o `agents/_DEFAULT_MODULE_AGENT.md`.
