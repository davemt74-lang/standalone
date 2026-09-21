const ANNOTATED_HIGHLIGHT_KEY='annotated.highlightColor';
const ANNOTATED_DEFAULT_HIGHLIGHT='#fff6bf';

function annotatedValidHighlight(value){
  return /^#[0-9a-f]{6}$/i.test(String(value||''))?String(value).toLowerCase():ANNOTATED_DEFAULT_HIGHLIGHT;
}
function annotatedApplyHighlight(value,persist=false){
  const color=annotatedValidHighlight(value);
  document.documentElement.style.setProperty('--annotation-highlight',color);
  document.querySelectorAll('[data-annotation-highlight-picker]').forEach(input=>{if(input.value!==color)input.value=color;});
  if(persist){try{localStorage.setItem(ANNOTATED_HIGHLIGHT_KEY,color);}catch{}}
  return color;
}
function annotatedInitHighlight(){
  let stored=ANNOTATED_DEFAULT_HIGHLIGHT;
  try{stored=localStorage.getItem(ANNOTATED_HIGHLIGHT_KEY)||ANNOTATED_DEFAULT_HIGHLIGHT;}catch{}
  annotatedApplyHighlight(stored,false);
}

function annotatedResearchDialog(){
  let dialog=document.querySelector('#annotationResearchDialog');
  if(dialog)return dialog;
  dialog=document.createElement('dialog');
  dialog.id='annotationResearchDialog';
  dialog.className='annotationResearchDialog';
  dialog.innerHTML='<form method="dialog" class="stack"><h3>Add to Research</h3><select data-research-project-select aria-label="Research project"></select><p class="meta" data-research-status></p><div class="inlineActions"><button value="cancel" class="button secondary">Cancel</button><button type="button" data-research-confirm>Add</button></div></form>';
  document.body.appendChild(dialog);
  return dialog;
}
async function annotatedOpenResearch(id,trigger){
  const dialog=annotatedResearchDialog(),select=dialog.querySelector('[data-research-project-select]'),status=dialog.querySelector('[data-research-status]'),confirm=dialog.querySelector('[data-research-confirm]');
  dialog.dataset.annotationId=id;dialog._trigger=trigger||null;select.replaceChildren();status.textContent='Loading projects…';confirm.disabled=true;
  try{
    const r=await fetch('/api/extension.php?action=research_projects');
    const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Unable to load Research projects');
    const projects=j.data?.projects||[];
    if(!projects.length){status.textContent='No writable Research projects yet.';confirm.disabled=true;}
    else{
      for(const project of projects){const option=document.createElement('option');option.value=String(project.public_id||'');option.textContent=String(project.title||'Untitled project');select.appendChild(option);}
      status.textContent='';confirm.disabled=false;
    }
    if(!dialog.open)dialog.showModal();
  }catch(err){status.textContent=err.message||'Unable to load Research projects.';if(!dialog.open)dialog.showModal();}
}
async function annotatedConfirmResearch(){
  const dialog=annotatedResearchDialog(),id=dialog.dataset.annotationId,select=dialog.querySelector('[data-research-project-select]'),status=dialog.querySelector('[data-research-status]'),confirm=dialog.querySelector('[data-research-confirm]');
  if(!id||!select.value)return;
  confirm.disabled=true;status.textContent='Adding…';
  try{
    const r=await fetch('/api/extension.php?action=research_add',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.ANNOTATED_CSRF||''},body:JSON.stringify({project_id:select.value,annotation_id:id})});
    const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Unable to add annotation');
    const trigger=dialog._trigger;if(trigger){trigger.classList.add('active');const label=trigger.querySelector('[data-research-label]');if(label)label.textContent='Added';}
    dialog.close();
  }catch(err){status.textContent=err.message||'Unable to add annotation.';}
  finally{confirm.disabled=false;}
}

