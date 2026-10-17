# Módulo CRM

Última actualización: 2026-10-17.

## Qué es hoy

La **consola de Atención Telefónica** del agente de televentas, en estado de
**maqueta**: navegable y con todas sus reglas de negocio activas, pero sin
backend. El estado vive en memoria: recargar la página lo descarta.

No confundir con `src/features/whatsapp/vendor/src/features/crm/` — es un
módulo de otro proyecto vendoreado (business suite de Zynerwaba/WhatsApp),
sin relación con este.

## Origen y alcance actual

Portado desde un proyecto standalone (`crm-escritorio`, fuera de este
repositorio): una app Python con servidor WSGI, SQLite, login y roles
propios, que reproducía en el navegador un escritorio Windows Forms de 14
formularios mediante un motor genérico (`demo.js`) que interpretaba 353
controles en coordenadas absolutas desde un `gui-layout.json` de 7.200
líneas.

De ahí se descartó, por decisión explícita, todo lo que no era la atención
telefónica:

- El servidor Python, su SQLite y su login/roles propios.
- El motor de render WinForms y su `gui-layout.json`, junto con las 21
  imágenes de iconos. El módulo pasó de ~390 KB de assets a ~43 KB.
- Los 13 formularios restantes (Back Office, Trama, Cargar Base, Actualizar
  Campos, Cambio de Estado, Eliminar Gestiones, Referido, Menú, Login…).
- La pestaña **Datos Inconcert** y sus 12 campos de integración con el
  discador (id de llamada, lote, base, agente, proceso, país, área, teléfono
  contactado, user token, anexo, campaña). No queda ninguna referencia.

Lo que sí se conserva es la **lógica**, reimplementada de forma explícita en
`crm.js` (ver "Reglas de negocio").

## Dónde vive cada archivo

Todo bajo `app/web/modules/admin/` (fuente oficial `/opt/zynervoxv2-fuente`,
desplegado a `/srv/www/htdocs/zynervoxv2/modules/admin/`):

```
modules/admin/
├── crm.php                 Página Zynervox: sidebar + layout.css + <iframe> a crm_app.php
├── crm_app.php             La consola (full-page), cargada dentro del iframe
└── crm-assets/
    ├── crm.css             Estilo propio, ~19 KB
    └── crm.js              Estado, render y reglas de negocio, ~51 KB
```

Y el entorno del agente, **autónomo**:

```
modules/agc/
├── crm.php                 Acceso + consola (la misma URL resuelve ambos)
├── login_view.php          Pantalla de credenciales
├── logout.php              Sale de las colas, cierra sesión y vuelve a crm.php
├── crm_wa_session.php      Puente de WhatsApp propio
├── crm.css                 Copia propia + estilos del acceso
└── crm.js                  Copia propia
```

`modules/agc/crm.php` es para quien sólo atiende llamadas: no muestra el menú
de administración, **pide credenciales propias** y **conecta al agente con el
discador** al abrirse.

### Por qué está aislado

El agente nunca sale de `modules/agc/`: ni el acceso, ni la salida, ni los
recursos. Por eso esta carpeta tiene **copias** de `crm.css`, `crm.js` y
`crm_wa_session.php` en vez de referenciar las de `modules/admin/`. Es
duplicación deliberada: el CRM del panel queda como demostración y el del
agente evolucionará hacia datos reales del discador, así que van a divergir.
Mientras no lo hagan, un arreglo en uno hay que replicarlo en el otro.

### Acceso y salida

`crm.php` resuelve las dos situaciones en la misma URL: sin sesión incluye
`login_view.php`, con sesión abre la consola. El formulario publica
`action=login` y autentica con `Auth::login()` — la misma función del panel —
seguido de `session_regenerate_id(true)` y un `303` para que recargar no
reenvíe las credenciales. Un intento fallido responde 401 y espera 300 ms.

