# Auditoría del 2026-10-17

Comparación del repositorio contra el despliegue de `zynervoxv2`, y
reconciliación de los quince contratos de `src/features/`.

Alcance: `/opt/zynervoxv2-fuente` y `/srv/www/htdocs/zynervoxv2`. Trabajo de
sólo lectura: **no se modificó producción, ni servicios, ni base de datos**.
`zynervoxv2205` queda fuera de alcance por indicación del usuario (excepción
de migración), salvo donde `zynervoxv2` depende de él, que se documenta.

Método: cinco análisis en paralelo sobre los contratos y su código, más una
comparación archivo por archivo entre fuente y docroot. Los contratos suman
unas 15.000 palabras en 179 secciones; son append-only y dieciséis secciones
anulan parcialmente a otras anteriores, así que hubo que reconstruir qué rige
hoy antes de poder comparar.

---

## 1. Defectos con consecuencia operativa

Ninguno fue corregido. Esperan autorización.

### 1.1 XSS almacenado en la consola del agente

`app/web/modules/agc/crm.js` arma la tira de control con `innerHTML` y
concatena dos datos sin escapar:

```js
caja.innerHTML = 'Intento <b>#' + lead.call_id + '</b> · ' + (lead.phone || 's/n')
  + (lead.campaign ? ' · ' + lead.campaign : '')
```

`Dialer::campaignName()` sólo valida longitud (1–120). Un nombre de campaña
con HTML se ejecuta en el navegador de **cada agente** que atienda una llamada
de esa campaña, una y otra vez, porque queda guardado en la base. `lead.phone`
tiene el mismo camino: se valida con expresión regular al **marcar**, no al
importar ni al mostrar.

Rompe la cláusula «el panel renderiza valores mediante `textContent`, sin
HTML» del contrato del dialer. El panel clásico `dialer_agent.php` no tiene un
solo `innerHTML`.

**Arreglo:** reemplazar esos tres `innerHTML` por nodos con `textContent`.

### 1.2 El logout del agente deja al agente en las colas

`app/web/modules/agc/logout.php`:

```php
try { Dialer::agentLogout($usuario); } catch (Throwable $e) { /* se cierra igual */ }
session_destroy();
```

Dos problemas. Es **GET y sin CSRF**: cualquier página puede cerrar la sesión
del agente. Y si Asterisk no responde, el agente **sigue siendo miembro de las
colas** mientras cree que salió; el discador le manda llamadas a un puesto
vacío y esas llamadas se abandonan.

El contrato de core lo prohíbe explícitamente. `app/web/logout.php` hace lo
correcto: POST+CSRF, y ante fallo devuelve 503 conservando la sesión con un
botón para reintentar.

### 1.3 103 archivos escribibles por cualquiera en producción

Modo `-rw-rw-rw-` en el docroot, entre ellos `includes/PbxAmi.php`,
`includes/Phones.php`, `modules/admin/index.php`, `modules/admin/dialplan.php`
y `bot_ivr/list_call_api.php`.

El despliegue no los corrige: `deploy-web.sh` copia sin `--delete` y sin
checksum, comparando tamaño y fecha.

### 1.4 «Play requiere canales iguales a los guardados» sólo se valida en el navegador

El contrato de `bot_ivr` lo promete. El servidor sólo comprueba que el valor
esté en la lista permitida; la igualdad contra `call_channels` se exige
únicamente en JavaScript. Un POST directo con otro valor permitido arranca la
marcación.

### 1.5 La revalidación de cuenta no cubre todos los endpoints

El contrato de core garantiza que desactivar un usuario revoca su acceso al
siguiente request. Eso vale para `Auth::checkAccess`, pero
`modules/admin/campaign_monitor_api.php`, `bot_ivr/auth.php` e
`ivr_builder/auth.php` leen `$_SESSION` directo. Un usuario desactivado
conserva esas APIs mientras viva su sesión.

