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
4. `docs/INBOUND.md` — sólo si toca entrantes: contrato con el dialplan, modelo
   de datos, reglas del lead y del blending. Decisión: `ADR-0025`.
5. `docs/AUDITORIA_2026-10-17.md` — divergencias y defectos abiertos.

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
app/web/modules/admin/dialer_inbound_agi.php    AGI de la llamada entrante
app/web/modules/admin/inbound.php        panel de DIDs de entrada
src/features/dialer/models/*.sql         migraciones numeradas
src/features/dialer/tests/               pruebas
```

Entrantes: `registerInboundCall()`, `bindInboundInterface()`,
`closeInboundCall()`, `queuesWithCallersWaiting()` y el CRUD `inboundDid*`. El
detalle está en `docs/INBOUND.md`; lo que no se ve leyendo el código es que la
cola entrante **es** la de la campaña de la lista del DID, que no hay tabla de
colas ni de presencia de agente, y que el lead entrante nace con estado
`INBOUND` justamente para que `claimLead()` no lo tome.

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
app/web/modules/admin/dialer_inbound_agi.php
app/web/modules/admin/inbound.php
docs/CRM_MODULE.md
docs/INBOUND.md
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

### Promesas del contrato que conviene tener a mano

- Todo vive en `zynervox_core` con prefijo `v2_dialer_`, sólo por
  `getCoreInstance()` y sólo en despliegue aislado. Nunca la base legacy.
- No hay tabla de agentes: la identidad es el usuario Zynervox, el anexo es
  una fila de `v2_phones` leída por `Includes\Phones`, y la asignación la fija
  el administrador.
- Sin ARI ni Redis: todo es MySQL + AMI + AGI. El estado de agentes se obtiene
  **parseando texto** de `queue show` y `core show channels concise`.
- `disposition_agent_user` se captura al reservar, sólo si la campaña es
  MANUAL en ese momento, y se conserva aunque la campaña cambie de modo.
- Un intento MANUAL sin respuesta se cierra conservando el fallo y con
  `agent_user`, `answered_at`, `bridged_at`, `duration_talk` y grabación en
  NULL — pero **igual debe tipificarse**. Esa espera no fabrica wrapup ni AHT.
- Tipificar una llamada vieja **nunca** recicla un intento más nuevo del mismo
  lead.
- MANUAL conecta primero el anexo del agente y omite Queue y AMD aunque la
  campaña tenga AMD. Un fallo de grabación **aborta el puente**.
- `channel_uniqueid` es `zv2dialer-<call_id>` durante todo el intento: es la
  clave de reconciliación y de colgar.
- Cambiar `dial_method` o el dialplan exige motor detenido y ningún intento
  abierto.
- Prioridad de pausa: manual → pendiente de tipificación → reserva →
  disponible. Sólo se revierte la automática, nunca la manual.
- `MACHINE` nunca cuenta como abandono. La reconciliación libera un intento
  **sólo** tras confirmar que el canal ya no existe, y una consulta incompleta
  de canales lanza excepción sin liberar nada.
- Producción es una unidad systemd única con `flock`. El panel no ejecuta
  shell ni `systemctl`.
- Borrar una campaña exige detenida, cero llamadas abiertas y confirmación;
  borra sólo las DNC propias y **conserva los WAV**.
- El panel renderiza valores con `textContent`, **sin HTML**.

### Riesgos de rotura

1. **El nombre de las colas y el hash de la cola personal.** Cambiar el
   prefijo, el algoritmo o la longitud deja agentes en colas huérfanas que
   nadie quita, y los intentos en vuelo marcan a una cola inexistente.
2. **«Normalizar» el parseo de texto de AMI.** Las columnas de `core show
   channels concise`, los literales `paused`, `(Not in use)`,
   `DIALER_RESERVED`, `DIALER_WRAPUP`, y el prefijo `zv2dialer-`. Un `trim` de
   más rompe la reconciliación **en silencio**: los intentos quedan abiertos,
   ocupan capacidad para siempre y el motor deja de marcar sin error visible.
3. **Quitar el `COALESCE` «por claridad».** El agente deja de ver su llamada,
   puede marcar sin tipificar, o recibe «llamada de otro agente» al tipificar
   lo suyo.
4. **Agregar un campo a la lista de campos de campaña.** Se exige valor válido
   para todos salvo tres excepciones: un campo nuevo que el formulario no
   envíe **rompe todo guardado de campaña**, y cada guardado dispara recarga
   de dialplan y colas por AMI.
5. **Añadir una consulta al sondeo de la consola**, o quitar `lead_id` o
   `call_id` del payload: deja sin rellamada y sin tipificación a la cartera
   que el navegador mantiene en memoria. Y `Dialer::campaignMonitor()` debe
   devolver **exactamente la misma forma** que el de bot_ivr, porque se
   concatenan en el mismo array y los pinta un único componente.
6. Escribir fuera de `/run/zynervox-dialer` o del directorio de grabaciones
   falla por el endurecimiento de la unidad systemd.
7. Desplegar clases PHP sin reiniciar el worker deja el daemon con la versión
   vieja en memoria. El reinicio exige autorización.

### Defectos abiertos en este módulo

Detectados el 2026-10-17, **no corregidos**. Ver `docs/AUDITORIA_2026-10-17.md`.

- **XSS almacenado en la tira de control.** `crm.js` arma esa línea con
  `innerHTML` y concatena `lead.campaign` y `lead.phone` sin escapar. El
  nombre de campaña sólo se valida por longitud, así que un administrador
  puede inyectar HTML que se ejecuta en el navegador de cada agente de esa
  campaña. Rompe la promesa «sin HTML» del contrato. El panel clásico no tiene
  un solo `innerHTML`.
- **`modules/agc/logout.php` cierra por GET, sin CSRF, y se traga el fallo de
  `agentLogout`.** El contrato de core prohíbe explícitamente fingir que el
  agente se retiró de las colas: si Asterisk no responde, el agente queda
  recibiendo llamadas en un puesto vacío. El logout de core hace lo contrario:
  POST+CSRF y se niega a cerrar, ofreciendo reintentar.
- **La consola viva no está en el contrato.** `CONTRACT.md` no menciona «agc»,
  «crm», «cascada», «rellamada» ni «transcripción»: describe el panel clásico.
  Toda la consola es deuda de contrato reconocida.
- **`crm.php` llama a `getCoreInstance()` directo** tres veces, saltando el
  guard de base que `Dialer::database()` sí aplica. Mitigado por el gate, pero
  la comprobación nominal no se hace.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| Sesión, niveles, auditoría, la lista blanca, credenciales AMI | `zynervox_nucleo` | «¿Esta ruta está en `IsolatedGate` y qué expone `Auth` para esto?» |
| Troncales, dialplans, contextos, recarga de Asterisk | `zynervox_telefonia` | «¿Puedo pedir esta ruta de salida sin romper la plantilla común?» |
| El monitor de campañas, el sidebar, páginas de administración | `zynervox_panel` | «¿Qué forma espera el snapshot y qué acciones acepta el API?» |
| IVR, listas automáticas, TTS, flujos | `zynervox_bot` | «¿`campaignMonitor()` de tu lado devuelve la misma forma que el mío?» |
| Anexos SIP y la flota de workers | `zynervox_granja` | «¿De dónde sale el anexo que uso para este agente?» |
| Desplegar, permisos, reiniciar el worker | `zynervox_instalador` | «¿Qué hay que desplegar y requiere reinicio del daemon?» |

Traé la respuesta y seguimos.

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
