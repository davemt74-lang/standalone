(()=>{
  const canvas=document.querySelector('[data-agent-chat-canvas]');
  if(!canvas)return;
  const desktop=canvas.querySelector('[data-research-desktop]');
  if(!desktop)return;

  const agentId=String(canvas.dataset.researchAgentId||'').trim();
  const teamId=String(canvas.dataset.researchAgentTeam||'').trim();
  const conversationId=String(canvas.dataset.researchAgentConversation||'').trim();
  const initialDocument=String(canvas.dataset.researchDocument||'').trim();
  const csrf=String(canvas.dataset.csrf||'');

  const openButton=canvas.querySelector('[data-research-desktop-open]');
  const closeButton=desktop.querySelector('[data-research-desktop-close]');
  const surface=desktop.querySelector('[data-research-desktop-surface]');
  const iconLayer=desktop.querySelector('[data-research-desktop-icons]');
  const stickyLayer=desktop.querySelector('[data-research-desktop-stickies]');
  const status=desktop.querySelector('[data-research-desktop-status]');
  const breadcrumbs=desktop.querySelector('[data-research-desktop-breadcrumbs]');

  const documentWindow=desktop.querySelector('[data-research-document-window]');
  const documentWindowHandle=desktop.querySelector('[data-research-document-window-handle]');
  const documentWindowLabel=desktop.querySelector('[data-research-document-window-label]');
  const documentTitle=desktop.querySelector('[data-research-document-title]');
  const documentEditor=desktop.querySelector('[data-research-document-editor]');
  const documentSaveState=desktop.querySelector('[data-research-document-save-state]');
  const documentHistoryPanel=desktop.querySelector('[data-research-document-history-panel]');
  const documentHistoryList=desktop.querySelector('[data-research-document-history-list]');

  let items=[],stickies=[],accessRole='viewer',currentFolder='',trashMode=false,desktopOpen=false;
  let activeDocument=null,documentRevision=0,documentDirty=false,documentSaving=false,documentSaveTimer=null;
  let maxIconZ=10,maxStickyZ=100;
  const stickyTimers=new Map(),stickyResizeTimers=new Map();

  async function api(url,{method='GET',data=null}={}){
    const options={method,headers:{Accept:'application/json'}};
    if(method!=='GET'){
      options.headers['Content-Type']='application/json';
      options.headers['X-CSRF-Token']=csrf;
      options.body=JSON.stringify(data||{});
    }
    const response=await fetch(url,options);
    const payload=await response.json().catch(()=>({ok:false,error:{message:'Invalid server response.'}}));
    if(!response.ok||payload.ok===false)throw new Error(payload.error?.message||payload.error?.code||'Research Desktop request failed.');
    return payload.data||{};
  }
  const workspaceUrl=(action,params={})=>{
    const u=new URL('/api/research-workspace-objects.php',location.origin);
    u.searchParams.set('action',action);if(agentId)u.searchParams.set('agent_id',agentId);
    Object.entries(params).forEach(([k,v])=>{if(v!==''&&v!==null&&v!==undefined)u.searchParams.set(k,String(v));});
    return u;
  };
  const canWrite=()=>['owner','admin','researcher'].includes(accessRole);
  function setStatus(message,error=false){
    if(!status)return;status.textContent=message||'Desktop ready';status.classList.toggle('is-error',!!error);
  }
  function safeClientId(){return globalThis.crypto?.randomUUID?.()||('desktop-'+Date.now()+'-'+Math.random().toString(16).slice(2));}

  function iconGlyph(type){
    return ({folder:'📁',document:'📄',bookmark:'🔖',annotation:'📝',upload:'📦',recording:'🎙️',trash:'🗑️'})[type]||'📎';
  }
  function defaultIconPosition(index){
    const height=Math.max(420,(surface?.clientHeight||720)-70),rows=Math.max(1,Math.floor(height/106));
    return {x:22+Math.floor(index/rows)*112,y:22+(index%rows)*106,z:10+index};
  }
  function folderRows(){
    const map=new Map();for(const item of items)if(item.object_type==='folder')map.set(String(item.public_id),item);return map;
  }
  function folderPath(id){
    const map=folderRows(),out=[];let current=String(id||''),guard=0;
    while(current&&map.has(current)&&guard++<40){const row=map.get(current);out.unshift(row);current=String(row.parent_public_id||'');}
    return out;
  }
  function renderBreadcrumbs(){
    if(!breadcrumbs)return;breadcrumbs.replaceChildren();
    const root=document.createElement('button');root.type='button';root.textContent=trashMode?'Trash':'Desktop';
    root.addEventListener('click',()=>{currentFolder='';renderDesktop();});breadcrumbs.appendChild(root);
    if(trashMode)return;
    for(const row of folderPath(currentFolder)){
      const sep=document.createElement('span');sep.textContent='›';breadcrumbs.appendChild(sep);
      const b=document.createElement('button');b.type='button';b.textContent=String(row.title||'Folder');
      b.addEventListener('click',()=>{currentFolder=String(row.public_id);renderDesktop();});breadcrumbs.appendChild(b);
    }
  }

  function visibleItems(){
    if(trashMode)return items;
    return items.filter(item=>{
      if(item.object_type==='annotation')return currentFolder==='';
      return String(item.parent_public_id||'')===String(currentFolder||'');
    });
  }
  function desktopObjectLabel(item){
    if(item.object_type==='annotation')return item.title||'Annotation';
    return item.title||({folder:'Folder',document:'Document',bookmark:'Bookmark',recording:'Recording',upload:'Upload'})[item.object_type]||'Research item';
  }

  async function saveIconPosition(item,x,y,z){
    if(!canWrite()||trashMode||item.system)return;
    item.desktop={x,y,z};
    try{await api(workspaceUrl('save_desktop_position'),{method:'POST',data:{agent_id:agentId,object_type:item.object_type,object_id:item.public_id,x,y,z}});}
    catch(err){setStatus(err.message||'Desktop position could not be saved.',true);}
  }
  function makeDesktopIcon(item,index){
    const pos=item.desktop||defaultIconPosition(index);
    maxIconZ=Math.max(maxIconZ,Number(pos.z||10));
    const icon=document.createElement('button');icon.type='button';icon.className='researchDesktopIcon';icon.dataset.objectType=item.object_type;icon.dataset.objectId=item.public_id||'';
    icon.style.left=Number(pos.x||0)+'px';icon.style.top=Number(pos.y||0)+'px';icon.style.zIndex=String(Number(pos.z||10));
    const graphic=document.createElement('span');graphic.className='researchDesktopIconGraphic';graphic.textContent=iconGlyph(item.object_type);
    const label=document.createElement('span');label.className='researchDesktopIconLabel';label.textContent=desktopObjectLabel(item);
    icon.append(graphic,label);
    if(item.object_type==='document'&&item.created_by_agent){const badge=document.createElement('small');badge.textContent='AGENT';icon.appendChild(badge);}
    if(trashMode){const restore=document.createElement('span');restore.className='researchDesktopIconHint';restore.textContent='Double-click to restore';icon.appendChild(restore);}
    icon.addEventListener('dblclick',()=>openDesktopItem(item));
    icon.addEventListener('keydown',e=>{if(e.key==='Enter')openDesktopItem(item);});
    icon.addEventListener('contextmenu',e=>{e.preventDefault();openItemMenu(item,icon,e.clientX,e.clientY);});
    if(canWrite()&&!trashMode&&!item.system){
      let dragging=false,moved=false,startX=0,startY=0,left=0,top=0;
      icon.addEventListener('pointerdown',e=>{
        if(e.button!==0)return;dragging=true;moved=false;icon.setPointerCapture(e.pointerId);startX=e.clientX;startY=e.clientY;left=parseInt(icon.style.left,10)||0;top=parseInt(icon.style.top,10)||0;
        maxIconZ++;icon.style.zIndex=String(maxIconZ);icon.classList.add('is-dragging');
      });
      icon.addEventListener('pointermove',e=>{
        if(!dragging)return;const dx=e.clientX-startX,dy=e.clientY-startY;if(Math.abs(dx)+Math.abs(dy)>4)moved=true;
        const maxX=Math.max(0,(surface?.clientWidth||1200)-104),maxY=Math.max(0,(surface?.clientHeight||700)-104);
        icon.style.left=Math.max(0,Math.min(maxX,left+dx))+'px';icon.style.top=Math.max(0,Math.min(maxY,top+dy))+'px';
      });
      icon.addEventListener('pointerup',e=>{
        if(!dragging)return;dragging=false;icon.classList.remove('is-dragging');try{icon.releasePointerCapture(e.pointerId);}catch{}
        if(moved)saveIconPosition(item,parseInt(icon.style.left,10)||0,parseInt(icon.style.top,10)||0,Number(icon.style.zIndex)||1);
      });
    }
    return icon;
  }
  function makeTrashIcon(index){
    const pos={x:Math.max(20,(surface?.clientWidth||1200)-126),y:22,z:9};
    return makeDesktopIcon({object_type:'trash',public_id:'trash',title:'Trash',desktop:pos,system:true},index);
  }
  function renderDesktop(){
    if(!iconLayer)return;iconLayer.replaceChildren();renderBreadcrumbs();
    const rows=visibleItems();rows.forEach((item,index)=>iconLayer.appendChild(makeDesktopIcon(item,index)));
    if(!trashMode&&currentFolder==='')iconLayer.appendChild(makeTrashIcon(rows.length));
    renderStickies();
  }

  async function loadDesktop(trashed=false){
    if(!agentId)return;setStatus(trashed?'Opening Trash…':'Loading Desktop…');
    try{
      const data=await api(workspaceUrl('desktop',{trashed:trashed?1:0}));
      accessRole=String(data.project?.access_role||'viewer');items=data.items||[];stickies=data.stickies||[];
      trashMode=trashed;if(trashed)currentFolder='';
      applyWriteState();renderDesktop();setStatus(trashed?'Trash':'Desktop ready');
    }catch(err){setStatus(err.message||'Unable to load Research Desktop.',true);}
  }
  async function openDesktop(){
    desktopOpen=true;desktop.hidden=false;canvas.classList.add('researchDesktopOpen');
    await loadDesktop(false);
  }
  async function closeDesktop(){
    if(activeDocument&&documentDirty){try{await saveDocument(true);}catch{}}
    desktopOpen=false;desktop.hidden=true;canvas.classList.remove('researchDesktopOpen');
  }

  function openDesktopItem(item){
    if(trashMode){
      if(canWrite()&&item.object_type!=='annotation')restoreItem(item);
      return;
    }
    if(item.object_type==='trash'){loadDesktop(true);return;}
    if(item.object_type==='folder'){currentFolder=String(item.public_id);renderDesktop();return;}
    if(item.object_type==='document'){openDocument(String(item.public_id));return;}
    if(item.object_type==='bookmark'&&item.canonical_url){window.open(String(item.canonical_url),'_blank','noopener');return;}
    if(item.object_type==='annotation'){location.href='/annotation.php?id='+encodeURIComponent(String(item.public_id));return;}
    const url=String(item.download_url||item.url||item.metadata?.url||'');
    if(url){window.open(url,'_blank','noopener');return;}
    setStatus('This '+String(item.object_type||'item')+' does not have an opener yet.');
  }

  function closeMenus(){document.querySelectorAll('.researchDesktopContextMenu').forEach(x=>x.remove());}
  function openItemMenu(item,anchor,x,y){
    closeMenus();const menu=document.createElement('div');menu.className='researchDesktopContextMenu';menu.style.left=x+'px';menu.style.top=y+'px';
    const add=(label,handler,disabled=false)=>{const b=document.createElement('button');b.type='button';b.textContent=label;b.disabled=disabled;b.addEventListener('click',()=>{menu.remove();handler();});menu.appendChild(b);};
    add('Open',()=>openDesktopItem(item));
    if(item.object_type==='document'||item.object_type==='bookmark')add('Ask Agent',()=>askAgentAbout(item.object_type,item.public_id,desktopObjectLabel(item)));
    if(teamId&&(item.object_type==='document'||item.object_type==='bookmark'))add('Share to Team',()=>shareObjectToTeam(item.object_type,item.public_id,item.object_type==='document'?'Research document':'Research bookmark'));
    if(canWrite()&&!trashMode&&item.object_type!=='annotation'){
      add('Rename',()=>renameItem(item),item.object_type==='document');
      add('Move to folder…',()=>moveItem(item));
      add('Move to Trash',()=>trashItem(item));
    }
    if(trashMode&&canWrite()&&item.object_type!=='annotation')add('Restore',()=>restoreItem(item));
    document.body.appendChild(menu);
    requestAnimationFrame(()=>{const r=menu.getBoundingClientRect();if(r.right>innerWidth)menu.style.left=Math.max(8,innerWidth-r.width-8)+'px';if(r.bottom>innerHeight)menu.style.top=Math.max(8,innerHeight-r.height-8)+'px';});
  }
  document.addEventListener('pointerdown',e=>{if(!e.target.closest('.researchDesktopContextMenu'))closeMenus();});

  function dialogBase(title){
    const d=document.createElement('dialog');d.className='researchWorkspaceDialog researchDesktopDialog';
    const form=document.createElement('form');form.method='dialog';const h=document.createElement('h3');h.textContent=title;form.appendChild(h);d.appendChild(form);document.body.appendChild(d);
    d.addEventListener('close',()=>d.remove());return {d,form};
  }
  function folderMap(){const map=new Map();for(const i of items)if(i.object_type==='folder')map.set(String(i.public_id),i);return map;}
  function folderSelect(selected=''){
    const select=document.createElement('select');const root=document.createElement('option');root.value='';root.textContent='Desktop';select.appendChild(root);
    const map=folderMap(),labelFor=row=>{const parts=[];let cur=row,guard=0;while(cur&&guard++<40){parts.unshift(cur.title);cur=map.get(String(cur.parent_public_id||''));}return parts.join(' / ');};
    [...map.values()].sort((a,b)=>labelFor(a).localeCompare(labelFor(b))).forEach(row=>{const o=document.createElement('option');o.value=row.public_id;o.textContent=labelFor(row);o.selected=String(row.public_id)===String(selected);select.appendChild(o);});
    return select;
  }
  function dialogActions(form,onSave,label='Save'){
    const row=document.createElement('div');row.className='researchWorkspaceDialogActions';
    const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';cancel.addEventListener('click',()=>form.closest('dialog')?.close());
    const save=document.createElement('button');save.type='submit';save.className='primary';save.textContent=label;row.append(cancel,save);form.appendChild(row);form.addEventListener('submit',onSave);
  }
  function labeled(form,text,control){const l=document.createElement('label');l.textContent=text;l.appendChild(control);form.appendChild(l);}
  function openFolderDialog(){
    if(!canWrite())return;const {d,form}=dialogBase('New folder'),name=document.createElement('input'),parent=folderSelect(currentFolder);name.required=true;name.maxLength=240;name.placeholder='Folder name';labeled(form,'Name',name);labeled(form,'Location',parent);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('create_folder'),{method:'POST',data:{agent_id:agentId,title:name.value,parent_id:parent.value}});d.close();await loadDesktop(false);}catch(err){setStatus(err.message,true);}},'Create folder');d.showModal();name.focus();
  }
  function openBookmarkDialog(){
    if(!canWrite())return;const {d,form}=dialogBase('Add website bookmark'),url=document.createElement('input'),title=document.createElement('input'),notes=document.createElement('textarea'),parent=folderSelect(currentFolder);
    url.type='url';url.required=true;url.placeholder='https://example.com';title.maxLength=240;notes.rows=4;notes.maxLength=5000;
    labeled(form,'Website URL',url);labeled(form,'Title',title);labeled(form,'Notes',notes);labeled(form,'Location',parent);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('create_bookmark'),{method:'POST',data:{agent_id:agentId,url:url.value,title:title.value,description:notes.value,parent_id:parent.value}});d.close();await loadDesktop(false);}catch(err){setStatus(err.message,true);}},'Save bookmark');d.showModal();url.focus();
  }
  function openDocumentDialog(){
    if(!canWrite())return;const {d,form}=dialogBase('New Research document'),title=document.createElement('input'),type=document.createElement('select'),parent=folderSelect(currentFolder);title.required=true;title.maxLength=240;
    [['document','Document'],['research_brief','Research brief'],['memo','Memo'],['report','Report'],['analysis','Analysis'],['source_summary','Source summary'],['timeline','Timeline'],['weekly_report','Weekly report']].forEach(([v,l])=>{const o=document.createElement('option');o.value=v;o.textContent=l;type.appendChild(o);});
    labeled(form,'Title',title);labeled(form,'Document type',type);labeled(form,'Location',parent);
    dialogActions(form,async e=>{e.preventDefault();try{const data=await api(workspaceUrl('create_document'),{method:'POST',data:{agent_id:agentId,title:title.value,document_type:type.value,parent_id:parent.value,content_html:'<p><br></p>'}});d.close();await loadDesktop(false);await openDocument(String(data.item?.public_id||''));}catch(err){setStatus(err.message,true);}},'Create document');d.showModal();title.focus();
  }
  function renameItem(item){
    if(!canWrite())return;const {d,form}=dialogBase('Rename'),name=document.createElement('input');name.required=true;name.maxLength=240;name.value=item.title||'';labeled(form,'Name',name);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('rename'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id,title:name.value}});d.close();await loadDesktop(false);}catch(err){setStatus(err.message,true);}});d.showModal();name.select();
  }
  function moveItem(item){
    if(!canWrite())return;const {d,form}=dialogBase('Move '+desktopObjectLabel(item)),parent=folderSelect(item.parent_public_id||'');labeled(form,'Move to',parent);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('move'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id,parent_id:parent.value}});d.close();await loadDesktop(false);}catch(err){setStatus(err.message,true);}},'Move');d.showModal();
  }
  async function trashItem(item){
    if(!canWrite()||!confirm('Move '+desktopObjectLabel(item)+' to Trash?'))return;
    try{await api(workspaceUrl('trash'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id}});await loadDesktop(false);}catch(err){setStatus(err.message,true);}
  }
  async function restoreItem(item){
    if(!canWrite())return;try{await api(workspaceUrl('restore'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id}});await loadDesktop(true);}catch(err){setStatus(err.message,true);}
  }

  function applyWriteState(){
    desktop.querySelectorAll('[data-research-desktop-new-sticky],[data-research-desktop-new-doc],[data-research-desktop-new-folder],[data-research-desktop-new-bookmark]').forEach(b=>b.hidden=!canWrite());
    if(documentTitle)documentTitle.readOnly=!canWrite();if(documentEditor)documentEditor.contentEditable=canWrite()?'true':'false';
    desktop.querySelectorAll('.researchDocumentToolbar button,.researchDocumentToolbar select').forEach(control=>{if(!control.matches('[data-doc-ask-selection]'))control.disabled=!canWrite();});
  }

  function stickySeed(){return {x:44+(stickies.length%5)*36,y:70+(stickies.length%6)*30};}
  async function createSticky(body=''){
    if(!canWrite())return;const p=stickySeed();
    try{await api(workspaceUrl('create_sticky'),{method:'POST',data:{agent_id:agentId,body,color:'yellow',x:p.x,y:p.y,width:245,height:205}});await loadDesktop(false);}catch(err){setStatus(err.message,true);}
  }
  function stickyPatch(item,patch,delay=450){
    if(!canWrite())return;const id=String(item.public_id),old=stickyTimers.get(id);if(old)clearTimeout(old);
    stickyTimers.set(id,setTimeout(async()=>{stickyTimers.delete(id);try{const data=await api(workspaceUrl('update_sticky'),{method:'POST',data:{agent_id:agentId,object_id:id,...patch}});const idx=stickies.findIndex(x=>String(x.public_id)===id);if(idx>=0&&data.item)stickies[idx]={...stickies[idx],...data.item};}catch(err){setStatus(err.message,true);}},delay));
  }
  function bringStickyFront(item,note){
    maxStickyZ=Math.max(maxStickyZ,...stickies.map(x=>Number(x.z_index||x.sticky_z||1)))+1;note.style.zIndex=String(maxStickyZ);stickyPatch(item,{z:maxStickyZ},0);
  }
  function renderSticky(item){
    const color=String(item.color||item.sticky_color||'yellow');
    const note=document.createElement('article');note.className='researchDesktopSticky researchDesktopSticky-'+color;note.dataset.stickyId=String(item.public_id);
    note.style.left=Number(item.position_x??item.sticky_x??40)+'px';note.style.top=Number(item.position_y??item.sticky_y??72)+'px';
    note.style.width=Number(item.width_px??item.sticky_width??245)+'px';note.style.height=Number(item.height_px??item.sticky_height??205)+'px';note.style.zIndex=String(Number(item.z_index??item.sticky_z??100));
    maxStickyZ=Math.max(maxStickyZ,Number(note.style.zIndex)||100);
    const head=document.createElement('header'),handle=document.createElement('button'),palette=document.createElement('div'),close=document.createElement('button');
    handle.type='button';handle.className='researchDesktopStickyHandle';handle.textContent='⋮⋮';handle.title='Drag note';
    palette.className='researchDesktopStickyPalette';
    ['yellow','pink','blue','green','purple','gray'].forEach(name=>{const b=document.createElement('button');b.type='button';b.className='researchDesktopStickySwatch is-'+name;b.title=name;b.addEventListener('click',()=>{if(!canWrite())return;note.className='researchDesktopSticky researchDesktopSticky-'+name;stickyPatch(item,{color:name},0);});palette.appendChild(b);});
    close.type='button';close.className='researchDesktopStickyDelete';close.textContent='×';close.hidden=!canWrite();close.addEventListener('click',async()=>{if(confirm('Move this sticky note to Trash?')){await api(workspaceUrl('trash'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id}});await loadDesktop(false);}});
    head.append(handle,palette,close);
    const body=document.createElement('textarea');body.value=String(item.body??item.sticky_body??'');body.maxLength=10000;body.placeholder='Write a note…';body.readOnly=!canWrite();body.addEventListener('input',()=>stickyPatch(item,{body:body.value}));
    note.append(head,body);note.addEventListener('pointerdown',()=>{if(canWrite())bringStickyFront(item,note);});
    if(canWrite()){
      let dragging=false,startX=0,startY=0,left=0,top=0;
      handle.addEventListener('pointerdown',e=>{e.preventDefault();e.stopPropagation();dragging=true;handle.setPointerCapture(e.pointerId);startX=e.clientX;startY=e.clientY;left=parseInt(note.style.left,10)||0;top=parseInt(note.style.top,10)||0;bringStickyFront(item,note);});
      handle.addEventListener('pointermove',e=>{if(!dragging)return;const maxX=Math.max(0,(surface?.clientWidth||1200)-note.offsetWidth),maxY=Math.max(0,(surface?.clientHeight||700)-note.offsetHeight);note.style.left=Math.max(0,Math.min(maxX,left+e.clientX-startX))+'px';note.style.top=Math.max(0,Math.min(maxY,top+e.clientY-startY))+'px';});
      handle.addEventListener('pointerup',e=>{if(!dragging)return;dragging=false;try{handle.releasePointerCapture(e.pointerId);}catch{}stickyPatch(item,{x:parseInt(note.style.left,10)||0,y:parseInt(note.style.top,10)||0,z:Number(note.style.zIndex)||1},0);});
      if('ResizeObserver' in window){
        const ro=new ResizeObserver(()=>{if(note.dataset.ready!=='1'||!note.isConnected)return;const key=String(item.public_id),old=stickyResizeTimers.get(key);if(old)clearTimeout(old);stickyResizeTimers.set(key,setTimeout(()=>{stickyResizeTimers.delete(key);stickyPatch(item,{width:Math.round(note.offsetWidth),height:Math.round(note.offsetHeight)},0);},400));});
        ro.observe(note);requestAnimationFrame(()=>note.dataset.ready='1');
      }
    }
    return note;
  }
  function renderStickies(){
    if(!stickyLayer)return;stickyLayer.replaceChildren();
    if(trashMode||currentFolder!==''){stickyLayer.hidden=true;return;}stickyLayer.hidden=false;
    stickies.forEach(item=>stickyLayer.appendChild(renderSticky(item)));
  }

  function documentUrl(publicId){const u=new URL(location.href);if(publicId)u.searchParams.set('doc',publicId);else u.searchParams.delete('doc');return u.pathname+(u.searchParams.toString()?'?'+u.searchParams.toString():'')+u.hash;}
  function setDocumentSaveState(text,error=false){if(!documentSaveState)return;documentSaveState.textContent=text;documentSaveState.classList.toggle('is-error',!!error);}
  async function openDocument(publicId){
    publicId=String(publicId||'').trim();if(!publicId)return;if(!desktopOpen)await openDesktop();
    if(activeDocument&&activeDocument.public_id!==publicId&&documentDirty){try{await saveDocument(true);}catch{}}
    try{
      const data=await api(workspaceUrl('document',{object_id:publicId})),item=data.item;if(!item)throw new Error('Document not found.');
      activeDocument=item;documentRevision=Number(item.revision_number||1);documentDirty=false;
      documentTitle.value=String(item.title||'Untitled document');documentEditor.innerHTML=String(item.content_html||'<p><br></p>');
      if(documentWindowLabel)documentWindowLabel.textContent=String(item.title||'Research document');
      applyWriteState();setDocumentSaveState((canWrite()?'Saved':'Read only')+' · v'+documentRevision);
      documentWindow.hidden=false;documentWindow.classList.remove('is-minimized');history.replaceState({},'',documentUrl(publicId));
      maxIconZ+=20;documentWindow.style.zIndex=String(1000+maxIconZ);documentEditor.focus();
    }catch(err){setStatus(err.message||'Unable to open document.',true);}
  }
  async function saveDocument(force=false){
    if(!activeDocument||!canWrite()||documentSaving||(!documentDirty&&!force))return activeDocument;
    if(documentSaveTimer){clearTimeout(documentSaveTimer);documentSaveTimer=null;}documentSaving=true;setDocumentSaveState('Saving…');
    try{
      const data=await api(workspaceUrl('save_document'),{method:'POST',data:{agent_id:agentId,object_id:activeDocument.public_id,title:documentTitle.value||activeDocument.title,content_html:documentEditor.innerHTML||'',base_revision:documentRevision}});
      activeDocument=data.item||activeDocument;documentRevision=Number(activeDocument.revision_number||documentRevision);documentDirty=false;if(documentWindowLabel)documentWindowLabel.textContent=activeDocument.title||'Research document';
      setDocumentSaveState('Saved · v'+documentRevision);document.dispatchEvent(new CustomEvent('annotated:research-document-saved',{detail:{document:activeDocument}}));return activeDocument;
    }catch(err){setDocumentSaveState(err.message||'Save failed',true);throw err;}finally{documentSaving=false;}
  }
  function scheduleDocumentSave(){if(!activeDocument||!canWrite())return;documentDirty=true;setDocumentSaveState('Unsaved');if(documentSaveTimer)clearTimeout(documentSaveTimer);documentSaveTimer=setTimeout(()=>saveDocument().catch(()=>{}),1000);}
  async function closeDocument(save=true){
    if(save&&documentDirty){try{await saveDocument(true);}catch{}}activeDocument=null;documentRevision=0;documentDirty=false;if(documentSaveTimer){clearTimeout(documentSaveTimer);documentSaveTimer=null;}documentWindow.hidden=true;history.replaceState({},'',documentUrl(''));
  }
  function selectedDocumentText(){const s=getSelection();if(!s||s.isCollapsed||!documentEditor)return'';const r=s.getRangeAt(0);if(!documentEditor.contains(r.commonAncestorContainer))return'';return s.toString().trim().slice(0,12000);}
  function execDocumentCommand(command,value=null){if(!activeDocument||!canWrite())return;documentEditor.focus();try{document.execCommand(command,false,value);}catch{}scheduleDocumentSave();}
  function insertDocumentTable(){execDocumentCommand('insertHTML','<table><tbody><tr><th>Column 1</th><th>Column 2</th></tr><tr><td>Value</td><td>Value</td></tr></tbody></table><p><br></p>');}
  async function openDocumentHistory(){
    if(!activeDocument)return;documentHistoryPanel.hidden=false;documentHistoryList.innerHTML='<div class="meta">Loading versions…</div>';
    try{const data=await api(workspaceUrl('document_revisions',{object_id:activeDocument.public_id}));documentHistoryList.replaceChildren();
      for(const revision of data.revisions||[]){const row=document.createElement('article');row.className='researchDocumentRevision';const copy=document.createElement('div'),strong=document.createElement('strong'),small=document.createElement('small');strong.textContent='Version '+revision.revision_number;small.textContent=(revision.editor_name||revision.editor_username||'Agent')+' · '+new Date(revision.created_at).toLocaleString();copy.append(strong,small);row.append(copy);
        if(Number(revision.revision_number)===documentRevision){const tag=document.createElement('span');tag.textContent='Current';row.append(tag);}
        else if(canWrite()){const restore=document.createElement('button');restore.type='button';restore.textContent='Restore';restore.addEventListener('click',async()=>{if(!confirm('Restore this version as the new current version?'))return;try{const out=await api(workspaceUrl('restore_document_revision'),{method:'POST',data:{agent_id:agentId,object_id:activeDocument.public_id,revision_id:revision.public_id,base_revision:documentRevision}});activeDocument=out.item;documentRevision=Number(activeDocument.revision_number||documentRevision);documentTitle.value=activeDocument.title||'';documentEditor.innerHTML=activeDocument.content_html||'<p><br></p>';documentDirty=false;setDocumentSaveState('Saved · v'+documentRevision);await openDocumentHistory();}catch(err){setDocumentSaveState(err.message,true);}});row.append(restore);}
        documentHistoryList.append(row);}
    }catch(err){documentHistoryList.textContent=err.message||'Unable to load history.';}
  }
  function askAgentAbout(type,id,label,selection=''){
    const prompt=selection?('Review this selected text from '+label+':\n\n'+selection):('Review this '+label+' and tell me what matters most.');
    closeDesktop();document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:{conversation:conversationId,research_agent:true,prompt,context:[{type,public_id:id,label}]},bubbles:true}));
  }
  async function shareObjectToTeam(type,publicId,label){
    if(!teamId)return;try{const list=await api('/api/conversations.php?action=list'),conversation=(list.conversations||[]).find(x=>String(x.team_public_id||'')===teamId);if(!conversation)throw new Error('Team Chat is unavailable.');await api('/api/conversations.php?action=send',{method:'POST',data:{conversation:conversation.public_id,body:'Shared '+label+'.',client_message_id:safeClientId(),attachments:[{type,public_id:publicId}]}});setStatus('Shared to '+(conversation.team_name||'Team')+'.');}catch(err){setStatus(err.message,true);}
  }

  if(documentWindowHandle){
    let moving=false,startX=0,startY=0,left=0,top=0;
    documentWindowHandle.addEventListener('pointerdown',e=>{if(e.target.closest('button'))return;moving=true;documentWindowHandle.setPointerCapture(e.pointerId);startX=e.clientX;startY=e.clientY;const r=documentWindow.getBoundingClientRect(),s=surface.getBoundingClientRect();left=r.left-s.left;top=r.top-s.top;});
    documentWindowHandle.addEventListener('pointermove',e=>{if(!moving)return;const maxX=Math.max(0,surface.clientWidth-documentWindow.offsetWidth),maxY=Math.max(0,surface.clientHeight-documentWindow.offsetHeight);documentWindow.style.left=Math.max(0,Math.min(maxX,left+e.clientX-startX))+'px';documentWindow.style.top=Math.max(0,Math.min(maxY,top+e.clientY-startY))+'px';documentWindow.style.transform='none';});
    documentWindowHandle.addEventListener('pointerup',e=>{moving=false;try{documentWindowHandle.releasePointerCapture(e.pointerId);}catch{}});
  }

  openButton?.addEventListener('click',openDesktop);closeButton?.addEventListener('click',closeDesktop);
  desktop.querySelector('[data-research-desktop-new-sticky]')?.addEventListener('click',()=>createSticky(''));
  desktop.querySelector('[data-research-desktop-new-doc]')?.addEventListener('click',openDocumentDialog);
  desktop.querySelector('[data-research-desktop-new-folder]')?.addEventListener('click',openFolderDialog);
  desktop.querySelector('[data-research-desktop-new-bookmark]')?.addEventListener('click',openBookmarkDialog);
  desktop.querySelector('[data-research-document-close]')?.addEventListener('click',()=>closeDocument(true));
  desktop.querySelector('[data-research-document-minimize]')?.addEventListener('click',()=>{if(documentWindow)documentWindow.hidden=true;});
  desktop.querySelector('[data-research-document-history]')?.addEventListener('click',openDocumentHistory);
  desktop.querySelector('[data-research-document-history-close]')?.addEventListener('click',()=>documentHistoryPanel.hidden=true);
  desktop.querySelector('[data-research-document-move]')?.addEventListener('click',()=>{if(activeDocument)moveItem(activeDocument);});
  documentTitle?.addEventListener('input',scheduleDocumentSave);documentEditor?.addEventListener('input',scheduleDocumentSave);
  desktop.querySelectorAll('[data-doc-command]').forEach(b=>{b.addEventListener('mousedown',e=>e.preventDefault());b.addEventListener('click',()=>execDocumentCommand(String(b.dataset.docCommand||'')));});
  desktop.querySelector('[data-doc-block]')?.addEventListener('change',e=>execDocumentCommand('formatBlock','<'+String(e.target.value||'p')+'>'));
  desktop.querySelector('[data-doc-link]')?.addEventListener('click',()=>{const href=prompt('Link URL');if(href)execDocumentCommand('createLink',href);});
  desktop.querySelector('[data-doc-table]')?.addEventListener('click',insertDocumentTable);
  desktop.querySelector('[data-doc-ask-selection]')?.addEventListener('click',()=>{if(activeDocument)askAgentAbout('document',activeDocument.public_id,documentTitle.value||activeDocument.title||'document',selectedDocumentText());});
  desktop.querySelector('[data-doc-sticky-selection]')?.addEventListener('click',()=>{const text=selectedDocumentText();if(text)createSticky(text);});

  document.addEventListener('visibilitychange',()=>{if(document.hidden&&documentDirty)saveDocument(true).catch(()=>{});});
  document.addEventListener('annotated:research-document-open',e=>openDocument(String(e.detail?.public_id||e.detail?.document_id||'')));
  document.addEventListener('annotated:research-document-created',()=>{if(desktopOpen)loadDesktop(false);});
  document.addEventListener('annotated:research-action-executed',e=>{const type=e.detail?.result?.type;if(desktopOpen&&(type==='document'||type==='sticky'))loadDesktop(false);});
  document.addEventListener('annotated:agent-chat-feed-restored',()=>{if(desktopOpen)closeDesktop();});

  window.addEventListener('resize',()=>{if(desktopOpen)renderDesktop();});
  if(initialDocument)openDocument(initialDocument);

  window.AnnotatedResearchWorkspace={openDesktop,closeDesktop,openDocument,createSticky,reload:()=>desktopOpen?loadDesktop(trashMode):Promise.resolve(),shareBookmarkToTeam:id=>shareObjectToTeam('bookmark',id,'Research bookmark'),shareDocumentToTeam:id=>shareObjectToTeam('document',id,'Research document')};
})();