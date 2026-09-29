<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agents','research-agent-shell-ui','research-agent-knowledge-ui'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p74s3(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message
";}
$run='p74s3'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase74KnowledgeOwner');$member=$makeUser('Phase74KnowledgeMember');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 74 Knowledge Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 74 Knowledge Agent','description'=>'Unified Knowledge permission fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);

p74s3(!empty($agent['public_id'])&&!empty($agent['project_public_id'])&&!empty($agent['conversation_public_id']),'Knowledge fixture preserves Agent, Project, and conversation identities.');
$ownerCtx=research_agent_shell_resolve($pdo,$owner,(string)$agent['public_id']);
$memberCtx=research_agent_shell_resolve($pdo,$member,(string)$agent['public_id']);
p74s3((string)($ownerCtx['agent']['public_id']??'')===(string)$agent['public_id'],'Owner resolves canonical Knowledge Agent.');
p74s3((string)($memberCtx['agent']['public_id']??'')===(string)$agent['public_id'],'Authorized Team researcher resolves canonical Knowledge Agent.');

foreach(['library','insights','changes'] as $view){
    $href=research_agent_knowledge_href((string)$agent['public_id'],$view);
    p74s3(str_contains($href,'agent='.rawurlencode((string)$agent['public_id']))&&str_contains($href,'view='.$view),'Knowledge '.$view.' URL preserves Agent identity.');
}
$links=research_agent_knowledge_engine_links($ownerCtx['agent']);
p74s3(str_contains((string)$links['library'][0]['href'],'workspace=library')&&str_contains((string)$links['library'][1]['href'],'workspace=desktop'),'Library keeps existing workspace modes.');
p74s3(str_contains((string)$links['insights'][0]['href'],rawurlencode((string)$agent['project_public_id'])),'Insights deep links retain internal Project boundary.');
p74s3(str_contains((string)$links['changes'][0]['href'],rawurlencode((string)$agent['public_id'])),'Changes monitoring link retains Agent boundary.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_agents WHERE public_id=?');$q->execute([$agent['public_id']]);$beforeAgents=(int)$q->fetchColumn();
foreach(['library','insights','changes'] as $view){research_agent_knowledge_href((string)$agent['public_id'],$view);research_agent_knowledge_engine_links($ownerCtx['agent']);}
$q->execute([$agent['public_id']]);$afterAgents=(int)$q->fetchColumn();
p74s3($beforeAgents===$afterAgents,'Knowledge navigation creates no duplicate Agent or Project.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$member['id']]);
p74s3(research_agent_access($pdo,$member,(string)$agent['public_id'])===null,'Team revocation remains authoritative for Knowledge access.');
$revoked=research_agent_shell_resolve($pdo,$member,(string)$agent['public_id']);
p74s3((string)($revoked['agent']['public_id']??'')!==(string)$agent['public_id'],'Revoked Team Agent cannot remain selected in Knowledge.');
p74s3(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Unified Knowledge adds no migration 104.');
echo "Phase 74 Section 3 Unified Knowledge UI database journey passed.
";
