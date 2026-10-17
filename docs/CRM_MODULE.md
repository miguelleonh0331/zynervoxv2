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

`sidebar.php` tiene la entrada `crm` (icono propio `_svgIcon('crm')`) justo
debajo de `dialer_agent` ("Panel de agente"), con `built => true`.

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
la bandeja completa. El servicio se puede embeber
que se puede incrustar porque el servicio no envía `X-Frame-Options` ni CSP
`frame-ancestors` y comparte dominio con el panel.

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
en cada apertura, y la cabecera del panel ofrece "Abrir aparte" para usar la
bandeja completa en otra pestaña.

El cliente de ejemplo del CRM usa el número `+51 957 741 704`, que
corresponde a una conversación real en Zynerwaba, para que el panel abra con
contenido. Los demás contactos de la maqueta no tienen hilo asociado: en
ellos `chat.html` avisa de que no existe.

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
