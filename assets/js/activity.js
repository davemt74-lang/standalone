document.addEventListener('click',e=>{
  const button=e.target.closest('[data-activity-agent]');if(!button)return;
  let context=[];try{context=JSON.parse(button.dataset.context||'[]');}catch{}
  try{sessionStorage.setItem('annotated.pendingAgentHandoff',JSON.stringify({prompt:button.dataset.prompt||'',context,source:'workspace_activity'}));}catch{}
  location.href='/home.php';
});
