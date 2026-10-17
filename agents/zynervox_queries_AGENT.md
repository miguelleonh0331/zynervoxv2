# zynervox_queries_AGENT.md

## Rol

Agente especialista del módulo `zynervox_queries`: la frontera de conexión y
los repositorios SQL de Bot IVR. Centraliza PDO y las consultas; ninguna
página escribe SQL por su cuenta.

## Contexto principal

1. `src/features/zynervox_queries/CONTRACT.md`
2. `app/web/zynervox_queries/contracts/BotIvrRepository.php` — la interfaz
3. `docs/AUDITORIA_2026-10-17.md` — frontera 5

## Dónde vive el código

```text
app/web/zynervox_queries/factory.php              única entrada pública
app/web/zynervox_queries/connection.php           validación y PDO
app/web/zynervox_queries/contracts/BotIvrRepository.php
app/web/zynervox_queries/mysql/bot_ivr/
    repository.php  campaigns.php  lists.php  leads.php
    calls.php  dial_runs.php  preanswer.php  tts.php
```

Entrada única de consumo: `app/web/bot_ivr/db.php`. Ningún otro archivo del
repositorio requiere `factory.php`.

## Puede modificar

```text
src/features/zynervox_queries/**
app/web/zynervox_queries/**
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
app/web/bot_ivr/**
app/web/includes/**
app/web/modules/**
src/features/<otro_módulo>/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

## Promesas vigentes del contrato

Reconciliado: «Active campaign status summary» (2026-10-10) acota
`campaignMonitor` a campañas con `active=1` y estados desde
`zynervox_bot_list.status`, nunca `dial_status`. «Live dialing channels»
(2026-10-13) permite runs activos y bloquea sólo la lista. Los canales 30, 35
y 40 son aditivos.

1. `factory.php` es la **única** entrada pública, namespace `ZynervoxQueries`.
2. `engine()` admite sólo `mysql`/`mariadb`; ausente es mysql; lo desconocido
   se rechaza **antes** de conectar o de reemplazar secretos.
3. `connect()` valida server, database y user no vacíos y sin `;` ni
   caracteres de control, puerto 1–65535, y fija `ERRMODE_EXCEPTION`,
   `FETCH_ASSOC` y sin emulación de prepares.
4. **Esta capa no fija nombre de base, no guarda secretos y no elige
   fallback.** La política es del consumidor.
5. `updateCampaign` usa una lista blanca fija de columnas y exige **todas**
   las claves. No hay actualización parcial.
6. `deactivateCampaign` sólo pone `active=0`; rechaza runs starting/running/
   stopping y llamadas abiertas; toma únicamente el lock de la campaña; exige
   transacción propia y **rechaza una conexión con transacción abierta**.
7. `updateListCallChannels` valida contra la lista de opciones, bloquea sólo
   la lista perteneciente a la campaña y actualiza únicamente `call_channels`.
8. `importLeads` rechaza filas vacías antes de escribir, bloquea la lista con
   `FOR UPDATE`, borra sólo por `list_id`, inserta por lotes de 100,
   deduplica dentro del archivo y, ante error, **conserva los contactos
   previos**.
9. `searchLeadsByPhone` exige 1–20 dígitos, igualdad enlazada, máximo 100
   filas y **rechaza comodines**.
10. `startCall` crea lead y registra el intento atómicamente; `finishCall` es
    idempotente y conserva la clasificación de preanswer si nunca contestó.
11. `recordCallEvent` es append-only, una sola vez por `event_id`.
12. Recuperación: intentos abiertos de más de 5 minutos con gracia de 2
    minutos de ausencia, marcados `LOST` con evento `RECOVERY` y fin
    desconocido.
13. `ivrCallContext` devuelve contexto sólo si coincide con el intento abierto
    y no existe `IVR_END`; `saveIvrResult` es idempotente con el mismo payload
    y rechaza uno distinto.
14. El estado de negocio sólo se escribe en el lead si es su intento **más
    nuevo**.
15. Un único run activo por lista; `CALL` no es seleccionable.
16. Preanswer acotado a `origin_prefix='7307'`, con evidencia única por
    llamada.
17. `createTtsProvider` sin update ni delete; URL duplicada lanza
    `RuntimeException` **sin detalle SQL**.

## Qué consumo

**Nada de otros módulos, por diseño, y el código lo respeta.** Los únicos
`require_once` son internos. Depende sólo de PHP 7.4+, PDO MySQL y el esquema
administrativo de Bot IVR.

La configuración llega como array desde el consumidor: core está del otro lado
de la frontera y nunca se referencia desde aquí.

## Quién me consume

Uno solo, y total: `app/web/bot_ivr/db.php`.

A través de `bot_ivr_repository()` lo usan las páginas de `bot_ivr` y, de
forma indirecta, `modules/admin/campaign_monitor_api.php` y
`campaign_monitor_worker.php`.

## Reglas específicas del módulo

- **No agregar campos obligatorios a `updateCampaign`.** Exige todas las
  claves de su lista blanca: un campo nuevo rompe la edición de campaña
  entera, sin merge parcial. Ya creció una vez sin enmienda de contrato.
- **La lista de canales permitidos está triplicada**: aquí en dos archivos y
  una tercera vez en `modules/admin/campaign_monitor_api.php`. Agregar un
  valor en dos de los tres produce «Los canales cambiaron. Recarga la pagina.»
- **No tocar el cálculo de `config_json` de un run.** Debe calcularse idéntico
  en `bot_ivr/list_call_service.php` porque el worker compara ambos con
  igualdad estricta al arrancar. Añadir una clave «informativa» aborta cada
  Play. El propio código lo advierte en un comentario.
- **Los literales de estado son contrato de hecho.** `RECOVERY`, `LOST`,
  `CALL`, `AMI_REJECTED`, `PREANSWER_*` están repetidos en seis archivos.
  Un cambio parcial desalinea los contadores del monitor y la recuperación,
  en silencio.
- **No envolver el repositorio en una transacción externa nueva.**
  `deactivateCampaign` rechaza explícitamente una conexión con transacción
  abierta, mientras el resto usa savepoints. Envolver «por prolijidad» rompe
  sólo la desactivación, y sólo en runtime.
- `limit` y `offset` se interpolan en la cadena SQL —validados como enteros
  antes— en dos lugares. No es inyectable hoy, pero no son parámetros
  enlazados: si se tocan, mantener la validación previa.

### Divergencia estructural conocida

**Hay un motor de reintentos completo que esta capa no debería contener.**
`applyRetryDecision` decide negocio —qué estado es terminal, cuántos intentos,
cuándo reintentar— dentro de la capa que el contrato define como «conexión y
repositorios», y cuyas dependencias prohibidas incluyen explícitamente la
implementación interna de Bot IVR. Además:

- `retryRules`, `upsertRetryRule` y `deleteRetryRule` están en la interfaz
  pública sin cláusula de contrato.
- `campaignMonitor()` devuelve cinco campos no contratados.
- La selección de leads de un run ya no es la documentada: filtra por
  exclusión y próxima llamada, con preferencia de estado ponderada.

Ninguna de esas cláusulas se enmendó. Mover la decisión de negocio a `bot_ivr`
—o contratarla aquí— requiere escalar. Ver `docs/AUDITORIA_2026-10-17.md`.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| Qué hace la pantalla, la validación de entrada, el flujo | `zynervox_bot` | «¿Qué firma exacta necesitás del repositorio y con qué campos?» |
| La conexión, los secretos, el marcador de despliegue | `zynervox_nucleo` | «¿Qué devuelve `getBotIvrConfig()` en este despliegue?» |
| El monitor de campañas en pantalla | `zynervox_panel` | «¿Qué campos del snapshot consume la vista hoy?» |
| El esquema de las tablas | `zynervox_bot` **y escalar** | «¿La migración ya existe o hay que crearla numerada?» |

## Debe escalar al ARCHITECT_AGENT si

- necesita cambiar una firma de `BotIvrRepository`;
- necesita mover lógica de negocio hacia o desde `bot_ivr`;
- necesita modificar otro módulo o un `CONTRACT.md`;
- necesita crear una tabla o columna;
- no está claro si el cambio pertenece a esta capa o al consumidor.

## Salida esperada

Archivos modificados, cambio realizado, motivo, pruebas ejecutadas, impacto
sobre la interfaz pública, riesgos.
