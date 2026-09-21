let lastRegion=null,lastTextSelection=null;
function metaContent(name,property=false){const sel=property?`meta[property="${name}"]`:`meta[name="${name}"]`;return document.querySelector(sel)?.content?.trim()||'';}
function youtubeVideoId(){try{const u=new URL(location.href);if(u.hostname==='youtu.be')return u.pathname.slice(1).split('/')[0]||null;if(u.hostname.endsWith('youtube.com')){if(u.searchParams.get('v'))return u.searchParams.get('v');const m=u.pathname.match(/^\/(?:shorts|embed)\/([^/?]+)/);return m?.[1]||null;}}catch{}return null;}
function declaredCanonicalUrl(){try{const link=document.querySelector('link[rel~="canonical"]')?.href||metaContent('og:url',true)||'';if(!link)return location.href;const a=new URL(location.href),b=new URL(link,a.href);const key=h=>h.toLowerCase().replace(/^www\./,'');return ['http:','https:'].includes(b.protocol)&&key(a.hostname)===key(b.hostname)?b.href:location.href;}catch{return location.href;}}
function mediaInfo(){
  const video=document.querySelector('video');const audio=document.querySelector('audio');const el=video||audio;
  if(!el)return {mediaType:null,currentTime:null,duration:null,mediaPaused:null,mediaProvider:null,providerMediaId:null,mediaTitle:null,mediaAuthor:null};
  const youtube=!!youtubeVideoId();const provider=youtube?'youtube':'web';const providerMediaId=youtube?youtubeVideoId():null;
  const mediaTitle=(youtube?(document.querySelector('h1.ytd-watch-metadata yt-formatted-string')?.textContent||metaContent('og:title',true)):metaContent('og:title',true))||document.title;
  const mediaAuthor=youtube?(document.querySelector('#owner #channel-name a')?.textContent||document.querySelector('ytd-channel-name a')?.textContent||metaContent('author')):(metaContent('author')||metaContent('og:site_name',true));
  return {mediaType:video?'video':'audio',currentTime:Number.isFinite(el.currentTime)?el.currentTime:null,duration:Number.isFinite(el.duration)?el.duration:null,mediaPaused:!!el.paused,mediaMuted:!!el.muted,mediaPlaybackRate:Number.isFinite(el.playbackRate)?el.playbackRate:1,mediaProvider:provider,providerMediaId,mediaTitle:String(mediaTitle||'').trim().slice(0,500),mediaAuthor:String(mediaAuthor||'').trim().slice(0,500)};
}
function currentTextSelection(){
  const sel=window.getSelection();const text=(sel?.toString()||'').trim();
  if(!text||!sel?.rangeCount)return null;
  const r=sel.getRangeAt(0),rect=r.getBoundingClientRect();
  const state={selectedText:text,selector:{startOffset:r.startOffset,endOffset:r.endOffset,startNode:r.startContainer.parentElement?.tagName||null,endNode:r.endContainer.parentElement?.tagName||null},selectionRect:{x:rect.x,y:rect.y,width:rect.width,height:rect.height}};
  lastTextSelection=state;
  return state;
}
function rememberTextSelection(){currentTextSelection();}
document.addEventListener('selectionchange',rememberTextSelection,{passive:true});
document.addEventListener('mouseup',rememberTextSelection,{passive:true});
document.addEventListener('keyup',e=>{if(e.key==='Shift'||e.shiftKey)rememberTextSelection();},{passive:true});
function selectionPayload(includePageText=false){
  const live=currentTextSelection(),selection=live||lastTextSelection||{selectedText:'',selector:null,selectionRect:null};
  return {url:location.href,canonicalUrl:declaredCanonicalUrl(),title:document.title,selectedText:selection.selectedText,selector:selection.selector,selectionRect:selection.selectionRect,regionRect:lastRegion,pageText:includePageText?(document.body?.innerText||'').slice(0,150000):'',viewport:{width:innerWidth,height:innerHeight,devicePixelRatio:window.devicePixelRatio||1},...mediaInfo()};
}
function startRegionSelection(){
  if(document.getElementById('__annotated_region_overlay'))return;
  const overlay=document.createElement('div');overlay.id='__annotated_region_overlay';Object.assign(overlay.style,{position:'fixed',inset:'0',zIndex:'2147483647',cursor:'crosshair',background:'rgba(20,20,20,.08)'});
  const box=document.createElement('div');Object.assign(box.style,{position:'fixed',border:'2px solid #111',background:'rgba(255,255,255,.16)',pointerEvents:'none'});overlay.appendChild(box);document.documentElement.appendChild(overlay);
  let start=null;const move=e=>{if(!start)return;const x=Math.min(start.x,e.clientX),y=Math.min(start.y,e.clientY),w=Math.abs(e.clientX-start.x),h=Math.abs(e.clientY-start.y);Object.assign(box.style,{left:x+'px',top:y+'px',width:w+'px',height:h+'px'});};
  overlay.addEventListener('mousedown',e=>{start={x:e.clientX,y:e.clientY};move(e);});overlay.addEventListener('mousemove',move);overlay.addEventListener('mouseup',e=>{if(!start)return;lastRegion={x:Math.min(start.x,e.clientX),y:Math.min(start.y,e.clientY),width:Math.abs(e.clientX-start.x),height:Math.abs(e.clientY-start.y)};overlay.remove();chrome.runtime.sendMessage({type:'annotated:region-selected',rect:lastRegion}).catch(()=>{});});overlay.addEventListener('contextmenu',e=>{e.preventDefault();overlay.remove();});
}
function openTextContext(text){const needle=String(text||'').trim().replace(/\s+/g,' ').slice(0,300);if(!needle)return false;try{const found=window.find(needle,false,false,true,false,false,false);if(found){const sel=window.getSelection();if(sel?.rangeCount){const el=sel.getRangeAt(0).startContainer.parentElement;el?.scrollIntoView({block:'center',behavior:'smooth'});}return true;}}catch{}return false;}
async function mediaControl(msg){const el=document.querySelector('video, audio');if(!el)return {ok:false};try{if(Number.isFinite(Number(msg.time)))el.currentTime=Math.max(0,Number(msg.time));if(typeof msg.muted==='boolean')el.muted=msg.muted;if(Number.isFinite(Number(msg.playbackRate))&&Number(msg.playbackRate)>0)el.playbackRate=Number(msg.playbackRate);if(msg.command==='play')await el.play();else if(msg.command==='pause')el.pause();return {ok:true,...mediaInfo()};}catch(e){return {ok:false,error:e?.message||'Media control failed'};}}
function looksLikeAnnotatedSite(){
  const title=(document.title||'').toLowerCase();
  const branded=!!document.querySelector('.appShellBrand,.landingBrand,[data-annotated-app]');
  return branded||title.includes('annotated');
}
async function annotatedSiteProbe(){
  if(!looksLikeAnnotatedSite())return {ok:false};
  try{
    const r=await fetch('/api/extension.php?action=page_context&url='+encodeURIComponent(location.origin+'/'),{
      credentials:'same-origin',
      headers:{Accept:'application/json'}
    });
    const j=await r.json().catch(()=>null);
    const d=j?.data;
    if(!r.ok||!j?.ok||!d||!Object.prototype.hasOwnProperty.call(d,'annotation_count')||!Object.prototype.hasOwnProperty.call(d,'authenticated'))return {ok:false};
    return {ok:true,origin:location.origin,authenticated:!!d.authenticated};
  }catch{return {ok:false};}
}
async function annotatedWebsiteSession(msg){
  try{
    const expected=String(msg?.origin||'').replace(/\/$/,'');
    if(!expected||location.origin!==expected)return {ok:false,error:'ORIGIN_MISMATCH'};
    const r=await fetch('/api/extension-web-session.php',{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({extension_id:String(msg.extensionId||''),client_version:String(msg.clientVersion||'')})
    });
    const j=await r.json().catch(()=>({ok:false,error:{message:'Invalid website session response.'}}));
    return j;
  }catch(e){return {ok:false,error:{message:e?.message||'Unable to read Annotated website session.'}};}
}
chrome.runtime.onMessage.addListener((msg,_sender,sendResponse)=>{
  if(msg?.type==='annotated:site-probe'){annotatedSiteProbe().then(sendResponse);return true;}
  if(msg?.type==='annotated:website-session'){annotatedWebsiteSession(msg).then(sendResponse);return true;}
  if(msg?.type==='annotated:get-page'){sendResponse(selectionPayload(msg.includePageText===true));return true;}
  if(msg?.type==='annotated:start-region'){startRegionSelection();sendResponse({ok:true});return true;}
  if(msg?.type==='annotated:seek'){mediaControl({time:Number(msg.time)}).then(sendResponse);return true;}
  if(msg?.type==='annotated:media-control'){mediaControl(msg).then(sendResponse);return true;}
  if(msg?.type==='annotated:open-context'){(async()=>{let ok=false;if(Number.isFinite(Number(msg.time)))ok=!!(await mediaControl({time:Number(msg.time)})).ok;if(!ok&&msg.text)ok=openTextContext(msg.text);sendResponse({ok});})();return true;}
});
