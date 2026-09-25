<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','agent-chat','research-agents','research-agent-workspace'] as $lib)require_once $root.'/app/'.$lib.'.php';
require_once $root.'/app/release.php';
function p65(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$run='p65'.substr(bin2hex(random_bytes(6)),0,10);$username='phase65_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode,password_hash) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked',?)")
  ->execute(['u-'.$run,$username,'Phase 65 User',$username.'@example.test',password_hash('phase65-fixture',PASSWORD_DEFAULT)]);
$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$user=$q->fetch();

$before=onboarding_status($pdo,$user,false);
p65(($before['steps']['account']['complete']??false)===true,'Secure-account onboarding signal recognizes a configured login.');
p65(($before['steps']['agent']['complete']??true)===false,'Research Agent step starts incomplete before a workspace exists.');

$agent=research_agent_create($pdo,$user,['name'=>'First Run Agent','description'=>'Phase 65 first-run workspace.','cadence'=>'manual','timezone_name'=>'UTC'],true);
$afterAgent=onboarding_status($pdo,$user,false);
p65(($afterAgent['steps']['agent']['complete']??false)===true,'Creating a Research Agent completes the Research Agent onboarding step.');
p65((int)($afterAgent['signals']['research_agents']??0)===1,'Onboarding exposes the real Research Agent activity signal.');
p65((int)($afterAgent['signals']['research_projects']??0)>=1,'Agent-owned Research projects count as usable Research instead of being excluded.');

$project=research_agent_workspace_project($pdo,$user,(string)$agent['public_id']);
$doc=research_agent_workspace_create_document($pdo,$user,$project,['title'=>'First Research Note','content_html'=>'<p>Evidence captured during onboarding.</p>']);
$afterEvidence=onboarding_status($pdo,$user,false);
p65(($afterEvidence['steps']['capture']['complete']??false)===true,'Adding a Research workspace object completes the evidence step even without a browser annotation.');
p65(($afterEvidence['steps']['continue']['complete']??false)===true,'Saved Research work completes the continue-work step.');
p65((int)($afterEvidence['signals']['workspace_items']??0)>=1,'Workspace evidence is represented in onboarding signals.');

$resolved=research_agent_workspace_object($pdo,$user,(string)$doc['public_id'],false);
p65($resolved!==null&&str_contains((string)$resolved['document_plain_text'],'Evidence captured'),'First-run document is durable and can be continued later.');

echo "Phase 65 First-Run Experience & Daily Workflow Polish database journey passed.\n";