---

## 2. Fuente contra producción

1048 archivos en común, **48 con contenido distinto**. De esos, 47 tienen la
fuente más nueva: cambios que nunca se desplegaron. Sólo uno tiene producción
más nueva, y es un despliegue incompleto, no una edición en caliente.

### 2.1 El módulo VPN está en el menú y no existe desplegado

```
sidebar.php:73    ['key' => 'vpn', 'href' => 'modules/vpn/index.php', 'built' => true]
IsolatedGate      'modules/vpn/index.php' permitido
producción        No such file or directory
```

Declarado como construido, autorizado a servirse, y sus cuatro archivos nunca
se copiaron. Un clic en «Vpn» da 404.

### 2.2 La función «Reenviar» del constructor de IVR está a medio desplegar

El `index.php` de la fuente carga `assets/js/nodes/forwarding.js` y nombra el
nodo «Reenviar a anexo». En producción ese JavaScript no está y el HTML es el
anterior. Mismo caso con `bot_ivr/forward_headers_agi.php`.

### 2.3 Una aplicación completa en producción que no está en el repositorio

`modules/admin/stt_providers_app/` — 8 archivos, sólo en producción. No es
código huérfano: `modules/admin/stt_providers.php`, que **sí** está
versionado y desplegado, la carga por `require`.

El repositorio depende de código que el repositorio no tiene. Reinstalar desde
cero rompe esa pantalla. Incluye un `config/db.php` con credenciales, que es
probablemente el motivo por el que quedó afuera — pero se dejó la aplicación
entera, no sólo el archivo de credenciales.

**Consecuencia para el instalador:** añadir `--delete` a `deploy-web.sh` la
borraría.

### 2.4 Residuos

25 archivos bajo `modules/admin/crm-assets/retirado.20261017033918/` son
assets de la maqueta retirados durante el trabajo del CRM, dejados en
producción. Inofensivos, pero sobran.

Y el esquema vivo **sí** coincide con el repositorio: se comparó cada columna
de las 18 tablas contra todo el SQL versionado y no hay ninguna aplicada a
mano.

---

## 3. Lo que los contratos ya no describen

### 3.1 Un motor de reintentos completo sin una línea de contrato

Implementado de punta a punta: migración 015, tablas
`zynervox_bot_retry_rules` y `_exclusions`, columnas nuevas en el lead, lógica
en cinco archivos, métodos en la interfaz pública del repositorio.

El contrato de `bot_ivr` sigue diciendo que «queda pendiente de implementar» y
que «ningún campo dispara llamadas por sí mismo». El de `zynervox_queries`
nunca se enmendó. Sólo está documentado en `docs/research/`.

Peor: la decisión de negocio —qué estado es terminal, cuántos intentos, cuándo
reintentar— vive dentro de `zynervox_queries`, cuyo contrato define esa capa
como «conexión y repositorios» y le **prohíbe explícitamente** contener
implementación interna de Bot IVR.

### 3.2 El horario de campaña sí dispara llamadas

El contrato afirma que activo y horario son «configuración persistida, no un
disparador de llamadas». Pero `campaign_scheduler.php` arranca y detiene
marcaciones según la ventana, ejecutado cada 15 segundos por un timer de
systemd, y `list_call_service.php` rechaza Play fuera de la ventana.

### 3.3 Acciones y superficie no contratadas

- `campaign_monitor_api.php` acepta `action=channels`, que muta
  `call_channels` de **todas** las listas activas de la campaña. Las cláusulas
  sólo autorizan Play y Stop.
- `services/pbx.php` ejecuta `sip reload` y `dialplan reload` por AMI. Ninguna
  cláusula de admin lo autoriza.
- `PbxAmi` expone `command()` y `originate()`, no documentados.
- `installer/zynerdesk.sh` implementa `install-control`, no declarada, y
  `check.sh` la trata como obligatoria.
