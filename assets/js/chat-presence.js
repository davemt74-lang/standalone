(()=>{
  const shell=document.querySelector('[data-annotated-shell][data-chat-presence-csrf]');
  if(!shell)return;
  const csrf=shell.dataset.chatPresenceCsrf||'',KEY='annotated.teamChatTabSession';
  let client='';
  try{client=sessionStorage.getItem(KEY)||'';}catch{}
  if(!/^[A-Za-z0-9._:-]{8,80}$/.test(client)){
    client=(globalThis.crypto?.randomUUID?.()||('chat-'+Date.now()+'-'+Math.random().toString(16).slice(2))).slice(0,80);
    try{sessionStorage.setItem(KEY,client);}catch{}
  }
  async function post(action,keepalive=false){
    const response=await fetch('/api/conversations.php?action='+encodeURIComponent(action),{
      method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'Accept':'application/json'},
      body:JSON.stringify({client_session_id:client}),keepalive
    });
    const json=await response.json().catch(()=>null);
    if(response.ok&&json?.ok&&json.data)document.dispatchEvent(new CustomEvent('annotated:chat-presence',{detail:json.data}));
  }
  const beat=()=>post('heartbeat').catch(()=>{});
  beat();
  const timer=setInterval(beat,25000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)beat();});
  window.addEventListener('beforeunload',()=>{clearInterval(timer);post('leave',true).catch(()=>{});},{once:true});
})();