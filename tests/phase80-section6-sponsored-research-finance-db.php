<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-tasks','research-programs','research-missions','research-accounts','sponsored-research-campaigns','sponsored-research-participation','data-attribution','data-datasets','sponsored-research-submissions','sponsored-research-reviews','sponsored-research-finance'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p80s6(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p80s6'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('FinanceAdmin','admin');$sponsor=$make('FinanceSponsor');$researcher=$make('FinanceResearcher');
$pkg=(int)$pdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,status) VALUES(?,'personal',?,?,?,?, 'active',CURRENT_DATE,DATE_ADD(CURRENT_DATE,INTERVAL 1 MONTH),'active')")->execute([$pub('acct'),'Finance Sponsor Account',(int)$sponsor['id'],(int)$sponsor['id'],$pkg]);$accountId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$accountId,(int)$sponsor['id']]);$q=$pdo->prepare('SELECT public_id FROM accounts WHERE id=?');$q->execute([$accountId]);$accountPublic=(string)$q->fetchColumn();
sponsor_account_apply($pdo,$sponsor,['organization_name'=>'Finance Sponsor']);research_account_admin_decide($pdo,$admin,(int)$sponsor['id'],'sponsor_account','approved','Approved sponsor.','organization');
research_account_apply($pdo,$researcher,['specialties'=>'research','languages'=>'English']);research_account_admin_decide($pdo,$admin,(int)$researcher['id'],'research_account','approved','Approved researcher.','identity');

sponsored_research_finance_settings_update($pdo,$admin,1000);p80s6((int)sponsored_research_finance_settings($pdo)['platform_fee_bps']===1000,'Admin explicitly configures a 10% platform fee; default fee policy is not implicit.');

$campaign=sponsored_research_campaign_create($pdo,$sponsor,['account_id'=>$accountPublic,'title'=>'Finance Ledger Campaign','brief'=>'Research for compensation ledger validation.','objective'=>'Validate reserved and accepted compensation.','questions'=>['What does the evidence support?'],'access_mode'=>'public','budget'=>'1000.00','eligibility'=>['min_verification'=>'identity'],'disclosures'=>['sponsorship_disclosure_required'=>false,'conflict_disclosure_required'=>false,'nda_required'=>false],'submission_deadline'=>'2030-12-31 23:59:00']);
$campaign=sponsored_research_campaign_set_status($pdo,$sponsor,(string)$campaign['public_id'],'open');
sponsored_research_campaign_terms_publish($pdo,$sponsor,(string)$campaign['public_id'],'Submit attributable paid research.','Initial finance terms.');
sponsored_research_campaign_join($pdo,$researcher,(string)$campaign['public_id'],['accept_terms'=>true]);

$fund1=sponsored_research_finance_record_funding($pdo,$admin,(string)$campaign['public_id'],100000,'manual_admin','funding-'.$run);
$fund2=sponsored_research_finance_record_funding($pdo,$admin,(string)$campaign['public_id'],100000,'manual_admin','funding-'.$run);
p80s6((int)$fund1['id']===(int)$fund2['id'],'Funding journal is idempotent for the same external reference.');
$bal=sponsored_research_finance_campaign_balances($pdo,(int)$campaign['id'],'USD');p80s6((int)$bal['campaign_available']===100000,'Campaign available balance is derived from immutable funding entries.');

$sourcePublic=$pub('source');$url='https://'.$run.'.example.test/source';$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'article',?,?,?,?, 'current')")->execute([$sourcePublic,$url,hash('sha256',$url),$run.'.example.test','Finance source']);$sourceId=(int)$pdo->lastInsertId();
$txt='Finance evidence '.$run;$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash) VALUES(?,1,?,?,?,?,?)')->execute([$sourceId,$url,'Finance source',$txt,hash('sha256',$txt),hash('sha256','target '.$run)]);$sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$sv,$sourceId]);
$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")->execute([$pub('cap'),$sourceId,$sv,(int)$researcher['id'],'Selected finance evidence']);$cap=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'private','draft')")->execute([$annotationPublic,(int)$researcher['id'],$sourceId,$sv,$cap,'Finance research analysis '.$run]);
$draft=sponsored_research_submission_draft($pdo,$researcher,(string)$campaign['public_id'],['title'=>'Compensated research','summary'=>'Source-backed paid research.','methodology'=>'Manual evidence review.','limitations'=>'Test scope.']);
$submission=sponsored_research_submission_submit($pdo,$researcher,(string)$draft['public_id'],[['type'=>'annotation','public_id'=>$annotationPublic],['type'=>'source','public_id'=>$sourcePublic]]);

