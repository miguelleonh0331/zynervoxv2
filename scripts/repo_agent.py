#!/usr/bin/env python3
"""Scaffolding y mantenimiento de un sistema repo-nativo de agentes por módulo.

Todo lo que genera este script vive dentro del repositorio del proyecto (--repo).
No usa ningún almacenamiento externo. Las operaciones son mecánicas y deterministas;
el razonamiento (qué tarea es, si debe escalar, etc.) lo hace el agente que invoca
este script, siguiendo SKILL.md.
"""

from __future__ import annotations

import argparse
import re
import shutil
import sys
import unicodedata
from datetime import datetime
from pathlib import Path

RESERVED_AGENT_FILES = {"ARCHITECT_AGENT.md", "_DEFAULT_MODULE_AGENT.md"}
DEFAULT_SUBDIRS = ("api", "services", "models", "tests")
DOC_FILES = (
    "PROJECT_OVERVIEW.md",
    "ARCHITECTURE.md",
    "DECISIONS.md",
    "CHANGELOG_AGENT.md",
    "ROADMAP.md",
    "MODULE_MAP.md",
    "STACK.md",
    "DEPLOYMENT.md",
)


# --------------------------------------------------------------------------
# Utilidades básicas
# --------------------------------------------------------------------------

def now_stamp() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M")


def slug(name: str) -> str:
    display = " ".join(name.strip().split())
    if not display:
        raise ValueError("El nombre no puede estar vacío.")
    normalized = unicodedata.normalize("NFKD", display)
    ascii_name = normalized.encode("ascii", "ignore").decode("ascii")
    value = re.sub(r"[^A-Za-z0-9_ -]+", "", ascii_name)
    value = re.sub(r"[ -]+", "_", value).strip("_").lower()
    if not value:
        raise ValueError("El nombre no contiene caracteres válidos.")
    return value


def backup_dir(repo: Path) -> Path:
    return repo / ".repo-agent-backups"


def write_file(repo: Path, rel_path: str, content: str, force: bool) -> str:
    """Escribe un archivo de forma segura. No sobrescribe sin --force; respalda si sobrescribe."""
    target = repo / rel_path
    if target.exists():
        if not force:
            return f"OMITIDO (ya existe, usa --force para sobrescribir)\t{rel_path}"
        stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
        backup_target = backup_dir(repo) / rel_path.replace("/", "__")
        backup_target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(target, backup_target.with_name(f"{backup_target.name}.{stamp}.bak"))
    target.parent.mkdir(parents=True, exist_ok=True)
    temp = target.with_suffix(target.suffix + ".tmp")
    temp.write_text(content.rstrip() + "\n", encoding="utf-8")
    temp.replace(target)
    return f"{'SOBRESCRITO' if target.exists() and force else 'CREADO'}\t{rel_path}"


def ensure_dir(repo: Path, rel_path: str) -> str:
    target = repo / rel_path
    keep = target / ".gitkeep"
    if target.exists() and keep.exists():
        return f"OMITIDO (ya existe)\t{rel_path}"
    target.mkdir(parents=True, exist_ok=True)
    if not any(target.iterdir()):
        keep.write_text("", encoding="utf-8")
    return f"CREADO\t{rel_path}/"


def module_dir(repo: Path, module: str) -> Path:
    return repo / "src" / "features" / module


def module_agent_file(repo: Path, module: str) -> Path:
    return repo / "agents" / f"{module}_AGENT.md"


def extract_section(text: str, heading: str) -> str:
    """Devuelve el cuerpo (texto crudo) entre '## <heading>' y el siguiente '## '."""
    pattern = re.compile(
        rf"^##\s+{re.escape(heading)}\s*$(.*?)(?=^##\s+|\Z)",
        re.MULTILINE | re.DOTALL,
    )
    match = pattern.search(text)
    return match.group(1).strip() if match else ""


def first_meaningful_line(block: str) -> str:
    for line in block.splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            return line
    return "(sin descripción)"


def extract_bullets(block: str) -> list[str]:
    items = []
    for line in block.splitlines():
        line = line.strip()
        if line.startswith("- "):
            value = line[2:].strip().rstrip(".")
            if value and value.lower() != "pendiente":
                items.append(value)
    return items


def list_modules(repo: Path) -> list[str]:
    features = repo / "src" / "features"
    if not features.is_dir():
        return []
    return sorted(p.name for p in features.iterdir() if p.is_dir())


