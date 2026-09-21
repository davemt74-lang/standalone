function activityWorkspacePatch(context=[],objectType='',objectId=''){
  const patch={surface:'activity'};for(const item of context){const type=String(item?.type||''),id=String(item?.public_id||'');if(type==='research'&&id)patch.research_public_id=id;else if(type==='team'&&id)patch.team_public_id=id;}
  if(['annotation','source','claim','finding'].includes(String(objectType||''))&&String(objectId||'')){patch.object_type=String(objectType);patch.object_public_id=String(objectId);}
  return patch;
}
document.addEventListener('click',async e=>{
  const open=e.target.closest('[data-activity-open-context]');
  if(open){
    let context=[];try{context=JSON.parse(open.dataset.context||'[]');}catch{}
    await window.AnnotatedWorkspaceState?.commit(activityWorkspacePatch(context,open.dataset.objectType,open.dataset.objectId));return;
  }
  const button=e.target.closest('[data-activity-agent]');if(!button)return;
  let context=[];try{context=JSON.parse(button.dataset.context||'[]');}catch{}
  await window.AnnotatedWorkspaceState?.commit(activityWorkspacePatch(context));
  try{sessionStorage.setItem('annotated.pendingAgentHandoff',JSON.stringify({prompt:button.dataset.prompt||'',context,source:'workspace_activity'}));}catch{}
  location.href='/home.php';
});
