<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-tasks','research-programs','research-missions','research-accounts','sponsored-project-builder','sponsored-research-campaigns','sponsored-research-participation','sponsored-research-submissions','sponsored-research-reviews','data-attribution','sponsored-research-finance','data-datasets','sponsored-research-projects','sponsored-research-project-compensation','sponsored-project-workspace'] as $lib)require_once $root.'/app/'.$lib.'.php';
function workspaceDbCheck(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p80s11'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('CompAdmin','admin');$sponsor=$make('CompSponsor');$researcher=$make('CompResearcher');
$sampleSettings=sponsored_project_sample_settings($pdo);workspaceDbCheck((int)$sampleSettings['sample_data_enabled']===1,'Sample Sponsored Project data defaults on for Admin demo review.');
$sampleProjects=sponsored_project_sample_projects($pdo);workspaceDbCheck(count($sampleProjects)===3&&array_reduce($sampleProjects,fn($ok,$x)=>$ok&&!empty($x['sample_data']),true),'Admin receives three clearly marked non-operational sample projects.');
sponsored_project_sample_toggle($pdo,$admin,false);workspaceDbCheck(sponsored_project_sample_projects($pdo)===[],'Admin can turn sample project data off.');
sponsored_project_sample_toggle($pdo,$admin,true);workspaceDbCheck(count(sponsored_project_sample_projects($pdo))===3,'Admin can turn sample project data back on.');
$pkg=(int)$pdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status) VALUES(?,'personal',?,?,?,?, 'active',CURRENT_DATE,DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),'active')")->execute([$pub('acct'),'Comp Account',(int)$sponsor['id'],(int)$sponsor['id'],$pkg]);$acct=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$acct,(int)$sponsor['id']]);$q=$pdo->prepare('SELECT public_id FROM accounts WHERE id=?');$q->execute([$acct]);$acctPublic=(string)$q->fetchColumn();
sponsor_account_apply($pdo,$sponsor,['organization_name'=>'Public Research Sponsor']);research_account_admin_decide($pdo,$admin,(int)$sponsor['id'],'sponsor_account','approved','Approved.','organization');
research_account_apply($pdo,$researcher,['specialties'=>'market research','languages'=>'English']);research_account_admin_decide($pdo,$admin,(int)$researcher['id'],'research_account','approved','Approved.','identity');
$campaign=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$acctPublic,'title'=>'Paid Public Project','brief'=>'Research an open market question.','objective'=>'Produce an evidence-backed report.','questions'=>['What does the evidence support?'],'access_mode'=>'public','budget_currency'=>'USD','budget'=>'1000.00','researcher_compensation'=>'250.00','max_participants'=>5,'eligibility'=>['min_verification'=>'identity','specialties'=>['market research']],'disclosures'=>['sponsorship_disclosure_required'=>false,'conflict_disclosure_required'=>false,'nda_required'=>false],'submission_deadline'=>'2030-12-31 23:59:00']);
$campaign=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$campaign['public_id'],'open');sponsored_research_campaign_terms_publish($pdo,$sponsor,(string)$campaign['public_id'],'Complete the project through your assigned Research Agent.','Initial terms.');
$list=sponsored_project_public_list($pdo,'Paid Public',20);workspaceDbCheck(count(array_filter($list,fn($x)=>$x['public_id']===$campaign['public_id']))===1,'Public marketplace lists open priced public Sponsored Projects.');
$public=sponsored_project_public_get($pdo,(string)$campaign['public_id']);workspaceDbCheck($public!==null&&(int)$public['researcher_compensation_cents']===25000&&$public['organization_name']==='Public Research Sponsor','Public project detail exposes sponsor and advertised flat fee.');

$private=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$acctPublic,'title'=>'Private Paid Project','brief'=>'Private brief.','objective'=>'Private objective.','questions'=>['Private question?'],'access_mode'=>'private','budget'=>'500.00','researcher_compensation'=>'100.00','submission_deadline'=>'2030-12-31 23:59:00']);$private=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$private['public_id'],'open');
$unpriced=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$acctPublic,'title'=>'Unpriced Public Project','brief'=>'Unpriced brief.','objective'=>'Unpriced objective.','questions'=>['Unpriced question?'],'access_mode'=>'public','budget'=>'500.00','researcher_compensation'=>'0','submission_deadline'=>'2030-12-31 23:59:00']);$unpriced=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$unpriced['public_id'],'open');
$all=sponsored_project_public_list($pdo,'',100);$ids=array_column($all,'public_id');workspaceDbCheck(!in_array($private['public_id'],$ids,true)&&!in_array($unpriced['public_id'],$ids,true),'Public marketplace excludes private and unpriced projects.');

