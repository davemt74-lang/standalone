<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','feed','public-discovery','search','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','profile-network'] as $lib)require_once $root.'/app/'.$lib.'.php';
function pp3(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function pp3throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

$cfg=['app'=>['base_url'=>'https://annotated.example.test']];
$run='pp3'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $visibility='public',int $search=1)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO user_preferences(user_id,profile_visibility,search_visibility) VALUES(?,?,?)')->execute([$id,$visibility,$search]);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$viewer=$makeUser('NetworkViewer');$candidate=$makeUser('NetworkResearcher');$mutual=$makeUser('NetworkMutual');$private=$makeUser('NetworkPrivate','private');$blocked=$makeUser('NetworkBlocked');

$makeSource=function(string $label,string $domain='shared.example')use($pdo,$pub): array{
    $url='https://'.$domain.'/'.rawurlencode(strtolower(str_replace(' ','-',$label))).'-'.substr(bin2hex(random_bytes(3)),0,6);
    $pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'article',?,?,?,?,'current')")->execute([$pub('src'),$url,hash('sha256',$url),$domain,$label]);$sid=(int)$pdo->lastInsertId();
    $text='Evidence '.$label;$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())')->execute([$sid,$url,$label,$text,hash('sha256',$text)]);$sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$sv,$sid]);
    return ['id'=>$sid,'version_id'=>$sv,'url'=>$url];
};
$shared=$makeSource('Shared climate source');
$makeAnnotation=function(array $user,array $source,string $label)use($pdo,$pub): string{
    $cap=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")->execute([$cap,$source['id'],$source['version_id'],(int)$user['id'],'Selected '.$label]);$captureId=(int)$pdo->lastInsertId();
    $ann=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status,published_at) VALUES(?,?,?,?,?,?,'public','published',NOW())")->execute([$ann,(int)$user['id'],$source['id'],$source['version_id'],$captureId,'Comment '.$label]);return $ann;
};
$viewerAnn=$makeAnnotation($viewer,$shared,'viewer evidence');$candidateAnn=$makeAnnotation($candidate,$shared,'candidate evidence');

