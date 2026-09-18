<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if(!isset($_SESSION))$_SESSION=[];
require_once $root.'/app/storage.php';require_once $root.'/app/jobs.php';require_once $root.'/app/concurrency.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/notifications.php';require_once $root.'/app/source-integrity.php';require_once $root.'/app/live.php';require_once $root.'/app/moderation.php';require_once $root.'/app/search.php';require_once $root.'/app/release.php';
function p11(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p11'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,bool $password=true)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,password_hash,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),?,'active','user','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$password?password_hash('release-pass-1234',PASSWORD_DEFAULT):null]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT id,public_id,username,display_name,email,role,live_presence_mode FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$user=$makeUser('ReleaseUser');$other=$makeUser('OtherUser');

p11(!onboarding_should_redirect($pdo,(int)$user['id']),'existing user without onboarding state is not forcibly redirected');
onboarding_ensure($pdo,(int)$user['id']);p11(onboarding_should_redirect($pdo,(int)$user['id']),'new onboarding state redirects before welcome is seen');
$status=onboarding_status($pdo,$user,false);p11($status['steps']['account']['complete']&&!$status['steps']['extension']['complete']&&!$status['complete'],'onboarding derives incomplete milestones from real account data');

$token=bin2hex(random_bytes(32));$pdo->prepare("INSERT INTO extension_sessions(user_id,token_hash,device_name,client_version,expires_at) VALUES(?,?,?,'0.9.0',DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$user['id'],hash('sha256',$token),'CI Chrome']);
$_SESSION=['user_id'=>(int)$other['id'],'auth_time'=>time()];$_SERVER['HTTP_AUTHORIZATION']='Bearer invalid-release-token';p11(current_user($pdo)===null,'invalid bearer token cannot inherit an authenticated website session');$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;p11((int)(current_user($pdo)['id']??0)===(int)$user['id'],'valid bearer token is authoritative even when a different website session exists');unset($_SERVER['HTTP_AUTHORIZATION']);$_SESSION=[];
$source=ensure_source($pdo,'https://'.$run.'.example.test/release','Release Gate Source');$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)')->execute([$source['id'],$source['canonical_url'],'Release Gate Source','release gate source body',hash('sha256','release-gate')]);$version=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE sources SET current_version_id=?,status='current' WHERE id=?")->execute([$version,$source['id']]);
$cap=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text','release gate excerpt')")->execute([$cap,$source['id'],$version,$user['id']]);$captureId=(int)$pdo->lastInsertId();$ann=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status,published_at) VALUES(?,?,?,?,?,'release gate annotation','public','published',NOW())")->execute([$ann,$user['id'],$source['id'],$version,$captureId]);
$pdo->prepare('INSERT INTO follows(follower_user_id,followed_user_id) VALUES(?,?)')->execute([$user['id'],$other['id']]);
$project=$pub('project');$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,description) VALUES(?,?,?,'Release onboarding project')")->execute([$project,$user['id'],'Release Project']);

$status=onboarding_status($pdo,$user,true);p11($status['complete']&&$status['completed_count']===$status['total_count'],'onboarding completes only after extension, annotation, follow, and Research milestones');
$q=$pdo->prepare('SELECT completed_at FROM user_onboarding WHERE user_id=?');$q->execute([$user['id']]);p11((bool)$q->fetchColumn(),'completed onboarding persists completion timestamp');
onboarding_mark_seen($pdo,(int)$user['id']);p11(!onboarding_should_redirect($pdo,(int)$user['id']),'seen onboarding does not redirect repeatedly');

$_SESSION=['user_id'=>(int)$user['id'],'auth_time'=>time()-3600];$pdo->prepare("UPDATE users SET sessions_revoked_before=NOW() WHERE id=?")->execute([$user['id']]);p11(current_user($pdo)===null,'website session revocation epoch invalidates older browser session');
$_SESSION=['user_id'=>(int)$user['id'],'auth_time'=>time()+2];p11((int)(current_user($pdo)['id']??0)===(int)$user['id'],'newer browser session remains valid after revocation epoch');$_SESSION=[];

release_worker_heartbeat($pdo,'media','starting','CI worker started.');release_worker_heartbeat($pdo,'media','success','CI processed media.',2);release_worker_heartbeat($pdo,'media','failure','CI synthetic failure.');
$workers=release_worker_health($pdo);p11(isset($workers['media'])&&$workers['media']['processed_total']>=2&&$workers['media']['failed_total']>=1,'worker heartbeat accumulates processed and failure counters');p11($workers['media']['status']==='failure','latest worker failure is visible in health state');
$queues=release_queue_health($pdo);p11(isset($queues['media'],$queues['transcription'],$queues['source_monitor'],$queues['ai']),'release queue health covers every leased queue');

$tmp=sys_get_temp_dir().'/annotated-release-'.$run;if(!is_dir($tmp))mkdir($tmp,0700,true);
$config=['app'=>['base_url'=>'https://annotated.example.test','encryption_key'=>str_repeat('a',40),'bootstrap_key'=>str_repeat('b',40)],'storage'=>['private_root'=>$tmp],'extension'=>['allowed_ids'=>['abcdefghijklmnopabcdefghijklmnop']],'oauth'=>['google'=>['client_id'=>'x','client_secret'=>'y','redirect_uri'=>'https://annotated.example.test/oauth/callback.php'],'x'=>['client_id'=>'x','client_secret'=>'y','redirect_uri'=>'https://annotated.example.test/oauth/callback.php']],'transcription'=>['command'=>'/bin/true']];
$health=release_environment_checks($pdo,$config);p11(($health['checks']['database']['status']??'')==='pass','release preflight validates database connection');p11(($health['checks']['migrations']['status']??'')==='pass','release preflight confirms all migrations applied');p11(($health['checks']['extension_ids']['status']??'')==='pass','release preflight validates extension allowlist');p11($health['release']===ANNOTATED_RELEASE&&$health['version']===ANNOTATED_RELEASE_VERSION,'release health reports exact RC identity');@rmdir($tmp);

$q=$pdo->prepare('SELECT client_version,expires_at FROM extension_sessions WHERE user_id=? ORDER BY id DESC LIMIT 1');$q->execute([$user['id']]);$session=$q->fetch();p11(($session['client_version']??'')==='0.9.0'&&!empty($session['expires_at']),'extension session records RC client version and expiry');

echo "Phase 11 V1 Release Hardening MariaDB suite passed.\n";