function annotatedTeamDialog(){
  let dialog=document.querySelector('#annotationTeamDialog');
  if(dialog)return dialog;
  dialog=document.createElement('dialog');
  dialog.id='annotationTeamDialog';dialog.className='annotationResearchDialog';
  dialog.innerHTML='<form method="dialog" class="stack"><h3>Share with Team</h3><select data-team-conversation-select aria-label="Team conversation"></select><textarea data-team-share-note rows="3" maxlength="5000" placeholder="Add a note (optional)"></textarea><p class="meta" data-team-share-status></p><div class="inlineActions"><button value="cancel" class="button secondary">Cancel</button><button type="button" data-team-share-confirm>Share</button></div></form>';
  document.body.appendChild(dialog);return dialog;
}
async function annotatedOpenTeam(id,trigger){
  const dialog=annotatedTeamDialog(),select=dialog.querySelector('[data-team-conversation-select]'),status=dialog.querySelector('[data-team-share-status]'),confirm=dialog.querySelector('[data-team-share-confirm]'),card=trigger?.closest?.('.annotationPost');
  dialog.dataset.annotationId=id;dialog._trigger=trigger||null;dialog.dataset.visibility=card?.dataset.visibility||'public';dialog.dataset.teamPublicId=card?.dataset.teamPublicId||'';select.replaceChildren();status.textContent='Loading Teams…';confirm.disabled=true;
  try{
    const r=await fetch('/api/conversations.php?action=list',{headers:{Accept:'application/json'}});
    const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Unable to load Team conversations');
    let rows=j.data?.conversations||[];
    if(dialog.dataset.visibility==='team'&&dialog.dataset.teamPublicId)rows=rows.filter(x=>String(x.team_public_id||'')===dialog.dataset.teamPublicId);
    if(dialog.dataset.visibility==='private')rows=[];
    for(const item of rows){const option=document.createElement('option');option.value=String(item.public_id||'');option.textContent=String(item.team_name||'Team');select.appendChild(option);}
    if(!rows.length){status.textContent=dialog.dataset.visibility==='private'?'Private Annotations cannot be shared into Team Chat without changing their visibility.':'No eligible Team conversations are available.';}
    else{status.textContent='The Annotation stays authoritative in Annotated; Team Chat receives a reference only.';confirm.disabled=false;}
    if(!dialog.open)dialog.showModal();
  }catch(err){status.textContent=err.message||'Unable to load Team conversations.';if(!dialog.open)dialog.showModal();}
}
async function annotatedConfirmTeam(){
  const dialog=annotatedTeamDialog(),id=dialog.dataset.annotationId,select=dialog.querySelector('[data-team-conversation-select]'),note=dialog.querySelector('[data-team-share-note]'),status=dialog.querySelector('[data-team-share-status]'),confirm=dialog.querySelector('[data-team-share-confirm]');
  if(!id||!select.value)return;confirm.disabled=true;status.textContent='Sharing…';
  try{
    const body=String(note.value||'').trim()||'Shared an Annotation.';
    const r=await fetch('/api/conversations.php?action=send',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.ANNOTATED_CSRF||''},body:JSON.stringify({conversation:select.value,body,client_message_id:globalThis.crypto?.randomUUID?.()||String(Date.now()),attachments:[{type:'annotation',public_id:id}]})});
    const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Unable to share Annotation');
    const conversation=select.value;note.value='';dialog.close();document.dispatchEvent(new CustomEvent('annotated:team-chat-share-complete',{detail:{conversation,annotation:id}}));
  }catch(err){status.textContent=err.message||'Unable to share Annotation.';}
  finally{confirm.disabled=false;}
}
function annotatedAskAgent(id){
  const detail={prompt:'Review this Annotation as evidence. Summarize what it actually captures, distinguish author commentary from source evidence, note uncertainty or integrity concerns, and suggest the most useful next step. Do not create or change Research without confirmation.',context:[{type:'annotation',public_id:id,label:'Annotation'}],source:'annotation_handoff'};
  if(document.querySelector('[data-agent-chat-canvas]')){document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail,bubbles:true,cancelable:true}));return;}
  try{sessionStorage.setItem('annotated.pendingAgentHandoff',JSON.stringify(detail));}catch{}
  location.href='/home.php';
}

annotatedInitHighlight();

document.addEventListener('input',e=>{
  const picker=e.target.closest?.('[data-annotation-highlight-picker]');if(!picker)return;
  annotatedApplyHighlight(picker.value,true);
});

