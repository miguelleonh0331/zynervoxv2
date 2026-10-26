# Entrantes (inbound)

Decisión de arquitectura: `ADR-0025` en `docs/DECISIONS.md`. Bitácora de cambios:
`docs/CHANGELOG_AGENT.md`. Módulo responsable: `dialer` (`agents/dialer_AGENT.md`).

Inbound **extiende** el módulo `dialer`; no es un módulo nuevo. Reutiliza leads,
`v2_dialer_call_log`, las colas, la relación agente-campaña y la consola del
agente. Un módulo propio habría duplicado las cinco cosas.

## Qué hace falta fuera de esta aplicación

Declarar un DID en el panel **no** hace que entren llamadas. Falta, en Asterisk y
por fuera del código:

1. Una troncal de entrada registrada con el proveedor. Hoy no hay ninguna de
   Zynervox registrada.
2. Un contexto de dialplan que atienda la llamada y la pase al AGI.

Esta aplicación no escribe `extensions*.conf` ni `sip*.conf`/`pjsip*.conf`. Lo
único que genera para Asterisk es `queues-zynervoxv2.conf`, desde
`Dialer::syncQueues()`, y eso no cambió con inbound.

## El contrato con el dialplan

El AGI es `/srv/www/htdocs/zynervoxv2/modules/admin/dialer_inbound_agi.php`
(fuente: `app/web/modules/admin/dialer_inbound_agi.php`), modo `750 root:www`.
Asterisk corre como `root`, así que puede ejecutarlo.

El orden importa y es el mismo del saliente (`Answer` → `AGI` → `Queue`): la
llamada se registra **antes** de `Queue()`, para que la ficha del cliente esté en
base mientras el teléfono del agente timbra.

| Acción | Argumentos | Qué hace |
|---|---|---|
| `start` | `<did> <telefono> <uniqueid>` | Resuelve el DID, reutiliza o crea el lead, inserta la fila de `call_log` |
| `bridged` | `<call_id> <interfaz>` | Estampa quién atendió. La interfaz es `${MEMBERINTERFACE}` |
| `closed` | `<call_id> <queuestatus> <uniqueid>` | Cierra el intento y deja al agente en wrapup |

`start` deja tres variables de canal:

- `ZV2_INBOUND_READY` — `1` si el DID tenía ruta, `0` si no. Con `0` el dialplan
  decide qué hacer: el AGI no inventa destino.
- `ZV2_INBOUND_CALL_ID` — identificador para los pasos siguientes.
- `ZV2_INBOUND_QUEUE` — cola a la que pasar el canal.

El AGI es *fail-open*: un error suyo no tumba la llamada en curso. En `start`
avisa con `ZV2_INBOUND_READY=0` para que el canal no se mande a una cola que no
se resolvió.

## Modelo de datos

Migración `src/features/dialer/models/017-inbound.sql`, aplicada en
`zynervox_core`. Es aditiva: las filas previas quedaron `direction='OUT'`.

```
v2_dialer_call_log
  direction     ENUM('OUT','IN') NOT NULL DEFAULT 'OUT'
  inbound_did   VARCHAR(20) NULL
  INDEX dialer_inbound_did (direction,inbound_did,originated_at)

v2_dialer_inbound_dids
  did      VARCHAR(20) PK
  list_id  FK → v2_dialer_lists
  name, active, created_at, updated_at
```

**No hay tabla de colas entrantes, y es deliberado.** El DID apunta a una lista;
la campaña de esa lista aporta la cola. El formato `v2dialer_<id>` ya lo valida
`Dialer::dialplanBody()` por expresión regular, y `agentLogin()` ya mete al
agente en esa cola. Una tabla aparte habría que mantenerla sincronizada con
`queues-zynervoxv2.conf` a mano.

**No hay columna de estado de agente, y también es deliberado.** El estado vivo
se sigue leyendo de Asterisk parseando `queue show`. Duplicarlo en base traería
la debilidad conocida de `vicidial_live_agents`: filas rancias cuando el agente
cae sin cerrar sesión.

## El lead

Si el teléfono ya existe como lead, **se reutiliza**, aunque viva en la lista de
otra campaña: el historial es del cliente, no de la lista. Gana el `lead_id` más
antiguo, para que dos entrantes del mismo número no elijan fichas distintas.

Si es un número nuevo, se crea en la lista del DID con estado **`INBOUND`**.
`claimLead()` solo toma `status='NEW'`, así que haber llamado no mete al cliente
en el hopper de salientes. Esa palabra es la única barrera: cambiarla por `NEW`
pondría a todos los que llamaron en cola de marcación.

Consecuencia de combinar las dos reglas: la lista de un DID **no** contiene a
todos los que llamaron a ese número, solo a los que eran nuevos. Para ver todas
las llamadas de un DID hay que consultar `call_log` por `inbound_did`.

## Tipificación

La entrante también se tipifica, y el agente queda en wrapup hasta hacerlo.

Se guarda en `custom_fields` del lead con prefijo **`tipin_`**
(`tipin_nivel1..3`, `tipin_codigo`, `tipin_nota`, `tipin_agente`,
`tipin_llamada`, `tipin_fecha`), separado del `tip_` de la saliente. Sin eso, una
entrante pisaría la tipificación que la campaña saliente había dejado en el
mismo lead reutilizado.

