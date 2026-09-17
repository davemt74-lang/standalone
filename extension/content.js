function selectionPayload(){
  const sel=window.getSelection();
  const text=(sel?.toString()||'').trim();
  let selector=null;
  if(text&&sel.rangeCount){const r=sel.getRangeAt(0);selector={startOffset:r.startOffset,endOffset:r.endOffset};}
  return {url:location.href,title:document.title,selectedText:text,selector,pageText:(document.body?.innerText||'').slice(0,100000)};
}
chrome.runtime.onMessage.addListener((msg,_sender,sendResponse)=>{
  if(msg?.type==='annotated:get-page'){sendResponse(selectionPayload());return true;}
});
