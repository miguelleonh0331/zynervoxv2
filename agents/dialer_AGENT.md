# dialer_AGENT.md

## Rol

Agente especialista del módulo `dialer`. Cubre el ciclo de llamada completo: el
motor de marcación y la consola con la que el agente de televentas lo opera.

Son una sola cosa en la práctica. Un fallo que el agente ve en pantalla puede
estar en el navegador o en el motor, y separarlos obliga a escalar en la mitad
de los casos reales.

## Contexto principal

Debe leer antes de trabajar:

1. `src/features/dialer/README.md`
2. `src/features/dialer/CONTRACT.md`
3. `docs/CRM_MODULE.md` — sólo si toca la consola del agente (`modules/agc`).

## Dónde vive el código

El módulo guarda en `src/features/dialer/` su contrato, migraciones y pruebas;
el código ejecutable vive en `app/web/`, como en `zynerdesk`.

```text
app/web/includes/Dialer.php              motor: API estática, único archivo
app/web/modules/agc/crm.php              consola del agente: marcado + acciones JSON
app/web/modules/agc/crm.js               toda la lógica de la consola
app/web/modules/agc/crm.css              hoja propia
app/web/modules/admin/dialer_agent.php   panel de agente clásico
app/web/modules/admin/dialer_campaign_edit.php  campañas y disposiciones
src/features/dialer/models/*.sql         migraciones numeradas
src/features/dialer/tests/               pruebas
```

`Dialer.php` pasa de las cien funciones. Para el ciclo del agente importan seis:

| Función | Qué resuelve |
|---|---|
| `claimLead()` | Reserva y origina. Con `onlyLeadId` es la rellamada dirigida |
| `agentCurrentLead()` | Llamada abierta, o colgada sin tipificar |
| `pendingDispositionForAgent()` | La tipificación que falta |
| `agentHasWork()` | Bloquea nuevas reservas mientras algo quede abierto |
| `setDisposition()` | Cierra el intento y aplica reintentos |
| `dispositionLevel()` / `dispositionParent()` | Jerarquía derivada del código |

## Puede modificar

