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
  const popoutCurrent=rail.querySelector('[data-team-chat-popout]');
  const popupLayer=document.querySelector('[data-team-chat-popups]');
  const selfStatus=rail.querySelector('[data-team-chat-self-status]');
  const mobileOpen=document.querySelector('[data-team-chat-open]');
  const mobileClose=rail.querySelector('[data-team-chat-close]');
  const totalUnread=document.querySelector('[data-team-chat-total-unread]');
  const csrf=rail.dataset.csrf||'';

  let railParentMessage='',railParentLabel='',railSequence=0,railNextBefore=null,railHistoryExpanded=false;
  let pollTimer=null;
  const presenceByUsername=new Map();
  const popups=new Map();
  const popupOrder=[];
  const POPUP_KEY='annotated.teamChatPopups';

  function currentOption(){return select?.selectedOptions?.[0]||null;}
  function absoluteProfile(username){return '/'+encodeURIComponent(String(username||''));}
  function formatTime(value){
    const raw=String(value||'').trim();if(!raw)return '';
    const normalized=/Z$|[+-]\d\d:\d\d$/.test(raw)?raw:raw.replace(' ','T')+'Z';
    const d=new Date(normalized);if(Number.isNaN(d.getTime()))return raw;
    return new Intl.DateTimeFormat(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(d);
  }
  function currentPresence(username){return presenceByUsername.get(String(username||''))||{effective_status:'offline',custom_status:''};}
  function statusLabel(value){return ({online:'Online',away:'Away',busy:'Busy',offline:'Offline'})[value]||'Offline';}
  function statusClass(value){return ['online','away','busy','offline'].includes(value)?value:'offline';}

  function avatar(row,size='32'){
    const wrap=document.createElement('span');
    wrap.className='teamChatAvatar';wrap.style.setProperty('--team-chat-avatar-size',size+'px');wrap.dataset.username=String(row.username||'');
    if(row.profile_image_url){
      const img=document.createElement('img');img.src=row.profile_image_url;img.alt=row.display_name||row.username||'Profile photo';wrap.appendChild(img);
    }else{
      const fallback=document.createElement('span');fallback.className='teamChatAvatarFallback';fallback.textContent=String(row.display_name||row.username||'A').trim().charAt(0).toUpperCase()||'A';wrap.appendChild(fallback);
    }
    const p=currentPresence(row.username),dot=document.createElement('i');
    dot.className='chatPresenceDot status-'+statusClass(p.effective_status);dot.dataset.presenceDot='1';dot.title=p.custom_status||statusLabel(p.effective_status);dot.setAttribute('role','img');dot.setAttribute('aria-label',p.custom_status||statusLabel(p.effective_status));wrap.appendChild(dot);
    return wrap;
  }

  async function request(action,{method='GET',data=null,params={}}={}){
    const url=new URL('/api/conversations.php',location.origin);url.searchParams.set('action',action);
    Object.entries(params).forEach(([k,v])=>{if(v!==null&&v!==undefined&&v!=='')url.searchParams.set(k,String(v));});
    const options={method,headers:{'Accept':'application/json'}};
    if(method!=='GET'){
      options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=csrf;options.body=JSON.stringify(data||{});
    }
    const response=await fetch(url,options);
    const json=await response.json().catch(()=>({ok:false,error:{message:'Invalid server response'}}));
    if(!response.ok||json.ok===false)throw new Error(json.error?.message||json.error?.code||'Team Chat request failed.');
    return json.data||{};
  }

  function applyPresence(rows=[]){
    presenceByUsername.clear();
    for(const row of rows)presenceByUsername.set(String(row.username||''),row);
    document.querySelectorAll('.teamChatAvatar[data-username]').forEach(node=>{
      const p=currentPresence(node.dataset.username),dot=node.querySelector('[data-presence-dot]');
      if(dot){dot.className='chatPresenceDot status-'+statusClass(p.effective_status);dot.title=p.custom_status||statusLabel(p.effective_status);dot.setAttribute('aria-label',p.custom_status||statusLabel(p.effective_status));}
    });
  }

  const agentEnabled=rail.dataset.agentEnabled==='1';
  function renderAttachment(item){
    const wrap=document.createElement('section');wrap.className='teamChatAttachment'+(item.available===false?' is-unavailable':'');
    if(item.available===false){wrap.textContent=item.label||'Shared item unavailable';return wrap;}
    const primary=document.createElement('a');primary.className='teamChatAttachmentPrimary';primary.href=item.url||'#';
    const eyebrow=document.createElement('small');eyebrow.textContent=item.label||item.type||'Shared item';
    const title=document.createElement('strong');title.textContent=item.title||item.preview||item.public_id||'Annotated item';
    primary.append(eyebrow,title);
    if(item.source?.title){const source=document.createElement('span');source.textContent=item.source.title;primary.appendChild(source);}
    wrap.appendChild(primary);
    const actions=document.createElement('div');actions.className='teamChatAttachmentActions';
    const open=document.createElement('a');open.href=item.url||'#';open.textContent='Open';actions.appendChild(open);
    if(item.type==='annotation'){
      const research=document.createElement('button');research.type='button';research.textContent='Research';research.addEventListener('click',()=>document.dispatchEvent(new CustomEvent('annotated:object-add-research',{detail:{type:'annotation',public_id:item.public_id,source:'team_chat'},bubbles:true})));actions.appendChild(research);
      if(agentEnabled){const agent=document.createElement('button');agent.type='button';agent.textContent='Agent';agent.addEventListener('click',()=>document.dispatchEvent(new CustomEvent('annotated:object-ask-agent',{detail:{type:'annotation',public_id:item.public_id,source:'team_chat'},bubbles:true})));actions.appendChild(agent);}
    }
    wrap.appendChild(actions);return wrap;
  }

  function renderMessage(row,onReply){
    const article=document.createElement('article');article.className='teamChatMessage'+(row.is_self?' is-self':'');article.dataset.message=row.public_id;
    const head=document.createElement('div');head.className='teamChatMessageHead';
    const identity=document.createElement('a');identity.className='teamChatIdentity';identity.href=absoluteProfile(row.username);
    identity.appendChild(avatar(row));
    const names=document.createElement('span'),strong=document.createElement('strong'),meta=document.createElement('small');
    strong.textContent=row.display_name||row.username||'Annotated user';
    meta.textContent='@'+(row.username||'user')+' · '+formatTime(row.created_at);
    const presence=currentPresence(row.username);if(presence.custom_status)meta.title=presence.custom_status;
    names.append(strong,meta);identity.appendChild(names);head.appendChild(identity);
    const replyButton=document.createElement('button');replyButton.type='button';replyButton.className='teamChatReplyButton';replyButton.textContent='Reply';
    replyButton.addEventListener('click',()=>onReply?.(row.public_id,row.display_name||row.username||'message'));
    head.appendChild(replyButton);article.appendChild(head);
    if(row.parent_public_id){
      const quote=document.createElement('div');quote.className='teamChatParent';
      const who=document.createElement('strong'),body=document.createElement('span');who.textContent=row.parent_display_name||'Reply';body.textContent=String(row.parent_body||'').slice(0,180);
      quote.append(who,body);article.appendChild(quote);
    }
    const body=document.createElement('div');body.className='teamChatBody';body.textContent=row.body||'';article.appendChild(body);
    if(Array.isArray(row.attachments)&&row.attachments.length){const attachments=document.createElement('div');attachments.className='teamChatAttachments';for(const item of row.attachments)attachments.appendChild(renderAttachment(item));article.appendChild(attachments);}
    return article;
  }

  async function markRead(conversation,last){
    if(!conversation||!last?.public_id)return;
    try{await request('read',{method:'POST',data:{conversation,message:last.public_id}});setConversationUnread(conversation,0);}catch{}
  }

  function setConversationUnread(conversation,count){
    const option=[...(select?.options||[])].find(o=>o.value===conversation);
    if(option){option.dataset.unread=String(count);const base=option.textContent.replace(/ · \d+ new$/,'');option.textContent=base+(count?' · '+count+' new':'');}
    if(option&&option===currentOption()&&unread){unread.hidden=!count;unread.textContent=count?count+' unread':'';}
    const popup=popups.get(conversation);if(popup){popup.badge.hidden=!count;popup.badge.textContent=count?String(count):'';}
    updateTotalUnread();
  }

  function updateTotalUnread(){
    const total=[...(select?.options||[])].reduce((sum,o)=>sum+(Number(o.dataset.unread)||0),0);
    if(totalUnread)totalUnread.textContent=total?'('+total+')':'';
  }

  function setRailReply(publicId,label){
    railParentMessage=publicId||'';railParentLabel=label||'';if(!reply)return;
    reply.hidden=!railParentMessage;const span=reply.querySelector('span');if(span)span.textContent=railParentMessage?'Replying to '+railParentLabel:'';
  }

  function syncTeamMeta(onlineCount=null){
    const option=currentOption(),sendButton=composer?.querySelector('button[type=submit]');
    if(!option){
      memberCount.textContent='No current team access';if(unread)unread.hidden=true;if(openTeam)openTeam.hidden=true;if(input)input.disabled=true;if(sendButton)sendButton.disabled=true;updateTotalUnread();return;
    }
    if(openTeam){openTeam.hidden=false;openTeam.href='/team.php?id='+encodeURIComponent(option.dataset.team||'');}
    if(input)input.disabled=false;if(sendButton)sendButton.disabled=false;
    const online=onlineCount===null?null:Number(onlineCount);
    memberCount.textContent=(online===null?'':online+' online · ')+(option.dataset.members||'0')+' members';
    const count=Number(option.dataset.unread)||0;if(unread){unread.hidden=!count;unread.textContent=count?count+' unread':'';}
    updateTotalUnread();
  }

  async function loadRailMessages({quiet=false}={}){
    if(!select?.value)return;
    const sequence=++railSequence,conversation=select.value,nearBottom=(messages.scrollHeight-messages.scrollTop-messages.clientHeight)<90;
    if(!quiet){messages.innerHTML='<div class="teamChatLoading">Loading messages…</div>';railHistoryExpanded=false;}
    try{
      const data=await request('messages',{params:{conversation,limit:60}});
      if(sequence!==railSequence||conversation!==select.value)return;
      applyPresence(data.presence||[]);syncTeamMeta(data.conversation?.online_count??0);
      const rows=data.messages||[];
      if(quiet&&railHistoryExpanded){
        for(const row of rows)if(!messages.querySelector('[data-message="'+CSS.escape(String(row.public_id))+'"]'))messages.appendChild(renderMessage(row,(id,label)=>{setRailReply(id,label);input?.focus();}));
      }else{
        messages.replaceChildren();
        for(const row of rows)messages.appendChild(renderMessage(row,(id,label)=>{setRailReply(id,label);input?.focus();}));
        if(!rows.length){const empty=document.createElement('div');empty.className='teamChatEmpty';empty.textContent='No messages yet. Start the conversation.';messages.appendChild(empty);}
      }
      railNextBefore=data.next_before||null;if(loadEarlier)loadEarlier.hidden=!railNextBefore;
      if(!quiet||nearBottom)messages.scrollTop=messages.scrollHeight;
      if(!quiet||nearBottom)await markRead(conversation,rows[rows.length-1]);
    }catch(err){
      if(sequence!==railSequence||conversation!==select.value)return;
      if(!quiet){messages.replaceChildren();const e=document.createElement('div');e.className='teamChatError';e.textContent=err.message||'Unable to load Team Chat.';messages.appendChild(e);}
    }
  }

  async function loadEarlierMessages(){
    if(!select?.value||!railNextBefore)return;
    const conversation=select.value,sequence=++railSequence,oldHeight=messages.scrollHeight;loadEarlier.disabled=true;
    try{
      const data=await request('messages',{params:{conversation,before:railNextBefore,limit:60}});
      if(sequence!==railSequence||conversation!==select.value)return;
      applyPresence(data.presence||[]);
      const fragment=document.createDocumentFragment();
      for(const row of (data.messages||[]))fragment.appendChild(renderMessage(row,(id,label)=>{setRailReply(id,label);input?.focus();}));
      messages.prepend(fragment);railNextBefore=data.next_before||null;railHistoryExpanded=true;loadEarlier.hidden=!railNextBefore;messages.scrollTop+=messages.scrollHeight-oldHeight;
    }catch(err){alert(err.message||'Unable to load earlier team messages.');}
    finally{loadEarlier.disabled=false;}
  }

  function popupMeta(conversation){
    const option=[...(select?.options||[])].find(o=>o.value===conversation);
    return option?{conversation,team:option.dataset.team||'',name:option.textContent.replace(/ · \d+ new$/,''),members:Number(option.dataset.members)||0}:null;
  }

  function savePopups(){
    try{localStorage.setItem(POPUP_KEY,JSON.stringify(popupOrder.filter(id=>popups.has(id)).map(id=>({conversation:id,minimized:!!popups.get(id)?.minimized}))));}catch{}
  }

  function closePopup(conversation){
    const popup=popups.get(conversation);if(!popup)return;popup.el.remove();popups.delete(conversation);
    const i=popupOrder.indexOf(conversation);if(i>=0)popupOrder.splice(i,1);savePopups();
  }

  function setPopupMinimized(popup,minimized){
    popup.minimized=!!minimized;popup.el.classList.toggle('is-minimized',popup.minimized);popup.minimize.textContent=popup.minimized?'□':'—';popup.minimize.setAttribute('aria-label',popup.minimized?'Restore chat':'Minimize chat');savePopups();
    if(!popup.minimized)loadPopupMessages(popup,{quiet:true});
  }

  async function loadPopupMessages(popup,{quiet=false}={}){
    if(!popup||popup.loading)return;popup.loading=true;
    try{
      const data=await request('messages',{params:{conversation:popup.conversation,limit:40}});
      applyPresence(data.presence||[]);popup.online.textContent=(data.conversation?.online_count??0)+' online';
      const rows=data.messages||[],nearBottom=(popup.messages.scrollHeight-popup.messages.scrollTop-popup.messages.clientHeight)<70;
      popup.messages.replaceChildren();
      for(const row of rows)popup.messages.appendChild(renderMessage(row,(id,label)=>{popup.parentMessage=id;popup.replyLabel.textContent='Replying to '+label;popup.reply.hidden=false;popup.input.focus();}));
      if(!rows.length){const empty=document.createElement('div');empty.className='teamChatEmpty';empty.textContent='No messages yet.';popup.messages.appendChild(empty);}
      if(!quiet||nearBottom)popup.messages.scrollTop=popup.messages.scrollHeight;
      if(!popup.minimized&&!document.hidden)await markRead(popup.conversation,rows[rows.length-1]);
    }catch(err){if(!quiet){popup.messages.textContent=err.message||'Unable to load chat.';}}
    finally{popup.loading=false;}
  }

  function openPopup(conversation,{minimized=false}={}){
    if(!conversation)return;
    if(matchMedia('(max-width: 900px)').matches){
      if([...select.options].some(o=>o.value===conversation))select.value=conversation;syncTeamMeta();loadRailMessages();document.body.classList.add('teamChatMobileOpen');return;
    }
    if(popups.has(conversation)){const existing=popups.get(conversation);setPopupMinimized(existing,false);existing.input.focus();return;}
    const meta=popupMeta(conversation);if(!meta)return;
    while(popupOrder.length>=3)closePopup(popupOrder[0]);

    const el=document.createElement('section');el.className='teamChatPopup';el.dataset.conversation=conversation;
    const header=document.createElement('header');header.className='teamChatPopupHeader';
    const titleWrap=document.createElement('button');titleWrap.type='button';titleWrap.className='teamChatPopupTitle';
    const title=document.createElement('strong');title.textContent=meta.name;
    const online=document.createElement('small');online.textContent='Checking status…';titleWrap.append(title,online);
    const badge=document.createElement('span');badge.className='teamChatPopupUnread';badge.hidden=true;
    const actions=document.createElement('div');actions.className='teamChatPopupActions';
    const minimize=document.createElement('button');minimize.type='button';minimize.textContent='—';minimize.setAttribute('aria-label','Minimize chat');
    const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Close chat');
    actions.append(minimize,close);header.append(titleWrap,badge,actions);

    const body=document.createElement('div');body.className='teamChatPopupBody';
    const popupMessages=document.createElement('div');popupMessages.className='teamChatPopupMessages';popupMessages.setAttribute('role','log');
    const popupReply=document.createElement('div');popupReply.className='teamChatReply';popupReply.hidden=true;
    const replyLabel=document.createElement('span'),cancelReply=document.createElement('button');cancelReply.type='button';cancelReply.textContent='×';cancelReply.setAttribute('aria-label','Cancel reply');popupReply.append(replyLabel,cancelReply);
    const form=document.createElement('form');form.className='teamChatPopupComposer';
    const popupInput=document.createElement('textarea');popupInput.rows=1;popupInput.maxLength=5000;popupInput.placeholder='Message '+meta.name+'…';popupInput.setAttribute('aria-label','Team message');
    const send=document.createElement('button');send.type='submit';send.textContent='↑';send.setAttribute('aria-label','Send message');form.append(popupInput,send);
    body.append(popupMessages,popupReply,form);el.append(header,body);popupLayer?.appendChild(el);

    const popup={conversation,el,body,messages:popupMessages,input:popupInput,form,reply:popupReply,replyLabel,parentMessage:'',online,badge,minimize,minimized:false,loading:false};
    popups.set(conversation,popup);popupOrder.push(conversation);
    minimize.addEventListener('click',()=>setPopupMinimized(popup,!popup.minimized));
    titleWrap.addEventListener('click',()=>setPopupMinimized(popup,!popup.minimized));
    close.addEventListener('click',()=>closePopup(conversation));
    cancelReply.addEventListener('click',()=>{popup.parentMessage='';popup.reply.hidden=true;});
    popupInput.addEventListener('input',()=>{popupInput.style.height='auto';popupInput.style.height=Math.min(popupInput.scrollHeight,90)+'px';});
    popupInput.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}});
    form.addEventListener('submit',async e=>{
      e.preventDefault();const bodyText=popupInput.value.trim();if(!bodyText)return;send.disabled=true;
      const client=(globalThis.crypto?.randomUUID?.()||String(Date.now())+'-'+Math.random().toString(16).slice(2));
      try{
        await request('send',{method:'POST',data:{conversation,body:bodyText,parent_message:popup.parentMessage||null,client_message_id:client}});
        popupInput.value='';popupInput.style.height='auto';popup.parentMessage='';popup.reply.hidden=true;await loadPopupMessages(popup);
        if(select.value===conversation)loadRailMessages({quiet:true});
      }catch(err){alert(err.message||'Unable to send team message.');}
      finally{send.disabled=false;popupInput.focus();}
    });
    setPopupMinimized(popup,minimized);loadPopupMessages(popup);savePopups();
  }

  function restorePopups(){
    let saved=[];try{saved=JSON.parse(localStorage.getItem(POPUP_KEY)||'[]');}catch{}
    if(!Array.isArray(saved))return;
    for(const item of saved.slice(-3))if(item&&typeof item.conversation==='string')openPopup(item.conversation,{minimized:!!item.minimized});
  }

  async function refreshConversationList(){
    try{
      const data=await request('list'),incoming=data.conversations||[],selected=select.value,incomingIds=new Set(incoming.map(item=>item.public_id));
      [...select.options].forEach(option=>{if(!incomingIds.has(option.value))option.remove();});
      for(const id of [...popupOrder])if(!incomingIds.has(id))closePopup(id);
      for(const item of incoming){
        let option=[...select.options].find(o=>o.value===item.public_id);
        if(!option){option=document.createElement('option');option.value=item.public_id;select.appendChild(option);}
        option.dataset.team=String(item.team_public_id||'');option.dataset.unread=String(item.unread_count||0);option.dataset.members=String(item.member_count||0);
        option.textContent=item.team_name+(item.unread_count?' · '+item.unread_count+' new':'');
        const popup=popups.get(item.public_id);if(popup){popup.badge.hidden=!item.unread_count;popup.badge.textContent=item.unread_count?String(item.unread_count):'';}
      }
      if([...select.options].some(o=>o.value===selected))select.value=selected;else if(select.options.length)select.selectedIndex=0;
      const changed=selected!==select.value;syncTeamMeta();if(changed){railNextBefore=null;railHistoryExpanded=false;loadRailMessages();}
    }catch{}
  }

  function updateSelfStatus(data){
    if(!selfStatus||!data)return;
    const dot=selfStatus.querySelector('.chatPresenceDot'),label=selfStatus.querySelector('span');
    if(dot){dot.className='chatPresenceDot status-'+statusClass(data.effective_status);}
    if(label)label.textContent=data.custom_status||({auto:'Automatic',available:'Available',away:'Away',busy:'Busy',invisible:'Invisible'})[data.status_mode]||statusLabel(data.effective_status);
  }

  composer?.addEventListener('submit',async e=>{
    e.preventDefault();const body=input.value.trim();if(!body||!select.value)return;
    const button=composer.querySelector('button[type=submit]');button.disabled=true;
    const client=(globalThis.crypto?.randomUUID?.()||String(Date.now())+'-'+Math.random().toString(16).slice(2));
    try{
      await request('send',{method:'POST',data:{conversation:select.value,body,parent_message:railParentMessage||null,client_message_id:client}});
      input.value='';input.style.height='auto';setRailReply('','');await loadRailMessages();
      const popup=popups.get(select.value);if(popup)loadPopupMessages(popup,{quiet:true});
    }catch(err){alert(err.message||'Unable to send team message.');}
    finally{button.disabled=false;input.focus();}
  });

  input?.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,110)+'px';});
  input?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();composer.requestSubmit();}});
  reply?.querySelector('button')?.addEventListener('click',()=>setRailReply('',''));
  select?.addEventListener('change',()=>{setRailReply('','');railNextBefore=null;railHistoryExpanded=false;syncTeamMeta();const option=currentOption();document.dispatchEvent(new CustomEvent('annotated:workspace-context',{detail:{team_public_id:option?.dataset.team||'',surface:'team'}}));loadRailMessages();});
  loadEarlier?.addEventListener('click',loadEarlierMessages);
  popoutCurrent?.addEventListener('click',()=>openPopup(select.value));
  mobileOpen?.addEventListener('click',()=>document.body.classList.add('teamChatMobileOpen'));
  mobileClose?.addEventListener('click',()=>document.body.classList.remove('teamChatMobileOpen'));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')document.body.classList.remove('teamChatMobileOpen');});
  document.addEventListener('annotated:chat-presence',e=>updateSelfStatus(e.detail||{}));
  document.addEventListener('annotated:team-chat-share-complete',e=>{const conversation=String(e.detail?.conversation||'');if(!conversation)return;openPopup(conversation);refreshConversationList();if(select?.value===conversation)loadRailMessages({quiet:true});});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden){loadRailMessages({quiet:true});for(const popup of popups.values())if(!popup.minimized)loadPopupMessages(popup,{quiet:true});}});

  syncTeamMeta();loadRailMessages();restorePopups();
  pollTimer=setInterval(()=>{if(document.hidden)return;loadRailMessages({quiet:true});refreshConversationList();for(const popup of popups.values())if(!popup.minimized)loadPopupMessages(popup,{quiet:true});},8000);
  window.addEventListener('beforeunload',()=>clearInterval(pollTimer),{once:true});
})();