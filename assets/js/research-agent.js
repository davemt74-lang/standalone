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
  function add(role,body){
    const el=document.createElement('article');el.className='researchAgentMessage '+(role==='assistant'?'is-agent':'is-user');
    const head=document.createElement('strong');head.textContent=role==='assistant'?'Annotated Agent':'You';const text=document.createElement('div');text.textContent=body||'';el.append(head,text);messages.appendChild(el);messages.scrollTop=messages.scrollHeight;return el;
  }
  async function load(){
    if(!conversation)return;show();messages.innerHTML='<div class="meta">Loading conversation…</div>';
    try{const d=await request('messages',null,{conversation,limit:60});messages.replaceChildren();(d.messages||[]).forEach(x=>add(x.role==='assistant'||x.sender_type==='agent'?'assistant':'user',x.body));}catch(e){messages.innerHTML='<div class="error">'+e.message+'</div>';}
  }
  async function send(prompt){
    if(sending||!prompt.trim())return;sending=true;show();add('user',prompt.trim());const thinking=add('assistant','Thinking…');thinking.classList.add('is-thinking');input.value='';resize();
    const client=globalThis.crypto?.randomUUID?.()||Date.now()+'-'+Math.random().toString(16).slice(2);
    try{const d=await request('send',{conversation:conversation||null,prompt:prompt.trim(),context:[{type:'research',public_id:project}],client_message_id:client});conversation=d.conversation?.public_id||conversation;try{sessionStorage.setItem(key,conversation);}catch{}thinking.remove();add('assistant',d.assistant_message?.body||'No response returned.');}
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