const PHASE34_WORKSPACE_KEY='annotatedWorkspaceContextV1';

function phase34WorkspaceUser(){return String(accountUser?.public_id||'').trim();}
function phase34WorkspaceRef(value){const v=String(value||'').trim();return /^[A-Za-z0-9._:-]{1,96}$/.test(v)?v:'';}
function phase34WorkspaceBase(){return {version:1,user_public_id:phase34WorkspaceUser(),team_public_id:'',research_public_id:'',object_type:'',object_public_id:'',agent_conversation_public_id:'',surface:'browser',updated_at:Date.now()};}

async function phase34WorkspaceRead(){
  const user=phase34WorkspaceUser();if(!user)return phase34WorkspaceBase();
  try{
    const stored=(await chrome.storage.session.get({[PHASE34_WORKSPACE_KEY]:null}))[PHASE34_WORKSPACE_KEY];
    if(!stored||stored.version!==1||String(stored.user_public_id||'')!==user)return phase34WorkspaceBase();
    return {
      version:1,user_public_id:user,
      team_public_id:phase34WorkspaceRef(stored.team_public_id),
      research_public_id:phase34WorkspaceRef(stored.research_public_id),
      object_type:['annotation','source','claim','finding','bookmark'].includes(String(stored.object_type||''))?String(stored.object_type):'',
      object_public_id:phase34WorkspaceRef(stored.object_public_id),
      agent_conversation_public_id:phase34WorkspaceRef(stored.agent_conversation_public_id),
      surface:String(stored.surface||'browser').slice(0,32),updated_at:Number(stored.updated_at)||Date.now()
    };
  }catch{return phase34WorkspaceBase();}
}

async function phase34WorkspaceWrite(state){
  const clean={
    version:1,user_public_id:phase34WorkspaceUser(),
    team_public_id:phase34WorkspaceRef(state.team_public_id),
    research_public_id:phase34WorkspaceRef(state.research_public_id),
    object_type:['annotation','source','claim','finding','bookmark'].includes(String(state.object_type||''))?String(state.object_type):'',
    object_public_id:phase34WorkspaceRef(state.object_public_id),
    agent_conversation_public_id:phase34WorkspaceRef(state.agent_conversation_public_id),
    surface:String(state.surface||'browser').slice(0,32),updated_at:Date.now()
  };
  if(!clean.object_public_id)clean.object_type='';
  await chrome.storage.session.set({[PHASE34_WORKSPACE_KEY]:clean});return clean;
}

function phase34WorkspaceMerge(state,patch={}){
  const next={...state},team=phase34WorkspaceRef(patch.team_public_id),research=phase34WorkspaceRef(patch.research_public_id);
  if(patch.clear_all)return {...phase34WorkspaceBase(),surface:String(patch.surface||'browser').slice(0,32)};
  if(Object.prototype.hasOwnProperty.call(patch,'team_public_id')){
    if(team!==next.team_public_id&&!research){next.research_public_id='';next.object_type='';next.object_public_id='';}
    next.team_public_id=team;
  }
  if(Object.prototype.hasOwnProperty.call(patch,'research_public_id')){
    if(research!==next.research_public_id&&!Object.prototype.hasOwnProperty.call(patch,'object_public_id')){next.object_type='';next.object_public_id='';}
    next.research_public_id=research;
  }
  if(Object.prototype.hasOwnProperty.call(patch,'object_public_id')){
    next.object_public_id=phase34WorkspaceRef(patch.object_public_id);next.object_type=next.object_public_id&&['annotation','source','claim','finding','bookmark'].includes(String(patch.object_type||''))?String(patch.object_type):'';
  }
  if(Object.prototype.hasOwnProperty.call(patch,'agent_conversation_public_id'))next.agent_conversation_public_id=phase34WorkspaceRef(patch.agent_conversation_public_id);
  if(patch.clear_object){next.object_type='';next.object_public_id='';}
  if(patch.clear_agent)next.agent_conversation_public_id='';
  if(patch.surface!==undefined)next.surface=String(patch.surface||'browser').slice(0,32);
  return next;
}

