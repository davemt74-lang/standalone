<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=getenv('DB_DSN')?:'';$dbUser=getenv('DB_USER')?:'root';$dbPass=getenv('DB_PASS')?:'';
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','feed','research-workspace','research-knowledge','research-intelligence','research-reports','object-handoff','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow','unified-activity','action-center','workspace-context'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p36(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p36action(array $center,string $type): ?array {foreach((array)($center['items']??[]) as $item)if((string)($item['source_type']??'')===$type)return $item;return null;}

$run='p36'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array {
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('JourneyOwner');$reviewer=$makeUser('JourneyReviewer');$outsider=$makeUser('JourneyOutsider');

/* Team + browser-equivalent capture */
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Journey Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$reviewer['id']]);

$url='https://journey-'.$run.'.example.test/article';$sourcePublic=$pub('source');$sourceTitle='Phase 36 Journey Source';
$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status,monitoring_enabled,moderation_status) VALUES(?,'article',?,?,?,?,'current',1,'visible')")
  ->execute([$sourcePublic,$url,hash('sha256',$url),'journey.example.test',$sourceTitle]);$sourceId=(int)$pdo->lastInsertId();
$selected='critical evidence phrase '.$run;$v1Text='Background text. '.$selected.' Additional supporting context.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash,captured_at) VALUES(?,1,?,?,?,?,?,NOW())")
  ->execute([$sourceId,$url,$sourceTitle,$v1Text,hash('sha256',$v1Text),hash('sha256',$selected)]);$v1=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$v1,$sourceId]);
$capturePublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")
  ->execute([$capturePublic,$sourceId,$v1,$owner['id'],$selected]);$captureId=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status,published_at) VALUES(?,?,?,?,?,?, 'team',?,'published',NOW())")
  ->execute([$annotationPublic,$owner['id'],$sourceId,$v1,$captureId,'Journey annotation',$teamId]);
p36(annotation_access($pdo,$annotationPublic,$owner)!==null&&annotation_access($pdo,$annotationPublic,$reviewer)!==null,'Browser-equivalent capture publishes one permission-checked Team Annotation tied to the preserved Source Version');

$feed=feed_annotation_rows($pdo,$owner,'following',null,null,15);
p36(in_array($annotationPublic,array_column($feed['annotations'],'public_id'),true),'new Annotation appears in the signed-in owner Following feed');

/* Team handoff */
$team=['id'=>$teamId,'public_id'=>$teamPublic,'name'=>'Journey Team','owner_user_id'=>$owner['id'],'access_role'=>'owner','member_count'=>2];
$teamConversation=conversation_team_ensure($pdo,$team,$owner);
$shared=conversation_message_create($pdo,$owner,(string)$teamConversation['public_id'],'Please review this evidence.',null,'journey-share-'.$run,[['type'=>'annotation','public_id'=>$annotationPublic]]);
p36($shared['created']&&count($shared['attachments'])===1,'Annotation hands off to Team Chat as a structured object reference');
$reviewerRows=conversation_message_rows($pdo,$reviewer,(string)$teamConversation['public_id']);
$sharedRow=array_values(array_filter((array)$reviewerRows['messages'],fn($row)=>(string)$row['public_id']===(string)$shared['public_id']))[0]??[];
p36(($sharedRow['attachments'][0]['available']??false)===true&&($sharedRow['attachments'][0]['public_id']??'')===$annotationPublic,'Team recipient re-resolves the authoritative Annotation rather than receiving a copied shadow payload');

/* Research project */
$projectPublic=$pub('project');$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active')")
  ->execute([$projectPublic,$owner['id'],$teamId,'Journey Research','End-to-end release journey']);$projectId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$sourceId,$owner['id']]);
$pdo->prepare("INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) SELECT ?,id,? FROM annotations WHERE public_id=?")->execute([$projectId,$owner['id'],$annotationPublic]);
$project=project_access($pdo,(int)$owner['id'],$projectPublic);p36((bool)$project&&project_access($pdo,(int)$reviewer['id'],$projectPublic)!==null,'Team Annotation continues into one shared Research project without changing authorization');

/* Agent conversation + explicit confirmed actions */
$agentPublic=$pub('agent');$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,'Journey Agent')")->execute([$agentPublic,$owner['id']]);$agentConversationId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$agentConversationId,$owner['id']]);
$proposal=function(string $capability,array $args)use($pdo,$owner,$projectId,$agentConversationId,$pub,$run): array {
    $assistantPublic=$pub('assistant');$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,body) VALUES(?,?,NULL,'agent','Proposed bounded Research action.')")->execute([$assistantPublic,$agentConversationId]);$assistantId=(int)$pdo->lastInsertId();
    $proposalPublic=$pub('proposal');$hash=research_workspace_input_hash($pdo,$projectId);
    $pdo->prepare("INSERT INTO agent_action_proposals(public_id,conversation_id,assistant_message_id,proposed_by_user_id,project_id,capability_key,status,arguments_json,provenance_json,project_state_hash,dedupe_key,expires_at) VALUES(?,?,?,?,?,?,'pending',?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))")
      ->execute([$proposalPublic,$agentConversationId,$assistantId,$owner['id'],$projectId,$capability,json_encode($args,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode([['type'=>'research','public_id'=>(string)$projectId]],JSON_UNESCAPED_SLASHES),$hash,hash('sha256',$run.$proposalPublic)]);
    return agent_action_confirm_execute($pdo,$owner,$proposalPublic);
};
$claimResult=$proposal('research.create_claim',['statement'=>'The captured source supports the Phase 36 proposition.','claim_type'=>'factual']);
$claimPublic=(string)$claimResult['result']['public_id'];p36($claimResult['status']==='executed'&&research_claim_access($pdo,$owner,$claimPublic)!==null,'explicitly confirmed Agent action creates the Research Claim');
$evidenceResult=$proposal('research.attach_annotation_evidence',['claim_id'=>$claimPublic,'annotation_id'=>$annotationPublic,'relationship'=>'primary','note'=>'Captured browser evidence']);
p36($evidenceResult['status']==='executed','explicitly confirmed Agent action attaches the original Annotation as Claim evidence');
$pdo->prepare("UPDATE research_claims SET status='supported' WHERE public_id=?")->execute([$claimPublic]);
$findingResult=$proposal('research.create_finding',['title'=>'Journey Finding','summary'=>'The reviewed captured evidence supports the proposition.','claim_ids'=>[$claimPublic]]);
$findingPublic=(string)$findingResult['result']['public_id'];$pdo->prepare("UPDATE research_findings SET status='final' WHERE public_id=?")->execute([$findingPublic]);
p36($findingResult['status']==='executed'&&research_finding_access($pdo,$owner,$findingPublic)!==null,'explicitly confirmed Agent action synthesizes a Finding from the Claim');

