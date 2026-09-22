<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo2=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','functions','data-attribution','data-datasets','notifications','ai','data-evaluations','data-model-registry','data-training','data-post-training','data-model-release','data-model-deployment','data-model-observability','data-model-improvement','data-model-campaigns','intelligence-release-audit','release','release-operations'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p495(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p495_rm(string $path): void {if(is_link($path)||is_file($path)){@unlink($path);return;}if(!is_dir($path))return;foreach(scandir($path)?:[] as $name){if($name==='.'||$name==='..')continue;p495_rm($path.'/'.$name);}@rmdir($path);}

$lockName=app_advisory_lock($pdo,'hardening-review','same-governed-object',0);$blocked=false;
try{app_advisory_lock($pdo2,'hardening-review','same-governed-object',0);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'already in progress');}
p495($blocked,'shared advisory lock prevents two database connections from mutating the same governed object concurrently');
app_advisory_unlock($pdo,$lockName);$lock2=app_advisory_lock($pdo2,'hardening-review','same-governed-object',0);p495($lock2!=='','advisory lock becomes available immediately after explicit release');app_advisory_unlock($pdo2,$lock2);

$q=$pdo->query("SELECT id,public_id,role FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1");$admin=$q->fetch();if(!$admin)throw new RuntimeException('Hardening audit requires an administrator fixture.');

$datasetName='Hardening rollback '.bin2hex(random_bytes(4));$pdo->beginTransaction();$draft=data_dataset_create($pdo,$admin,['name'=>$datasetName,'purpose'=>'evaluation','corpus_types'=>['model_regression_case'],'max_items'=>1]);p495($pdo->inTransaction(),'Dataset Registry creation respects an existing outer transaction instead of committing it');$draftPublic=(string)$draft['public_id'];$pdo->rollBack();p495(data_dataset_get($pdo,$draftPublic)===null,'rolling back the outer transaction removes the composed dataset and its event atomically');

$q=$pdo->query("SELECT public_id FROM data_model_improvement_cases WHERE classification NOT IN ('untriaged','expected_behavior','no_action') ORDER BY id DESC LIMIT 1");$casePublic=(string)($q->fetchColumn()?:'');if($casePublic==='')throw new RuntimeException('Hardening audit requires an actionable Phase 46 case.');
$title='Idempotent draft '.bin2hex(random_bytes(4));$proposal=data_model_improvement_proposal_create($pdo,$admin,$casePublic,['proposal_type'=>'evaluation_case','title'=>$title,'sanitized_input'=>'Sanitized idempotent input','sanitized_expected_output'=>'Sanitized idempotent expected behavior','sanitized_context'=>'Review fixture context','purpose_justification'=>'Verify identical draft saves are not mistaken for stale writes.','redaction_attested'=>1,'rights_attested'=>1]);
$updated=data_model_improvement_proposal_update($pdo,$admin,$proposal['public_id'],['title'=>$proposal['title'],'sanitized_input'=>$proposal['sanitized_input'],'sanitized_expected_output'=>$proposal['sanitized_expected_output'],'sanitized_context'=>$proposal['sanitized_context'],'purpose_justification'=>$proposal['purpose_justification'],'redaction_attested'=>1,'rights_attested'=>1]);
p495($updated['status']==='draft'&&hash_equals((string)$proposal['content_hash'],(string)$updated['content_hash']),'Phase 46 accepts an idempotent draft save without a false concurrency conflict');
$sourceCase=data_model_improvement_case_get($pdo,$casePublic);$originalClassification=(string)$sourceCase['classification'];$originalStatus=(string)$sourceCase['status'];
data_model_improvement_triage($pdo,$admin,$casePublic,['classification'=>'no_action','status'=>'no_action','triage_note'=>'Hardening actionability test.']);
$approveBlocked=false;try{data_model_improvement_proposal_approve($pdo,$admin,$proposal['public_id']);}catch(RuntimeException $e){$approveBlocked=str_contains($e->getMessage(),'no longer actionable');}p495($approveBlocked,'Phase 46 cannot approve reusable evidence after human triage changes the source case to no action');
data_model_improvement_triage($pdo,$admin,$casePublic,['classification'=>$originalClassification,'status'=>$originalStatus,'triage_note'=>'Restore hardening fixture.']);
$proposal=data_model_improvement_proposal_approve($pdo,$admin,$proposal['public_id']);p495($proposal['status']==='approved','Phase 46 can approve the same evidence again after human triage restores an actionable classification');
data_model_improvement_triage($pdo,$admin,$casePublic,['classification'=>'no_action','status'=>'no_action','triage_note'=>'Hardening publication test.']);
$publishBlocked=false;try{data_model_improvement_proposal_publish($pdo,$admin,$proposal['public_id']);}catch(RuntimeException $e){$publishBlocked=str_contains($e->getMessage(),'no longer actionable');}p495($publishBlocked,'Phase 46 cannot publish approved reusable evidence after the source case becomes no action');
data_model_improvement_triage($pdo,$admin,$casePublic,['classification'=>$originalClassification,'status'=>$originalStatus,'triage_note'=>'Restore hardening fixture.']);
$pdo->prepare('DELETE FROM data_model_improvement_proposals WHERE public_id=?')->execute([$proposal['public_id']]);

