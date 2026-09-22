<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/functions.php';require_once $root.'/app/data-attribution.php';require_once $root.'/app/data-datasets.php';require_once $root.'/app/notifications.php';require_once $root.'/app/ai.php';require_once $root.'/app/data-evaluations.php';require_once $root.'/app/data-model-registry.php';require_once $root.'/app/data-training.php';require_once $root.'/app/data-post-training.php';require_once $root.'/app/data-model-release.php';require_once $root.'/app/data-model-deployment.php';require_once $root.'/app/data-model-observability.php';require_once $root.'/app/data-model-improvement.php';require_once $root.'/app/data-model-campaigns.php';
function p47(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
p47(data_model_campaign_ready($pdo),'Phase 47 Model Improvement Campaign schema is available');

$q=$pdo->query("SELECT id,public_id,role FROM users WHERE display_name='DeploymentCreator' ORDER BY id DESC LIMIT 1");$admin=$q->fetch();if(!$admin)throw new RuntimeException('Phase 44–46 admin fixture missing.');
$q=$pdo->query("SELECT id,public_id,role FROM users WHERE display_name='DeploymentOutsider' ORDER BY id DESC LIMIT 1");$outsider=$q->fetch();if(!$outsider)throw new RuntimeException('Phase 44–46 outsider fixture missing.');

$q=$pdo->query("SELECT c.* FROM data_model_improvement_cases c WHERE c.classification NOT IN ('untriaged','expected_behavior','no_action') AND EXISTS(SELECT 1 FROM data_model_improvement_proposals p WHERE p.case_id=c.id AND p.proposal_type='evaluation_case' AND p.status='published') AND EXISTS(SELECT 1 FROM data_model_improvement_proposals p WHERE p.case_id=c.id AND p.proposal_type='training_example' AND p.status='published') ORDER BY c.id DESC LIMIT 1");
$case=$q->fetch();if(!$case)throw new RuntimeException('Phase 47 requires the Phase 46 published evaluation/training fixture.');
$q=$pdo->prepare("SELECT public_id FROM data_model_improvement_proposals WHERE case_id=? AND proposal_type='evaluation_case' AND status='published' ORDER BY id DESC LIMIT 1");$q->execute([$case['id']]);$evalProposalPublic=(string)$q->fetchColumn();
$q=$pdo->prepare("SELECT public_id FROM data_model_improvement_proposals WHERE case_id=? AND proposal_type='training_example' AND status='published' ORDER BY id DESC LIMIT 1");$q->execute([$case['id']]);$trainProposalPublic=(string)$q->fetchColumn();
$evalProposal=data_model_improvement_proposal_get($pdo,$evalProposalPublic);$trainProposal=data_model_improvement_proposal_get($pdo,$trainProposalPublic);if(!$evalProposal||!$trainProposal)throw new RuntimeException('Phase 46 proposal fixture missing.');

if(!$case['model_version_id'])throw new RuntimeException('Phase 47 fixture requires a governed model-version-bound improvement case.');
$caseVersion=data_model_campaign_model_version_by_id($pdo,(int)$case['model_version_id']);if(!$caseVersion)throw new RuntimeException('Phase 46 case model version unavailable.');
$q=$pdo->prepare("SELECT public_id FROM data_model_versions WHERE registry_id=? AND status IN ('active','approved') ORDER BY FIELD(status,'active','approved'),id DESC");$q->execute([$caseVersion['registry_id']]);$base=null;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$v=data_model_version_get($pdo,(string)$public);if(!$v)continue;try{data_training_base_assert($pdo,(int)$v['id'],'manual');$base=$v;break;}catch(Throwable $ignored){}}
if(!$base)throw new RuntimeException('Phase 47 fixture requires an approved/active base with integrity-valid approval receipt.');

$routingBefore=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
$q=$pdo->query("SELECT id,status,current_traffic_percent,revision FROM data_model_deployments ORDER BY id DESC LIMIT 1");$deploymentBefore=$q->fetch();
$q=$pdo->query("SELECT id,status FROM data_model_versions ORDER BY id");$versionsBefore=$q->fetchAll();
$versionCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_model_versions')->fetchColumn();
$trainingCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_training_jobs')->fetchColumn();
$evalRunsBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_evaluation_runs')->fetchColumn();
$postPlansBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_post_training_plans')->fetchColumn();

