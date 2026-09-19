let API_BASE='http://localhost',token='',page=null,context=null,captureMode='text',regionRect=null,pendingResearchAnnotation=null,liveTimer=null,captureOptions={teams:[],projects:[]};
let liveClientSessionId='',liveRoomSelection='public',liveMessageCursor=0,liveEventCursor=0,liveRoomKey='',liveMessageCache=new Map(),liveEventCache=new Map(),liveReplyTo=null,livePollFailures=0,livePollCount=0,pendingLiveSend=null;
let audioBlob=null,audioDataUrl='',mediaRecorder=null,audioStream=null;
let accountUser=null,landingLoadedFor='',landingAssetUrls=[];
const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)];
const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
function normalizeApiBase(raw){const value=String(raw||'').trim().replace(/\/$/,'');const u=new URL(value);const loopback=['localhost','127.0.0.1','::1'].includes(u.hostname);if(u.username||u.password||u.hash||u.search)throw new Error('Invalid Annotated server URL.');if(u.protocol!=='https:'&&!(u.protocol==='http:'&&loopback))throw new Error('Annotated server must use HTTPS.');return value;}
async function discoverAnnotatedServer(){
    try{
        const tabs=await chrome.tabs.query({currentWindow:true});
        const candidates=tabs.filter(tab=>{try{return ['http:','https:'].includes(new URL(tab.url||'').protocol);}catch{return false;}})
            .sort((a,b)=>(b.active?1:0)-(a.active?1:0));
        for(const tab of candidates){
            if(!tab.id)continue;
            try{
                const ready=await ensurePageContentScript(tab);
                if(!ready)continue;
                const probe=await chrome.tabs.sendMessage(tab.id,{type:'annotated:site-probe'});
                if(probe?.ok&&probe.origin){
                    const base=normalizeApiBase(probe.origin);
                    await chrome.storage.sync.set({apiBase:base});
                    return base;
                }
            }catch{}
        }
    }catch{}
    return '';
}
async function settings(){
    const s=await chrome.storage.sync.get({apiBase:''});let configured=String(s.apiBase||'').trim();
    if(configured){try{API_BASE=normalizeApiBase(configured);}catch{configured='';}}
    if(!configured){const discovered=await discoverAnnotatedServer();API_BASE=discovered||'';}
    const a=await chrome.storage.local.get({annotatedToken:'',liveClientSessionId:'',liveRoomSelection:'public'});
    token=a.annotatedToken||'';liveClientSessionId=a.liveClientSessionId||crypto.randomUUID();liveRoomSelection=a.liveRoomSelection||'public';
    if(!a.liveClientSessionId)await chrome.storage.local.set({liveClientSessionId});
}
async function api(path,opts={}){if(!API_BASE){const e=new Error('Annotated website is not connected. Open your Annotated site in a tab or set it in Extension settings.');e.code='SERVER_NOT_CONNECTED';throw e;}const headers={'Content-Type':'application/json',...(opts.headers||{})};if(token)headers.Authorization='Bearer '+token;let r;try{r=await fetch(API_BASE+path,{...opts,headers});}catch(err){const e=new Error('Unable to reach your Annotated website.');e.code='NETWORK_UNAVAILABLE';throw e;}let j={};try{j=await r.json()}catch{}if(!r.ok||j.ok===false){const e=new Error(j.error?.message||j.error?.code||('HTTP '+r.status));e.code=j.error?.code||'HTTP_'+r.status;e.status=r.status;throw e;}return j;}

