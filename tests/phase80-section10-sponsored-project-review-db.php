<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-tasks','research-programs','research-missions','research-accounts','sponsored-research-campaigns','sponsored-research-participation','data-attribution','data-datasets','sponsored-research-projects'] as $lib)require_once $root.'/app/'.$lib.'.php';
function ok10(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p80s10'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('SponsoredReviewAdmin','admin');$sponsor=$make('SponsoredReviewSponsor');$researcher=$make('SponsoredReviewResearcher');
$pkg=(int)$pdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status) VALUES(?,'personal',?,?,?,?, 'active',CURRENT_DATE,DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),'active')")->execute([$pub('acct'),'Sponsored Review Account',(int)$sponsor['id'],(int)$sponsor['id'],$pkg]);$acct=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$acct,(int)$sponsor['id']]);$q=$pdo->prepare('SELECT public_id FROM accounts WHERE id=?');$q->execute([$acct]);$acctPublic=(string)$q->fetchColumn();
sponsor_account_apply($pdo,$sponsor,['organization_name'=>'Sponsored Review Sponsor']);research_account_admin_decide($pdo,$admin,(int)$sponsor['id'],'sponsor_account','approved','Approved.','organization');
research_account_apply($pdo,$researcher,['specialties'=>'market research','languages'=>'English']);research_account_admin_decide($pdo,$admin,(int)$researcher['id'],'research_account','approved','Approved.','identity');
$campaign=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$acctPublic,'title'=>'Revision Project','brief'=>'Submit and revise a sponsored deliverable.','objective'=>'Verify immutable revision lineage.','questions'=>['What does the evidence support?'],'access_mode'=>'public','budget'=>'100.00','researcher_compensation'=>'25.00','eligibility'=>['min_verification'=>'identity'],'disclosures'=>['sponsorship_disclosure_required'=>false,'conflict_disclosure_required'=>false,'nda_required'=>false],'submission_deadline'=>'2030-12-31 23:59:00']);
$campaign=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$campaign['public_id'],'open');sponsored_research_campaign_terms_publish($pdo,$sponsor,(string)$campaign['public_id'],'Submit attributable reports.','Initial terms.');

$projectPublic=$pub('proj');$convPublic=$pub('conv');$agentPublic=$pub('agent');
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,description,status) VALUES(?,?,?,?,'active')")->execute([$projectPublic,(int)$researcher['id'],'Revision Agent Project','Sponsored revision test']);$projectId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,?)")->execute([$convPublic,(int)$researcher['id'],'Revision Research Agent']);$convId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$convId,(int)$researcher['id']]);
$pdo->prepare("INSERT INTO research_agents(public_id,owner_user_id,project_id,conversation_id,name,visibility,status,monitoring_cadence) VALUES(?,?,?,?,?,'private','active','manual')")->execute([$agentPublic,(int)$researcher['id'],$projectId,$convId,'Revision Research Agent']);$agentId=(int)$pdo->lastInsertId();
$assignment=sponsored_project_assign_agent($pdo,$researcher,(string)$campaign['public_id'],$agentPublic,['accept_terms'=>true]);

$project=project_access($pdo,(int)$researcher['id'],$projectPublic);
$doc1=research_agent_workspace_create_document($pdo,$researcher,$project,['title'=>'Initial Deliverable','body'=>'Initial sponsored research evidence.','summary'=>'Initial submission.'],true);
$first=sponsored_project_submit($pdo,$researcher,(string)$campaign['public_id'],[['type'=>'document','public_id'=>(string)$doc1['public_id']]],'Initial Sponsored Submission','Initial bundle.',true);
ok10((int)$first['revision_number']===1&&empty($first['supersedes_submission_id']),'Initial submission starts revision chain at 1.');