$campaign=data_model_campaign_create($pdo,$admin,['title'=>'Phase 47 Grounding Campaign','strategy'=>'model_training','base_model_version_id'=>$base['id'],'target_version_label'=>'phase47-controlled-output','objective'=>'Reduce the confirmed production grounding failure using only human-approved Phase 46 evidence.','success_criteria'=>'Pass the permanent production regression benchmark before release consideration.']);
p47($campaign['status']==='planned'&&$campaign['strategy']==='model_training'&&(int)$campaign['base_model_version_id']===(int)$base['id'],'controlled training campaign starts as a governed planned record');

data_model_campaign_add_case($pdo,$admin,$campaign['public_id'],$case['public_id']);
data_model_campaign_add_proposal($pdo,$admin,$campaign['public_id'],$evalProposalPublic);
data_model_campaign_add_proposal($pdo,$admin,$campaign['public_id'],$trainProposalPublic);
$campaign=data_model_campaign_get($pdo,$campaign['public_id']);
$links=data_model_campaign_cases($pdo,(int)$campaign['id']);$props=data_model_campaign_proposals($pdo,(int)$campaign['id']);
p47(count($links)===1&&count($props)===2,'campaign scope links one actionable production case and its approved evaluation/training evidence');

$q=$pdo->prepare("SELECT dci.* FROM data_model_improvement_proposals p JOIN data_corpus_items dci ON dci.public_id=p.corpus_item_public_id WHERE p.public_id=? LIMIT 1");$q->execute([$trainProposalPublic]);$trainingCorpus=$q->fetch();$meta=$trainingCorpus?json_decode((string)$trainingCorpus['metadata_json'],true):null;
p47($trainingCorpus&&(int)$trainingCorpus['training_eligible']===1&&(int)$trainingCorpus['attribution_required']===0&&(int)$trainingCorpus['commercial_training_eligible']===0&&(int)$trainingCorpus['shared_retrieval_eligible']===0,'selected sanitized training example refreshes to internal-training-only eligibility');
p47(is_array($meta)&&isset($meta['training_example']['input'],$meta['training_example']['output']),'selected sanitized training example exposes Phase 41 supervised metadata after refresh');

$campaign=data_model_campaign_create_dataset_drafts($pdo,$admin,$campaign['public_id']);
p47($campaign['evaluation_dataset_status']==='draft'&&$campaign['training_dataset_status']==='draft','Phase 47 creates campaign datasets as drafts only');
$evalPreview=data_dataset_preview($pdo,(string)$campaign['evaluation_dataset_public_id'],20);$trainPreview=data_dataset_preview($pdo,(string)$campaign['training_dataset_public_id'],20);
p47($evalPreview['selected_count']===1&&$evalPreview['sample'][0]['source_object_public_id']===$evalProposalPublic,'campaign evaluation dataset selects exactly the chosen regression proposal');
p47($trainPreview['selected_count']===1&&$trainPreview['sample'][0]['source_object_public_id']===$trainProposalPublic,'campaign training dataset selects exactly the chosen training proposal');
p47($evalPreview['eligible_total']===1&&$trainPreview['eligible_total']===1,'exact source-object policy prevents unrelated Phase 46 examples from entering campaign datasets');

$campaign=data_model_campaign_lock($pdo,$admin,$campaign['public_id']);$integrity=data_model_campaign_plan_integrity($pdo,$campaign);$current=data_model_campaign_current_use($pdo,$campaign);
p47($integrity['ok']&&$current['usable']&&strlen((string)$campaign['plan_hash'])===64,'campaign lock creates a tamper-evident current-use-valid immutable plan');