No se usa `Auth::checkAccess()` porque redirige al `index.php` del panel; en
su lugar se comprueba la sesión y, en despliegue aislado, se revalida la
cuenta contra la base en cada carga (igual que hace `checkAccess`), de modo
que una cuenta desactivada pierde el acceso sin esperar a que caduque.

`logout.php` cierra primero la sesión del discador con `Dialer::agentLogout()`
—sacando al agente de las colas— y después la de Zynervox, borrando la cookie
y devolviendo a `crm.php`, que volverá a pedir credenciales. Si el discador no
responde la salida continúa igual, para no dejar al agente atrapado.

### Conexión con el discador

La consola repite la acción `bootstrap` de `modules/admin/dialer_agent.php`,
sin tocar ese archivo: al cargar, `crm.js` hace un POST a la propia página con
`action=bootstrap` y el token CSRF, y ésta llama a `Dialer::agentLogin()`, que
valida que el agente tenga anexo SIP activo, lo marca conectado en
`v2_dialer_agent_status` y lo suma a las colas de sus campañas activas.

El resultado se muestra en la barra superior junto al estado de llamada:
verde con el número de campañas, gris mientras conecta, rojo con el motivo si
falla (sin anexo asignado, anexo inactivo, discador caído). Un token inválido
responde 503 y sin sesión de Zynervox se redirige al login.

Sólo ocurre en esta consola: se activa por el atributo `data-crm-dialer` del
`<body>`, que el CRM del panel no declara.

### Control de llamada

El bloque "Control de llamada" de la columna derecha trae las funciones de
`modules/admin/dialer_agent.php`, sin tocar ese archivo:

| Control | Acción | Qué hace |
|---|---|---|
| Campaña para marcar | — | Sólo campañas activas con `dial_method = MANUAL` |
| Marcar siguiente lead | `manual` | `selectManualCampaign()` + `originateNext()` |
| Motivo de pausa · Pausar | `pause` | `agentPauseAll()` con el motivo elegido |
| Reanudar | `unpause` | `agentPauseAll(null)` |
| Colgar | `hangup` | `agentHangup()`; sólo visible con llamada activa |

A diferencia del panel de agente, que recarga la página con formularios, aquí
las acciones van por JSON y **cada respuesta devuelve el estado completo del
puesto** (campañas, motivos, pausa, llamada en curso), así que la interfaz se
repinta con la situación real y no con la que suponía — también tras un
error. El `bootstrap` ya trae ese estado, de modo que el bloque no queda en
blanco esperando al primer sondeo.

En pausa no se puede marcar, y el selector de motivo se oculta dejando sólo
"Reanudar".

### Coste de consultas

El sondeo es por agente y corre todo el turno, así que se cuida lo que pide:

- **Lo fijo viaja una vez.** Campañas del agente y motivos de pausa sólo van
  en el `bootstrap` (`fixed`); repetirlos en cada sondeo era gasto puro. El
  sondeo (`state`) lleva únicamente lo que cambia solo: llamada, pausa,
  tipificación pendiente y autodial.
- **Cadencia adaptativa.** Con llamada viva, tipificación pendiente o cuenta
  regresiva se relee cada 2,5 s; en reposo, cada 12 s. Un agente pasa buena
  parte del turno sin llamada.
- **La cartera no se consulta.** Se siembra con **una** consulta al conectar
  (`history`) y después se mantiene sola: el lead ya viaja en cada sondeo, así
  que la lista se actualiza en el navegador sin pedir nada más.

### Cartera de llamadas

La columna izquierda son **las llamadas que el agente trabajó hoy**, no una
lista de clientes: nombre del lead, teléfono, número de intento, campaña,
cuándo y si quedó tipificada (con su código) o pendiente. Pestañas **Hoy ·
Sin tipificar · Todas**; el buscador de la barra superior filtra por nombre,
teléfono, campaña o número de intento.

Al no haber leads con prioridad, producto ni monto, se quitaron los filtros
de producto y orden, y el botón "Terminar atención": en el ciclo progresivo
el siguiente contacto lo decide el discador, no el agente.

