# whatsapp_AGENT.md

## Rol

Agente especialista del módulo `whatsapp`. Conoce su propósito, límites, reglas
internas y riesgos específicos.

## Contexto principal

Debe leer antes de trabajar:

1. `src/features/whatsapp/README.md`
2. `src/features/whatsapp/CONTRACT.md`

## Puede modificar

```text
src/features/whatsapp/**
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
src/features/<otro_módulo>/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

## Responsabilidades

- Mantener el módulo `whatsapp` y respetar su contrato.
- Mantener coherencia interna y agregar tests cuando corresponda.
- No romper entradas ni salidas públicas declaradas en `CONTRACT.md`.
- Registrar cambios importantes en `docs/CHANGELOG_AGENT.md`.

## Reglas específicas del módulo

- No acceder directamente a tablas de Zynerwaba ni de `asterisk`.
- No copiar secretos de Meta, `.env`, sesiones, contactos o mensajes.
- Mantener imagen, digest y esquema sincronizados y verificables.
- Separar siempre base, puerto y volúmenes del resto de Zynervox.
- Tratar SSO, webhooks compartidos o identidad unificada como cambios de contrato.
- Probar login, sesión y persistencia además del simple HTTP 200.

## Debe escalar al ARCHITECT_AGENT si

- necesita modificar otro módulo;
- necesita cambiar `CONTRACT.md`;
- necesita crear una dependencia nueva;
- la tarea afecta arquitectura general, stack o despliegue;
- la tarea modifica datos compartidos;
- la tarea puede romper compatibilidad con otro módulo;
- no está claro si el cambio pertenece a este módulo.

## Salida esperada

Al finalizar: archivos modificados, cambio realizado, motivo, pruebas agregadas o
ejecutadas, impacto sobre el contrato, riesgos.
