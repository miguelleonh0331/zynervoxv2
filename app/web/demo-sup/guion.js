'use strict';
/* Recorrido guiado de la maqueta de supervisión.

   Una voz explica la pantalla mientras el puntero hace los clics. No hay
   un camino especial para la demostración: el guion usa irA(), abrirEscucha(),
   accion()… las mismas funciones que usa una persona navegando a mano.

   LA VOZ. Los audios están pregrabados en audio/ y se llaman por el hash de
   su propio texto (hashTexto). Si una frase cambia, cambia el nombre del
   archivo y hay que regenerarla — eso lo hace scripts/demo_voz_sup.py, el
   único que toca la llave de ElevenLabs. La página nunca llama a la API:
   funciona sin internet y no gasta créditos al reproducirse.

   EL ORDEN. La voz y el foco van encolados en la misma fila (VOZ.cola). Si
   el foco se aplicara al llamarlo en vez de al encolarlo, el recuadro iría
   una frase adelantado: se ilumina la derecha mientras la voz todavía habla
   de la izquierda. Ya pasó una vez; por eso enfocar() también se encola. */

window.__tour = true;

/* ====================================================== la voz ======= */
const VOZ = {cola:Promise.resolve(), audio:null, silencio:false, ritmo:1.3};

function hashTexto(t) {
  let h = 0;
  for (const c of String(t)) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return h.toString(36);
}

function reproducir(texto, gen) {
  return new Promise(resolve => {
    if (VOZ.silencio || gen !== P.gen) return resolve();
    const a = new Audio('audio/v' + hashTexto(texto) + '.mp3');
    a.playbackRate = VOZ.ritmo;
    if ('preservesPitch' in a) a.preservesPitch = true;
    VOZ.audio = a;
    let listo = false;
    const fin = () => { if (listo) return; listo = true; resolve(); };
    a.addEventListener('ended', fin);
    a.addEventListener('error', fin);
    // Red de seguridad: si el archivo no existe o el navegador se traba,
    // el recorrido sigue igual en vez de quedarse mudo para siempre.
    setTimeout(fin, 30000);
    const p = a.play();
    if (p && p.catch) p.catch(fin);
  });
}

const vozAlDia = () => VOZ.cola;
function decir(texto) {
  const gen = P.gen;
  VOZ.cola = VOZ.cola.then(() => reproducir(texto, gen));
}

/* ==================================================== el reproductor = */
const P = {i:0, corriendo:false, gen:0, t0:0, transcurrido:0};
const NUNCA = () => new Promise(() => {});

async function dormir(ms) {
  const gen = P.gen;
  let resta = ms;
  while (resta > 0) {
    if (gen !== P.gen) return NUNCA();
    if (!P.corriendo) { await new Promise(r => setTimeout(r, 100)); continue; }
    const paso = Math.min(100, resta);
    await new Promise(r => setTimeout(r, paso));
    resta -= paso;
  }
  return gen === P.gen ? undefined : NUNCA();
}

/* -------------------------------------------------------- el foco ---- */
function aplicarFoco(sel) {
  const f = $('foco');
  if (!sel) { f.hidden = true; return; }
  const n = document.querySelector(sel);
  if (!n) { f.hidden = true; return; }
  n.scrollIntoView({behavior:'smooth', block:'center'});
  const r = n.getBoundingClientRect();
  f.hidden = false;
  f.style.left   = (r.left - 6) + 'px';
  f.style.top    = (r.top - 6) + 'px';
  f.style.width  = (r.width + 12) + 'px';
  f.style.height = (r.height + 12) + 'px';
}
function enfocar(sel) {
  const gen = P.gen;
  VOZ.cola = VOZ.cola.then(() => { if (gen === P.gen) aplicarFoco(sel); });
}

/* ------------------------------------------------------ el puntero --- */
function moverPuntero(x, y) {
  const p = $('puntero');
  p.hidden = false;
  p.style.transform = 'translate(' + x + 'px,' + y + 'px)';
}
async function clicEn(sel) {
  const n = document.querySelector(sel);
  if (!n) return;
  n.scrollIntoView({behavior:'smooth', block:'center'});
  await dormir(260);
  const r = n.getBoundingClientRect();
  moverPuntero(r.left + Math.min(r.width / 2, 70), r.top + r.height / 2);
  await dormir(620);
  const p = $('puntero');
  p.classList.add('is-click');
  setTimeout(() => p.classList.remove('is-click'), 520);
  await dormir(170);
  n.click();
  await dormir(320);
}

