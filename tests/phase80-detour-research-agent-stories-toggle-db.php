<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-agent-stories'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p80story(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p80story'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='stories_'.$run;$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,'Story Toggle User',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$u=$q->fetch();
$agent=research_agent_create($pdo,$u,['name'=>'Toggle Agent','description'=>'Story master switch test','cadence'=>'manual','timezone_name'=>'UTC']);
$access=research_agent_access($pdo,$u,(string)$agent['public_id']);
p80story(research_agent_stories_enabled($pdo,$access),'Stories default on for existing/new Agents without an explicit policy row.');
$published=research_agent_story_create_manual($pdo,$u,(string)$agent['public_id'],['title'=>'Visible update','body'=>'This Story is visible while Stories are enabled.','status'=>'published']);
$draft=research_agent_story_create_manual($pdo,$u,(string)$agent['public_id'],['title'=>'Draft update','body'=>'This Story should not publish while Stories are disabled.','status'=>'draft']);
p80story(count(research_agent_story_list($pdo,$u,20))===1,'Published Story is visible while Agent Stories are enabled.');
research_agent_story_policy_update($pdo,$u,(string)$agent['public_id'],['stories_enabled'=>false,'publish_mode'=>'approval','min_priority'=>'medium','daily_story_cap'=>3,'timezone_name'=>'UTC','trigger_evidence'=>1,'trigger_risk'=>1,'trigger_question'=>1,'trigger_decision'=>1,'trigger_task'=>1,'trigger_update'=>1]);
$access=research_agent_access($pdo,$u,(string)$agent['public_id']);
p80story(!research_agent_stories_enabled($pdo,$access),'Per-Agent master switch disables Stories.');
p80story(research_agent_story_list($pdo,$u,20)===[],'Disabled Agent Stories disappear from the canonical Story list.');
p80story(research_agent_story_access($pdo,$u,(string)$published['public_id'])===null,'Disabled Agent published Stories cannot be opened by deep link.');
$decision=research_agent_story_policy_decision($pdo,$access,'update','high');p80story(empty($decision['allow'])&&$decision['reason']==='stories_disabled','Proactive generation is suppressed by the master switch.');
$blocked=false;try{research_agent_story_publish_manual($pdo,$u,(string)$draft['public_id']);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'turned off');}p80story($blocked,'Manual draft publishing is blocked while Stories are off.');
research_agent_story_policy_update($pdo,$u,(string)$agent['public_id'],['stories_enabled'=>true,'publish_mode'=>'approval','min_priority'=>'medium','daily_story_cap'=>3,'timezone_name'=>'UTC','trigger_evidence'=>1,'trigger_risk'=>1,'trigger_question'=>1,'trigger_decision'=>1,'trigger_task'=>1,'trigger_update'=>1]);
p80story(count(research_agent_story_list($pdo,$u,20))===1,'Re-enabling Stories restores eligible published Stories.');
echo "Research Agent Stories master-toggle database journey passed.\n";
