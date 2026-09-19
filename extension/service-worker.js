chrome.runtime.onInstalled.addListener(details => {
  chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true });
  if(details.reason==='install')chrome.runtime.openOptionsPage();
});

chrome.tabs.onActivated.addListener(() => {
  chrome.runtime.sendMessage({ type: 'annotated:tab-changed' }).catch(() => {});
});
chrome.tabs.onUpdated.addListener((_id, info) => {
  if(info.status !== 'complete')return;
  chrome.runtime.sendMessage({ type: 'annotated:tab-changed' }).catch(() => {});
});
chrome.runtime.onMessage.addListener((msg,_sender,sendResponse)=>{
  if(msg?.type!=='annotated:get-tab-media-stream-id')return;
  const tabId=Number(msg.tabId);if(!Number.isInteger(tabId)||tabId<1){sendResponse({ok:false,error:'Invalid tab.'});return true;}
  chrome.tabCapture.getMediaStreamId({targetTabId:tabId},streamId=>{const err=chrome.runtime.lastError;if(err||!streamId)sendResponse({ok:false,error:err?.message||'Tab capture is unavailable.'});else sendResponse({ok:true,streamId});});return true;
});
