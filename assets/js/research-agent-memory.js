(()=>{
  const body=document.body;
  const root=document.querySelector('[data-research-memory-manager]');
  if(!root)return;
  const agent=body.dataset.memoryAgent||'';
  const csrf=body.dataset.memoryCsrf||'';

  const request=async(action,payload={})=>{
    const r=await fetch('/api/research-agent-memory.php?action='+encodeURIComponent(action),{
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify(Object.assign({agent_id:agent},payload))
    });
    const data=await r.json().catch(()=>({ok:false,error:{message:'Invalid server response.'}}));
    if(!r.ok||!data.ok)throw new Error(data?.error?.message||'Agent Memory update failed.');
    return data.data||{};
  };

  const esc=(value)=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const renderHistory=(panel,events)=>{
    if(!events.length){panel.innerHTML='<div class="researchMemoryHistoryEmpty">No memory changes recorded yet.</div>';return;}
    panel.innerHTML=events.map(e=>{
      const after=e.after||{};
      const correction=String(after.correction_text||'').trim();
      return '<article><header><strong>'+esc(String(e.event_type||'change').replace(/_/g,' '))+'</strong><time>'+esc(e.created_at||'')+'</time></header>'+
        '<small>'+esc(e.display_name||e.username||'User')+'</small>'+
        '<p>Retrieval: '+esc(after.retrieval_state||'inherit')+(correction?' · correction saved':'')+'</p></article>';
    }).join('');
  };

  root.addEventListener('click',async(e)=>{
    const save=e.target.closest('[data-memory-save]');
    const history=e.target.closest('[data-memory-history]');
    if(!save&&!history)return;
    const item=e.target.closest('[data-memory-item]');if(!item)return;
    const status=item.querySelector('[data-memory-status]');
    const payload={object_type:item.dataset.objectType||'',object_id:item.dataset.objectId||''};
    try{
      if(save){
        save.disabled=true;if(status)status.textContent='Saving…';
        payload.retrieval_state=item.querySelector('[data-memory-state]')?.value||'inherit';
        payload.correction_text=item.querySelector('[data-memory-correction]')?.value||'';
        await request('update',payload);
        if(status)status.textContent='Saved. Future Agent retrieval will use this setting.';
        window.setTimeout(()=>{if(status)status.textContent='';},3500);
      }else{
        const panel=item.querySelector('[data-memory-history-panel]');if(!panel)return;
        history.disabled=true;panel.hidden=false;panel.innerHTML='<div class="researchMemoryHistoryEmpty">Loading history…</div>';
        const data=await request('history',payload);
        renderHistory(panel,Array.isArray(data.events)?data.events:[]);
      }
    }catch(err){
      if(status)status.textContent=err instanceof Error?err.message:'Agent Memory update failed.';
      const panel=item.querySelector('[data-memory-history-panel]');
      if(history&&panel){panel.hidden=false;panel.innerHTML='<div class="researchMemoryHistoryEmpty">'+esc(err instanceof Error?err.message:'Unable to load history.')+'</div>';}
    }finally{
      if(save)save.disabled=false;
      if(history)history.disabled=false;
    }
  });
})();