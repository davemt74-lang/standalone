let API_BASE='http://localhost',token='',page=null,context=null,captureMode='text',regionRect=null,pendingResearchAnnotation=null,liveTimer=null,captureOptions={teams:[],projects:[]};
let liveClientSessionId='',liveRoomSelection='public',liveMessageCursor=0,liveEventCursor=0,liveRoomKey='',liveMessageCache=new Map(),liveEventCache=new Map(),liveReplyTo=null,livePollFailures=0,livePollCount=0,pendingLiveSend=null;
let audioBlob=null,audioDataUrl='',mediaRecorder=null,audioStream=null;
let accountUser=null,landingLoadedFor='';
// Feed entry points are supplied by sidepanel-feed.js after shared state/helpers load.
let loadPage=null,loadThisPage=null,loadFollowing=null,annotationCard=null,filterPageFeed=null;
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
function authShow(mode='login'){
    hideLanding();
    const panel=$('#authPanel');if(panel)panel.hidden=false;
    document.body.classList.remove('sidebar-booting');document.body.classList.add('auth-open');
    const login=$('#authLoginForm'),register=$('#authRegisterForm'),account=$('#authAccount');
    if(login)login.hidden=mode!=='login';
    if(register)register.hidden=mode!=='register';
    if(account)account.hidden=mode!=='account';
    if($('#authLoginError'))$('#authLoginError').hidden=true;if($('#authRegisterError'))$('#authRegisterError').hidden=true;
    if(mode==='login')setTimeout(()=>$('#authLoginIdentifier')?.focus(),0);
    if(mode==='register')setTimeout(()=>$('#authRegisterName')?.focus(),0);
}
function authClose(){const panel=$('#authPanel');if(panel)panel.hidden=true;document.body.classList.remove('auth-open');}
function authSetError(id,message){const el=$(id);if(!el)return;el.textContent=message||'Unable to continue.';el.hidden=false;}
function accountInitial(user){return String(user?.display_name||user?.username||'A').trim().charAt(0).toUpperCase()||'A';}
function profileImageAbsolute(raw){const value=String(raw||'').trim();if(!value)return '';try{const u=new URL(value,API_BASE?API_BASE+'/':location.href);return ['http:','https:'].includes(u.protocol)?u.href:'';}catch{return '';}}

function showAccount(user){
    accountUser=user||accountUser;if(!accountUser)return;
    $('#authAccountName').textContent=accountUser.display_name||accountUser.username||'Annotated user';
    $('#authAccountUsername').textContent='@'+(accountUser.username||'user');
    const accountAvatar=$('#authAccountAvatar'),accountPhoto=profileImageAbsolute(accountUser.profile_image_url);if(accountAvatar){accountAvatar.replaceChildren();if(accountPhoto){const img=document.createElement('img');img.src=accountPhoto;img.alt=accountUser.display_name||accountUser.username||'Profile photo';accountAvatar.appendChild(img);}else accountAvatar.textContent=accountInitial(accountUser);}
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
        authShow('login');
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
    await loadMe(false);
    await enterWorkspace();
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
    token='';accountUser=null;await chrome.storage.local.remove('annotatedToken');if(typeof phase34WorkspaceClear==='function')await phase34WorkspaceClear();
    $('#status').textContent='Not signed in';$('#connect').textContent='Log in';
    await loadLandingPage(true);
}
async function connect(){if(token){showAccount(accountUser);return;}authShow('login');}
async function loadMe(showAccountView=false){
    if(!token){$('#status').textContent='Not signed in';$('#connect').textContent='Log in';return false;}
    try{
        const j=await api('/api/extension.php?action=me');accountUser=j.data.user;
        $('#status').textContent='@'+j.data.user.username;$('#connect').textContent='Account';
        $('#presence').value=j.data.user.live_presence_mode||'cloaked';
        const dv=j.data.user.default_annotation_visibility;if(['public','team','private'].includes(dv))$('#visibility').value=dv;
        await loadCaptureOptions();
        if(typeof phase34WorkspaceInit==='function')await phase34WorkspaceInit();
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
function fmtTime(v){v=Number(v);if(!Number.isFinite(v))return '';const m=Math.floor(v/60),s=Math.floor(v%60);return m+':'+String(s).padStart(2,'0');}
