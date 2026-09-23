(()=>{
  const canvas=document.querySelector('[data-agent-chat-canvas]');
  if(!canvas)return;
  const desktop=canvas.querySelector('[data-research-desktop]');
  if(!desktop)return;

  const agentId=String(canvas.dataset.researchAgentId||'').trim();
  const projectId=String(canvas.dataset.researchAgentProject||'').trim();
  const teamId=String(canvas.dataset.researchAgentTeam||'').trim();
  const conversationId=String(canvas.dataset.researchAgentConversation||'').trim();
  const initialDocument=String(canvas.dataset.researchDocument||'').trim();
  const csrf=String(canvas.dataset.csrf||'');

  const openButton=canvas.querySelector('[data-research-desktop-open]');
  const libraryOpenButton=canvas.querySelector('[data-research-library-open]');
  const libraryDrawer=canvas.querySelector('[data-research-library-drawer]');
  const libraryCloseButton=canvas.querySelector('[data-research-library-close]');
  const librarySearch=canvas.querySelector('[data-research-library-search]');
  const libraryList=canvas.querySelector('[data-research-library-list]');
  const libraryCount=canvas.querySelector('[data-research-library-count]');
  const libraryIndexState=canvas.querySelector('[data-research-library-index-state]');
  const libraryFolder=canvas.querySelector('[data-research-library-folder]');
  const libraryStatus=canvas.querySelector('[data-research-library-status]');
  const libraryDateFrom=canvas.querySelector('[data-research-library-date-from]');
  const libraryDateTo=canvas.querySelector('[data-research-library-date-to]');
  const libraryAskButton=canvas.querySelector('[data-research-library-ask]');
  const libraryDesktopButton=canvas.querySelector('[data-research-library-desktop]');
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
  const fileInput=desktop.querySelector('[data-research-desktop-file-input]');
  const recordingWindow=desktop.querySelector('[data-research-recording-window]');
  const recordingTitle=desktop.querySelector('[data-research-recording-title]');
  const recordingClock=desktop.querySelector('[data-research-recording-clock]');
  const recordingStatus=desktop.querySelector('[data-research-recording-status]');
  const recordingLevel=desktop.querySelector('[data-research-recording-level]');
  const recordingPreview=desktop.querySelector('[data-research-recording-preview]');
  const transcriptWindow=desktop.querySelector('[data-research-transcript-window]');
  const transcriptTitle=desktop.querySelector('[data-research-transcript-title]');
  const transcriptAudio=desktop.querySelector('[data-research-transcript-audio]');
  const transcriptMeta=desktop.querySelector('[data-research-transcript-meta]');
  const transcriptStatus=desktop.querySelector('[data-research-transcript-status]');
  const transcriptText=desktop.querySelector('[data-research-transcript-text]');


  let items=[],stickies=[],accessRole='viewer',currentFolder='',trashMode=false,desktopOpen=false,libraryOpen=false,libraryFilter='all',libraryResults=[],librarySearchTimer=null,libraryRequestSerial=0;
  const librarySelected=new Map();
  let activeDocument=null,documentRevision=0,documentDirty=false,documentSaving=false,documentSaveTimer=null;
  let maxIconZ=10,maxStickyZ=100;
  const stickyTimers=new Map(),stickyResizeTimers=new Map();
  let processingPollTimer=null,recordingPollTimer=null;
  let mediaRecorder=null,mediaStream=null,recordingChunks=[],recordingBlob=null,recordingObjectUrl='',recordingElapsedMs=0,recordingTicker=null,recordingMeterFrame=null,recordingAudioContext=null;
  let activeRecording=null;


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
    return items.filter(item=>String(item.parent_public_id||'')===String(currentFolder||''));
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
  function clearFolderDropTarget(){desktop.querySelectorAll('.researchDesktopIcon.is-folder-drop-target').forEach(el=>el.classList.remove('is-folder-drop-target'));}
  function folderDropTarget(clientX,clientY,sourceId=''){
    const hit=(document.elementsFromPoint?.(clientX,clientY)||[]).find(el=>el.matches?.('.researchDesktopIcon[data-object-type="folder"]')&&String(el.dataset.objectId||'')!==String(sourceId||''));
    return hit||null;
  }
  function updateFolderDropTarget(clientX,clientY,sourceId=''){
    clearFolderDropTarget();const target=folderDropTarget(clientX,clientY,sourceId);if(target)target.classList.add('is-folder-drop-target');return target;
  }
  async function moveDesktopObject(item,parentId){
    if(!canWrite()||item.system)return false;
    const label=desktopObjectLabel(item),target=String(parentId||'');
    try{
      await api(workspaceUrl('move'),{method:'POST',data:{agent_id:agentId,object_type:item.object_type,object_id:item.public_id,parent_id:target}});
      setStatus(target?label+' moved into folder.':label+' moved to Desktop.');
      await loadDesktop(false,true);return true;
    }catch(err){setStatus(err.message||('Unable to move '+label+'.'),true);await loadDesktop(false,true);return false;}
  }
  function makeDesktopIcon(item,index){
    const pos=item.desktop||defaultIconPosition(index);
    maxIconZ=Math.max(maxIconZ,Number(pos.z||10));
    const icon=document.createElement('button');icon.type='button';icon.className='researchDesktopIcon';icon.dataset.objectType=item.object_type;icon.dataset.objectId=item.public_id||'';
    icon.style.left=Number(pos.x||0)+'px';icon.style.top=Number(pos.y||0)+'px';icon.style.zIndex=String(Number(pos.z||10));
    const graphic=document.createElement('span');graphic.className='researchDesktopIconGraphic';graphic.textContent=iconGlyph(item.object_type);
    const label=document.createElement('span');label.className='researchDesktopIconLabel';label.textContent=desktopObjectLabel(item);
    icon.append(graphic,label);
    if(item.object_type==='upload'&&['queued','extracting','blocked','failed'].includes(String(item.upload_processing_status||''))){const s=document.createElement('span');s.className='researchDesktopIconStatus is-'+String(item.upload_processing_status||'queued');s.textContent=String(item.upload_processing_status||'queued');icon.appendChild(s);}
    if(item.object_type==='recording'&&String(item.transcript_status||'queued')!=='ready'){const s=document.createElement('span');s.className='researchDesktopIconStatus is-'+String(item.transcript_status||'queued');s.textContent='transcript '+String(item.transcript_status||'queued');icon.appendChild(s);}
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
        if(moved)updateFolderDropTarget(e.clientX,e.clientY,String(item.public_id||''));
      });
      icon.addEventListener('pointerup',async e=>{
        if(!dragging)return;dragging=false;icon.classList.remove('is-dragging');try{icon.releasePointerCapture(e.pointerId);}catch{}
        const folder=folderDropTarget(e.clientX,e.clientY,String(item.public_id||''));clearFolderDropTarget();
        if(moved&&folder){await moveDesktopObject(item,String(folder.dataset.objectId||''));return;}
        if(moved)await saveIconPosition(item,parseInt(icon.style.left,10)||0,parseInt(icon.style.top,10)||0,Number(icon.style.zIndex)||1);
      });
      icon.addEventListener('pointercancel',()=>{dragging=false;icon.classList.remove('is-dragging');clearFolderDropTarget();});
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

  function libraryResultLabel(item){
    return item.title||({source:'Source',annotation:'Annotation',document:'Document',bookmark:'Bookmark',sticky:'Sticky note',upload:'Research file',recording:'Recording'})[item.object_type]||'Research item';
  }
  function populateLibraryFolders(){
    if(!libraryFolder)return;const selected=libraryFolder.value;libraryFolder.replaceChildren();
    const all=document.createElement('option');all.value='';all.textContent='All folders';libraryFolder.appendChild(all);
    const map=folderMap(),labelFor=row=>{const parts=[];let cur=row,guard=0;while(cur&&guard++<40){parts.unshift(String(cur.title||'Folder'));cur=map.get(String(cur.parent_public_id||''));}return parts.join(' / ');};
    [...map.values()].sort((a,b)=>labelFor(a).localeCompare(labelFor(b))).forEach(row=>{const o=document.createElement('option');o.value=String(row.public_id);o.textContent=labelFor(row);libraryFolder.appendChild(o);});
    if([...libraryFolder.options].some(o=>o.value===selected))libraryFolder.value=selected;
  }
  function librarySearchUrl(action='search',extra={}){
    const u=new URL('/api/research-retrieval.php',location.origin);u.searchParams.set('action',action);u.searchParams.set('project_id',projectId);
    Object.entries(extra).forEach(([k,v])=>{if(v!==''&&v!==null&&v!==undefined)u.searchParams.set(k,String(v));});return u;
  }
  function updateLibraryAskState(){
    if(!libraryAskButton)return;const n=librarySelected.size;libraryAskButton.disabled=n===0;libraryAskButton.textContent=n?'Ask Agent ('+n+')':'Ask Agent';
  }
  function toggleLibrarySelection(item,checked){
    const key=String(item.object_type)+':'+String(item.public_id);
    if(checked){
      if(librarySelected.size>=6&&!librarySelected.has(key)){setStatus('Select up to 6 Research items at once.',true);return false;}
      librarySelected.set(key,item);
    }else librarySelected.delete(key);
    updateLibraryAskState();return true;
  }
  async function openLibraryItem(item){
    closeLibrary();
    if(item.object_type==='document'){await openDocument(String(item.public_id));return;}
    if(item.object_type==='recording'){await openRecording(String(item.public_id));return;}
    if(item.object_type==='sticky'){
      if(!desktopOpen)await openDesktop();currentFolder=String(item.folder_public_id||'');renderDesktop();
      requestAnimationFrame(()=>{const el=stickyLayer?.querySelector('[data-sticky-id="'+CSS.escape(String(item.public_id))+'"]');if(el){el.scrollIntoView({block:'center',inline:'center'});el.classList.add('is-library-focus');setTimeout(()=>el.classList.remove('is-library-focus'),1200);}});
      return;
    }
    if(item.object_type==='upload'){window.open('/research-workspace-file.php?id='+encodeURIComponent(String(item.public_id)),'_blank','noopener');return;}
    if(item.object_type==='annotation'){location.href='/annotation.php?id='+encodeURIComponent(String(item.public_id));return;}
    if(item.object_type==='source'){location.href='/source.php?id='+encodeURIComponent(String(item.public_id));return;}
    const href=String(item.href||item.metadata?.url||'');if(href)window.open(href,'_blank','noopener');
  }
  async function loadRelated(item){
    if(!item?.public_id)return;const serial=++libraryRequestSerial;
    try{
      if(libraryList)libraryList.innerHTML='<div class="researchLibraryEmpty">Finding related evidence…</div>';
      const data=await api(librarySearchUrl('related',{object_type:item.object_type,object_id:item.public_id,limit:12}));
      if(serial!==libraryRequestSerial)return;libraryResults=data.results||[];renderLibrary('Related to '+libraryResultLabel(item));
    }catch(err){if(serial!==libraryRequestSerial)return;libraryResults=[];renderLibrary();setStatus(err.message||'Related evidence could not be loaded.',true);}
  }
  function renderLibrary(overrideLabel=''){
    if(!libraryList)return;libraryList.replaceChildren();
    const rows=libraryResults;if(libraryCount)libraryCount.textContent=overrideLabel||(rows.length+' result'+(rows.length===1?'':'s'));
    if(!rows.length){const empty=document.createElement('div');empty.className='researchLibraryEmpty';empty.textContent='No matching Research evidence.';libraryList.appendChild(empty);return;}
    for(const item of rows){
      const row=document.createElement('article');row.className='researchLibraryItem';row.dataset.objectType=String(item.object_type||'');
      const select=document.createElement('input');select.type='checkbox';select.className='researchLibrarySelect';select.setAttribute('aria-label','Select '+libraryResultLabel(item));
      const key=String(item.object_type)+':'+String(item.public_id);select.checked=librarySelected.has(key);
      select.addEventListener('change',()=>{if(!toggleLibrarySelection(item,select.checked))select.checked=false;});
      const open=document.createElement('button');open.type='button';open.className='researchLibraryItemOpen';
      const icon=document.createElement('span');icon.className='researchLibraryItemIcon';icon.textContent=iconGlyph(item.object_type);
      const copy=document.createElement('span');copy.className='researchLibraryItemCopy';
      const title=document.createElement('strong');title.textContent=libraryResultLabel(item);
      const meta=document.createElement('small');meta.textContent=[String(item.object_type||'').toUpperCase(),item.locator_label,item.source_status&&item.source_status!=='ready'?String(item.source_status).toUpperCase():''].filter(Boolean).join(' · ');
      copy.append(title,meta);
      if(item.snippet){const p=document.createElement('span');p.className='researchLibraryItemPreview';p.textContent=String(item.snippet);copy.appendChild(p);}
      open.append(icon,copy);open.addEventListener('click',()=>openLibraryItem(item));
      const related=document.createElement('button');related.type='button';related.className='researchLibraryRelated';related.textContent='Related';related.addEventListener('click',()=>loadRelated(item));
      row.append(select,open,related);libraryList.appendChild(row);
    }
  }
  async function loadLibraryResults(){
    if(!projectId)return;const serial=++libraryRequestSerial;
    if(libraryList)libraryList.innerHTML='<div class="researchLibraryEmpty">Searching Research…</div>';
    try{
      const data=await api(librarySearchUrl('search',{
        q:String(librarySearch?.value||'').trim(),type:libraryFilter,folder_id:String(libraryFolder?.value||''),
        status:String(libraryStatus?.value||''),date_from:String(libraryDateFrom?.value||''),date_to:String(libraryDateTo?.value||''),limit:40
      }));
      if(serial!==libraryRequestSerial)return;libraryResults=data.results||[];
      if(libraryIndexState){const idx=data.index||{};libraryIndexState.textContent=(data.mode?String(data.mode).toUpperCase():'SEARCH')+' · '+String(idx.document_count||0)+' objects · '+String(idx.chunk_count||0)+' chunks'+(idx.semantic_available?' · semantic ready':'');}
      renderLibrary();
    }catch(err){if(serial!==libraryRequestSerial)return;libraryResults=[];if(libraryIndexState)libraryIndexState.textContent='';renderLibrary();setStatus(err.message||'Research search failed.',true);}
  }
  async function loadLibrary(){
    if(!agentId||!projectId)return;
    try{
      const data=await api(workspaceUrl('desktop',{trashed:0}));accessRole=String(data.project?.access_role||'viewer');items=data.items||[];stickies=data.stickies||[];populateLibraryFolders();
      await loadLibraryResults();
    }catch(err){libraryResults=[];renderLibrary();setStatus(err.message||'Unable to load Research Library.',true);}
  }
  function scheduleLibrarySearch(){
    if(librarySearchTimer)clearTimeout(librarySearchTimer);librarySearchTimer=setTimeout(()=>{librarySearchTimer=null;loadLibraryResults();},240);
  }
  function askAgentAboutLibrarySelection(){
    const selected=[...librarySelected.values()].slice(0,6);if(!selected.length)return;
    const context=selected.map(item=>({type:item.object_type,public_id:String(item.public_id),label:libraryResultLabel(item)}));
    const labels=selected.map(item=>libraryResultLabel(item)+(item.locator_label?' · '+item.locator_label:''));
    librarySelected.clear();updateLibraryAskState();closeLibrary();
    document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:{conversation:conversationId,research_agent:true,prompt:'Review these selected Research items together. Identify the strongest evidence, conflicts, gaps, and useful next steps.\n\nSelected: '+labels.join('; '),context},bubbles:true}));
  }
  async function openLibrary(){
    libraryOpen=true;libraryDrawer.hidden=false;document.body.classList.add('researchLibraryMode');await loadLibrary();requestAnimationFrame(()=>librarySearch?.focus());
  }
  function closeLibrary(){
    libraryOpen=false;libraryDrawer.hidden=true;document.body.classList.remove('researchLibraryMode');
  }

  function scheduleProcessingPoll(){
    if(processingPollTimer){clearTimeout(processingPollTimer);processingPollTimer=null;}
    if(!desktopOpen||trashMode)return;
    const pending=items.some(item=>(item.object_type==='upload'&&['queued','extracting'].includes(String(item.upload_processing_status||'')))||(item.object_type==='recording'&&['queued','processing'].includes(String(item.transcript_status||''))));
    if(pending)processingPollTimer=setTimeout(()=>loadDesktop(false,true),4000);
  }
  async function loadDesktop(trashed=false,quiet=false){
    if(!agentId)return;if(!quiet)setStatus(trashed?'Opening Trash…':'Loading Desktop…');
    try{
      const data=await api(workspaceUrl('desktop',{trashed:trashed?1:0}));
      accessRole=String(data.project?.access_role||'viewer');items=data.items||[];stickies=data.stickies||[];
      trashMode=trashed;if(trashed)currentFolder='';
      applyWriteState();renderDesktop();if(libraryOpen)loadLibraryResults();if(!quiet)setStatus(trashed?'Trash':'Desktop ready');scheduleProcessingPoll();
    }catch(err){setStatus(err.message||'Unable to load Research Desktop.',true);}
  }
  async function openDesktop(){
    desktopOpen=true;desktop.hidden=false;canvas.classList.add('researchDesktopOpen');document.body.classList.add('researchDesktopMode');
    await loadDesktop(false);
  }
  async function closeDesktop(){
    if(activeDocument&&documentDirty){try{await saveDocument(true);}catch{}}
    desktopOpen=false;desktop.hidden=true;canvas.classList.remove('researchDesktopOpen');document.body.classList.remove('researchDesktopMode');if(processingPollTimer){clearTimeout(processingPollTimer);processingPollTimer=null;}if(recordingPollTimer){clearTimeout(recordingPollTimer);recordingPollTimer=null;}
  }

  function openDesktopItem(item){
    if(trashMode){
      if(canWrite()&&item.object_type!=='annotation')restoreItem(item);
      return;
    }
    if(item.object_type==='trash'){loadDesktop(true);return;}
    if(item.object_type==='folder'){currentFolder=String(item.public_id);renderDesktop();return;}
    if(item.object_type==='document'){openDocument(String(item.public_id));return;}
    if(item.object_type==='recording'){openRecording(String(item.public_id));return;}
    if(item.object_type==='upload'){window.open('/research-workspace-file.php?id='+encodeURIComponent(String(item.public_id)),'_blank','noopener');return;}
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
    if(['document','bookmark','upload','recording'].includes(item.object_type))add('Ask Agent',()=>askAgentAbout(item.object_type,item.public_id,desktopObjectLabel(item)));
    if(item.object_type==='upload'||item.object_type==='recording')add('Download',()=>window.open('/research-workspace-file.php?id='+encodeURIComponent(String(item.public_id))+'&download=1','_blank','noopener'));
    if(teamId&&['document','bookmark','upload','recording'].includes(item.object_type))add('Share to Team',()=>shareObjectToTeam(item.object_type,item.public_id,item.object_type==='document'?'Research document':item.object_type==='bookmark'?'Research bookmark':item.object_type==='upload'?'Research file':'Recording'));
    if(canWrite()&&!trashMode&&!item.system){
      if(item.object_type!=='annotation')add('Rename',()=>renameItem(item),item.object_type==='document');
      add('Move to folder…',()=>moveItem(item));
      if(item.object_type!=='annotation')add('Move to Trash',()=>trashItem(item));
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
    dialogActions(form,async e=>{e.preventDefault();const ok=await moveDesktopObject(item,parent.value);if(ok)d.close();},'Move');d.showModal();
  }
  async function trashItem(item){
    if(!canWrite()||!confirm('Move '+desktopObjectLabel(item)+' to Trash?'))return;
    try{await api(workspaceUrl('trash'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id}});await loadDesktop(false);}catch(err){setStatus(err.message,true);}
  }
  async function restoreItem(item){
    if(!canWrite())return;try{await api(workspaceUrl('restore'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id}});await loadDesktop(true);}catch(err){setStatus(err.message,true);}
  }

  function applyWriteState(){
    desktop.querySelectorAll('[data-research-desktop-new-sticky],[data-research-desktop-new-doc],[data-research-desktop-new-folder],[data-research-desktop-new-bookmark],[data-research-desktop-upload],[data-research-desktop-recording]').forEach(b=>b.hidden=!canWrite());
    if(documentTitle)documentTitle.readOnly=!canWrite();if(documentEditor)documentEditor.contentEditable=canWrite()?'true':'false';
    desktop.querySelectorAll('.researchDocumentToolbar button,.researchDocumentToolbar select').forEach(control=>{if(!control.matches('[data-doc-ask-selection]'))control.disabled=!canWrite();});
  }

  function stickySeed(){return {x:44+(stickies.length%5)*36,y:70+(stickies.length%6)*30};}
  async function createSticky(body=''){
    if(!canWrite())return;const p=stickySeed();
    try{await api(workspaceUrl('create_sticky'),{method:'POST',data:{agent_id:agentId,body,color:'yellow',x:p.x,y:p.y,width:245,height:205,parent_id:currentFolder}});await loadDesktop(false);}catch(err){setStatus(err.message,true);}
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
      handle.addEventListener('pointermove',e=>{if(!dragging)return;const maxX=Math.max(0,(surface?.clientWidth||1200)-note.offsetWidth),maxY=Math.max(0,(surface?.clientHeight||700)-note.offsetHeight);note.style.left=Math.max(0,Math.min(maxX,left+e.clientX-startX))+'px';note.style.top=Math.max(0,Math.min(maxY,top+e.clientY-startY))+'px';updateFolderDropTarget(e.clientX,e.clientY,String(item.public_id||''));});
      handle.addEventListener('pointerup',async e=>{if(!dragging)return;dragging=false;try{handle.releasePointerCapture(e.pointerId);}catch{}const folder=folderDropTarget(e.clientX,e.clientY,String(item.public_id||''));clearFolderDropTarget();stickyPatch(item,{x:parseInt(note.style.left,10)||0,y:parseInt(note.style.top,10)||0,z:Number(note.style.zIndex)||1},0);if(folder)await moveDesktopObject({...item,object_type:'sticky'},String(folder.dataset.objectId||''));});
      handle.addEventListener('pointercancel',()=>{dragging=false;clearFolderDropTarget();});
      if('ResizeObserver' in window){
        const ro=new ResizeObserver(()=>{if(note.dataset.ready!=='1'||!note.isConnected)return;const key=String(item.public_id),old=stickyResizeTimers.get(key);if(old)clearTimeout(old);stickyResizeTimers.set(key,setTimeout(()=>{stickyResizeTimers.delete(key);stickyPatch(item,{width:Math.round(note.offsetWidth),height:Math.round(note.offsetHeight)},0);},400));});
        ro.observe(note);requestAnimationFrame(()=>note.dataset.ready='1');
      }
    }
    return note;
  }
  function renderStickies(){
    if(!stickyLayer)return;stickyLayer.replaceChildren();
    if(trashMode){stickyLayer.hidden=true;return;}stickyLayer.hidden=false;
    stickies.filter(item=>String(item.parent_public_id||'')===String(currentFolder||'')).forEach(item=>stickyLayer.appendChild(renderSticky(item)));
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
  async function uploadResearchFile(file,{x=32,y=72,parentId=currentFolder,title='',recordingSource='upload',duration=0}={}){
    if(!canWrite())throw new Error('You have view-only access to this Research Desktop.');
    const fd=new FormData();fd.append('agent_id',agentId);fd.append('parent_id',String(parentId||''));fd.append('x',String(Math.max(0,Math.round(x))));fd.append('y',String(Math.max(0,Math.round(y))));
    if(title)fd.append('title',title);if(duration>0)fd.append('duration_seconds',String(duration));fd.append('recording_source',recordingSource);fd.append('file',file,file.name||'upload');
    const response=await fetch('/api/research-workspace-upload.php',{method:'POST',headers:{Accept:'application/json','X-CSRF-Token':csrf},body:fd});
    const payload=await response.json().catch(()=>({ok:false,error:{message:'Invalid upload response.'}}));if(!response.ok||payload.ok===false)throw new Error(payload.error?.message||'Upload failed.');
    return payload.data?.item;
  }
  async function uploadFiles(fileList,x=38,y=82,parentId=currentFolder){
    const files=[...fileList];if(!files.length)return;setStatus('Uploading '+files.length+' file'+(files.length===1?'':'s')+'…');
    let firstRecording=null;
    try{
      for(let i=0;i<files.length;i++){
        const item=await uploadResearchFile(files[i],{x:x+(i%5)*18,y:y+(i%5)*18,parentId,recordingSource:'upload'});
        if(item?.object_type==='recording'&&!firstRecording)firstRecording=item;
        setStatus('Uploaded '+(i+1)+' of '+files.length+'…');
      }
      await loadDesktop(false);setStatus(files.length+' file'+(files.length===1?'':'s')+' added.');
      if(firstRecording)openRecording(String(firstRecording.public_id));
    }catch(err){setStatus(err.message||'Upload failed.',true);}
    finally{if(fileInput)fileInput.value='';}
  }
  function dropFolderId(event){
    const icon=event.target.closest?.('.researchDesktopIcon[data-object-type="folder"]');return icon?String(icon.dataset.objectId||''):currentFolder;
  }

  function formatClock(ms){const total=Math.max(0,Math.floor(ms/1000)),m=Math.floor(total/60),s=total%60;return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');}
  function stopRecordingMeter(){
    if(recordingMeterFrame)cancelAnimationFrame(recordingMeterFrame);recordingMeterFrame=null;
    try{recordingAudioContext?.close();}catch{}recordingAudioContext=null;if(recordingLevel)recordingLevel.style.width='0%';
  }
  function startRecordingMeter(stream){
    try{
      const AudioCtx=window.AudioContext||window.webkitAudioContext;if(!AudioCtx)return;recordingAudioContext=new AudioCtx();const source=recordingAudioContext.createMediaStreamSource(stream),analyser=recordingAudioContext.createAnalyser();analyser.fftSize=256;source.connect(analyser);const data=new Uint8Array(analyser.frequencyBinCount);
      const tick=()=>{analyser.getByteFrequencyData(data);let sum=0;for(const value of data)sum+=value;const level=Math.min(100,Math.round((sum/Math.max(1,data.length))/1.4));if(recordingLevel)recordingLevel.style.width=level+'%';recordingMeterFrame=requestAnimationFrame(tick);};tick();
    }catch{}
  }
  function resetRecording(){
    if(recordingTicker)clearInterval(recordingTicker);recordingTicker=null;stopRecordingMeter();mediaStream?.getTracks().forEach(t=>t.stop());mediaStream=null;mediaRecorder=null;recordingChunks=[];recordingBlob=null;recordingElapsedMs=0;
    if(recordingObjectUrl){URL.revokeObjectURL(recordingObjectUrl);recordingObjectUrl='';}
    if(recordingPreview){recordingPreview.pause();recordingPreview.removeAttribute('src');recordingPreview.hidden=true;}
    if(recordingClock)recordingClock.textContent='00:00';if(recordingStatus)recordingStatus.textContent='Ready to record';
    const start=desktop.querySelector('[data-research-record-start]'),pause=desktop.querySelector('[data-research-record-pause]'),stop=desktop.querySelector('[data-research-record-stop]'),save=desktop.querySelector('[data-research-record-save]');
    if(start)start.disabled=false;if(pause){pause.disabled=true;pause.textContent='Pause';}if(stop)stop.disabled=true;if(save)save.disabled=true;
  }
  function openRecordingWindow(){
    if(!canWrite())return;resetRecording();if(recordingTitle)recordingTitle.value='Research recording '+new Date().toLocaleString();recordingWindow.hidden=false;
  }
  function closeRecordingWindow(){if(mediaRecorder&&mediaRecorder.state!=='inactive')mediaRecorder.stop();resetRecording();recordingWindow.hidden=true;}
  async function startRecording(){
    if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){setStatus('Browser audio recording is not supported here.',true);return;}
    try{
      mediaStream=await navigator.mediaDevices.getUserMedia({audio:true});const preferred=['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus'];const mime=preferred.find(x=>MediaRecorder.isTypeSupported?.(x))||'';
      mediaRecorder=new MediaRecorder(mediaStream,mime?{mimeType:mime}:undefined);recordingChunks=[];recordingElapsedMs=0;
      mediaRecorder.addEventListener('dataavailable',e=>{if(e.data?.size)recordingChunks.push(e.data);});
      mediaRecorder.addEventListener('stop',()=>{const actual=mediaRecorder?.mimeType||mime||'audio/webm';recordingBlob=new Blob(recordingChunks,{type:actual});recordingObjectUrl=URL.createObjectURL(recordingBlob);recordingPreview.src=recordingObjectUrl;recordingPreview.hidden=false;desktop.querySelector('[data-research-record-save]').disabled=false;if(recordingStatus)recordingStatus.textContent='Recording complete. Review it, then Save & Transcribe.';mediaStream?.getTracks().forEach(t=>t.stop());mediaStream=null;stopRecordingMeter();});
      mediaRecorder.start(1000);desktop.querySelector('[data-research-record-start]').disabled=true;desktop.querySelector('[data-research-record-pause]').disabled=false;desktop.querySelector('[data-research-record-stop]').disabled=false;if(recordingStatus)recordingStatus.textContent='Recording…';startRecordingMeter(mediaStream);
      recordingTicker=setInterval(()=>{if(mediaRecorder?.state==='recording'){recordingElapsedMs+=250;if(recordingClock)recordingClock.textContent=formatClock(recordingElapsedMs);}},250);
    }catch(err){setStatus(err.message||'Microphone access failed.',true);resetRecording();}
  }
  function pauseRecording(){
    if(!mediaRecorder)return;const b=desktop.querySelector('[data-research-record-pause]');
    if(mediaRecorder.state==='recording'){mediaRecorder.pause();if(b)b.textContent='Resume';if(recordingStatus)recordingStatus.textContent='Paused';}
    else if(mediaRecorder.state==='paused'){mediaRecorder.resume();if(b)b.textContent='Pause';if(recordingStatus)recordingStatus.textContent='Recording…';}
  }
  function stopRecording(){
    if(!mediaRecorder||mediaRecorder.state==='inactive')return;if(recordingTicker)clearInterval(recordingTicker);recordingTicker=null;mediaRecorder.stop();desktop.querySelector('[data-research-record-pause]').disabled=true;desktop.querySelector('[data-research-record-stop]').disabled=true;
  }
  async function saveRecording(){
    if(!recordingBlob)return;const save=desktop.querySelector('[data-research-record-save]');save.disabled=true;if(recordingStatus)recordingStatus.textContent='Saving recording and queuing transcription…';
    try{
      const mime=recordingBlob.type.split(';')[0]||'audio/webm',ext=mime.includes('ogg')?'ogg':mime.includes('mp4')?'m4a':'webm';const title=String(recordingTitle?.value||'Research recording').trim();
      const file=new File([recordingBlob],(title||'research-recording').replace(/[^a-z0-9_-]+/gi,'-')+'.'+ext,{type:mime});
      const item=await uploadResearchFile(file,{x:70,y:90,parentId:currentFolder,title,recordingSource:'browser',duration:recordingElapsedMs/1000});
      closeRecordingWindow();await loadDesktop(false);if(item?.public_id)openRecording(String(item.public_id));
    }catch(err){if(recordingStatus)recordingStatus.textContent=err.message||'Recording could not be saved.';save.disabled=false;}
  }

  async function openRecording(publicId){
    publicId=String(publicId||'');if(!publicId)return;if(!desktopOpen)await openDesktop();
    try{
      const data=await api(workspaceUrl('recording',{object_id:publicId})),item=data.item;if(!item)throw new Error('Recording not found.');activeRecording=item;
      transcriptTitle.textContent=String(item.title||'Recording');transcriptAudio.src='/research-workspace-file.php?id='+encodeURIComponent(publicId);transcriptMeta.textContent=[item.recording_duration_seconds?Math.round(Number(item.recording_duration_seconds))+' sec':'',item.recording_mime_type||''].filter(Boolean).join(' · ');
      const state=String(item.transcript_status||'queued'),text=String(item.transcript_text||'');transcriptStatus.textContent=state==='ready'?'Transcript ready':state==='blocked'?'Transcription needs configuration':state==='failed'?'Transcription failed':'Transcription '+state+'…';
      transcriptText.textContent=text||((state==='ready')?'No transcript text returned.':'The transcript will appear here when processing finishes.');
      desktop.querySelector('[data-research-transcript-doc]').disabled=!canWrite()||state!=='ready';desktop.querySelector('[data-research-transcript-sticky]').disabled=!canWrite()||state!=='ready';
      const retry=desktop.querySelector('[data-research-transcript-retry]');retry.hidden=!canWrite()||!['blocked','failed'].includes(state);transcriptWindow.hidden=false;
      if(recordingPollTimer){clearTimeout(recordingPollTimer);recordingPollTimer=null;}if(['queued','processing'].includes(state))recordingPollTimer=setTimeout(()=>openRecording(publicId),4000);
    }catch(err){setStatus(err.message||'Unable to open recording.',true);}
  }
  function closeTranscript(){if(recordingPollTimer){clearTimeout(recordingPollTimer);recordingPollTimer=null;}transcriptWindow.hidden=true;activeRecording=null;transcriptAudio?.pause();}
  async function recordingToDocument(){
    if(!activeRecording)return;try{const data=await api(workspaceUrl('transcript_to_document'),{method:'POST',data:{agent_id:agentId,object_id:activeRecording.public_id}});closeTranscript();await loadDesktop(false);if(data.item?.public_id)openDocument(String(data.item.public_id));}catch(err){setStatus(err.message,true);}
  }
  async function retryTranscription(){
    if(!activeRecording)return;try{await api(workspaceUrl('retry_transcription'),{method:'POST',data:{agent_id:agentId,object_id:activeRecording.public_id}});await openRecording(activeRecording.public_id);}catch(err){setStatus(err.message,true);}
  }

  function askAgentAbout(type,id,label,selection='',customPrompt=''){
    const prompt=customPrompt||(selection?('Review this selected text from '+label+':\n\n'+selection):('Review this '+label+' and tell me what matters most.'));
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
  libraryOpenButton?.addEventListener('click',openLibrary);libraryCloseButton?.addEventListener('click',closeLibrary);
  librarySearch?.addEventListener('input',scheduleLibrarySearch);
  canvas.querySelectorAll('[data-research-library-filter]').forEach(button=>button.addEventListener('click',()=>{libraryFilter=String(button.dataset.researchLibraryFilter||'all');canvas.querySelectorAll('[data-research-library-filter]').forEach(b=>b.classList.toggle('is-active',b===button));loadLibraryResults();}));
  [libraryFolder,libraryStatus,libraryDateFrom,libraryDateTo].forEach(control=>control?.addEventListener('change',loadLibraryResults));
  libraryAskButton?.addEventListener('click',askAgentAboutLibrarySelection);
  libraryDesktopButton?.addEventListener('click',async()=>{closeLibrary();await openDesktop();});
  desktop.querySelector('[data-research-desktop-new-sticky]')?.addEventListener('click',()=>createSticky(''));
  desktop.querySelector('[data-research-desktop-new-doc]')?.addEventListener('click',openDocumentDialog);
  desktop.querySelector('[data-research-desktop-new-folder]')?.addEventListener('click',openFolderDialog);
  desktop.querySelector('[data-research-desktop-new-bookmark]')?.addEventListener('click',openBookmarkDialog);
  desktop.querySelector('[data-research-desktop-upload]')?.addEventListener('click',()=>fileInput?.click());
  fileInput?.addEventListener('change',()=>uploadFiles(fileInput.files||[],54,86,currentFolder));
  desktop.querySelector('[data-research-desktop-recording]')?.addEventListener('click',openRecordingWindow);
  desktop.querySelector('[data-research-recording-close]')?.addEventListener('click',closeRecordingWindow);
  desktop.querySelector('[data-research-record-start]')?.addEventListener('click',startRecording);
  desktop.querySelector('[data-research-record-pause]')?.addEventListener('click',pauseRecording);
  desktop.querySelector('[data-research-record-stop]')?.addEventListener('click',stopRecording);
  desktop.querySelector('[data-research-record-save]')?.addEventListener('click',saveRecording);
  desktop.querySelector('[data-research-transcript-close]')?.addEventListener('click',closeTranscript);
  desktop.querySelector('[data-research-transcript-ask]')?.addEventListener('click',()=>{if(activeRecording)askAgentAbout('recording',activeRecording.public_id,activeRecording.title||'recording');});
  desktop.querySelector('[data-research-transcript-summary]')?.addEventListener('click',()=>{if(activeRecording)askAgentAbout('recording',activeRecording.public_id,activeRecording.title||'recording','',"Summarize this recording transcript. Separate evidence, decisions, commitments, open questions, and next steps.");});
  desktop.querySelector('[data-research-transcript-tasks]')?.addEventListener('click',()=>{if(activeRecording)askAgentAbout('recording',activeRecording.public_id,activeRecording.title||'recording','',"Extract concrete tasks, owners if stated, deadlines if stated, decisions, and follow-ups from this recording transcript.");});
  desktop.querySelector('[data-research-transcript-doc]')?.addEventListener('click',recordingToDocument);
  desktop.querySelector('[data-research-transcript-sticky]')?.addEventListener('click',()=>{if(activeRecording?.transcript_text)createSticky(String(activeRecording.transcript_text).slice(0,10000));});
  desktop.querySelector('[data-research-transcript-retry]')?.addEventListener('click',retryTranscription);

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

  surface?.addEventListener('dragover',e=>{if(!canWrite()||![...(e.dataTransfer?.types||[])].includes('Files'))return;e.preventDefault();surface.classList.add('is-file-drop');if(e.dataTransfer)e.dataTransfer.dropEffect='copy';});
  surface?.addEventListener('dragleave',e=>{if(!surface.contains(e.relatedTarget))surface.classList.remove('is-file-drop');});
  surface?.addEventListener('drop',e=>{if(!canWrite()||!(e.dataTransfer?.files?.length))return;e.preventDefault();surface.classList.remove('is-file-drop');const rect=surface.getBoundingClientRect();uploadFiles(e.dataTransfer.files,e.clientX-rect.left,e.clientY-rect.top,dropFolderId(e));});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&libraryOpen){e.preventDefault();closeLibrary();}});
  document.addEventListener('visibilitychange',()=>{if(document.hidden&&documentDirty)saveDocument(true).catch(()=>{});});
  document.addEventListener('annotated:research-document-open',e=>openDocument(String(e.detail?.public_id||e.detail?.document_id||'')));
  document.addEventListener('annotated:research-recording-open',e=>openRecording(String(e.detail?.public_id||e.detail?.recording_id||'')));

  document.addEventListener('annotated:research-document-created',()=>{if(desktopOpen)loadDesktop(false);});
  document.addEventListener('annotated:research-action-executed',e=>{const type=e.detail?.result?.type;if(desktopOpen&&(type==='document'||type==='sticky'))loadDesktop(false);});
  document.addEventListener('annotated:agent-chat-feed-restored',()=>{if(desktopOpen)closeDesktop();});

  window.addEventListener('resize',()=>{if(desktopOpen)renderDesktop();});
  if(initialDocument)openDocument(initialDocument);

  window.AnnotatedResearchWorkspace={openDesktop,closeDesktop,openLibrary,closeLibrary,openDocument,openRecording,createSticky,uploadFiles,reload:()=>desktopOpen?loadDesktop(trashMode):libraryOpen?loadLibraryResults():Promise.resolve(),shareBookmarkToTeam:id=>shareObjectToTeam('bookmark',id,'Research bookmark'),shareDocumentToTeam:id=>shareObjectToTeam('document',id,'Research document')};
})();