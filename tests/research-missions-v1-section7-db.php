<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow','research-system-reports','research-report-studio','research-missions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function rmv17(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$run='rm7'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('MissionReleaseOwner','admin');$reviewer=$makeUser('MissionReleaseReviewer');$outsider=$makeUser('MissionReleaseOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Mission Release Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$reviewer['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Mission V1 Release Agent','description'=>'Final Research Missions V1 acceptance agent.','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
rmv17((bool)$project&&(int)$project['team_id']===$teamId,'Final journey starts with a Team-scoped Research Agent.');

$sources=[];
foreach([1,2] as $n){$url='https://8.8.8.8/'.$run.'/mission-source-'.$n;$source=ensure_source($pdo,$url,'Mission Release Source '.$n);$body='Mission V1 evidence '.$n.' '.$run;
  $pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")->execute([(int)$source['id'],$url,'Mission Release Source '.$n,$body,hash('sha256',$body)]);
  $sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sv,(int)$source['id']]);
  $pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);$sources[]=$source;
}
rmv17(count($sources)===2,'Final journey has two accessible independent Sources for Mission completion gates.');

$mission=research_mission_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],'title'=>'Research Missions V1 Final Acceptance',
  'research_question'=>'Does the current evidence support shipping the Research Missions V1 workflow?',
  'objective'=>'Produce an evidence-backed, team-reviewable release conclusion while preserving explicit execution and change-response governance.',
  'success_definition'=>'All Mission sub-questions are answered, the synthesis is cited, completion gates are satisfied, and the result remains reviewable after material change.',
  'priority'=>'high',
  'success_criteria'=>[['label'=>'Primary release question answered']],
  'subquestions'=>[
    ['question'=>'Does the Mission execution path preserve explicit user control?','priority'=>'high'],
    ['question'=>'Does the Mission preserve provenance and reviewability across change response?','priority'=>'high']
  ]
]);
rmv17(($mission['status']??'')==='draft'&&empty($mission['plan_public_id'])&&empty($mission['program_public_id']),'Mission creation is durable but non-executing.');

$planned=research_mission_create_plan($pdo,$owner,(string)$mission['public_id'],[]);
rmv17(($planned['plan']['status']??'')==='paused'&&count((array)$planned['plan']['tasks'])===3,'Mission planning creates the existing paused Task graph with final synthesis.');
rmv17(empty($planned['plan']['deliverable']['document_public_id']??null),'Planning alone does not create the living deliverable.');
$started=research_mission_start($pdo,$owner,(string)$mission['public_id']);
rmv17(($started['status']??'')==='active'&&($started['plan']['status']??'')==='active','Explicit start activates Mission and existing Research Plan.');
rmv17(!empty($started['plan']['deliverable']['document_public_id']),'Explicit start creates the existing living Plan deliverable.');

$planId=(int)$started['plan']['id'];$tasks=(array)$started['plan']['tasks'];
foreach($tasks as $idx=>$task){
    $refs=[
      ['type'=>'source','id'=>(string)$sources[0]['public_id'],'locator'=>'Release evidence A','relationship'=>'primary'],
      ['type'=>'source','id'=>(string)$sources[1]['public_id'],'locator'=>'Release evidence B','relationship'=>'supports']
    ];
    $stored=research_task_store_refs($pdo,(int)$task['id'],(int)$project['id'],$refs);
    rmv17(count($stored)>=2,'Task '.($idx+1).' preserves cited accessible evidence.');
    $summary=$idx<count($tasks)-1?'Sub-question evidence supports the governed Mission workflow.':'The combined evidence supports shipping Research Missions V1 with explicit human completion and review controls.';
    $pdo->prepare("UPDATE research_tasks SET execution_summary=?,status='review',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([$summary,(int)$task['id']]);
    $reviewed=research_task_review($pdo,$owner,(string)$task['public_id'],true);
    rmv17(($reviewed['status']??'')==='complete','Task '.($idx+1).' completes through existing evidence gates and human review.');
}
$afterTasks=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
rmv17(($afterTasks['plan']['status']??'')==='completed'&&($afterTasks['status']??'')==='review','Completed existing Plan moves Mission to Review without auto-completing it.');
rmv17(($afterTasks['progress']['subquestions']['answered']??0)===2&&!empty($afterTasks['progress']['primary_answer']['summary']),'Task completion synchronizes sub-question answers and current synthesis.');

$criterion=(array)$afterTasks['criteria'][0];research_mission_update_criterion($pdo,$owner,(string)$criterion['public_id'],['status'=>'satisfied','evaluation'=>['reason'=>'Final acceptance evidence and synthesis are complete.']]);
$ready=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
rmv17(!empty($ready['progress']['completion_readiness']['ready'])&&(int)$ready['progress']['percent_complete']===100,'Mission progress reaches explainable 100% readiness only after stored success criteria are satisfied.');
$completed=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'completed');
rmv17(($completed['status']??'')==='completed'&&!empty($completed['completed_at']),'Mission completion remains an explicit human lifecycle action.');