async function phase34WorkspaceResolve(state){
  if(!token||!phase34WorkspaceUser())return null;
  const q=new URLSearchParams();
  if(state.team_public_id)q.set('team',state.team_public_id);
  if(state.research_public_id)q.set('research',state.research_public_id);
  if(state.object_public_id){q.set('object_type',state.object_type);q.set('object',state.object_public_id);}
  if(state.agent_conversation_public_id)q.set('agent',state.agent_conversation_public_id);
  try{return (await api('/api/workspace-context.php?'+q.toString())).data||{};}catch{return null;}
}

function phase34WorkspaceRender(resolved){
  const host=$('#workspaceContextMini'),chips=$('#workspaceContextMiniChips');if(!host||!chips)return;
  chips.replaceChildren();const rows=[
    resolved?.team&&{kind:'Team',...resolved.team},
    resolved?.research&&{kind:'Research',...resolved.research},
    resolved?.object&&{kind:resolved.object.label||'Object',...resolved.object},
    resolved?.agent&&{kind:'Agent',...resolved.agent}
  ].filter(Boolean);
  for(const item of rows){
    const b=document.createElement('button');b.type='button';b.className='workspaceMiniChip';b.dataset.workspaceUrl=item.url||'';
    const small=document.createElement('small');small.textContent=item.kind;const strong=document.createElement('strong');strong.textContent=item.label||item.kind;b.append(small,strong);chips.appendChild(b);
  }
  host.hidden=!rows.length;
}

async function phase34WorkspaceCommit(patch={}){
  let state=phase34WorkspaceMerge(await phase34WorkspaceRead(),patch);state=await phase34WorkspaceWrite(state);
  const resolved=await phase34WorkspaceResolve(state);
  if(resolved){
    state=await phase34WorkspaceWrite({...state,
      team_public_id:resolved.team?.public_id||'',research_public_id:resolved.research?.public_id||'',
      object_type:resolved.object?.type||'',object_public_id:resolved.object?.public_id||'',
      agent_conversation_public_id:resolved.agent?.public_id||''
    });
    phase34WorkspaceRender(resolved);
  }else phase34WorkspaceRender({});
  return state;
}

async function phase34WorkspaceInit(){
  if(!token||!phase34WorkspaceUser()){phase34WorkspaceRender({});return;}
  await phase34WorkspaceCommit({surface:'browser'});
}

async function phase34WorkspaceClear(){
  await chrome.storage.session.remove(PHASE34_WORKSPACE_KEY);phase34WorkspaceRender({});
}

async function phase34WorkspaceWebsiteUrl(path,patch={}){
  const state=phase34WorkspaceMerge(await phase34WorkspaceRead(),patch),url=new URL(API_BASE+path);
  if(state.team_public_id)url.searchParams.set('ws_team',state.team_public_id);
  if(state.research_public_id)url.searchParams.set('ws_research',state.research_public_id);
  if(state.object_public_id){url.searchParams.set('ws_object_type',state.object_type);url.searchParams.set('ws_object',state.object_public_id);}
  if(state.agent_conversation_public_id)url.searchParams.set('ws_agent',state.agent_conversation_public_id);
  return url.toString();
}

async function phase34WorkspaceOpen(path,patch={}){
  await phase34WorkspaceCommit(patch);chrome.tabs.create({url:await phase34WorkspaceWebsiteUrl(path,patch)});
}


/* Phase 50A — Research Agent website bookmarks */
let phase50BookmarkAgents=[],phase50BookmarkPage=null;

async function phase50ResearchAgents(){
  const j=await api('/api/research-agents.php?action=list');
  phase50BookmarkAgents=(j.data?.agents||[]).filter(a=>String(a.status||'active')!=='archived');
  return phase50BookmarkAgents;
}

function phase50FolderLabel(row,map){
  const parts=[];let current=row,guard=0;
  while(current&&guard++<40){parts.unshift(String(current.title||'Folder'));current=map.get(String(current.parent_public_id||''));}
  return parts.join(' / ');
}

async function phase50LoadBookmarkFolders(agentPublic){
  const select=$('#bookmarkFolder');if(!select)return;
  select.innerHTML='<option value="">All files</option>';
  if(!agentPublic)return;
  try{
    const j=await api('/api/research-workspace-objects.php?action=list&agent_id='+encodeURIComponent(agentPublic));
    const folders=(j.data?.items||[]).filter(x=>x.object_type==='folder'&&x.status==='active'),map=new Map(folders.map(x=>[String(x.public_id),x]));
    folders.sort((a,b)=>phase50FolderLabel(a,map).localeCompare(phase50FolderLabel(b,map)));
    for(const row of folders){const option=document.createElement('option');option.value=String(row.public_id);option.textContent=phase50FolderLabel(row,map);select.appendChild(option);}
  }catch(e){$('#bookmarkStatus').textContent=e.message||'Unable to load Research Agent folders.';}
}

