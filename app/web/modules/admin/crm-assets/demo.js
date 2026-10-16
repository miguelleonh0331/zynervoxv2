'use strict';
const $=id=>document.getElementById(id);
let layout,forms,main=null,modal=null,loggedIn=false,selected=0,phoneEdit=-1;
const values=new Map();
const demo={
 contacts:[
  {id:1001,document:'00000001',name:'ANA DEMO PÉREZ',email:'ana@example.invalid',phone:'900000001',address:'DIRECCIÓN DE DEMOSTRACIÓN 123',policy:'DEMO-001'},
  {id:1002,document:'00000002',name:'LUIS DEMO TORRES',email:'luis@example.invalid',phone:'900000002',address:'DIRECCIÓN DE DEMOSTRACIÓN 456',policy:'DEMO-002'},
  {id:1003,document:'00000003',name:'MARÍA DEMO ROJAS',email:'maria@example.invalid',phone:'900000003',address:'DIRECCIÓN DE DEMOSTRACIÓN 789',policy:'DEMO-003'}],
 history:[],sales:[],recalls:[],phones:['900000001','900000004'],saleDraft:null
};
const contact=()=>demo.contacts[selected]||demo.contacts[0];
const title=s=>String(s||'').replaceAll('&','');
const OPTIONS={
 cbCriterio:['DNI','NOMBRES Y APELLIDOS','TELÉFONO','ID CONTACTO'],CboCriterio:['DNI','NOMBRES Y APELLIDOS','ASESOR'],
 cb_Gestion01:['CONTACTADO','NO CONTACTADO'],cb_Gestion02:['INTERESADO','VENTA','RELLAMADA','NO INTERESADO'],cb_Gestion03:['SEGUIMIENTO','VENTA CONFIRMADA','VOLVER A LLAMAR'],
 CbTIPO_DOCUMENTO:['DNI','CE','PASAPORTE'],CbSexo:['FEMENINO','MASCULINO'],CbESTADO_CIVIL:['SOLTERO/A','CASADO/A','CONVIVIENTE'],
 CbDEPARTAMENTO:['LIMA','AREQUIPA'],CbPROVINCIA:['LIMA','AREQUIPA'],CbDISTRITO:['LIMA','MIRAFLORES','SAN ISIDRO'],
 CbPROTECCION:['10,000','20,000','50,000'],CbTIEMPO:['5 AÑOS','10 AÑOS','20 AÑOS'],CbFRECUENCIA_PAGO:['MENSUAL','TRIMESTRAL','ANUAL'],
 CbPorcentajeRetorno:['0%','50%','100%'],CbSituacionLaboral:['DEPENDIENTE','INDEPENDIENTE'],CbNivelEducacion:['SECUNDARIA','TÉCNICA','UNIVERSITARIA'],
 CbCanalVentas:['TELEVENTAS','REFERIDO'],CbTipoTarjeta:['DÉBITO','CRÉDITO'],CbMarcaTarjeta:['VISA','MASTERCARD'],CbTipoSeguro:['VIDA','INVERSION_SEGURA'],
 CbMedioLLamada:['ENTRANTE','SALIENTE'],CmbBanco:['BANCO DEMO'],CmbMoneda:['SOLES','DOLARES'],CmbBase:['BASE DEMO'],
 'CmbCampañas':['CRM VENTAS'],cmbusuario:['OPERADOR DEMO'],CbTipoEstado:['PENDIENTE','VALIDADO','OBSERVADO'],CbEstado:['POR REVISAR','APROBADO','RECHAZADO'],CbSubEstado:['EN PROCESO','COMPLETO','SUBSANAR'],
 CboEstado1:['PENDIENTE','VALIDADO','OBSERVADO'],CboEstado2:['POR REVISAR','APROBADO','RECHAZADO'],CboEstado3:['EN PROCESO','COMPLETO','SUBSANAR'],
 cbTipoTelefono:['MÓVIL','FIJO','TRABAJO'],CbTipoTelefono:['MÓVIL','FIJO','TRABAJO']
};
const BUTTON_HINTS={btnGrabar:'Guardar',btnBuscar:'Buscar contacto',BtnTerminar:'Terminar atención',Btn_BuscarPrevio:'Buscar',btn_LimpiarPrevio:'Limpiar búsqueda',btnBuscarAgendados:'Buscar agendados',btn_BuscarRellamado:'Buscar rellamadas',btnLimpiarRellamado:'Limpiar rellamadas',BtnBuscarVentasAgentes:'Buscar ventas',BtnCancelar:'Cancelar',btnCancelar:'Cancelar',BtnAsignados:'Leads asignados'};
const MENUS=[
 ['Archivo',[['Atención telefónica','Frm_AtencionTelefonica'],['Cerrar ventana','close'],['—'],['Cerrar sesión','logout'],['Salir','logout']]],
 ['Gestión',[['Atención telefónica','Frm_AtencionTelefonica'],['Venta','Frm_Venta'],['Rellamada','Frm_Rellamado'],['Leads asignados','Frm_LeadsAsignados'],['Referido','Form_Referido']]],
 ['Consultas',[['Back Office','Frm_BackOffice'],['Trama','Frm_Trama']]],
 ['Mantenimiento',[['Teléfonos','Frm_TelefonosMantemiento'],['Cargar base','Frm_CargarBase'],['Actualizar campos','Frm_Act_Campos'],['Cambio de estado','Frm_CambioEstado'],['Eliminar gestiones','Frm_EliminarGestiones']]],
 ['Ayuda',[['Acerca de','about']]]
];
function status(text){$('status').textContent=text+(currentUser?' · '+currentUser.name:'');}
function font(node,el){if(!node)return;el.style.fontFamily='"'+node.family+'",sans-serif';el.style.fontSize=node.pt+'pt';el.style.fontWeight=node.bold?'700':'400';el.style.fontStyle=node.italic?'italic':'normal';}
function applyStyle(node,el,bounds){
 const [x,y,w,h]=bounds;Object.assign(el.style,{left:x+'px',top:y+'px',width:Math.max(0,w)+'px',height:Math.max(0,h)+'px'});
 if(node.BackColor==='Transparent')el.style.background='transparent';
 else if(node.BackColor)el.style.backgroundColor=node.BackColor;
 if(node.ForeColor)el.style.color=node.ForeColor==='Transparent'?'#fff':node.ForeColor;
 font(node.Font,el);if(node.Visible===false)el.hidden=true;
 if(node.AutoScroll)el.style.overflow='auto';
 el.dataset.control=node.id;
}
function boundsFor(parent,children,size){
 const group=parent.type==='GroupBox',available={x:group?3:0,y:group?22:0,w:size[0]-(group?6:0),h:size[1]-(group?25:0)},result={};
 for(const node of [...children].reverse()){
  let [x,y]=node.Location||[0,0];let [w,h]=node.Size||[75,23];
  if(node.Visible!==false){
   if(node.Dock==='Bottom'){x=available.x;y=available.y+available.h-h;w=available.w;available.h-=h;}
   else if(node.Dock==='Top'){x=available.x;y=available.y;w=available.w;available.y+=h;available.h-=h;}
   else if(node.Dock==='Right'){x=available.x+available.w-w;y=available.y;h=available.h;available.w-=w;}
   else if(node.Dock==='Left'){x=available.x;y=available.y;h=available.h;available.x+=w;available.w-=w;}
   else if(node.Dock==='Fill'){x=available.x;y=available.y;w=available.w;h=available.h;}
  }
  result[node.id]=[x,y,w,h];
 }
 return result;
}
function defaults(formId,id){
 const c=contact();
 const map={UsernameTextBox:'',PasswordTextBox:'',txtIdContacto:c.id,TxtId_Contacto:c.id,TxtIdContacto:c.id,lblUsuario:currentUser?.name||'',BtnAsignados:String(demo.contacts.length),
  TxtPRIMER_NOMBRE:c.name.split(' ')[0],TxtSEGUNDO_NOMBRE:'',TxtAPELLIDO_PATERNO:'DEMO',TxtAPELLIDO_MATERNO:'PÉREZ',TxtNUMERO_DOCUMENTO:c.document,TxtCORREO_01:c.email,TxtMOVIL_01:c.phone,TxtDIRECCION:c.address,TxtPoliza:c.policy,
  TxtIMPORTE_PACTADO:'100.00',TxtMontoRetorno:'0.00',TxtTrea:'0.00',TxtPorcentajeRetorno:'0',txtcanal:'TELEVENTAS',
  TxtNombreCampañaDiscador:'CRM VENTAS',TxtAnexoDiscador:'100',TxtContactoInconcert:c.id,TxtTelefonoContactado:c.phone,TxtIdAgente:'DEMO',TxtBaseInconcert:'BASE DEMO',TxtLoteInconcert:'LOTE DEMO',TxtIdLlamada:'DEMO-0001',
  TxtIdAudio:'DEMO SIN TOKEN',TxtPaisInconcert:'PE',TxtAreaInconcert:'01',TxtProcesoInconcert:'DEMO',TxtIdInconcert:'DEMO',
  lblRegistro:'3',lblRegistros:String(Math.max(demo.sales.length,3)),TxtNroDoc:'00000004',TxtNomApe:'CONTACTO REFERIDO DEMO',TxtCorreo:'referido@example.invalid',Txttelefono:'900000005',TxtMontoInvertido:'0.00'};
 if(formId==='Frm_TelefonosMantemiento'&&id==='txtNumeroTelefono')return phoneEdit>=0?demo.phones[phoneEdit]:'';
 if(id==='txt_IdTelefono')return phoneEdit>=0?phoneEdit+1:0;
 return map[id];
}
function makeTable(ctx,node){
 const el=document.createElement('div');el.className='wf-control wf-list';el.style.color=node.ForeColor||'#000';el.dataset.list=node.id;
 let columns=(node.columns||[]).map(id=>ctx.form.controls[id]).filter(Boolean).map(c=>({title:title(c.Text||''),width:c.Width||90}));
 let rows=[];
 const c=contact();const contacts=demo.contacts.map(x=>[x.id,x.document,x.name,x.phone,'SI']);
 if(/contacto$/i.test(node.id)){
  columns=[{title:'DATOS',width:170},{title:'INFORMACIÓN',width:280}];
  rows=[['ID CONTACTO',c.id],['DOCUMENTO',c.document],['NOMBRES Y APELLIDOS',c.name],['TELÉFONO',c.phone],['CORREO',c.email],['DIRECCIÓN',c.address],['CAMPAÑA','CRM VENTAS'],['BASE','BASE DEMO'],['PÓLIZA',c.policy],['MONEDA','SOLES'],['PRODUCTO','VIDA'],['PRIMA INFORMADA','100.00'],['VIGENCIA','10 AÑOS']];
 }else if(/telefon/i.test(node.id)){columns=[{title:'ID',width:40},{title:'TIPO',width:100},{title:'TELÉFONO',width:170},{title:'ACTIVO',width:90}];rows=demo.phones.map((p,i)=>[i+1,'MÓVIL',p,'SI']);}
 else if(/historial/i.test(node.id)){columns=[{title:'ID GESTIÓN',width:85},{title:'FECHA',width:145},{title:'ASESOR',width:140},{title:'GESTIÓN 1',width:120},{title:'GESTIÓN 2',width:140},{title:'GESTIÓN 3',width:140},{title:'NOTA',width:330}];rows=demo.history.length?demo.history:[['DEMO',new Date().toLocaleString('es-PE'),'OPERADOR DEMO','CONTACTADO','INTERESADO','SEGUIMIENTO','Registro ficticio para la demostración visual.']];}
 else if(/estado/i.test(node.id)){columns=[{title:'',width:22},{title:'ID',width:35},{title:'DESCRIPCIÓN',width:230}];rows=[['□',1,'PENDIENTE'],['□',2,'VALIDADO'],['□',3,'OBSERVADO']];}
 else if(/venta|trama/i.test(node.id)){columns=[{title:'ID VENTA',width:75},{title:'ID CONTACTO',width:90},{title:'DOCUMENTO',width:100},{title:'NOMBRES Y APELLIDOS',width:230},{title:'ASESOR',width:130},{title:'ESTADO',width:110},{title:'SUBESTADO',width:120}];rows=demo.contacts.map((x,i)=>[i+1,x.id,x.document,x.name,'OPERADOR DEMO','PENDIENTE','POR REVISAR']);}
 else if(/rellam|agendad/i.test(node.id)){columns=[{title:'CONTACTO',width:75},{title:'FECHA',width:120},{title:'NOMBRES',width:210},{title:'NOTA',width:190}];rows=demo.recalls.length?demo.recalls:demo.contacts.map(x=>[x.id,new Date().toLocaleDateString('es-PE'),x.name,'RELLAMADA DEMO']);}
 else{if(!columns.length)columns=[{title:'ID',width:75},{title:'DOCUMENTO',width:95},{title:'NOMBRES Y APELLIDOS',width:230},{title:'TELÉFONO',width:110},{title:'ACTIVO',width:65}];rows=contacts;}
 const table=document.createElement('table'),head=document.createElement('thead'),tr=document.createElement('tr');
 for(const col of columns){const th=document.createElement('th');th.textContent=col.title;th.style.minWidth=col.width+'px';tr.append(th);}head.append(tr);table.append(head);
 const body=document.createElement('tbody');
 rows.forEach((row,index)=>{const tr=document.createElement('tr');tr.dataset.row=index;row.forEach(value=>{const td=document.createElement('td');td.textContent=String(value??'');tr.append(td);});tr.addEventListener('click',()=>{body.querySelectorAll('tr').forEach(t=>t.classList.toggle('selected',t===tr));ctx.selection[node.id]=index;});tr.addEventListener('dblclick',()=>rowAction(ctx,node,index));body.append(tr);});
 table.append(body);el.append(table);
 el.addEventListener('contextmenu',event=>{event.preventDefault();if(/telefon/i.test(node.id)){phoneEdit=ctx.selection[node.id]??0;message('Teléfonos','Selecciona la operación para el teléfono de demostración.',[['Nuevo',()=>{phoneEdit=-1;openModal('Frm_TelefonosMantemiento');}],['Modificar',()=>openModal('Frm_TelefonosMantemiento')],['Eliminar',()=>{demo.phones.splice(phoneEdit,1);refresh();persistWorkspace().catch(showDesktopError);}]]);}else if(/venta|historial/i.test(node.id))openModal('Frm_Venta');});
 return el;
}
function createControl(ctx,node,bounds){
 const type=node.type;let el;
 if(['Timer','ToolTip','OpenFileDialog','SaveFileDialog','NotifyIcon','ContextMenuStrip','MenuStrip','ColumnHeader','ToolStripMenuItem'].includes(type))return null;
 if(type==='TabControl'){
  el=document.createElement('div');el.className='wf-control wf-tabs';
  const tabs=node.children.map(id=>ctx.form.controls[id]).filter(x=>x?.type==='TabPage');
  const strip=document.createElement('div');strip.className='tab-strip';const current=tabs[Math.max(0,Math.min(tabs.length-1,node.SelectedIndex||0))]?.id;
  const select=id=>{strip.querySelectorAll('button').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.page===id)));for(const tab of tabs){const page=ctx.elements[tab.id];if(page)page.hidden=tab.id!==id;}};
  for(const tab of tabs){const button=document.createElement('button');button.type='button';button.textContent=title(tab.Text);button.dataset.page=tab.id;button.setAttribute('role','tab');button.addEventListener('click',()=>select(tab.id));strip.append(button);}
  el.append(strip);addChildren(ctx,node,el,[bounds[2],bounds[3]]);select(current);strip.style.height=(tabs[0]?.Location?.[1]||22)+'px';strip.style.backgroundColor=node.BackColor||ctx.form.BackColor;
 }else if(['GroupBox','Panel','TabPage','SplitContainer'].includes(type)){
  el=document.createElement('div');el.className='wf-control '+(type==='GroupBox'?'wf-group':type==='TabPage'?'wf-tab-page':'wf-panel');
  if(type==='GroupBox'){const legend=document.createElement('span');legend.className='group-title';legend.textContent=title(node.Text);legend.style.backgroundColor=node.BackColor||ctx.form.BackColor;el.append(legend);}
  addChildren(ctx,node,el,[bounds[2],bounds[3]]);
 }else if(type==='Label'||type==='LinkLabel'){
  el=document.createElement('div');el.className='wf-control wf-label';el.textContent=defaults(ctx.form.id,node.id)??title(node.Text);
  if(node.TextAlign?.includes('Center'))el.classList.add('center');if(node.TextAlign?.includes('Right'))el.classList.add('right');
 }else if(type==='TextBox'||type==='RichTextBox'){
  el=document.createElement(node.Multiline?'textarea':'input');el.className='wf-control wf-input'+(node.Multiline?' wf-textarea':'');
  el.value=values.get(ctx.form.id)?.[node.id]??defaults(ctx.form.id,node.id)??node.Text??'';
  if(node.id==='PasswordTextBox')el.type='password';if(node.ReadOnly)el.readOnly=true;
  if(node.MaxLength)el.maxLength=node.MaxLength;el.setAttribute('aria-label',node.id);el.autocomplete='off';
  el.addEventListener('keydown',event=>{if(event.key==='Enter'&&!node.Multiline){event.preventDefault();action(ctx,ctx.form.id==='Frm_Login'?'OK':ctx.form.id==='Frm_AtencionTelefonica'?'btnBuscar':'btnGrabar');}});
 }else if(type==='ComboBox'){
  el=document.createElement('select');el.className='wf-control wf-select';el.setAttribute('aria-label',node.id);
  const list=node.options||OPTIONS[node.id]||['DEMO 1','DEMO 2','DEMO 3'];
  const blank=document.createElement('option');blank.value='';blank.textContent='';el.append(blank);
  for(const value of list){const option=document.createElement('option');option.value=option.textContent=title(value);el.append(option);}
  el.value=values.get(ctx.form.id)?.[node.id]||'';el.addEventListener('change',()=>{
   if(node.id==='cb_Gestion03'){const save=ctx.elements.btnGrabar;if(save)save.disabled=false;if(el.value==='VENTA CONFIRMADA')openModal('Frm_Venta');if(el.value==='VOLVER A LLAMAR')openModal('Frm_Rellamado');}
   if(node.id==='cb_Gestion01')for(const id of ['cb_Gestion02','cb_Gestion03'])if(ctx.elements[id])ctx.elements[id].value='';
   if(node.id==='cb_Gestion02'&&ctx.elements.cb_Gestion03)ctx.elements.cb_Gestion03.value='';
  });
 }else if(type==='DateTimePicker'){
  el=document.createElement('input');el.className='wf-control wf-input';el.type=node.Format==='Time'?'time':'date';el.value=values.get(ctx.form.id)?.[node.id]||(el.type==='time'?'10:00':new Date().toLocaleDateString('en-CA'));el.setAttribute('aria-label',node.id);
 }else if(['CheckBox','RadioButton'].includes(type)){
  el=document.createElement('label');el.className='wf-control wf-checkbox';const input=document.createElement('input');input.type=type==='RadioButton'?'radio':'checkbox';input.checked=values.get(ctx.form.id)?.[node.id]??node.Checked??false;const span=document.createElement('span');span.textContent=title(node.Text);el.append(input,span);
 }else if(type==='Button'){
  el=document.createElement('button');el.type='button';el.className='wf-control wf-button';const caption=defaults(ctx.form.id,node.id)??title(node.Text);el.title=caption||BUTTON_HINTS[node.id]||node.id;
  el.setAttribute('aria-label',el.title);
  if(node.FlatStyle==='Flat')el.classList.add('flat');
  if(node.Image){const img=document.createElement('img');img.src=node.Image;img.alt='';el.append(img);el.classList.add(caption?'has-text':'icon-only');}
  if(caption){const span=document.createElement('span');span.textContent=caption;el.append(span);}
  if(node.TextImageRelation==='TextAboveImage')el.classList.add('text-above');if(node.TextImageRelation==='ImageAboveText')el.classList.add('image-above');
  if(bounds[2]<30&&bounds[3]>100)el.style.writingMode='vertical-rl';
  el.addEventListener('click',()=>action(ctx,node.id));
 }else if(type==='PictureBox'){
  el=document.createElement('img');el.className='wf-control wf-picture';el.src=node.Image||'';el.alt='';if(node.SizeMode!=='StretchImage')el.classList.add('center-image');
 }else if(type==='ListView'||type==='DataGridView')el=makeTable(ctx,node);
 else if(type==='StatusStrip'){el=document.createElement('div');el.className='wf-control wf-status';el.textContent='Estado';}
 else if(type==='ProgressBar'){el=document.createElement('div');el.className='wf-control wf-progress';}
 else return null;
 applyStyle(node,el,bounds);if(node.Enabled===false&&'disabled' in el)el.disabled=true;
 if(node.type==='Button'&&['btnGrabar','BtnBuscar'].includes(node.id)&&node.Enabled===false)el.disabled=false;
 ctx.elements[node.id]=el;return el;
}
function addChildren(ctx,parent,container,size){
 const children=parent.children.map(id=>ctx.form.controls[id]).filter(Boolean),bounds=boundsFor(parent,children,size);
 for(const node of [...children].reverse()){const el=createControl(ctx,node,bounds[node.id]);if(el)container.append(el);}
}
function snapshot(ctx){if(!ctx)return;const data={};for(const [id,el]of Object.entries(ctx.elements)){if(['INPUT','SELECT','TEXTAREA'].includes(el.tagName))data[id]=el.value;else if(el.classList.contains('wf-checkbox'))data[id]=el.querySelector('input').checked;}values.set(ctx.form.id,data);}
function windowFor(id,isModal){
 const form=forms.get(id),size=form.ClientSize||[800,600];const wrap=document.createElement('div');wrap.className='window-scaler';
 const window=document.createElement('section');window.className='win-window';window.style.width=(size[0]+2)+'px';window.style.height=(size[1]+28)+'px';
 const header=document.createElement('div');header.className='win-caption';const icon=document.createElement('span');icon.textContent='▣';const name=document.createElement('span');name.className='caption-text';name.textContent=form.Text||id;header.append(icon,name);
 const close=document.createElement('button');close.className='close';close.textContent='×';close.setAttribute('aria-label','Cerrar '+name.textContent);close.addEventListener('click',()=>isModal?closeModal():closeMain());header.append(close);window.append(header);
 const body=document.createElement('div');body.className='win-body';body.style.width=size[0]+'px';body.style.height=size[1]+'px';body.style.backgroundColor=form.BackColor;body.style.color=form.ForeColor==='Transparent'?'#fff':form.ForeColor||'#000';font(form.Font,body);window.append(body);wrap.append(window);
 const ctx={form,elements:{},selection:{},wrap,window,body,isModal};addChildren(ctx,form,body,size);return ctx;
}
function scale(ctx){
 if(!ctx)return;const size=ctx.form.ClientSize;const maxW=ctx.isModal?innerWidth-16:$('workspace').clientWidth-8,maxH=ctx.isModal?innerHeight-20:$('workspace').clientHeight-8;
 const selectedZoom=$('zoom').value;const factor=selectedZoom==='fit'?Math.min(1,Math.max(.35,ctx.isModal||ctx.form.id==='Frm_Login'?Math.min(maxW/(size[0]+2),maxH/(size[1]+28)):maxW/(size[0]+2))):Number(selectedZoom);
 ctx.window.style.transform=`scale(${factor})`;ctx.wrap.style.width=(size[0]+2)*factor+'px';ctx.wrap.style.height=(size[1]+28)*factor+'px';
 if(ctx.form.id==='Frm_Login'&&!ctx.isModal){ctx.wrap.style.margin=Math.max(0,(maxH-(size[1]+28)*factor)/2)+'px auto';}
}
function menus(){
 $('menu').replaceChildren();if(!loggedIn)return;
 for(const [label,items] of MENUS){const details=document.createElement('details'),summary=document.createElement('summary');summary.textContent=label;details.append(summary);const list=document.createElement('div');list.className='menu-dropdown';
  for(const [caption,target] of items){if(caption==='—'){const hr=document.createElement('div');hr.className='menu-separator';list.append(hr);continue;}const button=document.createElement('button');button.textContent=caption;button.addEventListener('click',()=>{details.open=false;if(target==='logout'){logoutDesktop();}else if(target==='close')closeMain();else if(target==='about')message('CRM Ventas','Atención telefónica, gestiones, ventas y rellamadas. Almacenamiento local por usuario.');else if(['Frm_Venta','Frm_Rellamado','Form_Referido','Frm_TelefonosMantemiento'].includes(target))openModal(target);else showForm(target);});list.append(button);}
  details.append(list);details.addEventListener('toggle',()=>{if(details.open)$('menu').querySelectorAll('details').forEach(d=>{if(d!==details)d.open=false;});});$('menu').append(details);
 }
 const tag=document.createElement('span');tag.className='demo-tag';tag.textContent='ESCRITORIO WEB';$('menu').append(tag);
}
function showForm(id){snapshot(main);if(main?.form.id!==id&&id==='Frm_AtencionTelefonica')values.delete(id);main=windowFor(id,false);$('workspace').replaceChildren(main.wrap);menus();scale(main);$('appCaption').textContent=id==='Frm_Login'?'CRM VENTAS V.2026.09.25':'FORMULARIO PRINCIPAL   CAMPAÑA: "CRM VENTAS"   '+(currentUser?.name||'');status(id==='Frm_Login'?needsSetup?'Primer acceso: elige usuario y contraseña de al menos 12 caracteres':'Ingresa tu usuario y contraseña':title(main.form.Text));}
function refresh(){if(main){snapshot(main);showForm(main.form.id);}}
function closeMain(){snapshot(main);main=null;$('workspace').innerHTML='<div class="empty-desktop">CRM VENTAS</div>';status('Selecciona una opción del menú');}
function openModal(id){snapshot(main);if(modal)snapshot(modal);modal=windowFor(id,true);$('modalLayer').replaceChildren();const shade=document.createElement('div');shade.className='dialog-shade';const wrap=document.createElement('div');wrap.className='modal-wrap';wrap.setAttribute('role','dialog');wrap.setAttribute('aria-modal','true');wrap.setAttribute('aria-label',modal.form.Text||id);wrap.append(modal.wrap);$('modalLayer').append(shade,wrap);scale(modal);modal.body.querySelector('input,select,textarea,button')?.focus();}
function closeModal(){if(modal)snapshot(modal);modal=null;$('modalLayer').replaceChildren();}
function message(caption,text,actions=[['Aceptar',()=>{}]]){
 const previous=Array.from($('modalLayer').childNodes);$('modalLayer').replaceChildren();const shade=document.createElement('div');shade.className='dialog-shade';const wrap=document.createElement('div');wrap.className='modal-wrap';const win=document.createElement('section');win.className='win-window';win.style.position='relative';
 const head=document.createElement('div');head.className='win-caption';head.textContent=caption;const body=document.createElement('div');body.className='message-body';body.textContent=text;const footer=document.createElement('div');footer.className='message-actions';
 for(const [label,fn]of actions){const b=document.createElement('button');b.textContent=label;b.addEventListener('click',()=>{$('modalLayer').replaceChildren(...previous);fn();});footer.append(b);}
 win.append(head,body,footer);wrap.append(win);$('modalLayer').append(shade,wrap);footer.querySelector('button')?.focus();
}
function input(ctx,id){return ctx.elements[id]?.value||'';}
function setInput(ctx,id,value){if(ctx.elements[id]&&'value'in ctx.elements[id])ctx.elements[id].value=value;}
function rowAction(ctx,node,index){
 if(/telefon/i.test(node.id)){phoneEdit=index;openModal('Frm_TelefonosMantemiento');return;}
 if(/venta|historial/i.test(node.id)){openModal('Frm_Venta');return;}
 if(/contacto$/i.test(node.id)){const row=ctx.elements[node.id].querySelectorAll('tbody tr')[index];if(ctx.elements.TxtTItulo)setInput(ctx,'TxtTItulo',row?.cells[0]?.textContent);if(ctx.elements.TxtDato)setInput(ctx,'TxtDato',row?.cells[1]?.textContent);return;}
 selected=Math.min(index,demo.contacts.length-1);values.delete('Frm_AtencionTelefonica');values.delete('Frm_Venta');closeModal();showForm('Frm_AtencionTelefonica');status('Contacto '+contact().id+' cargado');
}
function exportDemo(){const rows=[['ID','DOCUMENTO','NOMBRES','TELÉFONO'],...demo.contacts.map(c=>[c.id,c.document,c.name,c.phone])];const blob=new Blob(['\ufeff'+rows.map(r=>r.join('\t')).join('\r\n')],{type:'text/plain;charset=utf-8'});const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='contactos-crm.txt';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);status('Archivo de demostración exportado');}
function runDesktopAction(ctx,id){
 const f=ctx.form.id;snapshot(ctx);
 if(f==='Frm_Login'){
  if(id==='Cancel'){setInput(ctx,'UsernameTextBox','');setInput(ctx,'PasswordTextBox','');status('Inicio de sesión cancelado');return;}
  if(!input(ctx,'UsernameTextBox')||!input(ctx,'PasswordTextBox')){message('Inicio de sesión','Ingrese usuario y contraseña de demostración.');return;}
  loggedIn=true;showForm('Frm_AtencionTelefonica');return;
 }
 if(/cancel|salir/i.test(id)){if(ctx.isModal)closeModal();else closeMain();return;}
 if(f==='Frm_AtencionTelefonica'){
  if(id==='BtnAsignados'){openModal('Frm_LeadsAsignados');return;}
  if(id==='btnSegmenta'){const p=ctx.elements.PnlSegmenta;p.hidden=!p.hidden;p.style.height='370px';return;}
  if(id==='BtnTerminar'){values.delete(f);demo.saleDraft=null;selected=(selected+1)%demo.contacts.length;showForm(f);return;}
  if(id==='btnGrabar'){
   const g=['cb_Gestion01','cb_Gestion02','cb_Gestion03'].map(k=>input(ctx,k));
   if(g.some(x=>!x)){message('APLICATIVO DE GESTIÓN','Seleccione las tres gestiones.');return;}
   if(g[2]==='VENTA CONFIRMADA'&&!demo.saleDraft){openModal('Frm_Venta');return;}
   demo.history.unshift([demo.history.length+1,new Date().toLocaleString('es-PE'),currentUser.name,...g,input(ctx,'TxtObservacionGestion')]);
   if(demo.saleDraft)demo.sales.push(demo.saleDraft);demo.saleDraft=null;refresh();message('Registro de Gestión','Gestión registrada. Los cambios se guardan en este escritorio.');return;
  }
  if(id==='btnBuscar'){const idValue=input(ctx,'txtIdContacto');const index=demo.contacts.findIndex(c=>String(c.id)===idValue||c.document===idValue);if(index<0){message('Contacto','Contacto no encontrado en los datos ficticios. Usa 1001, 1002 o 1003.');return;}selected=index;values.delete(f);showForm(f);return;}
  if(id==='Btn_BuscarPrevio'){const term=input(ctx,'txtCriterio').toLocaleLowerCase('es');ctx.elements.lvw_Busqueda?.querySelectorAll('tbody tr').forEach(row=>row.hidden=!row.textContent.toLocaleLowerCase('es').includes(term));status('Búsqueda local de demostración');return;}
  if(/limpiar/i.test(id)){for(const key of ['txtCriterio'])setInput(ctx,key,'');status('Búsqueda limpiada');return;}
  if(id==='BtnExportarExcel'){exportDemo();return;}
  if(/buscar/i.test(id)){status('Resultados de demostración actualizados');return;}
 }
 if(f==='Frm_Venta'){
  if(id==='BtnPnlContacto'){const p=ctx.elements.PnContacto;p.hidden=!p.hidden;return;}
  if(id==='btnGrabar'){demo.saleDraft=values.get(f);closeModal();message('APLICATIVO DE GESTIÓN','Propuesta preparada en la demo. Guarda la gestión en Atención Telefónica.');return;}
 }
 if(f==='Frm_Rellamado'){
  if(id==='btnBuscar'){demo.recalls.push([contact().id,input(ctx,'DTPFecha')+' '+input(ctx,'DTPHora'),contact().name,input(ctx,'TxtNota')]);closeModal();status('Rellamada de demostración preparada');return;}
  if(id==='btnGrabar'){closeModal();return;}
 }
 if(f==='Frm_TelefonosMantemiento'&&id==='btnGrabar'){
  const number=input(ctx,'txtNumeroTelefono');if(!/^[+\d ()-]{6,30}$/.test(number)){message('Teléfono','POR FAVOR INTRODUZCA EL NÚMERO DE TELÉFONO');return;}
  if(phoneEdit>=0)demo.phones[phoneEdit]=number;else demo.phones.push(number);closeModal();refresh();status('Teléfono de demostración guardado');return;
 }
 if(f==='Form_Referido'&&id==='BtnReferidos'){
  const document=input(ctx,'TxtNroDoc'),name=input(ctx,'TxtNomApe'),phone=input(ctx,'Txttelefono');if(!document||!phone||!name){message('Referido','INGRESE DNI, NOMBRES Y TELÉFONO');return;}
  demo.contacts.push({id:1001+demo.contacts.length,document,name,email:input(ctx,'TxtCorreo'),phone,address:'',policy:'DEMO'});closeModal();refresh();status('Referido de demostración creado');return;
 }
 if(f==='Frm_EliminarGestiones'&&id==='BtnEliminar'){message('Eliminar Gestión','¿Realmente desea retirar una gestión ficticia?',[['Sí',()=>{demo.history.pop();persistWorkspace().catch(showDesktopError);status('Gestión retirada');}],['No',()=>{}]]);return;}
 if(/export|excel|txt/i.test(id)&&!['BtnBuscarExcel','btnImportarExcel','Btn_Buscar'].includes(id)){exportDemo();return;}
 if(['Frm_CargarBase','Frm_Act_Campos','Frm_CambioEstado'].includes(f)||id==='BtnBuscarExcel'){
  $('demoFile').dataset.form=f;$('demoFile').click();return;
 }
 if(/asignar|actualizar|cargar/i.test(id)){message('APLICATIVO DE GESTIÓN','Operación simulada correctamente. Esta demo no escribe en SQL Server ni envía información externa.');return;}
 if(/buscar/i.test(id)){status('Consulta ficticia actualizada');return;}
 status(title(ctx.form.controls[id]?.Text)||BUTTON_HINTS[id]||id);
}
$('demoFile').addEventListener('change',event=>{const file=event.target.files[0];if(!file)return;const ctx=modal||main;for(const id of ['TxtRuta','txtRuta','TextBox1'])setInput(ctx,id,file.name);status('Archivo seleccionado para demo: '+file.name+' (sin importar datos reales)');event.target.value='';});
$('zoom').addEventListener('change',()=>{scale(main);scale(modal);});
$('resetDemo').addEventListener('click',async()=>{try{if(loggedIn)await persistWorkspace();location.reload();}catch(error){showDesktopError(error);}});
window.addEventListener('resize',()=>{scale(main);scale(modal);});
document.addEventListener('keydown',event=>{if(event.key==='Escape'){if(modal)closeModal();else $('menu').querySelectorAll('details').forEach(d=>d.open=false);}});
document.addEventListener('click',event=>{if(!$('menu').contains(event.target))$('menu').querySelectorAll('details').forEach(d=>d.open=false);});
