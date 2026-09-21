<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dsn=getenv('DB_DSN')?:'';$dbUser=getenv('DB_USER')?:'root';$dbPass=getenv('DB_PASS')?:'';
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow'] as $lib)require_once $root.'/app/'.$lib.'.php';

function auditv1(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

$run='audit'.substr(bin2hex(random_bytes(6)),0,10);
$pub=fn(string $prefix)=>$prefix.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array {
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
        ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};

$owner=$makeUser('AuditOwner');$member=$makeUser('AuditMember');$outsider=$makeUser('AuditOutsider');
$teamPublic=$pub('team');
$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Audit Team']);
$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);

$projectPublic=$pub('project');
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active')")
    ->execute([$projectPublic,$owner['id'],$teamId,'Final Audit Research','Independent final audit fixture']);
$projectId=(int)$pdo->lastInsertId();
$project=project_access($pdo,(int)$owner['id'],$projectPublic);
auditv1((bool)$project,'owner can access audit project');

$makeSource=function(string $domain,string $label)use($pdo,$owner,$projectId,$run,$pub): array {
    $url='https://'.$domain.'/'.$run.'/'.$label;$sourcePublic=$pub('source');
    $pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status,monitoring_enabled,moderation_status) VALUES(?,'article',?,?,?,?,'current',1,'visible')")
        ->execute([$sourcePublic,$url,hash('sha256',$url),$domain,$label]);
    $sourceId=(int)$pdo->lastInsertId();$text='Audit evidence '.$label.' '.$run;
    $pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash,captured_at) VALUES(?,1,?,?,?,?,?,NOW())")
        ->execute([$sourceId,$url,$label,$text,hash('sha256',$text),hash('sha256','target-'.$label.'-'.$run)]);
    $versionId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$sourceId]);
    $pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$sourceId,$owner['id']]);
    return ['id'=>$sourceId,'public_id'=>$sourcePublic,'version_id'=>$versionId];
};
$a=$makeSource('audit-a.example','A');
$b=$makeSource('audit-b.example','B');

$claimPublic=$pub('claim');
$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'Audit Claim','factual','supported')")
    ->execute([$claimPublic,$projectId,$owner['id']]);
$claimId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship,note) VALUES(?,?,?,'source_version',?,'primary','Initial evidence')")
    ->execute([$pub('ev'),$claimId,$owner['id'],$a['version_id']]);

$claimReview=research_review_create($pdo,$owner,'claim',$claimPublic,[$member['id']],null,'Review exact Claim evidence.');
research_review_respond($pdo,$member,(string)$claimReview['public_id'],'approve','Initial evidence reviewed.');
research_review_complete($pdo,$owner,(string)$claimReview['public_id']);
$claimReviewCurrent=research_review_access($pdo,$owner,(string)$claimReview['public_id']);
auditv1($claimReviewCurrent!==null&&!$claimReviewCurrent['is_stale'],'completed Claim review starts current');

$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship,note) VALUES(?,?,?,'source_version',?,'supports','New evidence after review')")
    ->execute([$pub('ev'),$claimId,$owner['id'],$b['version_id']]);
$claimReviewStale=research_review_access($pdo,$owner,(string)$claimReview['public_id']);
auditv1($claimReviewStale!==null&&$claimReviewStale['is_stale'],'adding Claim evidence invalidates prior completed Claim review');

$findingPublic=$pub('finding');
$pdo->prepare("INSERT INTO research_findings(public_id,project_id,created_by_user_id,title,summary,status) VALUES(?,?,?,'Audit Finding','Finding based on reviewed Claim.','final')")
    ->execute([$findingPublic,$projectId,$owner['id']]);
$findingId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO finding_claims(finding_id,claim_id,added_by_user_id,relationship,position) VALUES(?,?,?,'primary',0)")
    ->execute([$findingId,$claimId,$owner['id']]);
$findingReview=research_review_create($pdo,$owner,'finding',$findingPublic,[$member['id']],null,'Review Finding and supporting Claim state.');
research_review_respond($pdo,$member,(string)$findingReview['public_id'],'approve','Finding reviewed.');
research_review_complete($pdo,$owner,(string)$findingReview['public_id']);
auditv1(!research_review_access($pdo,$owner,(string)$findingReview['public_id'])['is_stale'],'completed Finding review starts current');

$pdo->prepare("UPDATE claim_evidence SET relationship='contradicts',note='Relationship changed after Finding review' WHERE claim_id=? AND source_version_id=?")
    ->execute([$claimId,$b['version_id']]);
auditv1(research_review_access($pdo,$owner,(string)$findingReview['public_id'])['is_stale'],'changing linked Claim evidence invalidates prior Finding review');

research_verification_record($pdo,$owner,'claim',$claimPublic,'needs_review','First pass needs another look.');
research_verification_record($pdo,$owner,'claim',$claimPublic,'reviewed_current','Second pass supersedes my prior state.');
$claim=research_claim_access($pdo,$owner,$claimPublic);
$verification=research_verification_claim_state($pdo,$owner,$claim);
auditv1($verification['human_review']['state']==='reviewed_current','latest verification decision from one reviewer supersedes their older decision');
auditv1(($verification['human_review']['counts']['reviewed_current']??0)===1&&($verification['human_review']['counts']['needs_review']??0)===0,'superseded verification decision does not remain in effective counts');
$effective=array_values(array_filter($verification['human_review']['current'],fn($e)=>!empty($e['is_effective'])));
auditv1(count($effective)===1&&$effective[0]['decision']==='reviewed_current','append-only verification history has exactly one effective latest state per reviewer');

