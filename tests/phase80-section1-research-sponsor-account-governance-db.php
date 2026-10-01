<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','functions','research-accounts'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p80s1(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
p80s1(research_accounts_ready($pdo),'Research/Sponsor account governance schema is available.');
$run='p80s1'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='user')use($pdo,$run,$pub):array{$u=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?, 'active',?,'free','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$make('GovernanceAdmin','admin');$researcher=$make('PaidResearcher');$dual=$make('DualAuthority');
$r=research_account_apply($pdo,$researcher,['specialties'=>'AI, Markets, AI','languages'=>'English','biography'=>'Research profile']);
p80s1($r['status']==='pending','Research Account starts pending and never self-approves.');
p80s1(!research_account_is_approved($pdo,$researcher),'Pending researcher is blocked from paid research.');
$blocked=false;try{research_account_require_approved($pdo,$researcher);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'approved Research Account');}p80s1($blocked,'Canonical paid-research gate rejects unapproved users.');
$r=research_account_admin_decide($pdo,$admin,(int)$researcher['id'],'research_account','approved','Qualified researcher.','identity');
p80s1($r['status']==='approved'&&$r['verification_level']==='identity','Admin approval activates Research Account with verification level.');
p80s1(research_account_is_approved($pdo,$researcher),'Approved Research Account passes paid-research eligibility.');
$s=sponsor_account_apply($pdo,$dual,['organization_name'=>'Example Sponsor','website_url'=>'https://example.test']);
research_account_apply($pdo,$dual,['specialties'=>'Policy','languages'=>'English']);
research_account_admin_decide($pdo,$admin,(int)$dual['id'],'sponsor_account','approved','Verified sponsor.','organization');
p80s1(sponsor_account_is_approved($pdo,$dual)&&!research_account_is_approved($pdo,$dual),'Sponsor approval is independent and does not grant Research Account authority.');
research_account_admin_decide($pdo,$admin,(int)$researcher['id'],'research_account','suspended','Temporary governance hold.','identity');
p80s1(!research_account_is_approved($pdo,$researcher),'Suspension immediately removes paid-research eligibility.');
$history=research_account_governance_history($pdo,(int)$researcher['id']);p80s1(count($history)>=3,'Application, approval and suspension create immutable governance events.');
$snapshot=research_account_eligibility_snapshot($pdo,$researcher);p80s1($snapshot['research_status']==='suspended'&&!$snapshot['research_approved'],'Eligibility snapshot exposes governed authority state to downstream campaign code.');
echo "Phase 80 Section 1 Research & Sponsor Account Governance database journey passed.\n";