La consulta de siembra filtra por `reserved_agent_user OR
disposition_agent_user OR agent_user`, acotada a `originated_at >= CURDATE()`
y con `LIMIT`. En llamadas MANUAL `agent_user` queda **NULL** —el agente real
está en `reserved_agent_user`—, por eso no basta el índice
`idx_dialer_call_log_agent`. Con la tabla actual (decenas de filas) el plan
recorre el índice primario sin coste apreciable; **si crece mucho, conviene
un índice sobre `reserved_agent_user`**.

### Ciclo manual progresivo

El encadenado de llamadas nace en la tipificación: `setDisposition()` llama a
`triggerManualAutodialIfIdle()`, que programa `autodial_due_at = NOW() + 3s`,
y el worker (`workerTick → processManualAutodial`) marca la siguiente. Si la
consola no cerrara el intento en el discador, el ciclo se detendría tras la
primera llamada.

Por eso "Grabar gestión" hace dos cosas, en este orden:

1. **Cierra el intento en el discador** (`action=dispose` → `setDisposition`).
   Si el discador lo rechaza, se avisa y **la gestión no se registra**: nunca
   queda una gestión en el CRM que el discador no aceptó.
2. **Guarda la cascada en el lead** y deja la gestión en el expediente.

### Dónde queda la tipificación

Los tres niveles se escriben en `v2_dialer_leads.custom_fields`, el JSON que
ya trae los datos de origen de la base cargada (`id_contacto`, `nombre`,
`monto`, `local`, `direccion`). Se añaden sólo claves con prefijo `tip_`:

| Clave | Contenido |
|---|---|
| `tip_nivel1` `tip_nivel2` `tip_nivel3` | La cascada elegida |
| `tip_nota` | Nota de la gestión |
| `tip_codigo` | Código enviado al discador (`01` / `02`) |
| `tip_agente` · `tip_llamada` · `tip_fecha` | Quién, en qué intento y cuándo |

La escritura usa **`JSON_SET` en una sola sentencia** (MariaDB 10.6), no un
leer-modificar-escribir: así no hay forma de pisar los datos de origen ni de
perder lo que otro proceso haya guardado entretanto. Si `custom_fields`
estuviera vacío o con JSON inválido, parte de `{}` en vez de fallar. Los
valores se recortan (80 caracteres por nivel, 1000 la nota) porque el campo
es compartido.

Guarda **la última tipificación del lead**, a propósito: el historial por
llamada ya vive en `v2_dialer_call_log`, y las tablas dedicadas de
tipificaciones y gestiones llegarán con el software de gestión que se
conectará más adelante.

El orden importa: primero el discador, después el lead. Si la escritura en el
lead fallara, la llamada ya quedó cerrada y se avisa («Llamada tipificada (no
se pudo escribir en el lead)») en vez de deshacer el cierre.

**La cascada la define la campaña, no el código.** El árbol llega con el
`bootstrap` (`fixed.trees`) desde las disposiciones de la campaña y la
consola lo reconstruye con `armarArbol()`: los códigos de seis dígitos dicen
el nivel y el padre, así que no hace falta ninguna consulta extra ni repetir
el árbol en cada sondeo.

El desenlace de cada hoja sale de las marcas que la disposición ya tiene:
**Venta** → abre la propuesta, **Reintenta** → exige agendar rellamada, y si
no tiene ninguna se registra sin trámite.

Ya no hay traducción de cascada a código: **la hoja elegida es la disposición
de la campaña**, de modo que su código (`010101`) cierra la llamada en el
discador y es la ruta que queda en el lead. Si una campaña no tiene árbol
jerárquico cargado, la cascada aparece vacía y lo dice, en lugar de ofrecer
niveles que el discador rechazaría.