- `campaignMonitor()` devuelve cinco campos no contratados.
- `updateCampaign` creció su lista blanca obligatoria sin enmienda.

### 3.4 La consola del agente no está en ningún contrato

`src/features/dialer/CONTRACT.md` no menciona «agc», «crm», «cascada»,
«jerárquic», «rellamada» ni «transcrip». Describe el panel clásico. Toda la
consola construida en octubre es deuda de contrato reconocida.

### 3.5 Siete endpoints de bot_ivr sin el gate 409

Escriben y leen tablas legacy (`synervox_campaigns`,
`synervox_campaign_processes`, `carsa_initial_survey`, `synervox_bot_agents`)
sin `Config::requireLegacyRuntime()`, a diferencia de sus hermanos. El gate de
aislamiento deja pasar cualquier ruta bajo `bot_ivr/`.

---

## 4. Fronteras mal trazadas

1. **`app/web/includes/` es territorio de nadie.** El contrato de core dice
   que los demás módulos consumen sus clases públicas, pero el directorio
   contiene además `Dialer.php` (dialer), `Carriers.php`/`Dialplans.php`/
   `DialplanOrigins.php` (telefonía, con cláusulas también en core),
   `Reporting.php` y una docena de clases VICIdial que ningún contrato
   reclama. No se puede deducir la propiedad mirando el disco.

2. **`IsolatedGate.php` es donde chocan todos.** Un array con 15 páginas de
   admin, 5 de la consola del agente, 1 de VPN y **dos comodines de prefijo**.
   Cinco cláusulas distintas dicen «permite exactamente» y se contradicen.
   Varias entradas vigentes no figuran en ningún contrato.

3. **Carriers ↔ Dialplans ↔ admin, repartido en tres.** Dependencia circular
   dentro de core, UI partida en dos páginas, y el dialer **escribe**
   dialplans con nombres reservados que `outboundRoute` rechaza y que la
   página de rutas muestra y permite borrar.

4. **El monitor de campañas tiene tres dueños.** El SQL en
   `zynervox_queries`, la caché y el worker en `admin`, la vista en `bot_ivr`
   —que a su vez requiere el helper de admin—, y el worker mezcla además
   datos del dialer. Las cláusulas están duplicadas casi palabra por palabra
   en tres contratos.

5. **La política de reintentos** es de `bot_ivr` por contrato y vive en
   `zynervox_queries`. Ver 3.1.

6. **Servicios › Base de datos cruza tres módulos:** la página es de admin, la
   validación de core, el endpoint de `ivr_builder`, y la cláusula está en
   core. Ese endpoint abre además su propio PDO, fuera de la frontera que
   `zynervox_queries` reclama.

7. **Autorización en tres dialectos:** `Auth::checkAccess` con revalidación,
   los helpers que leen `$_SESSION` en `bot_ivr` e `ivr_builder`, y un chequeo
   en línea en el API del monitor. La garantía de core sólo vale para el
   primero.

8. **Dos logouts con contratos distintos** para la misma sesión. Ver 1.2.

9. **`modules/agc/crm.php` entra a la base a mano**, sin pasar por ninguna
   clase pública ni por el repositorio. Ningún contrato cubre esas consultas.

---

## 5. Módulos que no son lo que su nombre sugiere

| Módulo | Realidad |
|---|---|
| `agent` | **Zombi.** 192 bytes de contrato sin una sola ruta ni firma. Reclama `modules/agente` (4.4 MB congelados, bloqueados con 403 en aislado) y `app/web/agc/` (12 MB de VICIdial). Lo vivo que roza su nombre, `modules/agc/`, es del dialer |
| `reporting` | No son reportes: es un **espejo de leads** a otra base, apagado por defecto. 191 bytes de contrato. 11 de sus 13 consumidores están dentro del módulo congelado |
| `telephony` | El 60% de su contrato legisla sobre código de `bot_ivr`. El directorio que reclama tiene 754 bytes |
| `stt_providers` | Un monolito de vendor de 55 KB que no debe editarse, más 1.4 KB de envoltorio. **Ningún código versionado consume las credenciales que administra** |
| `farm` y `stt_providers` | Sus páginas están **rotas a propósito en el checkout**: hacen `require` de carpetas que crea el instalador |
| `ivr_builder` | Tiene sustancia real y el único contrato con firmas de API, pero su frontera con `bot_ivr` es porosa en ambos sentidos |
| `vpn` | El mejor del grupo: tres lenguajes, dos planos de confianza, ~25 invariantes verificables y los únicos tests propios |