$bound=research_mission_bind_program($pdo,$owner,(string)$mission['public_id'],['cadence'=>'daily','timezone_name'=>'UTC','run_time_local'=>'09:00','materiality_threshold'=>'important']);
rmv17(!empty($bound['program_public_id'])&&($bound['program']['status']??'')==='paused','Mission Watch reuses the existing Program scheduler and starts paused.');
$bound=research_mission_set_program_status($pdo,$owner,(string)$mission['public_id'],'active');$program=research_program_access($pdo,$owner,(string)$bound['program_public_id']);
$runPublic=research_program_enqueue($pdo,$program,(int)$owner['id'],'manual');$programRun=research_program_run_row($pdo,$owner,(string)$runPublic);
$pdo->prepare("UPDATE research_program_runs SET status='completed',material_change_count=1,summary='New release evidence materially affects the completed Mission.',completed_at=NOW() WHERE id=?")->execute([(int)$programRun['id']]);
research_mission_program_observe_run($pdo,$program,(int)$programRun['id'],'section7_final_acceptance');
$reactivated=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
rmv17(($reactivated['status']??'')==='active'&&empty($reactivated['completed_at']),'Material Program change reactivates a completed Mission without a duplicate scheduler.');

$agentCtx=research_mission_agent_context($pdo,$owner,(string)$agent['public_id'],6);
rmv17(str_contains((string)$agentCtx['text'],'Research Missions V1 Final Acceptance'),'Agent Chat context includes the reactivated Mission.');
$feed=[];research_mission_cognitive_observations($pdo,$owner,$feed,20);$missionCards=array_values(array_filter(array_values($feed),fn($x)=>str_starts_with((string)($x['type']??''),'research_mission_')));
rmv17(count($missionCards)>=1,'Now / Cognitive Feed surfaces current Mission attention state.');

$review=research_review_create($pdo,$owner,'mission',(string)$mission['public_id'],[(int)$reviewer['id']],null,'Verify the final Mission state after material change reactivation.');
research_review_respond($pdo,$reviewer,(string)$review['public_id'],'approve','The Mission state, evidence lineage, and reactivation history are visible.');
$review=research_review_access($pdo,$owner,(string)$review['public_id']);$aggregate=research_review_aggregate($pdo,$review);
rmv17(($aggregate['counts']['approve']??0)===1,'Existing Review Center records a Team review response for the Mission.');

$report=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'mission_review','Mission V1 Final Review Brief',false,null,['depth'=>'standard','focus_query'=>'Research Missions V1 Final Acceptance']);
rmv17(str_contains((string)$report['rendered_html'],'Mission review queue')&&!empty($report['knowledge_manifest']['missions']),'Report Studio deterministically renders Mission review state with a Mission manifest.');

$events=research_mission_events($pdo,(int)$reactivated['id'],200);$types=array_column($events,'event_type');
foreach(['mission_created','mission_plan_created','mission_execution_started','mission_ready_for_review','mission_status_changed','mission_program_bound','mission_program_status_changed','mission_material_change_reactivated'] as $type)
    rmv17(in_array($type,$types,true),'Mission history preserves '.$type.'.');
rmv17(count((array)$reactivated['versions'])>=2,'Mission keeps immutable configuration revisions through the final lifecycle.');

rmv17(research_mission_access($pdo,$outsider,(string)$mission['public_id'])===null,'Outsider cannot access Team Mission state.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,(int)$reviewer['id']]);
rmv17(research_mission_access($pdo,$reviewer,(string)$mission['public_id'])===null,'Team revocation immediately removes Mission access.');
rmv17(research_review_access($pdo,$reviewer,(string)$review['public_id'])===null,'Team revocation also removes access to the Mission review.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_plans WHERE id=?');$q->execute([$planId]);rmv17((int)$q->fetchColumn()===1,'Final Mission journey retains one authoritative execution Plan.');
echo "Research Missions V1 Section 7 end-to-end acceptance journey passed.\n";
