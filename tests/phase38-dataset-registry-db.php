<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/functions.php';require_once $root.'/app/data-attribution.php';require_once $root.'/app/data-datasets.php';
function p38(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
p38(data_dataset_ready($pdo),'Phase 38 Dataset Registry schema is available');

$run='p38'.substr(bin2hex(random_bytes(7)),0,12);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(4)),0,8);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$public=$pub('u');$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,password_hash,status,role) VALUES(?,?,?,?,?,'active',?)")->execute([$public,$username,$name,$username.'@example.test',password_hash('dataset-test',PASSWORD_DEFAULT),$role]);return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'username'=>$username,'display_name'=>$name,'role'=>$role];};
$admin=$makeUser('DatasetAdmin','admin');$user=$makeUser('Contributor');

$sourcePublic=$pub('source');$url='https://'.$run.'.example.test/research';$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'article',?,?,?,?, 'current')")->execute([$sourcePublic,$url,hash('sha256',$url),$run.'.example.test','Dataset source']);$sourceId=(int)$pdo->lastInsertId();
$sourceText='Licensed source text '.$run;$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash) VALUES(?,1,?,?,?,?,?)')->execute([$sourceId,$url,'Dataset source',$sourceText,hash('sha256',$sourceText),hash('sha256','target '.$run)]);$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$sourceId]);

$selected='quoted passage '.$run;$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$pub('cap'),$sourceId,$versionId,$user['id'],$selected]);$captureId=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$comment='Contributor analysis '.$run;$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'public','published')")->execute([$annotationPublic,$user['id'],$sourceId,$versionId,$captureId,$comment]);data_attribution_capture_object($pdo,(int)$user['id'],'annotation',$annotationPublic);

data_contributor_preferences_update($pdo,$user,['allow_shared_retrieval'=>1,'allow_evaluation'=>1,'allow_training'=>0,'allow_commercial_training'=>0,'attribution_required'=>1]);
data_corpus_refresh_object($pdo,'annotation',$annotationPublic);
$retrieval=data_dataset_create($pdo,$admin,['name'=>'Retrieval Baseline '.$run,'purpose'=>'retrieval','corpus_types'=>'annotation_commentary','max_items'=>100,'description'=>'Phase 38 retrieval fixture']);
$retrievalPreview=data_dataset_preview($pdo,$retrieval['public_id']);p38($retrievalPreview['selected_count']===1,'retrieval dataset selects shared-retrieval-eligible corpus');
$training=data_dataset_create($pdo,$admin,['name'=>'Training Baseline '.$run,'purpose'=>'training','corpus_types'=>'annotation_commentary','max_items'=>100]);
$trainingPreview=data_dataset_preview($pdo,$training['public_id']);p38($trainingPreview['selected_count']===0,'training dataset excludes corpus without training consent');
$failed=false;try{data_dataset_freeze($pdo,$admin,$training['public_id']);}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'No currently eligible corpus items');}p38($failed,'empty governed selection cannot be frozen');

$policyHash=$retrieval['selection_policy_hash'];$retrieval=data_dataset_update_draft($pdo,$admin,$retrieval['public_id'],['name'=>$retrieval['name'],'purpose'=>'retrieval','corpus_types'=>'annotation_commentary','max_items'=>100,'description'=>'Phase 38 retrieval fixture']);p38(hash_equals($policyHash,$retrieval['selection_policy_hash']),'normalized unchanged selection policy has a stable hash');

$frozen=data_dataset_freeze($pdo,$admin,$retrieval['public_id']);p38($frozen['status']==='frozen'&&(int)$frozen['item_count']===1&&!empty($frozen['manifest_hash']),'eligible draft freezes into an immutable dataset snapshot');
$manifestHash=$frozen['manifest_hash'];$calc=data_dataset_manifest_hash($pdo,$frozen);p38(hash_equals($manifestHash,$calc),'stored manifest hash deterministically reproduces from frozen items');
$items=data_dataset_items($pdo,$retrieval['public_id']);p38(count($items)===1&&$items[0]['normalized_text_snapshot']===$comment,'frozen item stores the exact eligible contributor text snapshot');
p38(!str_contains($items[0]['normalized_text_snapshot'],$selected)&&!str_contains($items[0]['normalized_text_snapshot'],$sourceText),'dataset snapshot does not substitute captured publisher text for contributor commentary');