/* -------------------------------------------------- la narración ----- */
async function narrar(txt) {
  const gen = P.gen;
  await vozAlDia();
  if (gen !== P.gen) return NUNCA();
  $('narraTxt').textContent = txt;
  decir(txt);
  await vozAlDia();
  if (gen !== P.gen) return NUNCA();
  // Un respiro entre frases: sin esto suena a locutor apurado.
  await dormir(VOZ.silencio ? 2600 : 420);
}

/* ==================================================== los capítulos == */
const CAPITULOS = [

{n:1, titulo:'El piso en vivo', sub:'Todo en una pantalla', correr: async () => {
  irA('piso');
  await dormir(700);
  enfocar(null);
  await narrar('Esta es la pantalla del supervisor. Todo el piso en un solo lugar: quién está en llamada, quién en pausa, y cómo va el día.');
  enfocar('#pisoKpis');
  await narrar('Arriba, los seis números que importan. El último está en rojo: el abandono subió del tres por ciento permitido.');
}},

{n:2, titulo:'El marcador', sub:'No es un reporte, es un volante', correr: async () => {
  irA('piso');
  await dormir(500);
  enfocar('#cardMotor');
  await narrar('Este es el marcador en vivo. La aguja marca el abandono real, y la perilla sube o baja la agresividad del discado.');
  enfocar('#btnPanico');
  await narrar('Si se dispara, un solo botón lo deja en una llamada por agente. El abandono cae en minutos.');
}},

{n:3, titulo:'La mañana del piso', sub:'Quién trabajó y quién no', correr: async () => {
  irA('piso');
  await dormir(500);
  enfocar('#cardTL');
  await narrar('Esta es la mañana completa del piso. Cada franja es una persona, y cada color, lo que estuvo haciendo.');
  await narrar('Verde es llamada, ámbar es pausa, gris es espera. De un vistazo se ve quién trabajó y quién no.');
}},

{n:4, titulo:'El equipo', sub:'Persona por persona', correr: async () => {
  irA('piso');
  await dormir(500);
  enfocar('#cardEquipo');
  await narrar('Debajo, el detalle por persona: llamadas, contactos, ventas y ocupación. Diana lleva veintitrés minutos en pausa.');
}},

{n:5, titulo:'Entrar a la llamada', sub:'Escuchar, susurrar, intervenir', correr: async () => {
  irA('piso');
  await dormir(450);
  await clicEn('#oir-5000');
  enfocar('#escucha');
  await narrar('Desde aquí el supervisor entra a cualquier llamada en curso.');
  await clicEn('#modo-susurro');
  await narrar('Puede solo escuchar, puede susurrarle al agente sin que el cliente lo oiga, o puede entrar y hablar los tres.');
  await narrar('Cada intervención queda registrada: quién escuchó a quién y a qué hora.');
  cerrarEscucha();
}},

{n:6, titulo:'Alertas', sub:'Avisan y resuelven', correr: async () => {
  irA('piso');
  await dormir(500);
  enfocar('#cardAlertas');
  await narrar('Las alertas no solo avisan: traen el botón que las resuelve.');
  await clicEn('#alerta-1 .btn');
  enfocar('#cardEquipo');
  await narrar('Un clic y Diana vuelve a estar disponible, sin levantarse del asiento.');
}},

{n:7, titulo:'Campañas', sub:'Salientes y entrantes', correr: async () => {
  irA('campanas');
  await dormir(650);
  enfocar('#camps');
  await narrar('Acá se arman las campañas, salientes y entrantes, en la misma pantalla.');
  enfocar('#cardCola');
  await narrar('Mover a alguien de una campaña a otra es arrastrarlo. Abajo, quién está esperando en la línea entrante.');
  enfocar('#cardAsignacion');
  await narrar('Acá se define en qué campañas puede trabajar cada uno. Quien cruza una saliente con una entrante queda mixto: atiende las dos.');
  // Se marca una casilla de verdad: Jorge pasa a atender también entrantes
  // y el aviso lo nombra. Es la demostración, no una captura.
  await clicEn('#asig-5005-entrantes');
}},

{n:8, titulo:'Carga de base', sub:'Del archivo a la campaña', correr: async () => {
  S.cargaPaso = 1; irA('base');
  await dormir(600);
  enfocar('#cardArchivo');
  await narrar('La base entra por acá. Se suelta el archivo y el sistema propone a qué campo va cada columna.');
  await clicEn('#btnMapeo');
  enfocar('#cardMapeo');
  await dormir(900);
  S.cargaPaso = 3; irA('base');
  await dormir(500);
  enfocar('#cardValida');
  await narrar('Antes de cargar nada, muestra qué entra y qué se rechaza: repetidos, teléfonos inválidos y lista negra.');
  await narrar('Y normaliza los números con código de país, para que WhatsApp pueda abrir la conversación.');
}},

{n:9, titulo:'Segmentación', sub:'Volver a llamar a quien conviene', correr: async () => {
  irA('segmentos');
  await dormir(650);
  enfocar('#cardCond');
  await narrar('Esta es la parte que más tiempo ahorra: volver a llamar solo a quien conviene.');
  enfocar('#contador');
  await narrar('Se arman condiciones y el contador se mueve solo. Mil doscientos ochenta y cuatro clientes, sin duplicar la lista.');
  enfocar('#cardCalor');
  await narrar('El mapa de calor dice a qué hora contestan, y el semáforo avisa cuándo una base ya está agotada.');
}},

{n:10, titulo:'El cliente', sub:'Todo su historial', correr: async () => {
  S.cliente = 4820; irA('clientes');
  await dormir(650);
  enfocar('#cardBuscar');
  await narrar('Buscar a un cliente es escribir su nombre, su teléfono o su documento.');
  enfocar('#cardFicha');
  await narrar('Y aparece todo: sus datos, el producto, el monto y cada contacto que tuvo, por el canal que haya sido.');
}},

{n:11, titulo:'WhatsApp', sub:'Responder por el agente', correr: async () => {
  S.cliente = 4820; irA('clientes');
  await dormir(600);
  enfocar('#cardWA');
  await narrar('Esta es su conversación de WhatsApp, la misma que ve el agente.');
  const inp = $('waTexto');
  if (inp) { inp.value = 'Señor Héctor, a 30 meses la cuota queda en 680 soles.'; }
  await clicEn('#waEnviar');
  await narrar('Si hace falta, el supervisor responde por él. El mensaje sale a nombre del agente y queda registrado.');
}},

{n:12, titulo:'La bandeja', sub:'El WhatsApp de todo el piso', correr: async () => {
  irA('conversa');
  await dormir(650);
  enfocar('#cardBandeja');
  await narrar('Y acá está el WhatsApp de todo el piso, con lo que lleva demasiado tiempo sin respuesta.');
}},

{n:13, titulo:'Comunicación', sub:'Del supervisor al piso', correr: async () => {
  S.chatCon = '5003'; irA('comunica');
  await dormir(650);
  enfocar('#cardAcuse');
  await narrar('La promoción del día se manda a todos de una vez, con imagen, y se ve quién la leyó.');
  enfocar('#cardChat');
  await narrar('Al costado, el chat con cada agente. Y cuando uno necesita ayuda, levanta la mano y aparece acá.');
}},

{n:14, titulo:'Calidad', sub:'De la grabación al entrenamiento', correr: async () => {
  irA('calidad');
  await dormir(650);
  enfocar('#cardGrab');
  await narrar('Las llamadas quedan grabadas y puntuadas. Esta se puede escuchar, leer y convertir en entrenamiento.');
  enfocar('#cardMomentos');
  await narrar('Un buen cierre se guarda y se publica en la base de conocimiento que el agente consulta.');
}},

{n:15, titulo:'El equipo', sub:'Asistencia y seguimiento', correr: async () => {
  irA('equipo');
  await dormir(650);
  enfocar('#cardAsist');
  await narrar('Puntualidad de la semana, y las pausas de hoy contra el tope permitido.');
  enfocar('#cardCoach');
  await narrar('Cuando el mismo problema se repite tres veces, deja de ser un susurro y pasa a ser un plan.');
}},

{n:16, titulo:'Reportes', sub:'Lo que el motor ya calcula', correr: async () => {
  irA('reportes');
  await dormir(650);
  enfocar('#cardKpis');
  await narrar('Veinte indicadores que el motor ya calcula: contactabilidad, conversión, abandono, ocupación.');
  enfocar('#cardCurva');
  await narrar('La línea azul es la producción real; la punteada, la meta. Hoy va al ochenta y siete por ciento de lo esperado.');
}},

{n:17, titulo:'Modo TV', sub:'Para la pared del piso', correr: async () => {
  irA('reportes');
  await dormir(400);
  await clicEn('#btnTV');
  aplicarFoco(null);
  await narrar('Y esto se proyecta en la pared del piso.');
  await narrar('Todo lo que vio está en un solo sistema: las llamadas, el WhatsApp, la base, la calidad y el equipo. Sin cambiar de pantalla.');
}},

];

