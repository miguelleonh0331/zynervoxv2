# telephony_AGENT.md

## Rol

Agente especialista del módulo `telephony`: generación de la configuración de
Asterisk —troncales, dialplans y contextos— a partir de la base, y su recarga
por wrapper sudo.

No es «la capa de telefonía» del sistema. Es el generador que escribe en
`/etc/asterisk` y pide el reload. Es el código con mayor capacidad de tumbar
la central en todo el repositorio.

## Advertencia de perímetro — leer antes de tocar nada

Este módulo tiene **la propiedad mal trazada** y está documentado como tal:

1. Su `CONTRACT.md` dedica el 60% de su texto a la sección «Baresip 7307»,
   cuya implementación **no está en ninguna ruta suya**: vive en
   `app/web/includes/DialplanOrigins.php`, `app/web/bot_ivr/baresip_origin.php`
   y en los modelos de `bot_ivr`. Y `src/features/bot_ivr/CONTRACT.md` tiene su
   **propia versión del mismo acuerdo**.
2. Las cláusulas de `Carriers` y `Dialplans` están además en
   `src/features/core/CONTRACT.md`. Dos contratos legislan el mismo código.
3. El directorio que su README reclama —`asterisk/synervox/modules/asterisk/`—
   tiene **754 bytes**: los `.conf` reales se generan y no se versionan.

Mientras eso no se resuelva, cualquier cambio que toque un contexto de
dialplan **debe escalar**. Ver `docs/AUDITORIA_2026-10-17.md`.

## Contexto principal

1. `src/features/telephony/CONTRACT.md`
2. `src/features/core/CONTRACT.md` — las cláusulas de Carriers y Dialplans
3. `docs/AUDITORIA_2026-10-17.md` — frontera 3

## Dónde vive el código

```text
app/web/includes/Carriers.php         troncales, generación y recarga
app/web/includes/Dialplans.php        rutas de salida sobre v2_dialplans
app/web/includes/DialplanOrigins.php  plantilla común de contextos
app/web/modules/admin/carriers.php    UI de troncales (sólo troncales)
app/web/modules/admin/dialplan.php    UI de rutas
installer/carriers.sh                 instalación del módulo
asterisk/synervox/modules/asterisk/   casi vacío a propósito
```

Rutas y constantes que importan:

| Qué | Dónde |
|---|---|
| Destino de la configuración | `Carriers.php:96` |
| Comando de recarga | `Carriers.php:101` (`sudo zynervox-pjsip-reload.sh`) |
| Regenerar y recargar | `Carriers.php:192-232` |
| Protocolo autoritativo | `Carriers.php:15` |
| Plantilla común inmutable | `DialplanOrigins.php:80-101` |
| Validación de ruta de salida | `Dialplans.php:29-73` |

## Puede modificar

