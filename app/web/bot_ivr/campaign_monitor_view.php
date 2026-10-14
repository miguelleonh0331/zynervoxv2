<?php
require_once __DIR__.'/db.php';
if(session_status()===PHP_SESSION_NONE)session_start();
if(empty($_SESSION['user']) || !in_array((int)($_SESSION['user_level']??0),[7,8,9],true)){http_response_code(403);exit;}
$monitorError='';$snapshot=[];
try{$snapshot=bot_ivr_repository()->campaignMonitor();}catch(Throwable $e){$monitorError='No se pudo consultar las campanas.';}
$monitorTitle=$monitorTitle??'Monitor de campanas';
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
.state-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;color:#fff;background:#6b7280;white-space:nowrap}
.state-badge[data-state="running"],.state-badge[data-state="starting"]{background:#10b981}
.state-badge[data-state="stopping"]{background:#f59e0b}
.state-badge[data-state="failed"]{background:#ef4444}
.kpi-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.kpi-row .stat-tile{text-align:center;background:var(--glass);padding:10px 6px;border-radius:6px}
.kpi-row .stat-value{display:block;font-size:24px;font-weight:700;color:var(--text)}
.kpi-row .stat-label{font-size:11px;color:var(--text-muted)}
.donut-row{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.donut-row svg{flex:0 0 auto;width:110px;height:110px}
.donut-center-pct{font-size:20px;font-weight:700;fill:var(--text)}
.donut-center-label{font-size:8px;fill:var(--text-muted);text-transform:uppercase;letter-spacing:.3px}
.legend{list-style:none;margin:0;padding:0;font-size:12px;flex:1;min-width:160px}
.legend li{display:flex;align-items:center;gap:7px;padding:2px 0;color:var(--text)}
.legend .swatch{width:10px;height:10px;border-radius:2px;flex-shrink:0}
.legend .legend-label{flex:1;overflow-wrap:anywhere}
.legend .legend-value{font-variant-numeric:tabular-nums;font-weight:700;color:var(--text-muted)}
.monitor-error{color:#c74451;font-size:13px}
.monitor-note{color:var(--text-muted);font-size:12px;margin:10px 0 20px}
</style></head><body>
<?php if((int)($_SESSION['user_level']??0)===9){require_once __DIR__.'/../modules/admin/sidebar.php';renderSidebar($monitorTitle==='Dashboard'?'home':'campaign_monitor');}else{require_once __DIR__.'/../modules/sup/sidebar.php';renderSupSidebar('campaign_monitor');} ?>
<main class="main-content"><h1><?php echo htmlspecialchars($monitorTitle,ENT_QUOTES,'UTF-8'); ?></h1>
<p class="monitor-note">Estado de las campanas activas &middot; actualizacion cada 5 segundos</p>
<p id="monitor-error" class="monitor-error" role="status"><?php echo htmlspecialchars($monitorError,ENT_QUOTES,'UTF-8'); ?></p>
<div id="monitor-campaigns" class="monitor-grid"></div>
</main><script>
(() => {
 const grid=document.getElementById('monitor-campaigns'),error=document.getElementById('monitor-error');let timer;
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
 function buildDonut(states,total,finished){
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
  const pct=total>0 ? Math.round(finished/total*100) : 0;
  const pctText=svgEl('text',{x:cx,y:cy-2,'text-anchor':'middle',class:'donut-center-pct'});pctText.textContent=pct+'%';
  const labelText=svgEl('text',{x:cx,y:cy+10,'text-anchor':'middle',class:'donut-center-label'});labelText.textContent='Terminados';
  svg.append(pctText,labelText);
  return {svg,top};
 }
 function render(rows){grid.replaceChildren();
  if(!rows.length){grid.append(text('p','No hay campanas activas.'));return;}
  for(const c of rows){
   const card=el('section','campaign-card');card.dataset.state=c.state;
   const head=el('div','campaign-head');const link=text('a','#'+c.campaign_id+' - '+c.name);link.href='../../bot_ivr/campaign_edit.php?id='+c.campaign_id;
   head.append(link,text('span',labels[c.state]||c.state,'state-badge'));head.lastChild.dataset.state=c.state;card.append(head);
   const kpi=el('div','kpi-row');
   for(const [value,label] of [[c.total,'Total leads'],[c.calling,'Llamando'],[c.finished,'Terminados']]){
    const tile=el('div','stat-tile');tile.append(text('span',String(value),'stat-value'),text('span',label,'stat-label'));kpi.append(tile);
   }
   card.append(kpi);
   const row=el('div','donut-row');
   if(!c.states.length){row.append(text('p','Sin leads cargados.'));card.append(row);grid.append(card);continue;}
   const {svg,top}=buildDonut(c.states,c.total,c.finished);row.append(svg);
   const legend=el('ul','legend');
   top.forEach((s,i)=>{
    const li=el('li');
    const swatch=el('span','swatch');swatch.style.background=s.other?OTHER:SERIES[i];
    li.append(swatch,text('span',s.status,'legend-label'),text('span',String(s.count),'legend-value'));
    legend.append(li);
   });
   row.append(legend);card.append(row);
   grid.append(card);
  }
 }
 async function poll(){clearTimeout(timer);if(document.hidden)return;try{const response=await fetch('campaign_monitor_api.php',{cache:'no-store',credentials:'same-origin'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo actualizar el monitor.');render(data.campaigns);error.textContent='';}catch(e){error.textContent=e.message;}timer=setTimeout(poll,5000);}
 render(<?php echo json_encode($snapshot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);timer=setTimeout(poll,5000);
 document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(!document.hidden)poll();});
})();
</script></body></html>