$makeReport=function(array $user,string $title,string $topicName)use($pdo,$pub): array{
    $projectPublic=$pub('project');$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,title,description) VALUES(?,?,?,?)')->execute([$projectPublic,(int)$user['id'],$title.' Project','Public topic research']);$projectId=(int)$pdo->lastInsertId();
    $reportPublic=$pub('report');$summary='Validated research about '.$topicName;$snapshot=json_encode(['project'=>['title'=>$title,'summary'=>$summary],'findings'=>[['id'=>'f1','title'=>'Finding '.$topicName,'summary'=>'Evidence around '.$topicName]],'claims'=>[['id'=>'c1','statement'=>$topicName.' has material evidence']],'entities'=>[['id'=>'e1','name'=>$topicName,'description'=>'Shared research topic']],'sources'=>[]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO research_reports(public_id,project_id,created_by_user_id,title,summary,visibility,status,published_at) VALUES(?,?,?,?,?,'public','published',NOW())")->execute([$reportPublic,$projectId,(int)$user['id'],$title,$summary]);$reportId=(int)$pdo->lastInsertId();
    $hash=hash('sha256',$snapshot);$pdo->prepare("INSERT INTO research_report_versions(public_id,report_id,version_number,published_by_user_id,visibility,title,summary,snapshot_json,snapshot_hash) VALUES(?,?,1,?,'public',?,?,?,?)")->execute([$pub('rv'),$reportId,(int)$user['id'],$title,$summary,$snapshot,$hash]);$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE research_reports SET current_version_id=? WHERE id=?')->execute([$versionId,$reportId]);
    return ['public_id'=>$reportPublic,'id'=>$reportId,'version_id'=>$versionId,'snapshot'=>$snapshot,'snapshot_hash'=>$hash,'project_id'=>$projectId];
};
$viewerReport=$makeReport($viewer,'Viewer Climate Research','Climate');$candidateReport=$makeReport($candidate,'Candidate Climate Research','Climate');

$entityPublic=$pub('topic');$pdo->prepare("INSERT INTO discovery_entities(public_id,entity_type,canonical_name,normalized_name,description,status) VALUES(?,'topic','Climate','climate','Shared test topic','active')")->execute([$entityPublic]);$entityId=(int)$pdo->lastInsertId();
$mention=$pdo->prepare("INSERT INTO discovery_entity_mentions(entity_id,object_type,object_public_id,origin_report_public_id,mention_weight,excerpt) VALUES(?,'research_report',?,?,1.0,'Climate evidence')");
$mention->execute([$entityId,$viewerReport['public_id'],$viewerReport['public_id']]);$mention->execute([$entityId,$candidateReport['public_id'],$candidateReport['public_id']]);

$pdo->prepare('INSERT INTO follows(follower_user_id,followed_user_id) VALUES(?,?),(?,?)')->execute([(int)$viewer['id'],(int)$mutual['id'],(int)$candidate['id'],(int)$mutual['id']]);
$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([(int)$viewer['id'],(int)$blocked['id']]);

$discover=profile_network_discover_people($pdo,$viewer,'',100);$ids=array_column($discover,'public_id');
pp3(in_array($candidate['public_id'],$ids,true),'Public searchable researcher appears in people discovery.');
pp3(!in_array($private['public_id'],$ids,true)&&!in_array($blocked['public_id'],$ids,true),'Private and blocked profiles stay out of discovery.');

$suggested=profile_network_suggested_people($pdo,$viewer,20);$match=null;foreach($suggested as $row)if(($row['public_id']??'')===$candidate['public_id']){$match=$row;break;}
pp3($match!==null,'Explainable suggestion graph recommends an unfollowed public researcher with shared evidence.');
$reason=implode(' · ',(array)($match['recommendation_reasons']??[]));
pp3(str_contains($reason,'shared Research topic')&&str_contains($reason,'mutual connection')&&str_contains($reason,'shared public Source'),'Suggestion exposes concrete Research-topic, mutual-connection, and shared-Source reasons.');

$collectionPublic=$pub('collection');$pdo->prepare("INSERT INTO collections(public_id,owner_user_id,title,description,visibility) VALUES(?,?,?,?, 'public')")->execute([$collectionPublic,(int)$candidate['id'],'Candidate Evidence Collection','Curated public evidence']);
$collectionId=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT id FROM annotations WHERE public_id=?');$q->execute([$candidateAnn]);$candidateAnnId=(int)$q->fetchColumn();$pdo->prepare('INSERT INTO collection_items(collection_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$collectionId,$candidateAnnId,(int)$candidate['id']]);
$pdo->prepare('INSERT INTO follows(follower_user_id,followed_user_id) VALUES(?,?)')->execute([(int)$viewer['id'],(int)$candidate['id']]);

$activity=profile_network_following_activity($pdo,$viewer,30);$kinds=array_column($activity,'kind');
pp3(in_array('research_report',$kinds,true)&&in_array('collection',$kinds,true),'Following activity composes public Research Reports and collections alongside the existing annotation feed.');

$sent=profile_network_notify_followers_report($pdo,$candidate,['public_id'=>$candidateReport['public_id'],'version_number'=>1]);
pp3($sent>=1,'Publishing public Research creates follower notifications through the existing notification system.');
$q=$pdo->prepare("SELECT notification_type,category FROM notifications WHERE user_id=? AND object_type='research_report' AND object_public_id=? ORDER BY id DESC LIMIT 1");$q->execute([(int)$viewer['id'],$candidateReport['public_id']]);$notification=$q->fetch();
pp3(($notification['notification_type']??'')==='research_followed_publication'&&($notification['category']??'')==='research','Follower publication notification obeys the existing Research notification category.');

