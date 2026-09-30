(()=>{
  const root=document.querySelector('[data-agent-stories]');if(!root)return;
  const payloadEl=root.querySelector('[data-story-payload]');
  let groups=[];try{groups=JSON.parse(payloadEl?.textContent||'[]')||[];}catch{}
  const viewer=root.querySelector('[data-story-viewer]');
  const csrf=document.querySelector('[data-create-launcher]')?.dataset.csrf||document.querySelector('[data-cognitive-feed]')?.dataset.csrf||'';
  const byStory=new Map(),byAgent=new Map();
  groups.forEach(group=>{byAgent.set(String(group.agent_public_id||''),group);(group.stories||[]).forEach((story,index)=>byStory.set(String(story.public_id||''),{group,index}));});
  let currentGroup=null,currentIndex=0,timer=0;

  const post=async(action,id)=>{
    const r=await fetch('/api/research-agent-stories.php?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({public_id:id})});
    if(!r.ok)throw new Error('Story update failed.');
  };
  const setText=(sel,value)=>{const el=viewer?.querySelector(sel);if(el)el.textContent=String(value||'');};
  const avatar=(story)=>{
    const el=viewer?.querySelector('[data-story-avatar]');if(!el)return;el.replaceChildren();
    if(story.profile_image_url){const img=document.createElement('img');img.src=story.profile_image_url;img.alt='';el.appendChild(img);}
    else el.textContent=String(story.agent_name||'?').slice(0,1).toUpperCase();
  };
  const updateProgress=()=>{
    const wrap=viewer?.querySelector('[data-story-progress]');if(!wrap)return;wrap.replaceChildren();
    (currentGroup?.stories||[]).forEach((_,i)=>{const span=document.createElement('span');span.className=i<currentIndex?'done':(i===currentIndex?'active':'');wrap.appendChild(span);});
  };
  const markGroupState=()=>{
    if(!currentGroup)return;
    const unread=(currentGroup.stories||[]).some(s=>!s.viewed_at);
    const card=root.querySelector('[data-story-agent="'+CSS.escape(String(currentGroup.agent_public_id||''))+'"]');
    card?.classList.toggle('isNew',unread);card?.classList.toggle('isViewed',!unread);
  };
  const render=()=>{
    clearTimeout(timer);const story=currentGroup?.stories?.[currentIndex];if(!story||!viewer)return close();
    updateProgress();avatar(story);setText('[data-story-agent-name]',story.agent_name);
    setText('[data-story-meta]',String(story.story_type||'update').replace(/_/g,' ')+' · '+new Date(story.published_at||Date.now()).toLocaleDateString());
    setText('[data-story-title]',story.title);setText('[data-story-body]',story.body);
    const follow=viewer.querySelector('[data-story-follow-up]');if(follow)follow.hidden=!story.parent_story_id;
    const why=viewer.querySelector('[data-story-why]');if(why){const copy=why.querySelector('p');if(copy)copy.textContent=String(story.why_it_matters||'');why.hidden=!story.why_it_matters;}
    const source=viewer.querySelector('[data-story-source]');if(source){source.hidden=!story.primary_url;source.href=story.primary_url||'#';}
    const agent=viewer.querySelector('[data-story-agent-action]');
    if(agent){const own=Number(story.social_rank||1)===0;agent.hidden=!own;agent.href=own?'/home.php?agent='+encodeURIComponent(String(story.conversation_public_id||'')):'#';}
    post('view',story.public_id).catch(()=>{});story.viewed_at=story.viewed_at||new Date().toISOString();markGroupState();
    const url=new URL(location.href);url.searchParams.set('story',story.public_id);history.replaceState({},'',url.pathname+'?'+url.searchParams.toString()+url.hash);
    timer=setTimeout(()=>next(),8000);
  };
  function open(storyId){
    const hit=byStory.get(String(storyId||''));if(!hit||!viewer)return;
    currentGroup=hit.group;currentIndex=hit.index;viewer.showModal();document.body.classList.add('storyViewerOpen');render();
  }
  function close(){
    clearTimeout(timer);if(viewer?.open)viewer.close();document.body.classList.remove('storyViewerOpen');
    const url=new URL(location.href);url.searchParams.delete('story');history.replaceState({},'',url.pathname+(url.searchParams.toString()?'?'+url.searchParams.toString():'')+url.hash);
  }
  function next(){if(!currentGroup)return close();if(currentIndex+1<(currentGroup.stories||[]).length){currentIndex++;render();return;}close();}
  function prev(){if(!currentGroup)return;if(currentIndex>0){currentIndex--;render();}}

  root.addEventListener('click',e=>{
    const openButton=e.target.closest('[data-story-open]');if(openButton){e.preventDefault();open(openButton.dataset.storyOpen);return;}
    if(e.target.closest('[data-story-close]')){e.preventDefault();close();return;}
    if(e.target.closest('[data-story-next]')){e.preventDefault();next();return;}
    if(e.target.closest('[data-story-prev]')){e.preventDefault();prev();return;}
    if(e.target.closest('[data-story-dismiss]')){
      e.preventDefault();const story=currentGroup?.stories?.[currentIndex];if(!story)return;
      post('dismiss',story.public_id).then(()=>{story.dismissed_at=new Date().toISOString();currentGroup.stories.splice(currentIndex,1);if(!currentGroup.stories.length){root.querySelector('[data-story-agent="'+CSS.escape(String(currentGroup.agent_public_id||''))+'"]')?.remove();close();}else{currentIndex=Math.min(currentIndex,currentGroup.stories.length-1);render();}}).catch(()=>{});
    }
  });
  viewer?.addEventListener('click',e=>{if(e.target===viewer)close();});
  document.addEventListener('keydown',e=>{if(!viewer?.open)return;if(e.key==='Escape')close();if(e.key==='ArrowRight')next();if(e.key==='ArrowLeft')prev();});
  const requested=String(root.dataset.openStory||'');if(requested)requestAnimationFrame(()=>open(requested));
})();