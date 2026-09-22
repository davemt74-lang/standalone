<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','functions','data-attribution','data-datasets','notifications','ai','data-evaluations','data-model-registry','data-training','data-post-training','data-model-release','data-model-deployment','data-model-observability','data-model-improvement','data-model-campaigns','intelligence-release-audit'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p48(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$pending=installer_pending_migrations($pdo,$root.'/database/migrations');p48(count($pending)===0,'fresh release-candidate database has no pending migrations after Phase 47');
$audit=intelligence_release_audit($pdo,$root);p48($audit['pass'],'read-only Phase 48 Intelligence Release Audit reports no blocking runtime failures');
p48(count(array_filter($audit['phase_matrix'],fn($p)=>!$p['pass']))===0,'Phase 37–47 schema matrix is complete');

$q=$pdo->query("SELECT public_id FROM data_model_improvement_campaigns WHERE title='Phase 47 Grounding Campaign' ORDER BY id DESC LIMIT 1");$campaignPublic=(string)($q->fetchColumn()?:'');if($campaignPublic==='')throw new RuntimeException('Phase 48 requires the Phase 47 campaign fixture.');
$campaign=data_model_campaign_get($pdo,$campaignPublic);if(!$campaign)throw new RuntimeException('Phase 47 campaign fixture unavailable.');
p48(data_model_campaign_plan_integrity($pdo,$campaign)['ok'],'Phase 47 campaign plan remains hash-valid after all prior governance tests');
$cases=data_model_campaign_cases($pdo,(int)$campaign['id']);$props=data_model_campaign_proposals($pdo,(int)$campaign['id']);p48(count($cases)===1&&count($props)===2,'Phase 47 campaign preserves one production case and separate evaluation/training proposals');

$case=$cases[0];$evidence=data_model_improvement_evidence($pdo,(int)$case['improvement_case_id']);$incident=null;
foreach($evidence as $e){if(!empty($e['incident_id'])){$q=$pdo->prepare('SELECT * FROM data_model_incidents WHERE id=?');$q->execute([$e['incident_id']]);$incident=$q->fetch()?:null;if($incident)break;}}
p48($incident!==null,'Phase 46 campaign scope traces back to a concrete Phase 45 production-health incident');
$q=$pdo->prepare('SELECT * FROM data_model_health_snapshots WHERE id=?');$q->execute([$incident['snapshot_id']]);$snapshot=$q->fetch();p48($snapshot&&data_model_observability_snapshot_integrity($snapshot)['ok'],'Phase 45 health snapshot behind the improvement case is integrity-valid');

$q=$pdo->prepare('SELECT public_id FROM data_model_deployments WHERE id=?');$q->execute([$incident['deployment_id']]);$deploymentPublic=(string)($q->fetchColumn()?:'');$deployment=$deploymentPublic!==''?data_model_deployment_get($pdo,$deploymentPublic):null;
p48($deployment!==null,'Phase 45 incident resolves to its authoritative Phase 44 deployment');
p48(hash_equals((string)$deployment['routing_before_hash'],hash('sha256',data_attribution_encode($deployment['routing_before'])))&&hash_equals((string)$deployment['routing_current_hash'],hash('sha256',data_attribution_encode($deployment['routing_current']))),'Phase 44 deployment routing snapshots remain hash-valid');
$events=data_model_deployment_events($pdo,(int)$deployment['id'],250);$types=array_column($events,'event_type');p48(in_array('full_activated',$types,true)&&in_array('deployment_rolled_back',$types,true),'Phase 44 deployment ledger preserves full activation and governed rollback history');

$decision=data_model_release_decision_get($pdo,(string)$deployment['release_decision_public_id']);p48($decision&&$decision['status']==='decision_recorded'&&$decision['outcome']==='proceed_to_governed_release','Phase 44 deployment resolves to a recorded human Phase 43 Proceed decision');
p48(data_model_release_decision_integrity($pdo,$decision)['ok'],'Phase 43 signed release decision remains integrity-valid');
p48(hash_equals((string)$deployment['release_decision_hash'],(string)$decision['decision_hash'])&&hash_equals((string)$deployment['release_signature_hash'],(string)$decision['final_signature_hash']),'Phase 44 deployment is cryptographically bound to the exact Phase 43 decision/signature');

foreach($props as $p){
    $proposal=data_model_improvement_proposal_get($pdo,(string)$p['proposal_public_id']);p48($proposal&&$proposal['status']==='published'&&hash_equals((string)$proposal['content_hash'],data_model_improvement_proposal_hash($proposal)),'Phase 46 published sanitized proposal content hash validates: '.$p['proposal_type']);
    $q=$pdo->prepare('SELECT * FROM data_corpus_items WHERE public_id=? LIMIT 1');$q->execute([$proposal['corpus_item_public_id']]);$corpus=$q->fetch();p48($corpus!==false,'Phase 46 proposal remains present in the governed Phase 37 corpus: '.$p['proposal_type']);
    p48((int)$corpus['shared_retrieval_eligible']===0&&(int)$corpus['commercial_training_eligible']===0,'Phase 37 policy keeps improvement evidence out of shared retrieval/commercial training: '.$p['proposal_type']);
    if($p['proposal_type']==='evaluation_case')p48((int)$corpus['evaluation_eligible']===1&&(int)$corpus['training_eligible']===0,'regression proposal remains evaluation-only');
    if($p['proposal_type']==='training_example')p48((int)$corpus['training_eligible']===1&&(int)$corpus['evaluation_eligible']===0&&(int)$corpus['attribution_required']===0,'sanitized training proposal remains Phase 41-compatible internal-training-only evidence');
}

