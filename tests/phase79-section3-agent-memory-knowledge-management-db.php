<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agents','research-agent-shell-ui','research-agent-knowledge-ui','research-retrieval','research-agent-memory'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p79s3(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
p79s3(research_memory_ready($pdo),'Agent Memory schema is available.');

$run='p79s3'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('MemoryOwner');$member=$makeUser('MemoryMember');

$agent=research_agent_create($pdo,$owner,['name'=>'Memory Agent','description'=>'Memory governance test','cadence'=>'manual','timezone_name'=>'UTC']);
p79s3(!empty($agent['public_id'])&&!empty($agent['project_public_id']),'Personal Research Agent created.');
$project=project_access($pdo,(int)$owner['id'],(string)$agent['project_public_id']);p79s3((bool)$project,'Owner resolves Agent project.');

$type='document';$object=$pub('doc');
$control=research_memory_upsert($pdo,$owner,(string)$agent['project_public_id'],$type,$object,'include','The corrected answer is 42.');
p79s3(($control['retrieval_state']??'')==='include','Explicit include state persists.');
p79s3(trim((string)($control['correction_text']??''))==='The corrected answer is 42.','User correction persists.');

$result=['object_type'=>$type,'public_id'=>$object,'title'=>'Original note','snippet'=>'The original answer was 41.','metadata'=>[]];
$applied=research_memory_apply_result($pdo,$owner,$project,$result);
p79s3(is_array($applied)&&str_contains((string)$applied['snippet'],'The corrected answer is 42.'),'Correction is injected into Agent-facing retrieval.');
p79s3(str_contains((string)$applied['snippet'],'ORIGINAL CAPTURED KNOWLEDGE'),'Original evidence remains visible behind correction.');

research_memory_record_usage($pdo,$owner,$agent,[$applied],null);
$usage=research_memory_usage_summary($pdo,(int)$project['id'],$type,$object);
p79s3((int)$usage['count']===1&&!empty($usage['last_used_at']),'Agent-use ledger records retrieval usage.');

$history=research_memory_history($pdo,$owner,(string)$agent['project_public_id'],$type,$object,20);
p79s3(count($history)>=1,'Memory history records governed changes.');

$control=research_memory_upsert($pdo,$owner,(string)$agent['project_public_id'],$type,$object,'exclude','The corrected answer is 42.');
p79s3(($control['retrieval_state']??'')==='exclude','Exclude state persists.');
p79s3(research_memory_apply_result($pdo,$owner,$project,$result)===null,'Excluded memory is removed from future Agent retrieval.');

$control=research_memory_upsert($pdo,$owner,(string)$agent['project_public_id'],$type,$object,'inherit','');
p79s3(($control['retrieval_state']??'')==='inherit'&&trim((string)($control['correction_text']??''))==='','Memory can be restored to inherited state and correction cleared.');
$restored=research_memory_apply_result($pdo,$owner,$project,$result);
p79s3(is_array($restored)&&!str_contains((string)$restored['snippet'],'USER CORRECTION'),'Restored memory returns to canonical source representation.');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Memory Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);
$teamAgent=research_agent_create($pdo,$owner,['name'=>'Team Memory Agent','description'=>'Team memory test','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
p79s3((bool)research_agent_access($pdo,$member,(string)$teamAgent['public_id']),'Authorized Team researcher can resolve Agent Memory scope.');
research_memory_upsert($pdo,$member,(string)$teamAgent['project_public_id'],'claim',$pub('claim'),'exclude','');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$member['id']]);
p79s3(research_agent_access($pdo,$member,(string)$teamAgent['public_id'])===null,'Team revocation immediately removes Agent access.');
$denied=false;try{research_memory_upsert($pdo,$member,(string)$teamAgent['project_public_id'],'claim',$pub('claim2'),'exclude','');}catch(RuntimeException $e){$denied=true;}
p79s3($denied,'Revoked Team member cannot manage Agent Memory.');

echo "Phase 79 Section 3 Agent Memory / Knowledge Management database journey passed.\n";
