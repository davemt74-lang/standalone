<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-tasks','research-programs','research-missions','research-accounts','sponsored-research-campaigns','sponsored-research-participation','data-attribution','data-datasets','sponsored-research-submissions','sponsored-research-reviews'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p80s5(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p80s5'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('ReviewAdmin','admin');$sponsor=$make('ReviewSponsor');$researcher=$make('ReviewResearcher');$reviewer=$make('IndependentReviewer');$unapproved=$make('UnapprovedReviewer');

$pkg=(int)$pdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status) VALUES(?,'personal',?,?,?,?, 'active',CURRENT_DATE,DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),'active')")->execute([$pub('acct'),'Review Sponsor Account',(int)$sponsor['id'],(int)$sponsor['id'],$pkg]);$accountId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$accountId,(int)$sponsor['id']]);$q=$pdo->prepare('SELECT public_id FROM accounts WHERE id=?');$q->execute([$accountId]);$accountPublic=(string)$q->fetchColumn();

sponsor_account_apply($pdo,$sponsor,['organization_name'=>'Review Sponsor']);research_account_admin_decide($pdo,$admin,(int)$sponsor['id'],'sponsor_account','approved','Approved sponsor.','organization');
foreach([$researcher,$reviewer] as $person){research_account_apply($pdo,$person,['specialties'=>'research','languages'=>'English']);research_account_admin_decide($pdo,$admin,(int)$person['id'],'research_account','approved','Approved researcher.','identity');}

$campaign=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$accountPublic,'title'=>'Review Governance Campaign','brief'=>'Submit source-backed work for independent review.','objective'=>'Exercise review, revision and dispute controls.','questions'=>['What does the evidence support?'],'access_mode'=>'public','budget'=>'750.00','eligibility'=>['min_verification'=>'identity'],'disclosures'=>['sponsorship_disclosure_required'=>false,'conflict_disclosure_required'=>false,'nda_required'=>false],'submission_deadline'=>'2030-12-31 23:59:00']);
$campaign=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$campaign['public_id'],'open');
sponsored_research_campaign_terms_publish($pdo,$sponsor,(string)$campaign['public_id'],'Submit attributable research and accept independent review.','Initial review terms.');
$participation=sponsored_research_campaign_join($pdo,$researcher,(string)$campaign['public_id'],['accept_terms'=>true]);

$sourcePublic=$pub('source');$url='https://'.$run.'.example.test/source';$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'article',?,?,?,?, 'current')")->execute([$sourcePublic,$url,hash('sha256',$url),$run.'.example.test','Review source']);$sourceId=(int)$pdo->lastInsertId();
$text='Review source text '.$run;$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash) VALUES(?,1,?,?,?,?,?)')->execute([$sourceId,$url,'Review source',$text,hash('sha256',$text),hash('sha256','target '.$run)]);$sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$sv,$sourceId]);
$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$pub('cap'),$sourceId,$sv,(int)$researcher['id'],'Selected evidence']);$cap=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'private','draft')")->execute([$annotationPublic,(int)$researcher['id'],$sourceId,$sv,$cap,'Research analysis '.$run]);

$draft=sponsored_research_submission_draft($pdo,$researcher,(string)$campaign['public_id'],['title'=>'Initial review submission','summary'=>'Initial source-backed analysis.','methodology'=>'Manual review.','limitations'=>'Limited evidence.']);
$submitted=sponsored_research_submission_submit($pdo,$researcher,(string)$draft['public_id'],[['type'=>'annotation','public_id'=>$annotationPublic],['type'=>'source','public_id'=>$sourcePublic]]);
p80s5($submitted['status']==='submitted'&&(int)$submitted['current_revision']===1,'Research revision 1 is submitted before review.');

$case=sponsored_research_review_open($pdo,$sponsor,(string)$submitted['public_id'],['criteria'=>['Evidence strength','Completeness','Methodology'],'blind_review'=>true]);
p80s5($case['status']==='open'&&(int)$case['blind_review']===1,'Sponsor opens a pinned blind-review case on immutable submission revision 1.');

$blocked=false;try{sponsored_research_review_assign($pdo,$sponsor,(string)$case['public_id'],(string)$unapproved['username'],'independent');}catch(RuntimeException $e){$blocked=true;}p80s5($blocked,'Independent reviewers must hold approved Research Accounts.');
$selfBlocked=false;try{sponsored_research_review_assign($pdo,$sponsor,(string)$case['public_id'],(string)$researcher['username'],'independent');}catch(RuntimeException $e){$selfBlocked=true;}p80s5($selfBlocked,'Submitting researcher cannot review their own work.');

$assignment=sponsored_research_review_assign($pdo,$sponsor,(string)$case['public_id'],(string)$reviewer['username'],'independent');
$view=sponsored_research_review_assignment_for($pdo,$reviewer,(string)$case['public_id']);
p80s5($view&&$view['researcher_display_name']==='Anonymous Researcher'&&$view['researcher_user_id']===null,'Blind review masks researcher identity.');
$snap=json_decode((string)$view['snapshot_json'],true);p80s5(is_array($snap)&&!array_key_exists('researcher_user_id',$snap)&&!array_key_exists('participation_public_id',$snap),'Blind review strips researcher and participation identifiers from the reviewer snapshot.');