$privateToken='OWNER-PRIVATE-DECISION-'.$run;
$pdo->prepare("INSERT INTO research_outcome_events(public_id,user_id,project_id,event_type,decision_type,source_type,source_public_id,object_type,object_public_id,title,summary,dedupe_key,is_manual,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())")
    ->execute([$pub('outcome'),$owner['id'],$projectId,'manual_note','recorded','manual',$projectPublic,'project',$projectPublic,'Owner private decision',$privateToken,hash('sha256','audit-outcome-'.$run)]);
$ownerProvenance=provenance_project_manifest($pdo,$owner,$projectPublic);
$memberProvenanceBeforePack=provenance_project_manifest($pdo,$member,$projectPublic);
auditv1(str_contains(provenance_encode($ownerProvenance),$privateToken),'owner live provenance includes owner-scoped Decision Memory');
auditv1(!str_contains(provenance_encode($memberProvenanceBeforePack),$privateToken),'member live provenance excludes owner Decision Memory');

$ownerPack=research_evidence_pack_create($pdo,$owner,$projectPublic,'project',$projectPublic);
auditv1(!isset($ownerPack['manifest']['provenance']['decision_memory']),'project Evidence Pack excludes viewer-private Decision Memory');
auditv1(!str_contains(research_evidence_pack_encode($ownerPack['manifest']),$privateToken),'owner private Decision Memory cannot enter portable Evidence Pack payload');
auditv1(research_evidence_pack_access($pdo,$member,(string)$ownerPack['public_id'])===null,'another Team member cannot replay creator-scoped Evidence Pack');
auditv1(research_evidence_pack_access($pdo,$outsider,(string)$ownerPack['public_id'])===null,'outsider cannot replay creator-scoped Evidence Pack');

$memberPack=research_evidence_pack_create($pdo,$member,$projectPublic,'claim',$claimPublic);
auditv1(research_evidence_pack_access($pdo,$member,(string)$memberPack['public_id'])!==null,'Team researcher can create and replay their own pack');
auditv1(research_evidence_pack_access($pdo,$owner,(string)$memberPack['public_id'])===null,'project owner cannot inherit another creator permission-scoped pack');
$memberPackList=research_evidence_pack_list($pdo,$member,$projectPublic,20,false);
auditv1(count($memberPackList)===1&&!array_key_exists('manifest_json',$memberPackList[0])&&!array_key_exists('manifest',$memberPackList[0])&&!array_key_exists('current_manifest',$memberPackList[0]),'Evidence Pack list is creator-scoped metadata only');

$receipt=provenance_receipt_create($pdo,$owner,$projectPublic);
$receiptList=provenance_receipts($pdo,$owner,$projectPublic,20);
auditv1(count($receiptList)>=1&&!array_key_exists('manifest_json',$receiptList[0])&&!array_key_exists('manifest',$receiptList[0]),'Audit Receipt list does not load frozen manifest payloads');

$privateReport=research_report_publish($pdo,$project,$owner,'private','Private Audit Report','Owner/admin-only report version.','Audit');
$privateReportVersion=(string)$privateReport['version_public_id'];
auditv1(research_verification_subject($pdo,$member,'report_version',$privateReportVersion)===null,'Team researcher cannot reach private Report Version through Verification');

$assignmentBlocked=false;
try{research_review_create($pdo,$owner,'report_version',$privateReportVersion,[$member['id']],null,'Should not be assignable.');}
catch(InvalidArgumentException|RuntimeException $e){$assignmentBlocked=true;}
auditv1($assignmentBlocked,'owner cannot assign private Report Version review to collaborator without report access');

$legacyReviewPublic=$pub('review-private');
$pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES(?,?,?,'report_version',?,?,?,'Legacy private report review','open')")
    ->execute([$legacyReviewPublic,$projectId,$owner['id'],$privateReportVersion,$privateReport['snapshot_hash'],'Private report v1']);
auditv1(research_review_access($pdo,$owner,$legacyReviewPublic)!==null,'owner can access private Report Version review');
auditv1(research_review_access($pdo,$member,$legacyReviewPublic)===null,'Team researcher cannot use Review Center as private Report Version visibility bypass');

$memberProv=provenance_project_manifest($pdo,$member,$projectPublic);
auditv1(count(array_filter($memberProv['reviews'],fn($r)=>(string)$r['id']===$legacyReviewPublic))===0,'member provenance excludes inaccessible private Report Version review');
auditv1(count(array_filter($memberProv['report_versions'],fn($r)=>(string)$r['version_id']===$privateReportVersion))===0,'member provenance excludes inaccessible private Report Version');

$memberWorkflow=research_workflow_state($pdo,$member,$projectPublic);
auditv1(($memberWorkflow['counts']['open_reviews']??-1)===0,'member workflow ignores inaccessible private Report review');
auditv1(($memberWorkflow['counts']['report_versions']??-1)===0,'member workflow ignores inaccessible private Report Version');
$ownerWorkflow=research_workflow_state($pdo,$owner,$projectPublic);
auditv1(($ownerWorkflow['counts']['open_reviews']??0)>=1&&($ownerWorkflow['counts']['report_versions']??0)>=1,'owner workflow includes accessible private Report review and version');

$runtimePack=file_get_contents($root.'/app/research-evidence-packs.php');
$runtimeProv=file_get_contents($root.'/app/research-provenance.php');
auditv1(!str_contains($runtimePack,'UPDATE research_evidence_packs')&&!str_contains($runtimePack,'DELETE FROM research_evidence_packs'),'Evidence Pack runtime remains immutable');
auditv1(!str_contains($runtimeProv,'UPDATE research_audit_receipts')&&!str_contains($runtimeProv,'DELETE FROM research_audit_receipts'),'Audit Receipt runtime remains immutable');

echo "Research V1 final independent audit suite passed.\n";
