<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow','research-system-reports','research-report-studio','research-intelligence-delivery'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p69(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p69throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p69(research_intelligence_delivery_ready($pdo),'Phase 69 intelligence-delivery schema is ready.');
p69(array_keys(research_report_subscription_policies())===['every_run','if_changed','material_change_only','if_stale'],'Phase 69 exposes the four governed delivery policies.');

$run='p69'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('DeliveryOwner','admin');$outsider=$makeUser('DeliveryOutsider');

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 69 Intelligence Agent','description'=>'Recurring intelligence delivery fixture.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);p69((bool)$project,'Private Research Agent project is available.');

$source=ensure_source($pdo,'https://example.com/'.$run.'/delivery','Phase 69 Delivery Source');$sourceText='Mercury orchard demand increased 18 percent with verified buyer interest.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([(int)$source['id'],'https://example.com/'.$run.'/delivery','Phase 69 Delivery Source',$sourceText,hash('sha256',$sourceText)]);
$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$versionId,(int)$source['id']]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$claimPublic=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,?, 'factual','supported')")
  ->execute([$claimPublic,(int)$project['id'],(int)$owner['id'],'Mercury orchard demand increased by 18 percent.']);$claimId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship,note) VALUES(?,?,?,'source_version',?,'primary','Phase 69 primary evidence')")
  ->execute([$pub('ev'),$claimId,(int)$owner['id'],$versionId]);
$findingPublic=$pub('finding');$pdo->prepare("INSERT INTO research_findings(public_id,project_id,created_by_user_id,title,summary,status) VALUES(?,?,?,?,?,'draft')")
  ->execute([$findingPublic,(int)$project['id'],(int)$owner['id'],'Mercury demand signal','Buyer evidence supports increasing Mercury orchard demand.']);$findingId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO finding_claims(finding_id,claim_id,added_by_user_id,relationship,position) VALUES(?,?,?,'supports',0)")->execute([$findingId,$claimId,(int)$owner['id']]);

$program=research_program_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],'title'=>'Mercury Intelligence Cycle','objective'=>'Track meaningful changes in Mercury demand.','cadence'=>'daily','timezone_name'=>'UTC','run_time_local'=>'09:00',
  'priority'=>'medium','quiet_mode'=>'material_only','materiality_threshold'=>'important','deliverable_type'=>'weekly_report','monthly_run_limit'=>50
]);
$program=research_program_access($pdo,$owner,(string)$program['public_id']);p69((bool)$program,'Research Program owns the delivery schedule.');
$preset=research_report_studio_preset_save($pdo,$owner,(string)$agent['public_id'],[
  'name'=>'Mercury Intelligence Brief','report_type'=>'research_brief','title'=>'Mercury Intelligence Brief','depth'=>'standard','focus_query'=>'Mercury',
  'program_id'=>$program['public_id'],'source_ids'=>[(string)$source['public_id']],'claim_ids'=>[$claimPublic],'finding_ids'=>[$findingPublic]
]);
p69(($preset['program_public_id']??'')===$program['public_id'],'Saved Report preset is bound to the same Research Program.');

$sub=research_report_subscription_create($pdo,$owner,(string)$agent['public_id'],[
  'name'=>'Mercury material-change delivery','preset_id'=>$preset['public_id'],'program_id'=>$program['public_id'],'delivery_policy'=>'material_change_only',
  'notify_in_app'=>true,'notify_agent_chat'=>true,'include_summary'=>true,'include_comparison'=>true
]);
p69(($sub['status']??'')==='active'&&($sub['delivery_policy']??'')==='material_change_only','User creates an active per-Agent Report subscription.');
p69(research_report_subscription_access($pdo,$outsider,(string)$sub['public_id'])===null,'Report subscriptions are user-specific and inaccessible to another user.');
$agentContext=research_intelligence_delivery_agent_context($pdo,$owner,(string)$agent['public_id']);
p69(str_contains($agentContext,(string)$sub['public_id'])&&str_contains($agentContext,'Mercury material-change delivery'),'Research Agent context includes this user’s subscription state.');

$makeRun=function(int $material)use($pdo,$program,$owner): array{
    $public=research_program_enqueue($pdo,$program,(int)$owner['id'],'manual');if(!$public)throw new RuntimeException('Could not enqueue Program fixture run.');
    $row=research_program_run_row($pdo,$owner,$public);if(!$row)throw new RuntimeException('Could not load Program fixture run.');
    $pdo->prepare('UPDATE research_program_runs SET material_change_count=? WHERE id=?')->execute([$material,(int)$row['id']]);$row['material_change_count']=$material;return $row;
};
$finishRun=function(array $row,string $status='completed')use($pdo): void{$pdo->prepare('UPDATE research_program_runs SET status=?,completed_at=NOW() WHERE id=?')->execute([$status,(int)$row['id']]);};