Mientras dura el ciclo, la ficha "Información del cliente" muestra **el lead
del discador** (intento, teléfono, nombre, campaña, estado de la llamada y
las variables del lead) en lugar del contacto de la maqueta, y "Marcar
siguiente lead" queda bloqueado si hay una tipificación pendiente, diciendo
por qué. **No duplica código**: referencia `crm.css`, `crm.js` y
`crm_wa_session.php` de `modules/admin/`. Por eso `crm.js` toma el endpoint de
WhatsApp del atributo `data-crm-wa` del `<body>` (con `crm_wa_session.php` por
defecto, que es lo que usa admin) en lugar de llevarlo fijo, y el botón
"Salir" apunta a `../../index.php`.

`sidebar.php` tiene la entrada `crm` (icono propio `_svgIcon('crm')`) justo
debajo de `dialer_agent` ("Panel de agente"), con `built => true`. La consola
del agente no figura en el menú: se entra por enlace directo.

Sin dependencias externas: ni framework, ni webfont, ni imágenes. Dos
archivos estáticos y una página PHP.

## Disposición de la pantalla

Rejilla de tres columnas a pantalla completa, densa, sin espacios muertos.
La **estructura** sigue la de un CRM comercial de referencia (cartera ·
expediente · panel contextual); la **estética** es la del panel admin: plana,
sin sombras, sin degradados, sin esquinas redondeadas, sin imágenes.

