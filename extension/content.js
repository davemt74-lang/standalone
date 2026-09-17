let lastRegion=null;
function mediaInfo(){
  const video=document.querySelector('video');
  const audio=document.querySelector('audio');
  const el=video||audio;
  if(!el)return {mediaType:null,currentTime:null,duration:null};
  return {mediaType:video?'video':'audio',currentTime:Number.isFinite(el.currentTime)?el.currentTime:null,duration:Number.isFinite(el.duration)?el.duration:null};
}
function selectionPayload(){
  const sel=window.getSelection();const text=(sel?.toString()||'').trim();let selector=null,selectionRect=null;
  if(text&&sel.rangeCount){const r=sel.getRangeAt(0);const rect=r.getBoundingClientRect();selector={startOffset:r.startOffset,endOffset:r.endOffset,startNode:r.startContainer.parentElement?.tagName||null,endNode:r.endContainer.parentElement?.tagName||null};selectionRect={x:rect.x,y:rect.y,width:rect.width,height:rect.height};}
  return {url:location.href,title:document.title,selectedText:text,selector,selectionRect,regionRect:lastRegion,pageText:(document.body?.innerText||'').slice(0,150000),viewport:{width:innerWidth,height:innerHeight},...mediaInfo()};
}
function startRegionSelection(){
  if(document.getElementById('__annotated_region_overlay'))return;
  const overlay=document.createElement('div');overlay.id='__annotated_region_overlay';Object.assign(overlay.style,{position:'fixed',inset:'0',zIndex:'2147483647',cursor:'crosshair',background:'rgba(20,20,20,.08)'});
  const box=document.createElement('div');Object.assign(box.style,{position:'fixed',border:'2px solid #111',background:'rgba(255,255,255,.16)',pointerEvents:'none'});overlay.appendChild(box);document.documentElement.appendChild(overlay);
  let start=null;
  const move=e=>{if(!start)return;const x=Math.min(start.x,e.clientX),y=Math.min(start.y,e.clientY),w=Math.abs(e.clientX-start.x),h=Math.abs(e.clientY-start.y);Object.assign(box.style,{left:x+'px',top:y+'px',width:w+'px',height:h+'px'});};
  overlay.addEventListener('mousedown',e=>{start={x:e.clientX,y:e.clientY};move(e);});overlay.addEventListener('mousemove',move);overlay.addEventListener('mouseup',e=>{if(!start)return;lastRegion={x:Math.min(start.x,e.clientX),y:Math.min(start.y,e.clientY),width:Math.abs(e.clientX-start.x),height:Math.abs(e.clientY-start.y)};overlay.remove();chrome.runtime.sendMessage({type:'annotated:region-selected',rect:lastRegion}).catch(()=>{});});overlay.addEventListener('contextmenu',e=>{e.preventDefault();overlay.remove();});
}
chrome.runtime.onMessage.addListener((msg,_sender,sendResponse)=>{
  if(msg?.type==='annotated:get-page'){sendResponse(selectionPayload());return true;}
  if(msg?.type==='annotated:start-region'){startRegionSelection();sendResponse({ok:true});return true;}
  if(msg?.type==='annotated:seek'){const el=document.querySelector('video, audio');if(el&&Number.isFinite(Number(msg.time))){el.currentTime=Number(msg.time);sendResponse({ok:true});}else sendResponse({ok:false});return true;}
});