$beforeDocs=(int)$pdo->query("SELECT COUNT(*) FROM research_workspace_objects WHERE project_id=".(int)$project['id']." AND object_type='document'")->fetchColumn();
$run1=$makeRun(1);$first=research_intelligence_delivery_process_program_run($pdo,$program,(int)$run1['id'],'program_completed');$finishRun($run1);
p69(count($first)===1&&($first[0]['status']??'')==='delivered','First eligible Program cycle creates the baseline intelligence delivery.');
$deliveries=research_report_delivery_list($pdo,$owner,(string)$agent['public_id'],20,true);$delivery1=$deliveries[0]??null;
p69($delivery1&&($delivery1['status']??'')==='delivered'&&!empty($delivery1['report_public_id']),'Baseline delivery contains a generated Report Run.');
$report1=research_system_report_access($pdo,$owner,(string)$delivery1['report_public_id']);
p69($report1&&($report1['generation_mode']??'')==='program'&&empty($report1['document_public_id']),'Program delivery generates a Report Run in program mode and never creates a Document.');
$afterDocs=(int)$pdo->query("SELECT COUNT(*) FROM research_workspace_objects WHERE project_id=".(int)$project['id']." AND object_type='document'")->fetchColumn();
p69($beforeDocs===$afterDocs,'Scheduled intelligence delivery creates no Research Document.');

