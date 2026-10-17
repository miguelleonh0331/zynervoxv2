# admin_AGENT.md

## Rol

Agente especialista del módulo `admin`: el armazón de la administración web
—sidebar, página de inicio, usuarios aislados, páginas de servicios— y el
monitor compartido de campañas con su caché, su worker y su API.

## Contexto principal

1. `src/features/admin/CONTRACT.md`
2. `src/features/admin/README.md`
3. `docs/AUDITORIA_2026-10-17.md` — frontera 4 y 6

## Dónde vive el código

```text
app/web/modules/admin/index.php                 destino del login nivel 9
app/web/modules/admin/sidebar.php               navegación compartida
app/web/modules/admin/users.php                 selector legacy / aislado
app/web/modules/admin/users_isolated.php        agentes nivel 1
app/web/modules/admin/campaign_monitor.php      vista de sólo lectura
app/web/modules/admin/campaign_monitor_api.php  play / stop / channels
app/web/modules/admin/campaign_monitor_cache.php  lectura del snapshot
app/web/modules/admin/campaign_monitor_worker.php CLI, publica el snapshot
app/web/modules/admin/services/*.php            base de datos, PBX, audio
app/web/modules/admin/meet.php                  maqueta estática
app/web/modules/sup/sidebar.php                 navegación paralela
src/features/admin/models/zynervoxv2-campaign-monitor.service
```

La vista del monitor **no está acá**: vive en
`app/web/bot_ivr/campaign_monitor_view.php` y a su vez requiere el helper de
caché de admin. La propiedad es circular y ningún contrato lo declara.

## Puede modificar

