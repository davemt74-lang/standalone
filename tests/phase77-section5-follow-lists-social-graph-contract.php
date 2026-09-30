<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('app/profile-showcase.php','profile_network_enrich_person($pdo,$row,$viewer)','Connection lists must reuse canonical relationship enrichment.');
$need('app/profile-showcase.php','NOT EXISTS(SELECT 1 FROM blocks','Connection lists must exclude blocked relationships.');
$need('profile-connections.php','data-connection-follow','Followers/Following lists must expose direct Follow controls.');
$need('profile-connections.php','following:desired','Connection Follow controls must send explicit idempotent desired state.');
$need('profile-connections.php','data-relationship-state','Connection rows must render canonical relationship state.');
$need('profile-connections.php','Friends','Connection lists must show mutual/friend state.');
$need('profile-connections.php','Follows you','Connection lists must show inbound follow state.');
$need('profile-connections.php','ownFollowingList','A user\'s own Following list must react immediately to unfollow.');
$need('profile-connections.php','data-connection-count','Connection list counts must be updateable without reload.');
$need('people.php','profileDiscoveryRelationship','People discovery must surface existing social relationship state.');
$need('assets/css/app.css','/* Phase 77.5 — Follow Lists, Mutuals & Social Graph UX */','Phase 77.5 must include connection-list styling.');
if(is_file($root.'/assets/css/app.css')&&is_file($root.'/extension/landing-app.css')&&file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 77 Section 5 Follow Lists & Social Graph UX contract passed.\n";
