let pageFeedState={cursor:null,loading:false,done:false},followingFeedState={cursor:null,loading:false,done:false};
const phase6Items=new Map();let phase6ReadObserver=null,phase6PageMoreObserver=null,phase6FollowingMoreObserver=null,phase6ReadQueue=new Set(),phase6ReadTimer=null;

function phase6VisibilityBadge(a){
  if(a.visibility==='team')return '<span class="visibilityBadge team">Team'+(a.team_name?' · '+esc(a.team_name):'')+'</span>';
  if(a.visibility==='private')return '<span class="visibilityBadge private">Private</span>';
  return '';
}
function phase6SourceBadge(a){
  const integrity=a.integrity||null;
  if(integrity?.label&&integrity.label!=='Source unchanged')return '<span class="badge sourceChange '+(['passage_missing','passage_changed','source_unavailable'].includes(integrity.impact_type)?'warn':'')+'">'+esc(integrity.label)+'</span>';
  if(!a.source_changed)return '';
  const label=a.source_status==='edited'?'Source edited':a.source_status==='updated'?'Source updated':'Source changed';
  return '<span class="badge sourceChange">'+esc(label)+'</span>';
}
function phase6Reason(a){
  const bits=[];if(a.from_followed_user)bits.push('person you follow');if(a.from_followed_source)bits.push('source you follow');
  return bits.length?'<div class="feedReason">From '+esc(bits.join(' + '))+'</div>':'';
}
function phase6AnnotationPostTypeKey(a){
  const valid=new Set(['quote','note','image','image_quote','video','music','podcast','audio','annotation']);
  if(a.post_type&&valid.has(a.post_type))return a.post_type;
  const selected=String(a.selected_text||'').trim(),commentary=String(a.text_commentary||'').trim();
  const sourceType=String(a.source_type||'').toLowerCase(),provider=String(a.media_provider||'').toLowerCase(),url=String(a.canonical_url||'').toLowerCase();
  if(a.capture_type==='video_clip')return 'video';
  if(a.capture_type==='audio_clip'){
    if(sourceType==='podcast'||provider.includes('podcast'))return 'podcast';
    if(['spotify','soundcloud','bandcamp','tidal','deezer','apple_music','music'].includes(provider)||['spotify.com','soundcloud.com','bandcamp.com','music.apple.com','tidal.com'].some(x=>url.includes(x)))return 'music';
    return 'audio';
  }
  if(['image_region','page_region'].includes(a.capture_type))return selected?'image_quote':'image';
  if(a.capture_type==='text')return selected?'quote':commentary?'note':'annotation';
  return commentary?'note':'annotation';
}
function phase6AnnotationType(a){
  const labels={quote:'Quote',note:'Note',image:'Image',image_quote:'Image + Quote',video:'Video',music:'Music',podcast:'Podcast',audio:'Audio',annotation:'Annotation'};
  return labels[phase6AnnotationPostTypeKey(a)]||'Annotation';
}
function phase6AuthorAvatar(a){
  const src=profileImageAbsolute(a.profile_image_url),name=String(a.display_name||a.username||'Annotated user'),initial=name.trim().charAt(0).toUpperCase()||'A';
  return src?'<img class="feedAvatar" src="'+esc(src)+'" alt="'+esc(name)+'">':'<span class="feedAvatar feedAvatarFallback" aria-hidden="true">'+esc(initial)+'</span>';
}
function phase6SourceName(a){
  if(a.source_domain)return String(a.source_domain).replace(/^www\./i,'');
  try{return new URL(a.canonical_url||'').hostname.replace(/^www\./i,'')||'Source';}catch{return 'Source';}
}
function phase13Intelligence(a){
  const intel=a?.intelligence;if(!intel||intel.status!=='ready')return '';
  const summary=String(intel.summary||'').trim(),topics=Array.isArray(intel.topics)?intel.topics:[],entities=Array.isArray(intel.entities)?intel.entities:[],claims=Array.isArray(intel.claims)?intel.claims:[],relations=Array.isArray(intel.relationships)?intel.relationships:[];
  const relLabels={related:'Related',duplicate:'Possible duplicate',corroborates:'Corroborating',conflicts:'Conflicting'};
  const topicHtml=topics.length?'<div class="intelligenceChips">'+topics.slice(0,8).map(x=>'<span>'+esc(String(x))+'</span>').join('')+'</div>':'';
  const entityHtml=entities.length?'<div class="intelligenceSection"><strong>Entities</strong><div class="intelligenceEntities">'+entities.slice(0,10).map(x=>'<span><b>'+esc(String(x?.name||''))+'</b>'+(x?.type?'<small>'+esc(String(x.type))+'</small>':'')+'</span>').join('')+'</div></div>':'';
  const claimHtml=claims.length?'<div class="intelligenceSection"><strong>Claims detected</strong><div class="intelligenceClaims">'+claims.slice(0,5).map(x=>'<div><span class="intelligenceClaimBadge '+(x?.certainty==='explicit'?'explicit':'inferred')+'">'+esc(x?.certainty==='explicit'?'Explicit':'Inferred')+'</span><p>'+esc(String(x?.statement||''))+'</p></div>').join('')+'</div></div>':'';
  const relHtml=relations.length?'<div class="intelligenceSection"><strong>Related evidence</strong><div class="intelligenceRelations">'+relations.slice(0,5).map(x=>'<button type="button" data-action="open-related" data-related="'+esc(String(x?.public_id||''))+'"><span>'+esc(relLabels[x?.relation_type]||'Related')+'</span><b>'+esc(String(x?.text_commentary||x?.selected_text||x?.source_title||'Annotation').slice(0,90))+'</b><small>'+Math.round(Number(x?.confidence||0)*100)+'% analysis confidence</small></button>').join('')+'</div></div>':'';
  const integrity=a?.integrity?.label&&a.integrity.label!=='Source unchanged'?'<div class="intelligenceIntegrity"><strong>Source integrity</strong><span>'+esc(String(a.integrity.label))+'</span></div>':'';
  const confidence=Number(intel.confidence);const meta='AI-assisted derived analysis'+(Number.isFinite(confidence)?' · '+Math.round(confidence*100)+'% analysis confidence':'')+'. Verify conclusions against captured evidence.';
  return '<details class="annotationIntelligence"><summary><span>✦</span><span><small>Derived analysis</small><strong>Intelligence</strong></span><span>⌄</span></summary><div class="annotationIntelligenceBody">'+(summary?'<p class="intelligenceSummary">'+esc(summary)+'</p>':'')+topicHtml+entityHtml+claimHtml+relHtml+integrity+'<div class="intelligenceMeta">'+esc(meta)+'</div></div></details>';
}
const phase6EvidenceCache=new Map();
function phase6EvidenceAbsolute(raw){try{return new URL(String(raw||''),API_BASE+'/').href;}catch{return String(raw||'');}}
async function phase6EvidenceBlob(raw){
  const absolute=phase6EvidenceAbsolute(raw);if(!absolute)return '';
  const cacheKey=absolute+'|'+(token?'auth':'public');if(phase6EvidenceCache.has(cacheKey))return phase6EvidenceCache.get(cacheKey);
  const headers={};if(token)headers.Authorization='Bearer '+token;
  const r=await fetch(absolute,{headers});if(!r.ok)throw new Error('Evidence unavailable');
  const local=URL.createObjectURL(await r.blob());phase6EvidenceCache.set(cacheKey,local);return local;
}
async function phase6HydrateEvidence(root){
  if(!root)return;const nodes=[...root.querySelectorAll('[data-evidence-src]:not([data-evidence-ready])')];
  await Promise.all(nodes.map(async node=>{node.dataset.evidenceReady='1';try{const local=await phase6EvidenceBlob(node.dataset.evidenceSrc);if(local)node.src=local;}catch{node.closest('.evidenceFrame')?.classList.add('evidenceUnavailable');}}));
}
window.addEventListener('unload',()=>{for(const u of phase6EvidenceCache.values())try{URL.revokeObjectURL(u)}catch{}phase6EvidenceCache.clear();});
function phase31TeamDialog(){
  let dialog=document.querySelector('#phase31TeamShareDialog');if(dialog)return dialog;
  dialog=document.createElement('dialog');dialog.id='phase31TeamShareDialog';dialog.className='researchDialog';
  dialog.innerHTML='<form method="dialog"><h3>Share with Team</h3><select data-phase31-team-select></select><textarea data-phase31-team-note rows="3" maxlength="5000" placeholder="Add a note (optional)"></textarea><div class="hint" data-phase31-team-status></div><div class="row"><button value="cancel">Cancel</button><button type="button" class="primary" data-phase31-team-confirm>Share</button></div></form>';
  document.body.appendChild(dialog);dialog.querySelector('[data-phase31-team-confirm]').addEventListener('click',phase31ConfirmTeamShare);return dialog;
}
async function phase31OpenTeamShare(id){
  if(!token){await connect();if(!token)return;}
  const item=phase6Items.get(String(id));if(!item)return;
  const dialog=phase31TeamDialog(),select=dialog.querySelector('[data-phase31-team-select]'),status=dialog.querySelector('[data-phase31-team-status]'),confirm=dialog.querySelector('[data-phase31-team-confirm]');
  dialog.dataset.annotationId=String(id);dialog.dataset.visibility=String(item.visibility||'public');dialog.dataset.teamPublicId=String(item.team_public_id||'');select.replaceChildren();status.textContent='Loading Teams…';confirm.disabled=true;
  try{
    const j=await api('/api/conversations.php?action=list');let rows=j.data?.conversations||[];
    if(dialog.dataset.visibility==='team'&&dialog.dataset.teamPublicId)rows=rows.filter(x=>String(x.team_public_id||'')===dialog.dataset.teamPublicId);
    if(dialog.dataset.visibility==='private')rows=[];
    for(const row of rows){const option=document.createElement('option');option.value=String(row.public_id||'');option.textContent=String(row.team_name||'Team');select.appendChild(option);}
    if(!rows.length)status.textContent=dialog.dataset.visibility==='private'?'Private Annotations cannot be shared to Team Chat.':'No eligible Team conversations are available.';
    else{status.textContent='Shares an Annotated reference, not copied content.';confirm.disabled=false;}
    if(!dialog.open)dialog.showModal();
  }catch(err){status.textContent=err.message||'Unable to load Teams.';if(!dialog.open)dialog.showModal();}
}
async function phase31ConfirmTeamShare(){
  const dialog=phase31TeamDialog(),select=dialog.querySelector('[data-phase31-team-select]'),note=dialog.querySelector('[data-phase31-team-note]'),status=dialog.querySelector('[data-phase31-team-status]'),confirm=dialog.querySelector('[data-phase31-team-confirm]'),id=dialog.dataset.annotationId;
  if(!id||!select.value)return;confirm.disabled=true;status.textContent='Sharing…';
  try{
    await api('/api/conversations.php?action=send',{method:'POST',body:JSON.stringify({conversation:select.value,body:String(note.value||'').trim()||'Shared an Annotation.',client_message_id:crypto.randomUUID(),attachments:[{type:'annotation',public_id:id}]})});
    note.value='';dialog.close();
  }catch(err){status.textContent=err.message||'Unable to share Annotation.';}
  finally{confirm.disabled=false;}
}
function phase31OpenAgent(id){
  if(!API_BASE)return;
  phase34WorkspaceOpen('/home.php?agent_context_type=annotation&agent_context_id='+encodeURIComponent(String(id||'')),{object_type:'annotation',object_public_id:String(id||''),surface:'agent'});
}