```text
src/features/telephony/**
app/web/includes/Carriers.php
app/web/includes/Dialplans.php
app/web/modules/admin/carriers.php
app/web/modules/admin/dialplan.php
installer/carriers.sh
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
app/web/includes/DialplanOrigins.php     plantilla compartida: escalar
app/web/includes/IsolatedGate.php
src/features/<otro_módulo>/**
app/web/bot_ivr/**
app/web/includes/Dialer.php
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

## Promesas vigentes del contrato

Es el contrato más honesto de los módulos chicos: son invariantes operativas
que restringen cambios de verdad.

1. La recarga se solicita mediante un **wrapper sudo restringido**, nunca
   invocando Asterisk directo.
2. **No se reinicia Asterisk.** Se activa por recarga de dialplan y nada más.
3. SIP/RTP, troncales y secretos son configuración externa por servidor: no se
   versionan.
4. `Carriers::protocol` deriva SIP o PJSIP; el protocolo elegido es
   autoritativo y los bloques mezclados o contradictorios **fallan antes del
   SQL**.
5. Guardar troncales genera archivos **inactivos**: no implica activación en
   Asterisk, y la UI debe decirlo.
6. `regenerateAndReload` conserva **todas** las troncales activas en el
   dialplan; la escritura es por reemplazo atómico archivo por archivo, y los
   archivos **no son una transacción conjunta**.
7. `Dialplans` sobre `v2_dialplans`: un único prefijo numérico principal,
   único global, ceros iniciales preservados, helpers idénticos emitidos una
   sola vez y definiciones distintas rechazadas.
8. `outboundRoute`/`sipRoute` publica sólo dialplan_id, name, dial_prefix,
   protocol, endpoint y outbound_prefix. **Rechaza** los nombres reservados
   `v2dialer_campaign_*`, destinos dinámicos, múltiples `Dial`, sustitución
   ambigua y aplicaciones no reconocidas.
9. La plantilla común nunca se muestra en la web: el formulario sólo renderiza
   `DialplanOrigins::body()`.
10. Del acuerdo Baresip (implementado fuera de este módulo): sin `Answer`
    temprano en el origen, y el `MixMonitor` empieza en el destino saliente.

## Qué consumo

- `Includes\Database::getCoreInstance()` y `Config::deployment('runtime')`.
- `DialplanOrigins::prefixes()` y `body()` desde `Carriers`.
- El wrapper sudo y el directorio de runtime de Asterisk.

## Quién me consume

| Consumidor | Qué usa |
|---|---|
| `includes/Dialplans.php:117` | `Carriers::regenerateAndReload()` |
| `includes/Dialer.php:741` | `Carriers::getById()` |
| `app/web/bot_ivr/list_edit.php:9` | `Carriers::dialOrigins()` |
| `app/web/bot_ivr/list_call_service.php:28` | valida el prefijo contra `dialOrigins()` |
| `modules/admin/carriers.php` | la UI completa |
| `installer/carriers.sh` | instalación |

Y el dialer **escribe** dialplans con nombres reservados `v2dialer_campaign_*`
que `outboundRoute` rechaza explícitamente y que `dialplan.php` muestra y deja
borrar al administrador. Dependencia circular con core: `Dialplans::save`
llama a `Carriers::regenerateAndReload`, y `Carriers::dialOrigins` delega en
`Dialplans::origins`.

## Reglas específicas del módulo

- **No tocar `DialplanOrigins::COMMON`.** `body()` compara el texto **exacto**
  de cada contexto. Reordenar una línea, «limpiar» un `NoOp` o cambiar un path
  AGI hace que todo dialplan guardado con la copia histórica empiece a lanzar
  «el contexto pertenece a la plantilla común», y rompe en cascada
  `Dialplans::save`, `Carriers::regenerateAndReload` y `Dialer::syncDialplan`.
- **No «normalizar» la regex de `sipRoute`.** Es una lista blanca literal de
  aplicaciones. Agregar un `Set()` al dialplan o cambiar el path de un AGI
  invalida rutas válidas, y el marcador reporta «no tiene una salida SIP
  simple» sin más pista.
- **Los nombres de contexto son contrato de hecho.** `v2dialer_campaign_<id>`,
  `zynervoxv2-amd`, `-call-finish`, `-outbound`, `-baresip*` están incrustados
  en el dialer, en bot_ivr y en los `.conf` del servidor.
- **El runtime se valida por ruta real.** `writeConfiguration` exige
  `realpath(dirname) === dirname` y rechaza symlinks. Un bind-mount
  «equivalente» hace que guardar troncales falle aunque el dato ya esté en la
  base.
- Regenerar el archivo **no alcanza**: el dialer recarga por AMI en cada
  create/update/toggle/delete.
- PHP 7.4 en producción.
- Nunca la campaña 50 ni la lista 82 para pruebas destructivas.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| Un contexto de la plantilla común o Baresip 7307 | `zynervox_bot` **y escalar** | «¿Qué contexto exacto necesitás y qué contrato lo cubre?» |
| Marcación, campañas del discador, `v2dialer_campaign_*` | `zynervox_discador` | «¿El dialplan que generás necesita un cambio de ruta o de contexto?» |
| Credenciales AMI, `PbxAmi`, sesión, aislamiento | `zynervox_nucleo` | «¿La recarga pasa por `PbxAmi::command` o por el wrapper sudo?» |
| La pantalla de troncales o de rutas | `zynervox_panel` | «¿El cambio es de presentación o de validación del servidor?» |
| Instalar el módulo, permisos de `/etc/asterisk` | `zynervox_instalador` | «¿Qué deja `carriers.sh` y con qué dueño y modo?» |

## Debe escalar al ARCHITECT_AGENT si

- el cambio toca `DialplanOrigins.php` o cualquier contexto de la plantilla;
- el cambio afecta cláusulas que también están en el contrato de `core`;
- necesita resolver la propiedad del acuerdo Baresip 7307;
- necesita modificar otro módulo o un `CONTRACT.md`;
- la tarea afecta arquitectura, stack o despliegue;
- no está claro si el cambio pertenece a este módulo.

## Salida esperada

Archivos modificados, cambio realizado, motivo, pruebas ejecutadas, impacto
sobre el contrato, riesgos. Si se regeneró configuración de Asterisk, indicar
si quedó recarga pendiente y bajo qué autorización.