/* Human review */
$review=research_review_create($pdo,$owner,'finding',$findingPublic,[$reviewer['id']],null,'Review evidence and synthesis before publication.');
p36(!empty($review['public_id']),'Finding enters the collaborative human review workflow');
research_review_respond($pdo,$reviewer,(string)$review['public_id'],'approve','Evidence and synthesis reviewed.');
$completed=research_review_complete($pdo,$owner,(string)$review['public_id']);
p36(($completed['status']??'')==='completed','assigned collaborator response can be completed by the Research owner');
$workflow=research_workflow_state($pdo,$owner,$projectPublic);p36(($workflow['next']['stage']??'')==='publish','current completed human review advances the Research lifecycle to Publish');

/* Publication */
$published=research_report_publish($pdo,$project,$owner,'public','Journey Public Report','Immutable Phase 36 publication.','Journey');
$report=research_report_access($pdo,(string)$published['public_id'],$owner);$version=research_report_version($pdo,$report,(int)$published['version_number']);
p36($version!==null&&hash_equals((string)$version['snapshot_hash'],hash('sha256',(string)$version['snapshot_json'])),'publication creates an immutable hash-verifiable Report Version');
p36(research_report_access($pdo,(string)$published['public_id'],$outsider)!==null&&project_access($pdo,(int)$outsider['id'],$projectPublic)===null,'public Report visibility does not grant outsider access to private Team Research');

/* Workspace continuity across authoritative refs */
$workspace=workspace_context_resolve($pdo,$owner,['team_public_id'=>$teamPublic,'research_public_id'=>$projectPublic,'object_type'=>'finding','object_public_id'=>$findingPublic,'agent_conversation_public_id'=>$agentPublic]);
p36(($workspace['team']['public_id']??'')===$teamPublic&&($workspace['research']['public_id']??'')===$projectPublic&&($workspace['object']['public_id']??'')===$findingPublic&&($workspace['agent']['public_id']??'')===$agentPublic,'Phase 34 workspace continuity re-resolves Team Research object and Agent refs for the complete journey');

/* Monitor: source changes after publication */
$v2Text='Background text changed. The previously captured critical passage is no longer present.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash,captured_at) VALUES(?,2,?,?,?,?,?,NOW())")
  ->execute([$sourceId,$url,$sourceTitle,$v2Text,hash('sha256',$v2Text),hash('sha256','new-target-'.$run)]);$v2=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$v2,$sourceId]);
$pdo->prepare("INSERT INTO source_change_events(source_id,previous_version_id,new_version_id,change_type,target_changed,diff_summary) VALUES(?,?,?,'updated',1,?)")->execute([$sourceId,$v1,$v2,'Referenced passage removed in Phase 36 fixture.']);$eventId=(int)$pdo->lastInsertId();
$integrity=source_integrity_analyze_event($pdo,$eventId);
p36(in_array((string)$integrity['impact_type'],['passage_changed','passage_missing'],true)&&$integrity['affected_annotation_count']>=1,'Source monitoring detects that the preserved Annotation passage changed after publication');
$impact=change_impact_project_summary($pdo,$owner,$projectPublic,10);
p36($impact['unresolved']>0&&$impact['affected_claims']>0&&$impact['affected_findings']>0&&$impact['affected_reports']>0,'source change propagates into downstream Claim Finding and published Report impact');
$center=action_center_compose($pdo,$owner,null,100);
$impactAction=p36action($center,'change_impact');p36((bool)$impactAction&&($impactAction['kind']??'')==='review','Action Center routes downstream source impact back to human Review');

change_impact_set_decision($pdo,$owner,$eventId,'project',$projectPublic,'resolved','Reviewed the downstream impact for the complete project.');
$resolvedCenter=action_center_compose($pdo,$owner,null,100);
p36(p36action($resolvedCenter,'change_impact')===null,'resolving authoritative project impact automatically removes the Action Center item');

/* Revocation / privacy after journey */
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$reviewer['id']]);
p36(project_access($pdo,(int)$reviewer['id'],$projectPublic)===null&&annotation_access($pdo,$annotationPublic,$reviewer)===null,'Team removal revokes Research and Team Annotation access after the journey');
p36(conversation_message_rows($pdo,$reviewer,(string)$teamConversation['public_id'])===null,'Team removal also revokes the old Team conversation path');
p36(research_report_access($pdo,(string)$published['public_id'],$reviewer)!==null,'revoked collaborator may still access only the intentionally public immutable Report');

echo "Phase 36 end-to-end workspace journey passed: Browser → Annotation → Feed → Team → Research → Agent → Review → Publish → Monitor → Action Center.\n";
