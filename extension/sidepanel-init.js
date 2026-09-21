function sidebarBind(id,event,handler){
  const el=document.getElementById(id);
  if(!el){console.warn('[Annotated] Optional sidebar element missing:',id);return false;}
  el.addEventListener(event,handler);
  return true;
}
function sidebarBindClick(id,handler){return sidebarBind(id,'click',handler);}
function sidebarStatusError(message){
  const status=document.getElementById('status');
  if(status)status.textContent=message||'Sidebar error';
}

function initializeSidebarBindings(){
  sidebarBindClick('connect',connect);
  sidebarBindClick('headerCreateNew',phase6OpenCreate);
  sidebarBindClick('createClose',()=>phase6SwitchTab(document.getElementById('tab-page')));
  sidebarBindClick('authLoginBack',()=>loadLandingPage());
  sidebarBindClick('authGoRegister',()=>authShow('register'));
  sidebarBindClick('authRegisterBack',()=>authShow('login'));
  sidebarBind('authLoginForm','submit',submitExtensionLogin);
  sidebarBind('authRegisterForm','submit',submitExtensionRegister);
  sidebarBindClick('authAccountContinue',enterWorkspace);
  sidebarBindClick('authAccountOpenWeb',()=>chrome.tabs.create({url:API_BASE+'/home.php'}));
  sidebarBindClick('authAccountSignOut',extensionLogout);

  sidebarBindClick('refresh',loadPage);
  sidebarBindClick('publish',publish);
  sidebarBindClick('setStart',()=>setClip('start'));
  sidebarBindClick('setEnd',()=>setClip('end'));
  sidebarBindClick('captureClip',async()=>{
    if(clipRecording)return;
    const button=document.getElementById('captureClip');
    if(button)button.disabled=true;
    try{await recordSelectedClip();}
    catch(e){
      const status=document.getElementById('clipCaptureStatus');
      if(status)status.textContent=e?.message||'Unable to capture clip';
      alert(e?.message||'Unable to capture clip');
    }finally{if(button)button.disabled=false;}
  });
  sidebarBind('clipStart','input',updateClipDuration);
  sidebarBind('clipEnd','input',updateClipDuration);
  sidebarBindClick('sendLive',sendLive);
  sidebarBindClick('refreshFollowing',()=>loadFollowing(true));
  sidebarBind('presence','change',heartbeat);
  sidebarBind('visibility','change',refreshCaptureCompatibility);
  sidebarBind('captureTeam','change',refreshCaptureCompatibility);
  sidebarBind('liveRoom','change',changeLiveRoom);

  const feed=document.getElementById('feed');
  if(feed){
    feed.addEventListener('click',phase6CardAction);
    feed.addEventListener('submit',phase6CommentSubmit);
  }
  const followingFeed=document.getElementById('followingFeed');
  if(followingFeed){
    followingFeed.addEventListener('click',phase6CardAction);
    followingFeed.addEventListener('submit',phase6CommentSubmit);
  }

  sidebarBindClick('recordAudio',startAudio);
  sidebarBindClick('stopAudio',stopAudio);
  sidebarBindClick('clearAudio',clearAudio);
  sidebarBindClick('notifications',async()=>{
    await loadNotifications();
    document.getElementById('notificationsDialog')?.showModal();
  });
  sidebarBind('notificationList','click',markNotification);
  sidebarBindClick('markAllNotifications',markAllNotificationsRead);
  sidebarBindClick('openNotificationSettings',()=>chrome.tabs.create({url:API_BASE+'/settings.php#notifications'}));
  sidebarBindClick('openPortfolio',()=>chrome.tabs.create({url:API_BASE+'/research-portfolio.php'}));
  sidebarBindClick('openPublications',()=>chrome.tabs.create({url:API_BASE+'/research-publications.php'}));
  sidebarBindClick('openNetwork',()=>chrome.tabs.create({url:API_BASE+'/research-network.php'}));
  sidebarBindClick('openImpact',()=>chrome.tabs.create({url:API_BASE+'/research-impact.php'}));
  sidebarBindClick('openReviews',()=>chrome.tabs.create({url:API_BASE+'/research-reviews.php'}));
  sidebarBindClick('openDecisionMemory',()=>chrome.tabs.create({url:API_BASE+'/research-outcomes.php'}));
  sidebarBindClick('openRelatedResearch',()=>chrome.tabs.create({url:API_BASE+'/cross-research.php'}));
  sidebarBindClick('openAutomations',()=>chrome.tabs.create({url:API_BASE+'/research-automations.php'}));
  sidebarBind('liveMessages','click',liveMessageAction);
  sidebarBind('liveEvents','click',liveEventAction);
  sidebarBindClick('cancelLiveReply',cancelLiveReply);
  sidebarBindClick('confirmResearch',e=>{
    e.preventDefault();
    confirmResearch();
    document.getElementById('researchDialog')?.close();
  });

  const tabs=[...document.querySelectorAll('nav [role="tab"]')];
  for(const tab of tabs)tab.addEventListener('click',()=>phase6SwitchTab(tab));
  const tablist=document.querySelector('nav[role="tablist"]');
  if(tablist){
    tablist.addEventListener('keydown',e=>{
      if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
      const items=[...tablist.querySelectorAll('[role="tab"]')];
      const current=items.indexOf(document.activeElement);
      if(current<0||!items.length)return;
      e.preventDefault();
      let next=current;
      if(e.key==='ArrowRight')next=(current+1)%items.length;
      if(e.key==='ArrowLeft')next=(current-1+items.length)%items.length;
      if(e.key==='Home')next=0;
      if(e.key==='End')next=items.length-1;
      items[next].focus();
      phase6SwitchTab(items[next]);
    });
  }

  sidebarBind('pageSearch','input',filterPageFeed);
  sidebarBindClick('followSource',toggleHeaderSourceFollow);
  sidebarBindClick('pageMore',()=>loadThisPage(false));
  sidebarBindClick('followingMore',()=>loadFollowing(false));
  sidebarBindClick('searchGo',runSidebarSearch);
  sidebarBind('searchQuery','keydown',e=>{if(e.key==='Enter')runSidebarSearch();});
  sidebarBindClick('saveSearch',saveSidebarSearch);
  sidebarBindClick('openFullSearch',()=>chrome.tabs.create({url:searchWebUrl()}));
  sidebarBind('searchSaved','click',searchListAction);
  sidebarBind('searchRecent','click',searchListAction);
  sidebarBind('searchResults','click',searchResultAction);

  phase6SetupInfiniteScroll();

  for(const button of document.querySelectorAll('.modes button')){
    button.addEventListener('click',async()=>{
      try{
        captureMode=button.dataset.mode;
        for(const item of document.querySelectorAll('.modes button'))item.classList.toggle('active',item===button);
        const controls=document.getElementById('mediaControls');
        if(controls)controls.hidden=!(captureMode==='media'&&page?.mediaType);
        renderMediaMeta();
        if(captureMode==='media')resetClipUpload('Clip will be captured from the active tab when you publish.');
        if(captureMode==='region')await startRegion();
      }catch(e){
        console.error('[Annotated] Capture mode failed',e);
        const selection=document.getElementById('selection');
        if(selection)selection.textContent=e?.message||'Unable to start this capture mode on the current page.';
        sidebarStatusError(e?.message||'Capture mode unavailable');
      }
    });
  }
}

