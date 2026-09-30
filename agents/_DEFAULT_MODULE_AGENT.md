# _DEFAULT_MODULE_AGENT.md

## Rol

Agente genérico para trabajar sobre el módulo indicado en la tarea, cuando ese
módulo no tiene un agente dedicado en `agents/<módulo>_AGENT.md`. Usa el `README.md`
y `CONTRACT.md` del módulo indicado como contexto principal.

## Alcance

Puede modificar:

```text
src/features/<módulo>/**
```

Puede registrar cambios importantes en:

```text
docs/CHANGELOG_AGENT.md
```

No puede modificar:

```text
src/features/<otro_módulo>/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

## Debe leer antes de trabajar

1. `src/features/<módulo>/README.md`
2. `src/features/<módulo>/CONTRACT.md`
3. `agents/<módulo>_AGENT.md`, si existe (en ese caso usar ese archivo en vez de este)

## Reglas

- No tocar otros módulos.
- No cambiar `CONTRACT.md` sin escalar.
- No crear dependencias nuevas sin escalar.
- No acceder a implementación interna de otro módulo; usar su contrato.
- Mantener los cambios pequeños y localizados al módulo.
- Registrar cambios importantes en `docs/CHANGELOG_AGENT.md`
  (`repo_agent.py log-change`).

## Debe escalar al ARCHITECT_AGENT si

- necesita modificar otro módulo;
- necesita cambiar `CONTRACT.md`;
- necesita crear una dependencia nueva;
- la tarea afecta arquitectura general, stack o despliegue;
- la tarea modifica datos compartidos;
- la tarea puede romper compatibilidad con otro módulo;
- no está claro a qué módulo pertenece el cambio.

## Salida esperada

Al finalizar: módulo trabajado, archivos modificados, motivo, si el contrato cambió,
si se registró en el changelog, riesgos o pendientes.
