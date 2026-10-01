<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','subscriptions','account-admin','account-membership','research-accounts','sponsored-project-builder','sponsored-research-campaigns','sponsored-research-participation','sponsored-research-project-compensation','sponsored-project-detail'] as $lib)require_once $root.'/app/'.$lib.'.php';
function spBuilderCheck(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='spBuilderCheck'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('CampaignAdmin','admin');$sponsor=$make('ApprovedSponsor');$plain=$make('PlainUser');
$pkg=(int)$pdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status) VALUES(?, 'personal', ?,?,?,?,'active',CURRENT_DATE,DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),'active')")->execute([$pub('acct'),'Sponsor Account',(int)$sponsor['id'],(int)$sponsor['id'],$pkg]);$accountId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$accountId,(int)$sponsor['id']]);$q=$pdo->prepare('SELECT public_id FROM accounts WHERE id=?');$q->execute([$accountId]);$accountPublic=(string)$q->fetchColumn();
sponsor_account_apply($pdo,$sponsor,['organization_name'=>'Sponsor Lab']);research_account_admin_decide($pdo,$admin,(int)$sponsor['id'],'sponsor_account','approved','Approved sponsor.','organization');
$agent=research_agent_create($pdo,$sponsor,['name'=>'Sponsor Research Agent','description'=>'Campaign research authority','cadence'=>'manual','timezone_name'=>'UTC']);

$spec=['target_audience'=>'Independent neighborhood retail operators','geography'=>'United States',
 'scope_in'=>'Technology adoption decisions and workflow changes.','scope_out'=>'Do not purchase accounts.',
 'methods'=>['Desk research','Interviews'],
 'deliverables'=>[['title'=>'Evidence report','format'=>'report','acceptance_criteria'=>'Cite 10 independent sources.','due_date'=>'2030-12-01']],
 'milestones'=>[['title'=>'Research plan','due_date'=>'2030-11-10','success_criteria'=>'Methodology approved.'],
               ['title'=>'Interview summary','due_date'=>'2030-11-20','success_criteria'=>'Quotes traceable.']]
];
$normalized=sponsored_project_builder_normalize($spec,'2030-12-15 20:00:00');
spBuilderCheck(count($normalized['deliverables'])===1&&count($normalized['milestones'])===2,'Builder validates structured deliverables and planned milestones.');
$multi=sponsored_project_builder_normalize([
 'deliverables'=>"Market summary | report | At least 10 citations | 2030-12-01",
 'milestones'=>"2030-11-10 | Source audit | Verify sources\n2030-11-10 | Interview digest | Verify consent"
],'2030-12-15 20:00:00');
spBuilderCheck(count($multi['milestones'])===2&&$multi['milestones'][1]['due_date']==='2030-11-10','Builder accepts line-form submission and parallel same-day checkpoints.');
foreach([
 ['deliverables'=>[['title'=>'Bad','format'=>'executable','acceptance_criteria'=>'Must reject']]],
 ['deliverables'=>[['title'=>'Late','format'=>'report','acceptance_criteria'=>'Must reject','due_date'=>'2031-01-01']]],
 ['milestones'=>[['title'=>'Broken','due_date'=>'2030-02-30','success_criteria'=>'No']]],
 ['methods'=>['Unknown method']],
 ['scope_in'=>str_repeat('X',3001)]
] as $bad){
 $rejected=false;try{sponsored_project_builder_normalize($bad,'2030-12-15 20:00:00');}catch(InvalidArgumentException $e){$rejected=true;}
 spBuilderCheck($rejected,'Builder rejects invalid scope, method, deliverable or dates.');
}
$campaign=sponsored_research_campaign_create($pdo,$sponsor,[
 'account_id'=>$accountPublic,'research_agent_id'=>$agent['public_id'],
 'title'=>'Retail Technology Study','brief'=>'Independent retail technology decisions.','objective'=>'Publish source-backed buying analysis.',
 'questions'=>['What is the evidence?'],'access_mode'=>'public','budget_currency'=>'USD','budget'=>'3000.00',
 'researcher_compensation'=>'250.00','project_specs'=>$spec,'submission_deadline'=>'2030-12-15 20:00:00',
 'disclosures'=>['sponsorship_disclosure_required'=>false]
]);
$id=(string)$campaign['public_id'];
spBuilderCheck($campaign['status']==='draft'&&$campaign['project_specs']===$normalized,'Campaign create uses existing sponsor authority and canonical spec record.');
$versions=sponsored_research_campaign_versions($pdo,$sponsor,$id);
$config=json_decode((string)$versions[0]['config_json'],true,512,JSON_THROW_ON_ERROR);
spBuilderCheck($config['project_specs']===$normalized&&count($versions)===1,'Initial immutable campaign revision contains the exact normalized project specification.');
$before=$campaign['config_hash'];
$campaign=sponsored_research_campaign_update($pdo,$sponsor,$id,[
 'project_specs'=>array_merge($spec,['deliverables'=>array_merge($spec['deliverables'],[[
   'title'=>'Methods appendix','format'=>'research_document','acceptance_criteria'=>'List original search queries.','due_date'=>null
 ]])]),
 'reason'=>'Add auditable methods appendix.'
]);
spBuilderCheck((int)$campaign['current_revision']===2&&count($campaign['project_specs']['deliverables'])===2&&$campaign['config_hash']!==$before,
 'Builder amendments create new immutable campaign configuration hashes.');