async function phase50OpenBookmark(){
  if(!token){await connect();if(!token)return;}
  const dialog=$('#bookmarkDialog'),agentSelect=$('#bookmarkAgent'),status=$('#bookmarkStatus');
  if(!dialog||!agentSelect)return;
  status.textContent='Loading Research Agents…';
  try{
    phase50BookmarkPage=await readPage(false);
    const url=String(phase50BookmarkPage?.canonicalUrl||phase50BookmarkPage?.url||'');
    if(!/^https?:\/\//i.test(url))throw new Error('This page cannot be saved as a website bookmark.');
    const agents=await phase50ResearchAgents();agentSelect.replaceChildren();
    if(!agents.length)throw new Error('Create a Research Agent before saving website bookmarks.');
    for(const agent of agents){
      const option=document.createElement('option');option.value=String(agent.public_id);option.textContent=(agent.team_name?'Team · ':'')+String(agent.name||'Research Agent');option.dataset.project=String(agent.project_public_id||'');option.dataset.team=String(agent.team_public_id||'');option.dataset.conversation=String(agent.conversation_public_id||'');agentSelect.appendChild(option);
    }
    const state=await phase34WorkspaceRead(),preferred=agents.find(a=>String(a.project_public_id||'')===String(state.research_public_id||''))||agents.find(a=>a.is_default)||agents[0];
    if(preferred)agentSelect.value=String(preferred.public_id);
    $('#bookmarkTitle').value=String(phase50BookmarkPage?.title||'').slice(0,240);
    $('#bookmarkNotes').value='';
    await phase50LoadBookmarkFolders(agentSelect.value);
    status.textContent=String(phase50BookmarkPage?.canonicalUrl||phase50BookmarkPage?.url||'');
    if(!dialog.open)dialog.showModal();
  }catch(e){status.textContent=e.message||'Unable to prepare bookmark.';if(!dialog.open)dialog.showModal();}
}

async function phase50SaveBookmark(event){
  event?.preventDefault();
  const dialog=$('#bookmarkDialog'),agentPublic=String($('#bookmarkAgent')?.value||''),status=$('#bookmarkStatus'),button=$('#confirmBookmark');
  const agent=phase50BookmarkAgents.find(a=>String(a.public_id)===agentPublic);
  if(!agent||!phase50BookmarkPage){status.textContent='Choose a Research Agent.';return;}
  button.disabled=true;status.textContent='Saving bookmark…';
  try{
    const payload={
      agent_id:agentPublic,
      parent_id:String($('#bookmarkFolder')?.value||''),
      url:String(phase50BookmarkPage.canonicalUrl||phase50BookmarkPage.url||''),
      title:String($('#bookmarkTitle')?.value||phase50BookmarkPage.title||'').slice(0,240),
      description:String($('#bookmarkNotes')?.value||'').slice(0,5000),
      favicon_url:String(phase50BookmarkPage.faviconUrl||''),
      preview_image_url:String(phase50BookmarkPage.previewImageUrl||'')
    };
    const j=await api('/api/research-workspace-objects.php?action=create_bookmark',{method:'POST',body:JSON.stringify(payload)}),item=j.data?.item;
    if(!item?.public_id)throw new Error('Bookmark was saved but the workspace did not return its ID.');
    await phase34WorkspaceCommit({
      team_public_id:String(agent.team_public_id||''),
      research_public_id:String(agent.project_public_id||''),
      object_type:'bookmark',
      object_public_id:String(item.public_id),
      agent_conversation_public_id:String(agent.conversation_public_id||''),
      surface:'browser'
    });
    status.textContent='Saved to '+String(agent.name||'Research Agent')+'.';
    setTimeout(()=>{if(dialog.open)dialog.close();},350);
  }catch(e){status.textContent=e.message||'Unable to save bookmark.';}
  finally{button.disabled=false;}
}
