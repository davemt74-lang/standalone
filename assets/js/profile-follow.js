(()=>{
  const root=document.querySelector('[data-profile-follow-root]');if(!root)return;
  const csrf=String(root.dataset.csrf||'');if(!csrf)return;
  const labelFor=(state)=>state.following?'Following':(state.follows_you?'Follow back':'Follow');
  const apply=(userId,state)=>{
    document.querySelectorAll('[data-profile-follow="'+CSS.escape(String(userId))+'"]').forEach(btn=>{
      btn.textContent=labelFor(state);btn.setAttribute('aria-pressed',state.following?'true':'false');btn.disabled=false;btn.removeAttribute('aria-busy');
      const badge=btn.closest('[data-profile-network-person]')?.querySelector('[data-profile-relationship]');
      if(badge)badge.textContent=state.friends?'Friends':(state.follows_you?'Follows you':'');
    });
    document.querySelectorAll('[data-profile-follower-count-user="'+CSS.escape(String(userId))+'"]').forEach(el=>{if(Number.isFinite(Number(state.follower_count)))el.textContent=String(state.follower_count);});
  };
  root.addEventListener('click',async e=>{
    const btn=e.target.closest('[data-profile-follow]');if(!btn||btn.disabled)return;
    e.preventDefault();e.stopPropagation();const userId=String(btn.dataset.profileFollow||'');if(!userId)return;
    const desired=btn.getAttribute('aria-pressed')!=='true';btn.disabled=true;btn.setAttribute('aria-busy','true');
    try{
      const r=await fetch('/api/profile-follow.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({user_id:userId,following:desired})});
      const j=await r.json().catch(()=>({ok:false,error:{message:'Follow request failed.'}}));if(!r.ok||j.ok===false)throw new Error(j.error?.message||'Follow request failed.');
      apply(userId,j.data||{});document.dispatchEvent(new CustomEvent('annotated:profile-follow-changed',{detail:{user_id:userId,...(j.data||{})}}));
    }catch(err){btn.disabled=false;btn.removeAttribute('aria-busy');alert(err?.message||'Unable to update follow state.');}
  });
})();