$evalDataset=data_model_campaign_dataset($pdo,(int)$campaign['evaluation_dataset_id']);$trainDataset=data_model_campaign_dataset($pdo,(int)$campaign['training_dataset_id']);
$evalUse=data_dataset_current_use_status($pdo,(string)$evalDataset['public_id']);$trainUse=data_dataset_current_use_status($pdo,(string)$trainDataset['public_id']);
p48($evalDataset['status']==='frozen'&&$evalUse['manifest_ok'],'Phase 38 campaign evaluation dataset remains frozen with a valid immutable manifest');
p48($trainDataset['status']==='frozen'&&$trainUse['manifest_ok'],'Phase 38 campaign training dataset remains frozen with a valid immutable manifest');
p48(count((array)$evalDataset['selection_policy']['source_object_public_ids'])===1&&count((array)$trainDataset['selection_policy']['source_object_public_ids'])===1,'Phase 38 campaign datasets preserve exact approved source-object scoping');

$q=$pdo->prepare('SELECT * FROM data_evaluation_suites WHERE id=?');$q->execute([$campaign['baseline_evaluation_suite_id']]);$suite=$q->fetch();p48($suite&&$suite['benchmark_type']==='model','Phase 39 campaign baseline remains a governed model benchmark suite');
$evalCases=data_evaluation_cases($pdo,(int)$suite['id']);p48(count($evalCases)===1,'Phase 39 baseline suite contains the permanent production regression case');

$base=data_model_campaign_model_version_by_id($pdo,(int)$campaign['base_model_version_id']);$receipt=data_model_latest_approval_receipt($pdo,$base);
p48($base&&data_model_version_integrity($base)['ok'],'Phase 40 governed campaign base model identity remains valid');
p48($receipt&&data_model_receipt_integrity($receipt,$base)['ok'],'Phase 40 base-model approval receipt remains valid');

$q=$pdo->prepare('SELECT public_id FROM data_training_jobs WHERE id=?');$q->execute([$campaign['training_job_id']]);$jobPublic=(string)($q->fetchColumn()?:'');$job=data_training_job_get($pdo,$jobPublic);p48($job&&$job['status']==='draft','Phase 47 handoff leaves the Phase 41 campaign training job as a human-controlled draft');
$pre=data_training_preflight($pdo,$job);p48($pre['pass']&&$pre['package']['valid_examples']===1&&$pre['package']['invalid_examples']===0&&$pre['package']['attribution_blocked_items']===0,'Phase 41 preflight independently validates the campaign supervised training package');
$q=$pdo->prepare('SELECT COUNT(*) FROM data_training_attempts WHERE job_id=?');$q->execute([$job['id']]);p48((int)$q->fetchColumn()===0,'Phase 47/48 release audit has not executed the campaign training job');

$validTrainingCompletion=false;$q=$pdo->query("SELECT public_id FROM data_training_jobs WHERE status='succeeded' AND completion_hash IS NOT NULL ORDER BY id DESC");
foreach($q->fetchAll(PDO::FETCH_COLUMN) as $jp){$candidate=data_training_job_get($pdo,(string)$jp);if($candidate&&data_training_completion_integrity($pdo,$candidate)['ok']){$validTrainingCompletion=true;break;}}
p48($validTrainingCompletion,'Phase 41 historical suite contains at least one integrity-valid successful training completion');

$validBaseline=false;$q=$pdo->query("SELECT public_id FROM data_evaluation_runs WHERE status='completed' AND run_hash IS NOT NULL ORDER BY id DESC");
foreach($q->fetchAll(PDO::FETCH_COLUMN) as $rp){$run=data_evaluation_run_get($pdo,(string)$rp);if($run&&data_evaluation_run_integrity($pdo,$run)['ok']){$validBaseline=true;break;}}
p48($validBaseline,'Phase 39 historical suite contains at least one integrity-valid completed benchmark run');

$validPacket=false;$q=$pdo->query("SELECT p.id,p.public_id,p.plan_id FROM data_post_training_packets p ORDER BY p.id DESC");
foreach($q->fetchAll() as $pr){$packets=data_post_training_packets($pdo,(int)$pr['plan_id']);foreach($packets as $packet)if((int)$packet['id']===(int)$pr['id']&&data_post_training_packet_integrity($packet)['ok']){$validPacket=true;break 2;}}
p48($validPacket,'Phase 42 historical suite contains at least one integrity-valid post-training readiness packet');

$recorded=intelligence_release_decision_checks($pdo,200);p48(count($recorded)>0&&count(array_filter($recorded,fn($r)=>!$r['pass']))===0,'all recorded Phase 43 release decisions pass deterministic integrity checks');
$active=intelligence_release_active_model_checks($pdo);p48(count(array_filter($active,fn($r)=>!$r['pass']))===0,'all currently active Phase 40 model versions retain valid identity and approval evidence');
$campaignAudit=intelligence_release_campaign_checks($pdo,200);p48(count($campaignAudit)>0&&count(array_filter($campaignAudit,fn($r)=>!$r['pass']))===0,'all locked Phase 47 campaigns pass plan/current-use release audit rules');

$routeBefore=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
$auditAgain=intelligence_release_audit($pdo,$root);
$routeAfter=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
p48($auditAgain['pass']&&$routeAfter===$routeBefore,'Phase 48 release audit is repeatable and read-only with respect to AI routing');

p48(!is_file($root.'/database/migrations/20260922_047_end_to_end_closed_loop_release_hardening.sql'),'Phase 48 introduces no database migration or persistent release-audit subsystem');
echo "Phase 48 end-to-end intelligence closed-loop release audit passed: 37→38→39→40→41→42→43→44→45→46→47.\n";
