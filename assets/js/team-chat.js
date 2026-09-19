(()=>{
  const rail=document.querySelector('[data-team-chat-rail]');
  if(!rail)return;
  const select=rail.querySelector('#teamChatConversation');
  const messages=rail.querySelector('#teamChatMessages');
  const composer=rail.querySelector('#teamChatComposer');
  const input=rail.querySelector('#teamChatInput');
  const memberCount=rail.querySelector('#teamChatMemberCount');
  const unread=rail.querySelector('#teamChatUnread');
  const openTeam=rail.querySelector('#teamChatOpenTeam');
  const reply=rail.querySelector('#teamChatReply');
  const loadEarlier=rail.querySelector('#teamChatLoadEarlier');
  const mobileOpen=document.querySelector('[data-team-chat-open]');
  const mobileClose=rail.querySelector('[data-team-chat-close]');
  const totalUnread=document.querySelector('[data-team-chat-total-unread]');
  const csrf=rail.dataset.csrf||'';
  let parentMessage='',parentLabel='',pollTimer=null,loadSequence=0,nextBefore=null,historyExpanded=false;

  function currentOption(){return select?.selectedOptions?.[0]||null;}
  function absoluteProfile(username){return '/'+encodeURIComponent(String(username||''));}
  function formatTime(value){const raw=String(value||'').trim();if(!raw)return '';const normalized=/Z$|[+-]\d\d:\d\d$/.test(raw)?raw:raw.replace(' ','T')+'Z';const d=new Date(normalized);if(Number.isNaN(d.getTime()))return raw;return new Intl.DateTimeFormat(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(d);}
  function avatar(row,size='32'){
    const wrap=document.createElement('span');wrap.className='teamChatAvatar';wrap.style.setProperty('--team-chat-avatar-size',size+'px');
    if(row.profile_image_url){
      const img=document.createElement('img');img.src=row.profile_image_url;img.alt=row.display_name||row.username||'Profile photo';wrap.appendChild(img);
    }else wrap.textContent=String(row.display_name||row.username||'A').trim().charAt(0).toUpperCase()||'A';
    return wrap;
  }
  async function request(action,{method='GET',data=null,params={}}={}){
    const url=new URL('/api/conversations.php',location.origin);url.searchParams.set('action',action);
    Object.entries(params).forEach(([k,v])=>{if(v!==null&&v!==undefined&&v!=='')url.searchParams.set(k,String(v));});
    const options={method,headers:{'Accept':'application/json'}};
    if(method!=='GET'){options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=csrf;options.body=JSON.stringify(data||{});}
    const response=await fetch(url,options);const json=await response.json().catch(()=>({ok:false,error:{message:'Invalid server response'}}));
    if(!response.ok||json.ok===false)throw new Error(json.error?.message||json.error?.code||'Team Chat request failed.');
    return json.data||{};
  }
  function setReply(publicId,label){
    parentMessage=publicId||'';parentLabel=label||'';
    if(!reply)return;
    reply.hidden=!parentMessage;
    const span=reply.querySelector('span');if(span)span.textContent=parentMessage?'Replying to '+parentLabel:'';
  }
  function renderMessage(row){
    const article=document.createElement('article');article.className='teamChatMessage'+(row.is_self?' is-self':'');article.dataset.message=row.public_id;
    const head=document.createElement('div');head.className='teamChatMessageHead';
    const identity=document.createElement('a');identity.className='teamChatIdentity';identity.href=absoluteProfile(row.username);
    identity.appendChild(avatar(row));
    const names=document.createElement('span');const strong=document.createElement('strong');strong.textContent=row.display_name||row.username||'Annotated user';
    const meta=document.createElement('small');meta.textContent='@'+(row.username||'user')+' · '+formatTime(row.created_at);
    names.append(strong,meta);identity.appendChild(names);head.appendChild(identity);
    const replyButton=document.createElement('button');replyButton.type='button';replyButton.className='teamChatReplyButton';replyButton.textContent='Reply';replyButton.addEventListener('click',()=>{setReply(row.public_id,row.display_name||row.username||'message');input?.focus();});head.appendChild(replyButton);
    article.appendChild(head);
    if(row.parent_public_id){
      const quote=document.createElement('div');quote.className='teamChatParent';
      const who=document.createElement('strong');who.textContent=row.parent_display_name||'Reply';
      const body=document.createElement('span');body.textContent=String(row.parent_body||'').slice(0,180);
      quote.append(who,body);article.appendChild(quote);
    }
    const body=document.createElement('div');body.className='teamChatBody';body.textContent=row.body||'';article.appendChild(body);
    return article;
  }
  async function markRead(conversation,last){
    if(!conversation||!last?.public_id)return;
    try{await request('read',{method:'POST',data:{conversation,message:last.public_id}});setConversationUnread(conversation,0);}catch{}
  }
  function setConversationUnread(conversation,count){
    const option=[...(select?.options||[])].find(o=>o.value===conversation);if(option){option.dataset.unread=String(count);const base=option.textContent.replace(/ · \d+ new$/,'');option.textContent=base+(count?' · '+count+' new':'');}
    if(option&&option===currentOption()&&unread){unread.hidden=!count;unread.textContent=count?count+' unread':'';}
    updateTotalUnread();
  }
  function updateTotalUnread(){
    const total=[...(select?.options||[])].reduce((sum,o)=>sum+(Number(o.dataset.unread)||0),0);
    if(totalUnread)totalUnread.textContent=total?'('+total+')':'';
  }
  function syncTeamMeta(){
    const option=currentOption(),sendButton=composer?.querySelector('button[type=submit]');
    if(!option){memberCount.textContent='No current team access';if(unread)unread.hidden=true;if(openTeam)openTeam.hidden=true;if(input)input.disabled=true;if(sendButton)sendButton.disabled=true;updateTotalUnread();return;}
    if(openTeam){openTeam.hidden=false;openTeam.href='/team.php?id='+encodeURIComponent(option.dataset.team||'');}
    if(input)input.disabled=false;if(sendButton)sendButton.disabled=false;
    memberCount.textContent=(option.dataset.members||'0')+' members';
    const count=Number(option.dataset.unread)||0;if(unread){unread.hidden=!count;unread.textContent=count?count+' unread':'';}
    updateTotalUnread();
  }
  async function loadMessages({quiet=false}={}){
    if(!select?.value)return;const sequence=++loadSequence,conversation=select.value,nearBottom=(messages.scrollHeight-messages.scrollTop-messages.clientHeight)<90;
    if(!quiet){messages.innerHTML='<div class="teamChatLoading">Loading messages…</div>';historyExpanded=false;}
    try{
      const data=await request('messages',{params:{conversation,limit:60}});
      if(sequence!==loadSequence||conversation!==select.value)return;
      const rows=data.messages||[];
      if(quiet&&historyExpanded){
        for(const row of rows){if(!messages.querySelector('[data-message="'+CSS.escape(String(row.public_id))+'"]'))messages.appendChild(renderMessage(row));}
      }else{
        messages.replaceChildren();for(const row of rows)messages.appendChild(renderMessage(row));
        if(!rows.length){const empty=document.createElement('div');empty.className='teamChatEmpty';empty.textContent='No messages yet. Start the conversation.';messages.appendChild(empty);}
      }
      nextBefore=data.next_before||null;if(loadEarlier){loadEarlier.hidden=!nextBefore;}
      if(!quiet||nearBottom)messages.scrollTop=messages.scrollHeight;
      if(!quiet||nearBottom)await markRead(conversation,rows[rows.length-1]);
    }catch(err){
      if(sequence!==loadSequence||conversation!==select.value)return;
      if(!quiet){messages.replaceChildren();const e=document.createElement('div');e.className='teamChatError';e.textContent=err.message||'Unable to load Team Chat.';messages.appendChild(e);}
    }
  }
  async function loadEarlierMessages(){
    if(!select?.value||!nextBefore)return;const conversation=select.value,sequence=++loadSequence,oldHeight=messages.scrollHeight;loadEarlier.disabled=true;
    try{
      const data=await request('messages',{params:{conversation,before:nextBefore,limit:60}});if(sequence!==loadSequence||conversation!==select.value)return;
      const fragment=document.createDocumentFragment();for(const row of (data.messages||[]))fragment.appendChild(renderMessage(row));messages.prepend(fragment);
      nextBefore=data.next_before||null;historyExpanded=true;loadEarlier.hidden=!nextBefore;messages.scrollTop+=messages.scrollHeight-oldHeight;
    }catch(err){alert(err.message||'Unable to load earlier team messages.');}
    finally{loadEarlier.disabled=false;}
  }
  async function refreshConversationList(){
    try{
      const data=await request('list'),incoming=data.conversations||[],selected=select.value,incomingIds=new Set(incoming.map(item=>item.public_id));
      [...select.options].forEach(option=>{if(!incomingIds.has(option.value))option.remove();});
      for(const item of incoming){
        let option=[...select.options].find(o=>o.value===item.public_id);
        if(!option){option=document.createElement('option');option.value=item.public_id;select.appendChild(option);}
        option.dataset.team=String(item.team_public_id||'');option.dataset.unread=String(item.unread_count||0);option.dataset.members=String(item.member_count||0);
        option.textContent=item.team_name+(item.unread_count?' · '+item.unread_count+' new':'');
      }
      if([...select.options].some(o=>o.value===selected))select.value=selected;else if(select.options.length)select.selectedIndex=0;
      const changed=selected!==select.value;syncTeamMeta();if(changed){nextBefore=null;historyExpanded=false;loadMessages();}
    }catch{}
  }
  composer?.addEventListener('submit',async e=>{
    e.preventDefault();const body=input.value.trim();if(!body||!select.value)return;
    const button=composer.querySelector('button[type=submit]');button.disabled=true;
    const client=(globalThis.crypto?.randomUUID?.()||String(Date.now())+'-'+Math.random().toString(16).slice(2));
    try{
      await request('send',{method:'POST',data:{conversation:select.value,body,parent_message:parentMessage||null,client_message_id:client}});
      input.value='';input.style.height='auto';setReply('','');await loadMessages();
    }catch(err){alert(err.message||'Unable to send team message.');}
    finally{button.disabled=false;input.focus();}
  });
  input?.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,110)+'px';});
  input?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();composer.requestSubmit();}});
  reply?.querySelector('button')?.addEventListener('click',()=>setReply('',''));
  select?.addEventListener('change',()=>{setReply('','');nextBefore=null;historyExpanded=false;syncTeamMeta();loadMessages();});
  loadEarlier?.addEventListener('click',loadEarlierMessages);
  mobileOpen?.addEventListener('click',()=>document.body.classList.add('teamChatMobileOpen'));
  mobileClose?.addEventListener('click',()=>document.body.classList.remove('teamChatMobileOpen'));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')document.body.classList.remove('teamChatMobileOpen');});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)loadMessages({quiet:true});});
  syncTeamMeta();loadMessages();
  pollTimer=setInterval(()=>{if(document.hidden)return;loadMessages({quiet:true});refreshConversationList();},8000);
  window.addEventListener('beforeunload',()=>clearInterval(pollTimer),{once:true});
})();