$projectPublic=$pub('proj');$convPublic=$pub('conv');$agentPublic=$pub('agent');
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,description,status) VALUES(?,?,?,?,'active')")->execute([$projectPublic,(int)$researcher['id'],'Comp Agent Project','Paid Sponsored Project']);$projectId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,?)")->execute([$convPublic,(int)$researcher['id'],'Comp Research Agent']);$convId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$convId,(int)$researcher['id']]);
$pdo->prepare("INSERT INTO research_agents(public_id,owner_user_id,project_id,conversation_id,name,visibility,status,monitoring_cadence) VALUES(?,?,?,?,?,'private','active','manual')")->execute([$agentPublic,(int)$researcher['id'],$projectId,$convId,'Comp Research Agent']);
$assignment=sponsored_project_assign_agent($pdo,$researcher,(string)$campaign['public_id'],$agentPublic,['accept_terms'=>true]);
$comp=sponsored_project_compensation_for_assignment($pdo,(int)$assignment['id']);workspaceDbCheck($comp!==null&&(int)$comp['amount_cents']===25000&&$comp['status']==='pending','Assignment freezes the advertised flat fee as pending compensation.');

workspaceDbCheck(sponsored_workspace_ready($pdo),'Migration 129 registers the governed Sponsored Project progress ledger.');
$campaignPublic=(string)$campaign['public_id'];
$accessSponsor=sponsored_workspace_access($pdo,$sponsor,$campaignPublic);
$accessResearcher=sponsored_workspace_access($pdo,$researcher,$campaignPublic);
workspaceDbCheck($accessSponsor!==null&&$accessSponsor['role']==='sponsor','Approved account manager receives sponsor workspace access.');
workspaceDbCheck($accessResearcher!==null&&$accessResearcher['role']==='researcher'
   &&(int)$accessResearcher['assignment']['participation_id']===(int)$accessResearcher['participation']['id'],
   'Researcher workspace is tied to exact accepted participation and original Agent assignment.');
$unknown=$make('WorkspaceStranger');
research_account_apply($pdo,$unknown,['specialties'=>'market research','languages'=>'English']);
research_account_admin_decide($pdo,$admin,(int)$unknown['id'],'research_account','approved','Approved.','identity');
workspaceDbCheck(sponsored_workspace_access($pdo,$unknown,$campaignPublic)===null,
  'An approved Research Account without an accepted assignment cannot open a project workspace.');
$demo=sponsored_workspace_access($pdo,[],'sample-market-ai-001');
workspaceDbCheck($demo!==null&&$demo['role']==='sample'&&count(sponsored_workspace_recent($pdo,$demo,[],10))===2,
  'Public guest can preview the isolated demonstration workspace while samples are enabled.');
$writeDemoBlocked=false;
try{sponsored_workspace_post($pdo,$demo,[],['scope'=>'project','title'=>'Fake real work','body'=>'Must fail']);}
catch(RuntimeException $e){$writeDemoBlocked=true;}
workspaceDbCheck($writeDemoBlocked,'Demo workspace cannot write real updates or tasks.');
sponsored_project_sample_toggle($pdo,$admin,false);
workspaceDbCheck(sponsored_workspace_access($pdo,[],'sample-market-ai-001')===null,'Disabling Admin sample data revokes direct sample workspace URL.');
sponsored_project_sample_toggle($pdo,$admin,true);
$second=$make('SecondResearcher');
research_account_apply($pdo,$second,['specialties'=>'market research','languages'=>'English']);
research_account_admin_decide($pdo,$admin,(int)$second['id'],'research_account','approved','Approved.','identity');
$otherAgent=research_agent_create($pdo,$second,[
 'name'=>'Second Sponsored Agent','description'=>'Participant isolation fixture',
 'cadence'=>'manual','timezone_name'=>'UTC'
]);
$secondAssignment=sponsored_project_assign_agent($pdo,$second,$campaignPublic,(string)$otherAgent['public_id'],['accept_terms'=>true]);
$secondAccess=sponsored_workspace_access($pdo,$second,$campaignPublic);
workspaceDbCheck($secondAccess!==null&&$secondAccess['role']==='researcher','Second approved assigned researcher receives only their own scoped workspace.');
$sponsorParticipants=sponsored_workspace_participants($pdo,$accessSponsor,$sponsor);
workspaceDbCheck(count($sponsorParticipants)===2,'Sponsor roster reuses two existing approved accepted Agent assignments.');
workspaceDbCheck(sponsored_workspace_participants($pdo,$accessResearcher,$researcher)===[],
 'Researcher cannot enumerate project participant roster.');
