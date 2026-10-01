<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-tasks','research-programs','research-missions','research-accounts','sponsored-research-campaigns','sponsored-research-participation','sponsored-research-submissions','sponsored-research-reviews','data-attribution','sponsored-research-finance','data-datasets','sponsored-research-projects','sponsored-research-project-compensation','sponsored-project-builder','sponsored-project-workspace','sponsored-agent-awareness','sponsored-agent-operations','sponsored-agent-proactive'] as $lib)require_once $root.'/app/'.$lib.'.php';
function sponsored4cCheck(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p80s11'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('CompAdmin','admin');$sponsor=$make('CompSponsor');$researcher=$make('CompResearcher');
$sampleSettings=sponsored_project_sample_settings($pdo);sponsored4cCheck((int)$sampleSettings['sample_data_enabled']===1,'Sample Sponsored Project data defaults on for Admin demo review.');
$sampleProjects=sponsored_project_sample_projects($pdo);sponsored4cCheck(count($sampleProjects)===3&&array_reduce($sampleProjects,fn($ok,$x)=>$ok&&!empty($x['sample_data']),true),'Admin receives three clearly marked non-operational sample projects.');
sponsored_project_sample_toggle($pdo,$admin,false);sponsored4cCheck(sponsored_project_sample_projects($pdo)===[],'Admin can turn sample project data off.');
sponsored_project_sample_toggle($pdo,$admin,true);sponsored4cCheck(count(sponsored_project_sample_projects($pdo))===3,'Admin can turn sample project data back on.');
$pkg=(int)$pdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status) VALUES(?,'personal',?,?,?,?, 'active',CURRENT_DATE,DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),'active')")->execute([$pub('acct'),'Comp Account',(int)$sponsor['id'],(int)$sponsor['id'],$pkg]);$acct=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$acct,(int)$sponsor['id']]);$q=$pdo->prepare('SELECT public_id FROM accounts WHERE id=?');$q->execute([$acct]);$acctPublic=(string)$q->fetchColumn();
sponsor_account_apply($pdo,$sponsor,['organization_name'=>'Public Research Sponsor']);research_account_admin_decide($pdo,$admin,(int)$sponsor['id'],'sponsor_account','approved','Approved.','organization');
research_account_apply($pdo,$researcher,['specialties'=>'market research','languages'=>'English']);research_account_admin_decide($pdo,$admin,(int)$researcher['id'],'research_account','approved','Approved.','identity');
$campaign=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$acctPublic,'title'=>'Paid Public Project','brief'=>'Research an open market question.','objective'=>'Produce an evidence-backed report.','questions'=>['What does the evidence support?'],'access_mode'=>'public','budget_currency'=>'USD','budget'=>'1000.00','researcher_compensation'=>'250.00','max_participants'=>5,'eligibility'=>['min_verification'=>'identity','specialties'=>['market research']],'disclosures'=>['sponsorship_disclosure_required'=>false,'conflict_disclosure_required'=>false,'nda_required'=>false],'submission_deadline'=>'2030-12-31 23:59:00']);
$campaign=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$campaign['public_id'],'open');sponsored_research_campaign_terms_publish($pdo,$sponsor,(string)$campaign['public_id'],'Complete the project through your assigned Research Agent.','Initial terms.');
$list=sponsored_project_public_list($pdo,'Paid Public',20);sponsored4cCheck(count(array_filter($list,fn($x)=>$x['public_id']===$campaign['public_id']))===1,'Public marketplace lists open priced public Sponsored Projects.');
$public=sponsored_project_public_get($pdo,(string)$campaign['public_id']);sponsored4cCheck($public!==null&&(int)$public['researcher_compensation_cents']===25000&&$public['organization_name']==='Public Research Sponsor','Public project detail exposes sponsor and advertised flat fee.');

$private=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$acctPublic,'title'=>'Private Paid Project','brief'=>'Private brief.','objective'=>'Private objective.','questions'=>['Private question?'],'access_mode'=>'private','budget'=>'500.00','researcher_compensation'=>'100.00','submission_deadline'=>'2030-12-31 23:59:00']);$private=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$private['public_id'],'open');
$unpriced=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$acctPublic,'title'=>'Unpriced Public Project','brief'=>'Unpriced brief.','objective'=>'Unpriced objective.','questions'=>['Unpriced question?'],'access_mode'=>'public','budget'=>'500.00','researcher_compensation'=>'0','submission_deadline'=>'2030-12-31 23:59:00']);$unpriced=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$unpriced['public_id'],'open');
$all=sponsored_project_public_list($pdo,'',100);$ids=array_column($all,'public_id');sponsored4cCheck(!in_array($private['public_id'],$ids,true)&&!in_array($unpriced['public_id'],$ids,true),'Public marketplace excludes private and unpriced projects.');

