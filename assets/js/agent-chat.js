(()=>{
  const feed=document.querySelector('[data-home-feed-canvas]');
  const canvas=document.querySelector('[data-agent-chat-canvas]');
  const form=document.querySelector('#homeAgentComposer');
  const input=document.querySelector('#homeAgentPrompt');
  const add=document.querySelector('#homeAgentAdd');
  const rightRail=document.querySelector('.homeRightRail');
  if(!feed||!canvas||!form||!input)return;

  const messages=canvas.querySelector('[data-agent-messages]');
  const title=canvas.querySelector('[data-agent-title]');
  const back=canvas.querySelector('[data-agent-back]');
  const newChat=canvas.querySelector('[data-agent-new]');
  const historyToggle=canvas.querySelector('[data-agent-history-toggle]');
  const historyPanel=canvas.querySelector('[data-agent-history]');
  const historyList=canvas.querySelector('[data-agent-history-list]');
  const historyClose=canvas.querySelector('[data-agent-history-close]');
  const contextTray=canvas.querySelector('[data-agent-context-tray]');
  const contextPicker=canvas.querySelector('[data-agent-context-picker]');
  const contextOptions=canvas.querySelector('[data-agent-context-options]');
  const contextClose=canvas.querySelector('[data-agent-context-close]');
  const csrf=canvas.dataset.csrf||'';
  let activeConversation='';
  let selectedContext=[];
  let feedScroll=0;
  let sending=false;

  const esc=s=>String(s??'');
  function renderInlineError(container,message){container.replaceChildren();const error=document.createElement('div');error.className='error';error.textContent=String(message||'Agent Chat request failed.');container.appendChild(error);}
  async function request(action,{method='GET',data=null,params={}}={}){
    const url=new URL('/api/agent-chat.php',location.origin);url.searchParams.set('action',action);
    Object.entries(params).forEach(([k,v])=>{if(v!==null&&v!==undefined&&v!=='')url.searchParams.set(k,String(v));});
    const options={method,headers:{Accept:'application/json'}};
    if(method!=='GET'){options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=csrf;options.body=JSON.stringify(data||{});}
    const response=await fetch(url,options);const json=await response.json().catch(()=>({ok:false,error:{message:'Invalid server response'}}));
    if(!response.ok||json.ok===false)throw new Error(json.error?.message||json.error?.code||'Agent Chat request failed.');
    return json.data||{};
  }
  function sizeInput(){input.style.height='auto';input.style.height=Math.min(input.scrollHeight,132)+'px';}
  function saveState(open){try{sessionStorage.setItem('annotated.agentCanvasOpen',open?'1':'0');sessionStorage.setItem('annotated.agentConversation',activeConversation||'');sessionStorage.setItem('annotated.feedScroll',String(feedScroll||0));}catch{}}
  function setModeAgent(){
    if(!canvas.hidden)return;
    feedScroll=window.scrollY||0;feed.hidden=true;canvas.hidden=false;if(rightRail)rightRail.hidden=true;document.body.classList.add('agentChatMode');input.placeholder='Message Annotated Agent…';saveState(true);window.scrollTo({top:0,behavior:'instant'});
  }
  function setModeFeed(){
    canvas.hidden=true;feed.hidden=false;if(rightRail)rightRail.hidden=false;document.body.classList.remove('agentChatMode');input.placeholder='Ask Annotated…';saveState(false);const url=new URL(location.href);if(url.searchParams.has('agent')){url.searchParams.delete('agent');history.replaceState({},'',url.pathname+(url.searchParams.toString()?'?'+url.searchParams.toString():'')+url.hash);}requestAnimationFrame(()=>window.scrollTo({top:feedScroll||0,behavior:'instant'}));document.dispatchEvent(new CustomEvent('annotated:agent-chat-feed-restored'));
  }
  function clearWelcome(){messages.querySelector('.agentChatWelcome')?.remove();}
  function attachmentUrl(a){
    const id=encodeURIComponent(String(a?.public_id||''));if(!id)return '';
    return ({annotation:'/annotation.php?id=',research:'/research-project.php?id=',research_project:'/research-project.php?id=',source:'/source.php?id=',team:'/team.php?id=',claim:'/research-claim.php?id=',finding:'/research-finding.php?id='})[String(a?.type||'')]?.concat(id)||'';
  }
  function renderAttachment(a){
    const href=attachmentUrl(a),chip=document.createElement(href?'a':'span');chip.className='agentContextChip';chip.textContent=(a.metadata?.label||a.label||a.public_id||a.type);
    if(href){chip.href=href;chip.title='Open '+String(a.type||'Annotated context').replace(/_/g,' ');}
    return chip;
  }
  function capabilityLabel(key){
    return ({'research.create_task':'Create research task','research.create_note':'Create research note','research.create_claim':'Create claim','research.attach_annotation_evidence':'Attach annotation evidence','research.create_finding':'Create finding','research.link_claims':'Link claims'})[key]||String(key||'Research action');
  }
  function proposalSummary(p){
    const a=p.arguments||{},key=p.capability_key||'';
    if(key==='research.create_task')return a.title||'New research task';
    if(key==='research.create_note')return String(a.body||'').slice(0,180);
    if(key==='research.create_claim')return String(a.statement||'').slice(0,180);
    if(key==='research.attach_annotation_evidence')return (a.relationship||'supports')+' · '+(a.annotation_id||'annotation')+' → '+(a.claim_id||'claim');
    if(key==='research.create_finding')return a.title||'New finding';
    if(key==='research.link_claims')return (a.source_claim_id||'claim')+' '+(a.relation_type||'related')+' '+(a.target_claim_id||'claim');
    return 'Review this proposed Research change.';
  }
  function updateProposalCard(card,p){
    const status=String(p.status||'pending');card.dataset.status=status;card.querySelector('[data-proposal-status]').textContent=status.replace(/_/g,' ');
    const actions=card.querySelector('[data-proposal-actions]');if(actions)actions.hidden=status!=='pending';
    const result=card.querySelector('[data-proposal-result]');
    if(result){result.replaceChildren();if(status==='executed'&&p.result?.url){const a=document.createElement('a');a.href=p.result.url;a.textContent='Open '+(p.result.label||p.result.type||'result');result.appendChild(a);}else if(p.error_text){result.textContent=p.error_text;}}
  }
  function renderProposal(p){
    const card=document.createElement('section');card.className='agentActionProposal';card.dataset.proposal=p.public_id||'';
    const top=document.createElement('div');top.className='agentActionProposalHead';
    const title=document.createElement('strong');title.textContent=capabilityLabel(p.capability_key);
    const status=document.createElement('span');status.dataset.proposalStatus='1';top.append(title,status);
    const project=document.createElement('small');project.textContent=p.project_title?'Research · '+p.project_title:'Research action';
    const summary=document.createElement('p');summary.textContent=proposalSummary(p);
    const warning=document.createElement('div');warning.className='agentActionProposalWarning';warning.textContent='This changes Research only after you confirm.';
    const details=document.createElement('details');details.className='agentActionProposalDetails';const detailsSummary=document.createElement('summary');detailsSummary.textContent='Review exact details';const dl=document.createElement('dl');Object.entries(p.arguments||{}).forEach(([k,v])=>{const dt=document.createElement('dt');dt.textContent=k.replace(/_/g,' ');const dd=document.createElement('dd');dd.textContent=Array.isArray(v)?v.join(', '):String(v??'');dl.append(dt,dd);});details.append(detailsSummary,dl);
    const provenance=document.createElement('small');provenance.className='agentActionProposalProvenance';const refCount=Array.isArray(p.provenance?.refs)?p.provenance.refs.length:0;provenance.textContent='Provenance: '+refCount+' attached Annotated reference'+(refCount===1?'':'s')+'.';
    const actions=document.createElement('div');actions.className='agentActionProposalActions';actions.dataset.proposalActions='1';
    const confirm=document.createElement('button');confirm.type='button';confirm.textContent='Confirm & execute';
    const reject=document.createElement('button');reject.type='button';reject.className='secondary';reject.textContent='Reject';
    actions.append(confirm,reject);
    const result=document.createElement('div');result.className='agentActionProposalResult';result.dataset.proposalResult='1';
    card.append(top,project,summary,warning,details,provenance,actions,result);
    async function mutate(action){
      confirm.disabled=true;reject.disabled=true;
      try{
        const data=await request(action,{method:'POST',data:{proposal_id:p.public_id}});
        p={...p,...data,status:data.status||p.status,result:data.result||p.result};updateProposalCard(card,p);if(p.status==='executed')document.dispatchEvent(new CustomEvent('annotated:research-action-executed',{detail:p}));
      }catch(err){
        if(String(err.message||'').includes('changed after')||String(err.message||'').includes('expired')){p.status='stale';p.error_text=err.message;updateProposalCard(card,p);}
        else alert(err.message||'Unable to update Agent action.');
      }finally{confirm.disabled=false;reject.disabled=false;}
    }
    confirm.addEventListener('click',()=>mutate('action_confirm'));reject.addEventListener('click',()=>mutate('action_reject'));updateProposalCard(card,p);return card;
  }
  function renderMessage(row){
    clearWelcome();
    const article=document.createElement('article');article.className='agentChatMessage '+(row.role==='assistant'||row.sender_type==='agent'?'is-agent':'is-user');
    const head=document.createElement('div');head.className='agentChatMessageHead';head.textContent=row.role==='assistant'||row.sender_type==='agent'?'Annotated Agent':'You';
    const body=document.createElement('div');body.className='agentChatMessageBody';body.textContent=row.body||'';
    article.append(head,body);
    if(row.attribution&&Number(row.attribution.attribution_count||0)>0){const lineage=document.createElement('div');lineage.className='agentChatMessageContext';const refs=Number(row.attribution.attribution_count||0),contributors=Number(row.attribution.contributor_count||0);const info=document.createElement('span');info.className='agentContextChip';info.textContent=refs+' Annotated reference'+(refs===1?'':'s')+' · '+contributors+' contributor'+(contributors===1?'':'s');lineage.appendChild(info);if(row.attribution.run_id){const link=document.createElement('a');link.className='agentContextChip';link.href='/data-attribution.php?run_id='+encodeURIComponent(row.attribution.run_id);link.textContent='View lineage';lineage.appendChild(link);}article.appendChild(lineage);}
    if(Array.isArray(row.attachments)&&row.attachments.length){const wrap=document.createElement('div');wrap.className='agentChatMessageContext';row.attachments.forEach(a=>wrap.appendChild(renderAttachment(a)));article.appendChild(wrap);}
    if(Array.isArray(row.action_proposals)&&row.action_proposals.length){const proposals=document.createElement('div');proposals.className='agentActionProposalList';row.action_proposals.forEach(p=>proposals.appendChild(renderProposal(p)));article.appendChild(proposals);}
    messages.appendChild(article);return article;
  }
  function showThinking(){
    clearWelcome();const el=document.createElement('article');el.className='agentChatMessage is-agent is-thinking';el.dataset.agentThinking='1';el.innerHTML='<div class="agentChatMessageHead">Annotated Agent</div><div class="agentChatThinking">Thinking…</div>';messages.appendChild(el);messages.scrollTop=messages.scrollHeight;return el;
  }
  function renderContextTray(){
    contextTray.replaceChildren();
    selectedContext.forEach((item,index)=>{const chip=document.createElement('span');chip.className='agentContextChip';chip.textContent=item.label;const x=document.createElement('button');x.type='button';x.textContent='×';x.setAttribute('aria-label','Remove context');x.addEventListener('click',()=>{selectedContext.splice(index,1);renderContextTray();});chip.appendChild(x);contextTray.appendChild(chip);});
    contextTray.hidden=!selectedContext.length;
  }
  async function loadContextOptions(){
    contextOptions.innerHTML='<div class="meta">Loading context…</div>';
    try{
      const data=await request('context_options');contextOptions.replaceChildren();
      const groups=[['annotations','Recent annotations'],['research','Research projects'],['teams','Teams']];
      groups.forEach(([key,label])=>{const rows=data[key]||[];if(!rows.length)return;const section=document.createElement('section');const h=document.createElement('h4');h.textContent=label;section.appendChild(h);rows.forEach(item=>{const b=document.createElement('button');b.type='button';b.className='agentContextOption';b.textContent=item.label;b.addEventListener('click',()=>{if(!selectedContext.some(x=>x.type===item.type&&x.public_id===item.public_id)&&selectedContext.length<6)selectedContext.push(item);renderContextTray();contextPicker.hidden=true;});section.appendChild(b);});contextOptions.appendChild(section);});
      if(!contextOptions.children.length)contextOptions.innerHTML='<div class="meta">No recent Annotated context is available yet.</div>';
    }catch(err){renderInlineError(contextOptions,err.message||'Unable to load Annotated context.');}
  }
  async function loadHistory(){
    historyList.innerHTML='<div class="meta">Loading chats…</div>';
    try{
      const data=await request('list');historyList.replaceChildren();
      (data.conversations||[]).forEach(chat=>{const b=document.createElement('button');b.type='button';b.className='agentHistoryItem';const strong=document.createElement('strong');strong.textContent=chat.title||'New Research';const small=document.createElement('small');small.textContent=chat.last_message||'';b.append(strong,small);b.addEventListener('click',()=>{historyPanel.hidden=true;openConversation(chat.public_id,chat.title);});historyList.appendChild(b);});
      if(!historyList.children.length)historyList.innerHTML='<div class="meta">No previous Research sessions yet.</div>';
    }catch(err){renderInlineError(historyList,err.message||'Unable to load Agent Chat history.');}
  }
  async function openConversation(publicId,chatTitle=''){
    setModeAgent();activeConversation=publicId;document.dispatchEvent(new CustomEvent('annotated:workspace-context',{detail:{agent_conversation_public_id:activeConversation,surface:'agent'}}));title.textContent=chatTitle||'Agent chat';messages.innerHTML='<div class="agentChatLoading">Loading conversation…</div>';
    try{
      const data=await request('messages',{params:{conversation:publicId,limit:80}});messages.replaceChildren();(data.messages||[]).forEach(renderMessage);if(!(data.messages||[]).length)messages.innerHTML='<div class="agentChatWelcome"><span class="eyebrow">ANNOTATED AGENT</span><h2>New Research</h2><p>Ask a question or attach Annotated context.</p></div>';saveState(true);messages.scrollTop=messages.scrollHeight;
    }catch(err){renderInlineError(messages,err.message||'Unable to load this Agent Chat.');}
  }
  function resetConversation(){
    activeConversation='';document.dispatchEvent(new CustomEvent('annotated:workspace-context',{detail:{clear_agent:true,surface:'agent'}}));selectedContext=[];renderContextTray();title.textContent='New Research';messages.innerHTML='<div class="agentChatWelcome"><span class="eyebrow">ANNOTATED AGENT</span><h2>What are you researching?</h2><p>Ask about your annotations, sources, Research projects, or Team context. Attached context is permission-checked before the Agent can use it.</p></div>';saveState(true);input.focus();
  }
  async function sendPrompt(prompt){
    if(sending||!prompt.trim())return;sending=true;setModeAgent();const text=prompt.trim();renderMessage({role:'user',body:text,attachments:selectedContext.map(x=>({metadata:{label:x.label},public_id:x.public_id,type:x.type}))});input.value='';sizeInput();const thinking=showThinking();
    const client=globalThis.crypto?.randomUUID?.()||String(Date.now())+'-'+Math.random().toString(16).slice(2);
    try{
      const data=await request('send',{method:'POST',data:{conversation:activeConversation||null,prompt:text,context:selectedContext.map(({type,public_id})=>({type,public_id})),client_message_id:client}});
      activeConversation=data.conversation?.public_id||activeConversation;if(activeConversation)document.dispatchEvent(new CustomEvent('annotated:workspace-context',{detail:{agent_conversation_public_id:activeConversation,surface:'agent'}}));title.textContent=data.conversation?.title||title.textContent;thinking.remove();renderMessage(data.assistant_message||{role:'assistant',body:'No response returned.'});selectedContext=[];renderContextTray();saveState(true);messages.scrollTop=messages.scrollHeight;loadHistory();
    }catch(err){thinking.remove();const error=document.createElement('div');error.className='error agentChatError';error.textContent=err.message||'Agent Chat failed.';messages.appendChild(error);}
    finally{sending=false;input.focus();}
  }

  form.addEventListener('submit',e=>{e.preventDefault();const prompt=input.value.trim();if(!prompt)return;if(canvas.hidden){window.ANNOTATED_PENDING_AGENT_PROMPT=prompt;try{sessionStorage.setItem('annotated.pendingAgentPrompt',prompt);}catch{}document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:{prompt,source:'home_feed'},bubbles:true,cancelable:true}));}else sendPrompt(prompt);});
  document.addEventListener('annotated:agent-chat-request',e=>{const prompt=String(e.detail?.prompt||'').trim();const supplied=Array.isArray(e.detail?.context)?e.detail.context:[];if(supplied.length){selectedContext=supplied.slice(0,6).filter(x=>x&&x.type&&x.public_id).map(x=>({type:String(x.type),public_id:String(x.public_id),label:String(x.label||x.type)}));renderContextTray();}setModeAgent();if(prompt)sendPrompt(prompt);});
  document.addEventListener('annotated:agent-chat-add-context',()=>{setModeAgent();contextTray.hidden=true;contextPicker.hidden=false;loadContextOptions();});
  add.addEventListener('click',()=>document.dispatchEvent(new CustomEvent('annotated:agent-chat-add-context',{bubbles:true})));
  input.addEventListener('input',sizeInput);input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}});
  back.addEventListener('click',setModeFeed);newChat.addEventListener('click',resetConversation);
  historyToggle.addEventListener('click',()=>{historyPanel.hidden=!historyPanel.hidden;if(!historyPanel.hidden)loadHistory();});historyClose.addEventListener('click',()=>historyPanel.hidden=true);
  contextClose.addEventListener('click',()=>{contextPicker.hidden=true;renderContextTray();});

  try{
    feedScroll=Number(sessionStorage.getItem('annotated.feedScroll')||0)||0;
    const restoreOpen=sessionStorage.getItem('annotated.agentCanvasOpen')==='1',restoreConversation=sessionStorage.getItem('annotated.agentConversation')||'',pending=sessionStorage.getItem('annotated.pendingAgentPrompt')||'',pendingHandoff=sessionStorage.getItem('annotated.pendingAgentHandoff')||'',requestedConversation=new URLSearchParams(location.search).get('agent')||'';
    if(requestedConversation){setModeAgent();openConversation(requestedConversation);}
    else if(restoreOpen){setModeAgent();if(restoreConversation)openConversation(restoreConversation);}
    if(pendingHandoff){sessionStorage.removeItem('annotated.pendingAgentHandoff');try{const handoff=JSON.parse(pendingHandoff);const supplied=Array.isArray(handoff?.context)?handoff.context:[];selectedContext=supplied.slice(0,6).filter(x=>x&&x.type&&x.public_id).map(x=>({type:String(x.type),public_id:String(x.public_id),label:String(x.label||x.type)}));renderContextTray();setModeAgent();if(String(handoff?.prompt||'').trim())sendPrompt(String(handoff.prompt));}catch{}}
    else if(pending){sessionStorage.removeItem('annotated.pendingAgentPrompt');setModeAgent();sendPrompt(pending);}
  }catch{}
})();