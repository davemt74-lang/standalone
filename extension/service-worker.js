async function ensureAnnotatedPageReader(tab){
  if(!tab?.id)return false;
  try{
    const u=new URL(tab.url||'');
    if(!['http:','https:'].includes(u.protocol))return false;
  }catch{return false;}
  try{
    await chrome.tabs.sendMessage(tab.id,{type:'annotated:get-page',includePageText:false});
    return true;
  }catch{}
  try{
    await chrome.scripting.executeScript({target:{tabId:tab.id},files:['content.js']});
    return true;
  }catch{return false;}
}
async function prepareActiveAnnotatedTab(){
  try{
    const [tab]=await chrome.tabs.query({active:true,currentWindow:true});
    if(tab)await ensureAnnotatedPageReader(tab);
  }catch{}
}

chrome.runtime.onInstalled.addListener(details => {
  chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true });
  if(details.reason==='install')chrome.runtime.openOptionsPage();
  prepareActiveAnnotatedTab();
});
chrome.runtime.onStartup.addListener(prepareActiveAnnotatedTab);
prepareActiveAnnotatedTab();

chrome.tabs.onActivated.addListener(async info => {
  try{const tab=await chrome.tabs.get(info.tabId);await ensureAnnotatedPageReader(tab);}catch{}
  chrome.runtime.sendMessage({ type: 'annotated:tab-changed' }).catch(() => {});
});
chrome.tabs.onUpdated.addListener(async (id, info, tab) => {
  if(info.status !== 'complete')return;
  await ensureAnnotatedPageReader(tab||{id});
  chrome.runtime.sendMessage({ type: 'annotated:tab-changed' }).catch(() => {});
});
chrome.runtime.onMessage.addListener((msg,_sender,sendResponse)=>{
  if(msg?.type!=='annotated:get-tab-media-stream-id')return;
  const tabId=Number(msg.tabId);if(!Number.isInteger(tabId)||tabId<1){sendResponse({ok:false,error:'Invalid tab.'});return true;}
  chrome.tabCapture.getMediaStreamId({targetTabId:tabId},streamId=>{const err=chrome.runtime.lastError;if(err||!streamId)sendResponse({ok:false,error:err?.message||'Tab capture is unavailable.'});else sendResponse({ok:true,streamId});});return true;
});