$projectPublic=$pub('proj');$convPublic=$pub('conv');$agentPublic=$pub('agent');
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,description,status) VALUES(?,?,?,?,'active')")->execute([$projectPublic,(int)$researcher['id'],'Comp Agent Project','Paid Sponsored Project']);$projectId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,?)")->execute([$convPublic,(int)$researcher['id'],'Comp Research Agent']);$convId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$convId,(int)$researcher['id']]);
$pdo->prepare("INSERT INTO research_agents(public_id,owner_user_id,project_id,conversation_id,name,visibility,status,monitoring_cadence) VALUES(?,?,?,?,?,'private','active','manual')")->execute([$agentPublic,(int)$researcher['id'],$projectId,$convId,'Comp Research Agent']);
$assignment=sponsored_project_assign_agent($pdo,$researcher,(string)$campaign['public_id'],$agentPublic,['accept_terms'=>true]);
$comp=sponsored_project_compensation_for_assignment($pdo,(int)$assignment['id']);sponsored4cCheck($comp!==null&&(int)$comp['amount_cents']===25000&&$comp['status']==='pending','Assignment freezes the advertised flat fee as pending compensation.');

$project=project_access($pdo,(int)$researcher['id'],$projectPublic);
$context=sponsored_agent_awareness_project_context($pdo,$researcher,(string)$campaign['public_id'],$agentPublic);
sponsored4cCheck($context!==null&&$context['meta']['read_only']===true,'Approved exact Agent receives consent-bound read-only Sponsored Project context.');
$conversation=agent_chat_access($pdo,$researcher,$convPublic);
sponsored4cCheck($conversation!==null,'Existing private Agent conversation is available.');
$makeProposal=function(string $cap,array $args,array $refs)use($pdo,$researcher,$conversation,&$context,$projectPublic):array{
    $msg=conversation_message_create($pdo,$researcher,(string)$conversation['public_id'],'Prepare governed Sponsor action.',null,null);
    $assistant=agent_chat_insert_agent_message($pdo,$conversation,'Prepared a proposal requiring your explicit confirmation.',(int)$msg['id']);
    return agent_action_create_proposals($pdo,$researcher,$conversation,(int)$assistant['id'],[$context],
        [['capability'=>$cap,'project_id'=>$projectPublic,'arguments'=>$args]],$refs);
};
$base=['campaign_id'=>(string)$campaign['public_id'],'campaign_revision'=>$context['meta']['accepted_revision'],
    'accepted_terms_hash'=>$context['meta']['accepted_terms_hash']];
$args=$base+['title'=>'Private synthesis checkpoint','body'=>'Verified source outline is ready.',
    'progress_status'=>'ready_for_review','milestone_position'=>''];
$before=(int)$pdo->query('SELECT COUNT(*) FROM sponsored_project_updates')->fetchColumn();
$proposal=$makeProposal('sponsored.project.post_update',$args,$context['refs']);
sponsored4cCheck(count($proposal)===1&&$proposal[0]['status']==='pending',
    'Agent proposal is pending and is not executed until researcher confirms.');
sponsored4cCheck((int)$pdo->query('SELECT COUNT(*) FROM sponsored_project_updates')->fetchColumn()===$before,
    'A proposed Sponsored Project update creates no journal entry before confirmation.');
$done=agent_action_confirm_execute($pdo,$researcher,(string)$proposal[0]['public_id']);
sponsored4cCheck($done['status']==='executed'&&$done['result']['type']==='sponsored_project_update',
    'Explicit researcher confirmation writes via the existing progress ledger.');
$dedupe=agent_action_confirm_execute($pdo,$researcher,(string)$proposal[0]['public_id']);
sponsored4cCheck($dedupe['deduplicated']===true
    &&(int)$pdo->query('SELECT COUNT(*) FROM sponsored_project_updates')->fetchColumn()===$before+1,
    'Duplicate confirmation is idempotent and never posts twice.');
$updateId=(string)$done['result']['public_id'];
$q=$pdo->prepare('SELECT actor_user_id,scope,participant_user_id,body FROM sponsored_project_updates WHERE public_id=?');
$q->execute([$updateId]);$entry=$q->fetch();
sponsored4cCheck((int)$entry['actor_user_id']===(int)$researcher['id']
   &&(int)$entry['participant_user_id']===(int)$researcher['id']
   &&$entry['scope']==='participant'&&$entry['body']==='Verified source outline is ready.',
   'Canonical journal retains true confirmed researcher author and private scope.');
$reject=$makeProposal('sponsored.project.post_update',array_merge($args,
    ['title'=>'Unauthorized shared announcement','scope'=>'project']),$context['refs']);
