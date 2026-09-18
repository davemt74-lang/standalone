let pageFeedState={cursor:null,loading:false,done:false},followingFeedState={cursor:null,loading:false,done:false};
const phase6Items=new Map();let phase6ReadObserver=null,phase6PageMoreObserver=null,phase6FollowingMoreObserver=null,phase6ReadQueue=new Set(),phase6ReadTimer=null;

function phase6VisibilityBadge(a){
  if(a.visibility==='team')return '<span class="visibilityBadge team">Team'+(a.team_name?' · '+esc(a.team_name):'')+'</span>';
  if(a.visibility==='private')return '<span class="visibilityBadge private">Private</span>';
  return '<span class="visibilityBadge">Public</span>';
}
function phase6SourceBadge(a){
  if(!a.source_changed)return '';
  const label=a.source_status==='edited'?'Source edited':a.source_status==='updated'?'Source updated':'Source changed';
  return '<span class="badge sourceChange">'+esc(label)+'</span>';
}
function phase6Reason(a){
  const bits=[];if(a.from_followed_user)bits.push('person you follow');if(a.from_followed_source)bits.push('source you follow');
  return bits.length?'<div class="feedReason">From '+esc(bits.join(' + '))+'</div>':'';
}
function phase6AnnotationCard(a){
  phase6Items.set(String(a.public_id),a);
  const follow=Number(a.is_following)?'Following':'Follow';
  const saved=Number(a.is_saved)?'Saved':'Save';
  const sourceFollow=Number(a.source_following)?'Source followed':'Follow source';
  const shot=a.screenshot_url?`<img src="${esc(API_BASE+a.screenshot_url)}" alt="Captured source snapshot">`:'';
  const media=a.media_url?(a.capture_type==='video_clip'?`<video controls src="${esc(API_BASE+a.media_url)}"></video>`:`<audio controls src="${esc(API_BASE+a.media_url)}"></audio>`):(a.capture_type==='video_clip'||a.capture_type==='audio_clip')?`<div class="hint">Media derivative: ${esc(a.media_status||'queued')}</div>`:'';
  const audio=a.audio_url?`<audio controls src="${esc(API_BASE+a.audio_url)}"></audio>`:'';
  const transcript=a.transcript_status==='ready'&&a.transcript_text?`<details class="transcript"><summary>Transcript</summary>${esc(String(a.transcript_text).slice(0,1200))}</details>`:a.audio_url?`<div class="hint">Transcript: ${esc(a.transcript_status||'queued')}</div>`:'';
  const time=a.start_seconds!==null&&a.start_seconds!==undefined?`<div class="hint">Clip ${fmtTime(a.start_seconds)} → ${fmtTime(a.end_seconds)}</div>`:'';
  const provenance=a.media_provider?`<div class="provenance">${esc(a.media_provider==='youtube'?'YouTube':a.media_provider)}${a.media_title?' · '+esc(a.media_title):''}${a.media_author?' · '+esc(a.media_author):''}</div>`:'';
  const version=`<div class="sourceVersion">Captured v${Number(a.capture_version_number||0) || '—'}${a.current_version_number&&Number(a.current_version_number)!==Number(a.capture_version_number)?' · current v'+Number(a.current_version_number):''}</div>`;
  const unread=token&&!a.is_read?' unread':'';
  const authorButton=a.is_self?'':`<button data-action="follow" data-user="${esc(a.author_public_id)}">${follow}</button>`;
  return `<article class="annotationCard${unread}" data-id="${esc(a.public_id)}" data-source="${esc(a.source_public_id)}">
    ${phase6Reason(a)}
    <div class="author"><strong>${esc(a.display_name)}</strong><span>@${esc(a.username)}</span>${phase6SourceBadge(a)}${authorButton}</div>
    <div class="cardMeta">${phase6VisibilityBadge(a)}${version}</div>
    <h4>${esc(a.source_title||'Annotated source')}</h4>
    ${a.text_commentary?`<p>${esc(a.text_commentary)}</p>`:''}
    ${a.selected_text?`<div class="excerpt">${esc(String(a.selected_text).slice(0,700))}</div>`:''}
    ${shot}${media}${provenance}${audio}${transcript}${time}
    <div class="cardActions">
      <button data-action="comments">Comments · <span data-comment-count>${Number(a.comment_count||0)}</span></button>
      <button data-action="save">${saved}</button>
      <button data-action="research">Research</button>
      <button data-action="context">Original context</button>
      <button data-action="source" data-source="${esc(a.source_public_id)}">Source</button>
      <button data-action="source-follow" data-source="${esc(a.source_public_id)}">${sourceFollow}</button>
      <button data-action="open">Annotation</button>
      ${a.start_seconds!==null&&a.start_seconds!==undefined?'<button data-action="seek" data-time="'+Number(a.start_seconds)+'">Jump here</button>':''}
    </div>
    <div class="thread" hidden><div class="threadBody"></div>
      <form class="threadComposer"><div class="replyTarget hint" hidden></div><textarea name="comment" maxlength="5000" placeholder="Add a comment or reply…"></textarea><div class="row"><button type="button" data-action="cancel-reply" hidden>Cancel reply</button><button class="primary" type="submit">Post</button></div></form>
    </div>
  </article>`;
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
  return comments.map(c=>`<div class="threadComment${c.parent_comment_id?' reply':''}" data-comment-id="${esc(c.id)}"><div class="messageHead"><strong>${esc(c.display_name)}</strong> @${esc(c.username)} · ${esc(c.created_at)}</div><div>${esc(c.body)}</div><button type="button" data-action="reply" data-comment="${esc(c.id)}" data-author="${esc(c.display_name)}">Reply</button></div>`).join('')||'<div class="hint">No comments yet.</div>';
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
    if(action==='reply'){const form=card.querySelector('.threadComposer');form.dataset.parent=b.dataset.comment;const label=form.querySelector('.replyTarget');label.textContent='Replying to '+(b.dataset.author||'comment');label.hidden=false;form.querySelector('[data-action="cancel-reply"]').hidden=false;form.querySelector('textarea').focus();return;}
    if(action==='cancel-reply'){const form=card.querySelector('.threadComposer');delete form.dataset.parent;form.querySelector('.replyTarget').hidden=true;b.hidden=true;return;}
    if(action==='source-follow')return phase6ToggleSource(b.dataset.source);
    if(action==='context')return phase6OpenContext(card);
    if(action==='follow'){if(!token){await connect();if(!token)return;}const j=await api('/api/extension.php?action=follow',{method:'POST',body:JSON.stringify({user_id:b.dataset.user})});$$('[data-action="follow"]').filter(x=>x.dataset.user===b.dataset.user).forEach(x=>x.textContent=j.data.following?'Following':'Follow');return;}
    if(action==='save'){if(!token){await connect();if(!token)return;}const j=await api('/api/extension.php?action=save',{method:'POST',body:JSON.stringify({annotation_id:id})});b.textContent=j.data.saved?'Saved':'Save';return;}
    if(action==='research')return openResearch(id);
    if(action==='open')return chrome.tabs.create({url:API_BASE+'/annotation.php?id='+encodeURIComponent(id)});
    if(action==='source')return chrome.tabs.create({url:API_BASE+'/source.php?id='+encodeURIComponent(b.dataset.source)});
    if(action==='seek'){const tab=await activeTab();if(tab?.id)await chrome.tabs.sendMessage(tab.id,{type:'annotated:seek',time:Number(b.dataset.time)});return;}
  }catch(err){alert(err.message);}
}
async function phase6SwitchTab(btn){
  $$('nav button').forEach(x=>x.classList.toggle('active',x===btn));$$('main>section').forEach(s=>s.hidden=s.id!==btn.dataset.tab);
  if(btn.dataset.tab==='following')phase6LoadFollowing(true);
  if(btn.dataset.tab==='live'){loadLiveTeams();loadLive();clearInterval(liveTimer);liveTimer=setInterval(loadLive,4000);}else{clearInterval(liveTimer);liveTimer=null;}
  if(btn.dataset.tab==='research')loadProjects();
}
function phase6SetupInfiniteScroll(){
  if('IntersectionObserver' in window){
    phase6PageMoreObserver=new IntersectionObserver(es=>{if(es.some(e=>e.isIntersecting)&&!pageFeedState.done)phase6LoadThisPage(false);});phase6PageMoreObserver.observe($('#pageMoreSentinel'));
    phase6FollowingMoreObserver=new IntersectionObserver(es=>{if(es.some(e=>e.isIntersecting)&&!followingFeedState.done&&!$('#following').hidden)phase6LoadFollowing(false);});phase6FollowingMoreObserver.observe($('#followingMoreSentinel'));
  }
}
loadPage=phase6LoadPage;loadThisPage=phase6LoadThisPage;loadFollowing=phase6LoadFollowing;annotationCard=phase6AnnotationCard;filterPageFeed=phase6FilterPageFeed;
