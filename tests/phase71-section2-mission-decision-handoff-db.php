<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p71s2(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p71s2throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p71s2(research_decisions_ready($pdo)&&research_decision_handoffs_ready($pdo),'Decision Ledger and Mission handoff runtime are ready.');
$run='p71s2'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='p71s2_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
 ->execute([$pub('u'),$username,'Phase 71 S2',$username.'@example.test']);
$userId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$userId]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$userId]);$owner=$q->fetch();

$agent=research_agent_create($pdo,$owner,['name'=>'Mission Handoff Agent','description'=>'Phase 71 Section 2 fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
$mission=research_mission_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'title'=>'Market Entry Mission','research_question'=>'Should we enter the market now?',
 'objective'=>'Produce a cited synthesis suitable for a human decision.','success_definition'=>'A reviewable synthesis exists.','priority'=>'high',
 'success_criteria'=>[['label'=>'Primary question answered']],
 'subquestions'=>[['question'=>'What does current demand evidence show?','priority'=>'high']]
]);
p71s2throws(fn()=>research_decision_from_mission($pdo,$owner,(string)$mission['public_id'],[]),'Draft Mission cannot be handed off to a Decision.');

$mission=research_mission_create_plan($pdo,$owner,(string)$mission['public_id'],[]);$mission=research_mission_start($pdo,$owner,(string)$mission['public_id']);
$sources=[];
foreach([1,2] as $sourceIndex){
    $url='https://8.8.8.8/'.$run.'/handoff-source-'.$sourceIndex;$source=ensure_source($pdo,$url,'Mission Handoff Source '.$sourceIndex);$body='Mission handoff evidence '.$sourceIndex.' '.$run;
    $pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")
      ->execute([(int)$source['id'],$url,'Mission Handoff Source '.$sourceIndex,$body,hash('sha256',$body)]);
    $sourceVersion=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sourceVersion,(int)$source['id']]);
    $pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);$sources[]=$source;
}

foreach((array)$mission['plan']['tasks'] as $index=>$task){
    $refs=[
      ['type'=>'source','id'=>(string)$sources[0]['public_id'],'locator'=>'Mission handoff evidence A','relationship'=>'primary'],
      ['type'=>'source','id'=>(string)$sources[1]['public_id'],'locator'=>'Mission handoff evidence B','relationship'=>'supports']
    ];
    research_task_store_refs($pdo,(int)$task['id'],(int)$project['id'],$refs);
    $summary=$index===0?'Demand evidence supports a controlled market entry.':'The evidence supports entering the market with bounded rollout controls.';
    $pdo->prepare("UPDATE research_tasks SET execution_summary=?,status='review',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([$summary,(int)$task['id']]);
    research_task_review($pdo,$owner,(string)$task['public_id'],true);
}
$mission=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
p71s2(($mission['status']??'')==='review'&&($mission['plan']['status']??'')==='completed','Completed Mission execution reaches Review state.');
p71s2(!empty($mission['progress']['primary_answer']['summary']),'Mission has a current synthesis answer before handoff.');

$outcomesBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn();
$decision=research_decision_from_mission($pdo,$owner,(string)$mission['public_id'],[
 'idempotency_key'=>'market-entry-primary','decision_type'=>'conclusion','title'=>'Market entry conclusion',
 'rationale'=>'Mission evidence supports a controlled entry, while final acceptance remains a human decision.',
 'alternatives'=>[['title'=>'Delay entry','reason'=>'Retains optionality but postpones learning.']]
]);
p71s2(($decision['status']??'')==='proposed'&&($decision['decision_type']??'')==='conclusion','Mission handoff creates a Proposed Conclusion, never an Accepted Decision.');
p71s2(($decision['source_mission_public_id']??'')===$mission['public_id'],'Proposed Conclusion retains the source Mission link.');
p71s2(($decision['confidence']??null)===null,'Mission confidence is not silently converted into Decision confidence.');
p71s2(!empty($decision['handoff'])&&($decision['handoff']['mission_revision']??0)===$mission['current_revision'],'Handoff stores the exact Mission revision.');
$snapshot=(array)$decision['handoff']['snapshot'];
p71s2(($snapshot['mission_status']??'')==='review'&&($snapshot['primary_answer']??'')===$mission['progress']['primary_answer']['summary'],'Handoff snapshot freezes Mission status and synthesis.');
p71s2(($snapshot['plan_status']??'')==='completed'&&isset($snapshot['completion_readiness']),'Handoff snapshot includes execution and readiness state.');

$refTypes=array_column((array)$decision['refs'],'ref_type');$refRoles=array_column((array)$decision['refs'],'ref_role');
p71s2(in_array('mission',$refTypes,true)&&in_array('source',$refTypes,true),'Handoff carries Mission and underlying evidence pointers into Decision lineage.');
p71s2(in_array('source',$refRoles,true)||in_array('supports',$refRoles,true),'Mission evidence relationships are preserved as Decision reference roles.');
p71s2((int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn()===$outcomesBefore,'Mission handoff does not fabricate Outcome Learning events.');

$again=research_decision_from_mission($pdo,$owner,(string)$mission['public_id'],[
 'idempotency_key'=>'market-entry-primary','decision_type'=>'conclusion','title'=>'Market entry conclusion',
 'rationale'=>'Mission evidence supports a controlled entry, while final acceptance remains a human decision.'
]);
p71s2(($again['public_id']??'')===$decision['public_id'],'Repeated handoff with the same idempotency key returns the existing Decision.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_decision_handoffs WHERE mission_id=?');$q->execute([(int)$mission['id']]);p71s2((int)$q->fetchColumn()===1,'Idempotent retry cannot create a duplicate handoff.');

$explicit=research_decision_from_mission($pdo,$owner,(string)$mission['public_id'],[
 'idempotency_key'=>'market-entry-recommendation','decision_type'=>'recommendation','title'=>'Market entry rollout recommendation',
 'statement'=>'Use a bounded rollout with explicit review gates.','confidence'=>0.77,'rationale'=>'The Mission evidence supports a bounded rollout.'
]);
p71s2(($explicit['status']??'')==='proposed'&&abs((float)$explicit['confidence']-0.77)<0.0001,'A deliberate second handoff may create a separate Proposed Recommendation with explicit confidence.');
$q->execute([(int)$mission['id']]);p71s2((int)$q->fetchColumn()===2,'One Mission may intentionally produce multiple Decisions through distinct idempotency keys.');

$oldSnapshot=$decision['handoff']['snapshot'];$oldHash=(string)$decision['handoff']['mission_state_hash'];
research_mission_update($pdo,$owner,(string)$mission['public_id'],['objective'=>'Produce a cited synthesis suitable for a human decision and a documented rollout review.','reason'=>'Post-handoff Mission revision']);
$changedMission=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
$reloadedDecision=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s2((int)$changedMission['current_revision']>(int)$mission['current_revision'],'Mission can continue evolving after Decision handoff.');
p71s2((string)$reloadedDecision['handoff']['mission_state_hash']===$oldHash&&$reloadedDecision['handoff']['snapshot']===$oldSnapshot,'Later Mission revisions cannot rewrite the immutable handoff snapshot.');
p71s2(($changedMission['status']??'')==='review','Decision handoff does not mutate the Mission lifecycle.');

$eventTypes=array_column((array)$reloadedDecision['events'],'event_type');
p71s2(in_array('decision_created_from_mission',$eventTypes,true),'Decision history records the explicit Mission handoff event.');

echo "Phase 71 Section 2 Mission to Decision Handoff database journey passed.\n";
