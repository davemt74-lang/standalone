<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/functions.php';require_once $root.'/app/data-attribution.php';require_once $root.'/app/data-datasets.php';require_once $root.'/app/ai.php';require_once $root.'/app/data-evaluations.php';require_once $root.'/app/data-model-registry.php';require_once $root.'/app/data-training.php';require_once $root.'/app/data-post-training.php';require_once $root.'/app/data-model-release.php';
function p43(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
p43(data_model_release_ready($pdo),'Phase 43 Model Release Decision schema is available');

$run='p43'.substr(bin2hex(random_bytes(7)),0,12);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(4)),0,8);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$public=$pub('u');$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,password_hash,status,role) VALUES(?,?,?,?,?,'active',?)")->execute([$public,$username,$name,$username.'@example.test',password_hash('phase43-test',PASSWORD_DEFAULT),$role]);return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'username'=>$username,'display_name'=>$name,'role'=>$role];};
$creator=$makeUser('ReleaseCreator','admin');$reviewer=$makeUser('ReleaseReviewer','admin');$reviewer2=$makeUser('ReleaseReviewerTwo','admin');$outsider=$makeUser('ReleaseOutsider');
$routeBefore=(int)($pdo->query('SELECT admin_default_model_id FROM ai_settings WHERE id=1')->fetchColumn()?:0);

$registry=data_model_registry_create($pdo,$creator,['name'=>'Phase43 Model Family '.$run,'description'=>'Release decision fixture']);
$baseline=data_model_version_create($pdo,$creator,$registry['public_id'],['version_label'=>'baseline-v1','origin'=>'annotated_trained','architecture'=>'fixture','intended_use'=>'rollback baseline']);$pdo->prepare("UPDATE data_model_versions SET status='approved',status_changed_at=NOW() WHERE id=?")->execute([$baseline['id']]);$baseline=data_model_version_get($pdo,$baseline['public_id']);
$candidate=data_model_version_create($pdo,$creator,$registry['public_id'],['version_label'=>'candidate-v1','origin'=>'annotated_trained','architecture'=>'fixture trained','intended_use'=>'release candidate','artifact_ref'=>'registry://phase43/candidate','artifact_hash'=>hash('sha256','candidate-'.$run)]);
p43($baseline['status']==='approved'&&$candidate['status']==='experimental'&&data_model_version_integrity($baseline)['ok']&&data_model_version_integrity($candidate)['ok'],'fixture has integrity-valid governed baseline and experimental candidate');

$otherRegistry=data_model_registry_create($pdo,$creator,['name'=>'Other Family '.$run]);
$wrongRollback=data_model_version_create($pdo,$creator,$otherRegistry['public_id'],['version_label'=>'wrong-rollback','origin'=>'annotated_trained']);$pdo->prepare("UPDATE data_model_versions SET status='approved' WHERE id=?")->execute([$wrongRollback['id']]);$wrongRollback=data_model_version_get($pdo,$wrongRollback['public_id']);

$datasetPublic=$pub('dataset');$policyJson=data_attribution_encode(['fixture'=>'phase43']);$pdo->prepare("INSERT INTO data_datasets(public_id,name,slug,version_number,purpose,status,selection_policy_json,selection_policy_hash,created_by_user_id) VALUES(?,?,?,1,'training','frozen',?,?,?)")->execute([$datasetPublic,'Phase43 Fixture Dataset','phase43-'.$run,$policyJson,hash('sha256',$policyJson),$creator['id']]);$datasetId=(int)$pdo->lastInsertId();