$under=sponsored_project_admin_status($pdo,$admin,(string)$first['public_id'],'under_review','Review started.');
ok10($under['status']==='under_review'&&!empty($under['reviewed_by_user_id']),'Admin review records reviewer identity.');
$missing=false;try{sponsored_project_admin_status($pdo,$admin,(string)$first['public_id'],'revision_requested','');}catch(InvalidArgumentException $e){$missing=true;}ok10($missing,'Revision request requires reviewer instructions.');
$requested=sponsored_project_admin_status($pdo,$admin,(string)$first['public_id'],'revision_requested','Add corroborating evidence and address the source conflict.');
ok10($requested['status']==='revision_requested'&&str_contains((string)$requested['review_note'],'corroborating'),'Revision request persists structured instructions.');

$agentContext=sponsored_project_agent_context($pdo,$agentId);
ok10(!empty($agentContext['requires_revision'])&&str_contains((string)$agentContext['review_note'],'source conflict'),'Research Agent context exposes pending revision and feedback.');

$doc2=research_agent_workspace_create_document($pdo,$researcher,$project,['title'=>'Revised Deliverable','body'=>'Revised sponsored evidence with corroboration.','summary'=>'Revision response.'],true);
$second=sponsored_project_submit($pdo,$researcher,(string)$campaign['public_id'],[['type'=>'document','public_id'=>(string)$doc2['public_id']]],'Revised Sponsored Submission','Revision bundle.',true,(string)$first['public_id']);
ok10((int)$second['revision_number']===2&&(string)$second['supersedes_public_id']===(string)$first['public_id'],'Resubmission creates immutable revision 2 linked to revision 1.');
$firstAgain=sponsored_project_submission_get($pdo,(string)$first['public_id']);ok10((string)$firstAgain['status']==='revision_requested'&&(string)$firstAgain['submission_hash']===(string)$first['submission_hash'],'Original reviewed submission remains immutable after resubmission.');
$dupe=false;try{sponsored_project_submit($pdo,$researcher,(string)$campaign['public_id'],[['type'=>'document','public_id'=>(string)$doc2['public_id']]],'Duplicate Revision','',true,(string)$first['public_id']);}catch(RuntimeException $e){$dupe=true;}ok10($dupe,'A revision request can only be answered once.');

$chain=sponsored_project_revision_chain($pdo,(string)$second['public_id']);ok10(count($chain)===2&&(int)$chain[0]['revision_number']===1&&(int)$chain[1]['revision_number']===2,'Revision chain returns complete immutable history.');
$accepted=sponsored_project_admin_status($pdo,$admin,(string)$second['public_id'],'accepted','Revision satisfies the project requirements.');
ok10($accepted['status']==='accepted','Admin accepts the revised submission.');
$q=$pdo->prepare('SELECT status,completed_submission_id,completed_at FROM sponsored_research_agent_assignments WHERE id=?');$q->execute([(int)$assignment['id']]);$done=$q->fetch();
ok10($done['status']==='completed'&&(int)$done['completed_submission_id']===(int)$second['id']&&!empty($done['completed_at']),'Acceptance completes the assignment against the exact accepted submission.');
$ctx=sponsored_project_agent_context($pdo,$agentId);ok10(!empty($ctx['completed'])&&!empty($ctx['completed_submission_id']),'Research Agent context exposes Sponsored Project completion.');

$q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND notification_type IN ('research_sponsored_under_review','research_sponsored_revision_requested','research_sponsored_accepted')");$q->execute([(int)$researcher['id']]);ok10((int)$q->fetchColumn()>=3,'Review lifecycle creates researcher Notifications/Activity records.');
$events=$pdo->prepare("SELECT event_type FROM sponsored_research_agent_events WHERE assignment_id=? ORDER BY id");$events->execute([(int)$assignment['id']]);$types=$events->fetchAll(PDO::FETCH_COLUMN);
ok10(in_array('project_submission_resubmitted',$types,true)&&in_array('project_submission_revision_requested',$types,true)&&in_array('project_submission_accepted',$types,true),'Durable event ledger records revision and completion transitions.');
echo "Phase 80 Section 10 Sponsored Project review/revision/completion database journey passed.\n";