```
┌──────────────────────────────────────────────────────────────────────┐
│ marca · buscador global (/) · estado de llamada + cronómetro · perfil│
├────────────┬─────────────────────────────────┬───────────────────────┤
│ CARTERA    │ EXPEDIENTE DEL CLIENTE          │ TIPIFICAR GESTIÓN     │
│            │  cabecera: avatar, prioridad,   │  nivel 1 ▾            │
│ 3 pestañas │  contacto, Editar/Más           │  nivel 2 ▾            │
│ 2 filtros  │                                 │  nivel 3 ▾            │
│            │                                 │  desenlace            │
│ tarjetas   │  5 pestañas ───────────────────│  trámite pendiente    │
│ de cliente │  Resumen: info (o chat) + notas │  nota                 │
│ (avatar,   │   y botonera de canales         │  [grabar] [terminar]  │
│  prioridad,│  Interacciones: timeline+chips  ├───────────────────────┤
│  producto, │  Transacciones · Documentos     │ TU PERFORMANCE HOY    │
│  cuándo)   │  Notas                          │  anillo + 4 métricas  │
├────────────┴─────────────────────────────────┴───────────────────────┤
│ barra de estado                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

Decisiones al adoptar la estructura de referencia:

- **La columna de navegación del modelo no se replica**: Zynervox ya aporta su
  sidebar y el módulo vive embebido en él; dos menús en pantalla sobraban. Lo
  que sí se trajo de esa columna es el bloque **"Tu performance hoy"**, que
  ocupa el pie del panel derecho.
- **El panel de asistente del modelo se usa para la tipificación.** Es el
  núcleo del flujo de televentas y necesita un lugar fijo; si en el futuro se
  suma un asistente, convive en esa misma columna.
- **Historial unificado**: llamadas, WhatsApp, correos, SMS, notas y gestiones
  tipificadas son eventos del mismo timeline, filtrables por chips, en vez de
  dos tablas separadas (interacciones por un lado, gestiones por otro).

### Ventana de chat (WhatsApp)

El botón **WhatsApp** de la botonera no abre una hoja de envío como Email o
SMS: sustituye el panel "Información del cliente" por una **ventana de chat**
con ese cliente, dentro de la pestaña Resumen; las notas clave siguen al lado
(el chat toma algo más de ancho, 1.6 a 1). El botón pasa a "Cerrar chat" y
queda resaltado; el chat también se cierra al cambiar de cliente o al
terminar la atención.

El bloque principal de cada pestaña (`.det-body > .duo | .panel`) se estira
hasta el pie del expediente con `flex:1`, así que el chat ocupa todo el alto
que le deja la columna y la botonera de canales queda fija abajo. Cada
`panel-body` hace su propio scroll, y un `min-height` de 220 px evita que el
contenido se aplaste en pantallas bajas.

El chat embebe **la conversación del cliente** en un `<iframe>`: el frontend
`chat.html` de Zynerwaba acepta `?id=<teléfono en dígitos>` y abre ese hilo
(busca por coincidencia exacta o por sufijo del número). Sin número, se abre
la bandeja completa. El servicio se puede incrustar porque no envía
`X-Frame-Options` ni CSP `frame-ancestors` y comparte dominio con el panel.

La autenticación la resuelve **`crm_wa_session.php`**, un archivo nuevo de
este módulo que no toca nada del módulo WhatsApp:

1. Exige sesión de Zynervox (`Auth::checkAccess([1..9])`).
2. Lee la cuenta de `/etc/zynervox/crm-whatsapp.conf` (root:www 640, **fuera
   del repositorio**).
3. Hace `POST /api/login` contra Zynerwaba **servidor contra servidor**, por
   `127.0.0.1`, de modo que las credenciales nunca llegan al navegador.
4. Reemite al navegador la cookie de sesión devuelta (conservando `Path`,
   `HttpOnly` y `SameSite`, y añadiendo `Secure` bajo HTTPS) y responde un
   `303` hacia `chat.html?id=…` (o la bandeja si no se pidió número).

El parámetro `id` llega del navegador, así que se reduce a dígitos y se
limita a 20 caracteres antes de construir el destino: un valor con símbolos
se normaliza y cualquier intento de torcer el redirect cae en la bandeja.

Sobre credenciales por URL: `chat.html` **sí** acepta `?user=&password=`
para autologin, pero no se usa — esa URL acabaría en el `src` del iframe, en
los logs de Apache, el historial y la cabecera `Referer`. La bandeja
principal no lo admite en ningún caso (`/api/login` es sólo POST). Existe
además un SSO firmado (`POST /api/sso/zynervox`, HMAC con vigencia de 90 s)
que usa `whatsapp.php`; no se empleó aquí porque mapea el rol según el nivel
de Zynervox (≥9 superadmin, ≥8 supervisor, resto admin), mientras que el chat
necesita entrar siempre con la cuenta de la empresa configurada.

El `src` del marco lleva un parámetro de tiempo para forzar una sesión nueva
en cada apertura, y la cabecera del panel ofrece "Abrir aparte" para ver la
misma conversación en otra pestaña.

### Encaje del marco

Zynerwaba sirve su chat con un diseño para móvil: tarjeta de 480 px centrada,
con margen, esquinas redondeadas y sombra (`public/chat-compact.css`), más los
botones de volver a la lista (`#backButton`) y cerrar sesión (`#logout`).
Dentro del expediente eso sobra: el cliente ya está elegido y la cuenta es
compartida.

Como el marco es del mismo dominio, `crm.js` le inyecta una hoja de estilos al
cargar (`encajarChat`, constante `CHAT_EMBED_CSS`) en vez de modificar
Zynerwaba: anula ancho fijo, margen, redondeo y sombra para que ocupe todo, y
oculta esos dos botones. Si el servicio cambiara su maquetado, las reglas
dejarían de aplicar pero **el chat seguiría funcionando**; y si el marco
resultara inaccesible, la función sale sin hacer nada.

El marco permanece oculto (`visibility:hidden`) mostrando "Abriendo
conversación…" hasta que la conversación está montada: así no se ve el
formulario de acceso de Zynerwaba, que su HTML trae visible por defecto
mientras el JS valida la sesión, trae los contactos y abre el hilo. El
destape espera a que `#chatView` deje de estar oculto, con un tope de 5 s
para no tapar el panel si algo sale distinto.

Queda deliberadamente visible la lista de contactos en el caso raro de que
Zynerwaba no encuentre el hilo (número recién cambiado, por ejemplo): es
preferible a dejar el panel en blanco. En la práctica no debería ocurrir,
porque el botón ya se bloquea para los clientes sin conversación.

