<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agents','research-agent-shell-ui'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p74s2(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$run='p74s2'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase74ShellOwner');$member=$makeUser('Phase74ShellMember');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 74 Shell Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 74 Shell Agent','description'=>'Unified shell permission fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
p74s2(!empty($agent['public_id'])&&!empty($agent['project_public_id'])&&!empty($agent['conversation_public_id']),'Team Research Agent fixture preserves Agent, Project, and conversation identities.');

$ownerCtx=research_agent_shell_resolve($pdo,$owner,(string)$agent['public_id']);
p74s2((string)($ownerCtx['agent']['public_id']??'')===(string)$agent['public_id'],'Owner shell resolves the selected Agent public ID.');
$conversationCtx=research_agent_shell_resolve($pdo,$owner,(string)$agent['conversation_public_id']);
p74s2((string)($conversationCtx['agent']['public_id']??'')===(string)$agent['public_id'],'Shell resolves Chat conversation ID back to the same Research Agent.');

$memberCtx=research_agent_shell_resolve($pdo,$member,(string)$agent['public_id']);
p74s2((string)($memberCtx['agent']['public_id']??'')===(string)$agent['public_id'],'Authorized Team researcher resolves the same Research Agent shell.');

$resolved=$ownerCtx['agent'];
p74s2(research_agent_shell_href($resolved,'chat')==='/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']),'Chat tab preserves the Agent conversation identity.');
p74s2(research_agent_shell_href($resolved,'knowledge')==='/research-agent-knowledge.php?agent='.rawurlencode((string)$agent['public_id']),'Knowledge tab preserves Agent identity.');
p74s2(research_agent_shell_href($resolved,'research')==='/research-agent-research.php?agent='.rawurlencode((string)$agent['public_id']),'Research tab preserves Agent identity.');
p74s2(research_agent_shell_href($resolved,'reports')==='/research-reports.php?agent='.rawurlencode((string)$agent['public_id']),'Reports tab preserves Agent identity.');
p74s2(research_agent_shell_href($resolved,'library')==='/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']).'&workspace=library','Library remains a Chat workspace mode.');
p74s2(research_agent_shell_href($resolved,'desktop')==='/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']).'&workspace=desktop','Desktop remains a Chat workspace mode.');

$beforeProject=(string)$agent['project_public_id'];
$q=$pdo->prepare('SELECT COUNT(*) FROM research_agents WHERE public_id=?');$q->execute([$agent['public_id']]);$beforeAgents=(int)$q->fetchColumn();
research_agent_shell_resolve($pdo,$owner,(string)$agent['public_id']);
$q->execute([$agent['public_id']]);$afterAgents=(int)$q->fetchColumn();
$after=research_agent_access($pdo,$owner,(string)$agent['public_id']);
p74s2($beforeAgents===$afterAgents&&(string)($after['project_public_id']??'')===$beforeProject,'Navigating the shell creates no duplicate Agent or Project and preserves the internal Project boundary.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$member['id']]);
p74s2(research_agent_access($pdo,$member,(string)$agent['public_id'])===null,'Team revocation immediately removes direct Agent access.');
$revoked=research_agent_shell_resolve($pdo,$member,(string)$agent['public_id']);
p74s2((string)($revoked['agent']['public_id']??'')!==(string)$agent['public_id'],'Unified shell cannot preserve a revoked Team Agent context.');

p74s2(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Unified shell adds no migration 104.');
echo "Phase 74 Section 2 Unified Research Agent Shell database journey passed.\n";
