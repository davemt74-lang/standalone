(()=>{
  const panel=document.querySelector('[data-research-agent-panel]'),form=document.querySelector('[data-research-agent-composer]');
  if(!panel||!form)return;
  const project=panel.dataset.project||'',csrf=panel.dataset.csrf||'',messages=panel.querySelector('[data-research-agent-messages]'),input=form.querySelector('textarea'),close=panel.querySelector('[data-research-agent-close]'),newChat=panel.querySelector('[data-research-agent-new]');
  const key='annotated.researchAgent.'+project;let conversation='';let sending=false;
  try{conversation=sessionStorage.getItem(key)||'';}catch{}
  async function request(action,data=null,params={}){
    const url=new URL('/api/agent-chat.php',location.origin);url.searchParams.set('action',action);Object.entries(params).forEach(([k,v])=>{if(v)url.searchParams.set(k,String(v));});
    const options={method:data?'POST':'GET',headers:{Accept:'application/json'}};if(data){options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=csrf;options.body=JSON.stringify(data);}
    const r=await fetch(url,options),j=await r.json().catch(()=>null);if(!r.ok||!j?.ok)throw new Error(j?.error?.message||j?.error?.code||'Agent request failed');return j.data;
  }
  function show(){panel.hidden=false;document.body.classList.add('researchAgentOpen');}
  function hide(){panel.hidden=true;document.body.classList.remove('researchAgentOpen');}
  function capabilityLabel(key){return ({'research.create_task':'Create research task','research.create_note':'Create research note','research.create_claim':'Create claim','research.attach_annotation_evidence':'Attach annotation evidence','research.create_finding':'Create finding','research.link_claims':'Link claims'})[key]||String(key||'Research action');}
  function proposalSummary(p){const a=p.arguments||{},k=p.capability_key||'';if(k==='research.create_task')return a.title||'New research task';if(k==='research.create_note')return String(a.body||'').slice(0,180);if(k==='research.create_claim')return String(a.statement||'').slice(0,180);if(k==='research.attach_annotation_evidence')return (a.relationship||'supports')+' · '+(a.annotation_id||'annotation')+' → '+(a.claim_id||'claim');if(k==='research.create_finding')return a.title||'New finding';if(k==='research.link_claims')return (a.source_claim_id||'claim')+' '+(a.relation_type||'related')+' '+(a.target_claim_id||'claim');return 'Review this Research change.';}
  function updateProposalCard(card,p){const state=String(p.status||'pending');card.dataset.status=state;card.querySelector('[data-proposal-status]').textContent=state.replace(/_/g,' ');const actions=card.querySelector('[data-proposal-actions]');if(actions)actions.hidden=state!=='pending';const result=card.querySelector('[data-proposal-result]');if(result){result.replaceChildren();if(state==='executed'&&p.result?.url){const a=document.createElement('a');a.href=p.result.url;a.textContent='Open '+(p.result.label||p.result.type||'result');result.appendChild(a);}else if(p.error_text)result.textContent=p.error_text;}}
  function renderProposal(p){
    const card=document.createElement('section');card.className='agentActionProposal';card.dataset.proposal=p.public_id||'';
    const head=document.createElement('div');head.className='agentActionProposalHead';const title=document.createElement('strong');title.textContent=capabilityLabel(p.capability_key);const status=document.createElement('span');status.dataset.proposalStatus='1';head.append(title,status);
    const meta=document.createElement('small');meta.textContent=p.project_title?'Research · '+p.project_title:'Research action';const summary=document.createElement('p');summary.textContent=proposalSummary(p);const warning=document.createElement('div');warning.className='agentActionProposalWarning';warning.textContent='No Research data changes until you confirm.';
    const actions=document.createElement('div');actions.className='agentActionProposalActions';actions.dataset.proposalActions='1';const confirm=document.createElement('button');confirm.type='button';confirm.textContent='Confirm & execute';const reject=document.createElement('button');reject.type='button';reject.className='secondary';reject.textContent='Reject';actions.append(confirm,reject);
    const result=document.createElement('div');result.className='agentActionProposalResult';result.dataset.proposalResult='1';card.append(head,meta,summary,warning,actions,result);
    async function mutate(action){confirm.disabled=true;reject.disabled=true;try{const data=await request(action,{proposal_id:p.public_id});p={...p,...data,status:data.status||p.status,result:data.result||p.result};updateProposalCard(card,p);if(p.status==='executed')document.dispatchEvent(new CustomEvent('annotated:research-action-executed',{detail:p}));}catch(e){if(String(e.message||'').includes('changed after')||String(e.message||'').includes('expired')){p.status='stale';p.error_text=e.message;updateProposalCard(card,p);}else alert(e.message||'Unable to update Agent action.');}finally{confirm.disabled=false;reject.disabled=false;}}
    confirm.addEventListener('click',()=>mutate('action_confirm'));reject.addEventListener('click',()=>mutate('action_reject'));updateProposalCard(card,p);return card;
  }
  function add(role,body,proposals=[]){
    const el=document.createElement('article');el.className='researchAgentMessage '+(role==='assistant'?'is-agent':'is-user');
    const head=document.createElement('strong');head.textContent=role==='assistant'?'Annotated Agent':'You';const text=document.createElement('div');text.textContent=body||'';el.append(head,text);
    if(Array.isArray(proposals)&&proposals.length){const list=document.createElement('div');list.className='agentActionProposalList';proposals.forEach(p=>list.appendChild(renderProposal(p)));el.appendChild(list);}
    messages.appendChild(el);messages.scrollTop=messages.scrollHeight;return el;
  }
  async function load(){
    if(!conversation)return;show();messages.innerHTML='<div class="meta">Loading conversation…</div>';
    try{const d=await request('messages',null,{conversation,limit:60});messages.replaceChildren();(d.messages||[]).forEach(x=>add(x.role==='assistant'||x.sender_type==='agent'?'assistant':'user',x.body,x.action_proposals||[]));}catch(e){messages.innerHTML='<div class="error">'+e.message+'</div>';}
  }
  async function send(prompt){
    if(sending||!prompt.trim())return;sending=true;show();add('user',prompt.trim());const thinking=add('assistant','Thinking…');thinking.classList.add('is-thinking');input.value='';resize();
    const client=globalThis.crypto?.randomUUID?.()||Date.now()+'-'+Math.random().toString(16).slice(2);
    try{const d=await request('send',{conversation:conversation||null,prompt:prompt.trim(),context:[{type:'research',public_id:project}],client_message_id:client});conversation=d.conversation?.public_id||conversation;try{sessionStorage.setItem(key,conversation);}catch{}thinking.remove();add('assistant',d.assistant_message?.body||'No response returned.',d.assistant_message?.action_proposals||[]);}
    catch(e){thinking.remove();const err=document.createElement('div');err.className='error';err.textContent=e.message||'Agent request failed';messages.appendChild(err);}
    finally{sending=false;input.focus();}
  }
  function resize(){input.style.height='auto';input.style.height=Math.min(input.scrollHeight,120)+'px';}
  form.addEventListener('submit',e=>{e.preventDefault();send(input.value);});
  input.addEventListener('input',resize);input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}});
  close.addEventListener('click',hide);newChat.addEventListener('click',()=>{conversation='';try{sessionStorage.removeItem(key);}catch{}messages.replaceChildren();show();input.focus();});
  form.querySelector('[data-research-agent-context]')?.addEventListener('click',()=>{show();if(!messages.children.length)add('assistant','This conversation is automatically scoped to the current Research project, including its claims, findings, source risks, gaps, and live workspace intelligence.');});
  document.querySelectorAll('[data-research-agent-prompt]').forEach(b=>b.addEventListener('click',()=>{input.value=b.dataset.researchAgentPrompt||'';resize();show();input.focus();}));
  if(conversation)load();
})();