**El botón sólo se habilita si el cliente tiene conversación.** Al cargar, el
CRM consulta `crm_wa_session.php?contacts=1`, que inicia sesión contra
Zynerwaba y devuelve únicamente los números con hilo abierto (`{"phones":[…]}`;
ni nombres ni mensajes). Con esa lista el botón WhatsApp queda deshabilitado
para los clientes sin conversación, explicando el motivo en el tooltip. El
emparejamiento replica el de Zynerwaba: dígitos, admitiendo que un número sea
sufijo del otro por el prefijo de país.

Si la consulta falla, `S.waPhones` queda en `null` y **no se bloquea nada**:
vale más intentar abrir el chat que impedirlo por un fallo pasajero del
servicio.

El cliente de ejemplo usa `+51 957 741 704`, que corresponde a una
conversación real, para que el panel abra con contenido.

Equivalencias con el escritorio WinForms del que partió el módulo:

| Escritorio original | Ahora |
|---|---|
| `TabControl` de 5 pestañas de búsqueda | Pestañas de cartera (Mis clientes / Prioritarios / Seguimientos) + buscador global; la 5.ª pestaña era Inconcert y se eliminó |
| Botón campana → modal `Frm_LeadsAsignados` | La cartera *es* la lista de asignados; el total va en la barra de performance |
| "Buscar Agendados" y "Buscar Rellamados" | Pestaña **Seguimientos** (en el original mostraban la misma entidad) |
| "Buscar Ventas Agentes" | Pestaña **Transacciones** del expediente |
| `GroupBox` "Datos del Contacto" | Cabecera + panel "Información del cliente" (15 pares, incluidos producto de interés, monto estimado y origen) |
| `lvw_Telefonos` + menú contextual | Hoja "Gestionar teléfonos", desde la fila Teléfono de la ficha o desde Más acciones |
| `GboDatosGestiones` | Columna **Tipificar gestión**, fija a la derecha |
| `lvw_Historial` | Timeline en la pestaña **Interacciones**, con la gestión como un evento más (no se repite en Resumen) |
| Modal `Frm_Venta` (96 controles) | Hoja **Propuesta de venta**: 44 campos en rejilla de 4 columnas por secciones |
| Modal `Frm_Rellamado` | Hoja **Agendar rellamada** |
| Barra de menús y panel "Segmentador" | Eliminados |

## Reglas de negocio conservadas

Están explícitas en `crm.js`, ya no implícitas en un JSON de diseño:

- **Tipificación en cascada de tres niveles** (`TIPIFICACION`). Reproduce el
  árbol `gestion` que el backend original traía por defecto: cada nivel se
  puebla del anterior, y cambiar un nivel superior invalida los inferiores.
- **El nodo hoja define el desenlace** (`kind`), igual que en el original:
  - `sale` → exige registrar la propuesta de venta antes de grabar;
  - `recall` → exige agendar fecha y hora;
  - `normal` → se graba sin trámite adicional.
  Cambiar de rama descarta el trámite que ya no aplica.
- **Validaciones de la propuesta**, las mismas que aplicaba `sale_data()` en
  el servidor Python: 11 campos obligatorios; importes no negativos; prima
  pactada mayor que cero; TREA obligatorio y positivo sólo cuando el tipo de
  seguro es `INVERSION_SEGURA`; porcentaje de retorno ≤ 100; fecha de
  nacimiento no futura.
- **Rellamada** con fecha igual o posterior a hoy (regla `recall_date()`).
- **Teléfonos**: formato válido, sin duplicados en el contacto, y el contacto
  debe conservar al menos uno.
- **Terminar atención** pasa al siguiente cliente de la cartera visible
  (respetando pestaña, filtros y orden) y limpia el formulario, como el
  `BtnTerminar` original.
- **Llamada en curso**: al llamar arranca el cronómetro de la barra superior;
  mientras dure no se puede abrir otro cliente ni salir del módulo, y al
  colgar la llamada queda registrada con su duración.

