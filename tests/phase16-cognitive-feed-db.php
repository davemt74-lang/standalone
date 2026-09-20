<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/notifications.php';require_once $root.'/app/rate-limit.php';require_once $root.'/app/jobs.php';require_once $root.'/app/ai.php';require_once $root.'/app/source-integrity.php';require_once $root.'/app/annotation-intelligence.php';require_once $root.'/app/research-workspace.php';require_once $root.'/app/conversations.php';require_once $root.'/app/agent-actions.php';require_once $root.'/app/agent-chat.php';require_once $root.'/app/cognitive-feed.php';
function p16(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p16items(array $feed): array {$out=[];foreach($feed['sections']??[] as $s)foreach($s['items']??[] as $i)$out[]=$i;return $out;}
function p16find(array $feed,string $type): array {return array_values(array_filter(p16items($feed),fn($x)=>($x['type']??'')===$type));}
$run='p16'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('CognitiveOwner');$member=$makeUser('CognitiveMember');$outsider=$makeUser('CognitiveOutsider');
p16(cognitive_feed_ready($pdo),'Phase 16 Cognitive Feed schema is available');
p16(cognitive_feed_mode_get($pdo,$owner)==='cognitive','Cognitive Feed is the default Home mode');
p16(cognitive_feed_mode_set($pdo,$owner,'latest')==='latest'&&cognitive_feed_mode_get($pdo,$owner)==='latest','Home mode preference persists');
cognitive_feed_mode_set($pdo,$owner,'cognitive');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Cognitive Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);
$projectPublic=$pub('project');$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active')")->execute([$projectPublic,$owner['id'],$teamId,'Cognitive Research','Phase 16 cognitive feed project']);$projectId=(int)$pdo->lastInsertId();

$url='https://example.com/'.$run.'/source';$sourcePublic=$pub('source');$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status,monitoring_enabled,moderation_status) VALUES(?,'webpage',?,?,?,'Cognitive Source','updated',1,'visible')")->execute([$sourcePublic,$url,hash('sha256',$url),'example.com']);$sourceId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,'Cognitive Source v1','Original cognitive evidence',?)")->execute([$sourceId,$url,hash('sha256','cognitive-v1')]);$v1=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,2,?,'Cognitive Source v2','Changed cognitive evidence',?)")->execute([$sourceId,$url,hash('sha256','cognitive-v2')]);$v2=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$v2,$sourceId]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$sourceId,$owner['id']]);
$pdo->prepare('INSERT INTO source_watches(source_id,user_id) VALUES(?,?)')->execute([$sourceId,$owner['id']]);
$pdo->prepare("INSERT INTO source_change_events(source_id,previous_version_id,new_version_id,change_type,impact_type,target_changed,affected_annotation_count,diff_summary,created_at) VALUES(?,?,?,'edited','passage_changed',1,1,'A cited passage changed after capture.',DATE_SUB(NOW(),INTERVAL 5 MINUTE))")->execute([$sourceId,$v1,$v2]);$change1=(int)$pdo->lastInsertId();

$gapPublic=$pub('claim');$conflictPublic=$pub('claim');
$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'Unsupported cognitive claim','factual','unverified'),(?,?,?,'Contradicted cognitive claim','factual','contradicted')")->execute([$gapPublic,$projectId,$owner['id'],$conflictPublic,$projectId,$owner['id']]);
$taskPublic=$pub('task');$pdo->prepare("INSERT INTO research_tasks(public_id,project_id,created_by_user_id,title,description,task_type,status,due_at) VALUES(?,?,?,'Verify outside evidence','Find an independent source.','verify_claim','open',DATE_SUB(NOW(),INTERVAL 1 DAY))")->execute([$taskPublic,$projectId,$owner['id']]);

$capPublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$capPublic,$sourceId,$v1,$member['id'],'Project evidence excerpt']);$captureId=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status,published_at) VALUES(?,?,?,?,?,?,'private','published',NOW())")->execute([$annotationPublic,$member['id'],$sourceId,$v1,$captureId,'New project evidence']);$annotationId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id,created_at) VALUES(?,?,?,NOW())')->execute([$projectId,$annotationId,$member['id']]);

