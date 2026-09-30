(()=> {
  const dialog=document.querySelector('[data-command-palette]');
  const trigger=document.querySelector('[data-command-palette-open]');
  if(!dialog||!trigger)return;
  const input=dialog.querySelector('[data-command-input]');
  const results=dialog.querySelector('[data-command-results]');
  const empty=dialog.querySelector('[data-command-empty]');
  const close=()=>{dialog.close();results.innerHTML='';input.value='';};
  let timer=0,controller=null,active=-1;

  const allRows=()=>[...results.querySelectorAll('[data-command-row]')];
  const setActive=i=>{
    const rows=allRows();if(!rows.length){active=-1;return;}
    active=Math.max(0,Math.min(i,rows.length-1));
    rows.forEach((r,n)=>r.classList.toggle('active',n===active));
    rows[active]?.scrollIntoView({block:'nearest'});
  };
  const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const label=k=>({
    shortcuts:'Recent & Pinned',actions:'Actions',research_agents:'Research Agents',portfolios:'Portfolios',
    missions:'Missions',tasks:'Tasks',programs:'Programs',teams:'Teams',conversations:'Team Chat',
    projects:'Research Projects',reports:'Reports',people:'People',sources:'Sources',annotations:'Annotations',
    entities:'Entities',research:'Research'
  }[k]||k);
  const row=(item,key)=>{
    if(key==='actions')return `<button type="button" class="commandPaletteRow" data-command-row data-create-command="${esc(item.action)}"><span class="commandPaletteIcon">${esc(item.icon||'+')}</span><span><strong>${esc(item.title)}</strong><small>${esc(item.meta||'Create')}</small></span><kbd>↵</kbd></button>`;
    const detailTypes=new Set(['research_agent','portfolio','mission','task','program','decision','action_plan','report']);const detail=detailTypes.has(String(item.type||''))&&item.public_id?` data-object-detail-type="${esc(item.type)}" data-object-detail-id="${esc(item.public_id)}"`:'';return `<a class="commandPaletteRow" data-command-row href="${esc(item.url)}"${detail}><span class="commandPaletteIcon">⌁</span><span><strong>${esc(item.title)}</strong><small>${esc(item.meta||'')}</small>${item.snippet?`<em>${esc(item.snippet)}</em>`:''}</span><kbd>↵</kbd></a>`;
  };
  const render=data=>{
    results.innerHTML='';let html='';
    for(const [key,items] of Object.entries(data.groups||{})){
      if(!Array.isArray(items)||!items.length)continue;
      html+=`<section class="commandPaletteGroup"><h3>${esc(label(key))}</h3><div>${items.map(i=>row(i,key)).join('')}</div></section>`;
    }
    results.innerHTML=html;empty.hidden=!!data.total;active=-1;if(allRows().length)setActive(0);
  };
  const search=async()=>{
    const q=input.value.trim();controller?.abort();controller=new AbortController();
    try{
      const res=await fetch('/api/universal-command.php?q='+encodeURIComponent(q)+'&limit=8',{signal:controller.signal,headers:{'Accept':'application/json'}});
      const json=await res.json();if(!res.ok||json.ok===false)throw new Error();
      render(json.data||{groups:{},total:0});
    }catch(e){if(e.name!=='AbortError'){results.innerHTML='';empty.hidden=false;empty.textContent='Search is unavailable right now.';}}
  };
  const open=()=>{if(!dialog.open)dialog.showModal();requestAnimationFrame(()=>{input.focus();search();});};
  trigger.addEventListener('click',e=>{e.preventDefault();open();});
  trigger.addEventListener('submit',e=>{e.preventDefault();open();});
  dialog.querySelector('[data-command-close]')?.addEventListener('click',close);
  dialog.addEventListener('click',e=>{if(e.target===dialog)close();const create=e.target.closest('[data-create-command]');if(create){const action=create.dataset.createCommand;close();document.querySelector('[data-create-launcher-open]')?.click();requestAnimationFrame(()=>document.querySelector(`[data-create-action="${CSS.escape(action)}"]`)?.click());}});
  dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
  input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(search,140);});
  input.addEventListener('keydown',e=>{
    if(e.key==='ArrowDown'){e.preventDefault();setActive(active+1);}
    else if(e.key==='ArrowUp'){e.preventDefault();setActive(active-1);}
    else if(e.key==='Enter'){const r=allRows()[active];if(r){e.preventDefault();r.click();}}
  });
  document.addEventListener('keydown',e=>{
    if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();dialog.open?close():open();}
    else if(e.key==='/'&&!e.ctrlKey&&!e.metaKey&&!e.altKey&&document.activeElement?.tagName!=='INPUT'&&document.activeElement?.tagName!=='TEXTAREA'){e.preventDefault();open();}
  });
})();