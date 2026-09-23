(()=>{
  const canvas=document.querySelector('[data-agent-chat-canvas]');
  if(!canvas)return;
  const agentId=String(canvas.dataset.researchAgentId||document.body.dataset.researchAgentId||'').trim();
  const teamId=String(canvas.dataset.researchAgentTeam||document.body.dataset.researchAgentTeam||'').trim();
  const conversationId=String(canvas.dataset.researchAgentConversation||document.body.dataset.researchAgentConversation||'').trim();
  const initialDocument=String(canvas.dataset.researchDocument||'').trim();
  const csrf=String(canvas.dataset.csrf||'');
  const panel=canvas.querySelector('[data-research-workspace-panel]');
  const messages=canvas.querySelector('[data-agent-messages]');
  const messageHomeParent=messages?.parentNode||canvas;
  const messageHomeNext=messages?.nextSibling||null;
  const grid=canvas.querySelector('[data-research-workspace-grid]');
  const status=canvas.querySelector('[data-research-workspace-status]');
  const pathLabel=canvas.querySelector('[data-research-workspace-path]');
  const breadcrumbs=canvas.querySelector('[data-research-workspace-breadcrumbs]');
  const viewButtons=[...canvas.querySelectorAll('[data-research-workspace-view]')];
  const createFolderButton=canvas.querySelector('[data-research-create-folder]');
  const createBookmarkButton=canvas.querySelector('[data-research-create-bookmark]');
  const createDocumentButton=canvas.querySelector('[data-research-create-document]');
  const createStickyButton=canvas.querySelector('[data-research-create-sticky]');
  const documentWorkspace=canvas.querySelector('[data-research-document-workspace]');
  const documentTitle=canvas.querySelector('[data-research-document-title]');
  const documentEditor=canvas.querySelector('[data-research-document-editor]');
  const documentSaveState=canvas.querySelector('[data-research-document-save-state]');
  const documentAgentPane=canvas.querySelector('[data-research-document-agent-pane]');
  const documentHistoryPanel=canvas.querySelector('[data-research-document-history-panel]');
  const documentHistoryList=canvas.querySelector('[data-research-document-history-list]');
  const stickyLayer=canvas.querySelector('[data-research-sticky-layer]');

  let items=[],currentFolder='',currentView='chat',loading=false,accessRole='viewer';
  let activeDocument=null,documentRevision=0,documentDirty=false,documentSaveTimer=null,documentSaving=false,chatScroll=0;
  let stickies=[],maxStickyZ=1;
  const stickySaveTimers=new Map(),stickyResizeTimers=new Map();

  async function api(url,{method='GET',data=null}={}){
    const options={method,headers:{Accept:'application/json'}};
    if(method!=='GET'){
      options.headers['Content-Type']='application/json';
      options.headers['X-CSRF-Token']=csrf;
      options.body=JSON.stringify(data||{});
    }
    const r=await fetch(url,options),j=await r.json().catch(()=>({ok:false,error:{message:'Invalid server response.'}}));
    if(!r.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Research workspace request failed.');
    return j.data||{};
  }
  const workspaceUrl=(action,params={})=>{
    const u=new URL('/api/research-workspace-objects.php',location.origin);u.searchParams.set('action',action);
    if(agentId)u.searchParams.set('agent_id',agentId);
    Object.entries(params).forEach(([k,v])=>{if(v!==''&&v!==null&&v!==undefined)u.searchParams.set(k,String(v));});
    return u;
  };
  const canWrite=()=>['owner','admin','researcher'].includes(accessRole);
  function setStatus(message,error=false){
    if(!status)return;status.textContent=message||'';status.classList.toggle('is-error',!!error);
  }
  function applyWriteState(){
    [createFolderButton,createBookmarkButton,createDocumentButton,createStickyButton].forEach(b=>{if(b)b.hidden=!canWrite();});
  }
  function folderMap(){
    const map=new Map();for(const item of items)if(item.object_type==='folder')map.set(String(item.public_id),item);return map;
  }
  function folderPath(id){
    const map=folderMap(),out=[];let current=String(id||''),guard=0;
    while(current&&map.has(current)&&guard++<50){const row=map.get(current);out.unshift(row);current=String(row.parent_public_id||'');}
    return out;
  }
  function renderBreadcrumbs(){
    if(!breadcrumbs)return;breadcrumbs.replaceChildren();
    if(currentView==='docs'){breadcrumbs.hidden=true;return;}breadcrumbs.hidden=false;
    const root=document.createElement('button');root.type='button';root.textContent='All files';root.dataset.folder='';
    root.addEventListener('click',()=>{currentFolder='';render();});root.addEventListener('dragover',e=>e.preventDefault());root.addEventListener('drop',e=>dropMove(e,''));
    breadcrumbs.appendChild(root);
    for(const row of folderPath(currentFolder)){
      const sep=document.createElement('span');sep.textContent='/';breadcrumbs.appendChild(sep);
      const b=document.createElement('button');b.type='button';b.textContent=row.title;b.dataset.folder=row.public_id;b.addEventListener('click',()=>{currentFolder=String(row.public_id);render();});breadcrumbs.appendChild(b);
    }
  }
  function itemIcon(type){return ({folder:'▰',bookmark:'◇',document:'▤',sticky:'▧',upload:'⇧',recording:'◉'})[type]||'•';}
  function objectMeta(item){
    if(item.object_type==='bookmark')return item.domain||'Website bookmark';
    if(item.object_type==='folder')return 'Folder';
    if(item.object_type==='document'){
      const type=String(item.document_type||'document').replace(/_/g,' ');
      return type.charAt(0).toUpperCase()+type.slice(1)+' · v'+String(item.revision_number||1)+(item.parent_title?' · '+item.parent_title:'');
    }
    if(item.object_type==='sticky')return 'Sticky note';
    return String(item.object_type||'Workspace item').replace(/_/g,' ');
  }
  function actionButton(label,action){
    const b=document.createElement('button');b.type='button';b.textContent=label;b.dataset.workspaceAction=action;return b;
  }
  function askAgentAbout(type,id,title,selection=''){
    const prompt=selection
      ?'Review this selected passage from "'+title+'":\n\n'+selection
      :'Review "'+title+'" in the context of this Research Agent. Identify what matters, evidence gaps, and the most useful next step.';
    document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:{
      prompt,context:[{type,public_id:id,label:title}],research_agent:true,conversation:conversationId,source:'research_workspace'
    },bubbles:true,cancelable:true}));
  }
  async function shareObjectToTeam(type,publicId,label){
    if(!publicId||!teamId)return;
    setStatus('Sharing '+label+' to Team Chat…');
    try{
      const list=await api('/api/conversations.php?action=list'),conversation=(list.conversations||[]).find(x=>String(x.team_public_id||'')===String(teamId));
      if(!conversation)throw new Error('The Team Chat conversation is unavailable.');
      await api('/api/conversations.php?action=send',{method:'POST',data:{
        conversation:conversation.public_id,body:'Shared '+label+'.',client_message_id:crypto.randomUUID(),
        attachments:[{type,public_id:publicId}]
      }});
      setStatus('Shared to '+(conversation.team_name||'Team')+'.');
    }catch(err){setStatus(err.message||'Unable to share with Team Chat.',true);}
  }
  function buildCard(item,trashed=false){
    const card=document.createElement('article');card.className='researchWorkspaceItem researchWorkspaceItem-'+item.object_type;card.dataset.objectId=item.public_id;card.draggable=!trashed&&item.object_type!=='sticky';
    const icon=document.createElement('div');icon.className='researchWorkspaceItemIcon';icon.textContent=itemIcon(item.object_type);
    const copy=document.createElement('div');copy.className='researchWorkspaceItemCopy';
    const title=document.createElement('strong');title.textContent=item.title||'Untitled';
    const meta=document.createElement('small');meta.textContent=objectMeta(item)+(item.creator_name?' · '+item.creator_name:'');
    copy.append(title,meta);
    if(item.object_type==='bookmark'&&item.description){const p=document.createElement('p');p.textContent=String(item.description).slice(0,180);copy.appendChild(p);}
    if(item.object_type==='document'&&item.document_summary){const p=document.createElement('p');p.textContent=String(item.document_summary).slice(0,220);copy.appendChild(p);}
    if(item.object_type==='sticky'&&item.sticky_body){const p=document.createElement('p');p.textContent=String(item.sticky_body).slice(0,220);copy.appendChild(p);}
    const actions=document.createElement('div');actions.className='researchWorkspaceItemActions';
    if(trashed){
      if(canWrite()){const restore=actionButton('Restore','restore');restore.addEventListener('click',()=>mutateItem('restore',item));actions.appendChild(restore);}
    }else{
      if(item.object_type==='folder'){
        const open=actionButton('Open','open');open.addEventListener('click',()=>{currentFolder=String(item.public_id);render();});actions.appendChild(open);
      }else if(item.object_type==='bookmark'){
        const open=document.createElement('a');open.href=item.canonical_url||'#';open.target='_blank';open.rel='noopener noreferrer';open.textContent='Open';actions.appendChild(open);
        const ask=actionButton('Ask Agent','ask');ask.addEventListener('click',()=>askAgentAbout('bookmark',String(item.public_id),String(item.title||'Bookmark')));actions.appendChild(ask);
        if(teamId){const share=actionButton('Team','team');share.addEventListener('click',()=>shareObjectToTeam('bookmark',item.public_id,'Research bookmark'));actions.appendChild(share);}
      }else if(item.object_type==='document'){
        const open=actionButton('Open','open');open.addEventListener('click',()=>openDocument(String(item.public_id)));actions.appendChild(open);
        const ask=actionButton('Ask Agent','ask');ask.addEventListener('click',()=>askAgentAbout('document',String(item.public_id),String(item.title||'Document')));actions.appendChild(ask);
        if(teamId){const share=actionButton('Team','team');share.addEventListener('click',()=>shareObjectToTeam('document',item.public_id,'Research document'));actions.appendChild(share);}
      }
      if(canWrite()&&item.object_type!=='sticky'){
        const rename=actionButton('Rename','rename');rename.addEventListener('click',()=>renameItem(item));actions.appendChild(rename);
        const move=actionButton('Move','move');move.addEventListener('click',()=>moveItem(item));actions.appendChild(move);
        const trash=actionButton('Trash','trash');trash.addEventListener('click',()=>mutateItem('trash',item));actions.appendChild(trash);
      }
    }
    card.append(icon,copy,actions);
    if(!trashed&&card.draggable){
      card.addEventListener('dragstart',e=>{e.dataTransfer?.setData('text/x-annotated-workspace-object',String(item.public_id));if(e.dataTransfer)e.dataTransfer.effectAllowed='move';});
      if(item.object_type==='folder'){
        card.addEventListener('dragover',e=>{e.preventDefault();card.classList.add('is-drop-target');});
        card.addEventListener('dragleave',()=>card.classList.remove('is-drop-target'));
        card.addEventListener('drop',e=>{card.classList.remove('is-drop-target');dropMove(e,String(item.public_id));});
      }
    }
    return card;
  }
  async function dropMove(event,parentId){
    event.preventDefault();if(!canWrite())return;
    const objectId=String(event.dataTransfer?.getData('text/x-annotated-workspace-object')||'');if(!objectId||objectId===parentId)return;
    const item=items.find(x=>String(x.public_id)===objectId);if(!item)return;
    await mutateItem('move',item,{parent_id:parentId});
  }
  function render(){
    if(!panel||!grid)return;
    const trashed=currentView==='trash';renderBreadcrumbs();grid.replaceChildren();
    if(pathLabel){
      if(trashed)pathLabel.textContent='Deleted items';
      else if(currentView==='docs')pathLabel.textContent='All Research documents';
      else pathLabel.textContent=currentFolder?(folderPath(currentFolder).map(x=>x.title).join(' / ')||'Folder'):'All files';
    }
    let rows=items;
    if(trashed)rows=rows;
    else if(currentView==='docs')rows=rows.filter(x=>x.object_type==='document');
    else rows=rows.filter(x=>x.object_type!=='sticky'&&String(x.parent_public_id||'')===String(currentFolder||''));
    if(!rows.length){
      const empty=document.createElement('div');empty.className='researchWorkspaceEmpty';
      empty.innerHTML=trashed?'<strong>Trash is empty.</strong><span>Deleted documents, folders, bookmarks, and stickies can be restored here.</span>'
        :currentView==='docs'?'<strong>No documents yet.</strong><span>Create a document here, or ask the Research Agent to create a brief, memo, report, analysis, or timeline.</span>'
        :'<strong>This folder is empty.</strong><span>Add a bookmark, document, or folder.</span>';
      grid.appendChild(empty);return;
    }
    for(const item of rows)grid.appendChild(buildCard(item,trashed));
  }
  async function primeWorkspace(){
    if(!agentId)return;
    try{
      const data=await api(workspaceUrl('list'));items=data.items||[];accessRole=String(data.project?.access_role||'viewer');applyWriteState();
    }catch(err){setStatus(err.message||'Unable to load Research workspace.',true);}
  }
  async function loadWorkspace(trashed=false){
    if(!agentId||loading)return;loading=true;setStatus('Loading workspace…');
    try{
      const data=await api(workspaceUrl('list',{trashed:trashed?1:0}));items=data.items||[];accessRole=String(data.project?.access_role||accessRole);applyWriteState();
      if(!trashed&&currentFolder&&!items.some(x=>x.object_type==='folder'&&String(x.public_id)===currentFolder))currentFolder='';
      setStatus('');render();
    }catch(err){setStatus(err.message||'Unable to load Research workspace.',true);}
    finally{loading=false;}
  }
  function setView(view){
    if(activeDocument){closeDocument(false);}
    currentView=view;for(const b of viewButtons)b.classList.toggle('active',b.dataset.researchWorkspaceView===view);
    if(view==='chat'){if(panel)panel.hidden=true;if(documentWorkspace)documentWorkspace.hidden=true;if(messages)messages.hidden=false;return;}
    if(messages)messages.hidden=true;if(documentWorkspace)documentWorkspace.hidden=true;if(panel)panel.hidden=false;currentFolder='';loadWorkspace(view==='trash');
  }
  async function mutateItem(action,item,extra={}){
    if(!canWrite())return;
    setStatus(action==='restore'?'Restoring…':action==='trash'?'Moving to Trash…':'Moving…');
    try{
      await api(workspaceUrl(action),{method:'POST',data:{agent_id:agentId,object_id:item.public_id,...extra}});
      await loadWorkspace(currentView==='trash');if(item.object_type==='sticky')await loadStickies();
      document.dispatchEvent(new CustomEvent('annotated:research-workspace-changed',{detail:{action,object:item}}));
    }catch(err){setStatus(err.message||'Unable to update workspace item.',true);}
  }
  function dialogBase(title){
    const d=document.createElement('dialog');d.className='researchWorkspaceDialog';const form=document.createElement('form');form.method='dialog';
    const h=document.createElement('h3');h.textContent=title;form.appendChild(h);d.appendChild(form);document.body.appendChild(d);
    d.addEventListener('close',()=>d.remove());return {d,form};
  }
  function folderSelect(selected=''){
    const select=document.createElement('select');const root=document.createElement('option');root.value='';root.textContent='All files';select.appendChild(root);
    const map=folderMap();
    const labelFor=row=>{const path=[];let cur=row,guard=0;while(cur&&guard++<50){path.unshift(cur.title);cur=map.get(String(cur.parent_public_id||''));}return path.join(' / ');};
    [...map.values()].sort((a,b)=>labelFor(a,map).localeCompare(labelFor(b,map))).forEach(row=>{const o=document.createElement('option');o.value=row.public_id;o.textContent=labelFor(row);o.selected=String(row.public_id)===String(selected);select.appendChild(o);});
    return select;
  }
  function dialogActions(form,onSave,label='Save'){
    const actions=document.createElement('div');actions.className='researchWorkspaceDialogActions';
    const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';cancel.addEventListener('click',()=>form.closest('dialog')?.close());
    const save=document.createElement('button');save.type='submit';save.className='primary';save.textContent=label;actions.append(cancel,save);form.appendChild(actions);form.addEventListener('submit',onSave);
  }
  function openFolderDialog(){
    if(!canWrite())return;const {d,form}=dialogBase('New folder'),name=document.createElement('input'),parent=folderSelect(currentFolder);name.required=true;name.maxLength=240;name.placeholder='Folder name';
    const l1=document.createElement('label');l1.textContent='Name';l1.appendChild(name);const l2=document.createElement('label');l2.textContent='Location';l2.appendChild(parent);form.append(l1,l2);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('create_folder'),{method:'POST',data:{agent_id:agentId,title:name.value,parent_id:parent.value}});d.close();await loadWorkspace(false);}catch(err){setStatus(err.message,true);}},'Create folder');
    d.showModal();name.focus();
  }
  function openBookmarkDialog(){
    if(!canWrite())return;const {d,form}=dialogBase('Add website bookmark'),url=document.createElement('input'),title=document.createElement('input'),notes=document.createElement('textarea'),parent=folderSelect(currentFolder);
    url.type='url';url.required=true;url.placeholder='https://example.com/article';title.maxLength=240;title.placeholder='Optional title';notes.maxLength=5000;notes.rows=4;notes.placeholder='Why this matters…';
    for(const [label,input] of [['Website URL',url],['Title',title],['Notes',notes],['Folder',parent]]){const l=document.createElement('label');l.textContent=label;l.appendChild(input);form.appendChild(l);}
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('create_bookmark'),{method:'POST',data:{agent_id:agentId,url:url.value,title:title.value,description:notes.value,parent_id:parent.value}});d.close();await loadWorkspace(false);}catch(err){setStatus(err.message,true);}},'Save bookmark');
    d.showModal();url.focus();
  }
  function openDocumentDialog(){
    if(!canWrite())return;const {d,form}=dialogBase('New Research document'),title=document.createElement('input'),type=document.createElement('select'),parent=folderSelect(currentFolder);
    title.required=true;title.maxLength=240;title.placeholder='Document title';
    [['document','Document'],['research_brief','Research brief'],['memo','Memo'],['report','Report'],['analysis','Analysis'],['source_summary','Source summary'],['timeline','Timeline'],['weekly_report','Weekly report']].forEach(([value,label])=>{const o=document.createElement('option');o.value=value;o.textContent=label;type.appendChild(o);});
    for(const [label,input] of [['Title',title],['Document type',type],['Folder',parent]]){const l=document.createElement('label');l.textContent=label;l.appendChild(input);form.appendChild(l);}
    dialogActions(form,async e=>{e.preventDefault();try{
      const data=await api(workspaceUrl('create_document'),{method:'POST',data:{agent_id:agentId,title:title.value,document_type:type.value,parent_id:parent.value,content_html:'<p><br></p>'}});
      d.close();await primeWorkspace();await openDocument(String(data.item?.public_id||''));
    }catch(err){setStatus(err.message,true);}},'Create document');
    d.showModal();title.focus();
  }
  function renameItem(item){
    if(!canWrite())return;const {d,form}=dialogBase('Rename '+(item.object_type==='folder'?'folder':'item')),name=document.createElement('input');name.required=true;name.maxLength=240;name.value=item.title||'';
    const label=document.createElement('label');label.textContent='Name';label.appendChild(name);form.appendChild(label);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('rename'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id,title:name.value}});d.close();await loadWorkspace(currentView==='trash');}catch(err){setStatus(err.message,true);}});
    d.showModal();name.select();
  }
  function moveItem(item){
    if(!canWrite())return;const {d,form}=dialogBase('Move '+(item.title||'item')),parent=folderSelect(item.parent_public_id||'');
    const label=document.createElement('label');label.textContent='Move to';label.appendChild(parent);form.appendChild(label);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('move'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id,parent_id:parent.value}});d.close();await primeWorkspace();if(currentView!=='chat')render();}catch(err){setStatus(err.message,true);}},'Move');
    d.showModal();
  }

  function setDocumentSaveState(text,error=false){
    if(!documentSaveState)return;documentSaveState.textContent=text;documentSaveState.classList.toggle('is-error',!!error);
  }
  function documentUrl(publicId){
    const url=new URL(location.href);if(publicId)url.searchParams.set('doc',publicId);else url.searchParams.delete('doc');return url.pathname+(url.searchParams.toString()?'?'+url.searchParams.toString():'')+url.hash;
  }
  async function openDocument(publicId){
    publicId=String(publicId||'').trim();if(!publicId||!documentWorkspace||!documentEditor||!documentTitle)return;
    if(activeDocument&&String(activeDocument.public_id)!==publicId)await saveDocument(true);
    try{
      const data=await api(workspaceUrl('document',{object_id:publicId})),item=data.item;if(!item)throw new Error('Document not found.');
      activeDocument=item;documentRevision=Number(item.revision_number||1);documentDirty=false;
      documentTitle.value=String(item.title||'Untitled document');documentEditor.innerHTML=String(item.content_html||'<p><br></p>');
      setDocumentSaveState('Saved · v'+documentRevision);
      chatScroll=messages?.scrollTop||0;
      if(panel)panel.hidden=true;documentWorkspace.hidden=false;if(messages){messages.hidden=false;documentAgentPane?.appendChild(messages);}
      canvas.classList.add('researchDocumentMode');currentView='document';
      for(const b of viewButtons)b.classList.toggle('active',b.dataset.researchWorkspaceView==='docs');
      history.replaceState({},'',documentUrl(publicId));
      requestAnimationFrame(()=>documentEditor.focus());
    }catch(err){setStatus(err.message||'Unable to open document.',true);}
  }
  async function saveDocument(force=false){
    if(!activeDocument||!canWrite()||documentSaving||(!documentDirty&&!force))return activeDocument;
    if(documentSaveTimer){clearTimeout(documentSaveTimer);documentSaveTimer=null;}
    documentSaving=true;setDocumentSaveState('Saving…');
    try{
      const data=await api(workspaceUrl('save_document'),{method:'POST',data:{
        agent_id:agentId,object_id:activeDocument.public_id,title:documentTitle?.value||activeDocument.title,
        content_html:documentEditor?.innerHTML||'',base_revision:documentRevision
      }});
      activeDocument=data.item||activeDocument;documentRevision=Number(activeDocument.revision_number||documentRevision);documentDirty=false;
      setDocumentSaveState('Saved · v'+documentRevision);await primeWorkspace();
      document.dispatchEvent(new CustomEvent('annotated:research-document-saved',{detail:{document:activeDocument}}));
      return activeDocument;
    }catch(err){setDocumentSaveState(err.message||'Save failed',true);throw err;}
    finally{documentSaving=false;}
  }
  function scheduleDocumentSave(){
    if(!activeDocument||!canWrite())return;documentDirty=true;setDocumentSaveState('Unsaved');
    if(documentSaveTimer)clearTimeout(documentSaveTimer);
    documentSaveTimer=setTimeout(()=>saveDocument().catch(()=>{}),1100);
  }
  async function closeDocument(save=true){
    if(!activeDocument)return;
    if(save&&documentDirty){try{await saveDocument(true);}catch{}}
    activeDocument=null;documentRevision=0;documentDirty=false;
    if(documentSaveTimer){clearTimeout(documentSaveTimer);documentSaveTimer=null;}
    if(messages){messageHomeParent.insertBefore(messages,messageHomeNext);messages.hidden=false;}
    if(documentWorkspace)documentWorkspace.hidden=true;canvas.classList.remove('researchDocumentMode');
    currentView='chat';for(const b of viewButtons)b.classList.toggle('active',b.dataset.researchWorkspaceView==='chat');
    if(panel)panel.hidden=true;history.replaceState({},'',documentUrl(''));requestAnimationFrame(()=>{if(messages)messages.scrollTop=chatScroll;});
  }
  function selectedDocumentText(){
    const selection=window.getSelection();if(!selection||selection.isCollapsed||!documentEditor)return '';
    const range=selection.getRangeAt(0);if(!documentEditor.contains(range.commonAncestorContainer))return '';
    return selection.toString().trim().slice(0,12000);
  }
  function execDocumentCommand(command,value=null){
    if(!activeDocument||!canWrite()||!documentEditor)return;documentEditor.focus();
    try{document.execCommand(command,false,value);}catch{}
    scheduleDocumentSave();
  }
  function insertDocumentTable(){
    if(!activeDocument||!canWrite())return;
    execDocumentCommand('insertHTML','<table><tbody><tr><th>Column 1</th><th>Column 2</th></tr><tr><td>Value</td><td>Value</td></tr></tbody></table><p><br></p>');
  }
  async function openDocumentHistory(){
    if(!activeDocument||!documentHistoryPanel||!documentHistoryList)return;
    documentHistoryPanel.hidden=false;documentHistoryList.innerHTML='<div class="meta">Loading versions…</div>';
    try{
      const data=await api(workspaceUrl('document_revisions',{object_id:activeDocument.public_id}));documentHistoryList.replaceChildren();
      for(const revision of data.revisions||[]){
        const row=document.createElement('article');row.className='researchDocumentRevision';
        const copy=document.createElement('div'),strong=document.createElement('strong'),small=document.createElement('small');
        strong.textContent='Version '+String(revision.revision_number);small.textContent=(revision.editor_name||revision.editor_username||'Agent')+' · '+new Date(revision.created_at).toLocaleString();copy.append(strong,small);
        row.appendChild(copy);
        if(Number(revision.revision_number)===documentRevision){const current=document.createElement('span');current.textContent='Current';row.appendChild(current);}
        else if(canWrite()){const restore=actionButton('Restore','restore-version');restore.addEventListener('click',async()=>{if(!confirm('Restore this version as a new current version?'))return;try{
          const restored=await api(workspaceUrl('restore_document_revision'),{method:'POST',data:{agent_id:agentId,object_id:activeDocument.public_id,revision_id:revision.public_id,base_revision:documentRevision}});
          activeDocument=restored.item;documentRevision=Number(activeDocument.revision_number||documentRevision);documentTitle.value=activeDocument.title||'';documentEditor.innerHTML=activeDocument.content_html||'<p><br></p>';documentDirty=false;setDocumentSaveState('Saved · v'+documentRevision);await openDocumentHistory();
        }catch(err){setDocumentSaveState(err.message||'Restore failed',true);}});row.appendChild(restore);}
        documentHistoryList.appendChild(row);
      }
    }catch(err){documentHistoryList.textContent=err.message||'Unable to load version history.';}
  }

  function stickyPosition(seed=0){
    const layer=stickyLayer?.getBoundingClientRect();const width=layer?.width||900;
    return {x:Math.max(18,Math.min(width-280,36+(seed%8)*28)),y:96+(seed%9)*22};
  }
  async function createSticky(body=''){
    if(!canWrite()||!stickyLayer)return;
    const p=stickyPosition(stickies.length),data=await api(workspaceUrl('create_sticky'),{method:'POST',data:{agent_id:agentId,body,color:'yellow',x:p.x,y:p.y,width:240,height:190}});
    if(data.item){await loadStickies();const note=stickyLayer.querySelector('[data-sticky-id="'+CSS.escape(String(data.item.public_id))+'"] textarea');note?.focus();}
  }
  function scheduleStickySave(item,patch,delay=500){
    if(!canWrite())return;const id=String(item.public_id);const previous=stickySaveTimers.get(id);if(previous)clearTimeout(previous);
    const timer=setTimeout(async()=>{stickySaveTimers.delete(id);try{
      const data=await api(workspaceUrl('update_sticky'),{method:'POST',data:{agent_id:agentId,object_id:id,...patch}});
      const idx=stickies.findIndex(x=>String(x.public_id)===id);if(idx>=0&&data.item)stickies[idx]={...stickies[idx],...data.item};
    }catch(err){setStatus(err.message||'Sticky note could not be saved.',true);}},delay);
    stickySaveTimers.set(id,timer);
  }
  function bringStickyFront(item,note){
    maxStickyZ=Math.max(maxStickyZ,...stickies.map(x=>Number(x.z_index||x.sticky_z||1)))+1;note.style.zIndex=String(maxStickyZ);item.z_index=maxStickyZ;scheduleStickySave(item,{z:maxStickyZ},0);
  }
  function renderSticky(item){
    const note=document.createElement('article');note.className='researchSticky researchSticky-'+String(item.color||item.sticky_color||'yellow');note.dataset.stickyId=String(item.public_id);
    note.style.left=String(Number(item.position_x??item.sticky_x??32))+'px';note.style.top=String(Number(item.position_y??item.sticky_y??96))+'px';
    note.style.width=String(Number(item.width_px??item.sticky_width??240))+'px';note.style.height=String(Number(item.height_px??item.sticky_height??190))+'px';
    note.style.zIndex=String(Number(item.z_index??item.sticky_z??1));
    const head=document.createElement('header'),handle=document.createElement('button'),palette=document.createElement('div'),remove=document.createElement('button');
    handle.type='button';handle.className='researchStickyHandle';handle.textContent='⋮⋮';handle.title='Drag sticky note';
    palette.className='researchStickyPalette';
    ['yellow','pink','blue','green','purple','gray'].forEach(color=>{const b=document.createElement('button');b.type='button';b.className='researchStickyColor researchStickyColor-'+color;b.title=color;b.setAttribute('aria-label','Set '+color+' color');b.addEventListener('click',()=>{if(!canWrite())return;note.className='researchSticky researchSticky-'+color;item.color=color;scheduleStickySave(item,{color},0);});palette.appendChild(b);});
    remove.type='button';remove.className='researchStickyDelete';remove.textContent='×';remove.title='Delete sticky note';remove.hidden=!canWrite();remove.addEventListener('click',async()=>{if(!confirm('Move this sticky note to Trash?'))return;await mutateItem('trash',{...item,object_type:'sticky'});});
    head.append(handle,palette,remove);
    const body=document.createElement('textarea');body.value=String(item.body??item.sticky_body??'');body.maxLength=10000;body.placeholder='Write a note…';body.readOnly=!canWrite();
    body.addEventListener('input',()=>{item.body=body.value;scheduleStickySave(item,{body:body.value});});
    note.append(head,body);note.addEventListener('pointerdown',()=>bringStickyFront(item,note));
    if(canWrite()){
      let dragging=false,startX=0,startY=0,left=0,top=0;
      handle.addEventListener('pointerdown',e=>{e.preventDefault();dragging=true;handle.setPointerCapture(e.pointerId);startX=e.clientX;startY=e.clientY;left=parseInt(note.style.left,10)||0;top=parseInt(note.style.top,10)||0;bringStickyFront(item,note);});
      handle.addEventListener('pointermove',e=>{if(!dragging)return;const layerRect=stickyLayer.getBoundingClientRect(),maxX=Math.max(0,layerRect.width-note.offsetWidth),maxY=Math.max(0,layerRect.height-note.offsetHeight);const x=Math.max(0,Math.min(maxX,left+e.clientX-startX)),y=Math.max(0,Math.min(maxY,top+e.clientY-startY));note.style.left=x+'px';note.style.top=y+'px';});
      handle.addEventListener('pointerup',e=>{if(!dragging)return;dragging=false;try{handle.releasePointerCapture(e.pointerId);}catch{}const x=parseInt(note.style.left,10)||0,y=parseInt(note.style.top,10)||0;item.position_x=x;item.position_y=y;scheduleStickySave(item,{x,y,z:Number(note.style.zIndex)||1},0);});
      if('ResizeObserver' in window){
        const ro=new ResizeObserver(()=>{if(!note.isConnected||note.dataset.ready!=='1')return;const width=Math.round(note.offsetWidth),height=Math.round(note.offsetHeight);const old=stickyResizeTimers.get(String(item.public_id));if(old)clearTimeout(old);stickyResizeTimers.set(String(item.public_id),setTimeout(()=>{stickyResizeTimers.delete(String(item.public_id));scheduleStickySave(item,{width,height},0);},450));});
        ro.observe(note);requestAnimationFrame(()=>{note.dataset.ready='1';});
      }
    }
    return note;
  }
  function renderStickies(){
    if(!stickyLayer)return;stickyLayer.replaceChildren();maxStickyZ=1;
    for(const item of stickies){maxStickyZ=Math.max(maxStickyZ,Number(item.z_index||1));stickyLayer.appendChild(renderSticky(item));}
    stickyLayer.hidden=!stickies.length&&!canWrite();
  }
  async function loadStickies(){
    if(!agentId||!stickyLayer)return;
    try{const data=await api(workspaceUrl('stickies'));stickies=data.items||[];renderStickies();}catch(err){setStatus(err.message||'Unable to load sticky notes.',true);}
  }

  for(const b of viewButtons)b.addEventListener('click',()=>setView(String(b.dataset.researchWorkspaceView||'chat')));
  createFolderButton?.addEventListener('click',openFolderDialog);
  createBookmarkButton?.addEventListener('click',openBookmarkDialog);
  createDocumentButton?.addEventListener('click',openDocumentDialog);
  createStickyButton?.addEventListener('click',()=>createSticky('').catch(err=>setStatus(err.message,true)));
  canvas.querySelector('[data-research-document-close]')?.addEventListener('click',()=>closeDocument(true));
  canvas.querySelector('[data-research-document-history]')?.addEventListener('click',openDocumentHistory);
  canvas.querySelector('[data-research-document-history-close]')?.addEventListener('click',()=>{if(documentHistoryPanel)documentHistoryPanel.hidden=true;});
  canvas.querySelector('[data-research-document-move]')?.addEventListener('click',()=>{if(activeDocument)moveItem(activeDocument);});
  documentTitle?.addEventListener('input',scheduleDocumentSave);
  documentEditor?.addEventListener('input',scheduleDocumentSave);
  canvas.querySelectorAll('[data-doc-command]').forEach(button=>{
    button.addEventListener('mousedown',e=>e.preventDefault());
    button.addEventListener('click',()=>execDocumentCommand(String(button.dataset.docCommand||'')));
  });
  canvas.querySelector('[data-doc-block]')?.addEventListener('change',e=>{const tag=String(e.target.value||'p');execDocumentCommand('formatBlock','<'+tag+'>');});
  canvas.querySelector('[data-doc-link]')?.addEventListener('click',()=>{const href=prompt('Link URL');if(href)execDocumentCommand('createLink',href);});
  canvas.querySelector('[data-doc-table]')?.addEventListener('click',insertDocumentTable);
  canvas.querySelector('[data-doc-ask-selection]')?.addEventListener('click',()=>{if(!activeDocument)return;const selection=selectedDocumentText();askAgentAbout('document',String(activeDocument.public_id),String(documentTitle?.value||activeDocument.title||'Document'),selection);});
  canvas.querySelector('[data-doc-sticky-selection]')?.addEventListener('click',()=>{const selection=selectedDocumentText();if(selection)createSticky(selection).catch(err=>setStatus(err.message,true));});
  window.addEventListener('beforeunload',()=>{if(activeDocument&&documentDirty&&navigator.sendBeacon){/* autosave intentionally remains same-origin fetch; unload warning handled by browser */}});
  document.addEventListener('visibilitychange',()=>{if(document.hidden&&documentDirty)saveDocument(true).catch(()=>{});});
  document.addEventListener('annotated:research-document-open',e=>openDocument(String(e.detail?.public_id||'')));
  document.addEventListener('annotated:research-action-executed',e=>{if(e.detail?.result?.type==='document'){primeWorkspace();}});
  document.addEventListener('annotated:agent-chat-feed-restored',()=>{if(activeDocument)closeDocument(false);});
  document.addEventListener('click',e=>{
    const share=e.target.closest('[data-bookmark-share-team]');if(!share)return;
    const card=share.closest('[data-bookmark-id]');if(!card)return;
    shareObjectToTeam('bookmark',String(card.dataset.bookmarkId||''),'Research bookmark');
  });

  async function boot(){
    if(!agentId)return;
    await primeWorkspace();await loadStickies();
    if(initialDocument)await openDocument(initialDocument);
  }
  boot();

  window.AnnotatedResearchWorkspace={
    reload:async()=>{await primeWorkspace();if(currentView!=='chat'&&currentView!=='document')render();await loadStickies();},
    openDocument,closeDocument,createSticky,
    shareBookmarkToTeam:(id)=>shareObjectToTeam('bookmark',id,'Research bookmark'),
    shareDocumentToTeam:(id)=>shareObjectToTeam('document',id,'Research document')
  };
})();