$scopeBlocked=false;try{data_model_campaign_add_proposal($pdo,$admin,$campaign['public_id'],$evalProposalPublic);}catch(RuntimeException $e){$scopeBlocked=str_contains($e->getMessage(),'immutable');}p47($scopeBlocked,'locked campaign proposal scope is immutable');
$caseBlocked=false;try{data_model_campaign_add_case($pdo,$admin,$campaign['public_id'],$case['public_id']);}catch(RuntimeException $e){$caseBlocked=str_contains($e->getMessage(),'immutable');}p47($caseBlocked,'locked campaign case scope is immutable');

$evalBeforeFreeze=false;try{data_model_campaign_prepare_evaluation_suite($pdo,$admin,$campaign['public_id']);}catch(RuntimeException $e){$evalBeforeFreeze=str_contains($e->getMessage(),'frozen');}p47($evalBeforeFreeze,'Phase 47 cannot prepare the benchmark before a human freezes the evaluation dataset');
$trainBeforeFreeze=false;try{data_model_campaign_prepare_training_draft($pdo,$admin,$campaign['public_id'],['executor_type'=>'manual']);}catch(RuntimeException $e){$trainBeforeFreeze=str_contains($e->getMessage(),'frozen')||str_contains($e->getMessage(),'regression benchmark');}p47($trainBeforeFreeze,'Phase 47 cannot create a training draft before human dataset freeze and regression-suite preparation');

$lockedPlanJson=(string)$campaign['plan_json'];$pdo->prepare('UPDATE data_model_improvement_campaigns SET plan_json=? WHERE id=?')->execute([json_encode(['tampered'=>true]),$campaign['id']]);$tamperedCampaign=data_model_campaign_get($pdo,$campaign['public_id']);p47(!data_model_campaign_plan_integrity($pdo,$tamperedCampaign)['ok'],'campaign plan hash detects direct plan tampering');$pdo->prepare('UPDATE data_model_improvement_campaigns SET plan_json=? WHERE id=?')->execute([$lockedPlanJson,$campaign['id']]);

$evalDataset=data_dataset_get($pdo,(string)$campaign['evaluation_dataset_public_id']);$originalEvalPolicy=$evalDataset['selection_policy'];data_dataset_update_draft($pdo,$admin,$evalDataset['public_id'],['max_items'=>2]);$campaign=data_model_campaign_get($pdo,$campaign['public_id']);p47(!data_model_campaign_current_use($pdo,$campaign)['usable'],'locked campaign detects external dataset selection-policy drift');
data_dataset_update_draft($pdo,$admin,$evalDataset['public_id'],$originalEvalPolicy);$campaign=data_model_campaign_get($pdo,$campaign['public_id']);p47(data_model_campaign_current_use($pdo,$campaign)['usable'],'restoring the exact dataset selection policy restores campaign current-use validity');

data_dataset_freeze($pdo,$admin,(string)$campaign['evaluation_dataset_public_id']);data_dataset_freeze($pdo,$admin,(string)$campaign['training_dataset_public_id']);$campaign=data_model_campaign_get($pdo,$campaign['public_id'],true);
p47($campaign['evaluation_dataset_status']==='frozen'&&$campaign['training_dataset_status']==='frozen','datasets become frozen only through the explicit Phase 38 human action');

$q=$pdo->prepare('SELECT id FROM data_datasets WHERE public_id=?');$q->execute([$campaign['training_dataset_public_id']]);$trainDatasetId=(int)$q->fetchColumn();$package=data_training_package_inspect($pdo,['dataset_id'=>$trainDatasetId],false);
p47($package['valid_examples']===1&&$package['invalid_examples']===0&&$package['attribution_blocked_items']===0,'frozen campaign training dataset is a valid Phase 41 supervised package');

$evalRunsAtSuite=(int)$pdo->query('SELECT COUNT(*) FROM data_evaluation_runs')->fetchColumn();$suite=data_model_campaign_prepare_evaluation_suite($pdo,$admin,$campaign['public_id']);
p47($suite['status']==='draft'&&$suite['benchmark_type']==='model','Phase 47 prepares a Phase 39 model benchmark as a draft only');
p47(count(data_evaluation_cases($pdo,(int)$suite['id']))===1,'campaign baseline suite contains the selected permanent production regression');
p47((int)$pdo->query('SELECT COUNT(*) FROM data_evaluation_runs')->fetchColumn()===$evalRunsAtSuite,'preparing the Phase 39 suite does not queue an evaluation run');

