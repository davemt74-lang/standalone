(()=>{
  const body=document.body,user=String(body?.dataset?.workspaceUser||'').trim();
  if(!user)return;

  const KEY='annotated.workspaceContext.v1';
  const objectTypes=new Set(['annotation','source','claim','finding']);
  const ref=value=>{const v=String(value||'').trim();return /^[A-Za-z0-9._:-]{1,96}$/.test(v)?v:'';};
  const base=()=>({version:1,user_public_id:user,team_public_id:'',research_public_id:'',object_type:'',object_public_id:'',agent_conversation_public_id:'',surface:'',updated_at:Date.now()});

  function read(){
    try{
      const value=JSON.parse(sessionStorage.getItem(KEY)||'null');
      if(!value||value.version!==1||String(value.user_public_id||'')!==user)return base();
      return {
        version:1,user_public_id:user,
        team_public_id:ref(value.team_public_id),
        research_public_id:ref(value.research_public_id),
        object_type:objectTypes.has(String(value.object_type||''))?String(value.object_type):'',
        object_public_id:ref(value.object_public_id),
        agent_conversation_public_id:ref(value.agent_conversation_public_id),
        surface:String(value.surface||'').slice(0,32),
        updated_at:Number(value.updated_at)||Date.now()
      };
    }catch{return base();}
  }

  function write(state){
    const clean={
      version:1,user_public_id:user,
      team_public_id:ref(state.team_public_id),
      research_public_id:ref(state.research_public_id),
      object_type:objectTypes.has(String(state.object_type||''))?String(state.object_type):'',
      object_public_id:ref(state.object_public_id),
      agent_conversation_public_id:ref(state.agent_conversation_public_id),
      surface:String(state.surface||'').slice(0,32),
      updated_at:Date.now()
    };
    if(!clean.object_public_id)clean.object_type='';
    try{sessionStorage.setItem(KEY,JSON.stringify(clean));}catch{}
    return clean;
  }

  function handoffPatch(){
    const url=new URL(location.href),p=url.searchParams,patch={};let changed=false;
    const team=ref(p.get('ws_team')),research=ref(p.get('ws_research')),object=ref(p.get('ws_object')),agent=ref(p.get('ws_agent')),objectType=String(p.get('ws_object_type')||'');
    if(team)patch.team_public_id=team;if(research)patch.research_public_id=research;
    if(object&&objectTypes.has(objectType)){patch.object_type=objectType;patch.object_public_id=object;}
    if(agent)patch.agent_conversation_public_id=agent;
    for(const key of ['ws_team','ws_research','ws_object_type','ws_object','ws_agent'])if(p.has(key)){p.delete(key);changed=true;}
    if(changed)history.replaceState(history.state,'',url.pathname+(p.toString()?'?'+p.toString():'')+url.hash);
    return patch;
  }

  function pagePatch(){
    const d=body.dataset,patch={surface:String(d.workspaceSurface||'').slice(0,32)};
    if(ref(d.workspaceTeam))patch.team_public_id=ref(d.workspaceTeam);
    if(ref(d.workspaceResearch))patch.research_public_id=ref(d.workspaceResearch);
    if(objectTypes.has(String(d.workspaceObjectType||''))&&ref(d.workspaceObject)){
      patch.object_type=String(d.workspaceObjectType);patch.object_public_id=ref(d.workspaceObject);
    }
    if(ref(d.workspaceAgent))patch.agent_conversation_public_id=ref(d.workspaceAgent);
    return patch;
  }

  function merge(state,patch={}){
    if(patch.clear_all)return write({...base(),surface:String(patch.surface||state.surface||'').slice(0,32)});
    const next={...state};
    const incomingTeam=ref(patch.team_public_id),incomingResearch=ref(patch.research_public_id);
    if(Object.prototype.hasOwnProperty.call(patch,'team_public_id')){
      if(incomingTeam!==next.team_public_id&&!incomingResearch){next.research_public_id='';next.object_type='';next.object_public_id='';}
      next.team_public_id=incomingTeam;
    }
    if(Object.prototype.hasOwnProperty.call(patch,'research_public_id')){
      if(incomingResearch!==next.research_public_id&&!Object.prototype.hasOwnProperty.call(patch,'object_public_id')){next.object_type='';next.object_public_id='';}
      next.research_public_id=incomingResearch;
    }
    if(Object.prototype.hasOwnProperty.call(patch,'object_public_id')){
      next.object_public_id=ref(patch.object_public_id);next.object_type=next.object_public_id&&objectTypes.has(String(patch.object_type||''))?String(patch.object_type):'';
    }
    if(Object.prototype.hasOwnProperty.call(patch,'agent_conversation_public_id'))next.agent_conversation_public_id=ref(patch.agent_conversation_public_id);
    if(patch.clear_object){next.object_type='';next.object_public_id='';}
    if(patch.clear_agent)next.agent_conversation_public_id='';
    if(patch.surface!==undefined)next.surface=String(patch.surface||'').slice(0,32);
    return write(next);
  }

  async function resolve(state){
    const q=new URLSearchParams();
    if(state.team_public_id)q.set('team',state.team_public_id);
    if(state.research_public_id)q.set('research',state.research_public_id);
    if(state.object_public_id){q.set('object_type',state.object_type);q.set('object',state.object_public_id);}
    if(state.agent_conversation_public_id)q.set('agent',state.agent_conversation_public_id);
    try{
      const response=await fetch('/api/workspace-context.php?'+q.toString(),{credentials:'same-origin',headers:{Accept:'application/json'}});
      const json=await response.json();if(!response.ok||json.ok===false)throw new Error('context');
      return json.data||{};
    }catch{return null;}
  }

  function strip(){
    let node=document.querySelector('[data-workspace-context-strip]');
    if(node)return node;
    node=document.createElement('aside');node.className='workspaceContextStrip';node.dataset.workspaceContextStrip='1';node.hidden=true;
    const anchor=document.querySelector('.topbar');if(anchor?.parentNode)anchor.insertAdjacentElement('afterend',node);else body.prepend(node);
    return node;
  }

  function render(resolved){
    const node=strip();if(!resolved){node.hidden=true;node.replaceChildren();return;}
    const entries=[
      resolved.team&&{kind:'Team',...resolved.team},
      resolved.research&&{kind:'Research',...resolved.research},
      resolved.object&&{kind:resolved.object.label||'Object',...resolved.object},
      resolved.agent&&{kind:'Agent',...resolved.agent},
    ].filter(Boolean);
    node.replaceChildren();
    if(!entries.length){node.hidden=true;return;}
    const lead=document.createElement('span');lead.className='workspaceContextLead';lead.textContent='Working in';node.appendChild(lead);
    for(const item of entries){
      const a=document.createElement('a');a.className='workspaceContextChip';a.href=item.url||'#';
      const kind=document.createElement('small');kind.textContent=item.kind;
      const label=document.createElement('strong');label.textContent=item.label||item.kind;
      a.append(kind,label);node.appendChild(a);
    }
    const clear=document.createElement('button');clear.type='button';clear.className='workspaceContextClear';clear.textContent='Clear context';clear.addEventListener('click',()=>{const state=merge(read(),{clear_all:true,surface:body.dataset.workspaceSurface||''});render({});document.dispatchEvent(new CustomEvent('annotated:workspace-context-cleared',{detail:state}));});node.appendChild(clear);
    node.hidden=false;
  }

  function normalizedFromResolved(state,resolved){
    if(!resolved)return state;
    return write({
      ...state,
      team_public_id:resolved.team?.public_id||'',
      research_public_id:resolved.research?.public_id||'',
      object_type:resolved.object?.type||'',
      object_public_id:resolved.object?.public_id||'',
      agent_conversation_public_id:resolved.agent?.public_id||''
    });
  }

  async function commit(patch={}){
    let state=merge(read(),patch);
    const resolved=await resolve(state);
    if(resolved){state=normalizedFromResolved(state,resolved);render(resolved);}else render(null);
    return state;
  }

  function contextPatchFromAgent(items=[]){
    const patch={surface:'agent'};
    for(const item of items){
      const type=String(item?.type||''),id=ref(item?.public_id);if(!id)continue;
      if(type==='research')patch.research_public_id=id;
      else if(type==='team')patch.team_public_id=id;
      else if(type==='annotation'||type==='source'){patch.object_type=type;patch.object_public_id=id;}
    }
    return patch;
  }

  document.addEventListener('annotated:workspace-context',e=>{commit(e.detail||{});});
  document.addEventListener('annotated:agent-chat-request',e=>{const patch=contextPatchFromAgent(Array.isArray(e.detail?.context)?e.detail.context:[]);commit(patch);});

  window.AnnotatedWorkspaceState={read,commit,clear:()=>commit({clear_all:true,surface:body.dataset.workspaceSurface||''})};

  (async()=>{
    let state=merge(read(),handoffPatch());state=merge(state,pagePatch());
    const resolved=await resolve(state);
    if(resolved){state=normalizedFromResolved(state,resolved);render(resolved);}else render(null);
  })();
})();
