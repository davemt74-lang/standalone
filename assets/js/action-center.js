function actionCenterWorkspace(raw){try{const v=JSON.parse(raw||'{}');return v&&typeof v==='object'?{...v,surface:'actions'}:{surface:'actions'};}catch{return {surface:'actions'};}}
document.addEventListener('click',async e=>{
  const link=e.target.closest('[data-action-center-link]');if(link){e.preventDefault();await window.AnnotatedWorkspaceState?.commit(actionCenterWorkspace(link.dataset.workspace));location.href=link.href;return;}
  const agent=e.target.closest('[data-action-center-agent]');if(!agent)return;
  const workspace=actionCenterWorkspace(agent.dataset.workspace);await window.AnnotatedWorkspaceState?.commit(workspace);
  let context=[];try{context=JSON.parse(agent.dataset.context||'[]');}catch{}
  try{sessionStorage.setItem('annotated.pendingAgentHandoff',JSON.stringify({prompt:agent.dataset.prompt||'',context,source:'action_center'}));}catch{}
  location.href='/home.php';
});