function phase6AnnotationCard(a){
  phase6Items.set(String(a.public_id),a);
  const follow=Number(a.is_following)?'Following':'Follow';
  const saved=Number(a.is_saved)?'Saved':'Save';
  const sourceFollow=Number(a.source_following)?'Source followed':'Follow source';
  const postType=phase6AnnotationPostTypeKey(a),type=phase6AnnotationType(a),sourceName=phase6SourceName(a);
  const selected=String(a.selected_text||'').trim();
  const showShot=!!a.screenshot_url&&(['quote','image','image_quote','annotation'].includes(postType));
  const showSelected=!!selected&&!showShot&&(['quote','image_quote','annotation'].includes(postType));
  const showCapturedTranscript=!!selected&&showShot;
  const showMedia=!!a.media_url&&(['video','music','podcast','audio','annotation'].includes(postType));
  const mediaTranscript=a.transcript_status==='ready'&&a.transcript_text?String(a.transcript_text).trim():'';
  const transcriptText=showCapturedTranscript?selected:mediaTranscript;
  const transcriptLabel=showCapturedTranscript?'Captured text transcript':'Media transcript';
  const hasTranscript=!!transcriptText;
  const shot=showShot?'<div class="evidenceFrame"><img data-evidence-src="'+esc(a.screenshot_url)+'" alt="Captured annotation image"></div>':'';
  const transcriptPanel=hasTranscript?'<div class="capturedTextTranscriptPanel" hidden><div class="capturedTextTranscriptLabel">'+esc(transcriptLabel)+'</div><div>'+esc(transcriptText)+'</div></div>':'';
  const media=showMedia?(a.capture_type==='video_clip'?'<div class="evidenceFrame"><video controls data-evidence-src="'+esc(a.media_url)+'"></video></div>':'<div class="evidenceFrame"><audio controls data-evidence-src="'+esc(a.media_url)+'"></audio></div>'):((a.capture_type==='video_clip'||a.capture_type==='audio_clip')&&!a.media_url?'<div class="hint">Media derivative: '+esc(a.media_status||'queued')+'</div>':'');
  const audio=a.audio_url?'<div class="audioCommentaryPost"><span class="hint">Audio commentary</span><audio controls data-evidence-src="'+esc(a.audio_url)+'"></audio></div>':'';
  const transcriptStatus=!hasTranscript&&(a.capture_type==='video_clip'||a.capture_type==='audio_clip')&&a.transcript_status?'<div class="hint">Transcript: '+esc(a.transcript_status)+'</div>':'';
  const time=a.start_seconds!==null&&a.start_seconds!==undefined?'<div class="hint">Clip '+fmtTime(a.start_seconds)+' → '+fmtTime(a.end_seconds)+'</div>':'';
  const version='Captured v'+(Number(a.capture_version_number||0)||'—')+(a.current_version_number&&Number(a.current_version_number)!==Number(a.capture_version_number)?' · current v'+Number(a.current_version_number):'');
  const intelligence=phase13Intelligence(a);
  const unread=token&&!a.is_read?' unread':'';
  const authorButton=a.is_self?'':'<button class="authorFollow" data-action="follow" data-user="'+esc(a.author_public_id)+'">'+follow+'</button>';
  const likeClass=a.viewer_liked?' active':'';
  return '<article class="annotationCard socialPost'+unread+'" data-id="'+esc(a.public_id)+'" data-source="'+esc(a.source_public_id)+'">'+
    phase6Reason(a)+
    '<div class="postHead"><div class="author"><button class="feedAuthorIdentity" data-action="profile" data-username="'+esc(a.username)+'">'+phase6AuthorAvatar(a)+'<span class="feedAuthorText"><strong>'+esc(a.display_name)+'</strong><span>@'+esc(a.username)+'</span></span></button>'+authorButton+'</div><div class="postMeta"><span class="annotationType">'+esc(type)+'</span>'+phase6VisibilityBadge(a)+phase6SourceBadge(a)+'</div></div>'+
    (a.text_commentary?'<p class="postCaption">'+esc(a.text_commentary)+'</p>':'')+
    (showSelected?'<div class="excerpt postQuote">'+esc(selected.slice(0,1000))+'</div>':'')+
    shot+transcriptPanel+media+(a.media_provider?'<div class="provenance">'+esc(a.media_provider==='youtube'?'YouTube':a.media_provider)+(a.media_title?' · '+esc(a.media_title):'')+(a.media_author?' · '+esc(a.media_author):'')+'</div>':'')+audio+transcriptStatus+time+
    '<details class="sourceDetails"><summary><span class="sourceDetailsIcon">↗</span><span><small>Source content</small><strong>'+esc(sourceName)+'</strong></span><span class="sourceChevron">⌄</span></summary><div class="sourceDetailsBody"><div class="sourceDetailsUrl">'+esc(a.canonical_url||'')+'</div><div class="sourceDetailsStats"><span>'+esc(version)+'</span>'+(a.integrity?.label?'<span>'+esc(a.integrity.label)+'</span>':'')+'</div><div class="sourceDetailsActions"><button data-action="source" data-source="'+esc(a.source_public_id)+'">Source page</button><button data-action="source-follow" data-source="'+esc(a.source_public_id)+'">'+sourceFollow+'</button></div></div></details>'+intelligence+
    '<div class="postActions">'+
      '<button class="postAction'+likeClass+'" data-action="like">♥ <span>Like</span> <strong data-like-count>'+Number(a.like_count||0)+'</strong></button>'+
      '<button class="postAction" data-action="comments">💬 <span>Comments</span> <strong data-comment-count>'+Number(a.comment_count||0)+'</strong></button>'+
      '<button class="postAction" data-action="save">🔖 <span data-save-label>'+saved+'</span></button>'+
      '<button class="postAction" data-action="research">▣ <span>Research</span></button>'+
      '<details class="postMore"><summary class="postAction">•••</summary><div class="postMoreMenu"><button data-action="open">Open annotation</button><button data-action="team">Share with Team</button><button data-action="agent">Ask Agent</button>'+(hasTranscript?'<button data-action="toggle-transcript">Show transcript</button>':'')+'<button data-action="report">Report</button>'+(a.start_seconds!==null&&a.start_seconds!==undefined?'<button data-action="seek" data-time="'+Number(a.start_seconds)+'">Jump to clip</button>':'')+'</div></details>'+
    '</div>'+
    '<div class="thread" hidden><div class="threadBody"></div><form class="threadComposer"><div class="replyTarget hint" hidden></div><textarea name="comment" maxlength="5000" placeholder="Add a comment or reply…"></textarea><div class="row"><button type="button" data-action="cancel-reply" hidden>Cancel reply</button><button class="primary" type="submit">Post</button></div></form></div>'+
  '</article>';
}
function phase6RenderSourceIdentity(){
  const source=context?.source;const button=$('#followSource');if(!source){$('#sourceBadge').textContent='';$('#sourceCanonical').textContent='';if(button)button.hidden=true;return;}
  $('#sourceCanonical').textContent=source.canonical_url||context.canonical_url||'';
  const status=source.status&&source.status!=='current'?source.status:'';
  $('#sourceBadge').textContent=status?status.charAt(0).toUpperCase()+status.slice(1)+(source.current_version_number?' · v'+source.current_version_number:''):(source.current_version_number?'v'+source.current_version_number:'');
  if(button){button.hidden=!token;button.textContent=context.source_following?'Following source':'Follow source';button.dataset.source=source.public_id;}
}
async function phase6LoadPage(){
  page=await readPage();if(!page)return;
  $('#title').textContent=page.title||'Current page';try{$('#domain').textContent=new URL(page.canonicalUrl||page.url).hostname}catch{}
  $('#selection').textContent=page.selectedText||(captureMode==='region'?'Choose a region on the page.':'Highlight text on the page to capture it.');
  $('#mediaMode').hidden=!page.mediaType;$('#mediaControls').hidden=!(captureMode==='media'&&page.mediaType);renderMediaMeta();if(page.mediaType)updateClipDuration();
  try{
    const j=await api('/api/extension.php?action=page_context',{method:'POST',body:JSON.stringify({url:page.url,canonical_url:page.canonicalUrl||'',title:page.title,media_type:page.mediaType})});
    context=j.data;$('#count').textContent=(context.annotation_count||0)+' accessible annotations';$('#presenceCount').textContent=(context.presence_count||0)+' here';$('#presence').value=context.presence_mode||$('#presence').value;
    phase6RenderSourceIdentity();await phase6LoadThisPage(true);if(token){heartbeat();loadLiveTeams();}
  }catch(e){$('#count').textContent='Backend unavailable';$('#presenceCount').textContent='';$('#sourceCanonical').textContent='';}
}
async function phase6LoadThisPage(reset=true){
  if(!context?.source?.public_id){$('#feed').innerHTML='<div class="hint">No Annotated source record yet.</div>';$('#pageMore').hidden=true;return;}
  if(pageFeedState.loading)return;if(reset){pageFeedState={cursor:null,loading:false,done:false};$('#feed').innerHTML='';}
  if(pageFeedState.done)return;pageFeedState.loading=true;$('#pageMore').disabled=true;
  try{
    const qs=new URLSearchParams({action:'feed_page',source:context.source.public_id,limit:'15'});if(pageFeedState.cursor)qs.set('cursor',pageFeedState.cursor);
    const j=await api('/api/extension.php?'+qs);const rows=j.data.annotations||[];
    if(reset&&!rows.length)$('#feed').innerHTML='<div class="hint">No annotations you can access on this source yet.</div>';
    else $('#feed').insertAdjacentHTML('beforeend',rows.map(phase6AnnotationCard).join(''));
    await phase6HydrateEvidence($('#feed'));
    pageFeedState.cursor=j.data.next_cursor||null;pageFeedState.done=!pageFeedState.cursor;$('#pageMore').hidden=pageFeedState.done;phase6ObserveCards('#feed');phase6FilterPageFeed();
  }catch(e){if(reset)$('#feed').innerHTML='<div class="hint">Unable to load annotations.</div>';}finally{pageFeedState.loading=false;$('#pageMore').disabled=false;}
}
async function phase6LoadFollowing(reset=true){
  if(!token){$('#followingFeed').innerHTML='<div class="hint">Connect your account to see Following.</div>';$('#followingMore').hidden=true;return;}
  if(followingFeedState.loading)return;if(reset){followingFeedState={cursor:null,loading:false,done:false};$('#followingFeed').innerHTML='';}
  if(followingFeedState.done)return;followingFeedState.loading=true;$('#followingMore').disabled=true;
  try{
    const qs=new URLSearchParams({action:'feed_following',limit:'15'});if(followingFeedState.cursor)qs.set('cursor',followingFeedState.cursor);
    const j=await api('/api/extension.php?'+qs);const rows=j.data.annotations||[];$('#followingUnread').textContent=j.data.unread_count?j.data.unread_count+' unread':'';
    if(reset&&!rows.length)$('#followingFeed').innerHTML='<div class="hint">Follow people or sources from This Page to build this feed.</div>';
    else $('#followingFeed').insertAdjacentHTML('beforeend',rows.map(phase6AnnotationCard).join(''));
    await phase6HydrateEvidence($('#followingFeed'));
    followingFeedState.cursor=j.data.next_cursor||null;followingFeedState.done=!followingFeedState.cursor;$('#followingMore').hidden=followingFeedState.done;phase6ObserveCards('#followingFeed');
  }catch(e){if(reset)$('#followingFeed').innerHTML='<div class="hint">Unable to load Following.</div>';}finally{followingFeedState.loading=false;$('#followingMore').disabled=false;}
}
function phase6FilterPageFeed(){
  const q=($('#pageSearch')?.value||'').trim().toLowerCase();$$('#feed .annotationCard').forEach(card=>{card.hidden=!!q&&!card.textContent.toLowerCase().includes(q);});
}
function phase6ObserveCards(selector){
  if(!token)return;if(!phase6ReadObserver)phase6ReadObserver=new IntersectionObserver(entries=>{for(const entry of entries){if(entry.isIntersecting&&entry.intersectionRatio>=.35){const card=entry.target;if(card.classList.contains('unread')){phase6ReadQueue.add(card.dataset.id);phase6ReadObserver.unobserve(card);card.classList.remove('unread');}}}phase6ScheduleReadFlush();},{threshold:[.35]});
  $$(selector+' .annotationCard.unread').forEach(card=>phase6ReadObserver.observe(card));
}
function phase6ScheduleReadFlush(){if(!phase6ReadQueue.size||phase6ReadTimer)return;phase6ReadTimer=setTimeout(phase6FlushReads,350);}
async function phase6FlushReads(){phase6ReadTimer=null;if(!token||!phase6ReadQueue.size)return;const ids=[...phase6ReadQueue].slice(0,50);ids.forEach(id=>phase6ReadQueue.delete(id));try{const j=await api('/api/extension.php?action=feed_read',{method:'POST',body:JSON.stringify({annotation_ids:ids})});$('#followingUnread').textContent=j.data.unread_count?j.data.unread_count+' unread':'';}catch{}if(phase6ReadQueue.size)phase6ScheduleReadFlush();}
function phase6RenderThread(comments){
  return comments.map(c=>`<div class="threadComment${c.parent_comment_id?' reply':''}" data-comment-id="${esc(c.id)}"><div class="messageHead commentIdentity">${phase6AuthorAvatar(c)}<span><strong>${esc(c.display_name)}</strong> @${esc(c.username)} · ${esc(c.created_at)}</span></div><div>${esc(c.body)}</div><button type="button" data-action="reply" data-comment="${esc(c.id)}" data-author="${esc(c.display_name)}">Reply</button></div>`).join('')||'<div class="hint">No comments yet.</div>';
}
async function phase6LoadThread(card,force=false){
  const thread=card.querySelector('.thread');if(!thread)return;if(!force&&thread.dataset.loaded==='1'){thread.hidden=!thread.hidden;return;}
  const j=await api('/api/extension.php?action=feed_comments&annotation_id='+encodeURIComponent(card.dataset.id));thread.querySelector('.threadBody').innerHTML=phase6RenderThread(j.data.comments||[]);thread.dataset.loaded='1';thread.hidden=false;
}
async function phase6CommentSubmit(e){
  const form=e.target.closest('.threadComposer');if(!form)return;e.preventDefault();if(!token){await connect();if(!token)return;}
  const card=form.closest('.annotationCard'),textarea=form.querySelector('textarea'),body=textarea.value.trim();if(!body)return;
  const payload={annotation_id:card.dataset.id,body};if(form.dataset.parent)payload.parent_comment_id=form.dataset.parent;
  try{const j=await api('/api/extension.php?action=comment',{method:'POST',body:JSON.stringify(payload)});textarea.value='';delete form.dataset.parent;form.querySelector('.replyTarget').hidden=true;form.querySelector('[data-action="cancel-reply"]').hidden=true;card.querySelector('[data-comment-count]').textContent=String(j.data.comment_count||0);await phase6LoadThread(card,true);}catch(err){alert(err.message);}
}
async function phase6ToggleSource(sourceId){
  if(!token){await connect();if(!token)return null;}const j=await api('/api/extension.php?action=watch_source',{method:'POST',body:JSON.stringify({source:sourceId})});const following=!!j.data.following;
  $$('[data-action="source-follow"][data-source="'+CSS.escape(sourceId)+'"]').forEach(b=>b.textContent=following?'Source followed':'Follow source');
  if(context?.source?.public_id===sourceId){context.source_following=following;phase6RenderSourceIdentity();}
  return following;
}
async function toggleHeaderSourceFollow(){const source=$('#followSource')?.dataset.source;if(source)await phase6ToggleSource(source);}
async function phase6OpenContext(card){
  const item=phase6Items.get(String(card.dataset.id));if(!item)return;
  const tab=await activeTab();
  if(tab?.id&&context?.source?.public_id===item.source_public_id){
    try{const result=await chrome.tabs.sendMessage(tab.id,{type:'annotated:open-context',text:item.selected_text||'',time:item.start_seconds!==null?Number(item.start_seconds):null});if(result?.ok)return;}catch{}
  }
  if(item.context_url)chrome.tabs.create({url:item.context_url});
}
async function phase6CardAction(e){
  const b=e.target.closest('button[data-action]');if(!b)return;const card=b.closest('.annotationCard'),id=card?.dataset.id;const action=b.dataset.action;
  try{
    if(action==='comments')return phase6LoadThread(card);
    if(action==='profile'){const username=String(b.dataset.username||'').trim();if(username)return chrome.tabs.create({url:API_BASE+'/'+encodeURIComponent(username)});return;}
    if(action==='toggle-transcript'){const panel=card.querySelector('.capturedTextTranscriptPanel');if(!panel)return;const opening=panel.hidden;panel.hidden=!opening;b.textContent=opening?'Hide transcript':'Show transcript';b.closest('details')?.removeAttribute('open');if(opening)panel.scrollIntoView({behavior:'smooth',block:'nearest'});return;}
    if(action==='reply'){const form=card.querySelector('.threadComposer');form.dataset.parent=b.dataset.comment;const label=form.querySelector('.replyTarget');label.textContent='Replying to '+(b.dataset.author||'comment');label.hidden=false;form.querySelector('[data-action="cancel-reply"]').hidden=false;form.querySelector('textarea').focus();return;}
    if(action==='cancel-reply'){const form=card.querySelector('.threadComposer');delete form.dataset.parent;form.querySelector('.replyTarget').hidden=true;b.hidden=true;return;}
    if(action==='source-follow')return phase6ToggleSource(b.dataset.source);
    if(action==='context')return phase6OpenContext(card);
    if(action==='follow'){if(!token){await connect();if(!token)return;}const j=await api('/api/extension.php?action=follow',{method:'POST',body:JSON.stringify({user_id:b.dataset.user})});$$('[data-action="follow"]').filter(x=>x.dataset.user===b.dataset.user).forEach(x=>x.textContent=j.data.following?'Following':'Follow');return;}
    if(action==='like'){if(!token){await connect();if(!token)return;}const j=await api('/api/extension.php?action=annotation_react',{method:'POST',body:JSON.stringify({annotation_id:id})});b.classList.toggle('active',!!j.data.liked);const count=b.querySelector('[data-like-count]');if(count)count.textContent=String(j.data.like_count||0);return;}
    if(action==='save'){if(!token){await connect();if(!token)return;}const j=await api('/api/extension.php?action=save',{method:'POST',body:JSON.stringify({annotation_id:id})});b.classList.toggle('active',!!j.data.saved);const label=b.querySelector('[data-save-label]');if(label)label.textContent=j.data.saved?'Saved':'Save';return;}
    if(action==='research')return openResearch(id);
    if(action==='team')return phase31OpenTeamShare(id);
    if(action==='agent')return phase31OpenAgent(id);
    if(action==='open')return chrome.tabs.create({url:API_BASE+'/annotation.php?id='+encodeURIComponent(id)});
    if(action==='open-related')return chrome.tabs.create({url:API_BASE+'/annotation.php?id='+encodeURIComponent(String(b.dataset.related||''))});
    if(action==='report')return reportObject('annotation',id);
    if(action==='source')return chrome.tabs.create({url:API_BASE+'/source.php?id='+encodeURIComponent(b.dataset.source)});
    if(action==='seek'){const tab=await activeTab();if(tab?.id)await chrome.tabs.sendMessage(tab.id,{type:'annotated:seek',time:Number(b.dataset.time)});return;}
  }catch(err){alert(err.message);}
}
async function phase6OpenCreate(){
  authClose();hideLanding();document.body.classList.remove('sidebar-booting');
  document.querySelectorAll('nav [role="tab"]').forEach(x=>{x.classList.remove('active');x.setAttribute('aria-selected','false');x.tabIndex=-1;});
  document.querySelectorAll('main>section[role="tabpanel"]').forEach(s=>s.hidden=true);
  const create=$('#create');if(create)create.hidden=false;
  stopLivePoll();await phase6LoadPage();
}
function phase35ActionCard(item){
  const title=String(item?.title||'Action needed'),body=String(item?.body||''),kind=String(item?.kind||'continue'),urgency=String(item?.urgency||'medium'),primary=item?.primary_action||{},href=String(primary?.href||'');
  const ws=item?.workspace||{};
  return '<article class="actionMiniCard urgency-'+esc(urgency)+'"><div class="actionMiniHead"><span>'+esc(kind)+'</span><b>'+esc(urgency)+'</b></div><strong>'+esc(title)+'</strong>'+(body?'<p>'+esc(body)+'</p>':'')+(href?'<button type="button" data-action-open="'+esc(href)+'" data-workspace-team="'+esc(ws.team_public_id||'')+'" data-workspace-research="'+esc(ws.research_public_id||'')+'" data-workspace-object-type="'+esc(ws.object_type||'')+'" data-workspace-object="'+esc(ws.object_public_id||'')+'" data-workspace-agent="'+esc(ws.agent_conversation_public_id||'')+'">'+esc(primary.label||'Open')+'</button>':'')+'</article>';
}
async function phase35LoadActions(){
  const feed=$('#actionCenterMiniFeed'),count=$('#actionCenterMiniCount');if(!feed)return;
  if(!token){feed.innerHTML='<div class="hint">Connect your account to see actions.</div>';if(count)count.hidden=true;return;}
  feed.innerHTML='<div class="hint">Loading actions…</div>';
  try{
    const j=await api('/api/action-center.php?limit=8'),data=j.data||{},rows=data.items||[];
    if(count){count.textContent=String(data.total||0);count.hidden=!(data.total>0);}
    feed.innerHTML=rows.length?rows.map(phase35ActionCard).join(''):'<div class="hint">You’re caught up.</div>';
  }catch(e){feed.innerHTML='<div class="hint">Unable to load actions.</div>';if(count)count.hidden=true;}
}