chrome.runtime.onMessage.addListener(async message=>{
  if(message?.type==='annotated:tab-changed')loadPage();
  if(message?.type==='annotated:region-selected'){
    regionRect=message.rect;
    captureMode='region';
    for(const item of document.querySelectorAll('.modes button'))item.classList.toggle('active',item.dataset.mode==='region');
    page=await readPage();
    try{
      const shots=await captureVisible(regionRect);
      const preview=document.getElementById('regionPreview');
      if(preview){preview.innerHTML='<img src="'+shots.target+'" alt="Selected region">';preview.hidden=false;}
      const selection=document.getElementById('selection');
      if(selection)selection.textContent='Selected screen region ready to annotate.';
    }catch(e){console.warn('[Annotated] Region preview failed',e);}
  }
});

(async()=>{
  try{
    initializeSidebarBindings();
    const build=document.getElementById('buildVersion');if(build)build.textContent='v'+chrome.runtime.getManifest().version;
    await settings();
    let signedIn=false;
    if(token)signedIn=await loadMe(false);
    if(signedIn){
      authClose();hideLanding();document.body.classList.remove('sidebar-booting','landing-open');
      await loadPage();
    }else if(!signedIn&&token){
      authClose();hideLanding();document.body.classList.remove('sidebar-booting','landing-open');
      await loadPage();
    }else if(!signedIn){
      const websiteUser=await websiteSessionHandoff();
      if(websiteUser){signedIn=await loadMe(false);if(signedIn)await enterWorkspace();}
      if(!signedIn)await loadLandingPage();
    }
    setInterval(()=>{if(!document.hidden&&token&&context?.source?.public_id)heartbeat();},30000);
  }catch(e){
    console.error('[Annotated] Sidebar startup failed',e);
    document.body.classList.remove('sidebar-booting');
    sidebarStatusError(e?.message||'Sidebar startup failed');
  }
})();
