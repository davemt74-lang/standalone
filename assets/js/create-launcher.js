(()=>{
  const dialog=document.querySelector('[data-create-launcher]');
  const open=document.querySelector('[data-create-launcher-open]');
  if(!dialog||!open)return;
  const menu=dialog.querySelector('[data-create-menu]');
  const panels=[...dialog.querySelectorAll('[data-create-panel]')];
  const csrf=String(dialog.dataset.csrf||'');
  let active='';

  const showMenu=()=>{
    active='';
    menu.hidden=false;
    for(const panel of panels){panel.hidden=true;panel.querySelector('[data-create-error]')?.setAttribute('hidden','');}
  };
  const showPanel=action=>{
    const panel=panels.find(p=>p.dataset.createPanel===action);if(!panel)return;
    active=action;menu.hidden=true;
    for(const item of panels)item.hidden=item!==panel;
    panel.querySelector('input:not([type="hidden"]),textarea,select')?.focus();
  };
  const formData=form=>{
    const out={};
    for(const [key,value] of new FormData(form).entries()){
      if(key.endsWith('[]')){const k=key.slice(0,-2);(out[k]??=[]).push(value);continue;}
      out[key]=value;
    }
    if(active==='mission'){
      out.success_criteria=String(out.criteria_lines||'').split(/\r?\n/).map(v=>v.trim()).filter(Boolean).map(label=>({label}));
      delete out.criteria_lines;
    }
    return out;
  };
  const close=()=>{dialog.close();showMenu();};

  open.addEventListener('click',()=>{showMenu();dialog.showModal();});
  dialog.addEventListener('click',e=>{
    if(e.target===dialog)close();
    const choice=e.target.closest('[data-create-action]');if(choice)showPanel(String(choice.dataset.createAction||''));
    if(e.target.closest('[data-create-back]'))showMenu();
    if(e.target.closest('[data-create-close]'))close();
  });
  dialog.addEventListener('cancel',e=>{e.preventDefault();close();});

  for(const form of panels){
    form.addEventListener('submit',async e=>{
      e.preventDefault();
      const action=String(form.dataset.createPanel||'');if(!action)return;
      const error=form.querySelector('[data-create-error]');if(error){error.hidden=true;error.textContent='';}
      const submit=form.querySelector('button[type="submit"]');if(submit){submit.disabled=true;submit.dataset.label=submit.textContent;submit.textContent='Creating…';}
      try{
        const res=await fetch('/api/create.php?action='+encodeURIComponent(action),{
          method:'POST',
          headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
          body:JSON.stringify(formData(form))
        });
        const json=await res.json().catch(()=>({ok:false,error:{message:'Create request failed.'}}));
        if(!res.ok||json.ok===false)throw new Error(json.error?.message||json.error?.code||'Create request failed.');
        const redirect=String(json.data?.redirect||'');
        if(redirect){location.href=redirect;return;}
        close();location.reload();
      }catch(err){
        if(error){error.textContent=err?.message||'Unable to create this item.';error.hidden=false;}
      }finally{
        if(submit&&!location.href.includes('/api/create.php')){submit.disabled=false;submit.textContent=submit.dataset.label||'Create';}
      }
    });
  }
})();