def list_module_agents(repo: Path) -> list[str]:
    agents = repo / "agents"
    if not agents.is_dir():
        return []
    return sorted(
        p.name for p in agents.glob("*_AGENT.md")
        if p.name not in RESERVED_AGENT_FILES
    )


# --------------------------------------------------------------------------
# Plantillas
# --------------------------------------------------------------------------

def tpl_agents_md(project_name: str) -> str:
    return f"""# AGENTS.md

## Objetivo del proyecto

{project_name} — contexto global del proyecto y reglas generales que deben respetar
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
"""


def tpl_architect_agent() -> str:
    return """# ARCHITECT_AGENT.md

## Rol

Agente global responsable de la coherencia arquitectónica del proyecto. No es un
agente de implementación diaria: decide estructura, límites, contratos y resuelve
los casos que un agente de módulo no debe resolver por su cuenta.

## Responsabilidades

- Crear módulos nuevos (`repo_agent.py create-module`).
- Decidir si un módulo necesita agente dedicado (`repo_agent.py create-agent`).
- Mantener `docs/ARCHITECTURE.md`, `docs/STACK.md` y `docs/DEPLOYMENT.md`.
- Revisar y aprobar cambios de `CONTRACT.md`.
- Definir dependencias permitidas entre módulos.
- Resolver conflictos entre módulos.
- Registrar decisiones en `docs/DECISIONS.md` (`repo_agent.py log-decision`).

## Puede modificar

- `AGENTS.md`
- `agents/` (incluyendo crear agentes dedicados)
- `docs/ARCHITECTURE.md`, `docs/STACK.md`, `docs/DEPLOYMENT.md`, `docs/DECISIONS.md`,
  `docs/ROADMAP.md`
- `src/features/<módulo>/README.md` y `CONTRACT.md` de cualquier módulo
- Estructura de `src/features/`

## No debe hacer por defecto

- Implementar detalles internos extensos de un módulo si puede delegarlo al agente
  de ese módulo.
- Modificar lógica interna sin definir antes contrato y alcance.

## Debe intervenir cuando

- se crea un módulo nuevo o se decide si necesita agente dedicado;
- se modifica un contrato;
- una tarea afecta más de un módulo;
- una tarea requiere una dependencia nueva;
- hay duda sobre a qué módulo pertenece un cambio;
- hay riesgo de romper compatibilidad entre módulos;
- la tarea toca `shared/`, stack o despliegue.

## Salida esperada

Al intervenir debe indicar: módulo(s) afectado(s), decisión tomada, motivo, archivos
permitidos, archivos prohibidos, si cambia contrato, si se registró en
`DECISIONS.md`/`CHANGELOG_AGENT.md`, y qué agente debe continuar.
"""


def tpl_default_module_agent() -> str:
    return """# _DEFAULT_MODULE_AGENT.md

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
"""


def tpl_module_agent(module: str) -> str:
    return f"""# {module}_AGENT.md

## Rol

Agente especialista del módulo `{module}`. Conoce su propósito, límites, reglas
internas y riesgos específicos.

## Contexto principal

Debe leer antes de trabajar:

1. `src/features/{module}/README.md`
2. `src/features/{module}/CONTRACT.md`

## Puede modificar

```text
src/features/{module}/**
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

- Mantener el módulo `{module}` y respetar su contrato.
- Mantener coherencia interna y agregar tests cuando corresponda.
- No romper entradas ni salidas públicas declaradas en `CONTRACT.md`.
- Registrar cambios importantes en `docs/CHANGELOG_AGENT.md`.

## Reglas específicas del módulo

Pendiente de completar por `ARCHITECT_AGENT`.

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
"""


def tpl_module_readme(module: str) -> str:
    return f"""# Módulo: {module}

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

## Notas para agentes

Antes de modificar este módulo, leer en orden:

1. este `README.md`;
2. `CONTRACT.md`;
3. `agents/{module}_AGENT.md` si existe, o `agents/_DEFAULT_MODULE_AGENT.md`.
"""