Mejoras deliberadas sobre la maqueta original, por coherencia: los teléfonos,
el historial, las ventas y las agendas pertenecen a un contacto concreto (en
el original eran listas globales compartidas por todos), y la propuesta de
venta precarga los datos ya conocidos del contacto más valores de arranque de
televenta (medio de llamada saliente, tarjeta "no aplica").

## Acceso

`crm.php` y `crm_app.php` llaman `Auth::checkAccess([1,2,3,4,5,6,7,8,9])` —
mismo criterio que "Panel de agente": cualquier usuario logueado en
Zynervox, sin nivel mínimo. No hay control de rol propio.

El nombre del operador sale de la sesión de Zynervox
(`$_SESSION['full_name']`, con `$_SESSION['user']` de reserva) y llega al JS
por `data-crm-user` en el `<body>`; `data-crm-exit` indica a dónde vuelve el
botón "Salir".

## Por qué un `<iframe>`

La consola usa su propia hoja de estilos de pantalla completa (`html,body` al
100 %, rejilla a `100dvh`, capa modal fija), que chocaría con el layout de
Zynervox (`.sidebar`, `.main-content`) si se insertara en la misma página.
`crm.php` muestra el sidebar y la cabecera estándar y embebe `crm_app.php` en
un iframe que ocupa el resto exacto; también ofrece "Abrir en pestaña
completa" para usarla sin el sidebar.

## Gotcha de despliegue: `IsolatedGate.php`

Producción corre con `deployment.isolated = true`
(`config/deployment.php`), lo que activa un `auto_prepend_file` de Apache
(`includes/IsolatedGate.php`) que **bloquea con 403 cualquier `.php` que no
esté en su allowlist explícita**, sin importar que `Auth::checkAccess` lo
permita.

`modules/admin/crm.php` y `modules/admin/crm_app.php` ya están en esa lista.
**Si se agrega un nuevo `.php` a este módulo (por ejemplo un endpoint de API
para el backend futuro), hay que sumarlo también ahí** o responderá 403
aunque pase `php -l` y `Auth::checkAccess`. Los assets estáticos
(`crm-assets/*.css`, `*.js`) no pasan por este gate.

## Cómo verificar cambios sin navegador

El servidor no tiene navegador y `npm` está bloqueado por certificados, así
que la verificación se hace ejecutando `crm.js` sobre un mini-DOM en Node,
alimentado con el HTML real de `crm_app.php`. Eso permite comprobar el
render y recorrer los flujos (cascada, venta con validaciones, rellamada,
teléfonos, cola, terminar atención) de punta a punta. El arnés es
desechable: se escribe en `/tmp` al momento de verificar, no vive en el
repositorio. Lo importante es qué cubrir, no el archivo.

## Tipificaciones jerárquicas

Las tipificaciones se administran donde ya se administraban: **Campañas →
[campaña] → Disposiciones**. No hay módulo ni tablas nuevas; se reutiliza
`v2_dialer_dispositions`, que ya existía.

La jerarquía vive en el propio código, de **ancho fijo: seis dígitos en tres
tramos de dos**. Los tramos no usados van en `00`, así que el código dice por
sí solo en qué nivel está y de quién cuelga:

```
010000   CONTACTADO
010100     SEGUIMIENTO
010101       INTERESADO
010102       VOLVER A LLAMAR
010200     VENTA
010201       VENTA CONFIRMADA
020000   NO CONTACTADO
020100     NO CONTESTA
020101       REINTENTAR
```

Ventajas de resolverlo con el código y no con una tabla de relaciones: el
nivel y el padre se calculan sin consultar nada (`dispositionLevel()`,
`dispositionParent()`), y como el orden alfabético coincide con el del árbol,
basta `ORDER BY code` para listarlo ya jerarquizado.

