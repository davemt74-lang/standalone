(()=>{
  const dialog=document.querySelector('[data-research-agent-dialog]');
  if(!dialog)return;
  const form=dialog.querySelector('[data-research-agent-form]');
  const error=dialog.querySelector('[data-research-agent-error]');
  const timezone=dialog.querySelector('[data-research-agent-timezone]');
  const triggers=document.querySelectorAll('[data-research-agent-add]');
  const closes=dialog.querySelectorAll('[data-research-agent-close]');
  const csrf=String(dialog.dataset.csrf||'');

  const open=()=>{
    if(timezone)try{timezone.value=Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC';}catch{timezone.value='UTC';}
    if(error){error.hidden=true;error.textContent='';}
    if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
    requestAnimationFrame(()=>form?.querySelector('input[name="name"]')?.focus());
  };
  const close=()=>{if(dialog.open&&typeof dialog.close==='function')dialog.close();else dialog.removeAttribute('open');};

  triggers.forEach(button=>button.addEventListener('click',open));
  closes.forEach(button=>button.addEventListener('click',close));
  dialog.addEventListener('click',event=>{if(event.target===dialog)close();});
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});

  form?.addEventListener('submit',async event=>{
    event.preventDefault();
    const submit=form.querySelector('button[type="submit"]');
    const data=Object.fromEntries(new FormData(form).entries());
    if(error){error.hidden=true;error.textContent='';}
    if(submit)submit.disabled=true;
    try{
      const response=await fetch('/api/research-agents.php?action=create',{
        method:'POST',
        credentials:'same-origin',
        headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify(data)
      });
      const json=await response.json().catch(()=>({ok:false,error:{message:'Invalid server response.'}}));
      if(!response.ok||json.ok===false)throw new Error(json.error?.message||'Unable to create Research Agent.');
      const agent=json.data?.agent||{};
      const conversation=String(agent.conversation_public_id||'');
      if(!conversation)throw new Error('Research Agent was created without a conversation.');
      location.href='/home.php?agent='+encodeURIComponent(conversation);
    }catch(err){
      if(error){error.textContent=String(err?.message||'Unable to create Research Agent.');error.hidden=false;}
      if(submit)submit.disabled=false;
    }
  });
})();