function clearSidebarViewClasses(){document.body.classList.remove('sidebar-booting','landing-open','auth-open');}
function hideLanding(){const host=$('#landingPanel');if(host)host.hidden=true;document.body.classList.remove('landing-open');}
function authShow(mode='chooser'){
    hideLanding();
    const panel=$('#authPanel');if(panel)panel.hidden=false;
    document.body.classList.remove('sidebar-booting');document.body.classList.add('auth-open');
    $('#authChooser').hidden=mode!=='chooser';
    $('#authLoginForm').hidden=mode!=='login';
    $('#authRegisterForm').hidden=mode!=='register';
    $('#authAccount').hidden=mode!=='account';
    $('#authLoginError').hidden=true;$('#authRegisterError').hidden=true;
    if(mode==='login')setTimeout(()=>$('#authLoginIdentifier')?.focus(),0);
    if(mode==='register')setTimeout(()=>$('#authRegisterName')?.focus(),0);
}
function authClose(){const panel=$('#authPanel');if(panel)panel.hidden=true;document.body.classList.remove('auth-open');}
function authSetError(id,message){const el=$(id);if(!el)return;el.textContent=message||'Unable to continue.';el.hidden=false;}
function accountInitial(user){return String(user?.display_name||user?.username||'A').trim().charAt(0).toUpperCase()||'A';}
function showAccount(user){
    accountUser=user||accountUser;if(!accountUser)return;
    $('#authAccountName').textContent=accountUser.display_name||accountUser.username||'Annotated user';
    $('#authAccountUsername').textContent='@'+(accountUser.username||'user');
    $('#authAccountAvatar').textContent=accountInitial(accountUser);
    $('#authAccountRole').textContent=(accountUser.role==='admin'?'Administrator':'Annotated account')+' · Same account as the website';
    $('#status').textContent='@'+(accountUser.username||'user');
    $('#connect').textContent='Account';
    authShow('account');
}
async function enterWorkspace(){
    authClose();hideLanding();document.body.classList.remove('sidebar-booting');
    await loadPage();
    if(token)await loadNotifications();
}
function landingAbsolute(value){
    if(!value)return value;
    if(value.startsWith('#'))return value;
    if(value==='annotated-hero.svg'||value.startsWith('./'))return chrome.runtime.getURL(value.replace(/^\.\//,''));
    if(!API_BASE)return value;
    try{return new URL(value,API_BASE+'/').href;}catch{return value;}
}
async function loadLandingPage(force=false){
    const host=$('#landingPanel');if(!host)return false;
    if(!force&&landingLoadedFor==='local'&&host.shadowRoot){
        host.hidden=false;authClose();document.body.classList.remove('sidebar-booting');document.body.classList.add('landing-open');return true;
    }
    try{
        const [htmlResponse,appCssResponse,landingCssResponse]=await Promise.all([
            fetch(chrome.runtime.getURL('landing.html')),
            fetch(chrome.runtime.getURL('landing-app.css')),
            fetch(chrome.runtime.getURL('landing.css'))
        ]);
        if(!htmlResponse.ok||!appCssResponse.ok||!landingCssResponse.ok)throw new Error('Local landing assets are unavailable.');
        const [html,appCss,landingCss]=await Promise.all([htmlResponse.text(),appCssResponse.text(),landingCssResponse.text()]);
        const doc=new DOMParser().parseFromString(html,'text/html');
        const wrap=doc.querySelector('.landingBody');
        if(!wrap)throw new Error('Local landing markup is invalid.');
        wrap.querySelectorAll('img[src]').forEach(el=>{
            const raw=el.getAttribute('src')||'';
            if(raw)el.setAttribute('src',chrome.runtime.getURL(raw.replace(/^\.\//,'')));
        });
        wrap.querySelectorAll('a[href]').forEach(el=>{
            const raw=el.getAttribute('href')||'';
            if(raw==='/login.php'){el.dataset.sidebarAuth='login';el.setAttribute('href','#');return;}
            if(raw==='/register.php'){el.dataset.sidebarAuth='register';el.setAttribute('href','#');return;}
            if(raw.startsWith('#'))return;
            if(API_BASE)el.setAttribute('href',landingAbsolute(raw));
            else el.dataset.needsServer='1';
        });
        const shadow=host.shadowRoot||host.attachShadow({mode:'open'});
        shadow.innerHTML='';
        const sheet=new CSSStyleSheet();
        sheet.replaceSync(appCss+'\n'+landingCss+'\n:host{display:block;background:#fff;min-height:100vh}.landingBody{min-height:100vh}.landingHeader{position:sticky;top:0}');
        shadow.adoptedStyleSheets=[sheet];
        shadow.appendChild(wrap);
        if(!shadow.__annotatedLandingBound){
            shadow.__annotatedLandingBound=true;
            shadow.addEventListener('click',e=>{
                const a=e.target.closest?.('a');if(!a)return;
                const auth=a.dataset.sidebarAuth;
                if(auth){e.preventDefault();authShow(auth);return;}
                if(a.dataset.needsServer==='1'){e.preventDefault();chrome.runtime.openOptionsPage();return;}
                const href=a.getAttribute('href')||'';
                if(href.startsWith('#')){
                    e.preventDefault();shadow.querySelector(href)?.scrollIntoView({behavior:'smooth',block:'start'});return;
                }
                if(/^https?:/i.test(href)){e.preventDefault();chrome.tabs.create({url:href});}
            });
        }
        landingLoadedFor='local';
        host.hidden=false;authClose();document.body.classList.remove('sidebar-booting');document.body.classList.add('landing-open');
        return true;
    }catch(e){
        console.error('[Annotated] Local landing page failed to render',e);
        host.hidden=true;document.body.classList.remove('sidebar-booting','landing-open');
        authShow('chooser');
        return false;
    }
}
async function websiteSessionHandoff(){
    if(token)return null;
    let origin='';try{origin=new URL(API_BASE).origin;}catch{return null;}
    const tabs=await chrome.tabs.query({currentWindow:true});
    const candidates=tabs.filter(tab=>{try{return new URL(tab.url||'').origin===origin;}catch{return false;}}).sort((a,b)=>(b.active?1:0)-(a.active?1:0));
    for(const tab of candidates){
        if(!tab.id)continue;
        try{
            const ready=await ensurePageContentScript(tab);
            if(!ready)continue;
            const j=await chrome.tabs.sendMessage(tab.id,{type:'annotated:website-session',origin,extensionId:chrome.runtime.id,clientVersion:chrome.runtime.getManifest().version});
            if(j?.ok&&j.data?.signed_in&&j.data?.token){
                token=j.data.token;accountUser=j.data.user||null;
                await chrome.storage.local.set({annotatedToken:token});
                return accountUser;
            }
        }catch(e){
            console.warn('[Annotated] Website session handoff failed',e);
        }
    }
    return null;
}
async function finishExtensionAuth(j){
    token=j.data.token;accountUser=j.data.user||null;
    await chrome.storage.local.set({annotatedToken:token});
    $('#authLoginPassword').value='';$('#authRegisterPassword').value='';$('#authRegisterConfirm').value='';
    await loadMe(true);
}
async function submitExtensionLogin(e){
    e.preventDefault();const button=$('#authLoginSubmit');button.disabled=true;button.textContent='Logging in…';$('#authLoginError').hidden=true;
    try{
        await settings();
        const j=await api('/api/extension-account.php',{method:'POST',body:JSON.stringify({action:'login',identifier:$('#authLoginIdentifier').value.trim(),password:$('#authLoginPassword').value,client_version:chrome.runtime.getManifest().version})});
        await finishExtensionAuth(j);
    }catch(err){authSetError('#authLoginError',err?.message||'Unable to log in.');}
    finally{button.disabled=false;button.textContent='Log in';}
}
async function submitExtensionRegister(e){
    e.preventDefault();const button=$('#authRegisterSubmit');button.disabled=true;button.textContent='Creating account…';$('#authRegisterError').hidden=true;
    try{
        await settings();
        const j=await api('/api/extension-account.php',{method:'POST',body:JSON.stringify({action:'register',display_name:$('#authRegisterName').value.trim(),username:$('#authRegisterUsername').value.trim(),email:$('#authRegisterEmail').value.trim(),password:$('#authRegisterPassword').value,confirm_password:$('#authRegisterConfirm').value,client_version:chrome.runtime.getManifest().version})});
        await finishExtensionAuth(j);
    }catch(err){authSetError('#authRegisterError',err?.message||'Unable to create account.');}
    finally{button.disabled=false;button.textContent='Create account';}
}
async function extensionLogout(){
    try{await api('/api/extension-account.php',{method:'POST',body:JSON.stringify({action:'logout'})});}catch{}
    token='';accountUser=null;await chrome.storage.local.remove('annotatedToken');
    $('#status').textContent='Not signed in';$('#connect').textContent='Log in';
    await loadLandingPage(true);
}
async function connect(){if(token){showAccount(accountUser);return;}authShow('chooser');}
async function loadMe(showAccountView=false){
    if(!token){$('#status').textContent='Not signed in';$('#connect').textContent='Log in';return false;}
    try{
        const j=await api('/api/extension.php?action=me');accountUser=j.data.user;
        $('#status').textContent='@'+j.data.user.username;$('#connect').textContent='Account';
        $('#presence').value=j.data.user.live_presence_mode||'cloaked';
        const dv=j.data.user.default_annotation_visibility;if(['public','team','private'].includes(dv))$('#visibility').value=dv;
        await loadCaptureOptions();
        if(showAccountView)showAccount(accountUser);
        return true;
    }catch(e){
        const authFailure=e?.status===401&&['SESSION_EXPIRED','AUTH_REQUIRED','INVALID_OR_EXPIRED_CODE'].includes(String(e?.code||''));
        if(authFailure){
            token='';accountUser=null;await chrome.storage.local.remove('annotatedToken');
            $('#status').textContent='Not signed in';$('#connect').textContent='Log in';
        }else{
            console.warn('[Annotated] Account check failed; preserving extension session.',e);
            $('#status').textContent='Connection unavailable';$('#connect').textContent='Account';
        }
        return false;
    }
}

async function loadCaptureOptions(){if(!token)return;try{const j=await api('/api/extension.php?action=capture_options');captureOptions=j.data||{teams:[],projects:[]};const teams=captureOptions.teams||[],projects=captureOptions.projects||[];$('#captureTeam').innerHTML=teams.map(t=>`<option value="${esc(t.public_id)}">${esc(t.name)}</option>`).join('');const teamOption=$('#visibility option[value="team"]');if(teamOption)teamOption.disabled=!teams.length;if($('#visibility').value==='team'&&!teams.length)$('#visibility').value='private';$('#captureProject').innerHTML='<option value="">Not now</option>'+projects.map(p=>`<option value="${esc(p.public_id)}" data-team="${esc(p.team_public_id||'')}">${p.team_public_id?'Team · ':''}${esc(p.title)}</option>`).join('');refreshCaptureCompatibility();}catch{captureOptions={teams:[],projects:[]};}}
function refreshCaptureCompatibility(){const visibility=$('#visibility').value,team=$('#captureTeam').value||'';$('#captureTeamRow').hidden=visibility!=='team';$$('#captureProject option[data-team]').forEach(o=>{const projectTeam=o.dataset.team||'';o.disabled=(visibility==='private'&&!!projectTeam)||(visibility==='team'&&!!projectTeam&&projectTeam!==team);});if($('#captureProject').selectedOptions[0]?.disabled)$('#captureProject').value='';}
function renderMediaMeta(){if(!$('#mediaMeta'))return;if(!page?.mediaType){$('#mediaMeta').innerHTML='';return;}const provider=page.mediaProvider==='youtube'?'YouTube':(page.mediaProvider||'Web media');const title=page.mediaTitle||page.title||'Media';const author=page.mediaAuthor?` · ${esc(page.mediaAuthor)}`:'';const duration=Number.isFinite(Number(page.duration))?` · ${fmtTime(page.duration)}`:'';$('#mediaMeta').innerHTML=`<strong>${esc(title)}</strong>${esc(provider)}${author}${duration}`;}

async function activeTab(){const [tab]=await chrome.tabs.query({active:true,currentWindow:true});return tab;}
async function ensurePageContentScript(tab){
  if(!tab?.id)return false;
  try{
    await chrome.tabs.sendMessage(tab.id,{type:'annotated:get-page',includePageText:false});
    return true;
  }catch{}
  try{
    const u=new URL(tab.url||'');
    if(!['http:','https:'].includes(u.protocol))return false;
    await chrome.scripting.executeScript({target:{tabId:tab.id},files:['content.js']});
    return true;
  }catch(e){
    console.warn('[Annotated] Unable to inject page reader',e);
    return false;
  }
}
async function readPage(includePageText=false){
  const tab=await activeTab();if(!tab?.id)return null;
  const ready=await ensurePageContentScript(tab);
  if(ready){
    try{return await chrome.tabs.sendMessage(tab.id,{type:'annotated:get-page',includePageText});}catch(e){console.warn('[Annotated] Page reader did not respond after retry',e);}
  }
  return {url:tab.url,title:tab.title,selectedText:'',selector:null,selectionRect:null,pageText:'',viewport:null,mediaType:null};
}
async function loadPage(){page=await readPage();if(!page)return;$('#title').textContent=page.title||'Current page';try{$('#domain').textContent=new URL(page.url).hostname}catch{}$('#selection').textContent=page.selectedText||(captureMode==='region'?'Choose a region on the page.':'Highlight text on the page to capture it.');$('#mediaMode').hidden=!page.mediaType;$('#mediaControls').hidden=!(captureMode==='media'&&page.mediaType);renderMediaMeta();if(page.mediaType)updateClipDuration();try{const j=await api('/api/extension.php?action=page_context',{method:'POST',body:JSON.stringify({url:page.url,title:page.title,media_type:page.mediaType})});context=j.data;$('#count').textContent=(context.annotation_count||0)+' annotations';$('#presenceCount').textContent=(context.presence_count||0)+' here';$('#presence').value=context.presence_mode||$('#presence').value;await loadThisPage();if(token){heartbeat();loadLiveTeams();}}catch{$('#count').textContent='Backend unavailable';$('#presenceCount').textContent='';}}
function fmtTime(v){v=Number(v);if(!Number.isFinite(v))return '';const m=Math.floor(v/60),s=Math.floor(v%60);return m+':'+String(s).padStart(2,'0');}
function annotationCard(a){const follow=Number(a.is_following)?'Following':'Follow',saved=Number(a.is_saved)?'Saved':'Save';const badge=a.source_status==='edited'?'<span class="badge">Edited</span>':a.source_status==='updated'?'<span class="badge">Updated</span>':'';const shot=a.screenshot_url?`<img src="${esc(API_BASE+a.screenshot_url)}" alt="Captured source snapshot">`:'';const time=a.start_seconds!==null&&a.start_seconds!==undefined?`<div class="hint">Clip ${fmtTime(a.start_seconds)} → ${fmtTime(a.end_seconds)}</div>`:'';const audio=a.audio_url?`<audio controls src="${esc(API_BASE+a.audio_url)}"></audio>`:'';const media=a.media_url?(a.capture_type==='video_clip'?`<video controls src="${esc(API_BASE+a.media_url)}"></video>`:`<audio controls src="${esc(API_BASE+a.media_url)}"></audio>`):(a.capture_type==='video_clip'||a.capture_type==='audio_clip')?`<div class="hint">Media derivative: ${esc(a.media_status||'queued')}</div>`:'';const provenance=a.media_provider?`<div class="provenance">${esc(a.media_provider==='youtube'?'YouTube':a.media_provider)}${a.media_title?' · '+esc(a.media_title):''}${a.media_author?' · '+esc(a.media_author):''}</div>`:'';const transcript=a.transcript_status==='ready'&&a.transcript_text?`<details class="transcript"><summary>Transcript</summary>${esc(a.transcript_text.slice(0,1200))}</details>`:a.audio_url?`<div class="hint">Transcript: ${esc(a.transcript_status||'queued')}</div>`:'';return `<article class="annotationCard" data-id="${esc(a.public_id)}"><div class="author"><strong>${esc(a.display_name)}</strong><span>@${esc(a.username)}</span>${badge}<button data-action="follow" data-user="${esc(a.author_public_id)}">${follow}</button></div><h4>${esc(a.source_title||'Annotated source')}</h4>${a.text_commentary?`<p>${esc(a.text_commentary)}</p>`:''}${a.selected_text?`<div class="excerpt">${esc(a.selected_text.slice(0,500))}</div>`:''}${shot}${media}${provenance}${audio}${transcript}${time}<div class="cardActions"><button data-action="comment">Comment · ${Number(a.comment_count||0)}</button><button data-action="save">${saved}</button><button data-action="research">Research</button><button data-action="source" data-source="${esc(a.source_public_id)}">Source</button><button data-action="open">Open</button>${a.start_seconds!==null&&a.start_seconds!==undefined?'<button data-action="seek" data-time="'+Number(a.start_seconds)+'">Jump</button>':''}</div></article>`;}
async function loadThisPage(){if(!context?.source?.public_id){$('#feed').innerHTML='<div class="hint">No Annotated source record yet.</div>';return;}try{const j=await api('/api/extension.php?action=feed_page&source='+encodeURIComponent(context.source.public_id));$('#feed').innerHTML=j.data.annotations.length?j.data.annotations.map(annotationCard).join(''):'<div class="hint">No public annotations yet. Be the first.</div>';}catch{$('#feed').innerHTML='<div class="hint">Unable to load annotations.</div>';}}

function filterPageFeed(){const q=($('#pageSearch')?.value||'').trim().toLowerCase();$$('#feed .annotationCard').forEach(card=>{card.hidden=!!q&&!card.textContent.toLowerCase().includes(q);});}

async function loadFollowing(){if(!token){$('#followingFeed').innerHTML='<div class="hint">Log in to see Following.</div>';return;}try{const j=await api('/api/extension.php?action=feed_following');$('#followingFeed').innerHTML=j.data.annotations.length?j.data.annotations.map(annotationCard).join(''):'<div class="hint">Follow researchers from This Page to build this feed.</div>';}catch{$('#followingFeed').innerHTML='<div class="hint">Unable to load Following.</div>';}}