sponsored4cCheck(count($reject)===1&&$reject[0]['arguments']['scope']==='participant',
    'Submitted Agent args cannot escalate a researcher update into project-wide communication.');
$untrusted=$makeProposal('sponsored.project.post_update',array_merge($args,
    ['title'=>'Fabricated project','campaign_id'=>'fake-sponsor-project']),$context['refs']);
sponsored4cCheck($untrusted===[],'A fabricated Sponsored Project ID has no proposal even with valid Research project access.');
$revoked=$makeProposal('sponsored.project.post_update',array_merge($args,['title'=>'Now stale']),$context['refs']);
sponsored4cCheck(count($revoked)===1,'Active accepted project can create another proposal before terms change.');
sponsored_research_campaign_terms_publish($pdo,$sponsor,(string)$campaign['public_id'],
    'Updated terms that were not accepted for this Agent proposal.','Test stale approval.');
$stale=false;
try{agent_action_confirm_execute($pdo,$researcher,(string)$revoked[0]['public_id']);}
catch(AgentActionStale $e){$stale=true;}
sponsored4cCheck($stale&&(int)$pdo->query('SELECT COUNT(*) FROM sponsored_project_updates')->fetchColumn()===$before+1,
   'A changed terms hash blocks already-proposed writes before any external state mutation.');
sponsored_project_assign_agent($pdo,$researcher,(string)$campaign['public_id'],$agentPublic,['accept_terms'=>true]);
$context=sponsored_agent_awareness_project_context($pdo,$researcher,(string)$campaign['public_id'],$agentPublic);
$base=['campaign_id'=>(string)$campaign['public_id'],'campaign_revision'=>$context['meta']['accepted_revision'],
    'accepted_terms_hash'=>$context['meta']['accepted_terms_hash']];
$doc=research_agent_workspace_create_document($pdo,$researcher,$project,[
    'title'=>'Approved sponsored research document','body'=>'Verified research findings with source provenance.',
    'summary'=>'Evidence-backed conclusions.'],true);
$submissionArgs=$base+['title'=>'Agent-assisted research submission','summary'=>'Prepared from approved evidence.',
  'assets'=>[['type'=>'document','public_id'=>(string)$doc['public_id']]],'supersedes_public_id'=>''];
$missingEvidence=$makeProposal('sponsored.project.submit',$submissionArgs,$context['refs']);
sponsored4cCheck($missingEvidence===[],'Fabricated or unattached report/doc IDs cannot be proposed for project submission.');
$refs=array_merge($context['refs'],[['type'=>'document','id'=>(string)$doc['public_id']]]);
$validated=sponsored_agent_operation_validate($pdo,$researcher,$project,'sponsored.project.submit',
    sponsored_agent_operation_clean('sponsored.project.submit',$submissionArgs),$refs);
if(!$validated){
    $preflight=sponsored_agent_operation_access($pdo,$researcher,$project,sponsored_agent_operation_clean('sponsored.project.submit',$submissionArgs));
    fwrite(STDERR,"Diagnostic: approved source proposal preflight=".($preflight?'authorized':'denied').
      " submit_enabled=".(int)($preflight['assignment']['agent_submit_enabled']??0).
      " reference_count=".count($refs)."\n");
}
$submissionProposal=$makeProposal('sponsored.project.submit',$submissionArgs,$refs);
sponsored4cCheck(count($submissionProposal)===1&&$submissionProposal[0]['status']==='pending',
   'Only cited existing evidence creates an explicitly confirmable Sponsored Project submission.');
$submissionResult=agent_action_confirm_execute($pdo,$researcher,(string)$submissionProposal[0]['public_id']);
sponsored4cCheck($submissionResult['status']==='executed'
   &&$submissionResult['result']['type']==='sponsored_project_submission',
   'Confirmed Agent submission uses canonical immutable submission pipeline without a nested transaction.');
$saved=sponsored_project_submission_get($pdo,(string)$submissionResult['result']['public_id']);
sponsored4cCheck($saved!==null&&$saved['status']==='submitted'
   &&count($saved['assets'])===1&&$saved['assets'][0]['asset_type']==='document',
   'Submission preserves original evidence snapshots and existing human sponsor-review status.');
$again=agent_action_confirm_execute($pdo,$researcher,(string)$submissionProposal[0]['public_id']);
sponsored4cCheck($again['deduplicated']===true&&$again['result']['public_id']===$saved['public_id'],
   'Repeated submission confirmation cannot generate a duplicate evidence package.');
$fee=sponsored_project_compensation_for_assignment($pdo,(int)$assignment['id']);
sponsored4cCheck($fee['status']==='pending','Agent confirmation cannot accept the work or change sponsor payment obligations.');


