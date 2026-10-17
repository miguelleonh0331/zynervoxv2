# Módulo CRM

Última actualización: 2026-10-17.

## Qué es hoy

Una **maqueta de interfaz**, sin backend: el escritorio de ventas con sus 14
formularios, menús, pestañas y tablas, navegable y clicable, pero sin base de
datos ni persistencia. Cerrar la pestaña borra lo capturado.

No confundir con `src/features/whatsapp/vendor/src/features/crm/` — es un
módulo de otro proyecto vendoreado (business suite de Zynerwaba/WhatsApp),
sin relación con este.

## Origen

Portado desde un proyecto standalone independiente (`crm-escritorio`, fuera
de este repositorio) que era una app Python completa: servidor WSGI propio,
SQLite privado, login y roles propios (admin/supervisor/agente/validador/
backoffice), guardado de escritorio por cuenta con detección de conflictos.

Al integrarlo como módulo de Zynervox se quitó deliberadamente todo lo que
dependía de ese servidor Python:

- El servidor WSGI (`server.py`, ~30 rutas REST) y su validación de negocio
  (dinero, fechas, catálogo dinámico, import CSV/XLSX, auditoría).
- La base SQLite (`crm.sqlite3`) y su esquema (`users`, `contacts`,
  `activities`, `sales`, `recalls`, `audit`, `gui_workspaces`, etc.).
- El login y los roles propios de la app (pantalla `Frm_Login`, sesión por
  cookie `escritorio_session`).
- La pantalla adicional `/gestion` (`index.html` + `app.js`), que dependía
  100% del mismo API Python y no se migró.

Lo que **sí** se conservó, copiado byte a byte desde el proyecto original:

- `demo.js` — motor de render: interpreta `gui-layout.json` y dibuja
  ventanas, pestañas, tablas y controles.
- `demo.css` — toda la geometría y el estilo visual del escritorio.
- `gui-layout.json` — definición de los 14 formularios (controles, posición,
  tamaño, imágenes).
- `gui-assets/*.{png,jpg}` — las 21 imágenes de iconos/botones del escritorio.

## Dónde vive cada archivo

Todo bajo `app/web/modules/admin/` (fuente oficial `/opt/zynervoxv2-fuente`,
desplegado a `/srv/www/htdocs/zynervoxv2/modules/admin/`):

```
modules/admin/
├── crm.php                  Página Zynervox: sidebar + layout.css + <iframe> a crm_app.php
├── crm_app.php               El escritorio en sí (full-page), cargado dentro del iframe
└── crm-assets/
    ├── demo.js                Sin tocar (copia del original)
    ├── demo.css                Sin tocar
    ├── gui-layout.json         Sin tocar
    ├── gui-assets/*.png,*.jpg  Sin tocar (21 imágenes)
    └── crm-local.js            NUEVO — reemplaza a desktop-api.js del original
```

`sidebar.php` tiene la entrada `crm` (icono propio `_svgIcon('crm')`) justo
debajo de `dialer_agent` ("Panel de agente"), con `built => true`.

## Por qué un `<iframe>` y no HTML inline

El escritorio trae su propia hoja de estilos de **pantalla completa**
(`html,body{height:100%;overflow:hidden}`, modales `position:fixed`), que
chocaría con el layout de Zynervox (`.sidebar`, `.main-content`) si se
insertara directo en la misma página. `crm.php` resuelve esto con:

- Sidebar + cabecera estándar de Zynervox arriba.
- Un `<iframe class="crm-frame" src="crm_app.php">` que ocupa el resto de
  `main-content` (convertido a `display:flex; flex-direction:column; height:
  100vh` solo en esta página).
- Un botón "Abrir en pestaña completa" que apunta a `crm_app.php` directo,
  sin iframe, para usar el escritorio a pantalla completa real.

`crm_app.php` es la única página que replica el `<!doctype html>` original
(`demo.html`): cabecera, nav de menú, workspace y la barra de estado.

## `crm-local.js`: el reemplazo de `desktop-api.js`

El original hablaba por HTTP con el servidor Python (`fetch('/api'+path)`)
para login, bootstrap y guardado de escritorio (`PUT /api/workspace`).
`crm-local.js` implementa la misma interfaz que `demo.js` espera, sin red:

| Símbolo que `demo.js` necesita | Qué hace `crm-local.js` |
|---|---|
| `currentUser` | Se arma desde `document.body.dataset.crmUser`, que `crm_app.php` llena con `$_SESSION['full_name']` (o `$_SESSION['user']`) de Zynervox. |
| `needsSetup` | Siempre `false` — no hay primer-uso que configurar. |
| `persistWorkspace()` | `Promise.resolve()` — no hay nada que guardar. |
| `logoutDesktop()` | `top.location.href = ...crmExit` — saca del iframe y vuelve a `index.php` de Zynervox (la sesión real la administra `Auth`, no este escritorio). |
| `action(ctx,id)` | Versión síncrona de la original (sin `await` porque no hay red). |
| `bootDesktop()` | Carga `gui-layout.json`, reescribe las rutas `/gui-assets/...` (absolutas de raíz en el original) a `crm-assets/gui-assets/...` (relativas al módulo), y entra directo a `Frm_AtencionTelefonica` — sin pasar por `Frm_Login`. |

Las rutas de imagen se reescriben en runtime (string-replace sobre el JSON
antes de parsearlo) en vez de editar `gui-layout.json`, precisamente para
poder seguir usando ese archivo sin tocarlo.

## Acceso

`crm.php` y `crm_app.php` llaman `Auth::checkAccess([1,2,3,4,5,6,7,8,9])` —
mismo criterio que "Panel de agente": cualquier usuario logueado en
Zynervox, sin nivel mínimo. No hay control de rol propio (el original tenía
5 roles distintos; aquí no se migraron).

## Gotcha de despliegue: `IsolatedGate.php`

Producción corre con `deployment.isolated = true`
(`config/deployment.php`), lo que activa un `auto_prepend_file` de Apache
(`includes/IsolatedGate.php`) que **bloquea con 403 cualquier `.php` que no
esté en su allowlist explícita**, sin importar que `Auth::checkAccess` lo
permita.

`modules/admin/crm.php` y `modules/admin/crm_app.php` ya están agregados a
esa lista. **Si se agrega un nuevo archivo `.php` a este módulo (por ejemplo
un endpoint de API para un backend futuro), hay que sumarlo también ahí** o
responderá 403 en producción aunque pase `php -l` y `Auth::checkAccess` sin
problema. Los assets estáticos (`crm-assets/*.js/.css/.json/.png`) no pasan
por este gate — solo los `.php`.

## Qué falta para que deje de ser maqueta

No hay nada construido de esto todavía; es el mapa de lo que falta decidir
e implementar cuando se aborde el backend real:

1. **Persistencia.** Reemplazar `persistWorkspace()`/`loadWorkspace()` en
   `crm-local.js` (o reintroducir equivalentes) por llamadas a un backend
   real. `demo.js` no necesita cambios para esto: ya invoca
   `persistWorkspace()` después de cada acción que modifica `demo`.
2. **Modelo de datos.** El esquema SQLite del proyecto original (tablas
   `contacts`, `phones`, `activities`, `sales`, `recalls`, `audit`,
   `gui_workspaces`) es una referencia de diseño ya validada, no un
   artefacto a reutilizar literalmente — no se trajo a este repo.
3. **Decisión de roles/auth**: si el futuro backend reutiliza los niveles
   1-9 de Zynervox tal cual, o si se necesita un modelo de permisos propio
   (el original distinguía agente/supervisor/validador/backoffice/admin con
   reglas de visibilidad por fila, p. ej. un agente solo ve sus contactos
   asignados).
4. **`/gestion`** (`index.html`/`app.js` del proyecto original) no tiene
   equivalente en este módulo. Si se necesita, es trabajo nuevo: esos
   archivos no se migraron.
5. Decidir si cada operador necesita su propio escritorio (como en el
   original) o si el CRM pasa a ser compartido/por campaña.

## Flujo para modificar este módulo

Igual que el resto de Zynervox: editar en `/opt/zynervoxv2-fuente`
(nunca en `/srv/www/htdocs/zynervoxv2`), `php -l` sobre cada `.php`
tocado, backup `.bak.<timestamp>` del destino, copiar con `root:www 640`,
comparar `md5sum` fuente/destino, actualizar `IsolatedGate.php` si se suma
un `.php` nuevo, commit y push desde la fuente. Ver la skill `zynervoxv2`
para el procedimiento completo.
