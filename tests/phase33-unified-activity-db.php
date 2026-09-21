<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=getenv('DB_DSN')?:'';$dbUser=getenv('DB_USER')?:'root';$dbPass=getenv('DB_PASS')?:'';
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','unified-activity'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p33(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p33has(array $items,string $type,?string $publicId=null): bool {
    foreach($items as $item){
        if((string)($item['type']??'')!==$type)continue;
        if($publicId===null||(string)($item['object']['public_id']??'')===$publicId)return true;
    }
    return false;
}
function p33count(array $items,string $type): int {return count(array_filter($items,fn($item)=>(string)($item['type']??'')===$type));}

$run='p33'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array {
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ActivityOwner');$member=$makeUser('ActivityMember');$outsider=$makeUser('ActivityOutsider');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Activity Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);
$team=['id'=>$teamId,'public_id'=>$teamPublic,'name'=>'Activity Team','owner_user_id'=>$owner['id'],'access_role'=>'owner','member_count'=>2];
$conversation=conversation_team_ensure($pdo,$team,$owner);

$projectPublic=$pub('project');$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active')")
  ->execute([$projectPublic,$owner['id'],$teamId,'Activity Research','Unified activity fixture']);$projectId=(int)$pdo->lastInsertId();$project=project_access($pdo,(int)$owner['id'],$projectPublic);
p33((bool)$project,'owner can access Phase 33 Research fixture');

$url='https://activity-'.$run.'.example.test/article';$sourcePublic=$pub('source');
$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status,monitoring_enabled,moderation_status) VALUES(?,'article',?,?,?,'Activity Source','current',1,'visible')")
  ->execute([$sourcePublic,$url,hash('sha256',$url),'activity.example.test']);$sourceId=(int)$pdo->lastInsertId();
$text='Phase 33 evidence '.$run;$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash,captured_at) VALUES(?,1,?,'Activity Source',?,?,?,NOW())")
  ->execute([$sourceId,$url,$text,hash('sha256',$text),hash('sha256','target-'.$run)]);$versionId=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$sourceId]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$sourceId,$owner['id']]);

$makeAnnotation=function(string $visibility,?int $teamId,string $comment)use($pdo,$owner,$sourceId,$versionId,$pub): string {
    $capturePublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")
      ->execute([$capturePublic,$sourceId,$versionId,$owner['id'],'Captured '.$comment]);$captureId=(int)$pdo->lastInsertId();
    $annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status,published_at) VALUES(?,?,?,?,?,?,?,?, 'published',NOW())")
      ->execute([$annotationPublic,$owner['id'],$sourceId,$versionId,$captureId,$comment,$visibility,$teamId]);
    return $annotationPublic;
};
$teamAnnotation=$makeAnnotation('team',$teamId,'Team Activity Annotation');
$privateAnnotation=$makeAnnotation('private',null,'Owner Private Activity Annotation');
$pdo->prepare("INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) SELECT ?,id,? FROM annotations WHERE public_id=?")
  ->execute([$projectId,$owner['id'],$teamAnnotation]);

$message=conversation_message_create($pdo,$owner,(string)$conversation['public_id'],'Phase 33 Team message.',null,'phase33-'.$run);
p33($message['created'],'Team message fixture created');

$claimPublic=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'Phase 33 Claim','factual','supported')")
  ->execute([$claimPublic,$projectId,$owner['id']]);
$findingPublic=$pub('finding');$pdo->prepare("INSERT INTO research_findings(public_id,project_id,created_by_user_id,title,summary,status) VALUES(?,?,?,'Phase 33 Finding','Phase 33 synthesis.','final')")
  ->execute([$findingPublic,$projectId,$owner['id']]);

$privateReport=research_report_publish($pdo,$project,$owner,'private','Private Activity Report','Owner-only version.','Activity');
$teamReport=research_report_publish($pdo,$project,$owner,'team','Team Activity Report','Team-visible version.','Activity');

$agentConversationPublic=$pub('agent-conv');$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,'Phase 33 Agent')")
  ->execute([$agentConversationPublic,$owner['id']]);$agentConversationId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$agentConversationId,$owner['id']]);
$userMessagePublic=$pub('agent-msg');$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,body) VALUES(?,?,?,'user','Do the bounded action.')")
  ->execute([$userMessagePublic,$agentConversationId,$owner['id']]);$userMessageId=(int)$pdo->lastInsertId();
$assistantMessagePublic=$pub('assistant-msg');$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,body) VALUES(?,?,NULL,'agent','Proposed action.')")
  ->execute([$assistantMessagePublic,$agentConversationId]);$assistantMessageId=(int)$pdo->lastInsertId();
