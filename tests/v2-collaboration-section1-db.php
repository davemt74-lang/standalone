<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');if($dsn==='')throw new RuntimeException('DB_DSN required.');
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','subscriptions','account-admin','account-membership','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','v2-collaboration'] as $lib)
 require_once $root.'/app/'.$lib.'.php';
function v2db(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
v2db(v2_collaboration_ready($pdo),'Migration 130 exposes canonical roster, assignments and audit events.');
$rand=substr(bin2hex(random_bytes(6)),0,12);
$pub=static fn(string $s):string=>$s.'-'.$rand.'-'.substr(bin2hex(random_bytes(3)),0,6);
$createUser=static function(string $label)use($pdo,$pub,$rand):array{
 $name=strtolower($label).$rand;
 $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
   ->execute([$pub('u'),$name,$label,$name.'@example.test']);
 $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)$pdo->lastInsertId()]);
 return $q->fetch();
};
$owner=$createUser('V2Owner');$other=$createUser('V2Other');$teamViewer=$createUser('V2TeamViewer');
$createTeam=static function(array $u)use($pdo,$pub):int{
 $pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')
  ->execute([$pub('team'),(int)$u['id'],'Collaboration team']);
 $id=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")->execute([$id,(int)$u['id']]);
 return $id;
};
$team=$createTeam($owner);$otherTeam=$createTeam($owner);
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'researcher')")
  ->execute([$team,(int)$teamViewer['id']]);
$makeAgent=static function(array $u,string $name,?int $teamId=null,string $visibility='private')use($pdo,$pub):array{
 $project=$pub('project');
 $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,status) VALUES(?,?,?,?,'active')")
   ->execute([$project,(int)$u['id'],$teamId,$name]);
 $projectId=(int)$pdo->lastInsertId();
 $conversation=$pub('conv');
 $pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,?)")
   ->execute([$conversation,(int)$u['id'],$name]);
 $convId=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")
   ->execute([$convId,(int)$u['id']]);
 $agent=$pub('agent');
 $pdo->prepare("INSERT INTO research_agents(public_id,owner_user_id,team_id,project_id,conversation_id,name,status,visibility,monitoring_cadence)
 VALUES(?,?,?,?,?,?,'active',?,'manual')")
   ->execute([$agent,(int)$u['id'],$teamId,$projectId,$convId,$name,$visibility]);
 return ['public_id'=>$agent,'id'=>(int)$pdo->lastInsertId(),'project_id'=>$projectId,'conv_id'=>$convId];
};
$a=$makeAgent($owner,'Lead');
$b=$makeAgent($owner,'Evidence');
$c=$makeAgent($owner,'Verification');
$d=$makeAgent($owner,'Over capacity');
$outsider=$makeAgent($other,'Other owner');
$teamA=$makeAgent($owner,'Team lead',$team);
$teamB=$makeAgent($owner,'Team evidence',$team);
$wrongTeam=$makeAgent($owner,'Other team evidence',$otherTeam);
$publicAgent=$makeAgent($owner,'Public profile',null,'public');
$deny=static function(callable $operation,string $label):void{
 $blocked=false;try{$operation();}catch(RuntimeException|InvalidArgumentException $e){$blocked=true;}
 v2db($blocked,$label);
};
$plan=v2_collaboration_create($pdo,$owner,$a['public_id']);
v2db($plan['owner']&&$plan['roster_only']&&$plan['allows_cross_agent_data_access']===false
 &&count($plan['members'])===1&&$plan['members'][0]['role']==='lead',
 'Owner creates non-sharing roster anchored to its canonical Lead.');
$deny(fn()=>v2_collaboration_create($pdo,$other,$a['public_id']),'Other user cannot forge Lead ownership.');
$deny(fn()=>v2_collaboration_create($pdo,$owner,$a['public_id']),'Unique Lead and Project forbid duplicate active plans.');
$deny(fn()=>v2_collaboration_assign($pdo,$other,$plan['public_id'],$b['public_id'],'evidence'),
 'Other user cannot assign an Agent into an owner plan.');
$deny(fn()=>v2_collaboration_assign($pdo,$owner,$plan['public_id'],$outsider['public_id'],'evidence'),
 'Another owner cannot delegate a private Agent.');
$deny(fn()=>v2_collaboration_assign($pdo,$owner,$plan['public_id'],$publicAgent['public_id'],'evidence'),
 'Public Agent cannot silently enter a private collaboration.');