$q=$pdo->query("SELECT public_id FROM data_model_improvement_campaigns WHERE status='abandoned' AND plan_hash IS NOT NULL ORDER BY id DESC LIMIT 1");$campaignPublic=(string)($q->fetchColumn()?:'');
if($campaignPublic!==''){
    $closed=data_model_campaign_get($pdo,$campaignPublic,false);$stale=$closed;$stale['status']='evaluation';$sync=data_model_campaign_sync_status($pdo,$stale);$fresh=data_model_campaign_get($pdo,$campaignPublic,false);
    p495($sync==='abandoned'&&$fresh['status']==='abandoned','stale automatic Phase 47 lifecycle sync cannot resurrect an abandoned human-closed campaign');

    $caseLink=data_model_campaign_cases($pdo,(int)$closed['id'])[0]??null;$evalLink=null;foreach(data_model_campaign_proposals($pdo,(int)$closed['id']) as $cp)if($cp['proposal_type']==='evaluation_case'){$evalLink=$cp;break;}
    if($caseLink&&$evalLink){
        $guardCampaign=data_model_campaign_create($pdo,$admin,['title'=>'Hardening triage guard '.bin2hex(random_bytes(3)),'strategy'=>'evaluation_only','base_model_version_id'=>$closed['base_model_version_id'],'objective'=>'Prove locked campaign triage authority.','success_criteria'=>'Human triage cannot drift underneath a locked campaign.']);
        data_model_campaign_add_case($pdo,$admin,$guardCampaign['public_id'],$caseLink['case_public_id']);data_model_campaign_add_proposal($pdo,$admin,$guardCampaign['public_id'],$evalLink['proposal_public_id']);data_model_campaign_create_dataset_drafts($pdo,$admin,$guardCampaign['public_id']);$guardCampaign=data_model_campaign_lock($pdo,$admin,$guardCampaign['public_id']);
        p495(data_model_campaign_current_use($pdo,$guardCampaign)['usable'],'newly locked campaign passes current-use case governance');
        $triageBlocked=false;try{data_model_improvement_triage($pdo,$admin,$caseLink['case_public_id'],['classification'=>'no_action','status'=>'no_action']);}catch(RuntimeException $e){$triageBlocked=str_contains($e->getMessage(),'active campaign');}p495($triageBlocked,'Phase 46 refuses to reclassify a case underneath an active locked Phase 47 campaign');
        $pdo->prepare("UPDATE data_model_improvement_cases SET classification='expected_behavior' WHERE public_id=?")->execute([$caseLink['case_public_id']]);$drift=data_model_campaign_get($pdo,$guardCampaign['public_id'],false);p495(!data_model_campaign_current_use($pdo,$drift)['usable'],'Phase 47 current-use validation detects direct human-triage classification drift');
        $pdo->prepare('UPDATE data_model_improvement_cases SET classification=? WHERE public_id=?')->execute([$caseLink['classification'],$caseLink['case_public_id']]);data_model_campaign_close($pdo,$admin,$guardCampaign['public_id'],'abandoned','Hardening triage guard complete.');
    }
}
$sample=intelligence_release_closed_loop_sample($pdo);p495(!$sample['available']||($sample['campaign']['status']??'')!=='abandoned','Phase 48 release lineage never chooses an abandoned campaign as its representative closed-loop sample');

