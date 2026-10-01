<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-tasks','research-programs','research-missions','research-accounts','sponsored-research-campaigns','sponsored-research-participation','data-attribution','data-datasets','sponsored-research-submissions'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p80s4(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p80s4'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('SubmissionAdmin','admin');$sponsor=$make('SubmissionSponsor');$researcher=$make('SubmissionResearcher');$other=$make('OtherResearcher');

$pkg=(int)$pdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status) VALUES(?,'personal',?,?,?,?, 'active',CURRENT_DATE,DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),'active')")->execute([$pub('acct'),'Submission Sponsor Account',(int)$sponsor['id'],(int)$sponsor['id'],$pkg]);$accountId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$accountId,(int)$sponsor['id']]);$q=$pdo->prepare('SELECT public_id FROM accounts WHERE id=?');$q->execute([$accountId]);$accountPublic=(string)$q->fetchColumn();

sponsor_account_apply($pdo,$sponsor,['organization_name'=>'Submission Sponsor']);research_account_admin_decide($pdo,$admin,(int)$sponsor['id'],'sponsor_account','approved','Approved sponsor.','organization');
research_account_apply($pdo,$researcher,['specialties'=>'research','languages'=>'English']);research_account_admin_decide($pdo,$admin,(int)$researcher['id'],'research_account','approved','Approved researcher.','identity');
research_account_apply($pdo,$other,['specialties'=>'research','languages'=>'English']);research_account_admin_decide($pdo,$admin,(int)$other['id'],'research_account','approved','Approved researcher.','identity');

$campaign=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$accountPublic,'title'=>'Submission Provenance Campaign','brief'=>'Submit attributable evidence.','objective'=>'Verify immutable sponsored research provenance.','questions'=>['What does the evidence support?'],'access_mode'=>'public','budget'=>'500.00','eligibility'=>['min_verification'=>'identity'],'disclosures'=>['sponsorship_disclosure_required'=>false,'conflict_disclosure_required'=>false,'nda_required'=>false],'submission_deadline'=>'2030-12-31 23:59:00']);
$campaign=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$campaign['public_id'],'open');
sponsored_research_campaign_terms_publish($pdo,$sponsor,(string)$campaign['public_id'],'Submit only research you are authorized to contribute.','Initial submission terms.');
$participation=sponsored_research_campaign_join($pdo,$researcher,(string)$campaign['public_id'],['accept_terms'=>true]);
p80s4($participation['status']==='active','Researcher joins before creating a submission.');

$sourcePublic=$pub('source');$url='https://'.$run.'.example.test/source';$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'article',?,?,?,?, 'current')")->execute([$sourcePublic,$url,hash('sha256',$url),$run.'.example.test','Sponsored evidence source']);$sourceId=(int)$pdo->lastInsertId();
$sourceText='Publisher evidence '.$run;$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash) VALUES(?,1,?,?,?,?,?)')->execute([$sourceId,$url,'Sponsored evidence source',$sourceText,hash('sha256',$sourceText),hash('sha256','target '.$run)]);$sourceVersionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$sourceVersionId,$sourceId]);
$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$pub('cap'),$sourceId,$sourceVersionId,(int)$researcher['id'],'Selected evidence '.$run]);$captureId=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$originalComment='Original sponsored analysis '.$run;$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'private','draft')")->execute([$annotationPublic,(int)$researcher['id'],$sourceId,$sourceVersionId,$captureId,$originalComment]);

$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$pub('cap2'),$sourceId,$sourceVersionId,(int)$other['id'],'Other evidence']);$otherCapture=(int)$pdo->lastInsertId();
$otherAnnotation=$pub('ann-other');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'private','draft')")->execute([$otherAnnotation,(int)$other['id'],$sourceId,$sourceVersionId,$otherCapture,'Other researcher work']);
$foreignBlocked=false;try{sponsored_research_submission_asset_snapshot($pdo,$researcher,['type'=>'annotation','public_id'=>$otherAnnotation]);}catch(RuntimeException $e){$foreignBlocked=str_contains($e->getMessage(),'own contributed');}p80s4($foreignBlocked,'Researcher cannot submit another contributor’s Annotation.');