$externalCap=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$externalCap,$sourceId,$v2,$owner['id'],'Related external evidence']);$externalCapture=(int)$pdo->lastInsertId();
$externalAnnotation=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status,published_at) VALUES(?,?,?,?,?,?,'public','published',NOW())")->execute([$externalAnnotation,$owner['id'],$sourceId,$v2,$externalCapture,'Corroborating evidence outside project']);$externalId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO annotation_relationships(source_annotation_id,target_annotation_id,relation_type,confidence,rationale,generated_by) VALUES(?,?,'corroborates',0.91,'Related evidence','deterministic'),(?,?,'corroborates',0.91,'Related evidence','deterministic')")->execute([$annotationId,$externalId,$externalId,$annotationId]);

$team=conversation_team_by_public($pdo,$member,$teamPublic);$conversation=conversation_team_ensure($pdo,$team,$member);conversation_message_create($pdo,$member,(string)$conversation['public_id'],'New team evidence needs review.',null,'cognitive-team-'.$run);

$agentConversation=agent_chat_create($pdo,$owner,'Cognitive pending action');$um=conversation_message_create($pdo,$owner,$agentConversation['public_id'],'Create a task',null,'cognitive-agent-'.$run);$am=agent_chat_insert_agent_message($pdo,$agentConversation,'I can propose that task.',(int)$um['id']);
$agentContext=[agent_chat_context_item($pdo,$owner,'research',$projectPublic)];$proposal=agent_action_create_proposals($pdo,$owner,$agentConversation,(int)$am['id'],$agentContext,[['capability'=>'research.create_task','project_id'=>$projectPublic,'arguments'=>['title'=>'Follow up cognitive evidence','task_type'=>'general']]],$agentContext[0]['refs'])[0]??null;
p16($proposal!==null,'Stage 15 pending action fixture is available to the Cognitive Feed');

$jobsBefore=(int)$pdo->query('SELECT COUNT(*) FROM ai_jobs')->fetchColumn();
$feed=cognitive_feed_compose($pdo,$owner,null,8,60);
$jobsAfter=(int)$pdo->query('SELECT COUNT(*) FROM ai_jobs')->fetchColumn();
p16($jobsBefore===$jobsAfter,'Cognitive Feed composition does not create AI ranking jobs');
p16(($feed['ready']??false)&&($feed['total']??0)>0,'Cognitive Feed composes live observations');
p16(count(p16find($feed,'pending_agent_action'))===1,'pending Agent confirmation surfaces in Needs Attention');
p16(count(p16find($feed,'research_gap'))>=1,'unverified unsupported Claim surfaces as Research gap');
p16(count(p16find($feed,'research_conflict'))>=1,'contradicted Claim surfaces as conflict');
p16(count(p16find($feed,'source_change'))===1,'watched and project source-change signals deduplicate into one cognitive card');
p16(count(p16find($feed,'research_task'))>=1,'open overdue Research task surfaces in Continue Researching');
p16(count(p16find($feed,'new_evidence'))>=1,'new project Annotation surfaces as New Evidence');
p16(count(p16find($feed,'related_research'))>=1,'permission-safe Annotation relationship surfaces as Related Research');
p16(count(p16find($feed,'team_activity'))>=1,'unread Team Chat surfaces as Team activity');
$pending=p16find($feed,'pending_agent_action')[0];$conflict=p16find($feed,'research_conflict')[0];p16((int)$pending['score']>(int)$conflict['score'],'pending confirmation outranks ordinary conflict using deterministic scoring');

$outsiderFeed=cognitive_feed_compose($pdo,$outsider,null,8,60);
p16(count(p16find($outsiderFeed,'research_gap'))===0&&count(p16find($outsiderFeed,'new_evidence'))===0&&count(p16find($outsiderFeed,'team_activity'))===0,'outsider cannot receive Team or Research cognitive observations');
p16(!str_contains(json_encode($outsiderFeed,JSON_UNESCAPED_SLASHES),(string)$projectPublic)&&!str_contains(json_encode($outsiderFeed,JSON_UNESCAPED_SLASHES),(string)$annotationPublic),'private project identity and evidence cannot leak to outsider feed');

