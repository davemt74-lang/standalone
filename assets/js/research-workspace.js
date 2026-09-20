(()=>{
  const now=document.querySelector('[data-research-now]');
  if(!now)return;
  const project=now.dataset.project||'',refresh=now.querySelector('[data-workspace-refresh]'),status=now.querySelector('.researchWorkspaceStatus');
  const csrf=document.querySelector('[data-research-agent-panel]')?.dataset.csrf||document.querySelector('input[name="csrf"]')?.value||'';
  async function request(action){
    const r=await fetch('/api/research-workspace.php?action='+encodeURIComponent(action),{method:action==='get'?'GET':'POST',headers:action==='get'?{Accept:'application/json'}:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},body:action==='get'?undefined:JSON.stringify({project_id:project})});
    const j=await r.json().catch(()=>null);if(!r.ok||!j?.ok)throw new Error(j?.error?.message||j?.error?.code||'Workspace intelligence request failed');return j.data;
  }
  function setStatus(value){if(!status)return;status.className='researchWorkspaceStatus status-'+String(value||'pending');status.textContent=String(value||'pending').replace(/_/g,' ').replace(/^./,x=>x.toUpperCase());}
  refresh?.addEventListener('click',async()=>{refresh.disabled=true;const old=refresh.textContent;refresh.textContent='Refreshing…';try{const data=await request('refresh');setStatus(data.intelligence?.status||'pending');refresh.textContent=data.queued?'Queued':'Already current';setTimeout(()=>location.reload(),data.queued?1400:500);}catch(e){alert(e.message||'Unable to refresh workspace intelligence.');refresh.textContent=old;}finally{refresh.disabled=false;}});
})();