```text
src/features/dialer/**
app/web/includes/Dialer.php
app/web/modules/agc/**
app/web/modules/admin/dialer_agent.php
app/web/modules/admin/dialer_campaign_edit.php
app/web/modules/admin/dialer_report.php
docs/CRM_MODULE.md
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
src/features/<otro_módulo>/**
app/web/includes/IsolatedGate.php
app/web/modules/admin/sidebar.php
installer/install.sh
installer/bootstrap.sh
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

`IsolatedGate.php` es la lista blanca del despliegue aislado y la comparten
todos los módulos: un archivo `.php` nuevo bajo `modules/` devuelve 403 hasta
que se declara ahí. Alta de entradas, por escalamiento.

## Frontera con `src/features/agent`

Ese módulo reclama `app/web/modules/agente` y `app/web/agc/zynervox.php`, que
es **otra** consola, de una migración anterior. La consola viva de este módulo
es `app/web/modules/agc/` y no está declarada en ningún contrato todavía.

No asumir que "agc" se refiere a lo mismo en ambos lugares. Unificarlo exige
escalar: toca dos contratos.

## Responsabilidades

- Mantener el módulo `dialer` y respetar su contrato.
- Mantener coherencia interna y agregar tests cuando corresponda.
- No romper entradas ni salidas públicas declaradas en `CONTRACT.md`.
- Registrar cambios importantes en `docs/CHANGELOG_AGENT.md`.

## Reglas específicas del módulo

### Trampas que no se ven leyendo el código

- **En llamadas MANUAL, `agent_user` suele venir NULL.** El agente real está en
  `reserved_agent_user` y en `disposition_agent_user`. Buscar las llamadas de un
  agente sólo por `agent_user` devuelve vacío y parece que no trabajó. El motor
  usa `COALESCE(agent_user, disposition_agent_user)` en todas partes; cualquier
  consulta nueva debe hacer lo mismo.
- **Un intento sin tipificar bloquea al agente en todas sus campañas**, no sólo
  en la de esa llamada: `agentHasWork()` lo consulta y `claimLead()` se niega a
  reservar. Ni `pendingDispositionForAgent()` ni la rama de llamada abierta de
  `agentCurrentLead()` tienen límite de fecha, así que un intento abandonado
  (navegador cerrado al colgar) sigue bloqueando días después. Es el
  comportamiento actual, no está decidido si acotarlo.
- **La tipificación pertenece a la llamada, no a la ficha abierta.** Pueden no
  ser la misma: el sondeo mueve la ficha mientras el agente tipifica. Resolver
  siempre contra el `call_id` que se tipificó.
- **La respuesta del discador cierra el ciclo y eso vacía la cascada.** Todo lo
  que haga falta después de confirmar (registros, copias en pantalla) debe
  capturarse *antes* de enviar la petición. Ya costó un fallo que anunciaba
  «no se pudo contactar el discador» con la llamada correctamente tipificada.
- **Nunca envolver el post-proceso en el mismo `catch` que el transporte.** Un
  error local disfrazado de caída de red manda a depurar al lugar equivocado.

### Decisiones del usuario que no se negocian sin consultarle

- **Menos consultas a la base.** Lo fijo (campañas, motivos de pausa, árboles)
  viaja una vez, al conectar. El historial, una sola consulta. De ahí en
  adelante la cartera se mantiene en el navegador con lo que ya trae el sondeo.
  Agregar una consulta por sondeo es una regresión.
- **La tipificación se guarda en el lead y sólo en el lead**, dentro de
  `custom_fields` con `JSON_SET` (`tip_nivel1..3`, `tip_codigo`, `tip_nota`,
  `tip_agente`, `tip_llamada`, `tip_fecha`). Sin tablas nuevas: más adelante
  esto se interconecta con otro software de gestión.
- **Los códigos de disposición son 6 dígitos en 3 tramos de 2** (`010000`,
  `010100`, `010101`). El código *es* la jerarquía: nivel y padre se derivan de
  él, así que `ORDER BY code` ya devuelve el árbol y el navegador lo reconstruye
  sin consultas extra. No introducir una columna `parent_id`.
- **El botón Llamar debe funcionar aunque el agente esté en pausa.** Si el
  cliente pierde señal, tiene que poder devolverle la llamada; el servidor cierra
  la pausa antes de originar.
- **Sin bordes redondeados y con densidad de planilla.** Es un requisito
  explícito de la consola, no una preferencia estética suelta.

### Operación

- PHP **7.4** en producción. No usar sintaxis posterior.
- Nunca la campaña 50 ni la lista 82 para pruebas destructivas.
- Un daemon persistente puede tener PHP cargado en memoria: desplegar no basta,
  hay que reiniciar el worker. El reinicio exige autorización explícita.
- El esquema vivo coincide hoy con el repo. Todo DDL entra como migración
  numerada en `src/features/dialer/models/`, nunca aplicado a mano.

### Cómo verificar la consola sin navegador

```bash
bash src/features/dialer/tests/console/run-console.sh
```

Levanta el marcado real de `crm.php` en un mini-DOM y ejecuta el `crm.js` real
contra un discador simulado. No toca MariaDB, Asterisk ni el despliegue; se
puede correr con el motor en marcha. Al cambiar la consola, agregar la
comprobación correspondiente: la prueba que no afirma nada deja pasar el fallo.

Para inspeccionar la base en lectura, un script de un solo uso ejecutado con
`php -d auto_prepend_file=` (así se evita `IsolatedGate` en CLI). Verificar
contra datos reales antes de cambiar nada: más de una vez el síntoma reportado
no estaba donde parecía.

## Debe escalar al ARCHITECT_AGENT si

- necesita modificar otro módulo;
- necesita cambiar `CONTRACT.md`;
- necesita declarar una entrada nueva en `IsolatedGate.php`;
- necesita crear una dependencia nueva;
- la tarea afecta arquitectura general, stack o despliegue;
- la tarea modifica datos compartidos;
- la tarea puede romper compatibilidad con otro módulo;
- no está claro si el cambio pertenece a este módulo.

## Salida esperada

Al finalizar: archivos modificados, cambio realizado, motivo, pruebas agregadas o
ejecutadas, impacto sobre el contrato, riesgos.