/* ================================================== el reproductor === */
function pintarCabecera() {
  const c = CAPITULOS[P.i];
  if (!c) return;
  $('narraN').textContent = c.n;
  $('narraTit').textContent = c.titulo;
  $('narraSub').textContent = c.sub;
  $('narraProg').style.width = ((P.i + 1) / CAPITULOS.length * 100) + '%';
}

/* Cortar en seco: sube la generación, lo encolado se descarta solo. */
function cortar() {
  P.gen++;
  VOZ.cola = Promise.resolve();
  if (VOZ.audio) { try { VOZ.audio.pause(); } catch (e) {} VOZ.audio = null; }
}

async function correr() {
  while (P.i < CAPITULOS.length) {
    const gen = P.gen;
    pintarCabecera();
    try { await CAPITULOS[P.i].correr(); } catch (e) { if (gen !== P.gen) return; }
    if (gen !== P.gen) return;
    await vozAlDia();
    if (gen !== P.gen) return;
    await dormir(600);
    if (gen !== P.gen) return;
    P.i++;
  }
  aplicarFoco(null);
  $('puntero').hidden = true;
  $('narraTxt').textContent = 'Fin del recorrido. Puede navegar la pantalla a mano desde aquí.';
  P.corriendo = false;
  $('ctlPausa').textContent = '▶';
}

