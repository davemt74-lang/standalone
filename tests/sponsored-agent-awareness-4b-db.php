<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-tasks','research-programs','research-missions','research-accounts','sponsored-project-builder','sponsored-research-campaigns','sponsored-research-participation','sponsored-research-submissions','sponsored-research-reviews','data-attribution','sponsored-research-finance','data-datasets','sponsored-research-projects','sponsored-research-project-compensation','sponsored-project-workspace','sponsored-agent-awareness'] as $lib)require_once $root.'/app/'.$lib.'.php';
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
$campaign=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$acctPublic,'title'=>'Paid Public Project','brief'=>'Research an open market question.','objective'=>'Produce an evidence-backed report.','questions'=>['What does the evidence support?'],'access_mode'=>'public','budget_currency'=>'USD','budget'=>'1000.00','researcher_compensation'=>'250.00','max_participants'=>5,'eligibility'=>['min_verification'=>'identity','specialties'=>['market research']],'disclosures'=>['sponsorship_disclosure_required'=>false,'conflict_disclosure_required'=>false,'nda_required'=>false],'project_specs'=>['target_audience'=>'Independent retailers','geography'=>'United States','scope_in'=>'Evidence-backed analysis','methods'=>['Desk research'],'milestones'=>[['title'=>'Initial synthesis','due_date'=>'2030-12-01','success_criteria'=>'Cite at least 3 sources']],'deliverables'=>[['title'=>'Evidence report','format'=>'report','acceptance_criteria'=>'Traceable citations','due_date'=>'2030-12-15']]],'submission_deadline'=>'2030-12-31 23:59:00']);
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

$assigned=sponsored_agent_awareness_access($pdo,$researcher,$campaignPublic,$agentPublic);
workspaceDbCheck($assigned!==null&&$assigned['assignment']['research_agent_public_id']===$agentPublic,
    'Only the exactly assigned personally owned Agent may receive this Sponsored Project context.');
workspaceDbCheck(sponsored_agent_awareness_access($pdo,$sponsor,$campaignPublic)===null,
    "Sponsor Account ownership cannot access a researcher's private Agent context.");
workspaceDbCheck(sponsored_agent_awareness_access($pdo,$researcher,'sample-market-ai-001')===null,
    'Sample Sponsored Projects never create a real Agent context.');
workspaceDbCheck(sponsored_agent_awareness_access($pdo,$researcher,$campaignPublic,'other-agent-id')===null,
    'An unrelated Research Agent cannot attach another Agent assignment.');
$options=sponsored_agent_awareness_project_options($pdo,$researcher,12);
workspaceDbCheck(count($options)===1&&$options[0]['type']==='sponsored_project'&&$options[0]['public_id']===$campaignPublic,
    'Native context picker returns only own accepted and assigned Sponsored Project.');
workspaceDbCheck(sponsored_agent_awareness_project_options($pdo,$sponsor)===[],
    "Sponsors do not receive a researcher's assigned Chat context options.");
$context=sponsored_agent_awareness_project_context($pdo,$researcher,$campaignPublic,$agentPublic);
workspaceDbCheck($context!==null&&$context['type']==='sponsored_project'
  &&str_contains($context['text'],'Independent retailers')
  &&str_contains($context['text'],'Traceable citations')
  &&str_contains($context['text'],'Your explicitly accepted terms:'),
  'Project brief, specs, deliverables, milestones and exactly accepted terms appear in a bounded Agent read model.');
workspaceDbCheck(($context['meta']['read_only']??false)===true
  &&($context['meta']['requires_reacceptance']??true)===false
  &&$context['meta']['accepted_terms_hash']!=='',
  'Agent context exposes provenance and revision flags without executable privileges.');
$agentRow=research_agent_access($pdo,$researcher,$agentPublic);
$auto=sponsored_agent_awareness_agent_context($pdo,$researcher,$agentRow);
workspaceDbCheck($auto!==null&&$auto['public_id']===$campaignPublic,'Personally owned assigned Agent automatically receives project awareness.');
$selected=agent_chat_context_item($pdo,$researcher,'sponsored_project',$campaignPublic);
workspaceDbCheck($selected!==null&&$selected['text']===$context['text'],'Existing Chat context selector resolves to the identical guarded adapter.');
$unknown=$make('UnassignedResearcher');
research_account_apply($pdo,$unknown,['specialties'=>'market research','languages'=>'English']);
research_account_admin_decide($pdo,$admin,(int)$unknown['id'],'research_account','approved','Approved.','identity');
workspaceDbCheck(sponsored_agent_awareness_access($pdo,$unknown,$campaignPublic)===null
  &&sponsored_agent_awareness_project_options($pdo,$unknown)===[],
    'Approved but unassigned Research Account cannot see private project details.');