$premature=false;try{sponsored_research_review_decide($pdo,$sponsor,(string)$case['public_id'],'accepted','Looks good.');}catch(RuntimeException $e){$premature=str_contains($e->getMessage(),'reviewers');}p80s5($premature,'Sponsor cannot finalize while an assigned reviewer is still pending.');

sponsored_research_review_respond($pdo,$reviewer,(string)$case['public_id'],'request_revision',['evidence_strength'=>3,'completeness'=>2,'methodology'=>3],'Add a second source and address contradictory evidence.');
$case=sponsored_research_review_decide($pdo,$sponsor,(string)$case['public_id'],'revision_requested','Please add a second independent source.');
p80s5($case['status']==='revision_requested'&&(int)$case['compensation_eligible']===0,'Revision request is explicit and cannot trigger compensation.');
$submission=sponsored_research_submission_get($pdo,(string)$submitted['public_id']);p80s5($submission['status']==='revision_requested','Revision request reopens the same canonical submission for a new immutable revision.');

$pdo->prepare("UPDATE annotations SET text_commentary=? WHERE public_id=?")->execute(['Revised analysis with contradictory evidence '.$run,$annotationPublic]);
$draft2=sponsored_research_submission_draft($pdo,$researcher,(string)$campaign['public_id'],['title'=>'Revised review submission','summary'=>'Revised source-backed analysis.','methodology'=>'Expanded evidence review.','limitations'=>'Two-source scope.']);
$submitted2=sponsored_research_submission_submit($pdo,$researcher,(string)$draft2['public_id'],[['type'=>'annotation','public_id'=>$annotationPublic],['type'=>'source','public_id'=>$sourcePublic]]);
p80s5((int)$submitted2['current_revision']===2&&$submitted2['status']==='submitted','Researcher resubmits as immutable revision 2 without rewriting revision 1.');

$case2=sponsored_research_review_open($pdo,$sponsor,(string)$submitted2['public_id'],['criteria'=>['Evidence strength','Completeness','Methodology'],'blind_review'=>true]);
sponsored_research_review_assign($pdo,$sponsor,(string)$case2['public_id'],(string)$reviewer['username'],'independent');
sponsored_research_review_respond($pdo,$reviewer,(string)$case2['public_id'],'accept',['evidence_strength'=>5,'completeness'=>4,'methodology'=>4],'Revision addresses the requested changes.');
$case2=sponsored_research_review_decide($pdo,$sponsor,(string)$case2['public_id'],'accepted','Accepted after independent review.');
p80s5($case2['status']==='accepted'&&(int)$case2['compensation_eligible']===1,'Accepted immutable revision emits compensation eligibility for the next ledger section.');
$q=$pdo->prepare("SELECT COUNT(*) FROM data_provenance_edges WHERE from_type='sponsored_submission_version' AND from_public_id=? AND relationship='accepted_in' AND to_type='sponsored_campaign' AND to_public_id=?");$q->execute([(string)$case2['submission_version_public_id'],(string)$campaign['public_id']]);p80s5((int)$q->fetchColumn()===1,'Accepted research adds a provenance edge from exact submission version to Sponsored Campaign.');

$dispute=sponsored_research_dispute_open($pdo,$researcher,(string)$case2['public_id'],'Compensation scope is disputed even though research was accepted.',['note'=>'Preserve immutable decision evidence.']);
$held=sponsored_research_review_case_get($pdo,(string)$case2['public_id']);p80s5($held['status']==='disputed'&&(int)$held['compensation_eligible']===0,'Opening a dispute places accepted compensation eligibility on hold.');
$locked=false;try{sponsored_research_review_decide($pdo,$sponsor,(string)$case2['public_id'],'rejected','Override while disputed.');}catch(RuntimeException $e){$locked=true;}p80s5($locked,'Sponsor cannot rewrite a decision while the dispute is open.');

$resolved=sponsored_research_dispute_resolve($pdo,$admin,(string)$dispute['public_id'],'upheld','Original acceptance stands; compensation hold may clear.');
$final=sponsored_research_review_case_get($pdo,(string)$case2['public_id']);p80s5($resolved['status']==='resolved'&&$final['status']==='resolved'&&(int)$final['compensation_eligible']===1,'Admin resolution preserves dispute history and restores accepted compensation eligibility when upheld.');

$events=$pdo->prepare("SELECT event_type FROM sponsored_research_review_events WHERE review_case_id IN (?,?) ORDER BY id");$events->execute([(int)$case['id'],(int)$case2['id']]);$types=$events->fetchAll(PDO::FETCH_COLUMN);foreach(['review_opened','reviewer_assigned','review_response_submitted','final_decision','dispute_opened','dispute_resolved'] as $type)p80s5(in_array($type,$types,true),'Durable review ledger records '.$type.'.');

echo "Phase 80 Section 5 Review, Acceptance, Revision & Disputes database journey passed.\n";
