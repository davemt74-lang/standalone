<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/functions.php';require_once $root.'/app/data-attribution.php';require_once $root.'/app/data-datasets.php';require_once $root.'/app/notifications.php';require_once $root.'/app/ai.php';require_once $root.'/app/data-evaluations.php';require_once $root.'/app/data-model-registry.php';require_once $root.'/app/data-training.php';require_once $root.'/app/data-post-training.php';require_once $root.'/app/data-model-release.php';require_once $root.'/app/data-model-deployment.php';require_once $root.'/app/data-model-observability.php';require_once $root.'/app/data-model-improvement.php';
function p46(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
p46(data_model_improvement_ready($pdo),'Phase 46 Production Feedback & Model Improvement schema is available');

$q=$pdo->query("SELECT id,public_id,role FROM users WHERE display_name='DeploymentCreator' ORDER BY id DESC LIMIT 1");$admin=$q->fetch();if(!$admin)throw new RuntimeException('Phase 44/45 admin fixture missing.');
$q=$pdo->query("SELECT id,public_id,role FROM users WHERE display_name='DeploymentOutsider' ORDER BY id DESC LIMIT 1");$outsider=$q->fetch();if(!$outsider)throw new RuntimeException('Phase 44/45 outsider fixture missing.');
$q=$pdo->query("SELECT * FROM data_model_incidents ORDER BY id DESC LIMIT 1");$incident=$q->fetch();if(!$incident)throw new RuntimeException('Phase 46 requires Phase 45 incident evidence.');
$q=$pdo->query("SELECT * FROM data_model_outcome_signals WHERE signal_value<0 ORDER BY id DESC LIMIT 1");$negativeSignal=$q->fetch();if(!$negativeSignal)throw new RuntimeException('Phase 46 requires Phase 45 negative outcome evidence.');

$routingBefore=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
$q=$pdo->query("SELECT id,status,current_traffic_percent,revision FROM data_model_deployments ORDER BY id DESC LIMIT 1");$deploymentBefore=$q->fetch();
$q=$pdo->query("SELECT id,status FROM data_model_versions ORDER BY id");$versionsBefore=$q->fetchAll();
$trainingBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_training_jobs')->fetchColumn();
$evalRunsBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_evaluation_runs')->fetchColumn();
$modelVersionsBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_model_versions')->fetchColumn();

$backfill=data_model_improvement_backfill($pdo,$admin,500);p46($backfill['cases_touched']>=1,'existing Phase 45 evidence backfills into clustered Phase 46 cases');
$case=data_model_improvement_case_for_incident($pdo,(int)$incident['id']);p46($case!==null,'Phase 45 incident has a governed Phase 46 improvement case');
p46((int)$case['evidence_count']>=1&&strlen((string)$case['evidence_hash'])===64,'improvement case preserves hashed evidence references');
$evidence=data_model_improvement_evidence($pdo,(int)$case['id']);p46(count($evidence)>0&&isset($evidence[0]['evidence_ref_public_id'])&&!array_key_exists('prompt',$evidence[0]),'improvement evidence stores references/hashes rather than raw production prompt text');

$recurrence=(int)$case['recurrence_count'];$evidenceCount=(int)$case['evidence_count'];data_model_improvement_backfill($pdo,$admin,500);$case2=data_model_improvement_case_get($pdo,(string)$case['public_id']);p46((int)$case2['recurrence_count']===$recurrence&&(int)$case2['evidence_count']===$evidenceCount,'feedback backfill is idempotent for already-linked evidence');

$blockedUntriaged=false;try{data_model_improvement_proposal_create($pdo,$admin,$case['public_id'],['proposal_type'=>'evaluation_case','title'=>'Blocked before triage','sanitized_input'=>'Sanitized scenario','sanitized_expected_output'=>'Expected behavior']);}catch(RuntimeException $e){$blockedUntriaged=str_contains($e->getMessage(),'Human triage');}p46($blockedUntriaged,'untriaged production feedback cannot become reusable evaluation/training evidence');

$case=data_model_improvement_triage($pdo,$admin,$case['public_id'],['classification'=>'model_defect','status'=>'ready_for_evaluation','triage_note'=>'Confirmed reproducible model-behavior defect; create a sanitized regression example.','owner_user_id'=>$admin['id']]);
p46($case['classification']==='model_defect'&&$case['status']==='ready_for_evaluation','human triage explicitly classifies and routes the improvement case');

$evalInput='Sanitized regression scenario: answer from the governed evidence and do not invent unsupported facts.';
$evalExpected='Expected behavior: cite available evidence, identify uncertainty, and omit unsupported claims.';
$proposal=data_model_improvement_proposal_create($pdo,$admin,$case['public_id'],['proposal_type'=>'evaluation_case','title'=>'Production regression '.$case['route_key'],'sanitized_input'=>$evalInput,'sanitized_expected_output'=>$evalExpected,'sanitized_context'=>'No private production content. Synthetic context only.','purpose_justification'=>'Permanent regression coverage for the confirmed production defect.']);
p46($proposal['status']==='draft'&&!$proposal['redaction_attested']&&!$proposal['rights_attested'],'new reusable proposal begins as an unapproved human draft');
$approveBlocked=false;try{data_model_improvement_proposal_approve($pdo,$admin,$proposal['public_id']);}catch(RuntimeException $e){$approveBlocked=str_contains($e->getMessage(),'Redaction and rights');}p46($approveBlocked,'proposal approval is blocked until redaction and reuse rights are explicitly attested');

$proposal=data_model_improvement_proposal_update($pdo,$admin,$proposal['public_id'],['title'=>$proposal['title'],'sanitized_input'=>$evalInput,'sanitized_expected_output'=>$evalExpected,'sanitized_context'=>'No private production content. Synthetic context only.','purpose_justification'=>'Permanent regression coverage for the confirmed production defect.','redaction_attested'=>'1','rights_attested'=>'1']);
$proposal=data_model_improvement_proposal_approve($pdo,$admin,$proposal['public_id']);p46($proposal['status']==='approved'&&strlen((string)$proposal['approval_hash'])===64,'human-approved sanitized proposal receives a deterministic approval hash');

$pdo->prepare('UPDATE data_model_improvement_proposals SET sanitized_input=? WHERE id=?')->execute([$evalInput.' TAMPERED',$proposal['id']]);$tamperBlocked=false;try{data_model_improvement_proposal_publish($pdo,$admin,$proposal['public_id']);}catch(RuntimeException $e){$tamperBlocked=str_contains($e->getMessage(),'changed after approval');}p46($tamperBlocked,'post-approval content tampering blocks corpus publication');
$pdo->prepare('UPDATE data_model_improvement_proposals SET sanitized_input=? WHERE id=?')->execute([$evalInput,$proposal['id']]);

$proposal=data_model_improvement_proposal_publish($pdo,$admin,$proposal['public_id']);p46($proposal['status']==='published'&&!empty($proposal['corpus_item_public_id']),'approved evaluation proposal publishes atomically into the governed corpus');
$q=$pdo->prepare("SELECT * FROM data_corpus_items WHERE public_id=? LIMIT 1");$q->execute([$proposal['corpus_item_public_id']]);$evalCorpus=$q->fetch();p46($evalCorpus&&$evalCorpus['corpus_type']==='model_regression_case'&&(int)$evalCorpus['evaluation_eligible']===1&&(int)$evalCorpus['training_eligible']===0&&(int)$evalCorpus['shared_retrieval_eligible']===0&&(int)$evalCorpus['commercial_training_eligible']===0,'published regression case is evaluation-only and never shared/commercial-training eligible');
p46(!str_contains((string)$evalCorpus['normalized_text'],'TAMPERED'),'governed corpus contains only the approved sanitized proposal content');
$q=$pdo->prepare('SELECT COUNT(*) FROM data_model_regression_cases WHERE proposal_id=? AND active=1');$q->execute([$proposal['id']]);p46((int)$q->fetchColumn()===1,'published evaluation proposal becomes a permanent regression-library case');
$sourceVersion=data_model_version_get($pdo,(string)$case['model_version_public_id']);if(!$sourceVersion)throw new RuntimeException('Source model version for regression gate is unavailable.');$candidateView=$sourceVersion;$candidateView['status']='candidate';$regressionGate=data_model_improvement_regression_gate($pdo,$candidateView);p46(!$regressionGate['pass']&&$regressionGate['required']>=1&&count($regressionGate['missing'])>=1,'future candidate gate blocks when a published production regression lacks integrity-valid benchmark coverage');$fullGate=data_model_gate_evaluate($pdo,$candidateView);p46(isset($fullGate['checks']['production_regressions'])&&!$fullGate['checks']['production_regressions']['pass'],'Phase 40 release gate consumes the Phase 46 permanent regression requirement');

$case=data_model_improvement_triage($pdo,$admin,$case['public_id'],['classification'=>'model_defect','status'=>'ready_for_training','triage_note'=>'A separate sanitized training example is approved for dataset consideration.','owner_user_id'=>$admin['id']]);
$trainInput='Sanitized training scenario: user asks for a conclusion that lacks sufficient supporting evidence.';
$trainExpected='Expected behavior: decline to invent facts and request or identify the missing evidence.';
$train=data_model_improvement_proposal_create($pdo,$admin,$case['public_id'],['proposal_type'=>'training_example','title'=>'Training example '.$case['route_key'],'sanitized_input'=>$trainInput,'sanitized_expected_output'=>$trainExpected,'sanitized_context'=>'Synthetic context only.','purpose_justification'=>'Teach evidence-bound behavior without copying production text.','redaction_attested'=>'1','rights_attested'=>'1']);
$train=data_model_improvement_proposal_approve($pdo,$admin,$train['public_id']);$train=data_model_improvement_proposal_publish($pdo,$admin,$train['public_id']);
$q=$pdo->prepare("SELECT * FROM data_corpus_items WHERE public_id=? LIMIT 1");$q->execute([$train['corpus_item_public_id']]);$trainCorpus=$q->fetch();p46($trainCorpus&&$trainCorpus['corpus_type']==='model_training_example'&&(int)$trainCorpus['training_eligible']===1&&(int)$trainCorpus['evaluation_eligible']===0&&(int)$trainCorpus['shared_retrieval_eligible']===0&&(int)$trainCorpus['commercial_training_eligible']===0,'published training proposal is training-only and never shared/commercial-training eligible');

$evalDataset=data_model_improvement_create_dataset_draft($pdo,$admin,'evaluation');$trainingDataset=data_model_improvement_create_dataset_draft($pdo,$admin,'training');
p46($evalDataset['status']==='draft'&&$evalDataset['purpose']==='evaluation'&&in_array('model_regression_case',$evalDataset['selection_policy']['corpus_types'],true),'Phase 46 creates only a draft evaluation dataset policy for regression cases');
p46($trainingDataset['status']==='draft'&&$trainingDataset['purpose']==='training'&&in_array('model_training_example',$trainingDataset['selection_policy']['corpus_types'],true),'Phase 46 creates only a draft training dataset policy for approved training examples');

$nonAdmin=false;try{data_model_improvement_triage($pdo,$outsider,$case['public_id'],['classification'=>'model_defect','status'=>'resolved']);}catch(RuntimeException $e){$nonAdmin=str_contains($e->getMessage(),'Administrator');}p46($nonAdmin,'Phase 46 human governance operations are administrator-only');

$routingAfter=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
$q=$pdo->query("SELECT id,status,current_traffic_percent,revision FROM data_model_deployments ORDER BY id DESC LIMIT 1");$deploymentAfter=$q->fetch();
$q=$pdo->query("SELECT id,status FROM data_model_versions ORDER BY id");$versionsAfter=$q->fetchAll();
$trainingAfter=(int)$pdo->query('SELECT COUNT(*) FROM data_training_jobs')->fetchColumn();$evalRunsAfter=(int)$pdo->query('SELECT COUNT(*) FROM data_evaluation_runs')->fetchColumn();$modelVersionsAfter=(int)$pdo->query('SELECT COUNT(*) FROM data_model_versions')->fetchColumn();
p46($routingAfter===$routingBefore,'Phase 46 improvement work never changes AI routing');
p46($deploymentAfter===$deploymentBefore,'Phase 46 improvement work never changes Phase 44 deployment state or traffic');
p46($versionsAfter===$versionsBefore&&$modelVersionsAfter===$modelVersionsBefore,'Phase 46 improvement work never changes governed model lifecycle or creates a model version');
p46($trainingAfter===$trainingBefore,'Phase 46 improvement work never launches a training job');
p46($evalRunsAfter===$evalRunsBefore,'Phase 46 improvement work never launches an evaluation run');

echo "Phase 46 Production Feedback & Model Improvement MariaDB suite passed.\n";