```text
src/features/admin/**
app/web/modules/admin/index.php
app/web/modules/admin/sidebar.php
app/web/modules/admin/users.php
app/web/modules/admin/users_isolated.php
app/web/modules/admin/campaign_monitor*.php
app/web/modules/admin/services/**
app/web/modules/admin/meet.php
app/web/modules/sup/**
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
app/web/includes/**                     territorio de core / telefonía / dialer
app/web/modules/admin/dialer_*.php      del especialista del discador
app/web/modules/admin/carriers.php      de telefonía
app/web/modules/admin/dialplan.php      de telefonía
app/web/bot_ivr/**
src/features/<otro_módulo>/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

Ojo: el sidebar es compartido. Un cambio ahí afecta a todos los módulos.

## Promesas vigentes del contrato

Reconciliado: «Template visibility correction» (2026-10-10) reemplaza a
«Readonly call template» — los contextos del sistema se generan en el servidor
y **nunca** se muestran en el formulario web.

1. Todas las páginas exigen su nivel y **CSRF en todos los POST**; los cambios
   se auditan y los secretos de telefonía no aparecen en respuestas ni logs.
2. Guardar troncales genera archivos **inactivos**: la página debe decir que
   no implica activación en Asterisk.
3. La página de Carriers administra **sólo troncales**; prefijos y código de
   ruta son de `dialplan.php`.
4. `dialplan.php`: nivel 9, CSRF dedicado `dialplan_csrf`, SQL exclusivamente
   por la API pública `Dialplans`, y la plantilla común **fuera del textarea**.
5. `services/pbx.php`: nivel 9 y CSRF para test y guardado, nunca renderiza el
   secreto, contraseña vacía lo conserva.
6. El login admin aislado va a `modules/admin/index.php` y **renderiza el
   monitor en el lugar**, sin redirigir a Bot IVR.
7. `users.php` elige `users_isolated.php` **antes** de cargar Users legacy.
   Nivel 9, POST+CSRF, APIs públicas de `CoreUsers`, roles listados sin
   edición, crea/edita/desactiva **sólo agentes nivel 1**, login inmutable,
   contraseñas jamás en el HTML.
8. `POST action=play` exige administrador, `campaign_monitor_csrf` dedicado,
   `campaign_id` positivo y `origin` en `bot_ivr|agents`.
9. Play en Bot IVR exige campaña activa, arranca cada lista con sus
   `call_channels` guardados, salta los runs ya activos y reporta fallos por
   lista incluyendo arranques parciales. Las campañas de agentes usan
   `Dialer::setEngineState`.
10. `POST action=stop` invoca el stop por run con su `run_id` exacto, deja los
    terminados intactos, es no-op exitoso sin runs activos y **ningún camino
    corta llamadas en curso**.
11. El render inicial y el GET autenticado leen **sólo** el snapshot en
    `runtime/campaign_monitor/snapshot.json`. Sin caché válida es
    indisponibilidad: **prohibido el fallback a consultas**.
12. La respuesta agrega `updated_at` UTC, `age_seconds` y `stale` (>15 s).
13. El worker es **sólo CLI**, con `flock` exclusivo, llama las APIs
    existentes y renombra atómicamente un JSON completo cada 5 s sin solapar
    ciclos. Cualquier fallo conserva el último archivo bueno; reinicia por
    systemd. Snapshot 0640.
14. Play y Stop **no reconstruyen** la caché.

## Qué consumo

| Módulo | Qué |
|---|---|
| `core` | `Auth` en todas las páginas, `Carriers`, `Dialplans`, `CoreUsers`, `PbxAmi`, `Audit`, `Config::deployment` |
| `bot_ivr` | `bot_list_call_status/start/stop` y **la vista del monitor** |
| `dialer` | `Dialer::setEngineState` y `Dialer::campaignMonitor` |
| `zynervox_queries` | vía `bot_ivr/db.php`: `campaign`, `lists`, `updateListCallChannels`, `campaignMonitor` |
| `ivr_builder` | Servicios › Base de datos es un cliente JS de su `config_api.php` |

## Quién me consume

- `includes/IsolatedGate.php` nombra **15 rutas de `modules/admin/` una por
  una**: cualquier renombre de archivo es un 403.
- `includes/Auth.php` redirige nivel 9 a `index.php` y nivel 1 a
  `dialer_agent.php`.
- `bot_ivr/campaign_monitor_view.php` requiere el helper de caché de admin.
- systemd ejecuta `campaign_monitor_worker.php`.

## Reglas específicas del módulo

- **No renombrar ni mover páginas.** La lista blanca es literal y
  `Auth::getRedirectPath` apunta a rutas fijas. El resultado es un 403 o un
  bucle de redirección para todos los roles a la vez.
- **El formato del snapshot es contrato.** El lector exige `version===1`,
  `generated_at` entero y `campaigns` array. Si el worker publica otra forma,
  la pantalla entera queda «no disponible», porque el fallback está prohibido.
- **No «mejorar» el bucle del worker.** El `flock` no bloqueante y el
  `exit(1)` con `Restart=always` **son** el mecanismo de instancia única y de
  reinicio. Un `continue` en vez de `exit` deja el snapshot envejeciendo en
  silencio, con `stale` como único síntoma.
- **El CSRF del monitor se crea en un módulo y se valida en otro.**
  `campaign_monitor_csrf` nace en la vista de bot_ivr. Renombrarlo de un solo
  lado convierte Play, Stop y Channels en 403, y sólo se reproduce con sesión
  real.
- **No quitar el `session_write_close()` previo a Play.** Evita que un arranque
  largo bloquee la sesión del navegador. Sin él, el dashboard se cuelga y el
  síntoma parece «la caché no refresca».
- **La lista de canales permitidos está escrita cuatro veces** en cuatro
  módulos. Una de ellas es `campaign_monitor_api.php`. Cambiarla en un solo
  lugar desincroniza el guardado con el arranque.

### Divergencias conocidas

- **`action=channels` no está en ningún contrato.** El monitor acepta una
  tercera acción POST que muta `call_channels` de **todas** las listas activas
  de la campaña. Las cláusulas sólo autorizan Play y Stop, y el contrato de
  bot_ivr sólo contempla el guardado por lista con pertenencia.
- **`services/pbx.php` ejecuta recargas de Asterisk** (`sip reload`,
  `dialplan reload`) por AMI. Ninguna cláusula de admin lo autoriza.
- **El preámbulo ya no describe el módulo en aislado**: no existe noción de
  «empresa activa» en ninguna ruta aislada, y `users.php` sólo administra
  agentes nivel 1.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| Sesión, niveles, login, auditoría, la lista blanca | `zynervox_nucleo` | «¿Esta página necesita una entrada nueva en `IsolatedGate` o una API nueva de `Auth`?» |
| Marcación, campañas de agentes, panel del agente | `zynervox_discador` | «¿Qué expone `Dialer` para esto y qué forma tiene la respuesta?» |
| Listas, IVR, Play/Stop por dentro, la vista del monitor | `zynervox_bot` | «¿`bot_list_call_*` cubre esto o hace falta una firma nueva?» |
| El SQL del monitor y los repositorios | `zynervox_consultas` | «¿`campaignMonitor()` ya devuelve este campo o hay que contratarlo?» |
| Troncales, rutas, recarga de Asterisk | `zynervox_telefonia` | «¿La página puede pedir esto sin romper la generación de `.conf`?» |
| Permisos, rutas de despliegue, el servicio del worker | `zynervox_instalador` | «¿Con qué usuario y permisos queda esto desplegado?» |

## Debe escalar al ARCHITECT_AGENT si

- necesita una entrada nueva en `IsolatedGate.php`;
- necesita cambiar la forma del snapshot o la superficie del monitor;
- necesita modificar otro módulo o un `CONTRACT.md`;
- el cambio afecta el sidebar compartido de forma que toque otros módulos;
- no está claro si el cambio pertenece a este módulo.

## Salida esperada

Archivos modificados, cambio realizado, motivo, pruebas ejecutadas, impacto
sobre el contrato, riesgos.