## Cierre

`Dialer::closeInboundCall()` existe aparte de `closeCall()` a propósito:

- `closeCall()` deja el lead en `NEW` cuando no hubo conversación. Reutilizarla
  haría que un cliente entrara al hopper de salientes por haber llamado.
- `closeCall()` dispara el autodial MANUAL, que no tiene relación con una
  entrante.

El lead entrante solo avanza a `CONTACTED`; nunca retrocede a dialable. El
abandono en cola se registra como **`ABANDONED`**, vocabulario propio: `DROPPED`
ya significa abandono de saliente y lo lee el nivel adaptativo.

## Agentes mixtos y blending

Un agente puede estar en una campaña saliente y en una entrante a la vez. Eso ya
funcionaba: la PK de `v2_dialer_agent_campaigns` es compuesta
`(agent_user, campaign_id)` y todo el motor itera `campaignsForAgent()`.

La prioridad de la entrante se aplica en dos puntos:

1. **Agente en conversación.** `bindInboundAgent()` ocupa `agent_slot`, el mutex
   global `UNIQUE` que `agentHasWork()` y `claimLead()` ya consultaban. Con el
   slot tomado el marcador no puede reservarlo. Si el slot ya estaba ocupado la
   entrante no se pierde: queda registrada sin slot y el conflicto se audita
   como `INBOUND_SLOT_BUSY`.
2. **Gente esperando en la cola.** `queuesWithCallersWaiting()` lee los
   encabezados de un solo `queue show` sin argumento (`<cola> has N calls`) y
   `originateNext()` saca de la reparto a los agentes cuya cola entrante tiene
   gente esperando. En `PROGRESSIVE` se los salta como candidatos; en
   `RATIO`/`ADAPTIVE` se los descuenta del conteo que multiplica el nivel.

Ese parseo es intencionalmente estrecho —solo el encabezado, solo colas
`v2dialer_`— y **no toca** el parseo de miembros de `availableAgentsCount()`, del
que dependen la reconciliación y el nivel de marcado. Es el riesgo de rotura nº 2
de la ficha del módulo.

**`MANUAL` queda fuera de la regla de cola, a propósito.** El contrato exige que
el botón Llamar funcione siempre, porque es cómo el agente devuelve la llamada a
un cliente que se cortó.

## La consola del agente

El agente contesta en su softphone; el panel no maneja audio. Lo único que el
panel tiene que hacer es mostrar la ficha a tiempo.

El sondeo iba a 2,5 s con el agente ocupado y a 12 s en reposo. Una entrante no
la pide el agente: con 12 s contestaría y hablaría hasta doce segundos con la
pantalla en blanco. Ahora, si al agente le puede caer una entrante, no baja a
reposo.

El dato viaja **una vez, al conectar**, en `fixed.inbound` de la acción
`bootstrap`. Preguntarlo en cada sondeo sería la consulta extra por sondeo que el
contrato prohíbe explícitamente.

## El panel

`modules/admin/inbound.php`, nivel de acceso 9, declarado en
`IsolatedGate.php`. Administra DIDs: número, nombre, lista destino y activo.
Muestra la cola y la campaña que resultan de la lista elegida.

Borrar un DID con entrantes abiertas está bloqueado. Cambiar el número de un DID
existente es un reemplazo, no un alta: no quedan dos filas para el mismo número.

## Reportería

`kpis`, `reportBreakdown` y `occupancy` están acotados a `direction='OUT'`. Son
indicadores de marcación saliente —ASR, contactabilidad, abandono por nivel— y
mezclarlos con entrantes los volvería ilegibles.

`inFlightCount()` y `recalculateDialLevel()` también están acotados a `OUT`: una
entrante en curso no debe consumir capacidad de marcado ni mover la histéresis
del adaptativo.

`reconcileUnanswered()` no necesitó cambio: ya se limita a canales con prefijo
`zv2dialer-`, y una entrante llega con el `uniqueid` real de Asterisk.

**Pendiente: no hay reportería de entrantes.** Nivel de servicio, abandono en
cola y tiempo de espera están en los datos (`duration_wait` mide la espera real,
porque `answered_at` es cuando atendimos al que llama y `bridged_at` cuando el
agente tomó) pero no hay pantalla que los muestre.

## Pruebas

```bash
bash src/features/dialer/tests/run-inbound.sh          # registro, lead, cierre, DIDs, blending
PATH=/usr/local/bin:$PATH bash src/features/dialer/tests/console/run-console.sh   # cadencia del sondeo
```

`run-inbound.sh` clona el esquema en una base desechable y aplica la `017` ahí
mismo, así que también valida la migración. No toca MariaDB operativa.

## Lo que falta

- Troncal de entrada y contexto de dialplan (fuera de esta aplicación).
- Reportería de entrantes: nivel de servicio, abandono en cola, espera.
- Decidir si un DID debe poder apuntar a un flujo de IVR antes de la cola. Hoy va
  directo a cola.