$deny(fn()=>v2_collaboration_assign($pdo,$owner,$plan['public_id'],$teamB['public_id'],'evidence'),
 'Personal Lead cannot inherit a Team Agent private context.');
$deny(fn()=>v2_collaboration_assign($pdo,$owner,$plan['public_id'],$b['public_id'],'lead'),
 'Lead role cannot be forged through delegated assignment.');
$plan=v2_collaboration_assign($pdo,$owner,$plan['public_id'],$b['public_id'],'evidence');
v2db(count($plan['members'])===2,'Second personal Agent receives a role without moving projects or conversations.');
$revision=$plan['revision'];
$plan=v2_collaboration_assign($pdo,$owner,$plan['public_id'],$b['public_id'],'evidence');
v2db($plan['revision']===$revision,'Identical assignment is idempotent, with no extra revision.');
$plan=v2_collaboration_assign($pdo,$owner,$plan['public_id'],$c['public_id'],'verifier');
$deny(fn()=>v2_collaboration_assign($pdo,$owner,$plan['public_id'],$d['public_id'],'analyst'),
 'Pilot roster fails closed at three Agents, including Lead.');
$plan=v2_collaboration_member_state($pdo,$owner,$plan['public_id'],$b['public_id'],'paused');
v2db(count(array_filter($plan['members'],fn($m)=>$m['status']==='paused'))===1,'Owner may pause a role.');
$plan=v2_collaboration_plan_state($pdo,$owner,$plan['public_id'],'paused');
$deny(fn()=>v2_collaboration_member_state($pdo,$owner,$plan['public_id'],$b['public_id'],'active'),
 'Paused plan forbids restoring a member to active.');
$plan=v2_collaboration_plan_state($pdo,$owner,$plan['public_id'],'active');
$pdo->prepare("UPDATE research_agents SET status='paused' WHERE id=?")->execute([$b['id']]);
$deny(fn()=>v2_collaboration_member_state($pdo,$owner,$plan['public_id'],$b['public_id'],'active'),
 'Paused canonical Agent cannot be reactivated by roster privileges.');
$pdo->prepare("UPDATE research_agents SET status='active' WHERE id=?")->execute([$b['id']]);
$plan=v2_collaboration_member_state($pdo,$owner,$plan['public_id'],$b['public_id'],'removed');
v2db(count($plan['members'])===2,'Removed Agent is absent from active roster and no longer counts against cap.');
$plan=v2_collaboration_assign($pdo,$owner,$plan['public_id'],$d['public_id'],'analyst');
v2db(count($plan['members'])===3,'Capacity is reclaimed after an auditable removal.');
$events=(int)$pdo->query('SELECT COUNT(*) FROM v2_collaboration_events WHERE plan_id=(SELECT id FROM v2_collaboration_plans WHERE public_id='.$pdo->quote($plan['public_id']).')')->fetchColumn();
v2db($events>=7,'Created, assigned, paused, resumed and removed events form a durable audit sequence.');
v2db((int)$pdo->query('SELECT COUNT(*) FROM research_agents WHERE id IN ('.implode(',',[$a['id'],$b['id'],$c['id'],$d['id']]).')')->fetchColumn()===4,
 'All four canonical Agents remain independent V1 records.');
$tp=v2_collaboration_create($pdo,$owner,$teamA['public_id']);
$deny(fn()=>v2_collaboration_assign($pdo,$owner,$tp['public_id'],$wrongTeam['public_id'],'evidence'),
 'Agents from different Teams cannot join the same collaboration.');
$tp=v2_collaboration_assign($pdo,$owner,$tp['public_id'],$teamB['public_id'],'evidence');
v2db(count($tp['members'])===2,'Same-owner, same-Team private Agents can be rostered without shared-memory access.');
v2db(v2_collaboration_for_lead($pdo,$teamViewer,$teamA['public_id'])!==null,
 'Current Team member may view authorized Team collaboration metadata.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$team,(int)$teamViewer['id']]);
$deny(fn()=>v2_collaboration_read($pdo,$teamViewer,$tp['public_id']),
 'Removing Team membership immediately revokes roster visibility.');
$eligible=v2_collaboration_assignable_agents($pdo,$owner,$a['public_id']);
v2db(in_array($b['public_id'],array_column($eligible,'public_id'),true)
 &&!in_array($outsider['public_id'],array_column($eligible,'public_id'),true),
 'Owner picker is permission-scoped, not a global Agent index.');
echo "V2 Section 1 canonical multi-Agent roster DB acceptance passed.\n";