$q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND object_type='research_report_delivery'");$q->execute([(int)$owner['id']);p69((int)$q->fetchColumn()===1,'Delivered intelligence creates one in-app notification.');
$q=$pdo->prepare("SELECT n.* FROM notifications n WHERE n.user_id=? AND n.object_type='research_report_delivery' ORDER BY n.id DESC LIMIT 1");$q->execute([(int)$owner['id']);$notification=$q->fetch();
p69($notification&&str_contains((string)notification_url($pdo,$owner,$notification),'view=inbox'),'Delivery notification resolves to the per-Agent Intelligence Inbox.');

$q=$pdo->prepare("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=? AND event_type='agent_message_created' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.source'))='research_intelligence_delivery'");$q->execute([(int)$agent['conversation_id']);p69((int)$q->fetchColumn()===1,'Private Research Agent receives one user-specific delivery chat update.');
$q=$pdo->prepare("SELECT COUNT(*) FROM conversation_message_attachments cma JOIN conversation_messages cm ON cm.id=cma.message_id WHERE cm.conversation_id=? AND cma.attachment_type='report' AND cma.object_public_id=?");$q->execute([(int)$agent['conversation_id'],(string)$report1['public_id']]);p69((int)$q->fetchColumn()===1,'Delivery Agent Chat update attaches the generated Report Run.');

$dedup=research_intelligence_delivery_process_program_run($pdo,$program,(int)$run1['id'],'program_completed');
p69(count($dedup)===1&&($dedup[0]['status']??'')==='deduplicated','Worker/reconciliation retry cannot deliver the same subscription Program cycle twice.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_report_deliveries WHERE subscription_id=?');$q->execute([(int)$sub['id']);p69((int)$q->fetchColumn()===1,'Same-cycle dedupe leaves only one delivery record.');

$run2=$makeRun(0);$quiet=research_intelligence_delivery_process_program_run($pdo,$program,(int)$run2['id'],'program_quiet');$finishRun($run2,'skipped');
p69(count($quiet)===1&&($quiet[0]['status']??'')==='suppressed'&&($quiet[0]['reason']??'')==='no_material_program_change','Quiet/no-material Program cycle is preserved as a suppressed delivery without notifying the user.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_system_reports WHERE preset_id=?');$q->execute([(int)$preset['id']);p69((int)$q->fetchColumn()===1,'Suppressed no-change delivery does not generate a redundant Report Run.');

$pdo->prepare("UPDATE research_claims SET statement=?,updated_at=NOW() WHERE id=?")->execute(['Mercury orchard demand increased by 26 percent.',$claimId]);
$run3=$makeRun(1);$changed=research_intelligence_delivery_process_program_run($pdo,$program,(int)$run3['id'],'program_completed');$finishRun($run3);
p69(count($changed)===1&&($changed[0]['status']??'')==='delivered','Material Program cycle affecting the subscribed Report scope produces a new delivery.');
$deliveries=research_report_delivery_list($pdo,$owner,(string)$agent['public_id'],20,true);$delivery3=$deliveries[0]??null;
p69($delivery3&&($delivery3['report_public_id']??'')!==($delivery1['report_public_id']??'')&&(int)$delivery3['material_change_count']===1,'Material delivery links a new Report Run and Program material-change count.');
p69(($delivery3['comparison']['claims']['changed_count']??0)>=1,'Delivered intelligence stores the Report comparison showing the changed scoped Claim.');

$items=[];research_intelligence_delivery_cognitive_observations($pdo,$owner,$items,10);
p69(count(array_filter($items,fn($i)=>($i['type']??'')==='research_report_delivery'))>=1,'Delivered intelligence surfaces through the existing Now/cognitive feed.');

$chatBefore=(int)$pdo->query('SELECT COUNT(*) FROM conversation_messages WHERE conversation_id='.(int)$agent['conversation_id'])->fetchColumn();
$teamSub=$sub;$teamSub['team_id']=999;
p69(research_intelligence_delivery_chat_update($pdo,$teamSub,research_system_report_access($pdo,$owner,(string)$delivery3['report_public_id']),'Private preference must not leak into shared Team chat.','team-privacy-test')===false,'User-specific delivery does not post into a shared Team Research Agent chat.');
$chatAfter=(int)$pdo->query('SELECT COUNT(*) FROM conversation_messages WHERE conversation_id='.(int)$agent['conversation_id'])->fetchColumn();
p69($chatBefore===$chatAfter,'Team-chat privacy guard creates no message.');

$viewed=research_report_delivery_mark_viewed($pdo,$owner,(string)$delivery3['public_id']);p69(($viewed['status']??'')==='viewed'&&!empty($viewed['viewed_at']),'User can mark an intelligence delivery reviewed.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_report_delivery_events WHERE delivery_id=? AND event_type='viewed'");$q->execute([(int)$delivery3['id']]);$viewEvents=(int)$q->fetchColumn();
research_report_delivery_mark_viewed($pdo,$owner,(string)$delivery3['public_id']);$q->execute([(int)$delivery3['id']]);p69($viewEvents===1&&(int)$q->fetchColumn()===1,'Mark reviewed is idempotent and does not duplicate delivery audit events.');

$retryRun=$makeRun(1);$freshSub=research_report_subscription_access($pdo,$owner,(string)$sub['public_id']);$reserve1=research_intelligence_delivery_reserve($pdo,$freshSub,$retryRun,'program_completed');
$pdo->prepare("UPDATE research_report_deliveries SET status='failed',reason_code='fixture_failure' WHERE id=?")->execute([(int)$reserve1['id']]);
$reserve2=research_intelligence_delivery_reserve($pdo,$freshSub,$retryRun,'program_completed');p69(!empty($reserve2['existing'])&&!empty($reserve2['retryable'])&&(string)$reserve2['public_id']===(string)$reserve1['public_id'],'Failed same-cycle reservation is retryable without creating a duplicate delivery row.');
$pdo->prepare("UPDATE research_report_deliveries SET status='suppressed',reason_code='fixture_cleanup' WHERE id=?")->execute([(int)$reserve1['id']]);$finishRun($retryRun,'skipped');

$sub=research_report_subscription_set_status($pdo,$owner,(string)$sub['public_id'],'paused',false);p69(($sub['status']??'')==='paused','Subscription can be paused without deleting history.');
$manual=research_intelligence_delivery_run_manual($pdo,[],$owner,(string)$sub['public_id']);
p69(($manual['status']??'')==='delivered','Deliver now remains available while scheduled delivery is paused.');
$sub=research_report_subscription_set_status($pdo,$owner,(string)$sub['public_id'],'active',false);p69(($sub['status']??'')==='active','Paused subscription can resume.');

$clean=agent_action_clean_arguments('research.create_report_subscription',['preset_id'=>$preset['public_id'],'program_id'=>$program['public_id'],'delivery_policy'=>'if_changed']);
p69(agent_action_validate_project_arguments($pdo,$owner,$project,'research.create_report_subscription',$clean,[]),'Governed Agent action validates a same-Agent preset and Program before proposing a subscription.');
$cleanStatus=agent_action_clean_arguments('research.set_report_subscription_status',['subscription_id'=>$sub['public_id'],'status'=>'paused']);
p69(agent_action_validate_project_arguments($pdo,$owner,$project,'research.set_report_subscription_status',$cleanStatus,[]),'Governed Agent action validates the user-owned Report subscription lifecycle change.');

$all=research_report_delivery_list($pdo,$owner,(string)$agent['public_id'],30,true);
p69(count(array_filter($all,fn($d)=>($d['status']??'')==='suppressed'))>=1&&count(array_filter($all,fn($d)=>in_array(($d['status']??''),['delivered','viewed'],true)))>=3,'Intelligence Inbox preserves delivered/viewed and intentionally suppressed cycles.');

echo "Phase 69 Research Intelligence Delivery & Subscriptions database journey passed.\n";