$quiet=$makeUser('NetworkQuiet');$pdo->prepare('UPDATE user_preferences SET notify_research=0 WHERE user_id=?')->execute([(int)$quiet['id']]);$pdo->prepare('INSERT INTO follows(follower_user_id,followed_user_id) VALUES(?,?)')->execute([(int)$quiet['id'],(int)$candidate['id']]);
profile_network_notify_followers_report($pdo,$candidate,['public_id'=>$candidateReport['public_id'],'version_number'=>2]);$q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND notification_type='research_followed_publication'");$q->execute([(int)$quiet['id']]);
pp3((int)$q->fetchColumn()===0,'Research notification preferences suppress followed-publication alerts.');

$agent=research_agent_create($pdo,$viewer,['name'=>'Network Evidence Agent','description'=>'Validate public network evidence.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);pp3($project!==null,'Public-network handoff targets a normal writable Research Agent.');

profile_network_add_to_agent($pdo,$cfg,$viewer,(string)$agent['public_id'],'annotation',$candidateAnn);
$q=$pdo->prepare('SELECT COUNT(*) FROM project_annotations WHERE project_id=? AND annotation_id=?');$q->execute([(int)$project['id'],$candidateAnnId]);pp3((int)$q->fetchColumn()===1,'Public annotation handoff references the existing annotation instead of copying it.');

$handoff=profile_network_add_to_agent($pdo,$cfg,$viewer,(string)$agent['public_id'],'research_report',$candidateReport['public_id']);
pp3(($handoff['type']??'')==='research_report'&&!empty($handoff['bookmark_public_id']),'Published Research handoff adds a provenance bookmark to the Research Agent.');
$url='https://annotated.example.test/research-report.php?id='.rawurlencode($candidateReport['public_id']);$q=$pdo->prepare('SELECT id,current_version_id FROM sources WHERE canonical_url_hash=? LIMIT 1');$q->execute([hash('sha256',canonicalize_url($url))]);$source=$q->fetch();pp3($source!==false,'Published Research handoff enters the existing Source graph.');
$q=$pdo->prepare('SELECT extracted_text,content_hash,metadata_json FROM source_versions WHERE id=?');$q->execute([(int)$source['current_version_id']]);$sv=$q->fetch();$meta=json_decode((string)$sv['metadata_json'],true)?:[];
pp3(hash_equals(hash('sha256',(string)$sv['extracted_text']),(string)$sv['content_hash']),'Imported public Research Source Version uses the normal extracted-content hash invariant.');
pp3(($meta['origin']??'')==='annotated_public_research'&&($meta['research_report_public_id']??'')===$candidateReport['public_id']&&hash_equals($candidateReport['snapshot_hash'],(string)($meta['report_snapshot_hash']??'')),'Imported public Research preserves exact immutable report provenance separately from Source content.');

$q=$pdo->prepare('SELECT COUNT(*) FROM source_versions WHERE source_id=?');$q->execute([(int)$source['id']]);$before=(int)$q->fetchColumn();profile_network_add_to_agent($pdo,$cfg,$viewer,(string)$agent['public_id'],'research_report',$candidateReport['public_id']);$q->execute([(int)$source['id']]);pp3((int)$q->fetchColumn()===$before,'Re-adding the same immutable public Research report does not duplicate Source Versions.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_workspace_bookmarks WHERE project_id=? AND source_id=?");$q->execute([(int)$project['id'],(int)$source['id']]);pp3((int)$q->fetchColumn()===1,'Re-adding public Research reuses the existing Research Agent bookmark.');

$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([(int)$viewer['id'],(int)$candidate['id']]);
pp3throws(fn()=>profile_network_add_to_agent($pdo,$cfg,$viewer,(string)$agent['public_id'],'research_report',$candidateReport['public_id']),'A block immediately prevents new public Research handoffs from that profile.');
$afterBlock=profile_network_following_activity($pdo,$viewer,30);pp3(!array_filter($afterBlock,fn($x)=>(string)($x['row']['username']??'')===(string)$candidate['username']),'Blocked followed users disappear from mixed Following activity.');

echo "Profile Phase 3 Social Discovery & Research Network database journey passed.\n";