Cuatro módulos (`agent`, `reporting`, `telephony` y casi `vpn` e
`ivr_builder`) **no tienen una sola línea de código** dentro de
`src/features/`: sus directorios sólo contienen `.gitkeep` vacíos. Son
declaraciones de propiedad sobre código que vive en `app/web/`.

---

## 6. Dependencia de `zynervoxv2205`

Fuera de alcance por indicación del usuario, pero hay que dejarlo escrito
porque `zynervoxv2` depende de ello:

- `/etc/zynervox/zynerdesk-control.php` apunta a
  `zynervoxv2205-zynerdesk.service`. La pantalla **Servicios › Zynerdesk de
  `zynervoxv2`** arranca y detiene el daemon de 2205.
- `/etc/zynervox/whatsapp.conf` es **global, no por instancia**. Lo escribió
  la instalación 2205, así que la página de WhatsApp de `zynervoxv2` firma el
  SSO con el secreto de 2205 y habla con su daemon. Incluye el chat embebido
  en la consola del agente.
- Causa estructural: `install.sh` **rechaza** WhatsApp, Zynerdesk, Farm y STT
  en modo aislado. El instalador de v2 nunca pudo instalarlos.

**Dos minas:** `installer/whatsapp.sh init` hace `DROP DATABASE IF EXISTS
zynerwabav2` y `installer/zynerdesk.sh init` hace lo mismo con
`syner_remoteo`. Los nombres están fijos, sin parametrizar por instancia:
ejecutar cualquiera destruye los datos vivos de 2205 aunque se pase otro
nombre de instancia.

---

## 7. Pendientes

Nada de esto se tocó. Ordenado por consecuencia.

| # | Pendiente | Naturaleza |
|---|---|---|
| 1 | XSS en la tira de control (1.1) | Código, 3 líneas |
| 2 | Logout del agente sin CSRF y que no falla cerrado (1.2) | Código |
| 3 | 103 archivos world-writable en producción (1.3) | Permisos |
| 4 | Validar en el servidor la igualdad de canales en Play (1.4) | Código |
| 5 | Revalidación de cuenta en los endpoints que no usan `checkAccess` (1.5) | Código |
| 6 | VPN en el menú sin desplegar (2.1) | Despliegue |
| 7 | «Reenviar» a medio desplegar (2.2) | Despliegue |
| 8 | `stt_providers_app` sin código fuente (2.3) | Decisión: versionar o declarar |
| 9 | Contratar el motor de reintentos y el scheduler (3.1, 3.2) | Escalar |
| 10 | Contratar la consola del agente (3.4) | Escalar |
| 11 | Resolver el perímetro de `telephony` (4.3) | Escalar |
| 12 | Resolver la propiedad del monitor de campañas (4.4) | Escalar |
| 13 | Archivar o redefinir `agent`; fusionar `reporting` en `core` | Escalar |
| 14 | Respaldos `.php.bak` servidos como texto plano en el docroot | Infraestructura |
| 15 | Índice sobre `reserved_agent_user` cuando la tabla crezca | Migración |
| 16 | Sin límite de fecha en la tipificación pendiente | Decisión de negocio |

Los pendientes propios de la consola del agente están además en
`docs/CRM_MODULE.md`, sección «Estado y pendientes».
