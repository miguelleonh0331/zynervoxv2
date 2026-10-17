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
    ├── crm.css             Estilo propio, ~12 KB
    └── crm.js              Estado, render y reglas de negocio, ~30 KB
```

`sidebar.php` tiene la entrada `crm` (icono propio `_svgIcon('crm')`) justo
debajo de `dialer_agent` ("Panel de agente"), con `built => true`.

Sin dependencias externas: ni framework, ni webfont, ni imágenes. Dos
archivos estáticos y una página PHP.

## Disposición de la pantalla

Rejilla de tres columnas a pantalla completa, densa, sin espacios muertos;
misma filosofía que `layout.css` del panel admin (sin sombras, sin
degradados, sin esquinas redondeadas, encabezados de tabla oscuros).

```
┌──────────────────────────────────────────────────────────────────┐
│ barra: marca · ir a contacto · campaña · operador · asignados    │
├────────────┬───────────────────────────────┬─────────────────────┤
│ COLA DE    │ FICHA DEL CONTACTO            │ TIPIFICAR GESTIÓN   │
│ TRABAJO    │  cabecera (id · nombre · doc) │  nivel 1 ▾          │
│            │  datos en rejilla etiqueta/   │  nivel 2 ▾          │
│ 4 modos:   │  valor (12 pares)             │  nivel 3 ▾          │
│ contactos  ├───────────────────────────────┤  desenlace          │
│ asignados  │ TELÉFONOS (alta/edición/baja) │  trámite pendiente  │
│ agenda     ├───────────────────────────────┤  nota               │
│ ventas     │ HISTORIAL DE GESTIONES        │  [grabar][terminar] │
├────────────┴───────────────────────────────┴─────────────────────┤
│ barra de estado                                                  │
└──────────────────────────────────────────────────────────────────┘
```

Equivalencias con el escritorio original, por si hace falta rastrear algo:

| Original | Ahora |
|---|---|
| `TabControl` de 5 pestañas de búsqueda (arriba a la derecha) | Columna **Cola de trabajo** (izquierda) con 4 modos; la 5.ª pestaña era Inconcert y se eliminó |
| Botón campana `BtnAsignados` → modal `Frm_LeadsAsignados` | Modo **Asignados** de la cola + contador en la barra superior |
| Pestañas "Buscar Clientes Agendados" y "Buscar Rellamados" | Unificadas en el modo **Agenda** (en la maqueta original mostraban la misma entidad con las mismas columnas) |
| `GroupBox` "Datos del Contacto" (izquierda) | Columna central: cabecera + rejilla etiqueta/valor |
| `lvw_Telefonos` + menú contextual (nuevo/modificar/eliminar) | Bloque **Teléfonos** con barra de alta/edición en línea y botones por fila |
| `GboDatosGestiones` (abajo a la derecha) | Columna **Tipificar gestión** (derecha), fija |
| `lvw_Historial` (franja inferior) | Bloque **Historial** en la columna central |
| Modal `Frm_Venta` (96 controles) | Hoja de **Propuesta de venta**: 44 campos en rejilla de 4 columnas, por secciones |
| Modal `Frm_Rellamado` | Hoja de **Agendar rellamada** |
| Barra de menús (Archivo/Gestión/Consultas/Mantenimiento/Ayuda) | Eliminada; sus entradas apuntaban a formularios que ya no existen. "Salir" quedó en la barra superior |
| Panel lateral "Segmentador" (estaba oculto y vacío) | Eliminado |

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
- **Terminar atención** pasa al siguiente contacto de la cola y limpia el
  formulario, como el `BtnTerminar` original.

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
5. **Integración con el discador.** Se quitó la pestaña Inconcert; si en el
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
