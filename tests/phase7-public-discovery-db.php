<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/feed.php';require_once $root.'/app/research-reports.php';require_once $root.'/app/public-discovery.php';
function p7ok(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p7'.substr(bin2hex(random_bytes(7)),0,12);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array {$public=$pub('u');$username=substr($name.'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,bio) VALUES(?,?,?,?,NOW(),'active',?)")->execute([$public,$username,$name,$username.'@example.test','bio '.$name]);return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'username'=>$username,'display_name'=>$name,'role'=>'user'];};
$alice=$makeUser('Alice');$bob=$makeUser('Bob');$carol=$makeUser('Carol');
$pdo->prepare("INSERT INTO user_preferences(user_id,profile_visibility,search_visibility) VALUES(?,'public',1),(?,'public',1),(?,'private',0)")->execute([$alice['id'],$bob['id'],$carol['id']]);
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$alice['id'],'P7 Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$alice['id'],$teamId,$bob['id']]);

$makeSource=function(string $path,string $title)use($pdo,$run): array {$url='https://'.$run.'.example.test/'.$path;$s=ensure_source($pdo,$url,$title);$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)')->execute([$s['id'],$s['canonical_url'],$title,'body '.$title,hash('sha256',$title)]);$vid=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE sources SET current_version_id=?,status='current' WHERE id=?")->execute([$vid,$s['id']]);$s['version_id']=$vid;return $s;};
$publicSource=$makeSource('public','Public Needle Source');$privateSource=$makeSource('private','Private Secret Source');
$makeAnnotation=function(array $u,array $s,string $visibility,?int $team,string $text)use($pdo,$pub): string {$cap=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")->execute([$cap,$s['id'],$s['version_id'],$u['id'],'selected '.$text]);$cid=(int)$pdo->lastInsertId();$ann=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status) VALUES(?,?,?,?,?,?,?,?,'published')")->execute([$ann,$u['id'],$s['id'],$s['version_id'],$cid,$text,$visibility,$team]);return $ann;};
$publicAnn=$makeAnnotation($bob,$publicSource,'public',null,'phase7-public-needle commentary');
$teamAnn=$makeAnnotation($bob,$publicSource,'team',$teamId,'phase7-team-secret commentary');
$privateAnn=$makeAnnotation($bob,$privateSource,'private',null,'phase7-private-secret commentary');
$alicePrivate=$makeAnnotation($alice,$publicSource,'private',null,'alice private note');

$anonSource=public_discovery_source($pdo,$publicSource['public_id'],null);p7ok($anonSource!==null,'anonymous viewers can open sources with public annotations');
p7ok(count($anonSource['annotations'])===1&&$anonSource['annotations'][0]['public_id']===$publicAnn,'anonymous source page returns public annotations only');
p7ok(public_discovery_source($pdo,$privateSource['public_id'],null)===null,'anonymous viewers cannot deep-link a private-only source');
$teamSource=public_discovery_source($pdo,$publicSource['public_id'],$alice);$teamIds=array_column($teamSource['annotations'],'public_id');
p7ok(in_array($publicAnn,$teamIds,true)&&in_array($teamAnn,$teamIds,true)&&in_array($alicePrivate,$teamIds,true),'authorized source page includes public, shared Team, and own private annotations');
p7ok(public_discovery_annotation($pdo,$teamAnn,null)===null,'anonymous annotation deep links do not reveal Team annotations');
p7ok(public_discovery_annotation($pdo,$teamAnn,$alice)!==null,'authorized Team member can open Team annotation');

$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$alice['id'],$bob['id']]);
$blockedSource=public_discovery_source($pdo,$publicSource['public_id'],$alice);$blockedIds=array_column($blockedSource['annotations'],'public_id');
p7ok(!in_array($publicAnn,$blockedIds,true)&&!in_array($teamAnn,$blockedIds,true)&&in_array($alicePrivate,$blockedIds,true),'signed-in source discovery honors block relationships');
$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$alice['id'],$bob['id']]);

$projectPublic=$pub('project');$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,title,description) VALUES(?,?,?,?)')->execute([$projectPublic,$bob['id'],'P7 Public Research','public research']);$projectId=(int)$pdo->lastInsertId();
$reportPublic=$pub('report');$pdo->prepare("INSERT INTO research_reports(public_id,project_id,created_by_user_id,title,summary,visibility,status,published_at) VALUES(?,?,?,?,?,'public','published',NOW())")->execute([$reportPublic,$projectId,$bob['id'],'Phase7 Public Report','phase7-report-needle summary']);$reportId=(int)$pdo->lastInsertId();
$snapshot=['schema'=>'annotated-research-report-v1','project'=>['title'=>'Phase7 Public Report','summary'=>'phase7-report-needle summary'],'sources'=>[],'findings'=>[['id'=>'finding-x','title'=>'Needle Finding','summary'=>'finding phase7-claim-needle','status'=>'final','claims'=>[]]],'claims'=>[['id'=>'claim-x','statement'=>'phase7-claim-needle statement','type'=>'fact','status'=>'open','evidence'=>[]]],'entities'=>[['id'=>'entity-x','type'=>'person','name'=>'Phase7EntityNeedle','description'=>'entity public description','status'=>'confirmed','mentions'=>[]]],'claim_relations'=>[],'entity_relations'=>[],'timeline'=>[]];$json=json_encode($snapshot,JSON_UNESCAPED_SLASHES);
$versionPublic=$pub('rv');$pdo->prepare("INSERT INTO research_report_versions(public_id,report_id,version_number,published_by_user_id,visibility,title,summary,snapshot_json,snapshot_hash) VALUES(?,?,1,?,'public',?,?,?,?)")->execute([$versionPublic,$reportId,$bob['id'],'Phase7 Public Report','phase7-report-needle summary',$json,hash('sha256',$json)]);$rvId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE research_reports SET current_version_id=? WHERE id=?')->execute([$rvId,$reportId]);

