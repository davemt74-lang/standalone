<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function rmv13(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$run='rm3'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='mission3_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")
 ->execute([$pub('u'),$username,'Mission Section 3',$username.'@example.test']);
$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$owner=$q->fetch();
$agent=research_agent_create($pdo,$owner,['name'=>'Mission Progress Agent','description'=>'Section 3 fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$mission=research_mission_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'title'=>'Progress Mission','research_question'=>'What should we conclude from the evidence?',
 'objective'=>'Resolve two evidence questions and synthesize a reviewable answer.','priority'=>'high',
 'success_criteria'=>[['type'=>'answer','label'=>'Primary question answered'],['type'=>'quality','label'=>'Material risks addressed']],
 'subquestions'=>[['question'=>'What does source group A establish?','priority'=>'high'],['question'=>'What contradicts source group A?','priority'=>'high']]
]);
$mission=research_mission_create_plan($pdo,$owner,(string)$mission['public_id'],[]);$mission=research_mission_start($pdo,$owner,(string)$mission['public_id']);
$planId=(int)$mission['plan']['id'];$subs=(array)$mission['subquestions'];
rmv13(($mission['progress']['percent_complete']??-1)===0,'Newly started Mission reports zero completed execution.');
rmv13(empty($mission['progress']['completion_readiness']['ready']),'Mission is not completion-ready while work remains.');

$firstTask=(string)$subs[0]['linked_task_public_id'];$secondTask=(string)$subs[1]['linked_task_public_id'];
$q=$pdo->prepare("UPDATE research_tasks SET status='complete',execution_summary='Source group A establishes sustained demand with cited support.',completed_at=NOW() WHERE public_id=?");$q->execute([$firstTask]);
research_task_plan_recalculate($pdo,$planId);
$progress=research_mission_progress($pdo,$owner,(string)$mission['public_id']);
rmv13(($progress['subquestions']['answered']??0)===1,'Completed linked Task resolves its Mission sub-question.');
$q=$pdo->prepare("SELECT answer_summary FROM research_mission_subquestions WHERE linked_task_id=(SELECT id FROM research_tasks WHERE public_id=?)");$q->execute([$firstTask]);
rmv13(str_contains((string)$q->fetchColumn(),'sustained demand'),'Task execution summary becomes the durable sub-question answer.');

research_task_mark_failed($pdo,$secondTask,'Contradictory evidence still needs resolution.');
$progress=research_mission_progress($pdo,$owner,(string)$mission['public_id']);
rmv13(($progress['subquestions']['blocked']??0)===1&&count((array)$progress['blockers'])>=2,'Failed linked Task produces explicit Task and sub-question blockers.');
rmv13(empty($progress['completion_readiness']['ready']),'Explicit blockers prevent completion readiness regardless of percentage.');

$q=$pdo->prepare("UPDATE research_tasks SET status='complete',blocking_reason=NULL,execution_summary='The contradiction is explainable by a narrower time window.',completed_at=NOW() WHERE public_id=?");$q->execute([$secondTask]);
$q=$pdo->prepare("SELECT public_id FROM research_tasks WHERE plan_id=? AND task_type='synthesize' LIMIT 1");$q->execute([$planId]);$synth=(string)$q->fetchColumn();
$q=$pdo->prepare("UPDATE research_tasks SET status='complete',execution_summary='The evidence supports a qualified expansion recommendation with remaining monitoring needs.',completed_at=NOW() WHERE public_id=?");$q->execute([$synth]);
research_task_plan_recalculate($pdo,$planId);
$mission=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
rmv13(($mission['status']??'')==='review'&&($mission['plan']['status']??'')==='completed','Completed existing Plan moves active Mission to Review, not Completed.');
rmv13(($mission['progress']['subquestions']['answered']??0)===2,'Execution sync resolves all linked sub-questions.');
rmv13(str_contains((string)($mission['progress']['primary_answer']['summary']??''),'qualified expansion'),'Progress exposes the synthesis Task as the primary Mission answer.');
rmv13(empty($mission['progress']['completion_readiness']['ready']),'Pending success criteria still prevent completion readiness after execution completes.');

foreach((array)$mission['criteria'] as $criterion)research_mission_update_criterion($pdo,$owner,(string)$criterion['public_id'],['status'=>'satisfied','evaluation'=>['reason'=>'Verified in Section 3 fixture.']]);
foreach((array)$mission['subquestions'] as $i=>$sub)research_mission_update_subquestion($pdo,$owner,(string)$sub['public_id'],['confidence'=>$i===0?0.8:0.6]);
$progress=research_mission_progress($pdo,$owner,(string)$mission['public_id']);
rmv13(!empty($progress['completion_readiness']['ready']),'Completed Plan + answered questions + satisfied criteria + no blockers is completion-ready.');
rmv13(abs((float)($progress['confidence']['average']??0)-0.7)<0.001&&($progress['confidence']['rated']??0)===2,'Confidence summary reports explicit ratings without inventing missing values.');
rmv13(($progress['percent_complete']??0)===100,'All applicable progress dimensions reach 100 percent when resolved.');

research_task_update($pdo,$owner,$firstTask,['description'=>'Re-open this evidence task because a material source changed.']);
$mission=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
rmv13(($mission['status']??'')==='active'&&($mission['plan']['status']??'')==='active','Reopening reviewed execution returns Mission and Plan to Active.');
rmv13(($mission['progress']['subquestions']['answered']??0)===1,'Reopened linked Task makes its sub-question unresolved again.');
rmv13(empty($mission['progress']['completion_readiness']['ready']),'Reopened execution immediately removes completion readiness.');
echo "Research Missions V1 Section 3 database journey passed.\n";
