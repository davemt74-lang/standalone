(()=> {
  const notificationDrawer=document.querySelector('[data-notification-drawer]');
  const notificationOpen=document.querySelector('[data-notification-drawer-open]');
  const notificationBackdrop=document.querySelector('[data-notification-drawer-backdrop]');
  const objectDrawer=document.querySelector('[data-object-detail-drawer]');
  const objectBackdrop=document.querySelector('[data-object-detail-backdrop]');
  const objectBody=objectDrawer?.querySelector('[data-object-detail-body]');
  const supported=new Set(['research_agent','portfolio','mission','task','program','decision','action_plan','report']);
  let objectData=null,objectTab='overview',activityLoaded=false;

  const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const pretty=s=>String(s??'').replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
  const when=s=>{if(!s)return '';const d=new Date(String(s).replace(' ','T')+'Z');return Number.isNaN(d.valueOf())?String(s):d.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});};

  const closeNotifications=()=>{
    if(!notificationDrawer)return;
    notificationDrawer.classList.remove('open');notificationDrawer.setAttribute('aria-hidden','true');
    if(notificationBackdrop)notificationBackdrop.hidden=true;
    notificationOpen?.setAttribute('aria-expanded','false');
  };
  const openNotifications=()=>{
    if(!notificationDrawer)return;
    closeObject(false);
    notificationDrawer.classList.add('open');notificationDrawer.setAttribute('aria-hidden','false');
    if(notificationBackdrop)notificationBackdrop.hidden=false;
    notificationOpen?.setAttribute('aria-expanded','true');
  };
  notificationOpen?.setAttribute('aria-expanded','false');
  notificationOpen?.addEventListener('click',()=>notificationDrawer?.classList.contains('open')?closeNotifications():openNotifications());
  notificationDrawer?.querySelector('[data-notification-drawer-close]')?.addEventListener('click',closeNotifications);
  notificationBackdrop?.addEventListener('click',closeNotifications);

  const renderActivity=items=>{
    const list=notificationDrawer?.querySelector('[data-activity-list]');const loading=notificationDrawer?.querySelector('[data-activity-loading]');
    if(!list)return;if(loading)loading.hidden=true;
    if(!items.length){list.innerHTML='<div class="notificationDrawerEmpty"><strong>No recent activity.</strong><span>Your timeline will appear here.</span></div>';return;}
    list.innerHTML=items.map(item=>{
      const obj=item.object||{},attrs=supported.has(String(obj.type||''))&&obj.public_id?` data-object-detail-type="${esc(obj.type)}" data-object-detail-id="${esc(obj.public_id)}"`:'';
      const href=String(item.href||'#');
      return `<a class="activityDrawerItem" href="${esc(href)}"${attrs}><span class="activityDrawerDot"></span><span><strong>${esc(item.title||'Activity')}</strong>${item.body?`<em>${esc(item.body)}</em>`:''}<small>${esc(when(item.created_at))}</small></span></a>`;
    }).join('');
  };
  const loadActivity=async()=>{
    if(activityLoaded)return;activityLoaded=true;
    try{const r=await fetch('/api/activity.php?limit=60',{headers:{Accept:'application/json'}});const j=await r.json();if(!r.ok||j.ok===false)throw new Error();renderActivity(j.data?.items||[]);}
    catch(e){activityLoaded=false;const l=notificationDrawer?.querySelector('[data-activity-loading]');if(l)l.textContent='Activity is unavailable right now.';}
  };
  notificationDrawer?.addEventListener('click',e=>{
    const tab=e.target.closest('[data-notification-tab]');
    if(tab){
      const name=String(tab.dataset.notificationTab||'notifications');
      notificationDrawer.querySelectorAll('[data-notification-tab]').forEach(b=>{const on=b===tab;b.classList.toggle('active',on);b.setAttribute('aria-selected',on?'true':'false');});
      notificationDrawer.querySelectorAll('[data-notification-panel]').forEach(p=>{const on=p.dataset.notificationPanel===name;p.hidden=!on;p.classList.toggle('active',on);});
      if(name==='activity')loadActivity();return;
    }
  });

  const closeObject=(history=true)=>{
    if(!objectDrawer)return;
    objectDrawer.classList.remove('open');objectDrawer.setAttribute('aria-hidden','true');
    if(objectBackdrop)objectBackdrop.hidden=true;objectData=null;
    if(history){
      const u=new URL(location.href);u.searchParams.delete('drawer_type');u.searchParams.delete('drawer_id');window.history.replaceState({},'',u);
    }
  };
  const detailList=(obj,kind)=>{
    const empty='<div class="universalObjectEmpty">Nothing to show here yet.</div>';
    if(kind==='overview'){
      const pairs=Object.entries(obj.overview||{});if(!pairs.length)return empty;
      return '<dl class="universalObjectOverview">'+pairs.map(([k,v])=>`<div><dt>${esc(pretty(k))}</dt><dd>${esc(v)}</dd></div>`).join('')+'</dl>';
    }
    if(kind==='activity'){
      const rows=obj.activity||[];if(!rows.length)return empty;
      return '<div class="universalObjectFeed">'+rows.map(x=>`<a href="${esc(x.href||'#')}"><strong>${esc(x.title||'Activity')}</strong>${x.body?`<span>${esc(x.body)}</span>`:''}<small>${esc(when(x.created_at))}</small></a>`).join('')+'</div>';
    }
    if(kind==='files'){
      const rows=obj.files||[];if(!rows.length)return empty;
      return '<div class="universalObjectFeed">'+rows.map(x=>`<div><strong>${esc(x.title||'Untitled')}</strong><span>${esc(pretty(x.object_type||'file'))}</span><small>${esc(when(x.updated_at))}</small></div>`).join('')+'</div>';
    }
    if(kind==='people'){
      const rows=obj.people||[];if(!rows.length)return empty;
      return '<div class="universalObjectPeople">'+rows.map(x=>`<a href="/${encodeURIComponent(x.username||'')}"><strong>${esc(x.display_name||x.username||'Member')}</strong><span>@${esc(x.username||'')} · ${esc(pretty(x.role||'member'))}</span></a>`).join('')+'</div>';
    }
    if(kind==='links'){
      const rows=obj.links||[];if(!rows.length)return empty;
      return '<div class="universalObjectFeed">'+rows.map(x=>{const d=x.object||{};return `<a href="${esc(d.url||'#')}" data-object-detail-type="${esc(d.type||'')}" data-object-detail-id="${esc(d.public_id||'')}"><strong>${esc(d.title||'Related object')}</strong><span>${esc(pretty(x.relationship||'related'))} · ${esc(d.type_label||pretty(d.type))}</span></a>`;}).join('')+'</div>';
    }
    if(kind==='agent'){
      const a=obj.agent_context||{},agent=String(a.agent_public_id||'');
      const href=agent?`/home.php?agent=${encodeURIComponent(agent)}&context_type=${encodeURIComponent(a.type||'')}&context_id=${encodeURIComponent(a.public_id||'')}`:'/home.php';
      return `<div class="universalObjectAgent"><span class="eyebrow">AGENT CONTEXT</span><h3>Work with this object</h3><p>${esc(a.prompt||'Open this object with a Research Agent.')}</p><a class="button" href="${esc(href)}">Open with Research Agent</a></div>`;
    }
    return empty;
  };
  const renderObject=()=>{
    if(!objectBody||!objectData)return;
    objectDrawer.querySelectorAll('[data-object-tab]').forEach(b=>b.classList.toggle('active',b.dataset.objectTab===objectTab));
    objectBody.innerHTML=detailList(objectData,objectTab);
  };
  async function openObject(type,id,push=true){
    if(!objectDrawer||!supported.has(type)||!id)return false;
    closeNotifications();objectTab='overview';objectBody.innerHTML='<div class="universalObjectLoading">Loading…</div>';
    objectDrawer.classList.add('open');objectDrawer.setAttribute('aria-hidden','false');if(objectBackdrop)objectBackdrop.hidden=false;
    try{
      const r=await fetch('/api/object-detail.php?type='+encodeURIComponent(type)+'&id='+encodeURIComponent(id),{headers:{Accept:'application/json'}});
      const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error?.message||'Unable to open object.');
      objectData=j.data;const d=objectData.descriptor||{};
      objectDrawer.querySelector('[data-object-detail-type-label]').textContent=d.type_label||pretty(type);
      objectDrawer.querySelector('[data-object-detail-title]').textContent=d.title||'Object detail';
      objectDrawer.querySelector('[data-object-detail-meta]').textContent=[d.status,d.agent_name,d.team_name].filter(Boolean).join(' · ');
      const open=objectDrawer.querySelector('[data-object-detail-open]');open.href=d.url||'#';
      renderObject();
      if(push){const u=new URL(location.href);u.searchParams.set('drawer_type',type);u.searchParams.set('drawer_id',id);window.history.replaceState({},'',u);}
      return true;
    }catch(e){objectBody.innerHTML=`<div class="universalObjectEmpty"><strong>Unable to open object.</strong><span>${esc(e.message||'Please try again.')}</span></div>`;return false;}
  }
  window.AnnotatedObjectDrawer={open:openObject,close:closeObject};
  objectDrawer?.querySelector('[data-object-detail-close]')?.addEventListener('click',()=>closeObject());
  objectBackdrop?.addEventListener('click',()=>closeObject());
  objectDrawer?.addEventListener('click',e=>{const tab=e.target.closest('[data-object-tab]');if(tab){objectTab=String(tab.dataset.objectTab||'overview');renderObject();}});

  document.addEventListener('click',e=>{
    const target=e.target.closest('[data-object-detail-type][data-object-detail-id]');
    if(!target)return;const type=String(target.dataset.objectDetailType||''),id=String(target.dataset.objectDetailId||'');
    if(!supported.has(type)||!id)return;e.preventDefault();const palette=document.querySelector('[data-command-palette]');if(palette?.open)palette.close();openObject(type,id);
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(objectDrawer?.classList.contains('open'))closeObject();else if(notificationDrawer?.classList.contains('open'))closeNotifications();}});
  const u=new URL(location.href),initialType=u.searchParams.get('drawer_type'),initialId=u.searchParams.get('drawer_id');
  if(initialType&&initialId&&supported.has(initialType))openObject(initialType,initialId,false);
})();