def tpl_module_contract(module: str) -> str:
    return f"""# CONTRACT.md - {module}

## Responsabilidad contractual

Qué promete este módulo hacia el resto del sistema. Completar.

## Entradas públicas

Pendiente de definir.

## Salidas públicas

Pendiente de definir.

## Errores posibles

Pendiente de definir.

## Dependencias permitidas

Este módulo puede depender de:

- pendiente.

## Dependencias prohibidas

Este módulo no debe depender de:

- implementación interna de otros módulos;
- módulos no declarados en "Dependencias permitidas";
- dependencias externas no aprobadas por `ARCHITECT_AGENT`.

## Garantías

Este módulo garantiza que:

- pendiente.

## Prohibiciones

Este módulo no debe:

- modificar otros módulos;
- acceder a datos ajenos sin pasar por su contrato;
- romper compatibilidad sin una decisión registrada.

## Cambios de contrato

Cualquier cambio en este archivo debe:

1. escalar a `ARCHITECT_AGENT`;
2. registrarse en `docs/DECISIONS.md` (`repo_agent.py log-decision`);
3. registrar el impacto en `docs/CHANGELOG_AGENT.md` (`repo_agent.py log-change`).
"""


def tpl_project_overview(project_name: str) -> str:
    return f"""# PROJECT_OVERVIEW.md

# {project_name}

- Última actualización: {now_stamp()}
- Estado: activo

## Objetivo

Pendiente de completar.

## Alcance

Pendiente de completar.

## Módulos principales

Ver `docs/MODULE_MAP.md`.
"""


def tpl_architecture() -> str:
    return """# ARCHITECTURE.md

## Visión general

Pendiente de completar por `ARCHITECT_AGENT`.

## Capas

Pendiente: describir capas (interfaz, aplicación, dominio, infraestructura) si aplica.

## Módulos

Ver `docs/MODULE_MAP.md` (autogenerado).

## Decisiones arquitectónicas relevantes

Ver `docs/DECISIONS.md`.
"""


def tpl_decisions() -> str:
    return """# DECISIONS.md

Registro append-only de decisiones técnicas importantes.

No reescribir entradas antiguas. Agregar nuevas entradas al final con
`repo_agent.py log-decision`.

## Formato

### ADR-0001 - Título de la decisión

Fecha: YYYY-MM-DD

Estado: propuesta | aceptada | reemplazada | descartada

Contexto:
Explicar el problema o situación.

Decisión:
Explicar qué se decidió.

Motivo:
Explicar por qué.

Alternativas evaluadas:
- alternativa 1

Impacto:
- módulos afectados
- contratos afectados
- riesgos

Seguimiento:
Pendientes o validaciones futuras.
"""


def tpl_changelog() -> str:
    return """# CHANGELOG_AGENT.md

Historial append-only de cambios hechos por agentes.

No reescribir entradas antiguas. Agregar nuevas entradas al final con
`repo_agent.py log-change`.

## Formato

### YYYY-MM-DD HH:mm - <agente> - <módulo>

Tipo: fix | feature | refactor | docs | test | chore

Resumen:
Qué se cambió.

Motivo:
Por qué se cambió.

Archivos modificados:
- ruta/archivo

Contrato:
- sin cambios
- modificado con ADR-XXXX

Riesgos:
- ninguno conocido
"""


def tpl_roadmap() -> str:
    return """# ROADMAP.md

Pendientes y próximos pasos del proyecto, a alto nivel.

## En curso

- pendiente.

## Próximo

- pendiente.

## Ideas / backlog

- pendiente.
"""


def tpl_stack() -> str:
    return """# STACK.md

Tecnologías y versiones que usa el proyecto. Mantenido por `ARCHITECT_AGENT`.

## Lenguajes y runtime

- pendiente (ej. Node.js 20.x, Python 3.12).

## Frameworks

- pendiente (ej. React 18, Express 4).

## Base de datos

- pendiente (ej. PostgreSQL 16).

## Infraestructura / despliegue

- pendiente (ej. Docker, Docker Compose).

## Dependencias clave con versión fija

- pendiente.

## Notas de compatibilidad

- pendiente.
"""


def tpl_deployment() -> str:
    return """# DEPLOYMENT.md

Paso a paso de despliegue. Mantenido por `ARCHITECT_AGENT`.

## Requisitos previos

- pendiente (variables de entorno, accesos, software instalado).

## Variables de entorno

| Variable | Descripción | Obligatoria |
|---|---|---|
| pendiente | pendiente | sí/no |

No colocar valores reales de secretos aquí. Indicar solo dónde están configurados.

## Pasos de build

1. pendiente.

## Pasos de despliegue

1. pendiente.

## Verificación post-despliegue

- pendiente.

## Rollback

- pendiente.
"""