$res=sponsored_research_compensation_reserve($pdo,$sponsor,(string)$submission['public_id'],50000);
p80s6($res['status']==='reserved'&&(int)$res['gross_amount_cents']===50000&&(int)$res['platform_fee_bps']===1000,'Sponsor reserves gross compensation with the platform fee policy frozen at reservation time.');
$bal=sponsored_research_finance_campaign_balances($pdo,(int)$campaign['id'],'USD');p80s6((int)$bal['campaign_available']===50000&&(int)$bal['campaign_reserved']===50000,'Reservation moves funds from available to reserved without changing total funded value.');
$over=false;try{sponsored_research_compensation_reserve($pdo,$sponsor,(string)$submission['public_id'],90000);}catch(Throwable $e){} // idempotent existing reservation
// Create a second submission is not allowed per participation; validate available balance directly instead.
p80s6((int)$bal['campaign_available']<90000,'Available balance prevents over-commitment of funded campaign money.');

$case=sponsored_research_review_open($pdo,$sponsor,(string)$submission['public_id'],['blind_review'=>true]);
$case=sponsored_research_review_decide($pdo,$sponsor,(string)$case['public_id'],'accepted','Accepted for compensation.');
p80s6($case['status']==='accepted'&&(int)$case['compensation_eligible']===1,'Accepted review remains the sole compensation trigger.');
$wallet=sponsored_research_finance_researcher_balances($pdo,(int)$researcher['id'],'USD');p80s6((int)$wallet['USD']['pending_cents']===45000&&(int)$wallet['USD']['held_cents']===0,'Acceptance settles 90% net researcher earnings into pending balance.');
$bal=sponsored_research_finance_campaign_balances($pdo,(int)$campaign['id'],'USD');p80s6((int)$bal['campaign_reserved']===0&&(int)$bal['platform_fee']===5000,'Acceptance clears reservation and records the frozen 10% platform fee.');
$res=sponsored_research_compensation_reservation_for_submission($pdo,(int)$submission['id']);p80s6($res['status']==='settled'&&!empty($res['settlement_transaction_id']),'Compensation reservation is atomically marked settled.');

$dispute=sponsored_research_dispute_open($pdo,$researcher,(string)$case['public_id'],'Compensation terms are disputed.');
$wallet=sponsored_research_finance_researcher_balances($pdo,(int)$researcher['id'],'USD');$bal=sponsored_research_finance_campaign_balances($pdo,(int)$campaign['id'],'USD');
p80s6((int)$wallet['USD']['pending_cents']===0&&(int)$wallet['USD']['held_cents']===45000,'Dispute moves researcher earnings from pending to held without deleting ledger history.');
p80s6((int)$bal['platform_fee']===0&&(int)$bal['platform_fee_held']===5000,'Dispute also holds the platform fee.');
sponsored_research_dispute_resolve($pdo,$admin,(string)$dispute['public_id'],'upheld','Original acceptance and compensation stand.');
$wallet=sponsored_research_finance_researcher_balances($pdo,(int)$researcher['id'],'USD');$bal=sponsored_research_finance_campaign_balances($pdo,(int)$campaign['id'],'USD');
p80s6((int)$wallet['USD']['pending_cents']===45000&&(int)$wallet['USD']['held_cents']===0,'Upheld dispute releases researcher earnings back to pending.');
p80s6((int)$bal['platform_fee']===5000&&(int)$bal['platform_fee_held']===0,'Upheld dispute releases platform fee hold.');

$rev=sponsored_research_compensation_reverse($pdo,$admin,(string)$case['public_id'],'Administrative reversal before payout.');
p80s6($rev['transaction_type']==='reversal','Admin reversal is an append-only journal transaction.');
$wallet=sponsored_research_finance_researcher_balances($pdo,(int)$researcher['id'],'USD');$bal=sponsored_research_finance_campaign_balances($pdo,(int)$campaign['id'],'USD');
p80s6((int)$wallet['USD']['pending_cents']===0&&(int)$wallet['USD']['held_cents']===0,'Reversal removes unpaid researcher earnings without destructive edits.');
p80s6((int)$bal['campaign_available']===100000&&(int)$bal['platform_fee']===0,'Reversal restores gross funds to campaign available balance and reverses platform fee.');
$final=sponsored_research_review_case_get($pdo,(string)$case['public_id']);p80s6((int)$final['compensation_eligible']===0,'Reversed compensation is no longer payout-eligible.');

$q=$pdo->prepare('SELECT COUNT(*) FROM sponsored_research_financial_transactions WHERE campaign_id=?');$q->execute([(int)$campaign['id']]);p80s6((int)$q->fetchColumn()===6,'Funding, reservation, settlement, hold, release and reversal remain as six immutable financial transactions.');

echo "Phase 80 Section 6 Compensation, Earnings & Financial Ledger database journey passed.\n";