$versions=sponsored_research_campaign_versions($pdo,$sponsor,$id);
$prior=json_decode((string)$versions[1]['config_json'],true,512,JSON_THROW_ON_ERROR);
spBuilderCheck(count($prior['project_specs']['deliverables'])===1,'Previous revision remains immutable after adding deliverables.');
$campaign=sponsored_research_campaign_update($pdo,$sponsor,$id,['brief'=>'Clarified background only.','reason'=>'Editorial brief change.']);
spBuilderCheck(count($campaign['project_specs']['deliverables'])===2,'Ordinary partial updates preserve existing Project Builder specifications.');
$rev=(int)$campaign['current_revision'];
$invalid=false;
try{sponsored_research_campaign_update($pdo,$sponsor,$id,['project_specs'=>['deliverables'=>'Broken | unknown | Reject me'],'reason'=>'Invalid test']);}
catch(InvalidArgumentException $e){$invalid=true;}
$again=sponsored_research_campaign_by_public($pdo,$id);
spBuilderCheck($invalid&&(int)$again['current_revision']===$rev&&count($again['project_specs']['deliverables'])===2,'Failed validation cannot mutate existing campaign or create a revision.');
$blocked=false;try{sponsored_research_campaign_update($pdo,$plain,$id,['project_specs'=>['target_audience'=>'Unauthorized']]);}
catch(Throwable $e){$blocked=true;}
spBuilderCheck($blocked&&(int)sponsored_research_campaign_by_public($pdo,$id)['current_revision']===$rev,'Unauthorized account cannot edit the Project Builder.');
$open=sponsored_research_campaign_set_status($pdo,$sponsor,$id,'open');
$guest=sponsored_project_detail_resolve($pdo,null,$id);
spBuilderCheck($open['status']==='open'&&$guest!==null&&$guest['role']==='visitor','Governed open public campaign remains visible in the existing public flow.');
spBuilderCheck(count($guest['project']['project_specs']['deliverables'])===2&&$guest['project']['project_specs']['target_audience']==='Independent neighborhood retail operators',
 'Public project page consumes the identical published specification from the existing campaign.');
spBuilderCheck($guest['project']['disclosures']['sponsorship_disclosure_required']===false,'Public detail reads actual disclosure policy instead of fabricating defaults.');
// Accepted researchers must never inherit silent, changed deliverable obligations.
research_account_apply($pdo,$plain,['specialties'=>'retail technology','languages'=>'English']);
research_account_admin_decide($pdo,$admin,(int)$plain['id'],'research_account','approved','Certified for test.','identity');
$terms=sponsored_research_campaign_terms_publish($pdo,$sponsor,$id,'Produce the reviewed research report.','Initial research terms.');
$accepted=sponsored_research_campaign_join($pdo,$plain,$id,['accept_terms'=>true,'conflict_disclosure'=>'None']);
spBuilderCheck($accepted['status']==='active','Approved researcher can explicitly accept published participation terms.');
$revBefore=(int)sponsored_research_campaign_by_public($pdo,$id)['current_revision'];
$blockedSpec=false;
try{sponsored_research_campaign_update($pdo,$sponsor,$id,[
 'project_specs'=>array_merge($spec,['deliverables'=>[['title'=>'Unagreed extra','format'=>'report','acceptance_criteria'=>'Unexpected new obligation']]]),
 'reason'=>'Attempt to silently change accepted obligations.',
]);}catch(RuntimeException $e){$blockedSpec=str_contains($e->getMessage(),'re-consent');}
spBuilderCheck($blockedSpec&&(int)sponsored_research_campaign_by_public($pdo,$id)['current_revision']===$revBefore,
 'Active accepted participation locks material Project Builder changes until an explicit re-consent workflow exists.');
$campaign=sponsored_research_campaign_update($pdo,$sponsor,$id,['access_mode'=>'private','reason'=>'Close public access.']);
spBuilderCheck(sponsored_project_detail_resolve($pdo,null,$id)===null,'Privacy changes immediately protect project specifications from guests.');
$sampleSettings=sponsored_project_sample_settings($pdo);
$sampleId='sample-market-ai-001';
if(!empty($sampleSettings['sample_data_enabled'])){
 $demo=sponsored_project_detail_resolve($pdo,null,$sampleId);
 spBuilderCheck($demo!==null&&$demo['role']==='sample'&&count($demo['project']['project_specs']['milestones'])===2,
    'Enabled samples show the same project-specification sections without creating campaigns.');
 sponsored_project_sample_toggle($pdo,$admin,false);
 spBuilderCheck(sponsored_project_detail_resolve($pdo,null,$sampleId)===null,'Admin OFF also removes directly addressable sample specs.');
 sponsored_project_sample_toggle($pdo,$admin,true);
}
echo "Sponsored Project Builder Section 2 integration and boundary journey passed.\n";