$memberFeed=cognitive_feed_compose($pdo,$member,null,8,60);
p16(count(p16find($memberFeed,'research_gap'))>=1,'Research collaborator receives shared project intelligence');
p16(count(p16find($memberFeed,'pending_agent_action'))===0,'Agent confirmation cards are scoped to the proposal owner');

$gap=p16find($feed,'research_gap')[0];$gapKey=(string)$gap['key'];cognitive_feed_dismiss($pdo,$owner,$gapKey,'research_gap');
$afterDismiss=cognitive_feed_compose($pdo,$owner,null,8,60);p16(count(array_filter(p16items($afterDismiss),fn($x)=>($x['key']??'')===$gapKey))===0,'explicit dismissal removes one cognitive observation');
$memberAfterDismiss=cognitive_feed_compose($pdo,$member,null,8,60);p16(count(array_filter(p16items($memberAfterDismiss),fn($x)=>($x['key']??'')===$gapKey))===1,'dismissals are private to the current user');
p16(cognitive_feed_restore($pdo,$owner,$gapKey),'dismissed observation can be restored');$restored=cognitive_feed_compose($pdo,$owner,null,8,60);p16(count(array_filter(p16items($restored),fn($x)=>($x['key']??'')===$gapKey))===1,'restored cognitive observation returns');

$sourceCards=p16find($feed,'source_change');$oldSourceKey=(string)$sourceCards[0]['key'];cognitive_feed_dismiss($pdo,$owner,$oldSourceKey,'source_change');
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,3,?,'Cognitive Source v3','Newer changed cognitive evidence',?)")->execute([$sourceId,$url,hash('sha256','cognitive-v3')]);$v3=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,status="edited" WHERE id=?')->execute([$v3,$sourceId]);
$pdo->prepare("INSERT INTO source_change_events(source_id,previous_version_id,new_version_id,change_type,impact_type,target_changed,affected_annotation_count,diff_summary,created_at) VALUES(?,?,?,'edited','passage_changed',1,1,'A second material source change occurred.',DATE_ADD(NOW(),INTERVAL 1 MINUTE))")->execute([$sourceId,$v2,$v3]);
$newFeed=cognitive_feed_compose($pdo,$owner,null,8,60);$newSourceCards=p16find($newFeed,'source_change');p16(count($newSourceCards)>=1&&count(array_filter($newSourceCards,fn($x)=>($x['key']??'')!==$oldSourceKey))>=1,'a genuinely new source-change state resurfaces after the prior card was dismissed');

$restrictedUrl='https://restricted.example.com/'.$run;$restrictedPublic=$pub('source');$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status,monitoring_enabled,moderation_status) VALUES(?,'webpage',?,?,?,'Restricted Cognitive Source','updated',1,'restricted')")->execute([$restrictedPublic,$restrictedUrl,hash('sha256',$restrictedUrl),'restricted.example.com']);$restrictedId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,'Restricted','Restricted text',?)")->execute([$restrictedId,$restrictedUrl,hash('sha256','restricted')]);$restrictedV=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$restrictedV,$restrictedId]);$pdo->prepare('INSERT INTO source_watches(source_id,user_id) VALUES(?,?)')->execute([$restrictedId,$owner['id']]);$pdo->prepare("INSERT INTO source_change_events(source_id,new_version_id,change_type,impact_type,target_changed,diff_summary) VALUES(?,?,'updated','source_updated',0,'Restricted source change')")->execute([$restrictedId,$restrictedV]);
$restrictedFeed=cognitive_feed_compose($pdo,$owner,null,8,60);p16(count(array_filter(p16find($restrictedFeed,'source_change'),fn($x)=>str_contains((string)($x['title']??''),'Restricted Cognitive Source')))===0,'restricted watched sources never leak into a non-admin Cognitive Feed');

$hiddenBefore=cognitive_feed_hidden_count($pdo,$owner);cognitive_feed_dismiss($pdo,$owner,$gapKey,'research_gap');p16(cognitive_feed_hidden_count($pdo,$owner)===$hiddenBefore+1,'hidden-item count reflects explicit dismissals');p16(cognitive_feed_restore_all($pdo,$owner)>=1&&cognitive_feed_hidden_count($pdo,$owner)===0,'Show hidden resets the user Cognitive Feed dismissals');

echo "Phase 16 Cognitive Feed MariaDB suite passed.\n";
