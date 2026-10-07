# zynerdesk_AGENT.md

## Rol

Agente especialista del módulo `zynerdesk`. Conoce su propósito, límites, reglas
internas y riesgos específicos.

## Contexto principal

Debe leer antes de trabajar:

1. `src/features/zynerdesk/README.md`
2. `src/features/zynerdesk/CONTRACT.md`

## Puede modificar

```text
src/features/zynerdesk/**
zynerdesk/**
installer/zynerdesk.sh
installer/apache-zynerdesk.conf.template
app/web/modules/admin/zynerdesk.php
docs/CHANGELOG_AGENT.md
```

El módulo es de despliegue e integración: su código vive fuera de
`src/features/` porque el instalador, el Compose y la vista web deben
convivir con los de los demás módulos. `src/features/zynerdesk/` guarda su
contrato y documentación.

## No puede modificar

```text
src/features/<otro_módulo>/**
app/web/modules/admin/sidebar.php
installer/install.sh
installer/bootstrap.sh
installer/check.sh
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

El sidebar y los instaladores generales son compartidos: tocarlos afecta a
todos los módulos y exige escalar.

## Responsabilidades

- Mantener el módulo `zynerdesk` y respetar su contrato.
- Mantener coherencia interna y agregar tests cuando corresponda.
- No romper entradas ni salidas públicas declaradas en `CONTRACT.md`.
- Registrar cambios importantes en `docs/CHANGELOG_AGENT.md`.

## Reglas específicas del módulo

- No reintroducir Docker, Compose ni una imagen fijada por digest para este
  módulo (ADR-0016). El código vive vendorizado en
  `src/features/zynerdesk/vendor/`; al actualizarlo, documentar el origen
  exacto en `README.md` (igual que se hizo al vendorizar la primera vez).
- `vendor/` es fuente mantenida por este repositorio desde ADR-0018. No
  reemplazarla ni importar otro upstream sin documentar origen y revisión.
  La adaptación específica del shell sigue ocurriendo en `zynerdesk.php`.
- No agregar reglas de reescritura ad-hoc por cadena: la resolución de rutas
  es genérica. Una vista nueva se declara en `$ZYNERDESK_VIEWS` y debería
  funcionar sin tocar el motor de reescritura.
- Antes de culpar al rewrite, comparar contra `http://127.0.0.1:<puerto>/`
  directo: distingue un fallo de integración de uno del propio upstream.
- Las trampas conocidas del upstream (hojas en el `<head>`, paleta en
  `:root`/`body`, base calculada en `supervicion/app.js`) están documentadas
  en `README.md`. Revisarlas en cada actualización de imagen.
- Nunca versionar `/etc/zynervox/zynervox-zynerdesk.env` ni volcar credenciales
  en logs, HTML o mensajes de commit.
- No borrar la base `syner_remoteo` en una actualización.

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