$immutable=false;try{data_dataset_update_draft($pdo,$admin,$retrieval['public_id'],['name'=>'Mutated']);}catch(RuntimeException $e){$immutable=str_contains($e->getMessage(),'immutable');}p38($immutable,'frozen dataset policy and identity cannot be edited');
$usable=data_dataset_current_use_status($pdo,$retrieval['public_id']);p38($usable['usable']&&$usable['manifest_ok'],'freshly frozen dataset passes current-use and manifest-integrity validation');
$export=data_dataset_export($pdo,$admin,$retrieval['public_id'],true);p38(($export['items'][0]['normalized_text']??'')===$comment,'currently usable frozen dataset can export approved text snapshot');

data_contributor_preferences_update($pdo,$user,['allow_shared_retrieval'=>0,'allow_evaluation'=>0,'allow_training'=>0,'allow_commercial_training'=>0,'attribution_required'=>1]);
$blocked=data_dataset_current_use_status($pdo,$retrieval['public_id']);p38(!$blocked['usable']&&$blocked['invalid_items']===1,'later contributor opt-out blocks future use of the frozen dataset without rewriting it');
$q=$pdo->prepare('SELECT normalized_text_snapshot,item_hash FROM data_dataset_items WHERE dataset_id=? LIMIT 1');$q->execute([$frozen['id']]);$historical=$q->fetch();p38($historical&&$historical['normalized_text_snapshot']===$comment,'revocation preserves the historical frozen snapshot for audit');
$manifest=data_dataset_export($pdo,$admin,$retrieval['public_id'],false);p38($manifest['manifest_hash']===$manifestHash&&!$manifest['current_use_status']['usable'],'blocked dataset still exports its non-text audit manifest');
$blockedExport=false;try{data_dataset_export($pdo,$admin,$retrieval['public_id'],true);}catch(RuntimeException $e){$blockedExport=str_contains($e->getMessage(),'blocked');}p38($blockedExport,'blocked dataset refuses full-text data export');

$retired=data_dataset_retire($pdo,$admin,$retrieval['public_id']);p38($retired['status']==='retired','frozen dataset can be retired without deleting its manifest');
$retiredManifest=data_dataset_export($pdo,$admin,$retrieval['public_id'],false);p38($retiredManifest['manifest_hash']===$manifestHash,'retired dataset preserves manifest-only audit export');
$retiredData=false;try{data_dataset_export($pdo,$admin,$retrieval['public_id'],true);}catch(RuntimeException $e){$retiredData=str_contains($e->getMessage(),'blocked');}p38($retiredData,'retired dataset refuses full-text export');

data_contributor_preferences_update($pdo,$user,['allow_shared_retrieval'=>1,'allow_evaluation'=>1,'allow_training'=>1,'allow_commercial_training'=>0,'attribution_required'=>1]);data_corpus_refresh_object($pdo,'annotation',$annotationPublic);
$trainable=data_dataset_create($pdo,$admin,['name'=>'Training Snapshot '.$run,'purpose'=>'training','corpus_types'=>['annotation_commentary'],'max_items'=>1]);$trainPreview=data_dataset_preview($pdo,$trainable['public_id']);p38($trainPreview['selected_count']===1,'training purpose begins selecting only after explicit training consent');
$trainFrozen=data_dataset_freeze($pdo,$admin,$trainable['public_id']);$events=data_dataset_events($pdo,$trainable['public_id']);$types=array_column($events,'event_type');p38(in_array('created',$types,true)&&in_array('frozen',$types,true),'dataset lifecycle records auditable create/freeze events');

$q=$pdo->prepare('UPDATE data_dataset_items SET item_hash=? WHERE dataset_id=? AND position=0');$q->execute([str_repeat('0',64),$trainFrozen['id']]);$integrity=data_dataset_current_use_status($pdo,$trainable['public_id']);p38(!$integrity['usable']&&!$integrity['manifest_ok']&&$integrity['reason']==='manifest_integrity_failure','manifest tampering is detected before dataset use');

$nonAdmin=false;try{data_dataset_create($pdo,$user,['name'=>'Unauthorized','purpose'=>'retrieval']);}catch(RuntimeException $e){$nonAdmin=str_contains($e->getMessage(),'Administrator');}p38($nonAdmin,'dataset lifecycle operations are administrator-only');

echo "Phase 38 Dataset Registry & Frozen Dataset Manifests MariaDB suite passed.\n";
