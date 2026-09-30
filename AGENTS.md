# AGENTS.md

## Objetivo del proyecto

Zynervox v2 — contexto global del proyecto y reglas generales que deben respetar
humanos y agentes de IA. Completar con el objetivo real del sistema.

## Arquitectura general

El proyecto usa una arquitectura modular basada en features. Cada módulo vive en:

```text
src/features/<módulo>/
```

Cada módulo tiene como mínimo:

```text
README.md
CONTRACT.md
api/
services/
models/
tests/
```

Ver también `docs/ARCHITECTURE.md`, `docs/STACK.md` y `docs/MODULE_MAP.md`.

## Sistema de agentes

- `agents/ARCHITECT_AGENT.md`: agente global/transversal. Único punto de escalamiento.
- `agents/_DEFAULT_MODULE_AGENT.md`: agente genérico para módulos simples.
- `agents/<módulo>_AGENT.md`: agente dedicado, solo para módulos complejos, importantes
  o críticos (ver `docs/MODULE_MAP.md` para saber cuál usa cada módulo).

## Reglas generales

1. No modificar código sin identificar primero el módulo afectado.
2. No tocar módulos ajenos al alcance de la tarea.
3. No cambiar `CONTRACT.md` sin escalar al `ARCHITECT_AGENT`.
4. No acceder a implementación interna de otro módulo; consumir solo vía contrato.
5. Todo cambio importante se registra en `docs/CHANGELOG_AGENT.md`.
6. Toda decisión técnica relevante se registra en `docs/DECISIONS.md`.
7. `docs/MODULE_MAP.md` es autogenerado (`repo_agent.py map`); no editar a mano.
8. `docs/DECISIONS.md` y `docs/CHANGELOG_AGENT.md` son append-only.
9. Mantener el contexto mínimo necesario para cada tarea.
10. Ante duda de alcance, escalar al `ARCHITECT_AGENT` en vez de asumir.

## Cuándo leer este archivo

- al iniciar el proyecto por primera vez;
- al crear un módulo nuevo;
- cuando no hay contexto global cargado en la sesión;
- cuando la tarea afecta arquitectura, contratos, stack o despliegue;
- cuando la tarea cruza el límite de un solo módulo.

Para una edición menor dentro de un módulo ya conocido basta con leer:
`src/features/<módulo>/README.md`, `src/features/<módulo>/CONTRACT.md` y
`agents/<módulo>_AGENT.md` (o `agents/_DEFAULT_MODULE_AGENT.md` si no existe uno dedicado).

## Regla principal

Un agente solo trabaja con el contexto necesario para su tarea y nunca modifica fuera
de su alcance sin escalar primero.
