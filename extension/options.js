const input=document.querySelector('#apiBase'),status=document.querySelector('#status');
function normalizeBase(raw){
  const value=raw.trim().replace(/\/$/,'');
  const u=new URL(value);
  const loopback=['localhost','127.0.0.1','::1'].includes(u.hostname);
  if(u.username||u.password||u.hash||u.search)throw new Error('Use a plain Annotated server URL without credentials, query, or fragment.');
  if(u.protocol!=='https:'&&!(u.protocol==='http:'&&loopback))throw new Error('Annotated server URLs must use HTTPS, except localhost development.');
  return value;
}
chrome.storage.sync.get({apiBase:'http://localhost'},v=>input.value=v.apiBase);
document.querySelector('#save').onclick=()=>{try{const v=normalizeBase(input.value);chrome.storage.sync.set({apiBase:v},()=>status.textContent='Saved.');}catch(e){status.textContent=e.message;}};
