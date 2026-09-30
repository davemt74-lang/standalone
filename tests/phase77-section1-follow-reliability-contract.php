<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['profile_network_relationship','profile_network_set_follow','profile_network_toggle_follow'] as $fn)$need('app/profile-network.php','function '.$fn,'Phase 77.1 missing canonical follow runtime: '.$fn);
$need('app/profile-network.php','INSERT IGNORE INTO follows','Following must be idempotent and duplicate-safe.');
$need('app/profile-network.php','DELETE FROM follows WHERE follower_user_id=? AND followed_user_id=?','Unfollow must target the canonical relationship pair.');
$need('app/profile-network.php',"'friends'=>\$following&&\$followsYou",'Canonical relationship state must expose mutual friendship.');
$need('app/profile-network.php',"'blocked'=>\$blocked",'Canonical relationship state must expose block state.');
$need('app/profile-network.php',"'follower_count'",'Follow mutation responses must return refreshed follower counts.');
$need('api/profile-follow.php',"array_key_exists('following',\$input)",'Follow API must support explicit desired state instead of toggle-only mutation.');
$need('profile.php',"following:desired",'Profile Follow button must send explicit desired state.');
$need('profile.php','data-profile-follower-count','Profile must update follower count from the canonical response.');
$need('profile.php',"button.getAttribute('aria-pressed')!=='true'",'Profile Follow button must derive desired state from current UI state.');
$need('app/public-discovery.php','profile_network_relationship($pdo,$viewer','Public profile rendering must reuse canonical relationship state.');
$need('app/profile-network.php','$relationship=profile_network_relationship($pdo,$viewer,$id)','People discovery must reuse canonical relationship state.');

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 77 Section 1 Follow Reliability contract passed.\n";