function phase33ActivityCard(item){
  const surface=String(item?.surface||'workspace'),title=String(item?.title||'Annotated activity'),body=String(item?.body||''),when=String(item?.created_at||''),href=String(item?.href||'');
  const research=(item?.context||[]).find(x=>x?.type==='research')?.public_id||'',team=(item?.context||[]).find(x=>x?.type==='team')?.public_id||'';
  const objectType=String(item?.object?.type||''),objectId=String(item?.object?.public_id||'');
  return '<article class="activityMiniCard"><div class="activityMiniHead"><span>'+esc(surface)+'</span><time>'+esc(when)+'</time></div><strong>'+esc(title)+'</strong>'+(body?'<p>'+esc(body)+'</p>':'')+(href?'<button type="button" data-activity-open="'+esc(href)+'" data-workspace-research="'+esc(research)+'" data-workspace-team="'+esc(team)+'" data-workspace-object-type="'+esc(objectType)+'" data-workspace-object="'+esc(objectId)+'">Open</button>':'')+'</article>';
}
async function phase33LoadActivity(){
  const feed=$('#activityFeed');if(!feed)return;if(!token){feed.innerHTML='<div class="hint">Connect your account to see workspace activity.</div>';return;}
  feed.innerHTML='<div class="hint">Loading activity…</div>';
  try{
    const j=await api('/api/activity.php?limit=35'),rows=j.data?.items||[];
    feed.innerHTML=rows.length?rows.map(phase33ActivityCard).join(''):'<div class="hint">No workspace activity yet.</div>';
  }catch(e){feed.innerHTML='<div class="hint">Unable to load workspace activity.</div>';}
}

