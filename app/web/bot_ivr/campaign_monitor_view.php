<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/../includes/Dialer.php';
use Includes\Dialer;
if(session_status()===PHP_SESSION_NONE)session_start();
if(empty($_SESSION['user']) || !in_array((int)($_SESSION['user_level']??0),[7,8,9],true)){http_response_code(403);exit;}
$monitorError='';$snapshot=[];
try{$snapshot=bot_ivr_repository()->campaignMonitor();}catch(Throwable $e){$monitorError='No se pudo consultar las campanas de bot IVR.';}
try{$snapshot=array_merge($snapshot,Dialer::campaignMonitor());}catch(Throwable $e){$monitorError=trim($monitorError.' No se pudo consultar las campanas de agentes.');}
$monitorTitle=$monitorTitle??'Monitor de campanas';
$canPlay=(int)($_SESSION['user_level']??0)===9;
if($canPlay && empty($_SESSION['campaign_monitor_csrf']))$_SESSION['campaign_monitor_csrf']=bin2hex(random_bytes(32));
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo htmlspecialchars($monitorTitle,ENT_QUOTES,'UTF-8'); ?> - Zynervox</title>
<link rel="stylesheet" href="layout.css">
<style>
/* Paleta categorica (dataviz skill, modo claro, orden fijo por rango dentro de cada card) */
.monitor-grid{
    --series-1: #2a78d6; /* blue */
    --series-2: #eb6834; /* orange */
    --series-3: #1baf7a; /* aqua */
    --series-4: #eda100; /* yellow */
    --series-5: #e87ba4; /* magenta */
    --series-other: var(--text-muted);
    display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;
}
.campaign-card{background:var(--bg-card);border:1px solid var(--border);border-top:4px solid #6b7280;border-radius:8px;padding:18px}
.campaign-card[data-state="running"],.campaign-card[data-state="starting"]{border-top-color:#10b981}
.campaign-card[data-state="stopping"]{border-top-color:#f59e0b}
.campaign-card[data-state="failed"]{border-top-color:#ef4444}
.campaign-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}
.campaign-head a{color:var(--text);font-weight:bold;text-decoration:none}
.head-badges{display:flex;gap:6px;align-items:center}
.origin-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;color:#fff;white-space:nowrap;background:#2a78d6}
.origin-badge[data-origin="agents"]{background:#eb6834}
.state-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;color:#fff;background:#6b7280;white-space:nowrap}
.state-badge[data-state="running"],.state-badge[data-state="starting"]{background:#10b981}
.state-badge[data-state="stopping"]{background:#f59e0b}
.state-badge[data-state="failed"]{background:#ef4444}
.kpi-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.kpi-row .stat-tile{text-align:center;background:var(--glass);padding:10px 6px;border-radius:6px}
.kpi-row .stat-value{display:block;font-size:24px;font-weight:700;color:var(--text)}
.kpi-row .stat-label{font-size:11px;color:var(--text-muted)}
.donut-row{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.donut-chart{margin:0;flex:0 0 110px;text-align:center}
.donut-control{position:relative;width:110px;height:110px}
.donut-control svg{display:block;width:110px;height:110px}
.donut-caption{margin-top:8px;font-size:11px;color:var(--text-muted)}
.donut-caption strong{font-size:16px;color:var(--text)}
.campaign-play{position:absolute;inset:30px;border:0;border-radius:50%;background:var(--series-1);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:transform .18s ease,box-shadow .18s ease,filter .18s ease}
.campaign-play svg{width:24px;height:24px;fill:currentColor;pointer-events:none}
.campaign-play:hover:not(:disabled){filter:brightness(1.08);transform:scale(1.08);box-shadow:0 3px 10px #0002}
.campaign-play:active:not(:disabled){transform:scale(.94)}
.campaign-play:focus-visible{outline:3px solid var(--text);outline-offset:3px}
.campaign-play:disabled{background:var(--glass);color:var(--text-muted);cursor:default}
.campaign-play[data-action="stop"]{background:#c74451;animation:campaign-pulse 2s ease-out infinite}
.campaign-play[data-action="stop"]:disabled{background:var(--glass);color:var(--text-muted);animation:none}
@keyframes campaign-pulse{0%{box-shadow:0 0 0 0 #c7445140}70%,100%{box-shadow:0 0 0 7px #c7445100}}
@media(prefers-reduced-motion:reduce){.campaign-play{transition:none}.campaign-play[data-action="stop"]{animation:none}}
.legend{list-style:none;margin:0;padding:0;font-size:12px;max-width:100%}
.legend li{display:flex;align-items:center;gap:7px;padding:2px 0;color:var(--text)}
.legend .swatch{width:10px;height:10px;border-radius:2px;flex-shrink:0}
.legend .legend-label{overflow-wrap:anywhere;min-width:0}
.legend .legend-value{min-width:5ch;text-align:right;flex-shrink:0;font-variant-numeric:tabular-nums;font-weight:700;color:var(--text-muted)}
.monitor-error{color:#c74451;font-size:13px}
.monitor-note{color:var(--text-muted);font-size:12px;margin:10px 0 20px}
</style></head><body>
<?php if((int)($_SESSION['user_level']??0)===9){require_once __DIR__.'/../modules/admin/sidebar.php';renderSidebar($monitorTitle==='Dashboard'?'home':'campaign_monitor');}else{require_once __DIR__.'/../modules/sup/sidebar.php';renderSupSidebar('campaign_monitor');} ?>
<main class="main-content"><h1><?php echo htmlspecialchars($monitorTitle,ENT_QUOTES,'UTF-8'); ?></h1>
<p class="monitor-note">Estado de las campanas activas &middot; actualizacion cada 5 segundos</p>
<p id="monitor-error" class="monitor-error" role="status"><?php echo htmlspecialchars($monitorError,ENT_QUOTES,'UTF-8'); ?></p>
<p id="monitor-action" class="monitor-note" role="status" aria-live="polite"></p>
<div id="monitor-campaigns" class="monitor-grid"></div>
</main><script>
(() => {
 const grid=document.getElementById('monitor-campaigns'),error=document.getElementById('monitor-error'),actionStatus=document.getElementById('monitor-action');let timer;
 const csrf=<?php echo json_encode($canPlay?$_SESSION['campaign_monitor_csrf']:null); ?>,pending=new Set();
 const labels={idle:'Sin marcacion',starting:'Iniciando',running:'Discando',stopping:'Deteniendo',stopped:'Detenida',completed:'Finalizada',failed:'Error'};
 // Resueltos una vez a hex real: un atributo SVG via setAttribute no siempre
 // interpreta var(--x), a diferencia de una propiedad CSS normal.
 const rootStyle=getComputedStyle(grid);
 const SERIES=[1,2,3,4,5].map(i=>rootStyle.getPropertyValue('--series-'+i).trim());
 const OTHER=rootStyle.getPropertyValue('--series-other').trim();
 const BORDER=rootStyle.getPropertyValue('--border').trim()||'#e5e0d8';
 function el(tag,cls){const e=document.createElement(tag);if(cls)e.className=cls;return e;}
 function text(tag,content,cls){const e=el(tag,cls);e.textContent=content;return e;}
 // Fold la cola de estados en "Otros" (token ceiling de la paleta categorica: top 5 + resto).
 function topStates(states,limit){
  const sorted=[...states].sort((a,b)=>b.count-a.count);
  const top=sorted.slice(0,limit);
  const restCount=sorted.slice(limit).reduce((s,x)=>s+x.count,0);
  return restCount>0 ? [...top,{status:'Otros',count:restCount,other:true}] : top;
 }
 const SVGNS='http://www.w3.org/2000/svg';
 function svgEl(tag,attrs){const e=document.createElementNS(SVGNS,tag);for(const k in attrs)e.setAttribute(k,attrs[k]);return e;}
 function buildDonut(states,total){
  const r=42,cx=50,cy=50,strokeWidth=16,C=2*Math.PI*r,GAP=3;
  const top=topStates(states,5);
  const svg=svgEl('svg',{viewBox:'0 0 100 100',role:'img','aria-label':'Distribucion de leads por estado'});
  const bg=svgEl('circle',{cx,cy,r,fill:'none',stroke:BORDER,'stroke-width':strokeWidth});
  svg.append(bg);
  const group=svgEl('g',{transform:'rotate(-90 '+cx+' '+cy+')'});
  let offset=0;
  top.forEach((s,i)=>{
   const frac=total>0 ? s.count/total : 0;
   const len=frac*C;
   const visible=Math.max(0,len-GAP);
   const arc=svgEl('circle',{cx,cy,r,fill:'none',stroke:s.other?OTHER:SERIES[i],'stroke-width':strokeWidth,'stroke-linecap':'butt','stroke-dasharray':visible+' '+(C-visible),'stroke-dashoffset':-offset});
   group.append(arc);
   offset+=len;
  });
  svg.append(group);
  return {svg,top};
 }
 function render(rows){grid.replaceChildren();
  if(!rows.length){grid.append(text('p','No hay campanas activas.'));return;}
  for(const c of rows){
   const card=el('section','campaign-card');card.dataset.state=c.state;
   const head=el('div','campaign-head');const link=text('a','#'+c.campaign_id+' - '+c.name);
   link.href=c.origin==='agents' ? '../../modules/admin/dialer_campaign_edit.php?id='+c.campaign_id : '../../bot_ivr/campaign_edit.php?id='+c.campaign_id;
   const badges=el('div','head-badges');
   badges.append(text('span',c.origin==='agents'?'Agentes':'Bot IVR','origin-badge'));badges.lastChild.dataset.origin=c.origin;
   badges.append(text('span',labels[c.state]||c.state,'state-badge'));badges.lastChild.dataset.state=c.state;
   head.append(link,badges);card.append(head);
   const kpi=el('div','kpi-row');
   for(const [value,label] of [[c.total,'Total leads'],[c.calling,'Llamando'],[c.finished,'Terminados']]){
    const tile=el('div','stat-tile');tile.append(text('span',String(value),'stat-value'),text('span',label,'stat-label'));kpi.append(tile);
   }
   card.append(kpi);
   const row=el('div','donut-row');
   if(!c.states.length){row.append(text('p','Sin leads cargados.'));card.append(row);grid.append(card);continue;}
   const {svg,top}=buildDonut(c.states,c.total);
   const chart=el('figure','donut-chart'),control=el('div','donut-control');control.append(svg);
   if(csrf){
    const busy=['starting','running','stopping'].includes(c.state);
    const action=busy?'stop':'play';
    const button=el('button','campaign-play');button.type='button';button.dataset.action=action;
    const icon=svgEl('svg',{viewBox:'0 0 24 24','aria-hidden':'true',focusable:'false'});
    icon.append(busy?svgEl('rect',{x:6,y:6,width:12,height:12,rx:2}):svgEl('path',{d:'M9 6 L18 12 L9 18 Z'}));button.append(icon);
    button.disabled=c.state==='stopping'||pending.has(c.origin+':'+c.campaign_id);
    button.title=c.state==='stopping'?'Deteniendo campana':busy?'Parar nuevas llamadas':'Iniciar campana';
    button.setAttribute('aria-label',(busy?'Parar campana ':'Iniciar campana ')+c.name);
    button.addEventListener('click',()=>controlCampaign(c,button,action));control.append(button);
   }
   const caption=el('figcaption','donut-caption');
   caption.append(text('strong',(c.total>0?Math.round(c.finished/c.total*100):0)+'%'),document.createTextNode(' Terminados'));
   chart.append(control,caption);row.append(chart);
   const legend=el('ul','legend');
   top.forEach((s,i)=>{
    const li=el('li');
    const swatch=el('span','swatch');swatch.style.background=s.other?OTHER:SERIES[i];
    li.append(text('span',String(s.count),'legend-value'),swatch,text('span',s.status,'legend-label'));
    legend.append(li);
   });
   row.append(legend);card.append(row);
   grid.append(card);
  }
 }
 async function controlCampaign(c,button,action){
  const key=c.origin+':'+c.campaign_id;if(pending.has(key))return;
  pending.add(key);button.disabled=true;actionStatus.textContent=(action==='stop'?'Deteniendo ':'Iniciando ')+c.name+'...';
  try{
   const response=await fetch('campaign_monitor_api.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams({action,csrf,origin:c.origin,campaign_id:String(c.campaign_id)})});
   const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo controlar la campana.');
   actionStatus.textContent=data.message;
  }catch(e){actionStatus.textContent=e.message;}
  finally{pending.delete(key);await poll();}
 }
 async function poll(){clearTimeout(timer);if(document.hidden)return;try{const response=await fetch('campaign_monitor_api.php',{cache:'no-store',credentials:'same-origin'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo actualizar el monitor.');render(data.campaigns);error.textContent='';}catch(e){error.textContent=e.message;}timer=setTimeout(poll,5000);}
 render(<?php echo json_encode($snapshot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);timer=setTimeout(poll,5000);
 document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(!document.hidden)poll();});
})();
</script></body></html>