$due=new DateTimeImmutable('2030-12-29 23:59:00',new DateTimeZone('UTC'));
$first=sponsored_agent_proactive_scan($pdo,60,$due);
sponsored4cCheck($first['deadlines']===1&&$first['blockers']===0&&$first['revisions']===0,
    'Only the currently assigned, accepted Research Account receives the 72h deadline alert.');
$again=sponsored_agent_proactive_scan($pdo,60,$due);
sponsored4cCheck($again['deadlines']===0,'An identical scheduled run never duplicates the 72h deadline.');
$soon=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('2030-12-31 09:59:00',new DateTimeZone('UTC')));
sponsored4cCheck($soon['deadlines']===1,'Separate 24h countdown warning occurs once as the actual deadline approaches.');
$q=$pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND notification_type='research_sponsored_deadline' ORDER BY id ASC LIMIT 1");
$q->execute([(int)$researcher['id']]);$deadlineNotice=$q->fetch();
sponsored4cCheck($deadlineNotice!==false&&notification_object_access($pdo,$researcher,$deadlineNotice)
    &&str_contains((string)notification_url($pdo,$researcher,$deadlineNotice),'#milestones'),
    'Deadline alert is readable only via the current exact assignment and links to the existing project milestone rail.');
$researcherAccess=sponsored_workspace_access($pdo,$researcher,(string)$campaign['public_id']);
$block=sponsored_workspace_post($pdo,$researcherAccess,$researcher,[
    'scope'=>'participant','title'=>'Source permission delay',
    'body'=>'PRIVATE_EVIDENCE_NOT_IN_NOTIFICATION_BODY','progress_status'=>'blocked'
]);
$active=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('now',new DateTimeZone('UTC')));
sponsored4cCheck($active['blockers']===1,'A new unresolved private-thread blocker notifies its authorized Sponsor Account manager.');
$q=$pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND notification_type='research_sponsored_blocker' ORDER BY id DESC LIMIT 1");
$q->execute([(int)$sponsor['id']]);$blockNotice=$q->fetch();
sponsored4cCheck($blockNotice!==false
    &&!str_contains((string)$blockNotice['body'],'PRIVATE_EVIDENCE_NOT_IN_NOTIFICATION_BODY')
    &&notification_object_access($pdo,$sponsor,$blockNotice)
    &&!notification_object_access($pdo,$researcher,$blockNotice)
    &&str_contains((string)notification_url($pdo,$sponsor,$blockNotice),'#activity')
    &&notification_url($pdo,$researcher,$blockNotice)===null,
    'Blocker notification contains no researcher's private text and resolves only for authorized sponsor managers.');
$repeat=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('now',new DateTimeZone('UTC')));
sponsored4cCheck($repeat['blockers']===0,'Hourly blocker scans use a stable update-and-recipient deduplication key.');
sponsored_workspace_post($pdo,$researcherAccess,$researcher,[
    'scope'=>'participant','title'=>'Blocker resolved','body'=>'Verified source access obtained.',
    'progress_status'=>'ready_for_review'
]);
$resolved=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('now',new DateTimeZone('UTC')));
sponsored4cCheck($resolved['blockers']===0,'Later ready-for-review progress suppresses obsolete blocker signals.');
sponsored_project_admin_status($pdo,$admin,(string)$saved['public_id'],'revision_requested',
    'Add a second independent evidence source.');
$reviewKey='sponsored-review:'.$saved['public_id'].':revision_requested';
$pdo->prepare('DELETE FROM notifications WHERE user_id=? AND dedupe_key=?')
    ->execute([(int)$researcher['id'],$reviewKey]);
$repair=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('now',new DateTimeZone('UTC')));
sponsored4cCheck($repair['revisions']===1,'Missing review notification is recovered under the exact canonical existing notification key.');
$repairAgain=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('now',new DateTimeZone('UTC')));
sponsored4cCheck($repairAgain['revisions']===0,'Revision recovery is idempotent and never creates a parallel review event.');
$pdo->prepare("UPDATE sponsored_research_participations SET status='withdrawn' WHERE id=?")
    ->execute([(int)$researcherAccess['participation']['id']]);
sponsored4cCheck(!notification_object_access($pdo,$researcher,$deadlineNotice)
    &&notification_url($pdo,$researcher,$deadlineNotice)===null,
    'Revocation hides already-delivered deadline notifications and their deep links.');
$revoked=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('2030-12-31 09:59:00',new DateTimeZone('UTC')));
sponsored4cCheck($revoked['deadlines']===0,'Revoked research assignment cannot receive another countdown alert.');
echo "Sponsored Research 4D deadline, blocker, recovered revision and revoked-notification DB journey passed.\n";