$job=data_model_campaign_prepare_training_draft($pdo,$admin,$campaign['public_id'],['executor_type'=>'manual','max_attempts'=>2]);
p47($job['status']==='draft'&&$job['executor_type']==='manual','Phase 47 creates a Phase 41 training draft without queueing or submitting it');
$q=$pdo->prepare('SELECT COUNT(*) FROM data_training_attempts WHERE job_id=?');$q->execute([$job['id']]);p47((int)$q->fetchColumn()===0,'Phase 47 training handoff creates no execution attempt');
$campaign=data_model_campaign_get($pdo,$campaign['public_id'],true);p47($campaign['status']==='ready_for_training'&&$campaign['training_job_status']==='draft','campaign lifecycle reports Ready for training while the Phase 41 job remains a human-controlled draft');

$postBlocked=false;try{data_model_campaign_prepare_post_training_plan($pdo,$admin,$campaign['public_id']);}catch(RuntimeException $e){$postBlocked=str_contains($e->getMessage(),'succeed');}p47($postBlocked,'Phase 47 cannot create Phase 42 readiness until Phase 41 training actually succeeds');
$outcome=data_model_campaign_outcome($pdo,$campaign);p47($outcome['state']==='awaiting_output_model','closed-loop outcome waits for a governed training output before making production claims');

$outsiderBlocked=false;try{data_model_campaign_create($pdo,$outsider,['title'=>'No','strategy'=>'evaluation_only','base_model_version_id'=>$base['id'],'objective'=>'x','success_criteria'=>'y']);}catch(RuntimeException $e){$outsiderBlocked=str_contains($e->getMessage(),'Administrator');}p47($outsiderBlocked,'campaign lifecycle operations are administrator-only');

$routingAfter=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
$q=$pdo->query("SELECT id,status,current_traffic_percent,revision FROM data_model_deployments ORDER BY id DESC LIMIT 1");$deploymentAfter=$q->fetch();
$q=$pdo->query("SELECT id,status FROM data_model_versions ORDER BY id");$versionsAfter=$q->fetchAll();
p47($routingAfter===$routingBefore,'Phase 47 orchestration never changes AI routing');
p47($deploymentAfter===$deploymentBefore,'Phase 47 orchestration never changes governed deployment state or traffic');
p47($versionsAfter===$versionsBefore&&(int)$pdo->query('SELECT COUNT(*) FROM data_model_versions')->fetchColumn()===$versionCountBefore,'Phase 47 orchestration never changes model lifecycle or creates a model version');
p47((int)$pdo->query('SELECT COUNT(*) FROM data_evaluation_runs')->fetchColumn()===$evalRunsBefore,'Phase 47 orchestration never queues an evaluation run');
p47((int)$pdo->query('SELECT COUNT(*) FROM data_post_training_plans')->fetchColumn()===$postPlansBefore,'Phase 47 does not create a Phase 42 plan before successful training');
p47((int)$pdo->query('SELECT COUNT(*) FROM data_training_jobs')->fetchColumn()===$trainingCountBefore+1,'Phase 47 creates exactly one explicit Phase 41 draft and no hidden training jobs');

$events=data_model_campaign_events($pdo,(int)$campaign['id'],100);$types=array_column($events,'event_type');p47(in_array('campaign_plan_locked',$types,true)&&in_array('baseline_evaluation_suite_draft_created',$types,true)&&in_array('training_job_draft_created',$types,true),'campaign audit ledger records governed handoff milestones');

$campaign=data_model_campaign_close($pdo,$admin,$campaign['public_id'],'abandoned','Lifecycle test closes without executing training.');p47($campaign['status']==='abandoned','campaign abandonment is an explicit human close action');

echo "Phase 47 Model Improvement Campaigns & Controlled Retraining Handoff MariaDB suite passed.\n";