$tmp=sys_get_temp_dir().'/annotated-review-package-'.bin2hex(random_bytes(5));$storage=sys_get_temp_dir().'/annotated-review-storage-'.bin2hex(random_bytes(5));$backup=sys_get_temp_dir().'/annotated-review-backup-'.bin2hex(random_bytes(5));$altStorage=sys_get_temp_dir().'/annotated-review-alt-storage-'.bin2hex(random_bytes(5));
try{
    foreach([$tmp.'/app',$tmp.'/assets',$tmp.'/database/migrations',$tmp.'/extension',$tmp.'/storage/uploads',$tmp.'/uploads',$storage,$backup,$altStorage] as $dir)if(!is_dir($dir))mkdir($dir,0700,true);
    file_put_contents($tmp.'/app/code.php',"<?php echo 'one';\n");file_put_contents($tmp.'/assets/app.js',"console.log('one');\n");file_put_contents($tmp.'/database/migrations/20260922_046_model_improvement_campaigns.sql',"-- fixture\n");
    file_put_contents($tmp.'/extension/manifest.json',json_encode(['manifest_version'=>3,'version'=>ANNOTATED_EXTENSION_VERSION],JSON_THROW_ON_ERROR));file_put_contents($tmp.'/storage/.htaccess',"Deny from all\n");file_put_contents($tmp.'/uploads/.htaccess',"Deny from all\n");
    $fp1=release_package_fingerprint($tmp);file_put_contents($tmp.'/assets/app.js',"console.log('two');\n");$fp2=release_package_fingerprint($tmp);p495(!hash_equals($fp1,$fp2),'full release fingerprint changes when any package-managed JS/PHP content changes');
    file_put_contents($tmp.'/assets/app.js',"console.log('one');\n");$manifest=release_manifest_data($tmp,str_repeat('a',40));file_put_contents($tmp.'/RELEASE-MANIFEST.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    p495(release_installed_manifest_status($tmp)['pass'],'installed release manifest validates against the full staged deploy tree');
    file_put_contents($tmp.'/config.php',"<?php return ['secret'=>'runtime'];\n");file_put_contents($tmp.'/storage/uploads/runtime.bin','runtime');file_put_contents($tmp.'/uploads/runtime.bin','runtime');p495(release_installed_manifest_status($tmp)['pass'],'runtime secrets/uploads are excluded from deploy-package identity');
    file_put_contents($tmp.'/app/code.php',"<?php echo 'tampered';\n");p495(!release_installed_manifest_status($tmp)['pass'],'installed package verification detects post-deploy managed-code tampering');
    file_put_contents($tmp.'/app/code.php',"<?php echo 'one';\n");
    $link=$tmp.'/assets/link.php';if(@symlink($tmp.'/app/code.php',$link)){$symlinkRejected=false;try{release_package_fingerprint($tmp);}catch(RuntimeException $e){$symlinkRejected=str_contains($e->getMessage(),'symlink');}p495($symlinkRejected,'release fingerprint rejects symlinked package content');@unlink($link);}

    $dbName='';foreach(explode(';',substr($dsn,6)) as $part)if(str_starts_with($part,'dbname='))$dbName=substr($part,7);
    $config=['app'=>['base_url'=>'https://annotated.example.test','encryption_key'=>str_repeat('k',48)],'db'=>['dsn'=>$dsn,'user'=>$dbUser,'pass'=>$dbPass],'storage'=>['private_root'=>$storage],'extension'=>['allowed_ids'=>[]],'oauth'=>['google'=>[],'x'=>[]],'transcription'=>['command'=>'/bin/true']];
    file_put_contents($backup.'/database.sql.gz','db');file_put_contents($backup.'/private-storage.tar.gz','storage');release_backup_manifest_write($backup,$config,$root,['build_sha'=>str_repeat('b',40)]);
    file_put_contents($backup.'/.INCOMPLETE','partial');p495(!release_backup_manifest_verify($backup)['ok'],'backup verification rejects a directory still marked incomplete');@unlink($backup.'/.INCOMPLETE');p495(release_backup_manifest_verify($backup)['ok'],'backup verification accepts the same backup after the completion marker is cleared');
    $dbMismatch=$config;$dbMismatch['db']['dsn']=preg_replace('/dbname=[^;]+/','dbname=hardening_mismatch',(string)$dsn);$blockedDb=false;try{release_restore_plan($backup,$dbMismatch);}catch(RuntimeException $e){$blockedDb=str_contains($e->getMessage(),'database target');}p495($blockedDb,'restore planner rejects a backup/database target mismatch');
    $storageMismatch=$config;$storageMismatch['storage']['private_root']=$altStorage;$blockedStorage=false;try{release_restore_plan($backup,$storageMismatch);}catch(RuntimeException $e){$blockedStorage=str_contains($e->getMessage(),'private-storage target');}p495($blockedStorage,'restore planner rejects a backup/private-storage target mismatch');
}finally{p495_rm($tmp);p495_rm($storage);p495_rm($backup);p495_rm($altStorage);}

echo "Recent Build Phase 44–49 hardening MariaDB suite passed.\n";
