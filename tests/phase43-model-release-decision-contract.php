<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['database/migrations/20260922_042_model_release_decision_workspace.sql','app/data-model-release.php','admin/model-release.php','admin/model-release-export.php','tests/phase43-model-release-decision-db.php','tests/phase43-model-release-decision-contract.php'] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 43 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260922_042_model_release_decision_workspace.sql');
foreach(['data_model_release_decisions','data_model_release_checklist_items','data_model_release_reviews','data_model_release_signatures','data_model_release_events','readiness_packet_hash','model_version_hash','baseline_version_hash','deployment_plan_hash','rollback_plan_hash','decision_hash','final_signature_hash'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 43 migration contract missing: '.$needle;
$avoid('database/migrations/20260922_042_model_release_decision_workspace.sql','DROP TABLE','Phase 43 migration must be expand-only.');
$avoid('database/migrations/20260922_042_model_release_decision_workspace.sql','TRUNCATE','Phase 43 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-model-release.php');
foreach(['data_model_release_ready','data_model_release_decision_create','data_model_release_default_checklist','data_model_release_current_context','data_model_release_subject_hash','data_model_release_review_submit','data_model_release_review_integrity','data_model_release_checklist_summary','data_model_release_finalize_checks','data_model_release_finalize','data_model_release_decision_integrity','data_model_release_archive'] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 43 runtime helper missing: '.$fn;
$need('app/data-model-release.php','The release-decision creator cannot satisfy the independent reviewer requirement.','Phase 43 must enforce an independent reviewer distinct from the creator.');
$need('app/data-model-release.php','decision_snapshot_hash','Reviewer signatures must bind to the current release-decision snapshot.');
$need('app/data-model-release.php','Required release checklist items cannot be marked not applicable.','Required model-risk checks cannot be bypassed as not applicable.');
$need('app/data-model-release.php','rollback_target_same_registry','Rollback target must remain inside the governed model family.');
$need('app/data-model-release.php','rollback_target_governed','Rollback target must already be a governed model version.');
$need('app/data-model-release.php','proceed_to_governed_release','Proceed must be recorded as a human decision outcome, not a model transition.');
$need('app/data-model-release.php','reviewers_recommend_proceed','Proceed gate must require current independent Proceed recommendations.');
$need('app/data-model-release.php','final_signature_hash','Final release decision must be signed and tamper-evident.');
$avoid('app/data-model-release.php','data_model_transition(','Phase 43 must never transition Phase 40 model lifecycle automatically.');
$avoid('app/data-model-release.php','UPDATE ai_settings','Phase 43 must never change production AI task routing.');
$avoid('app/data-model-release.php','ai_generate(','Phase 43 release decisions must not be delegated to model inference.');
$avoid('app/data-model-release.php','data_training_provider_submit(','Phase 43 must never retrain a model.');

foreach(['NEW DECISION','MODEL-RISK CHECKLIST','INDEPENDENT REVIEW','FINAL HUMAN DECISION','SIGNED DECISION','SIGNATURES','AUDIT EVENTS'] as $needle)$need('admin/model-release.php',$needle,'Phase 43 Admin Release Decision contract missing: '.$needle);
$need('admin/model-release.php','Another administrator must sign the independent review.','Admin UI must explain creator/reviewer separation.');
$need('admin/model-release.php','Open Model Registry for governed lifecycle action','Admin UI must hand Proceed decisions to explicit Phase 40 controls.');
$need('admin/model-release.php','Review AI routing separately','Admin UI must keep routing separate from lifecycle decision.');
$need('admin/model-release.php','it will not change model lifecycle or production routing','Final signing UI must state the non-automatic boundary.');
$need('admin/model-release-export.php',"\$_SERVER['REQUEST_METHOD']!=='POST'",'Signed decision export must be POST-only.');
$need('admin/model-release-export.php','require_csrf()','Signed decision export must require CSRF.');
$need('admin/model-release-export.php','data_model_release_decision_integrity','Signed decision export must refuse failed decision integrity.');
$need('admin/model-release-export.php','data_post_training_packet_integrity','Signed decision export must refuse failed readiness-packet integrity.');
$need('app/shell.php','Release Decisions','Release Decisions must be first-class Admin navigation.');
$need('admin/index.php','Model Release Decisions','Admin Home must surface Model Release Decisions.');
$need('admin/post-training.php','Open Model Release Decision','Phase 42 must hand ready packets into Phase 43.');
$need('admin/model-registry.php','PHASE 43 RELEASE DECISIONS','Model Registry must display Phase 43 human decision records.');
$need('admin/model-registry.php','These records never perform the lifecycle transition shown on this page.','Model Registry UI must preserve the Phase 43/Phase 40 boundary.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-model-release.php';",'Phase 43 runtime must load with the application.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 43 Model Release Decision Workspace architecture and Admin UI contract suite passed.\n";