# --------------------------------------------------------------------------
# Comandos
# --------------------------------------------------------------------------

def cmd_init(repo: Path, name: str, force: bool) -> int:
    results = []
    results.append(write_file(repo, "AGENTS.md", tpl_agents_md(name), force))
    results.append(write_file(repo, "agents/ARCHITECT_AGENT.md", tpl_architect_agent(), force))
    results.append(write_file(repo, "agents/_DEFAULT_MODULE_AGENT.md", tpl_default_module_agent(), force))
    results.append(write_file(repo, "docs/PROJECT_OVERVIEW.md", tpl_project_overview(name), force))
    results.append(write_file(repo, "docs/ARCHITECTURE.md", tpl_architecture(), force))
    results.append(write_file(repo, "docs/DECISIONS.md", tpl_decisions(), force))
    results.append(write_file(repo, "docs/CHANGELOG_AGENT.md", tpl_changelog(), force))
    results.append(write_file(repo, "docs/ROADMAP.md", tpl_roadmap(), force))
    results.append(write_file(repo, "docs/STACK.md", tpl_stack(), force))
    results.append(write_file(repo, "docs/DEPLOYMENT.md", tpl_deployment(), force))
    for line in results:
        print(line)
    return cmd_map(repo)


def cmd_create_module(repo: Path, name: str, force: bool) -> int:
    module = slug(name)
    results = []
    results.append(write_file(repo, f"src/features/{module}/README.md", tpl_module_readme(module), force))
    results.append(write_file(repo, f"src/features/{module}/CONTRACT.md", tpl_module_contract(module), force))
    for sub in DEFAULT_SUBDIRS:
        results.append(ensure_dir(repo, f"src/features/{module}/{sub}"))
    for line in results:
        print(line)
    return cmd_map(repo)


def cmd_create_agent(repo: Path, module_name: str, force: bool) -> int:
    module = slug(module_name)
    if not module_dir(repo, module).is_dir():
        print(f"ERROR\tEl módulo '{module}' no existe. Créalo primero con create-module.", file=sys.stderr)
        return 1
    result = write_file(repo, f"agents/{module}_AGENT.md", tpl_module_agent(module), force)
    print(result)
    return cmd_map(repo)


def cmd_map(repo: Path) -> int:
    lines: list[str] = []
    build_module_map_lines(repo, lines)
    write_file(repo, "docs/MODULE_MAP.md", "\n".join(lines), force=True)
    print("REGENERADO\tdocs/MODULE_MAP.md")
    return 0


def cmd_validate(repo: Path) -> int:
    problems = []
    modules = list_modules(repo)

    for module in modules:
        mdir = module_dir(repo, module)
        if not (mdir / "README.md").is_file():
            problems.append(f"falta README.md en src/features/{module}")
        if not (mdir / "CONTRACT.md").is_file():
            problems.append(f"falta CONTRACT.md en src/features/{module}")
        for sub in DEFAULT_SUBDIRS:
            if not (mdir / sub).is_dir():
                problems.append(f"falta subcarpeta '{sub}' en src/features/{module}")

    module_set = set(modules)
    for agent_name in list_module_agents(repo):
        owner = agent_name[: -len("_AGENT.md")]
        if owner not in module_set:
            problems.append(f"agente huérfano: agents/{agent_name} no corresponde a ningún módulo existente")

    map_path = repo / "docs" / "MODULE_MAP.md"
    if not map_path.is_file():
        problems.append("falta docs/MODULE_MAP.md (ejecuta 'map')")
    else:
        current = map_path.read_text(encoding="utf-8")
        current_no_stamp = re.sub(r"^Última generación:.*$", "", current, flags=re.MULTILINE)
        # Genera el contenido esperado sin escribirlo, comparando solo la forma.
        expected_lines: list[str] = []
        build_module_map_lines(repo, expected_lines)
        expected = "\n".join(expected_lines)
        expected_no_stamp = re.sub(r"^Última generación:.*$", "", expected, flags=re.MULTILINE)
        if current_no_stamp.strip() != expected_no_stamp.strip():
            problems.append("docs/MODULE_MAP.md está desactualizado (ejecuta 'map')")

    for doc in ("DECISIONS.md", "CHANGELOG_AGENT.md", "STACK.md", "DEPLOYMENT.md"):
        if not (repo / "docs" / doc).is_file():
            problems.append(f"falta docs/{doc}")

    if not (repo / "AGENTS.md").is_file():
        problems.append("falta AGENTS.md en la raíz del repo")
    if not (repo / "agents" / "ARCHITECT_AGENT.md").is_file():
        problems.append("falta agents/ARCHITECT_AGENT.md")
    if not (repo / "agents" / "_DEFAULT_MODULE_AGENT.md").is_file():
        problems.append("falta agents/_DEFAULT_MODULE_AGENT.md")

    if not problems:
        print("OK\tsin problemas encontrados")
        return 0
    for problem in problems:
        print(f"WARN\t{problem}")
    print(f"WARN\t{len(problems)} problema(s) encontrado(s)")
    return 1