En el alta **no se escribe el código**: se elige "Depende de" y
`createChildDisposition()` calcula el siguiente libre bajo ese padre. Eso
impide romper el formato del que depende toda la lectura. La tabla se muestra
indentada según el nivel.

Cada fila se puede **editar en línea**: etiqueta, Contacto, Venta, Reintenta
y espera, con su botón Guardar. **El código no es editable**: fija la
jerarquía y queda grabado en cada llamada ya tipificada, así que cambiarlo
rompería el árbol y el histórico. Para mover una rama hay que crear la nueva
y borrar la anterior.

Protecciones: `00` es reservado y ningún tramo propio puede valerlo; un
código con hueco (`010001`) se considera inválido; no se admite un cuarto
nivel; y una tipificación con subtipificaciones no se puede borrar sin
eliminar antes sus hijas.

El alta ya **no marca "Reintenta" por defecto**. Lo hacía, y como la consola
interpreta esa marca como "el agente debe agendar la rellamada", toda
tipificación nueva pedía agenda sin que nadie lo hubiera elegido.

Como la hoja (`010101`) es una disposición real de la campaña, ese mismo
código sirve para las dos cosas: cerrar la llamada en el discador y quedar en
el lead como ruta completa. Ya no hace falta traducir la cascada a un código
aparte. Y los campos que la tabla ya tenía siguen valiendo: **Venta** marca
la hoja que abre la propuesta, **Reintenta** devuelve el lead a la cola.

## Qué falta para que deje de ser maqueta

1. **Persistencia.** Hoy todo vive en el objeto `S` de `crm.js`. Hace falta
   un backend y reemplazar las escrituras a `S.gestiones`, `S.ventas`,
   `S.agendas` y `S.contactos[].telefonos` por llamadas a él.
2. **Modelo de datos.** El esquema SQLite del proyecto original (`contacts`,
   `phones`, `activities`, `sales`, `recalls`, `audit`) es una referencia de
   diseño ya validada; no se trajo a este repositorio.
3. **Catálogos**: hoy `TIPIFICACION` y `OPC` están codificados en el JS. En
   el original eran configurables por campaña desde la base de datos.
4. **Roles.** Decidir si basta con los niveles 1-9 de Zynervox o si hace
   falta un modelo propio (el original distinguía agente, supervisor,
   validador, backoffice y admin, con visibilidad por fila: un agente sólo
   veía sus contactos asignados).
5. **Datos del expediente.** Prioridad, producto de interés, monto estimado,
   origen, profesión, empresa, notas clave, documentos y las interacciones por
   canal hoy son campos de la maqueta en `crm.js`; al haber backend deben
   venir de la base y alimentarse de la operación real (el canal WhatsApp de
   Zynervox, el correo saliente, el discador).
6. **Integración con el discador.** Se quitó la pestaña Inconcert; si en el
   futuro la consola debe acoplarse al marcador de Zynervox, es trabajo
   nuevo y debería apoyarse en el módulo `dialer`, no en Inconcert.

## Flujo para modificar este módulo

Igual que el resto de Zynervox: editar en `/opt/zynervoxv2-fuente` (nunca en
`/srv/www/htdocs/zynervoxv2`), `php -l` sobre cada `.php` tocado, respaldo
`.bak.<timestamp>` del destino, copiar con `root:www 640`, comparar `md5sum`
fuente/destino, actualizar `IsolatedGate.php` si se suma un `.php` nuevo,
commit y push desde la fuente. Ver la skill `zynervoxv2`.

> Nota de seguridad observada el 2026-10-17: los respaldos `.php.bak.<ts>`
> que esta rutina deja dentro del docroot **Apache los sirve como texto
> plano** (se probó: HTTP 200 devolviendo el código fuente). Los respaldos de
> este módulo se dejaron en modo `600` para que no sean legibles por Apache,
> pero en el docroot quedan ~178 respaldos antiguos de otros módulos que sí
> lo son. Conviene decidir una solución general (negarlos en la
> configuración de Apache o guardar los respaldos fuera del docroot).