$makePacket=function(string $suffix,array $model)use($pdo,$creator,$registry,$baseline,$datasetId,$pub,$run): array {
    $config='{}';$completion=hash('sha256','completion-'.$suffix.'-'.$run);$jobPublic=$pub('train');$pdo->prepare("INSERT INTO data_training_jobs(public_id,dataset_id,base_model_version_id,output_registry_id,use_class,executor_type,method,training_format,status,output_version_label,config_json,config_hash,output_model_version_id,completion_hash,created_by_user_id,completed_at) VALUES(?,?,?,?,'internal_training','manual','supervised','chat_messages','succeeded',?,?,?,?,?,?,NOW())")->execute([$jobPublic,$datasetId,$baseline['id'],$registry['id'],$model['version_label'],$config,hash('sha256',$config),$model['id'],$completion,$creator['id']]);$jobId=(int)$pdo->lastInsertId();
    $planPublic=$pub('plan');$policy=data_attribution_encode(['phase42'=>'fixture']);$approvalHash=hash('sha256','approval-'.$suffix.'-'.$run);$pdo->prepare("INSERT INTO data_post_training_plans(public_id,training_job_id,output_model_version_id,baseline_model_version_id,status,policy_json,policy_hash,training_completion_hash,output_version_hash,baseline_version_hash,baseline_approval_receipt_hash,plan_hash,created_by_user_id,ready_at) VALUES(?,?,?,?,'ready',?,?,?,?,?,?,?, ?,NOW())")->execute([$planPublic,$jobId,$model['id'],$baseline['id'],$policy,hash('sha256',$policy),$completion,$model['version_hash'],$baseline['version_hash'],$approvalHash,hash('sha256','plan-'.$suffix.'-'.$run),$creator['id']]);$planId=(int)$pdo->lastInsertId();
    $packetPublic=$pub('packet');$packet=['schema'=>'annotated.post-training-readiness.v1','plan_public_id'=>$planPublic,'model_version_public_id'=>$model['public_id'],'readiness_state'=>'ready_for_human_consideration','boundary'=>'human decision required'];$packetJson=data_attribution_encode($packet);$packetHash=hash('sha256',$packetJson);$pdo->prepare("INSERT INTO data_post_training_packets(public_id,plan_id,sequence_number,readiness_state,packet_json,packet_hash,created_by_user_id) VALUES(?,?,1,'ready_for_human_consideration',?,?,?)")->execute([$packetPublic,$planId,$packetJson,$packetHash,$creator['id']]);return data_model_release_packet_by_public($pdo,$packetPublic);
};

$packet=$makePacket('proceed',$candidate);p43($packet&&data_post_training_packet_integrity($packet)['ok'],'fixture readiness packet is integrity-valid');
$eligible=data_model_release_eligible_packets($pdo);p43(count(array_filter($eligible,fn($r)=>$r['public_id']===$packet['public_id']))===1,'ready packet appears in release-decision eligibility list');

$decision=data_model_release_decision_create($pdo,$creator,$packet['public_id'],[
    'title'=>'Release candidate '.$run,'decision_summary'=>'Review trained candidate for governed release.','required_reviewers'=>1,
    'rollout_strategy'=>'canary','target_tasks'=>'research_synthesis, claim_analysis','initial_traffic_percent'=>10,'monitoring_window_minutes'=>120,'success_criteria'=>'No benchmark regression and no material production error-rate increase.','monitoring_owner'=>'Release Owner','change_window'=>'Saturday 02:00 UTC','routing_notes'=>'Routing changes happen separately in AI Admin.',
    'rollback_model_version_public_id'=>$baseline['public_id'],'rollback_trigger_conditions'=>'Error or quality threshold breach.','rollback_steps'=>'Restore prior governed runtime/task routing.','recovery_target_minutes'=>30,'rollback_owner'=>'Rollback Owner','rollback_validation_steps'=>'Verify prior model health and benchmark-critical task outputs.'
]);
p43($decision['status']==='draft'&&$decision['readiness_packet_hash']===$packet['packet_hash'],'decision draft snapshots the exact readiness packet');
p43(count(data_model_release_checklist($pdo,(int)$decision['id']))===10,'decision creates the default human model-risk checklist');

