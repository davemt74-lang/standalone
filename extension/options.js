const input=document.querySelector('#apiBase'),status=document.querySelector('#status');
function normalizeBase(raw){const value=raw.trim().replace(/\/$/,'');const u=new URL(value);const loopback=['localhost','127.0.0.1','::1'].includes(u.hostname);if(u.username||u.password||u.hash||u.search)throw new Error('Use a plain Annotated server URL.');if(u.protocol!=='https:'&&!(u.protocol==='http:'&&loopback))throw new Error('Use HTTPS except for localhost development.');return value;}
async function base(){const v=await chrome.storage.sync.get({apiBase:'http://localhost'});return normalizeBase(v.apiBase||'http://localhost');}
chrome.storage.sync.get({apiBase:'http://localhost'},v=>input.value=v.apiBase);
document.querySelector('#save').onclick=()=>{try{const v=normalizeBase(input.value);chrome.storage.sync.set({apiBase:v},()=>status.textContent='Saved. Open the sidebar and click Connect.');}catch(e){status.textContent=e.message;}};
document.querySelector('#openSidebar').onclick=async()=>{try{const [tab]=await chrome.tabs.query({active:true,currentWindow:true});if(tab?.windowId!==undefined)await chrome.sidePanel.open({windowId:tab.windowId});}catch(e){status.textContent=e.message||'Unable to open sidebar.';}};
document.querySelector('#openOnboarding').onclick=async()=>{try{chrome.tabs.create({url:(await base())+'/onboarding.php#extension'});}catch(e){status.textContent=e.message;}};