$proposalPublic=$pub('proposal');$pdo->prepare("INSERT INTO agent_action_proposals(public_id,conversation_id,assistant_message_id,proposed_by_user_id,project_id,capability_key,status,arguments_json,provenance_json,project_state_hash,dedupe_key,result_type,result_public_id,result_json,expires_at,executed_at) VALUES(?,?,?,?,?,'research.create_note','executed','{}','{}',?,?, 'research_note',?, ?,DATE_ADD(NOW(),INTERVAL 1 DAY),NOW())")
  ->execute([$proposalPublic,$agentConversationId,$assistantMessageId,$owner['id'],$projectId,hash('sha256','state-'.$run),hash('sha256','dedupe-'.$run),$pub('note'),json_encode(['label'=>'Agent note created'],JSON_UNESCAPED_SLASHES)]);

$memberItems=unified_activity_collect($pdo,$member,100);
p33(p33has($memberItems,'annotation_published',$teamAnnotation),'Team member sees current same-Team Annotation activity');
p33(!p33has($memberItems,'annotation_published',$privateAnnotation),'Team member does not see owner private Annotation activity');
p33(p33has($memberItems,'team_message'),'Team member sees current Team message activity');
p33(p33has($memberItems,'research_evidence_added',$teamAnnotation),'Team member sees current Research evidence activity');
p33(p33has($memberItems,'claim_updated',$claimPublic)&&p33has($memberItems,'finding_updated',$findingPublic),'Team member sees accessible Claim and Finding activity');
$claimActivity=array_values(array_filter($memberItems,fn($item)=>(string)($item['type']??'')==='claim_updated'))[0]??[];
$findingActivity=array_values(array_filter($memberItems,fn($item)=>(string)($item['type']??'')==='finding_updated'))[0]??[];
p33(count((array)($claimActivity['context']??[]))===1&&($claimActivity['context'][0]['type']??'')==='research','Claim activity hands Agent the supported Research context only');
p33(count((array)($findingActivity['context']??[]))===1&&($findingActivity['context'][0]['type']??'')==='research','Finding activity hands Agent the supported Research context only');
p33(p33count($memberItems,'report_published')===1,'Team member sees only the accessible Team Report Version, not the private version');
p33(!p33has($memberItems,'agent_action_executed',$proposalPublic),'Team member cannot see another user Agent action lifecycle');

$ownerItems=unified_activity_collect($pdo,$owner,100);
p33(p33has($ownerItems,'annotation_published',$privateAnnotation),'Annotation owner sees their own private published activity');
p33(p33count($ownerItems,'report_published')===2,'project owner sees both private and Team Report Versions');
p33(p33has($ownerItems,'agent_action_executed',$proposalPublic),'Agent action owner sees their own executed Agent activity');
for($i=1,$prev=null;$i<count($ownerItems);$i++){$prev=strtotime((string)$ownerItems[$i-1]['created_at'])?:0;$now=strtotime((string)$ownerItems[$i]['created_at'])?:0;p33($prev>=$now,'unified activity remains reverse chronological');}

$outsiderItems=unified_activity_collect($pdo,$outsider,100);
p33(!p33has($outsiderItems,'team_message')&&!p33has($outsiderItems,'research_evidence_added')&&!p33has($outsiderItems,'claim_updated')&&!p33has($outsiderItems,'report_published'),'outsider receives no Team or private Research workspace activity');

$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$owner['id'],$member['id']]);
$blockedItems=unified_activity_collect($pdo,$member,100);
p33(!p33has($blockedItems,'annotation_published',$teamAnnotation),'user block removes direct Annotation activity');
p33(!p33has($blockedItems,'research_evidence_added',$teamAnnotation),'user block removes blocked-author Annotation commentary from Research activity');
$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$owner['id'],$member['id']]);

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$member['id']]);
$revokedItems=unified_activity_collect($pdo,$member,100);
p33(!p33has($revokedItems,'team_message')&&!p33has($revokedItems,'annotation_published',$teamAnnotation),'Team revocation removes Team chat and Team Annotation activity');
p33(!p33has($revokedItems,'research_evidence_added')&&!p33has($revokedItems,'claim_updated')&&!p33has($revokedItems,'finding_updated')&&!p33has($revokedItems,'report_published'),'Team revocation removes historical Research and publication activity instead of preserving a leaked copy');

$runtime=file_get_contents($root.'/app/unified-activity.php');
p33(!str_contains($runtime,'CREATE TABLE')&&!str_contains($runtime,'INSERT INTO unified_activity')&&!str_contains($runtime,'UPDATE unified_activity'),'Phase 33 derives activity without a shadow activity store');
p33(str_contains($runtime,'annotation_access')&&str_contains($runtime,'conversation_access')&&str_contains($runtime,'project_access')&&str_contains($runtime,'research_report_version_access'),'Phase 33 rechecks authoritative object access');

echo "Phase 33 Unified Activity & Context Awareness MariaDB suite passed.\n";