def build_module_map_lines(repo: Path, out_lines: list[str]) -> None:
    """Genera el contenido de MODULE_MAP.md en memoria. Usado por 'map' y por 'validate'."""
    modules = list_modules(repo)
    lines = [
        "# MODULE_MAP.md",
        "",
        "Archivo autogenerado por `repo_agent.py map`. No editar a mano.",
        "",
        f"Última generación: {now_stamp()}",
        "",
        "## Módulos",
        "",
    ]
    if not modules:
        lines.append("_No hay módulos creados todavía. Usa `create-module` para agregar el primero._")
    else:
        lines.append("| Módulo | Ruta | Agente | Propósito | Contrato |")
        lines.append("|---|---|---|---|---|")

    dep_rows: list[tuple[str, str, str]] = []
    module_set = set(modules)
    for module in modules:
        mdir = module_dir(repo, module)
        readme = mdir / "README.md"
        contract = mdir / "CONTRACT.md"
        purpose = "(sin descripción)"
        if readme.is_file():
            block = extract_section(readme.read_text(encoding="utf-8"), "Propósito")
            purpose = first_meaningful_line(block) if block else purpose
        deps: list[str] = []
        if contract.is_file():
            block = extract_section(contract.read_text(encoding="utf-8"), "Dependencias permitidas")
            deps = extract_bullets(block)
        agent_file = module_agent_file(repo, module)
        agent_name = f"{module}_AGENT.md" if agent_file.is_file() else "_DEFAULT_MODULE_AGENT.md"
        contract_rel = f"src/features/{module}/CONTRACT.md"
        purpose_cell = purpose.replace("|", "\\|")
        lines.append(f"| {module} | src/features/{module} | {agent_name} | {purpose_cell} | {contract_rel} |")
        for dep in deps:
            status = "ok" if dep in module_set else "módulo inexistente"
            dep_rows.append((module, dep, status))

    lines += ["", "## Dependencias entre módulos", ""]
    if not dep_rows:
        lines.append("_Ningún módulo declara dependencias todavía en su CONTRACT.md._")
    else:
        lines.append("| Módulo | Depende de | Estado |")
        lines.append("|---|---|---|")
        for module, dep, status in dep_rows:
            lines.append(f"| {module} | {dep} | {status} |")

    out_lines.extend(lines)


def next_adr_id(text: str) -> str:
    ids = [int(match) for match in re.findall(r"ADR-(\d+)", text)]
    return f"ADR-{(max(ids) + 1) if ids else 1:04d}"


def cmd_log_decision(repo: Path, args: argparse.Namespace) -> int:
    path = repo / "docs" / "DECISIONS.md"
    if not path.is_file():
        print("ERROR\tNo existe docs/DECISIONS.md. Ejecuta 'init' primero.", file=sys.stderr)
        return 1
    existing = path.read_text(encoding="utf-8")
    adr_id = next_adr_id(existing)
    alternatives = "\n".join(f"- {item}" for item in (args.alternatives or []) if item) or "- (ninguna registrada)"
    entry = f"""

### {adr_id} - {args.title}

Fecha: {datetime.now().strftime('%Y-%m-%d')}

Estado: {args.status}

Contexto:
{args.context}

Decisión:
{args.decision}

Motivo:
{args.reason}

Alternativas evaluadas:
{alternatives}

Impacto:
{args.impact or '(pendiente)'}

Seguimiento:
{args.follow_up or '(ninguno)'}
"""
    path.write_text(existing.rstrip() + "\n\n" + entry.strip() + "\n", encoding="utf-8")
    print(f"REGISTRADO\t{adr_id}\tdocs/DECISIONS.md")
    return 0