$duplicate=false;try{data_model_release_decision_create($pdo,$creator,$packet['public_id'],[]);}catch(Throwable $e){$duplicate=true;}p43($duplicate,'one readiness packet cannot silently produce multiple release decisions');

$decision=data_model_release_update_draft($pdo,$creator,$decision['public_id'],['rollback_model_version_public_id'=>$wrongRollback['public_id']]);$wrongContext=data_model_release_current_context($pdo,$decision);p43(!$wrongContext['pass']&&!$wrongContext['checks']['rollback_target_same_registry'],'rollback target outside the model family blocks release context');
$openBlocked=false;try{data_model_release_open_review($pdo,$creator,$decision['public_id']);}catch(RuntimeException $e){$openBlocked=true;}p43($openBlocked,'invalid rollback context blocks opening independent review');

$decision=data_model_release_update_draft($pdo,$creator,$decision['public_id'],['rollback_model_version_public_id'=>$baseline['public_id']]);
$decision=data_model_release_open_review($pdo,$creator,$decision['public_id']);p43($decision['status']==='in_review','complete valid release plan can open independent review');

$selfReview=false;try{data_model_release_review_submit($pdo,$creator,$decision['public_id'],'proceed','Creator self-review');}catch(RuntimeException $e){$selfReview=str_contains($e->getMessage(),'creator');}p43($selfReview,'release-decision creator cannot satisfy independent reviewer requirement');

data_model_release_review_submit($pdo,$reviewer,$decision['public_id'],'proceed','Initial independent review before checklist completion.');$reviewSummary=data_model_release_review_summary($pdo,$decision);p43($reviewSummary['enough']&&$reviewSummary['counts']['proceed']===1,'independent reviewer can sign the current release snapshot');

$proceedBlocked=false;try{data_model_release_finalize($pdo,$creator,$decision['public_id'],'proceed_to_governed_release','Proceed before checklist.');}catch(RuntimeException $e){$proceedBlocked=true;}p43($proceedBlocked,'Proceed decision cannot bypass required risk checklist');

foreach(data_model_release_checklist($pdo,(int)$decision['id']) as $item)data_model_release_check_update($pdo,$creator,$decision['public_id'],$item['check_key'],'pass','Reviewed for Phase 43 fixture.');
$decision=data_model_release_decision_get($pdo,$decision['public_id']);$afterChecklist=data_model_release_review_summary($pdo,$decision);p43(count($afterChecklist['current'])===0&&count($afterChecklist['stale'])===1,'changing checklist state invalidates earlier reviewer snapshot signature');

data_model_release_review_submit($pdo,$reviewer,$decision['public_id'],'hold','Hold until monitoring owner confirms release window.');$holdGate=data_model_release_finalize_checks($pdo,$decision,'proceed_to_governed_release');p43(!$holdGate['pass']&&$holdGate['reviews']['counts']['hold']===1,'current Hold review blocks a Proceed outcome');
data_model_release_review_submit($pdo,$reviewer,$decision['public_id'],'proceed','Monitoring owner and change window confirmed.');$decision=data_model_release_decision_get($pdo,$decision['public_id']);$readyGate=data_model_release_finalize_checks($pdo,$decision,'proceed_to_governed_release');p43($readyGate['pass'],'all required checklist, rollback, context, and independent Proceed review satisfy final Proceed gate');

$receiptsBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_model_promotion_receipts WHERE version_id='.(int)$candidate['id'])->fetchColumn();$decision=data_model_release_finalize($pdo,$creator,$decision['public_id'],'proceed_to_governed_release','Proceed into the existing governed Phase 40 release lifecycle.');p43($decision['status']==='decision_recorded'&&$decision['outcome']==='proceed_to_governed_release','final human Proceed decision is recorded and signed');
$integrity=data_model_release_decision_integrity($pdo,$decision);p43($integrity['ok'],'signed release decision passes deterministic integrity verification');
$candidateNow=data_model_version_get($pdo,$candidate['public_id']);$receiptsAfter=(int)$pdo->query('SELECT COUNT(*) FROM data_model_promotion_receipts WHERE version_id='.(int)$candidate['id'])->fetchColumn();p43($candidateNow['status']==='experimental'&&$receiptsAfter===$receiptsBefore,'signed Proceed decision does not transition Phase 40 model lifecycle');
p43((int)($pdo->query('SELECT admin_default_model_id FROM ai_settings WHERE id=1')->fetchColumn()?:0)===$routeBefore,'signed release decision does not change production AI routing');
$sigs=data_model_release_signatures($pdo,(int)$decision['id']);p43(count($sigs)>=2&&in_array('review',array_column($sigs,'signature_type'),true)&&in_array('final_decision',array_column($sigs,'signature_type'),true),'review and final decision signatures are retained separately');

$pdo->prepare('UPDATE data_model_release_decisions SET rationale=? WHERE id=?')->execute(['tampered rationale',$decision['id']]);$tampered=data_model_release_decision_get($pdo,$decision['public_id']);p43(!data_model_release_decision_integrity($pdo,$tampered)['ok'],'direct signed-decision tampering is detected');
$pdo->prepare('UPDATE data_model_release_decisions SET rationale=? WHERE id=?')->execute([$decision['rationale'],$decision['id']]);$decision=data_model_release_decision_get($pdo,$decision['public_id']);p43(data_model_release_decision_integrity($pdo,$decision)['ok'],'restoring signed decision content restores deterministic integrity');

// Hold path: independent review is still required, but risk checklist need not all pass.
$candidate2=data_model_version_create($pdo,$creator,$registry['public_id'],['version_label'=>'candidate-hold','origin'=>'annotated_trained','architecture'=>'fixture trained','artifact_ref'=>'registry://phase43/hold','artifact_hash'=>hash('sha256','hold-'.$run)]);
$packet2=$makePacket('hold',$candidate2);$hold=data_model_release_decision_create($pdo,$creator,$packet2['public_id'],['title'=>'Hold candidate '.$run,'required_reviewers'=>1,'target_tasks'=>'research_synthesis','success_criteria'=>'Re-evaluate later.','monitoring_owner'=>'Owner','rollback_model_version_public_id'=>$baseline['public_id'],'rollback_trigger_conditions'=>'Any issue','rollback_steps'=>'Restore baseline','rollback_owner'=>'Owner','rollback_validation_steps'=>'Validate baseline']);
$hold=data_model_release_open_review($pdo,$creator,$hold['public_id']);data_model_release_review_submit($pdo,$reviewer2,$hold['public_id'],'hold','Additional evidence required.');$hold=data_model_release_finalize($pdo,$creator,$hold['public_id'],'hold','Hold release pending additional evidence.');p43($hold['status']==='decision_recorded'&&$hold['outcome']==='hold'&&data_model_release_decision_integrity($pdo,$hold)['ok'],'signed Hold decision records human stop without requiring all checklist items to pass');
p43(data_model_version_get($pdo,$candidate2['public_id'])['status']==='experimental','Hold decision leaves model lifecycle unchanged');

$nonAdmin=false;try{data_model_release_decision_create($pdo,$outsider,$packet2['public_id'],[]);}catch(RuntimeException $e){$nonAdmin=str_contains($e->getMessage(),'Administrator');}p43($nonAdmin,'release decision lifecycle is administrator-only');
$events=data_model_release_events($pdo,(int)$decision['id']);$types=array_column($events,'event_type');foreach(['decision_created','review_opened','checklist_updated','review_signed','final_decision_signed'] as $needed)p43(in_array($needed,$types,true),'release audit history includes '.$needed);
$summary=data_model_release_summary($pdo);p43($summary['decisions']>=2&&$summary['recorded']>=2&&$summary['proceed']>=1&&$summary['hold']>=1,'release decision summary exposes human outcome lifecycle');

echo "Phase 43 Model Release Decision Workspace MariaDB suite passed.\n";
