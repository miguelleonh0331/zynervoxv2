require('./dom.js');
const fs = require('fs'), vm = require('vm');
const base = require('path').resolve(__dirname, '../../../../../app/web/supervisor/') + '/';
// datos.php con TODO vacio: el peor caso que puede mandar el servidor.
const datos = fs.readFileSync(process.argv[2], 'utf8');
const sup = fs.readFileSync(base + 'sup.js', 'utf8');
const ctx = vm.createContext(globalThis);
const fallos = [];
try { vm.runInContext(datos, ctx, {filename:'datos'}); }
catch (e) { console.log('DATOS NO CARGA: ' + e.message); process.exit(1); }
vm.runInContext(sup, ctx, {filename:'sup.js'});
// arrancar() + cada vista + capas
const pasos = [['arrancar', () => ctx.arrancar()]];
for (const v of ['piso','campanas','base','segmentos','clientes','conversa','comunica','calidad','monitoreo','equipo','reportes'])
  pasos.push(['irA(' + v + ')', () => ctx.irA(v)]);
pasos.push(['abrirTV', () => ctx.abrirTV()]);
pasos.push(['buscarGlobal', () => ctx.buscarGlobal('a')]);
pasos.push(["accion(bajarNivel)", () => ctx.accion('bajarNivel')]);
// chat flotante: burbuja, apertura, envio y cierre
pasos.push(['pintarBurbuja', () => ctx.pintarBurbuja()]);
pasos.push(['abrirChatFlot', () => ctx.abrirChatFlot()]);
pasos.push(['abrirChatFlot(primero)', () => ctx.abrirChatFlot(
  vm.runInContext('AGENTES.length ? AGENTES[0].id : null', ctx))]);
pasos.push(['pintarChatFlot', () => ctx.pintarChatFlot()]);
pasos.push(['cerrarChatFlot', () => ctx.cerrarChatFlot()]);
for (const [n, f] of pasos) {
  try { f(); } catch (e) { fallos.push(n + ' -> ' + e.constructor.name + ': ' + e.message); }
}
// sup.js deja el reloj corriendo: hay que cortar a mano o Node no termina.
if (!fallos.length) { console.log('OK: arranque, 11 vistas, Modo TV, buscador y acciones, sin excepciones'); process.exit(0); }
console.log('FALLOS (' + fallos.length + '):'); fallos.forEach(f => console.log('  - ' + f)); process.exit(1);