function saltarA(i) {
  cortar();
  cerrarHoja(); cerrarTV(); cerrarEscucha();
  P.i = Math.max(0, Math.min(CAPITULOS.length - 1, i));
  P.corriendo = true;
  $('ctlPausa').textContent = '‖';
  aplicarFoco(null);
  correr();
}

/* Reloj del recorrido. */
function arrancarReloj() {
  setInterval(() => {
    if (!P.corriendo) return;
    P.transcurrido++;
    const m = Math.floor(P.transcurrido / 60), s = P.transcurrido % 60;
    $('narraReloj').textContent = m + ':' + String(s).padStart(2, '0');
  }, 1000);
}

/* El foco sigue al elemento si la ventana cambia de tamaño o se desplaza. */
let focoSel = null;
const _aplicar = aplicarFoco;
aplicarFoco = function (sel) { focoSel = sel; return _aplicar(sel); };
window.addEventListener('resize', () => { if (focoSel) _aplicar(focoSel); });

function iniciarTour() {
  entrarApp();
  document.body.classList.add('is-tour');
  $('narra').hidden = false;
  P.i = 0; P.corriendo = true; P.transcurrido = 0;
  arrancarReloj();
  setTimeout(correr, 500);
}

function salirTour() {
  cortar();
  P.corriendo = false;
  document.body.classList.remove('is-tour');
  $('narra').hidden = true;
  $('foco').hidden = true;
  $('puntero').hidden = true;
}

document.addEventListener('DOMContentLoaded', () => {
  $('btnTour').onclick = iniciarTour;

  $('ctlPausa').onclick = () => {
    P.corriendo = !P.corriendo;
    $('ctlPausa').textContent = P.corriendo ? '‖' : '▶';
    if (VOZ.audio) { try { P.corriendo ? VOZ.audio.play() : VOZ.audio.pause(); } catch (e) {} }
  };
  $('ctlSig').onclick   = () => saltarA(P.i + 1);
  $('ctlAtras').onclick = () => saltarA(P.i - 1);
  $('ctlSalir').onclick = salirTour;
  $('ctlVoz').onclick = () => {
    VOZ.silencio = !VOZ.silencio;
    $('ctlVoz').classList.toggle('is-off', VOZ.silencio);
    $('ctlVoz').title = VOZ.silencio ? 'Activar la voz' : 'Silenciar la voz';
    if (VOZ.silencio && VOZ.audio) { try { VOZ.audio.pause(); } catch (e) {} }
  };
});
