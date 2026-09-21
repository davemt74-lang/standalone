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
      object_type:['annotation','source','claim','finding'].includes(String(stored.object_type||''))?String(stored.object_type):'',
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
    object_type:['annotation','source','claim','finding'].includes(String(state.object_type||''))?String(state.object_type):'',
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
    next.object_public_id=phase34WorkspaceRef(patch.object_public_id);next.object_type=next.object_public_id&&['annotation','source','claim','finding'].includes(String(patch.object_type||''))?String(patch.object_type):'';
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
