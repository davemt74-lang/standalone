(()=>{
  const root=document.querySelector('[data-cognitive-feed]');if(!root)return;
  const csrf=root.dataset.csrf||'';let feedDirty=false,toastTimer=0;
  async function mutate(action,data={}){
    const r=await fetch('/api/cognitive-feed.php?action='+encodeURIComponent(action),{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});
    const j=await r.json().catch(()=>null);if(!r.ok||!j?.ok)throw new Error(j?.error?.message||j?.error?.code||'Cognitive Feed request failed');return j.data;
  }
  function toast(message,undo){
    let el=document.querySelector('.cognitiveToast');if(el)el.remove();clearTimeout(toastTimer);
    el=document.createElement('div');el.className='cognitiveToast';const span=document.createElement('span');span.textContent=message;el.appendChild(span);
    if(undo){const b=document.createElement('button');b.type='button';b.textContent='Undo';b.addEventListener('click',async()=>{b.disabled=true;try{await undo();el.remove();}catch(e){b.disabled=false;alert(e.message||'Unable to restore item.');}});el.appendChild(b);}
    document.body.appendChild(el);toastTimer=setTimeout(()=>el.remove(),5500);
  }
  document.querySelectorAll('[data-cognitive-mode]').forEach(link=>link.addEventListener('click',async e=>{
    e.preventDefault();const href=link.href,mode=link.dataset.cognitiveMode||'';try{await mutate('mode',{mode});}catch{}location.href=href;
  }));
  root.querySelector('[data-cognitive-refresh]')?.addEventListener('click',()=>location.reload());
  root.querySelector('[data-cognitive-restore-all]')?.addEventListener('click',async e=>{
    const b=e.currentTarget;b.disabled=true;try{await mutate('restore_all');location.reload();}catch(err){b.disabled=false;alert(err.message||'Unable to restore hidden items.');}
  });
  document.addEventListener('click',async e=>{
    const watch=e.target.closest?.('[data-proactive-watch]');
    if(watch){
      watch.disabled=true;
      try{
        const r=await fetch('/api/proactive-intelligence.php?action=watch',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({type:watch.dataset.watchType||'',public_id:watch.dataset.watchId||'',alert_level:'important'})});
        const j=await r.json().catch(()=>null);if(!r.ok||!j?.ok)throw new Error(j?.error?.message||'Unable to watch this Research context.');
        watch.textContent='Watching';toast('Added to Research watches.');
      }catch(err){watch.disabled=false;alert(err.message||'Unable to add watch.');}
      return;
    }
    const dismiss=e.target.closest?.('[data-cognitive-dismiss]');
    if(dismiss){
      const card=dismiss.closest('[data-cognitive-card]');if(!card)return;const key=card.dataset.key||'',type=card.dataset.type||'observation',stack=card.parentElement,section=card.closest('[data-cognitive-section]'),next=card.nextSibling;
      dismiss.disabled=true;
      try{
        await mutate('dismiss',{key,type});card.remove();if(stack&&!stack.querySelector('[data-cognitive-card]'))section?.setAttribute('hidden','');
        toast('Hidden from Now.',async()=>{await mutate('restore',{key});section?.removeAttribute('hidden');if(stack)stack.insertBefore(card,next&&next.parentNode===stack?next:null);dismiss.disabled=false;});
      }catch(err){dismiss.disabled=false;alert(err.message||'Unable to hide item.');}
      return;
    }
    const agent=e.target.closest?.('[data-cognitive-agent]');
    if(agent){
      const prompt=String(agent.dataset.prompt||'').trim();let context=[];try{const parsed=JSON.parse(agent.dataset.context||'[]');if(Array.isArray(parsed))context=parsed;}catch{}
      document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:{prompt,context,source:'cognitive_feed'},bubbles:true,cancelable:true}));return;
    }
  });
  document.addEventListener('annotated:research-action-executed',()=>{feedDirty=true;const b=root.querySelector('[data-cognitive-refresh]');if(b){b.textContent='Refresh Now';b.classList.add('attention');}});
  document.addEventListener('annotated:agent-chat-feed-restored',()=>{if(feedDirty)location.reload();});
})();