$draft=sponsored_research_submission_draft($pdo,$researcher,(string)$campaign['public_id'],['title'=>'Supply evidence package','summary'=>'Source-backed sponsored research summary.','methodology'=>'Reviewed the captured source and documented the finding.','limitations'=>'Single-source evidence.']);
p80s4($draft['status']==='draft'&&(int)$draft['current_revision']===0,'Submission begins as an editable draft without an immutable revision.');

$submitted=sponsored_research_submission_submit($pdo,$researcher,(string)$draft['public_id'],[
 ['type'=>'annotation','public_id'=>$annotationPublic],
 ['type'=>'source','public_id'=>$sourcePublic]
]);
p80s4($submitted['status']==='submitted'&&(int)$submitted['current_revision']===1&&strlen((string)$submitted['latest_snapshot_hash'])===64,'Submitting creates immutable revision 1 and a canonical snapshot hash.');
$versions=sponsored_research_submission_versions($pdo,(int)$submitted['id']);p80s4(count($versions)===1&&(int)$versions[0]['campaign_revision']===(int)$campaign['current_revision'],'Submission version freezes the accepted campaign revision.');
$assets=sponsored_research_submission_version_assets($pdo,(int)$versions[0]['id']);p80s4(count($assets)===2,'Submission version freezes every submitted research asset.');
$annAsset=array_values(array_filter($assets,fn($a)=>$a['asset_type']==='annotation'))[0]??null;$srcAsset=array_values(array_filter($assets,fn($a)=>$a['asset_type']==='source'))[0]??null;
p80s4($annAsset&&$annAsset['data_contribution_id']!==null&&(int)$annAsset['contributor_user_id']===(int)$researcher['id'],'Contributor-owned Annotation links to the Phase 37 contribution ledger.');
p80s4($srcAsset&&$srcAsset['contributor_user_id']===null&&strlen((string)$srcAsset['source_rights_snapshot_hash'])===64,'Source remains evidence with a separately hashed source-rights snapshot.');
$rights=json_decode((string)$srcAsset['source_rights_snapshot_json'],true);p80s4(($rights['rights_class']??'')==='unknown'&&(int)($rights['training_allowed']??1)===0,'Unknown Source rights remain closed for training inside the submission snapshot.');

$q=$pdo->prepare("SELECT COUNT(*) FROM data_provenance_edges WHERE to_type='sponsored_submission_version' AND to_public_id=? AND relationship='submitted_as'");$q->execute([(string)$versions[0]['public_id']]);p80s4((int)$q->fetchColumn()===2,'Phase 37 provenance graph links each submitted asset to the immutable submission version.');
$q=$pdo->prepare("SELECT COUNT(*) FROM data_contributions WHERE object_type='sponsored_submission_version' AND object_public_id=? AND actor_user_id=?");$q->execute([(string)$versions[0]['public_id'],(int)$researcher['id']]);p80s4((int)$q->fetchColumn()===1,'Immutable submission version is itself recorded in the Phase 37 contribution ledger.');

$assetSnapshotBefore=(string)$annAsset['snapshot_hash'];$pdo->prepare("UPDATE annotations SET text_commentary=? WHERE public_id=?")->execute(['Changed after submission '.$run,$annotationPublic]);$assetsAfter=sponsored_research_submission_version_assets($pdo,(int)$versions[0]['id']);$annAfter=array_values(array_filter($assetsAfter,fn($a)=>$a['asset_type']==='annotation'))[0]??null;
p80s4($annAfter&&hash_equals($assetSnapshotBefore,(string)$annAfter['snapshot_hash'])&&str_contains((string)$annAfter['snapshot_json'],$originalComment),'Later Annotation edits do not alter the frozen submitted asset snapshot.');

$immutable=false;try{sponsored_research_submission_draft($pdo,$researcher,(string)$campaign['public_id'],['title'=>'Changed','summary'=>'Changed']);}catch(RuntimeException $e){$immutable=str_contains($e->getMessage(),'immutable');}p80s4($immutable,'Submitted research cannot be silently rewritten before a revision is requested.');
$prefs=data_contributor_preferences($pdo,(int)$researcher['id']);p80s4((int)$prefs['allow_training']===0&&(int)$prefs['allow_evaluation']===0,'Sponsored submission creates no training or evaluation consent.');

echo "Phase 80 Section 4 Sponsored Research Submission & Provenance database journey passed.\n";