document.addEventListener('click',async e=>{
  const menu=e.target.closest?.('.annotationHeaderMenu');
  if(menu){document.querySelectorAll('.annotationHeaderMenu[open]').forEach(other=>{if(other!==menu)other.removeAttribute('open');});}
  else document.querySelectorAll('.annotationHeaderMenu[open]').forEach(other=>other.removeAttribute('open'));

  const reset=e.target.closest?.('[data-annotation-highlight-reset]');
  if(reset){annotatedApplyHighlight(ANNOTATED_DEFAULT_HIGHLIGHT,true);return;}

  const expand=e.target.closest?.('[data-annotation-expand]');
  if(expand){
    const wrap=expand.closest('.annotationCaptionWrap'),caption=wrap?.querySelector('.annotationCaption');if(!caption)return;
    const collapsed=caption.classList.toggle('is-collapsed');
    expand.textContent=collapsed?'Read more':'Show less';
    expand.setAttribute('aria-expanded',collapsed?'false':'true');
    return;
  }

  const transcriptToggle=e.target.closest?.('[data-annotation-transcript-toggle]');
  if(transcriptToggle){
    const card=transcriptToggle.closest('.annotationPost'),panelId=transcriptToggle.getAttribute('aria-controls'),panel=panelId?document.getElementById(panelId):card?.querySelector('.annotationTranscriptPanel');
    if(!panel)return;
    const opening=panel.hidden;
    panel.hidden=!opening;
    transcriptToggle.textContent=opening?'Hide transcript':'Show transcript';
    transcriptToggle.setAttribute('aria-expanded',opening?'true':'false');
    transcriptToggle.closest('details')?.removeAttribute('open');
    if(opening)panel.scrollIntoView({behavior:'smooth',block:'nearest'});
    return;
  }

  const intelligenceRefresh=e.target.closest?.('[data-intelligence-refresh]');
  if(intelligenceRefresh){
    const id=String(intelligenceRefresh.dataset.id||'').trim();if(!id)return;
    intelligenceRefresh.disabled=true;const old=intelligenceRefresh.textContent;intelligenceRefresh.textContent='Queuing…';
    try{
      const r=await fetch('/api/annotation-intelligence.php?action=refresh',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.ANNOTATED_CSRF||''},body:JSON.stringify({annotation_id:id})});
      const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Unable to refresh intelligence');
      intelligenceRefresh.textContent=j.data?.queued?'Queued':'Already current';setTimeout(()=>{intelligenceRefresh.textContent=old;},1600);
    }catch(err){intelligenceRefresh.textContent=old;alert(err.message||'Unable to refresh intelligence.');}
    finally{intelligenceRefresh.disabled=false;intelligenceRefresh.closest('details')?.removeAttribute('open');}
    return;
  }

  const copy=e.target.closest?.('[data-copy-annotation-link]');
  if(copy){
    const url=new URL(copy.dataset.url||location.href,location.origin).href;
    try{await navigator.clipboard.writeText(url);const old=copy.textContent;copy.textContent='Copied';setTimeout(()=>{copy.textContent=old;},1200);}catch{}
    copy.closest('details')?.removeAttribute('open');
    return;
  }

  const confirm=e.target.closest?.('[data-research-confirm]');
  if(confirm){await annotatedConfirmResearch();return;}
  const teamConfirm=e.target.closest?.('[data-team-share-confirm]');
  if(teamConfirm){await annotatedConfirmTeam();return;}

  const b=e.target.closest?.('[data-web-annotation-action]');if(!b)return;
  const action=b.dataset.webAnnotationAction,id=b.dataset.id;if(!action||!id)return;
  if(action==='research'){await annotatedOpenResearch(id,b);return;}
  if(action==='team'){await annotatedOpenTeam(id,b);return;}
  if(action==='agent'){annotatedAskAgent(id);return;}

  const apiAction=action==='like'?'annotation_react':action==='save'?'save':null;if(!apiAction)return;
  b.disabled=true;
  try{
    const r=await fetch('/api/extension.php?action='+apiAction,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.ANNOTATED_CSRF||''},body:JSON.stringify({annotation_id:id})});
    const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Request failed');
    if(action==='like'){b.classList.toggle('active',!!j.data.liked);const count=b.querySelector('[data-like-count]');if(count)count.textContent=String(j.data.like_count||0);}
    if(action==='save'){b.classList.toggle('active',!!j.data.saved);const label=b.querySelector('[data-save-label]');if(label)label.textContent=j.data.saved?'Saved':'Save';}
  }catch(err){alert(err.message||'Unable to update annotation.');}
  finally{b.disabled=false;}
});

document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.annotationHeaderMenu[open]').forEach(menu=>menu.removeAttribute('open'));});
