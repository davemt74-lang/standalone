document.addEventListener('click',async e=>{
  const b=e.target.closest('[data-web-annotation-action]');if(!b)return;
  const action=b.dataset.webAnnotationAction,id=b.dataset.id;if(!action||!id)return;
  const apiAction=action==='like'?'annotation_react':action==='save'?'save':null;if(!apiAction)return;
  b.disabled=true;
  try{
    const r=await fetch('/api/extension.php?action='+apiAction,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.ANNOTATED_CSRF||''},body:JSON.stringify({annotation_id:id})});
    const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Request failed');
    if(action==='like'){b.classList.toggle('active',!!j.data.liked);const count=b.querySelector('[data-like-count]');if(count)count.textContent=String(j.data.like_count||0);}
    if(action==='save'){b.classList.toggle('active',!!j.data.saved);const label=b.querySelector('[data-save-label]');if(label)label.textContent=j.data.saved?'Saved':'Save';}
  }catch(err){alert(err.message||'Unable to update annotation.');}
  finally{b.disabled=false;}
});
