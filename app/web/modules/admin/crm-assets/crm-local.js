'use strict';
// Adaptador de maqueta del escritorio CRM dentro de Zynervox.
//
// Sustituye a desktop-api.js del proyecto original (crm-escritorio): allí el
// escritorio hablaba con un servidor Python propio que resolvía inicio de
// sesión, usuarios y guardado en SQLite. Aquí no hay servidor Python, ni base
// de datos, ni login propio: el acceso ya lo valida Zynervox en crm_app.php y
// el estado del escritorio vive en memoria mientras dura la visita.
//
// demo.js se conserva sin cambios y espera encontrar aquí currentUser,
// needsSetup, persistWorkspace, showDesktopError, logoutDesktop y action.

const CRM = document.body.dataset;
let currentUser = { name: CRM.crmUser || 'OPERADOR', role: 'maqueta' };
let needsSetup = false;

function showDesktopError(error) {
    message('Aplicativo de gestión', (error && error.message) || String(error));
}

// Maqueta sin base de datos: no hay nada que guardar. demo.js encadena
// promesas sobre el resultado, así que devuelve una ya resuelta.
function persistWorkspace() {
    return Promise.resolve();
}

// "Cerrar sesión" y "Salir" del menú Archivo devuelven al panel de Zynervox,
// que es quien realmente tiene la sesión. Usa top para salir del iframe.
function logoutDesktop() {
    top.location.href = CRM.crmExit || 'index.php';
}

// Versión síncrona de la original: sin llamadas de red que esperar.
function action(ctx, id) {
    if (ctx.busy) return;
    ctx.busy = true;
    try {
        runDesktopAction(ctx, id);
    } catch (error) {
        showDesktopError(error);
    } finally {
        ctx.busy = false;
    }
}

async function bootDesktop() {
    const base = CRM.crmBase || '';
    const response = await fetch(base + 'gui-layout.json');
    if (!response.ok) throw new Error('No se pudo abrir la interfaz.');
    // El diseño original referencia las imágenes como rutas de raíz
    // (/gui-assets/...) porque el servidor Python ocupaba el dominio entero.
    // Aquí el módulo cuelga de modules/admin/, así que se reubican al vuelo y
    // gui-layout.json se conserva idéntico al original.
    const design = (await response.text()).replaceAll('"/gui-assets/', '"' + base + 'gui-assets/');
    layout = JSON.parse(design);
    forms = new Map(layout.forms.map(form => [form.id, form]));
    loggedIn = true;
    OPTIONS.cmbusuario = [currentUser.name];
    showForm('Frm_AtencionTelefonica');
}

bootDesktop().catch(showDesktopError);
