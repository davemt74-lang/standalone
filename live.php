<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/live.php';
$u=require_user($pdo);$sourcePublic=(string)($_GET['id']??$_POST['source']??'');
if($sourcePublic===''){
    $q=$pdo->query("SELECT s.public_id,s.title,s.domain,COUNT(lm.id) message_count,MAX(lm.created_at) last_activity FROM live_messages lm JOIN sources s ON s.id=lm.source_id WHERE lm.room_type='public' AND lm.deleted_at IS NULL GROUP BY s.id,s.public_id,s.title,s.domain ORDER BY last_activity DESC LIMIT 30");
    $active=$q->fetchAll();
    $q=$pdo->query("SELECT s.public_id,s.title,s.domain,MAX(a.published_at) activity FROM sources s JOIN annotations a ON a.source_id=s.id WHERE a.visibility='public' AND a.status='published' GROUP BY s.id,s.public_id,s.title,s.domain ORDER BY activity DESC LIMIT 20");
    $sources=$q->fetchAll();
    $rooms=live_rooms_for_user($pdo,$u);
    header('Cache-Control: private, no-store');header('Vary: Cookie');
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Live · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
    <main class="panel">
      <div class="pageTitle"><span class="eyebrow">LIVE</span><h1>Discuss the web while it happens.</h1><p>Open a public source room, then switch into a Team or Research room when that source belongs to one of your private workspaces.</p></div>
      <div class="sectionHeadWeb"><div><span class="eyebrow">RECENT ACTIVITY</span><h2>Active source rooms</h2></div></div>
      <div class="sourceGrid"><?php foreach($active as $s):?><a class="card sourceCard" href="/live.php?id=<?=h($s['public_id'])?>"><div class="meta"><?=h($s['domain'])?> · <?=h((string)$s['message_count'])?> messages</div><h3><?=h($s['title']?:$s['domain'])?></h3><p class="meta">Last activity <?=h((string)$s['last_activity'])?></p></a><?php endforeach?><?php if(!$active):?><div class="card empty">No public Live conversations yet. Start one from a source below.</div><?php endif?></div>
      <div class="sectionHeadWeb"><div><span class="eyebrow">START A ROOM</span><h2>Recently annotated sources</h2></div></div>
      <div class="sourceGrid"><?php foreach($sources as $s):?><a class="card sourceCard" href="/live.php?id=<?=h($s['public_id'])?>"><div class="meta"><?=h($s['domain'])?></div><h3><?=h($s['title']?:$s['domain'])?></h3><p>Open Live</p></a><?php endforeach?></div>
      <div class="card"><h2>Your private Live access</h2><p><strong><?=h((string)count($rooms['teams']))?></strong> Teams · <strong><?=h((string)count($rooms['projects']))?></strong> Research projects</p><p class="meta">Private room choices appear automatically after you open a source.</p></div>
    </main></body></html><?php exit;
}
$source=source_access($pdo,$sourcePublic,$u);if(!$source){http_response_code(404);exit('Source not found.');}
function web_live_room(PDO $pdo,array $u,string $sourcePublic): ?array {return live_room_scope($pdo,$u,$sourcePublic,(string)($_REQUEST['room_type']??'public'),isset($_REQUEST['room_id'])?(string)$_REQUEST['room_id']:null,false);}
if(isset($_GET['ajax'])){
    header('Content-Type: application/json; charset=utf-8');$room=web_live_room($pdo,$u,$sourcePublic);if(!$room)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);
    $messages=live_message_rows($pdo,$u,$room,max(0,(int)($_GET['after_message']??0)),100);$events=live_event_rows($pdo,$u,$room,max(0,(int)($_GET['after_event']??0)),60);$presence=live_presence_rows($pdo,$u,$room,(string)$u['live_presence_mode']);
    json_response(['ok'=>true,'data'=>['messages'=>$messages['messages'],'message_cursor'=>$messages['cursor'],'events'=>$events['events'],'event_cursor'=>$events['cursor'],'presence'=>$presence,'invitees'=>live_room_invite_candidates($pdo,$u,$room,8),'room'=>['type'=>$room['room_type'],'id'=>$room['room_public_id'],'name'=>$room['room_name'],'can_post'=>$room['can_post'],'can_moderate'=>$room['can_moderate']]]]);
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'');$room=web_live_room($pdo,$u,$sourcePublic);if(!$room)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);
    try{
        if($action==='presence'){$data=live_presence_touch($pdo,$u,$room,(string)($_POST['client_session_id']??''),(string)($_POST['mode']??'cloaked'));json_response(['ok'=>true,'data'=>$data]);}
        if($action==='message'){$write=live_room_scope($pdo,$u,$sourcePublic,$room['room_type'],$room['room_public_id'],true);if(!$write)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);$created=live_message_create($pdo,$u,$write,(string)($_POST['body']??''),null,(string)($_POST['client_message_id']??''),(string)($_POST['client_session_id']??''),!empty($_POST['reveal_identity']),isset($_POST['parent_message_id'])?(string)$_POST['parent_message_id']:null);json_response(['ok'=>true,'data'=>$created],$created['created']?201:200);}
        if($action==='react'){$active=live_message_react($pdo,$u,$room,(string)($_POST['message_id']??''));json_response(['ok'=>true,'data'=>['active'=>$active]]);}
        if($action==='pin'){$pinned=live_message_pin($pdo,$u,$room,(string)($_POST['message_id']??''));json_response(['ok'=>true,'data'=>['pinned'=>$pinned]]);}
        if($action==='delete'){$ok=live_message_delete($pdo,$u,$room,(string)($_POST['message_id']??''));if(!$ok)json_response(['ok'=>false,'error'=>['code'=>'MESSAGE_NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['deleted'=>true]]);}
        if($action==='invite'){rate_limit_api_or_429($pdo,'live-room-invite','user:'.$u['id'],120,3600);$created=live_room_invite($pdo,$u,$room,(string)($_POST['username']??''));json_response(['ok'=>true,'data'=>['invited'=>true,'notification_created'=>$created]]);}
    }catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_LIVE_REQUEST','message'=>$e->getMessage()]],422);}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'LIVE_FORBIDDEN','message'=>$e->getMessage()]],403);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}
$rooms=live_rooms_for_user($pdo,$u);$title=(string)($source['title']?:$source['canonical_url']);header('Cache-Control: private, no-store');header('Vary: Cookie');
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Live · <?=h($title)?> · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css?v=76.0"></head><body data-workspace-surface="live">
<main class="liveRoomPage">
  <a class="liveRoomBack" href="/live.php">← <span>Live Rooms</span></a>

  <header class="liveRoomTitle">
    <div class="liveRoomTitleCopy">
      <div class="liveRoomTitleLine"><h1><?=h($title)?></h1><span class="liveRoomScopeBadge" id="roomScopeBadge">Public</span></div>
      <p><?=h((string)($source['domain']??parse_url((string)$source['canonical_url'],PHP_URL_HOST)??''))?> · Live discussion around this source</p>
    </div>
    <div class="liveRoomTitleActions">
      <a class="button secondary" href="/source.php?id=<?=h($source['public_id'])?>">View source</a>
      <button class="button secondary" type="button" id="shareRoom">Share</button>
    </div>
  </header>

  <section class="liveRoomControls" aria-label="Live room controls">
    <label><span>Room</span><select id="room"><option value="public">Public · this source</option><?php if($rooms['teams']):?><optgroup label="Teams"><?php foreach($rooms['teams'] as $t):?><option value="team:<?=h($t['public_id'])?>">Team · <?=h($t['name'])?></option><?php endforeach?></optgroup><?php endif?><?php if($rooms['projects']):?><optgroup label="Research"><?php foreach($rooms['projects'] as $p):?><option value="project:<?=h($p['public_id'])?>">Research · <?=h($p['title'])?><?=$p['can_post']?'':' · read only'?></option><?php endforeach?></optgroup><?php endif?></select></label>
    <label><span>Presence</span><select id="presence"><option value="visible">Visible</option><option value="team_only">Team only</option><option value="cloaked">Cloaked</option><option value="off">Off</option></select></label>
    <div class="liveRoomConnection"><strong id="connection">Connecting…</strong><span id="stats">Checking room…</span></div>
  </section>

  <section class="liveRoomWorkspace">
    <section class="liveRoomConversation">
      <div class="liveRoomConversationHead"><span></span><strong>Today</strong><span></span></div>
      <div id="events" class="liveRoomEvents" aria-label="Room activity"></div>
      <div id="messages" class="liveRoomMessages" role="log" aria-live="polite"></div>
    </section>

    <aside class="liveRoomSide">
      <section class="liveRoomPanel">
        <header><div><span class="eyebrow">ROOM</span><h2>Participants <span id="participantCount"></span></h2></div></header>
        <div id="people" class="liveRoomParticipants"><div class="liveRoomPanelEmpty">Checking presence…</div></div>
      </section>
      <section class="liveRoomPanel">
        <header><div><span class="eyebrow">INVITE</span><h2>Invite to this room</h2></div></header>
        <label class="liveRoomInviteSearch"><span aria-hidden="true">⌕</span><input id="inviteSearch" type="search" placeholder="Search people…" aria-label="Search people to invite"></label>
        <div id="inviteList" class="liveRoomInviteList"><div class="liveRoomPanelEmpty">Finding people with access…</div></div>
      </section>
    </aside>

    <form id="composer" class="liveRoomComposer">
      <div id="reply" class="liveRoomReply" hidden></div>
      <div class="liveRoomComposerMain"><span class="liveRoomAttach" aria-hidden="true">⌕</span><textarea id="body" maxlength="3000" placeholder="Message this Live room…" required></textarea></div>
      <div class="liveRoomComposerFoot">
        <label class="checkRow"><input type="checkbox" id="reveal"> Reveal my identity for this message</label>
        <div class="liveRoomComposerActions"><button type="button" id="cancelReply" class="button secondary" hidden>Cancel reply</button><button id="send">Send</button></div>
      </div>
      <p class="liveRoomPrivacyNote">Cloaked messages do not expose your profile identity. Team-only identity is shown only to shared-team viewers.</p>
    </form>
  </section>
</main>
<script>
const csrf=<?=json_encode(csrf_token())?>,source=<?=json_encode($source['public_id'])?>,defaultPresence=<?=json_encode($u['live_presence_mode'])?>,requestedRoom=<?=json_encode((($_GET['room_type']??'public')==='team'?'team:':(($_GET['room_type']??'public')==='project'?'project:':'' )).(string)($_GET['room_id']??''))?>;
let client=localStorage.getItem('annotated_live_client')||crypto.randomUUID(),roomSelection=(requestedRoom&&requestedRoom!=='public'?requestedRoom:(localStorage.getItem('annotated_live_room')||'public')),messageCursor=0,eventCursor=0,messages=new Map(),events=new Map(),replyTo=null,pollTimer=null,failures=0,pending=null,invitees=[];
localStorage.setItem('annotated_live_client',client);
const $=s=>document.querySelector(s),esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
function params(){const v=$('#room').value||'public';if(v.startsWith('team:'))return{room_type:'team',room_id:v.slice(5)};if(v.startsWith('project:'))return{room_type:'project',room_id:v.slice(8)};return{room_type:'public',room_id:''};}
function initials(name){const parts=String(name||'A').trim().split(/\s+/).filter(Boolean);return (parts[0]?.[0]||'A')+(parts.length>1?(parts[parts.length-1]?.[0]||''):'');}
function avatarMarkup(person,size='40'){const name=person.display_name||person.username||'Participant';return person.profile_image_url?'<img class="liveRoomAvatar" style="--live-avatar:'+size+'px" src="'+esc(person.profile_image_url)+'" alt="'+esc(name)+'">':'<span class="liveRoomAvatar liveRoomAvatarFallback" style="--live-avatar:'+size+'px">'+esc(initials(name).toUpperCase())+'</span>';}
async function post(action,data={}){const r=params(),body=new URLSearchParams({csrf,action,source,room_type:r.room_type,room_id:r.room_id,client_session_id:client,...data});const res=await fetch('/live.php?id='+encodeURIComponent(source),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const j=await res.json();if(!res.ok||j.ok===false)throw new Error(j.error?.message||j.error?.code||'Live request failed');return j.data;}
function presence(d){
  $('#stats').textContent=(d.total||0)+' here'+((d.following_visible||0)?' · '+d.following_visible+' you follow':'');
  $('#participantCount').textContent=d.total?'('+d.total+')':'';
  const rows=[...(d.visible||[]).map(p=>({...p,kind:'visible'})),...(d.cloaked||[]).map(p=>({...p,kind:'cloaked'}))];
  $('#people').innerHTML=rows.length?rows.map(p=>p.kind==='visible'
    ?'<a class="liveRoomParticipant" href="/'+encodeURIComponent(p.username||'')+'">'+avatarMarkup(p)+'<span><strong>'+esc(p.display_name||p.username)+'</strong><small>'+esc(p.is_self?'You':('@'+(p.username||'user')))+'</small></span><i class="liveRoomPresenceDot"></i></a>'
    :'<div class="liveRoomParticipant">'+avatarMarkup({display_name:p.is_self?'You':'Cloaked'})+'<span><strong>'+esc(p.is_self?'You · cloaked':p.alias||'Cloaked participant')+'</strong><small>Private presence</small></span><i class="liveRoomPresenceDot"></i></div>'
  ).join(''):'<div class="liveRoomPanelEmpty">No one else is here yet.</div>';
}
function renderInvitees(){
  const term=String($('#inviteSearch')?.value||'').trim().toLowerCase();
  const rows=invitees.filter(p=>!term||String(p.display_name||'').toLowerCase().includes(term)||String(p.username||'').toLowerCase().includes(term));
  $('#inviteList').innerHTML=rows.length?rows.map(p=>'<div class="liveRoomInvitePerson">'+avatarMarkup(p,'38')+'<span><strong>'+esc(p.display_name||p.username)+'</strong><small>@'+esc(p.username||'user')+'</small></span><button type="button" data-live-invite="'+esc(p.username||'')+'">Invite</button></div>').join(''):'<div class="liveRoomPanelEmpty">'+(term?'No matching people with room access.':'No other people with access right now.')+'</div>';
}
function render(){const rows=[...messages.values()].sort((a,b)=>Number(b.pinned)-Number(a.pinned)||String(a.created_at).localeCompare(String(b.created_at)));$('#messages').innerHTML=rows.length?rows.map(m=>{const who=m.author?.display_name||(m.is_self?'You · cloaked':m.cloak_alias||'Cloaked participant'),person=m.author||{display_name:who},actions=m.deleted?'':`<div class="liveRoomMessageActions"><button data-action="reply" data-id="${esc(m.public_id)}" data-who="${esc(who)}">Reply</button><button data-action="react" data-id="${esc(m.public_id)}">${m.viewer_liked?'♥':'♡'} ${m.like_count||0}</button>${m.can_pin?`<button data-action="pin" data-id="${esc(m.public_id)}">${m.pinned?'Unpin':'Pin'}</button>`:''}${m.can_delete?`<button data-action="delete" data-id="${esc(m.public_id)}">Delete</button>`:''}<button data-action="report" data-id="${esc(m.public_id)}">Report</button></div>`;return `<article id="message-${esc(m.public_id)}" class="liveRoomMessage ${m.pinned?'pinned':''}"><div class="liveRoomMessageAvatar">${avatarMarkup(person,'42')}</div><div class="liveRoomMessageContent"><header><strong>${esc(who)}</strong><time>${esc(m.created_at)}</time>${m.pinned?'<span>PINNED</span>':''}</header><p>${m.deleted?'<em>Message removed</em>':esc(m.body||'')}</p>${actions}</div></article>`;}).join(''):'<div class="liveRoomEmptyMessages"><span aria-hidden="true">◫</span><strong>No messages yet.</strong><p>Be the first to start the conversation.</p></div>';
$('#events').innerHTML=[...events.values()].slice(-10).map(e=>`<a class="liveRoomEvent" href="${e.object_type==='annotation'?'/annotation.php?id=':'/source.php?id='}${encodeURIComponent(e.object_public_id||'')}"><strong>${esc(e.label)}</strong><span>${esc(e.created_at)}</span></a>`).join('');
}
async function heartbeat(){try{presence(await post('presence',{mode:$('#presence').value}));}catch{}}
async function poll(reset=false){
  if(reset){messageCursor=0;eventCursor=0;messages.clear();events.clear();}
  const r=params(),qs=new URLSearchParams({id:source,ajax:'1',room_type:r.room_type,room_id:r.room_id,after_message:String(messageCursor),after_event:String(eventCursor)});
  try{
    await heartbeat();const res=await fetch('/live.php?'+qs),j=await res.json();if(!res.ok||!j.ok)throw new Error(j.error?.message||'Live unavailable');
    for(const m of j.data.messages||[])messages.set(m.public_id,m);for(const e of j.data.events||[])events.set(e.public_id,e);
    messageCursor=Math.max(messageCursor,Number(j.data.message_cursor||0));eventCursor=Math.max(eventCursor,Number(j.data.event_cursor||0));
    presence(j.data.presence||{});invitees=j.data.invitees||[];renderInvitees();
    $('#connection').textContent=j.data.room?.can_post===false?'Connected · read only':'Connected';
    $('#roomScopeBadge').textContent=({public:'Public',team:'Team',project:'Research'})[j.data.room?.type]||'Live';
    $('#send').disabled=j.data.room?.can_post===false;failures=0;render();
  }catch(e){failures++;$('#connection').textContent='Reconnecting…';}
  clearTimeout(pollTimer);pollTimer=setTimeout(()=>poll(false),Math.min(15000,2500*Math.max(1,failures)));
}
$('#room').value=[...$('#room').options].some(o=>o.value===roomSelection)?roomSelection:'public';$('#presence').value=defaultPresence||'cloaked';
$('#room').onchange=()=>{roomSelection=$('#room').value;localStorage.setItem('annotated_live_room',roomSelection);pending=null;replyTo=null;poll(true)};
$('#presence').onchange=heartbeat;
$('#inviteSearch').oninput=renderInvitees;
$('#inviteList').onclick=async e=>{const button=e.target.closest('[data-live-invite]');if(!button)return;button.disabled=true;const original=button.textContent;try{await post('invite',{username:button.dataset.liveInvite});button.textContent='Invited';}catch(err){button.disabled=false;button.textContent=original;alert(err.message)}};
$('#shareRoom').onclick=async()=>{const value=location.href;try{await navigator.clipboard.writeText(value);$('#shareRoom').textContent='Copied';setTimeout(()=>$('#shareRoom').textContent='Share',1300);}catch{prompt('Copy this room link:',value);}};
$('#composer').onsubmit=async e=>{e.preventDefault();const text=$('#body').value.trim(),key=$('#room').value+'|'+(replyTo?.id||'');if(!text)return;if(!pending||pending.text!==text||pending.key!==key)pending={id:crypto.randomUUID(),text,key};try{await post('message',{body:text,client_message_id:pending.id,parent_message_id:replyTo?.id||'',reveal_identity:$('#reveal').checked?'1':''});pending=null;replyTo=null;$('#body').value='';$('#reveal').checked=false;$('#reply').hidden=true;$('#cancelReply').hidden=true;await poll(false);}catch(err){alert(err.message)}};
$('#messages').onclick=async e=>{const b=e.target.closest('[data-action]');if(!b)return;const a=b.dataset.action,id=b.dataset.id;if(a==='reply'){replyTo={id,who:b.dataset.who};$('#reply').textContent='Replying to '+replyTo.who;$('#reply').hidden=false;$('#cancelReply').hidden=false;$('#body').focus();return;}if(a==='report'){const reason=prompt('Report reason:','other');if(reason===null)return;const details=prompt('Optional details:','')||'';const body=new URLSearchParams({csrf,type:'live_message',id,reason,description:details});const res=await fetch('/report.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});if(res.ok)alert('Report submitted for review.');else alert('Unable to submit report.');return;}if(a==='delete'&&!confirm('Remove this Live message?'))return;try{await post(a,{message_id:id});await poll(true);}catch(err){alert(err.message)}};
$('#cancelReply').onclick=()=>{replyTo=null;$('#reply').hidden=true;$('#cancelReply').hidden=true};poll(true);
</script></body></html>