$privateProjectPublic=$pub('project');$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,title) VALUES(?,?,?)')->execute([$privateProjectPublic,$bob['id'],'P7 Private Research']);$privateProjectId=(int)$pdo->lastInsertId();
$privateReportPublic=$pub('report');$pdo->prepare("INSERT INTO research_reports(public_id,project_id,created_by_user_id,title,summary,visibility,status,published_at) VALUES(?,?,?,?,?,'private','published',NOW())")->execute([$privateReportPublic,$privateProjectId,$bob['id'],'Hidden Report','phase7-hidden-report-secret']);$privateReportId=(int)$pdo->lastInsertId();
$privateJson=json_encode(['project'=>['title'=>'Hidden Report'],'claims'=>[['id'=>'hidden','statement'=>'phase7-hidden-claim-secret']]],JSON_UNESCAPED_SLASHES);$prv=$pub('rv');$pdo->prepare("INSERT INTO research_report_versions(public_id,report_id,version_number,published_by_user_id,visibility,title,summary,snapshot_json,snapshot_hash) VALUES(?,?,1,?,'private',?,?,?,?)")->execute([$prv,$privateReportId,$bob['id'],'Hidden Report','phase7-hidden-report-secret',$privateJson,hash('sha256',$privateJson)]);$prvId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE research_reports SET current_version_id=? WHERE id=?')->execute([$prvId,$privateReportId]);

$profile=public_discovery_profile($pdo,$bob['username'],null);p7ok($profile!==null&&count($profile['annotations'])===1&&$profile['annotations'][0]['public_id']===$publicAnn,'public profile exposes public annotations only');
p7ok(count($profile['reports'])===1&&$profile['reports'][0]['public_id']===$reportPublic,'public profile exposes explicitly public Research reports only');
p7ok(public_discovery_profile($pdo,$carol['username'],null)===null,'private profile is not available anonymously');

$searchPublic=public_discovery_search($pdo,'phase7-public-needle');p7ok(count($searchPublic['annotations'])===1&&$searchPublic['annotations'][0]['public_id']===$publicAnn,'unified search finds public annotation text');
$searchTeam=public_discovery_search($pdo,'phase7-team-secret');p7ok(count($searchTeam['annotations'])===0,'unified public search never indexes Team annotation text');
$searchPrivate=public_discovery_search($pdo,'phase7-private-secret');p7ok(count($searchPrivate['annotations'])===0,'unified public search never indexes Private annotation text');
$searchReport=public_discovery_search($pdo,'phase7-claim-needle');p7ok(count($searchReport['reports'])===1&&$searchReport['reports'][0]['public_id']===$reportPublic,'unified search finds claims inside public immutable report snapshots');
p7ok(($searchReport['reports'][0]['matches'][0]['anchor']??'')==='finding-finding-x'||($searchReport['reports'][0]['matches'][0]['anchor']??'')==='claim-claim-x','report search returns an exact immutable-object anchor');
$searchHidden=public_discovery_search($pdo,'phase7-hidden-claim-secret');p7ok(count($searchHidden['reports'])===0,'private report snapshots never enter public search');

$explore=public_discovery_explore($pdo);$exploreSourceIds=array_column($explore['sources'],'public_id');$exploreAnnIds=array_column($explore['annotations'],'public_id');$exploreReportIds=array_column($explore['reports'],'public_id');
p7ok(in_array($publicSource['public_id'],$exploreSourceIds,true)&&!in_array($privateSource['public_id'],$exploreSourceIds,true),'Explore includes only sources with public annotations');
p7ok(in_array($publicAnn,$exploreAnnIds,true)&&!in_array($teamAnn,$exploreAnnIds,true)&&!in_array($privateAnn,$exploreAnnIds,true),'Explore annotations are public-only');
p7ok(in_array($reportPublic,$exploreReportIds,true)&&!in_array($privateReportPublic,$exploreReportIds,true),'Explore reports are public-only');

echo "Phase 7 public pages and discovery MariaDB suite passed.\n";
