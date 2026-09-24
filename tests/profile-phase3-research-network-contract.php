<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['profile_network_public_topics','profile_network_discover_people','profile_network_suggested_people','profile_network_following_activity','profile_network_notify_followers_report','profile_network_add_to_agent'] as $fn)$need('app/profile-network.php','function '.$fn,'Profile Phase 3 runtime missing: '.$fn);
$need('app/profile-network.php',"profile_visibility,'public')='public'",'People discovery must preserve public-profile visibility.');
$need('app/profile-network.php','search_visibility,1)=1','People discovery must preserve search visibility.');
$need('app/profile-network.php','NOT EXISTS(SELECT 1 FROM blocks','People discovery/following activity must preserve block boundaries.');
$need('app/profile-network.php',"'research_followed_publication'","Follower Research notifications must route through the existing research notification preference.");
$need('app/profile-network.php',"'origin'=>'annotated_public_research'","Published Research handoff must preserve source provenance.");
$need('app/profile-network.php',"'report_snapshot_hash'=>$snapshotHash",'Published Research handoff must preserve the immutable publication snapshot hash separately from Source-Version content hashing.');
$need('app/profile-network.php',"hash('sha256',$text)",'Imported public Research Source Versions must hash their extracted evidence text.');

$need('people.php',"\$GLOBALS['annotated_shell_disabled']=true;",'People discovery must be a standalone public surface.');
foreach(['Suggested people','Explainable recommendations','Search people or public Research','profilePeopleDiscoveryGrid'] as $needle)$need('people.php',$needle,'People discovery UI missing: '.$needle);
$need('explore.php','profile_network_suggested_people','Explore must reuse explainable people recommendations.');
$need('app/search.php','profile_network_enrich_person','Unified Search must enrich people with public Research context.');
$need('search.php','Open people discovery','Search must connect people results to the dedicated discovery surface.');
$need('home.php','profile_network_following_activity','Latest must compose followed public Research/collections through the existing feed surface.');
$need('home.php',"kind']??'')==='research_report'",'Latest must render followed public Research.');
$need('home.php',"kind']??'')==='collection'",'Latest must render followed public collections.');
$need('app/research-publishing.php','profile_network_notify_followers_report','Publishing must notify followers only after governed publication succeeds.');

$need('profile.php','network_add_research','Public profile evidence must support explicit Add to Research Agent.');
$need('profile.php','network_research_this','Public profile evidence must support explicit Research this handoff.');
$need('profile.php',"\$researchAgents=\$viewer&&!\$viewAsPublic",'View as public must hide the owner-only Research handoff controls.');
$need('collection.php','is_blocked($pdo','Public collections must enforce the same block boundary as profiles/search.');
foreach(['og:title','og:description','canonical'] as $needle)$need('collection.php',$needle,'Public collection rich-preview contract missing: '.$needle);

$avoid('database/migrations/20260923_062_profile_social_discovery_research_network.sql','CREATE TABLE','Profile Phase 3 must not introduce a parallel social/discovery persistence layer.');
$latest=glob($root.'/database/migrations/*.sql')?:[];sort($latest,SORT_STRING);$latest=$latest?basename((string)end($latest)):'';
if($latest!=='20260923_061_profile_public_identity_showcase.sql')$fail[]='Profile Phase 3 must keep migration 061 as the current latest migration.';

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
foreach(['.peopleDiscoveryPage','.profilePeopleDiscoveryGrid','.profileDiscoveryCard','.profileResearchHandoff','.homeNetworkObject'] as $needle)if(!str_contains($css,$needle))$fail[]='Profile Phase 3 style missing: '.$needle;

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Profile Phase 3 static contract passed.\n";
