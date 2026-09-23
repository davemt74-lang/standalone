(()=>{
  const canvas=document.querySelector('[data-agent-chat-canvas]');
  const agentId=String(canvas?.dataset.researchAgentId||document.body.dataset.researchAgentId||'').trim();
  const teamId=String(canvas?.dataset.researchAgentTeam||document.body.dataset.researchAgentTeam||'').trim();
  const csrf=String(canvas?.dataset.csrf||'');
  const panel=canvas?.querySelector('[data-research-workspace-panel]');
  const messages=canvas?.querySelector('[data-agent-messages]');
  const grid=canvas?.querySelector('[data-research-workspace-grid]');
  const status=canvas?.querySelector('[data-research-workspace-status]');
  const pathLabel=canvas?.querySelector('[data-research-workspace-path]');
  const breadcrumbs=canvas?.querySelector('[data-research-workspace-breadcrumbs]');
  const viewButtons=[...(canvas?.querySelectorAll('[data-research-workspace-view]')||[])];

  let items=[],currentFolder='',currentView='chat',loading=false;

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
  function setStatus(message,error=false){
    if(!status)return;status.textContent=message||'';status.classList.toggle('is-error',!!error);
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
    return String(item.object_type||'Workspace item').replace(/_/g,' ');
  }
  function actionButton(label,action){
    const b=document.createElement('button');b.type='button';b.textContent=label;b.dataset.workspaceAction=action;return b;
  }
  function buildCard(item,trashed=false){
    const card=document.createElement('article');card.className='researchWorkspaceItem researchWorkspaceItem-'+item.object_type;card.dataset.objectId=item.public_id;card.draggable=!trashed;
    const icon=document.createElement('div');icon.className='researchWorkspaceItemIcon';icon.textContent=itemIcon(item.object_type);
    const copy=document.createElement('div');copy.className='researchWorkspaceItemCopy';
    const title=document.createElement('strong');title.textContent=item.title||'Untitled';
    const meta=document.createElement('small');meta.textContent=objectMeta(item)+(item.creator_name?' · '+item.creator_name:'');
    copy.append(title,meta);
    if(item.object_type==='bookmark'&&item.description){const p=document.createElement('p');p.textContent=String(item.description).slice(0,180);copy.appendChild(p);}
    const actions=document.createElement('div');actions.className='researchWorkspaceItemActions';
    if(trashed){
      const restore=actionButton('Restore','restore');restore.addEventListener('click',()=>mutateItem('restore',item));actions.appendChild(restore);
    }else{
      if(item.object_type==='folder'){
        const open=actionButton('Open','open');open.addEventListener('click',()=>{currentFolder=String(item.public_id);render();});actions.appendChild(open);
      }else if(item.object_type==='bookmark'){
        const open=document.createElement('a');open.href=item.canonical_url||'#';open.target='_blank';open.rel='noopener noreferrer';open.textContent='Open';actions.appendChild(open);
        const ask=document.createElement('a');ask.href='/home.php?agent='+encodeURIComponent(String(canvas?.dataset.researchAgentConversation||''))+'&agent_context_type=bookmark&agent_context_id='+encodeURIComponent(String(item.public_id));ask.textContent='Ask Agent';actions.appendChild(ask);
        if(teamId){const share=actionButton('Team','team');share.addEventListener('click',()=>shareBookmarkToTeam(item.public_id,teamId));actions.appendChild(share);}
      }
      const rename=actionButton('Rename','rename');rename.addEventListener('click',()=>renameItem(item));actions.appendChild(rename);
      const move=actionButton('Move','move');move.addEventListener('click',()=>moveItem(item));actions.appendChild(move);
      const trash=actionButton('Trash','trash');trash.addEventListener('click',()=>mutateItem('trash',item));actions.appendChild(trash);
    }
    card.append(icon,copy,actions);
    if(!trashed){
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
    event.preventDefault();const objectId=String(event.dataTransfer?.getData('text/x-annotated-workspace-object')||'');if(!objectId||objectId===parentId)return;
    const item=items.find(x=>String(x.public_id)===objectId);if(!item)return;
    await mutateItem('move',item,{parent_id:parentId});
  }
  function render(){
    if(!panel||!grid)return;
    const trashed=currentView==='trash';
    renderBreadcrumbs();grid.replaceChildren();
    if(pathLabel)pathLabel.textContent=trashed?'Deleted items':(currentFolder?(folderPath(currentFolder).map(x=>x.title).join(' / ')||'Folder'):'All files');
    let rows=items;
    if(!trashed)rows=rows.filter(x=>String(x.parent_public_id||'')===String(currentFolder||''));
    if(!rows.length){const empty=document.createElement('div');empty.className='researchWorkspaceEmpty';empty.innerHTML=trashed?'<strong>Trash is empty.</strong><span>Deleted workspace items can be restored here.</span>':'<strong>This folder is empty.</strong><span>Add a bookmark or create a folder.</span>';grid.appendChild(empty);return;}
    for(const item of rows)grid.appendChild(buildCard(item,trashed));
  }
  async function loadWorkspace(trashed=false){
    if(!agentId||loading)return;loading=true;setStatus('Loading workspace…');
    try{
      const data=await api(workspaceUrl('list',{trashed:trashed?1:0}));items=data.items||[];
      if(!trashed&&currentFolder&&!items.some(x=>x.object_type==='folder'&&String(x.public_id)===currentFolder))currentFolder='';
      setStatus('');render();
    }catch(err){setStatus(err.message||'Unable to load Research workspace.',true);}
    finally{loading=false;}
  }
  function setView(view){
    currentView=view;for(const b of viewButtons)b.classList.toggle('active',b.dataset.researchWorkspaceView===view);
    if(view==='chat'){
      if(panel)panel.hidden=true;if(messages)messages.hidden=false;return;
    }
    if(messages)messages.hidden=true;if(panel)panel.hidden=false;currentFolder='';loadWorkspace(view==='trash');
  }
  async function mutateItem(action,item,extra={}){
    setStatus(action==='restore'?'Restoring…':action==='trash'?'Moving to Trash…':'Moving…');
    try{
      await api(workspaceUrl(action),{method:'POST',data:{agent_id:agentId,object_id:item.public_id,...extra}});
      await loadWorkspace(currentView==='trash');
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
    [...map.values()].sort((a,b)=>labelFor(a).localeCompare(labelFor(b))).forEach(row=>{const o=document.createElement('option');o.value=row.public_id;o.textContent=labelFor(row);o.selected=String(row.public_id)===String(selected);select.appendChild(o);});
    return select;
  }
  function dialogActions(form,onSave,label='Save'){
    const actions=document.createElement('div');actions.className='researchWorkspaceDialogActions';
    const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';cancel.addEventListener('click',()=>form.closest('dialog')?.close());
    const save=document.createElement('button');save.type='submit';save.className='primary';save.textContent=label;actions.append(cancel,save);form.appendChild(actions);form.addEventListener('submit',onSave);
  }
  function openFolderDialog(){
    const {d,form}=dialogBase('New folder'),name=document.createElement('input'),parent=folderSelect(currentFolder);name.required=true;name.maxLength=240;name.placeholder='Folder name';
    const l1=document.createElement('label');l1.textContent='Name';l1.appendChild(name);const l2=document.createElement('label');l2.textContent='Location';l2.appendChild(parent);form.append(l1,l2);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('create_folder'),{method:'POST',data:{agent_id:agentId,title:name.value,parent_id:parent.value}});d.close();await loadWorkspace(false);}catch(err){setStatus(err.message,true);}},'Create folder');
    d.showModal();name.focus();
  }
  function openBookmarkDialog(){
    const {d,form}=dialogBase('Add website bookmark'),url=document.createElement('input'),title=document.createElement('input'),notes=document.createElement('textarea'),parent=folderSelect(currentFolder);
    url.type='url';url.required=true;url.placeholder='https://example.com/article';title.maxLength=240;title.placeholder='Optional title';notes.maxLength=5000;notes.rows=4;notes.placeholder='Why this matters…';
    for(const [label,input] of [['Website URL',url],['Title',title],['Notes',notes],['Folder',parent]]){const l=document.createElement('label');l.textContent=label;l.appendChild(input);form.appendChild(l);}
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('create_bookmark'),{method:'POST',data:{agent_id:agentId,url:url.value,title:title.value,description:notes.value,parent_id:parent.value}});d.close();await loadWorkspace(false);}catch(err){setStatus(err.message,true);}},'Save bookmark');
    d.showModal();url.focus();
  }
  function renameItem(item){
    const {d,form}=dialogBase('Rename '+(item.object_type==='folder'?'folder':'item')),name=document.createElement('input');name.required=true;name.maxLength=240;name.value=item.title||'';
    const label=document.createElement('label');label.textContent='Name';label.appendChild(name);form.appendChild(label);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('rename'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id,title:name.value}});d.close();await loadWorkspace(false);}catch(err){setStatus(err.message,true);}});
    d.showModal();name.select();
  }
  function moveItem(item){
    const {d,form}=dialogBase('Move '+(item.title||'item')),parent=folderSelect(item.parent_public_id||'');
    const label=document.createElement('label');label.textContent='Move to';label.appendChild(parent);form.appendChild(label);
    dialogActions(form,async e=>{e.preventDefault();try{await api(workspaceUrl('move'),{method:'POST',data:{agent_id:agentId,object_id:item.public_id,parent_id:parent.value}});d.close();await loadWorkspace(false);}catch(err){setStatus(err.message,true);}},'Move');
    d.showModal();
  }
  async function shareBookmarkToTeam(bookmarkId,targetTeam){
    if(!bookmarkId||!targetTeam)return;
    setStatus('Sharing bookmark to Team Chat…');
    try{
      const list=await api('/api/conversations.php?action=list'),conversation=(list.conversations||[]).find(x=>String(x.team_public_id||'')===String(targetTeam));
      if(!conversation)throw new Error('The Team Chat conversation is unavailable.');
      await api('/api/conversations.php?action=send',{method:'POST',data:{conversation:conversation.public_id,body:'Shared a Research bookmark.',client_message_id:crypto.randomUUID(),attachments:[{type:'bookmark',public_id:bookmarkId}]}});
      setStatus('Shared to '+(conversation.team_name||'Team')+'.');
      document.dispatchEvent(new CustomEvent('annotated:team-bookmark-shared',{detail:{bookmark_id:bookmarkId,team_id:targetTeam}}));
    }catch(err){setStatus(err.message||'Unable to share bookmark to Team Chat.',true);}
  }

  for(const b of viewButtons)b.addEventListener('click',()=>setView(String(b.dataset.researchWorkspaceView||'chat')));
  canvas?.querySelector('[data-research-create-folder]')?.addEventListener('click',openFolderDialog);
  canvas?.querySelector('[data-research-create-bookmark]')?.addEventListener('click',openBookmarkDialog);

  document.addEventListener('click',e=>{
    const share=e.target.closest('[data-bookmark-share-team]');if(!share)return;
    const card=share.closest('[data-bookmark-id]');if(!card)return;
    shareBookmarkToTeam(String(card.dataset.bookmarkId||''),String(card.dataset.bookmarkTeam||''));
  });

  window.AnnotatedResearchWorkspace={reload:()=>loadWorkspace(currentView==='trash'),shareBookmarkToTeam};
})();