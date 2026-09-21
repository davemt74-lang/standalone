<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['database/migrations/20260921_037_dataset_registry_frozen_manifests.sql','app/data-datasets.php','admin/datasets.php','admin/dataset-export.php','tests/phase38-dataset-registry-db.php','tests/phase38-dataset-registry-contract.php'] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 38 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260921_037_dataset_registry_frozen_manifests.sql');
foreach(['data_datasets','data_dataset_items','data_dataset_events','selection_policy_hash','manifest_hash','normalized_text_snapshot','metadata_snapshot_hash','eligibility_snapshot_hash','item_hash'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 38 migration contract missing: '.$needle;
$avoid('database/migrations/20260921_037_dataset_registry_frozen_manifests.sql','DROP TABLE','Phase 38 migration must be expand-only.');
$avoid('database/migrations/20260921_037_dataset_registry_frozen_manifests.sql','TRUNCATE','Phase 38 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-datasets.php');
foreach(['data_dataset_ready','data_dataset_purposes','data_dataset_policy_normalize','data_dataset_create','data_dataset_update_draft','data_dataset_preview','data_dataset_freeze','data_dataset_recompute_item_hashes','data_dataset_snapshot_integrity','data_dataset_manifest_payload','data_dataset_manifest_hash','data_dataset_current_use_status','data_dataset_retire','data_dataset_export','data_dataset_summary'] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 38 runtime helper missing: '.$fn;
foreach(["'retrieval'=>['label'=>'Retrieval'","'evaluation'=>['label'=>'Evaluation'","'training'=>['label'=>'Training'","'commercial_training'=>['label'=>'Commercial training'"] as $needle)if(!str_contains($runtime,$needle))$fail[]='Dataset purpose separation missing: '.$needle;
$need('app/data-datasets.php',"if(\$d['status']!=='draft')throw new RuntimeException('Frozen or retired datasets are immutable.')",'Frozen dataset definitions must be immutable.');
$need('app/data-datasets.php','normalized_text_snapshot','Dataset freeze must preserve exact governed text snapshots.');
$need('app/data-datasets.php','data_dataset_recompute_item_hashes','Manifest verification must recompute hashes from frozen snapshot content.');
$need('app/data-datasets.php','data_dataset_snapshot_integrity','Stored frozen snapshot hashes must be cross-checked against recomputed content.');
$need('app/data-datasets.php',"\$sql.=' FOR UPDATE'",'Dataset freeze must lock selected corpus rows against concurrent consent/rights mutation.');
$need('app/data-datasets.php',"Only frozen datasets can be retired.",'Draft datasets must not enter the retired frozen lifecycle.');
$need('app/data-datasets.php','manifest_integrity_failure','Current-use gate must block manifest integrity failures.');
$need('app/data-datasets.php','eligibility_or_content_changed','Current-use gate must block later eligibility/content/provenance changes.');
$need('app/data-datasets.php',"if(\$includeText&&(\$d['status']!=='frozen'||!\$status['usable']))",'Full-text export must be blocked when a frozen dataset is retired or no longer currently usable.');
$need('app/data-datasets.php',"in_array(\$d['status'],['frozen','retired'],true)",'Manifest audit export must remain available for frozen and retired datasets.');
foreach(['ai_generate(','ai_http_json(','fine_tune','fine-tune','training_job'] as $needle)if(str_contains($runtime,$needle))$fail[]='Phase 38 must register datasets without initiating model training: '.$needle;

$need('admin/datasets.php','CREATE DATASET','Admin Dataset Registry must expose dataset creation.');
$need('admin/datasets.php','Current governed candidates','Admin Dataset Registry must preview governed candidates before freeze.');
$need('admin/datasets.php','Freeze dataset','Admin Dataset Registry must expose an explicit freeze action.');
$need('admin/datasets.php','CURRENT USE BLOCKED','Admin Dataset Registry must visibly distinguish blocked frozen datasets.');
$need('admin/datasets.php','Export manifest JSON','Admin Dataset Registry must expose manifest audit export.');
$need('admin/datasets.php','Export approved data JSON','Admin Dataset Registry must expose governed data export only when usable.');
$need('admin/datasets.php','AUDIT EVENTS','Admin Dataset Registry must expose dataset lifecycle events.');
$need('admin/datasets.php','RETIRED','Admin Dataset Registry must show retired dataset state.');
$need('admin/datasets.php',"in_array(\$selected['status'],['frozen','retired'],true)",'Admin must keep retired frozen snapshots inspectable.');
$need('admin/dataset-export.php',"\$_SERVER['REQUEST_METHOD']!=='POST'",'Dataset exports must be POST-only.');
$need('admin/dataset-export.php','require_csrf()','Dataset exports must require CSRF protection.');
$need('app/shell.php','Dataset Registry','Dataset Registry must be a first-class Admin navigation item.');
$need('admin/index.php','Dataset Registry','Admin Home must surface Dataset Registry.');
$need('admin/data-attribution.php','Open Dataset Registry','Data Governance must hand off to Dataset Registry.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-datasets.php';",'Dataset Registry runtime must load with the application.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 38 Dataset Registry architecture and Admin UI contract suite passed.\n";