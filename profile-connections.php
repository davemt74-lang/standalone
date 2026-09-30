<?php
declare(strict_types=1);

$GLOBALS['annotated_shell_disabled']=true;
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/public-discovery.php';
require_once __DIR__.'/app/profile-showcase.php';

$viewer=current_user($pdo);
$username=trim((string)($_GET['u']??''));
$type=($_GET['type']??'followers')==='following'?'following':'followers';
$p=$username!==''?public_discovery_profile($pdo,$username,$viewer):null;
if(!$p){http_response_code(404);exit('Profile not found.');}
if($viewer){header('Cache-Control: private, no-store');header('Vary: Cookie');}
$people=profile_showcase_people($pdo,(int)$p['id'],$type,$viewer,150);
$title=$type==='following'?'Following':'Followers';
$count=$type==='following'?(int)$p['following_count']:(int)$p['followers'];$csrf=$viewer?csrf_token():'';$owner=$viewer&&(int)$viewer['id']===(int)$p['id'];
?><!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> · <?=h($p['display_name'])?> · Annotated</title>
<meta name="robots" content="noindex,follow">
<link rel="stylesheet" href="/assets/css/app.css?v=profile-200">
</head>
<body class="profileStandaloneBody">
<main class="profileStandalonePage profileConnectionsPage">
    <header class="profileConnectionsHeader">
        <a class="profileBackLink" href="<?=h(profile_path((string)$p['username']))?>">← <?=h($p['display_name'])?></a>
        <div><span class="profileSectionEyebrow">PEOPLE</span><h1><?=h($title)?></h1><p><span data-connection-count><?=h((string)$count)?></span> total · showing public profiles you can currently access.</p></div>
        <nav><a class="<?=$type==='followers'?'active':''?>" href="/profile-connections.php?u=<?=rawurlencode((string)$p['username'])?>&type=followers">Followers</a><a class="<?=$type==='following'?'active':''?>" href="/profile-connections.php?u=<?=rawurlencode((string)$p['username'])?>&type=following">Following</a></nav>
    </header>
    <section class="profilePeopleList">
        <?php if(!$people):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">○</div><h3>No visible profiles here</h3><p>Private, blocked, or unavailable accounts are not exposed in public connection lists.</p></div><?php endif?>
        <?php foreach($people as $person):?>
        <article class="profilePersonCard" data-connection-card data-user-id="<?=h((string)$person['public_id'])?>">
            <a class="profilePersonIdentityLink" href="<?=h(profile_path((string)$person['username']))?>">
                <?php if(!empty($person['profile_image_url'])):?><img src="<?=h((string)$person['profile_image_url'])?>" alt="">
                <?php else:?><span class="profilePersonFallback"><?=h(mb_strtoupper(mb_substr((string)$person['display_name'],0,1)))?></span><?php endif?>
                <span class="profilePersonCopy"><strong><?=h((string)$person['display_name'])?></strong><small>@<?=h((string)$person['username'])?></small><?php if(!empty($person['bio'])):?><em><?=h(public_discovery_meta_description((string)$person['bio'],140))?></em><?php endif?></span>
            </a>
            <span class="profilePersonRelationship" data-relationship-state>
                <?php if(!empty($person['friends'])):?><small class="profileRelationshipBadge">Friends</small>
                <?php elseif(!empty($person['follows_you'])):?><small class="profileRelationshipBadge">Follows you</small><?php endif?>
            </span>
            <?php if($viewer&&(string)$viewer['public_id']!==(string)$person['public_id']):?>
              <button type="button" class="profileConnectionFollowButton" data-connection-follow aria-pressed="<?=!empty($person['following'])?'true':'false'?>"><?=!empty($person['following'])?'Following':'Follow'?></button>
            <?php else:?><span class="profilePersonArrow" aria-hidden="true">→</span><?php endif?>
        </article>
        <?php endforeach?>
    </section>
    <footer class="profileStandaloneFooter"><a href="<?=$viewer?'/home.php':'/'?>">Annotated</a><a href="<?=h(profile_path((string)$p['username']))?>">Profile</a></footer>
</main>
<?php if($viewer):?>
<script>
(()=>{
  const csrf=<?=json_encode($csrf)?>;const ownFollowingList=<?=json_encode($owner&&$type==='following')?>;
  const updateRelationship=(card,data)=>{
    const wrap=card.querySelector('[data-relationship-state]');if(!wrap)return;
    wrap.replaceChildren();
    if(data.friends){const s=document.createElement('small');s.className='profileRelationshipBadge';s.textContent='Friends';wrap.appendChild(s);}
    else if(data.follows_you){const s=document.createElement('small');s.className='profileRelationshipBadge';s.textContent='Follows you';wrap.appendChild(s);}
  };
  document.querySelectorAll('[data-connection-follow]').forEach(button=>button.addEventListener('click',async()=>{
    if(button.disabled)return;const card=button.closest('[data-connection-card]');if(!card)return;
    const desired=button.getAttribute('aria-pressed')!=='true';button.disabled=true;button.setAttribute('aria-busy','true');
    try{
      const r=await fetch('/api/profile-follow.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({user_id:card.dataset.userId,following:desired})});
      const j=await r.json().catch(()=>({ok:false,error:{message:'Follow request failed.'}}));if(!r.ok||j.ok===false)throw new Error(j.error?.message||'Follow request failed.');
      const following=!!j.data.following;button.textContent=following?'Following':'Follow';button.setAttribute('aria-pressed',following?'true':'false');updateRelationship(card,j.data);
      if(ownFollowingList&&!following){card.remove();const count=document.querySelector('[data-connection-count]');if(count)count.textContent=String(Math.max(0,Number(count.textContent||0)-1));}
    }catch(err){alert(err?.message||'Unable to update follow state.');}
    finally{button.disabled=false;button.removeAttribute('aria-busy');}
  }));
})();
</script>
<?php endif?>
</body>
</html>
