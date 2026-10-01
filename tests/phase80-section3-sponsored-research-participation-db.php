<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-accounts','sponsored-research-campaigns','sponsored-research-participation','data-attribution'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p80s3(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p80s3'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('ParticipationAdmin','admin');$sponsor=$make('SponsorOwner');$researcher=$make('QualifiedResearcher');$unqualified=$make('UnqualifiedResearcher');
$pkg=(int)$pdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status) VALUES(?, 'personal', ?,?,?,?,'active',CURRENT_DATE,DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),'active')")->execute([$pub('acct'),'Sponsor Research Account',(int)$sponsor['id'],(int)$sponsor['id'],$pkg]);$accountId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$accountId,(int)$sponsor['id']]);$q=$pdo->prepare('SELECT public_id FROM accounts WHERE id=?');$q->execute([$accountId]);$accountPublic=(string)$q->fetchColumn();

sponsor_account_apply($pdo,$sponsor,['organization_name'=>'Research Sponsor']);research_account_admin_decide($pdo,$admin,(int)$sponsor['id'],'sponsor_account','approved','Approved sponsor.','organization');
research_account_apply($pdo,$researcher,['specialties'=>'energy, market research','languages'=>'English','biography'=>'Qualified sponsored researcher.']);research_account_admin_decide($pdo,$admin,(int)$researcher['id'],'research_account','approved','Qualified researcher.','identity');
research_account_apply($pdo,$unqualified,['specialties'=>'software','languages'=>'English']);research_account_admin_decide($pdo,$admin,(int)$unqualified['id'],'research_account','approved','Approved but not specialty-qualified.','identity');

$campaign=sponsored_research_campaign_create($pdo,$sponsor,[
 'account_id'=>$accountPublic,'title'=>'Battery Supply Research','brief'=>'Research current battery supply risks.','objective'=>'Produce source-backed risk findings.','questions'=>["What changed?","What evidence contradicts the change?"],'access_mode'=>'invite_only','budget'=>'1500.00','max_participants'=>1,
 'eligibility'=>['min_verification'=>'identity','specialties'=>['energy','market research'],'languages'=>['English'],'min_completed_campaigns'=>0],
 'disclosures'=>['sponsorship_disclosure_required'=>true,'conflict_disclosure_required'=>true,'nda_required'=>true,'ai_assistance_policy'=>'allowed_with_disclosure','training_use_request'=>'optional_separate_consent'],
 'submission_deadline'=>'2030-10-20 17:00:00'
]);
$campaign=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$campaign['public_id'],'open');
$terms=sponsored_research_campaign_terms_publish($pdo,$sponsor,(string)$campaign['public_id'],'Contributors must provide source-backed work, disclose conflicts, and comply with the NDA.','Initial campaign participation terms.');
p80s3((int)$terms['campaign_revision']===(int)$campaign['current_revision'],'Terms are pinned to the exact current campaign revision.');

$invite=sponsored_research_campaign_invite($pdo,$sponsor,(string)$campaign['public_id'],(string)$researcher['username'],'2030-10-10 12:00:00');
p80s3($invite['status']==='pending','Approved Research Account can receive an invite.');
$elig=sponsored_research_campaign_eligibility_check($pdo,$researcher,sponsored_research_campaign_by_public($pdo,(string)$campaign['public_id']));
p80s3(!empty($elig['eligible'])&&$elig['verification_level']==='identity','Eligibility engine combines Research Account verification, profile skills, language and invite access.');

sponsored_research_campaign_invite($pdo,$sponsor,(string)$campaign['public_id'],(string)$unqualified['username'],'2030-10-10 12:00:00');
$bad=sponsored_research_campaign_eligibility_check($pdo,$unqualified,sponsored_research_campaign_by_public($pdo,(string)$campaign['public_id']));
p80s3(empty($bad['eligible'])&&in_array('specialty:energy',$bad['reasons'],true),'Eligibility explains missing required specialties.');

$joined=sponsored_research_campaign_join($pdo,$researcher,(string)$campaign['public_id'],['accept_terms'=>true,'conflict_disclosure'=>'No relevant financial conflict.','accept_nda'=>true,'acknowledge_sponsorship'=>true]);
p80s3($joined['status']==='active'&&(int)$joined['terms_version']===(int)$terms['version_number'],'Joining freezes the accepted terms version and activates participation.');
p80s3((int)$joined['campaign_revision_accepted']===(int)$campaign['current_revision'],'Participation freezes the accepted campaign revision.');
$pref=data_contributor_preferences($pdo,(int)$researcher['id']);p80s3((int)$pref['allow_training']===0&&(int)$pref['allow_evaluation']===0,'Campaign participation does not silently grant training or evaluation consent.');
$q=$pdo->prepare("SELECT status FROM sponsored_research_campaign_invites WHERE id=?");$q->execute([(int)$invite['id']);p80s3($q->fetchColumn()==='accepted','Joining records invite acceptance.');

$limitBlocked=false;try{sponsored_research_campaign_join($pdo,$unqualified,(string)$campaign['public_id'],['accept_terms'=>true,'conflict_disclosure'=>'None','accept_nda'=>true,'acknowledge_sponsorship'=>true]);}catch(RuntimeException $e){$limitBlocked=true;}p80s3($limitBlocked,'Ineligible or full campaigns fail closed.');

$withdrawn=sponsored_research_campaign_withdraw($pdo,$researcher,(string)$campaign['public_id'],'Researcher withdrew voluntarily.');p80s3($withdrawn['status']==='withdrawn'&&!empty($withdrawn['withdrawn_at']),'Researcher withdrawal is explicit and retained.');
$events=$pdo->prepare("SELECT event_type FROM sponsored_research_participation_events WHERE campaign_id=? ORDER BY id");$events->execute([(int)$campaign['id']]);$eventTypes=$events->fetchAll(PDO::FETCH_COLUMN);p80s3(in_array('terms_published',$eventTypes,true)&&in_array('researcher_invited',$eventTypes,true)&&in_array('participation_joined',$eventTypes,true)&&in_array('participation_withdrawn',$eventTypes,true),'Terms, invitation, join and withdrawal all emit durable participation events.');

$campaign=sponsored_research_campaign_update($pdo,$sponsor,(string)$campaign['public_id'],['brief'=>'Updated battery supply research brief.','reason'=>'New sponsor scope.']);
$staleBlocked=false;try{sponsored_research_campaign_join($pdo,$researcher,(string)$campaign['public_id'],['accept_terms'=>true,'conflict_disclosure'=>'None','accept_nda'=>true,'acknowledge_sponsorship'=>true]);}catch(RuntimeException $e){$staleBlocked=str_contains($e->getMessage(),'republished');}p80s3($staleBlocked,'Campaign revisions invalidate stale participation terms until explicitly republished.');
echo "Phase 80 Section 3 Campaign Participation, Eligibility & Terms database journey passed.\n";