def cmd_log_change(repo: Path, args: argparse.Namespace) -> int:
    path = repo / "docs" / "CHANGELOG_AGENT.md"
    if not path.is_file():
        print("ERROR\tNo existe docs/CHANGELOG_AGENT.md. Ejecuta 'init' primero.", file=sys.stderr)
        return 1
    existing = path.read_text(encoding="utf-8")
    files = "\n".join(f"- {item}" for item in (args.files or []) if item) or "- (sin archivos indicados)"
    module = args.module or "(global)"
    contract = f"modificado con {args.adr}" if args.adr else "sin cambios"
    entry = f"""

### {datetime.now().strftime('%Y-%m-%d %H:%M')} - {args.agent} - {module}

Tipo: {args.type}

Resumen:
{args.summary}

Motivo:
{args.reason}

Archivos modificados:
{files}

Contrato:
- {contract}

Riesgos:
{args.risk or '- ninguno conocido'}
"""
    path.write_text(existing.rstrip() + "\n\n" + entry.strip() + "\n", encoding="utf-8")
    print(f"REGISTRADO\tdocs/CHANGELOG_AGENT.md")
    return 0


# --------------------------------------------------------------------------
# CLI
# --------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Sistema repo-nativo de agentes por módulo, orquestado por contratos."
    )
    sub = parser.add_subparsers(dest="command", required=True)

    p_init = sub.add_parser("init", help="Crea la estructura base en el repo.")
    p_init.add_argument("--repo", type=Path, required=True)
    p_init.add_argument("--name", required=True, help="Nombre del proyecto.")
    p_init.add_argument("--force", action="store_true")

    p_mod = sub.add_parser("create-module", help="Crea un módulo nuevo (scaffold atómico).")
    p_mod.add_argument("--repo", type=Path, required=True)
    p_mod.add_argument("--name", required=True)
    p_mod.add_argument("--force", action="store_true")

    p_agent = sub.add_parser("create-agent", help="Crea un agente dedicado para un módulo existente.")
    p_agent.add_argument("--repo", type=Path, required=True)
    p_agent.add_argument("--module", required=True)
    p_agent.add_argument("--force", action="store_true")

    p_map = sub.add_parser("map", help="Regenera docs/MODULE_MAP.md leyendo src/features/*.")
    p_map.add_argument("--repo", type=Path, required=True)

    p_validate = sub.add_parser("validate", help="Valida consistencia de la estructura.")
    p_validate.add_argument("--repo", type=Path, required=True)

    p_decision = sub.add_parser("log-decision", help="Agrega una entrada a docs/DECISIONS.md.")
    p_decision.add_argument("--repo", type=Path, required=True)
    p_decision.add_argument("--title", required=True)
    p_decision.add_argument("--status", default="aceptada",
                             choices=["propuesta", "aceptada", "reemplazada", "descartada"])
    p_decision.add_argument("--context", required=True)
    p_decision.add_argument("--decision", required=True)
    p_decision.add_argument("--reason", required=True)
    p_decision.add_argument("--alternatives", action="append")
    p_decision.add_argument("--impact")
    p_decision.add_argument("--follow-up")

    p_change = sub.add_parser("log-change", help="Agrega una entrada a docs/CHANGELOG_AGENT.md.")
    p_change.add_argument("--repo", type=Path, required=True)
    p_change.add_argument("--agent", required=True)
    p_change.add_argument("--module")
    p_change.add_argument("--type", required=True,
                           choices=["fix", "feature", "refactor", "docs", "test", "chore"])
    p_change.add_argument("--summary", required=True)
    p_change.add_argument("--reason", required=True)
    p_change.add_argument("--files", action="append")
    p_change.add_argument("--adr")
    p_change.add_argument("--risk")

    args = parser.parse_args()

    try:
        repo = args.repo.resolve()
        if args.command == "init":
            return cmd_init(repo, args.name, args.force)
        if args.command == "create-module":
            return cmd_create_module(repo, args.name, args.force)
        if args.command == "create-agent":
            return cmd_create_agent(repo, args.module, args.force)
        if args.command == "map":
            return cmd_map(repo)
        if args.command == "validate":
            return cmd_validate(repo)
        if args.command == "log-decision":
            return cmd_log_decision(repo, args)
        if args.command == "log-change":
            return cmd_log_change(repo, args)
        parser.error("comando desconocido")
        return 2
    except (OSError, ValueError) as error:
        print(f"ERROR\t{error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