$broadcast=sponsored_workspace_post($pdo,$accessSponsor,$sponsor,[
  'scope'=>'project','progress_status'=>'started','title'=>'Scope confirmed','body'=>'Plan approved for all participants.'
]);
$private=sponsored_workspace_post($pdo,$accessResearcher,$researcher,[
  'scope'=>'participant','progress_status'=>'blocked','title'=>'Need a source','body'=>'Only my sponsor should see this.'
]);
$firstEntry=sponsored_workspace_post($pdo,$accessSponsor,$sponsor,[
  'scope'=>'participant','participant_public_id'=>(string)$accessResearcher['participation']['public_id'],
  'title'=>'Sponsor response','body'=>'Please use the evidence library.'
]);
workspaceDbCheck($broadcast['scope']==='project'&&$private['participant_user_id']===(int)$researcher['id']
   &&$firstEntry['participant_user_id']===(int)$researcher['id'],
   'Project-wide and private messages preserve exact target and author provenance.');
$forFirst=sponsored_workspace_recent($pdo,$accessResearcher,$researcher,30);
$forSecond=sponsored_workspace_recent($pdo,$secondAccess,$second,30);
$forSponsor=sponsored_workspace_recent($pdo,$accessSponsor,$sponsor,30);
$firstIds=array_column($forFirst,'public_id');$secondIds=array_column($forSecond,'public_id');$sponsorIds=array_column($forSponsor,'public_id');
workspaceDbCheck(in_array($broadcast['public_id'],$firstIds,true)&&in_array($broadcast['public_id'],$secondIds,true)
   &&in_array($broadcast['public_id'],$sponsorIds,true),'Project-wide sponsor progress is shared with all accepted assigned researchers.');
workspaceDbCheck(in_array($private['public_id'],$firstIds,true)&&in_array($firstEntry['public_id'],$firstIds,true)
   &&!in_array($private['public_id'],$secondIds,true)&&!in_array($firstEntry['public_id'],$secondIds,true)
   &&in_array($private['public_id'],$sponsorIds,true),'Participant journal is visible only to that participant and the sponsor.');
workspaceDbCheck(sponsored_workspace_submission_summaries($pdo,$accessResearcher,$researcher)===[],
    'Researcher submission list contains no fabricated or unrelated project work.');
$q=$pdo->prepare("SELECT actor_user_id,actor_role,scope,participant_user_id FROM sponsored_project_updates WHERE public_id=?");
$q->execute([(string)$private['public_id']]);$logged=$q->fetch();
workspaceDbCheck((int)$logged['actor_user_id']===(int)$researcher['id']&&$logged['actor_role']==='researcher'
    &&$logged['scope']==='participant','Append-only journal retains correct actor role, exact private scope and contributor identity.');
$q=$pdo->prepare("SELECT payload_json FROM sponsored_research_agent_events WHERE campaign_id=? AND event_type='sponsored_workspace_update' ORDER BY id DESC LIMIT 1");
$q->execute([(int)$campaign['id']]);$event=(string)$q->fetchColumn();
workspaceDbCheck($event!==''&&!str_contains($event,'Please use the evidence library.'),
   'Existing project event ledger receives only scoped activity metadata, not confidential message bodies.');
$malicious=false;
try{sponsored_workspace_post($pdo,$accessResearcher,$researcher,['scope'=>'project','title'=>'Leak','body'=>'Do not broadcast']);}
catch(RuntimeException $e){$malicious=true;}
workspaceDbCheck($malicious,'Researcher cannot escalate project-wide broadcast permissions.');
$pdo->prepare("UPDATE sponsored_research_participations SET status='withdrawn' WHERE id=?")->execute([(int)$accessResearcher['participation']['id']]);
workspaceDbCheck(sponsored_workspace_access($pdo,$researcher,$campaignPublic)===null,
   'Revoked participation immediately loses access to private project journal.');
$replayDenied=false;
try{sponsored_workspace_post($pdo,$accessResearcher,$researcher,['scope'=>'participant','title'=>'Stale','body'=>'Stale session post']);}
catch(RuntimeException $e){$replayDenied=true;}
workspaceDbCheck($replayDenied,'Mutation rechecks access and rejects a stale revoked researcher session.');
$closed=$accessSponsor;$closed['campaign']['status']='completed';
$closedRejected=false;
try{sponsored_workspace_validate_update($closed,$sponsor,['scope'=>'project','title'=>'Closed','body'=>'Too late']);}
catch(RuntimeException $e){$closedRejected=true;}
workspaceDbCheck($closedRejected,'Closed campaigns are read-only across the collaboration workspace.');
echo "Sponsored Project Workspace Section 3 database security, lifecycle and collaboration journey passed.\n";