async function phase6SwitchTab(btn){
  if(!btn)return;const create=$('#create');if(create)create.hidden=true;
  document.querySelectorAll('nav [role="tab"]').forEach(x=>{const active=x===btn;x.classList.toggle('active',active);x.setAttribute('aria-selected',active?'true':'false');x.tabIndex=active?0:-1;});document.querySelectorAll('main>section[role="tabpanel"]').forEach(s=>s.hidden=s.id!==btn.dataset.tab);
  if(btn.dataset.tab==='page'&&context?.source?.public_id)await phase6LoadThisPage(true);
  if(btn.dataset.tab==='following')phase6LoadFollowing(true);
  if(btn.dataset.tab==='activity'){await phase35LoadActions();await phase33LoadActivity();}
  if(btn.dataset.tab==='search')await loadSearchWorkspace();
  if(btn.dataset.tab==='live')await startLive();else stopLivePoll();
  if(btn.dataset.tab==='research')loadProjects();
}
function phase6SetupInfiniteScroll(){
  if('IntersectionObserver' in window){
    phase6PageMoreObserver=new IntersectionObserver(es=>{if(es.some(e=>e.isIntersecting)&&!pageFeedState.done)phase6LoadThisPage(false);});phase6PageMoreObserver.observe($('#pageMoreSentinel'));
    phase6FollowingMoreObserver=new IntersectionObserver(es=>{if(es.some(e=>e.isIntersecting)&&!followingFeedState.done&&!$('#following').hidden)phase6LoadFollowing(false);});phase6FollowingMoreObserver.observe($('#followingMoreSentinel'));
  }
}
loadPage=phase6LoadPage;loadThisPage=phase6LoadThisPage;loadFollowing=phase6LoadFollowing;annotationCard=phase6AnnotationCard;filterPageFeed=phase6FilterPageFeed;