$second=$make('AssignedSecondResearcher');
research_account_apply($pdo,$second,['specialties'=>'market research','languages'=>'English']);
research_account_admin_decide($pdo,$admin,(int)$second['id'],'research_account','approved','Approved.','identity');
$otherAgent=research_agent_create($pdo,$second,['name'=>'Other agent','description'=>'Own assignment','cadence'=>'manual','timezone_name'=>'UTC']);
$otherAssignment=sponsored_project_assign_agent($pdo,$second,$campaignPublic,(string)$otherAgent['public_id'],['accept_terms'=>true]);
$secondAccess=sponsored_workspace_access($pdo,$second,$campaignPublic);
$firstAccess=sponsored_workspace_access($pdo,$researcher,$campaignPublic);
sponsored_workspace_post($pdo,$firstAccess,$researcher,['scope'=>'participant','title'=>'First private finding','body'=>'FIRST_RESEARCHER_SECRET_DO_NOT_DISCLOSE']);
sponsored_workspace_post($pdo,$secondAccess,$second,['scope'=>'participant','title'=>'Second private finding','body'=>'SECOND_RESEARCHER_SECRET_DO_NOT_DISCLOSE']);
$context=sponsored_agent_awareness_project_context($pdo,$researcher,$campaignPublic,$agentPublic);
$otherContext=sponsored_agent_awareness_project_context($pdo,$second,$campaignPublic,(string)$otherAgent['public_id']);
workspaceDbCheck(str_contains($context['text'],'FIRST_RESEARCHER_SECRET_DO_NOT_DISCLOSE')
  &&!str_contains($context['text'],'SECOND_RESEARCHER_SECRET_DO_NOT_DISCLOSE')
  &&str_contains($otherContext['text'],'SECOND_RESEARCHER_SECRET_DO_NOT_DISCLOSE')
  &&!str_contains($otherContext['text'],'FIRST_RESEARCHER_SECRET_DO_NOT_DISCLOSE'),
  'Own private Sponsor Project journal is visible to the Agent while another participant’s thread is never leaked.');
$termsBefore=(string)$context['meta']['accepted_terms_hash'];
sponsored_research_campaign_terms_publish($pdo,$sponsor,$campaignPublic,'New sponsor terms that the researcher has NOT accepted.','New terms without approval.');
$termsStale=sponsored_agent_awareness_project_context($pdo,$researcher,$campaignPublic,$agentPublic);
workspaceDbCheck(($termsStale['meta']['requires_reacceptance']??false)
  &&$termsStale['meta']['accepted_terms_hash']===$termsBefore
  &&!str_contains($termsStale['text'],'New sponsor terms that the researcher has NOT accepted.'),
  'A newly published unaccepted terms version never replaces accepted terms in the Agent context.');
$pdo->prepare("UPDATE sponsored_research_campaigns SET current_revision=current_revision+1 WHERE id=?")->execute([(int)$campaign['id']]);
$stale=sponsored_agent_awareness_project_context($pdo,$researcher,$campaignPublic,$agentPublic);
workspaceDbCheck($stale!==null&&($stale['meta']['requires_reacceptance']??false)
  &&str_contains($stale['text'],'STALE'),
  'A newly edited campaign does not silently replace personally accepted terms or revision.');
$pdo->prepare("UPDATE sponsored_research_participations SET status='withdrawn' WHERE id=?")->execute([(int)$firstAccess['participation']['id']]);
workspaceDbCheck(sponsored_agent_awareness_access($pdo,$researcher,$campaignPublic)===null
  &&agent_chat_context_item($pdo,$researcher,'sponsored_project',$campaignPublic)===null,
  'Withdrawing participation immediately revokes manual and automatic Sponsored Project context.');
echo "Sponsored Research Agent Awareness 4B assignment, revision and cross-researcher database journey passed.\n";
