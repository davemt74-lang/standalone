<?php
declare(strict_types=1);

function research_programs_ready(PDO $pdo): bool {
    try{
        foreach(['research_programs','research_program_versions','research_program_runs','research_program_deltas','research_program_events'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return installer_table_exists($pdo,'research_task_plans');
    }catch(Throwable $e){return false;}
}

function research_program_cadences(): array {return ['hourly'=>'Hourly','daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','manual'=>'Manual only'];}
function research_program_materiality_levels(): array {return ['any'=>'Any change','important'=>'Important or high','high'=>'High only'];}
function research_program_quiet_modes(): array {return ['material_only'=>'Only produce a run when material changes','always'=>'Produce every scheduled run'];}
function research_program_catch_up_modes(): array {return ['latest'=>'Run the latest missed cycle','skip'=>'Skip stale missed cycles'];}

function research_program_agent(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_task_agent($pdo,$viewer,trim($agentPublic));if(!$agent)throw new RuntimeException('Research Agent is unavailable.');
    return $agent;
}

function research_program_project(PDO $pdo,array $viewer,array $agent): array {
    return research_task_project($pdo,$viewer,$agent);
}

function research_program_scope(array $program): array {
    $scope=json_decode((string)($program['scope_json']??''),true);if(!is_array($scope))$scope=[];
    foreach(['source_ids','claim_ids','entity_ids','watch_ids','topics'] as $key){$scope[$key]=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),(array)($scope[$key]??[])))));}
    $scope['include_annotations']=array_key_exists('include_annotations',$scope)?(bool)$scope['include_annotations']:true;
    $scope['include_workspace']=array_key_exists('include_workspace',$scope)?(bool)$scope['include_workspace']:true;
    return $scope;
}

function research_program_templates(array $program): array {
    $raw=json_decode((string)($program['task_template_json']??''),true);if(!is_array($raw))$raw=[];
    $out=[];foreach(array_slice($raw,0,30) as $item){if(!is_array($item))continue;$title=mb_substr(trim((string)($item['title']??'')),0,255);if($title==='')continue;$type=(string)($item['task_type']??'general');if(!isset(research_task_types()[$type]))$type='general';$priority=(string)($item['priority']??$program['priority']??'medium');if(!isset(research_task_priorities()[$priority]))$priority='medium';$deps=[];foreach(array_slice((array)($item['depends_on']??[]),0,12) as $dep)$deps[]=max(0,(int)$dep);$out[]=['title'=>$title,'description'=>mb_substr(trim((string)($item['description']??'')),0,12000),'task_type'=>$type,'priority'=>$priority,'depends_on'=>array_values(array_unique($deps))];}
    return $out;
}

function research_program_default_tasks(array $program): array {
    $priority=(string)($program['priority']??'medium');
    return [
      ['title'=>'Review material changes','description'=>'Review the structured delta from the previous Program run and verify the changes against the current Research evidence.','task_type'=>'review_source_change','priority'=>$priority,'depends_on'=>[]],
      ['title'=>'Synthesize implications','description'=>'Explain what the verified changes mean for the Program objective, including uncertainty, contradictions, and evidence gaps.','task_type'=>'synthesize','priority'=>$priority,'depends_on'=>[0]],
      ['title'=>'Draft recurring deliverable','description'=>'Update this cycle’s deliverable from the verified changes and cited Research evidence.','task_type'=>'draft_deliverable','priority'=>$priority,'depends_on'=>[1]],
    ];
}

function research_program_config_array(array $program): array {
    return [
      'title'=>(string)$program['title'],'objective'=>(string)$program['objective'],'priority'=>(string)$program['priority'],'cadence'=>(string)$program['cadence'],
      'timezone_name'=>(string)$program['timezone_name'],'run_time_local'=>(string)$program['run_time_local'],'weekday'=>$program['weekday']!==null?(int)$program['weekday']:null,'day_of_month'=>$program['day_of_month']!==null?(int)$program['day_of_month']:null,
      'quiet_mode'=>(string)$program['quiet_mode'],'materiality_threshold'=>(string)$program['materiality_threshold'],'catch_up_mode'=>(string)$program['catch_up_mode'],
      'max_concurrent_runs'=>(int)$program['max_concurrent_runs'],'monthly_run_limit'=>(int)$program['monthly_run_limit'],'token_budget_per_run'=>(int)$program['token_budget_per_run'],
      'max_tasks_per_run'=>(int)$program['max_tasks_per_run'],'plan_due_offset_hours'=>(int)$program['plan_due_offset_hours'],'deliverable_type'=>(string)$program['deliverable_type'],
      'plan_title_template'=>(string)$program['plan_title_template'],'deliverable_title_template'=>(string)$program['deliverable_title_template'],
      'task_template'=>research_program_templates($program),'scope'=>research_program_scope($program)
    ];
}

function research_program_config_hash(array $program): string {
    return hash('sha256',json_encode(research_program_config_array($program),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function research_program_event(PDO $pdo,int $programId,int $projectId,?int $runId,string $event,string $actor='system',?int $actorUserId=null,array $payload=[]): void {
    $actor=in_array($actor,['user','agent','system'],true)?$actor:'system';
    $pdo->prepare("INSERT INTO research_program_events(public_id,program_id,run_id,project_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?,?,?)")
      ->execute([ulid_like(),$programId,$runId,$projectId,mb_substr(trim($event),0,64),$actor,$actorUserId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function research_program_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_programs_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rp.*,ra.public_id agent_public_id,ra.name agent_name,ra.conversation_id,c.public_id conversation_public_id,proj.public_id project_public_id,proj.title project_title
      FROM research_programs rp JOIN research_agents ra ON ra.id=rp.research_agent_id JOIN research_projects proj ON proj.id=rp.project_id JOIN conversations c ON c.id=ra.conversation_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rp.public_id=? AND rp.status<>'archived' AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($publicId),(int)$viewer['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}

function research_program_by_id(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare("SELECT rp.*,ra.public_id agent_public_id,ra.name agent_name,ra.conversation_id,c.public_id conversation_public_id,proj.public_id project_public_id,proj.title project_title,proj.owner_user_id project_owner_user_id
      FROM research_programs rp JOIN research_agents ra ON ra.id=rp.research_agent_id JOIN research_projects proj ON proj.id=rp.project_id JOIN conversations c ON c.id=ra.conversation_id WHERE rp.id=? LIMIT 1");
    $q->execute([$id]);return $q->fetch()?:null;
}

function research_program_owner(PDO $pdo,array $program): array {
    $uid=(int)($program['project_owner_user_id']??$program['created_by_user_id']??0);$q=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$q->execute([$uid]);$viewer=$q->fetch();
    if(!$viewer)throw new RuntimeException('Research Program owner is unavailable.');return $viewer;
}

function research_program_next_run_values(string $cadence,string $timezoneName,string $runTime,?int $weekday,?int $dayOfMonth,?DateTimeImmutable $fromUtc=null): ?string {
    if($cadence==='manual')return null;
    if(in_array($cadence,['hourly','daily','weekly'],true))return research_automation_next_run($cadence,$timezoneName,$runTime,$weekday,$fromUtc);
    if($cadence!=='monthly')throw new InvalidArgumentException('Invalid Research Program cadence.');
    $utc=new DateTimeZone('UTC');$zone=research_automation_timezone($timezoneName);$from=($fromUtc?:new DateTimeImmutable('now',$utc))->setTimezone($utc);$local=$from->setTimezone($zone);$time=research_automation_time($runTime);[$h,$m,$s]=array_map('intval',explode(':',$time));$day=max(1,min(28,(int)($dayOfMonth??1)));
    $candidate=$local->setDate((int)$local->format('Y'),(int)$local->format('n'),$day)->setTime($h,$m,$s);if($candidate<=$local)$candidate=$candidate->modify('first day of next month')->setDate((int)$candidate->format('Y'),(int)$candidate->format('n'),$day)->setTime($h,$m,$s);
    return $candidate->setTimezone($utc)->format('Y-m-d H:i:s');
}

function research_program_next_run(array $program,?DateTimeImmutable $fromUtc=null): ?string {
    return research_program_next_run_values((string)$program['cadence'],(string)$program['timezone_name'],(string)$program['run_time_local'],$program['weekday']!==null?(int)$program['weekday']:null,$program['day_of_month']!==null?(int)$program['day_of_month']:null,$fromUtc);
}

function research_program_advance_to_future(array $program,string $scheduledFor): ?string {
    if((string)$program['cadence']==='manual')return null;$utc=new DateTimeZone('UTC');$cursor=new DateTimeImmutable($scheduledFor,$utc);$now=new DateTimeImmutable('now',$utc);$next=$scheduledFor;
    for($i=0;$i<400;$i++){$next=research_program_next_run($program,$cursor);if($next===null||new DateTimeImmutable($next,$utc)>$now)return $next;$cursor=new DateTimeImmutable($next,$utc);}
    throw new RuntimeException('Unable to advance Research Program schedule safely.');
}

function research_program_grace_seconds(string $cadence): int {
    return match($cadence){'hourly'=>7200,'daily'=>129600,'weekly'=>864000,'monthly'=>3456000,default=>0};
}

function research_program_clean_templates(array $input,string $priority): array {
    $tasks=is_array($input['tasks']??null)?$input['tasks']:(is_array($input['task_template']??null)?$input['task_template']:[]);$out=[];
    foreach(array_slice($tasks,0,30) as $raw){if(!is_array($raw))continue;$title=mb_substr(trim((string)($raw['title']??'')),0,255);if($title==='')continue;$type=(string)($raw['task_type']??'general');if(!isset(research_task_types()[$type]))$type='general';$p=(string)($raw['priority']??$priority);if(!isset(research_task_priorities()[$p]))$p=$priority;$deps=[];foreach(array_slice((array)($raw['depends_on']??[]),0,12) as $d)$deps[]=max(0,(int)$d);$out[]=['title'=>$title,'description'=>mb_substr(trim((string)($raw['description']??'')),0,12000),'task_type'=>$type,'priority'=>$p,'depends_on'=>array_values(array_unique($deps))];}
    return $out;
}

function research_program_clean_scope(array $input): array {
    $scope=is_array($input['scope']??null)?$input['scope']:[];
    foreach(['source_ids','claim_ids','entity_ids','watch_ids','topics'] as $key){if(isset($input[$key])&&!isset($scope[$key]))$scope[$key]=$input[$key];$scope[$key]=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),(array)($scope[$key]??[])))));}
    $scope['include_annotations']=array_key_exists('include_annotations',$scope)?(bool)$scope['include_annotations']:true;$scope['include_workspace']=array_key_exists('include_workspace',$scope)?(bool)$scope['include_workspace']:true;return $scope;
}

function research_program_snapshot_version(PDO $pdo,array $program,string $reason='',?int $userId=null,bool $byAgent=false): void {
    $pdo->prepare("INSERT INTO research_program_versions(public_id,program_id,revision_number,config_json,change_reason,edited_by_user_id,edited_by_agent) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$program['id'],(int)$program['current_revision'],json_encode(research_program_config_array($program),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$reason!==''?mb_substr($reason,0,1000):null,$userId,$byAgent?1:0]);
}

function research_program_create(PDO $pdo,array $viewer,array $input,bool $byAgent=false): array {
    if(!research_programs_ready($pdo))throw new RuntimeException('Research Programs require the latest database upgrade.');
    $agent=research_program_agent($pdo,$viewer,(string)($input['agent_id']??''));$project=research_program_project($pdo,$viewer,$agent);
    $q=$pdo->prepare("SELECT COUNT(*) FROM research_programs WHERE research_agent_id=? AND status<>'archived'");$q->execute([(int)$agent['id']]);if((int)$q->fetchColumn()>=25)throw new RuntimeException('A Research Agent can keep up to 25 active or paused Programs.');
    $title=mb_substr(trim((string)($input['title']??'')),0,255);$objective=mb_substr(trim((string)($input['objective']??'')),0,16000);if($title===''||$objective==='')throw new InvalidArgumentException('Program title and objective are required.');
    $priority=(string)($input['priority']??'medium');if(!isset(research_task_priorities()[$priority]))$priority='medium';
    $cadence=(string)($input['cadence']??'weekly');if(!isset(research_program_cadences()[$cadence]))$cadence='weekly';$timezone=trim((string)($input['timezone_name']??'UTC'));research_automation_timezone($timezone);$time=research_automation_time((string)($input['run_time_local']??'09:00'));
    $weekday=$cadence==='weekly'?max(0,min(6,(int)($input['weekday']??1))):null;$day=$cadence==='monthly'?max(1,min(28,(int)($input['day_of_month']??1))):null;
    $quiet=(string)($input['quiet_mode']??'material_only');if(!isset(research_program_quiet_modes()[$quiet]))$quiet='material_only';$threshold=(string)($input['materiality_threshold']??'important');if(!isset(research_program_materiality_levels()[$threshold]))$threshold='important';$catch=(string)($input['catch_up_mode']??'latest');if(!isset(research_program_catch_up_modes()[$catch]))$catch='latest';
    $concurrency=max(1,min(3,(int)($input['max_concurrent_runs']??1)));$monthly=max(1,min(1000,(int)($input['monthly_run_limit']??31)));$tokenBudget=max(1000,min(2000000,(int)($input['token_budget_per_run']??60000)));$maxTasks=max(1,min(30,(int)($input['max_tasks_per_run']??12)));$dueOffset=max(1,min(720,(int)($input['plan_due_offset_hours']??72)));
    $deliverable=(string)($input['deliverable_type']??'weekly_report');if(!isset(research_task_deliverable_types()[$deliverable]))$deliverable='weekly_report';$planTpl=mb_substr(trim((string)($input['plan_title_template']??'{program} · {date}')),0,255);$deliverableTpl=mb_substr(trim((string)($input['deliverable_title_template']??'{program} · {date}')),0,255);if($planTpl==='')$planTpl='{program} · {date}';if($deliverableTpl==='')$deliverableTpl='{program} · {date}';
    $tasks=research_program_clean_templates($input,$priority);$scope=research_program_clean_scope($input);$public=ulid_like();$next=research_program_next_run_values($cadence,$timezone,$time,$weekday,$day);
    $draft=['title'=>$title,'objective'=>$objective,'priority'=>$priority,'cadence'=>$cadence,'timezone_name'=>$timezone,'run_time_local'=>$time,'weekday'=>$weekday,'day_of_month'=>$day,'quiet_mode'=>$quiet,'materiality_threshold'=>$threshold,'catch_up_mode'=>$catch,'max_concurrent_runs'=>$concurrency,'monthly_run_limit'=>$monthly,'token_budget_per_run'=>$tokenBudget,'max_tasks_per_run'=>$maxTasks,'plan_due_offset_hours'=>$dueOffset,'deliverable_type'=>$deliverable,'plan_title_template'=>$planTpl,'deliverable_title_template'=>$deliverableTpl,'task_template_json'=>json_encode($tasks,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'scope_json'=>json_encode($scope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)];
    $hash=hash('sha256',json_encode($draft,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $pdo->prepare("INSERT INTO research_programs(public_id,research_agent_id,project_id,created_by_user_id,title,objective,status,priority,cadence,timezone_name,run_time_local,weekday,day_of_month,quiet_mode,materiality_threshold,catch_up_mode,max_concurrent_runs,monthly_run_limit,token_budget_per_run,max_tasks_per_run,plan_due_offset_hours,deliverable_type,plan_title_template,deliverable_title_template,task_template_json,scope_json,current_revision,config_hash,next_run_at)
      VALUES(?,?,?,?,?,?,'active',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)")
      ->execute([$public,(int)$agent['id'],(int)$project['id'],(int)$viewer['id'],$title,$objective,$priority,$cadence,$timezone,$time,$weekday,$day,$quiet,$threshold,$catch,$concurrency,$monthly,$tokenBudget,$maxTasks,$dueOffset,$deliverable,$planTpl,$deliverableTpl,$draft['task_template_json'],$draft['scope_json'],$hash,$next]);
    $program=research_program_access($pdo,$viewer,$public);if(!$program)throw new RuntimeException('Research Program could not be created.');$canonicalHash=research_program_config_hash($program);if(!hash_equals((string)$program['config_hash'],$canonicalHash)){$pdo->prepare("UPDATE research_programs SET config_hash=? WHERE id=?")->execute([$canonicalHash,(int)$program['id']]);$program['config_hash']=$canonicalHash;}research_program_snapshot_version($pdo,$program,'Initial Program',(int)$viewer['id'],$byAgent);research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],null,'created',$byAgent?'agent':'user',(int)$viewer['id'],['cadence'=>$cadence,'next_run_at'=>$next]);return $program;
}

function research_program_update(PDO $pdo,array $viewer,string $publicId,array $input,bool $byAgent=false): array {
    $program=research_program_access($pdo,$viewer,$publicId);if(!$program)throw new RuntimeException('Research Program not found.');$agent=research_program_agent($pdo,$viewer,(string)$program['agent_public_id']);research_program_project($pdo,$viewer,$agent);
    $merged=$input;$merged['agent_id']=$program['agent_public_id'];foreach(['title','objective','priority','cadence','timezone_name','run_time_local','weekday','day_of_month','quiet_mode','materiality_threshold','catch_up_mode','max_concurrent_runs','monthly_run_limit','token_budget_per_run','max_tasks_per_run','plan_due_offset_hours','deliverable_type','plan_title_template','deliverable_title_template'] as $key)if(!array_key_exists($key,$merged))$merged[$key]=$program[$key];
    if(!array_key_exists('tasks',$merged))$merged['tasks']=research_program_templates($program);if(!array_key_exists('scope',$merged))$merged['scope']=research_program_scope($program);
    $title=mb_substr(trim((string)$merged['title']),0,255);$objective=mb_substr(trim((string)$merged['objective']),0,16000);if($title===''||$objective==='')throw new InvalidArgumentException('Program title and objective are required.');
    $priority=(string)$merged['priority'];if(!isset(research_task_priorities()[$priority]))$priority=(string)$program['priority'];$cadence=(string)$merged['cadence'];if(!isset(research_program_cadences()[$cadence]))$cadence=(string)$program['cadence'];$timezone=trim((string)$merged['timezone_name']);research_automation_timezone($timezone);$time=research_automation_time((string)$merged['run_time_local']);$weekday=$cadence==='weekly'?max(0,min(6,(int)$merged['weekday'])):null;$day=$cadence==='monthly'?max(1,min(28,(int)$merged['day_of_month'])):null;
    $quiet=(string)$merged['quiet_mode'];if(!isset(research_program_quiet_modes()[$quiet]))$quiet=(string)$program['quiet_mode'];$threshold=(string)$merged['materiality_threshold'];if(!isset(research_program_materiality_levels()[$threshold]))$threshold=(string)$program['materiality_threshold'];$catch=(string)$merged['catch_up_mode'];if(!isset(research_program_catch_up_modes()[$catch]))$catch=(string)$program['catch_up_mode'];
    $concurrency=max(1,min(3,(int)$merged['max_concurrent_runs']));$monthly=max(1,min(1000,(int)$merged['monthly_run_limit']));$tokenBudget=max(1000,min(2000000,(int)$merged['token_budget_per_run']));$maxTasks=max(1,min(30,(int)$merged['max_tasks_per_run']));$dueOffset=max(1,min(720,(int)$merged['plan_due_offset_hours']));$deliverable=(string)$merged['deliverable_type'];if(!isset(research_task_deliverable_types()[$deliverable]))$deliverable=(string)$program['deliverable_type'];$planTpl=mb_substr(trim((string)$merged['plan_title_template']),0,255);$deliverableTpl=mb_substr(trim((string)$merged['deliverable_title_template']),0,255);
    $tasks=research_program_clean_templates($merged,$priority);$scope=research_program_clean_scope($merged);$config=['title'=>$title,'objective'=>$objective,'priority'=>$priority,'cadence'=>$cadence,'timezone_name'=>$timezone,'run_time_local'=>$time,'weekday'=>$weekday,'day_of_month'=>$day,'quiet_mode'=>$quiet,'materiality_threshold'=>$threshold,'catch_up_mode'=>$catch,'max_concurrent_runs'=>$concurrency,'monthly_run_limit'=>$monthly,'token_budget_per_run'=>$tokenBudget,'max_tasks_per_run'=>$maxTasks,'plan_due_offset_hours'=>$dueOffset,'deliverable_type'=>$deliverable,'plan_title_template'=>$planTpl,'deliverable_title_template'=>$deliverableTpl,'task_template'=>$tasks,'scope'=>$scope];$hash=hash('sha256',json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));if(hash_equals((string)$program['config_hash'],$hash))return $program;
    $next=$cadence==='manual'?null:research_program_next_run_values($cadence,$timezone,$time,$weekday,$day);$revision=(int)$program['current_revision']+1;
    $pdo->prepare("UPDATE research_programs SET title=?,objective=?,priority=?,cadence=?,timezone_name=?,run_time_local=?,weekday=?,day_of_month=?,quiet_mode=?,materiality_threshold=?,catch_up_mode=?,max_concurrent_runs=?,monthly_run_limit=?,token_budget_per_run=?,max_tasks_per_run=?,plan_due_offset_hours=?,deliverable_type=?,plan_title_template=?,deliverable_title_template=?,task_template_json=?,scope_json=?,current_revision=?,config_hash=?,next_run_at=?,updated_at=NOW() WHERE id=?")
      ->execute([$title,$objective,$priority,$cadence,$timezone,$time,$weekday,$day,$quiet,$threshold,$catch,$concurrency,$monthly,$tokenBudget,$maxTasks,$dueOffset,$deliverable,$planTpl,$deliverableTpl,json_encode($tasks,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($scope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$revision,$hash,$next,(int)$program['id']]);
    $fresh=research_program_access($pdo,$viewer,$publicId);research_program_snapshot_version($pdo,$fresh,mb_substr(trim((string)($input['reason']??'Program updated')),0,1000),(int)$viewer['id'],$byAgent);research_program_event($pdo,(int)$fresh['id'],(int)$fresh['project_id'],null,'revised',$byAgent?'agent':'user',(int)$viewer['id'],['revision'=>$revision]);return $fresh;
}

function research_program_set_status(PDO $pdo,array $viewer,string $publicId,string $status): array {
    $program=research_program_access($pdo,$viewer,$publicId);if(!$program)throw new RuntimeException('Research Program not found.');$agent=research_program_agent($pdo,$viewer,(string)$program['agent_public_id']);research_program_project($pdo,$viewer,$agent);if(!in_array($status,['active','paused','archived'],true))throw new InvalidArgumentException('Invalid Program status.');
    $next=$status==='active'?research_program_next_run($program):null;$pdo->prepare("UPDATE research_programs SET status=?,next_run_at=?,updated_at=NOW() WHERE id=?")->execute([$status,$next,(int)$program['id']]);
    if($status!=='active')$pdo->prepare("UPDATE research_program_runs SET status='skipped',claim_token=NULL,lease_expires_at=NULL,quiet_suppressed=1,summary='Program paused or archived before execution.',completed_at=NOW() WHERE program_id=? AND status='queued'")->execute([(int)$program['id']]);
    research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],null,$status==='active'?'resumed':$status,'user',(int)$viewer['id']);if($status==='archived')return array_merge($program,['status'=>'archived','next_run_at'=>null]);return research_program_access($pdo,$viewer,$publicId)??$program;
}

function research_program_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=100): array {
    $agent=research_program_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT rp.*,
      (SELECT COUNT(*) FROM research_program_runs rpr WHERE rpr.program_id=rp.id AND rpr.status IN ('processing','active')) active_runs,
      (SELECT COUNT(*) FROM research_program_runs rpr WHERE rpr.program_id=rp.id AND rpr.status='failed') failed_runs,
      (SELECT COUNT(*) FROM research_program_runs rpr WHERE rpr.program_id=rp.id AND rpr.status='completed') completed_runs,
      (SELECT COUNT(*) FROM research_program_runs rpr WHERE rpr.program_id=rp.id AND rpr.status='skipped' AND rpr.quiet_suppressed=1) quiet_runs,
      (SELECT rpr.public_id FROM research_program_runs rpr WHERE rpr.program_id=rp.id ORDER BY rpr.id DESC LIMIT 1) latest_run_public_id
      FROM research_programs rp WHERE rp.research_agent_id=? AND rp.status<>'archived' ORDER BY FIELD(rp.status,'active','paused'),rp.updated_at DESC LIMIT ".$limit);$q->execute([(int)$agent['id']]);return $q->fetchAll()?:[];
}

function research_program_run_row(PDO $pdo,array $viewer,string $runPublic): ?array {
    $q=$pdo->prepare("SELECT rpr.*,rp.public_id program_public_id,rp.title program_title,ra.public_id agent_public_id,ra.name agent_name,proj.public_id project_public_id,rtp.public_id plan_public_id,rtp.title plan_title,rtp.status plan_status,rtd.status deliverable_status,rwo.public_id document_public_id,rwo.title document_title
      FROM research_program_runs rpr JOIN research_programs rp ON rp.id=rpr.program_id JOIN research_agents ra ON ra.id=rpr.research_agent_id JOIN research_projects proj ON proj.id=rpr.project_id
      LEFT JOIN research_task_plans rtp ON rtp.program_run_id=rpr.id LEFT JOIN research_task_deliverables rtd ON rtd.plan_id=rtp.id LEFT JOIN research_workspace_objects rwo ON rwo.id=rtd.workspace_object_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rpr.public_id=? AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($runPublic),(int)$viewer['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}

function research_program_run_list(PDO $pdo,array $viewer,string $programPublic,int $limit=50): array {
    $program=research_program_access($pdo,$viewer,$programPublic);if(!$program)return [];$limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT public_id FROM research_program_runs WHERE program_id=? ORDER BY id DESC LIMIT ".$limit);$q->execute([(int)$program['id']]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_program_run_row($pdo,$viewer,(string)$id);if($row)$out[]=$row;}return $out;
}

function research_program_scope_allows(array $scope,string $type,string $publicId): bool {
    $map=['source'=>'source_ids','claim'=>'claim_ids','entity'=>'entity_ids','watch'=>'watch_ids'];if(isset($map[$type])&&!empty($scope[$map[$type]]))return in_array($publicId,$scope[$map[$type]],true);
    if($type==='annotation')return !empty($scope['include_annotations']);if($type==='workspace')return !empty($scope['include_workspace']);return true;
}

function research_program_snapshot(PDO $pdo,array $program): array {
    $scope=research_program_scope($program);$projectId=(int)$program['project_id'];$snapshot=['sources'=>[],'claims'=>[],'contradictions'=>[],'entities'=>[],'annotations'=>[],'workspace'=>[],'monitor_max_id'=>0,'captured_at'=>gmdate('Y-m-d H:i:s')];
    $q=$pdo->prepare("SELECT s.public_id,s.status,s.current_version_id,COALESCE(sv.content_hash,'') content_hash,COALESCE(sv.title,s.domain,'') title,s.domain FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE ps.project_id=? ORDER BY s.id");$q->execute([$projectId]);foreach($q->fetchAll() as $r){if(!research_program_scope_allows($scope,'source',(string)$r['public_id']))continue;$snapshot['sources'][(string)$r['public_id']]=['status'=>(string)$r['status'],'version_id'=>(int)($r['current_version_id']??0),'content_hash'=>(string)$r['content_hash'],'title'=>(string)$r['title'],'domain'=>(string)$r['domain']];}
    $q=$pdo->prepare("SELECT rc.public_id,rc.status,rc.statement,COUNT(ce.id) evidence_count,COALESCE(MAX(ce.id),0) evidence_revision FROM research_claims rc LEFT JOIN claim_evidence ce ON ce.claim_id=rc.id WHERE rc.project_id=? GROUP BY rc.id ORDER BY rc.id");$q->execute([$projectId]);foreach($q->fetchAll() as $r){if(!research_program_scope_allows($scope,'claim',(string)$r['public_id']))continue;$snapshot['claims'][(string)$r['public_id']]=['status'=>(string)$r['status'],'statement_hash'=>hash('sha256',(string)$r['statement']),'evidence_count'=>(int)$r['evidence_count'],'evidence_revision'=>(int)$r['evidence_revision']];}
    if(installer_table_exists($pdo,'research_autonomy_observations')){$q=$pdo->prepare("SELECT public_id,fingerprint,severity,status,title,subject_public_id FROM research_autonomy_observations WHERE project_id=? AND observation_type='contradiction' ORDER BY id");$q->execute([$projectId]);foreach($q->fetchAll() as $r){$subject=(string)($r['subject_public_id']??'');if($subject!==''&&!research_program_scope_allows($scope,'claim',$subject))continue;$snapshot['contradictions'][(string)$r['fingerprint']]=['public_id'=>(string)$r['public_id'],'severity'=>(string)$r['severity'],'status'=>(string)$r['status'],'title'=>(string)$r['title'],'subject_public_id'=>$subject];}}
    if(installer_table_exists($pdo,'research_entities')){$q=$pdo->prepare("SELECT public_id,status,entity_type,canonical_name FROM research_entities WHERE project_id=? AND status<>'archived' ORDER BY id");$q->execute([$projectId]);foreach($q->fetchAll() as $r){if(!research_program_scope_allows($scope,'entity',(string)$r['public_id']))continue;$snapshot['entities'][(string)$r['public_id']]=['status'=>(string)$r['status'],'type'=>(string)$r['entity_type'],'name'=>(string)$r['canonical_name']];}}
    if(!empty($scope['include_annotations'])){$q=$pdo->prepare("SELECT a.public_id,a.updated_at FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=? ORDER BY a.id");$q->execute([$projectId]);foreach($q->fetchAll() as $r)$snapshot['annotations'][(string)$r['public_id']]=(string)$r['updated_at'];}
    if(!empty($scope['include_workspace'])&&installer_table_exists($pdo,'research_workspace_objects')){$q=$pdo->prepare("SELECT rwo.public_id,rwo.object_type,rwo.status,rwo.updated_at FROM research_workspace_objects rwo WHERE rwo.project_id=? AND rwo.status='active' AND NOT EXISTS(SELECT 1 FROM research_task_deliverables rtd WHERE rtd.workspace_object_id=rwo.id) ORDER BY rwo.id");$q->execute([$projectId]);foreach($q->fetchAll() as $r)$snapshot['workspace'][(string)$r['public_id']]=['type'=>(string)$r['object_type'],'updated_at'=>(string)$r['updated_at']];}
    if(installer_table_exists($pdo,'research_monitor_events')){$params=[$projectId];$filter='';if(!empty($scope['watch_ids'])){$ph=implode(',',array_fill(0,count($scope['watch_ids']),'?'));$filter=" AND EXISTS(SELECT 1 FROM research_monitor_watches rmw WHERE rmw.id=rme.watch_id AND rmw.public_id IN ($ph))";$params=array_merge($params,$scope['watch_ids']);}$q=$pdo->prepare("SELECT COALESCE(MAX(rme.id),0) FROM research_monitor_events rme WHERE rme.project_id=?".$filter);$q->execute($params);$snapshot['monitor_max_id']=(int)$q->fetchColumn();}
    ksort($snapshot['sources']);ksort($snapshot['claims']);ksort($snapshot['contradictions']);ksort($snapshot['entities']);ksort($snapshot['annotations']);ksort($snapshot['workspace']);return $snapshot;
}

function research_program_previous_snapshot(PDO $pdo,int $programId,int $beforeRunId): ?array {
    $q=$pdo->prepare("SELECT id,input_snapshot_json FROM research_program_runs WHERE program_id=? AND id<? AND input_snapshot_json IS NOT NULL AND status IN ('active','completed','skipped') ORDER BY id DESC LIMIT 1");$q->execute([$programId,$beforeRunId]);$row=$q->fetch();if(!$row)return null;$snapshot=json_decode((string)$row['input_snapshot_json'],true);return is_array($snapshot)?['run_id'=>(int)$row['id'],'snapshot'=>$snapshot]:null;
}

function research_program_delta_importance(string $type,array $before=[],array $after=[]): string {
    if(in_array($type,['source_unavailable','claim_contradicted','contradiction_opened'],true))return 'high';
    if(in_array($type,['source_added','source_changed','source_restored','claim_strengthened','claim_weakened','claim_resolved','contradiction_resolved','entity_added','monitor_event','baseline'],true))return 'important';
    return 'info';
}

function research_program_add_delta(PDO $pdo,array $program,int $runId,string $type,string $summary,?string $refType=null,?string $refPublic=null,array $before=[],array $after=[],?string $importance=null): array {
    $importance=$importance?:research_program_delta_importance($type,$before,$after);$fingerprint=hash('sha256',implode('|',[$type,$refType??'',$refPublic??'',json_encode($before,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]));$public=ulid_like();
    $pdo->prepare("INSERT IGNORE INTO research_program_deltas(public_id,run_id,program_id,project_id,delta_type,importance,ref_type,ref_public_id,fingerprint,summary,before_json,after_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$public,$runId,(int)$program['id'],(int)$program['project_id'],$type,$importance,$refType,$refPublic,$fingerprint,mb_substr($summary,0,12000),$before?json_encode($before,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$after?json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
    return ['type'=>$type,'importance'=>$importance,'ref_type'=>$refType,'ref_public_id'=>$refPublic,'fingerprint'=>$fingerprint,'summary'=>$summary];
}

function research_program_compare_snapshots(PDO $pdo,array $program,array $current,?array $previous,int $runId): array {
    $deltas=[];$scope=research_program_scope($program);
    if($previous===null){$deltas[]=research_program_add_delta($pdo,$program,$runId,'baseline','Initial Program baseline captured for '.$program['title'].'.',null,null,[],['captured_at'=>$current['captured_at']],'important');return $deltas;}
    $prev=$previous['snapshot'];
    foreach($current['sources'] as $id=>$after){$before=$prev['sources'][$id]??null;if($before===null){$deltas[]=research_program_add_delta($pdo,$program,$runId,'source_added','New Research source: '.($after['title']?:$after['domain']), 'source',$id,[],$after);continue;}if(($before['status']??'')!==($after['status']??'')){if(($after['status']??'')==='unavailable')$type='source_unavailable';elseif(($before['status']??'')==='unavailable')$type='source_restored';else $type='source_changed';$deltas[]=research_program_add_delta($pdo,$program,$runId,$type,'Source status changed: '.($after['title']?:$after['domain']),'source',$id,$before,$after);continue;}if((int)($before['version_id']??0)!==(int)($after['version_id']??0)||!hash_equals((string)($before['content_hash']??''),(string)($after['content_hash']??'')))$deltas[]=research_program_add_delta($pdo,$program,$runId,'source_changed','Source content changed: '.($after['title']?:$after['domain']),'source',$id,$before,$after);}
    foreach($current['claims'] as $id=>$after){$before=$prev['claims'][$id]??null;if($before===null){$deltas[]=research_program_add_delta($pdo,$program,$runId,'claim_added','New Research Claim entered the Program scope.','claim',$id,[],$after,'info');continue;}if(($before['status']??'')!==($after['status']??'')){$status=(string)$after['status'];$type=match($status){'supported'=>'claim_strengthened','contradicted'=>'claim_contradicted','disputed'=>'claim_weakened','resolved'=>'claim_resolved',default=>'claim_status_changed'};$deltas[]=research_program_add_delta($pdo,$program,$runId,$type,'Claim status changed from '.($before['status']??'unknown').' to '.$status.'.','claim',$id,$before,$after);}elseif((int)($before['evidence_revision']??0)!==(int)($after['evidence_revision']??0))$deltas[]=research_program_add_delta($pdo,$program,$runId,'claim_status_changed','Claim evidence changed without changing the saved Claim status.','claim',$id,$before,$after,'info');}
    foreach($current['contradictions'] as $fp=>$after){$before=$prev['contradictions'][$fp]??null;if(($after['status']??'')==='open'&&($before===null||($before['status']??'')!=='open'))$deltas[]=research_program_add_delta($pdo,$program,$runId,'contradiction_opened','Contradiction opened: '.$after['title'],'claim',(string)($after['subject_public_id']??''),$before?:[],$after);}
    foreach((array)($prev['contradictions']??[]) as $fp=>$before){$after=$current['contradictions'][$fp]??null;if(($before['status']??'')==='open'&&($after===null||($after['status']??'')!=='open'))$deltas[]=research_program_add_delta($pdo,$program,$runId,'contradiction_resolved','Contradiction resolved: '.$before['title'],'claim',(string)($before['subject_public_id']??''),$before,$after?:[]);}
    foreach($current['entities'] as $id=>$after)if(!isset($prev['entities'][$id]))$deltas[]=research_program_add_delta($pdo,$program,$runId,'entity_added','New Research entity: '.$after['name'],'entity',$id,[],$after,'important');
    foreach($current['annotations'] as $id=>$updated)if(!isset($prev['annotations'][$id]))$deltas[]=research_program_add_delta($pdo,$program,$runId,'annotation_added','New annotation added to the Research project.','annotation',$id,[],['updated_at'=>$updated],'info');
    foreach($current['workspace'] as $id=>$after){$before=$prev['workspace'][$id]??null;if($before===null||($before['updated_at']??'')!==($after['updated_at']??''))$deltas[]=research_program_add_delta($pdo,$program,$runId,'workspace_changed',($before===null?'New':'Updated').' Research workspace '.$after['type'].'.','workspace',$id,$before?:[],$after,'info');}
    $previousMonitor=(int)($prev['monitor_max_id']??0);$currentMonitor=(int)($current['monitor_max_id']??0);if($currentMonitor>$previousMonitor&&installer_table_exists($pdo,'research_monitor_events')){$params=[(int)$program['project_id'],$previousMonitor,$currentMonitor];$filter='';if(!empty($scope['watch_ids'])){$ph=implode(',',array_fill(0,count($scope['watch_ids']),'?'));$filter=" AND EXISTS(SELECT 1 FROM research_monitor_watches rmw WHERE rmw.id=rme.watch_id AND rmw.public_id IN ($ph))";$params=array_merge($params,$scope['watch_ids']);}$q=$pdo->prepare("SELECT rme.public_id,rme.importance,rme.event_type,rme.summary FROM research_monitor_events rme WHERE rme.project_id=? AND rme.id>? AND rme.id<=?".$filter." ORDER BY rme.id");$q->execute($params);foreach($q->fetchAll() as $e)$deltas[]=research_program_add_delta($pdo,$program,$runId,'monitor_event',(string)$e['summary'],'monitor_event',(string)$e['public_id'],[],['event_type'=>$e['event_type']],(string)$e['importance']);}
    return $deltas;
}

function research_program_material_deltas(array $program,array $deltas): array {
    $threshold=match((string)$program['materiality_threshold']){'high'=>3,'important'=>2,default=>1};$rank=['info'=>1,'important'=>2,'high'=>3];return array_values(array_filter($deltas,fn($d)=>(int)($rank[$d['importance']]??1)>=$threshold));
}

function research_program_memory(PDO $pdo,array $program,int $limit=12): array {
    $limit=max(1,min(50,$limit));$q=$pdo->prepare("SELECT id,status,material_change_count,quiet_suppressed,tokens_used,completed_at,created_at FROM research_program_runs WHERE program_id=? AND status IN ('completed','skipped','failed') ORDER BY id DESC LIMIT ".$limit);$q->execute([(int)$program['id']]);$runs=$q->fetchAll()?:[];
    $q=$pdo->prepare("SELECT delta_type,COUNT(*) total FROM research_program_deltas WHERE program_id=? GROUP BY delta_type ORDER BY total DESC,delta_type LIMIT 12");$q->execute([(int)$program['id']]);$deltaTypes=[];foreach($q->fetchAll() as $r)$deltaTypes[$r['delta_type']]=(int)$r['total'];
    $q=$pdo->prepare("SELECT ref_public_id,COUNT(*) total FROM research_program_deltas WHERE program_id=? AND ref_type='source' AND ref_public_id IS NOT NULL GROUP BY ref_public_id ORDER BY total DESC LIMIT 8");$q->execute([(int)$program['id']]);$sources=[];foreach($q->fetchAll() as $r)$sources[$r['ref_public_id']]=(int)$r['total'];
    $q=$pdo->prepare("SELECT rt.status,COUNT(*) total FROM research_task_plans rtp JOIN research_tasks rt ON rt.plan_id=rtp.id JOIN research_program_runs rpr ON rpr.id=rtp.program_run_id WHERE rpr.program_id=? GROUP BY rt.status");$q->execute([(int)$program['id']]);$taskStates=[];foreach($q->fetchAll() as $r)$taskStates[$r['status']]=(int)$r['total'];
    $q=$pdo->prepare("SELECT rt.title,rt.execution_summary,rt.blocking_reason FROM research_task_plans rtp JOIN research_tasks rt ON rt.plan_id=rtp.id JOIN research_program_runs rpr ON rpr.id=rtp.program_run_id WHERE rpr.program_id=? AND (rt.execution_summary IS NOT NULL OR rt.blocking_reason IS NOT NULL) ORDER BY rt.updated_at DESC LIMIT 5");$q->execute([(int)$program['id']]);$recentTaskNotes=$q->fetchAll()?:[];
    $completed=count(array_filter($runs,fn($r)=>$r['status']==='completed'));$quiet=count(array_filter($runs,fn($r)=>$r['status']==='skipped'&&(int)$r['quiet_suppressed']===1));$failed=count(array_filter($runs,fn($r)=>$r['status']==='failed'));$tokens=array_sum(array_map(fn($r)=>(int)$r['tokens_used'],$runs));
    $parts=["Prior runs: ".count($runs)." (completed $completed, quiet $quiet, failed $failed).","Recent token use: $tokens."];
    if($deltaTypes)$parts[]='Recurring change types: '.implode(', ',array_map(fn($k,$v)=>$k.' '.$v,array_keys($deltaTypes),array_values($deltaTypes))).'.';
    if($sources)$parts[]='Frequently changing source IDs: '.implode(', ',array_map(fn($k,$v)=>$k.' '.$v,array_keys($sources),array_values($sources))).'.';
    if($taskStates)$parts[]='Historical task states: '.implode(', ',array_map(fn($k,$v)=>$k.' '.$v,array_keys($taskStates),array_values($taskStates))).'.';
    if($recentTaskNotes){$notes=[];foreach($recentTaskNotes as $n){$detail=trim((string)($n['blocking_reason']?:$n['execution_summary']));if($detail!=='')$notes[]=(string)$n['title'].': '.mb_substr($detail,0,220);}if($notes)$parts[]='Recent run lessons: '.implode(' | ',$notes).'.';}
    return ['runs'=>$runs,'delta_types'=>$deltaTypes,'recurring_sources'=>$sources,'task_states'=>$taskStates,'recent_task_notes'=>$recentTaskNotes,'text'=>implode(' ',$parts)];
}

function research_program_template_text(string $template,array $program,int $sequence,?string $when=null): string {
    $zone=research_automation_timezone((string)$program['timezone_name']);$utc=new DateTimeZone('UTC');$dt=$when?new DateTimeImmutable($when,$utc):new DateTimeImmutable('now',$utc);$date=$dt->setTimezone($zone)->format('M j, Y');return strtr($template,['{program}'=>(string)$program['title'],'{date}'=>$date,'{sequence}'=>(string)$sequence,'{agent}'=>(string)$program['agent_name']]);
}

function research_program_delta_summary(array $deltas,int $limit=16): string {
    if(!$deltas)return 'No material Research changes were detected.';$lines=[];foreach(array_slice($deltas,0,$limit) as $d)$lines[]='- '.strtoupper((string)$d['importance']).' · '.str_replace('_',' ',(string)$d['type']).' · '.(string)$d['summary'];if(count($deltas)>$limit)$lines[]='- '.(count($deltas)-$limit).' additional change(s).';return implode("\n",$lines);
}

function research_program_active_count(PDO $pdo,int $programId): int {
    $q=$pdo->prepare("SELECT COUNT(*) FROM research_program_runs WHERE program_id=? AND status IN ('processing','active')");$q->execute([$programId]);return (int)$q->fetchColumn();
}

function research_program_month_count(PDO $pdo,int $programId): int {
    $q=$pdo->prepare("SELECT COUNT(*) FROM research_program_runs WHERE program_id=? AND created_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01 00:00:00') AND status<>'failed'");$q->execute([$programId]);return (int)$q->fetchColumn();
}

function research_program_enqueue(PDO $pdo,array $program,?int $requestedByUserId,string $trigger,?string $scheduledFor=null): ?string {
    if(!in_array($trigger,['schedule','manual','catch_up','recovery'],true))throw new InvalidArgumentException('Invalid Research Program trigger.');
    if($trigger!=='manual'&&$program['status']!=='active')return null;if(research_program_active_count($pdo,(int)$program['id'])>=(int)$program['max_concurrent_runs'])return null;if(research_program_month_count($pdo,(int)$program['id'])>=(int)$program['monthly_run_limit'])return null;
    $slot=$scheduledFor?:gmdate('Y-m-d H:i:s');$triggerKey=hash('sha256',(int)$program['id'].'|'.$trigger.'|'.$slot.($trigger==='manual'?'|'.ulid_like():''));$public=ulid_like();
    $q=$pdo->prepare("SELECT id FROM research_program_runs WHERE program_id=? AND input_snapshot_json IS NOT NULL ORDER BY id DESC LIMIT 1");$q->execute([(int)$program['id']]);$previous=(int)($q->fetchColumn()?:0);
    $pdo->prepare("INSERT IGNORE INTO research_program_runs(public_id,program_id,research_agent_id,project_id,requested_by_user_id,previous_run_id,trigger_type,trigger_key,scheduled_for,available_at,status) VALUES(?,?,?,?,?,?,?,?,?,NOW(),'queued')")
      ->execute([$public,(int)$program['id'],(int)$program['research_agent_id'],(int)$program['project_id'],$requestedByUserId,$previous?:null,$trigger,$triggerKey,$scheduledFor]);
    if(!$pdo->lastInsertId())return null;research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],(int)$pdo->lastInsertId(),'run_queued',$requestedByUserId?'user':'system',$requestedByUserId,['trigger'=>$trigger,'scheduled_for'=>$scheduledFor]);return $public;
}

function research_program_enqueue_due(PDO $pdo,int $limit=100): int {
    if(!research_programs_ready($pdo))return 0;$limit=max(1,min(500,$limit));$q=$pdo->query("SELECT id FROM research_programs WHERE status='active' AND cadence<>'manual' AND next_run_at IS NOT NULL AND next_run_at<=NOW() ORDER BY next_run_at,id LIMIT ".$limit);$count=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$program=research_program_by_id($pdo,(int)$id);if(!$program)continue;$scheduled=(string)$program['next_run_at'];if(research_program_active_count($pdo,(int)$program['id'])>=(int)$program['max_concurrent_runs'])continue;
        if(research_program_month_count($pdo,(int)$program['id'])>=(int)$program['monthly_run_limit']){$zone=research_automation_timezone((string)$program['timezone_name']);$utc=new DateTimeZone('UTC');$endLocal=(new DateTimeImmutable('last day of this month',$zone))->setTime(23,59,59);$next=research_program_next_run($program,$endLocal->setTimezone($utc));$pdo->prepare("UPDATE research_programs SET next_run_at=?,updated_at=NOW() WHERE id=?")->execute([$next,(int)$program['id']]);research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],null,'monthly_limit_deferred','system',null,['monthly_run_limit'=>(int)$program['monthly_run_limit'],'next_run_at'=>$next]);continue;}
        $age=time()-(strtotime($scheduled)?:time());$stale=$age>research_program_grace_seconds((string)$program['cadence']);$next=research_program_advance_to_future($program,$scheduled);
        if($stale&&$program['catch_up_mode']==='skip'){$pdo->prepare("UPDATE research_programs SET next_run_at=?,updated_at=NOW() WHERE id=?")->execute([$next,(int)$program['id']]);research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],null,'missed_run_skipped','system',null,['scheduled_for'=>$scheduled,'next_run_at'=>$next]);continue;}
        $trigger=$stale?'catch_up':'schedule';if(research_program_enqueue($pdo,$program,null,$trigger,$scheduled)!==null)$count++;$pdo->prepare("UPDATE research_programs SET next_run_at=?,updated_at=NOW() WHERE id=?")->execute([$next,(int)$program['id']]);
    }return $count;
}

function research_program_claim(PDO $pdo): ?array {
    if(!research_programs_ready($pdo))return null;return job_claim($pdo,'research_program_runs',"SELECT rpr.*,rp.public_id program_public_id FROM research_program_runs rpr JOIN research_programs rp ON rp.id=rpr.program_id WHERE rpr.status='queued' AND rpr.available_at<=NOW() ORDER BY rpr.available_at,rpr.id LIMIT 1",[],1800);
}

function research_program_create_plan(PDO $pdo,array $program,array $viewer,array $run,array $materialDeltas): array {
    $sequence=1;$q=$pdo->prepare("SELECT COUNT(*) FROM research_program_runs WHERE program_id=? AND id<=?");$q->execute([(int)$program['id'],(int)$run['id']]);$sequence=(int)$q->fetchColumn();$when=(string)($run['scheduled_for']?:gmdate('Y-m-d H:i:s'));$memory=research_program_memory($pdo,$program,12);$deltaText=research_program_delta_summary($materialDeltas,20);$tasks=research_program_templates($program);if(!$tasks)$tasks=research_program_default_tasks($program);$tasks=array_slice($tasks,0,(int)$program['max_tasks_per_run']);
    foreach($tasks as &$task){$task['description']=trim((string)$task['description'])."\n\nProgram change set:\n".$deltaText."\n\nProgram continuity:\n".$memory['text'];}unset($task);
    $objective=(string)$program['objective']."\n\nThis is recurring Program run #".$sequence.". Focus on what changed since the previous run; do not repeat unchanged findings unless needed for context.\n\nStructured change set:\n".$deltaText."\n\nContinuity from prior runs:\n".$memory['text'];
    $due=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.(int)$program['plan_due_offset_hours'].' hours')->format(DateTimeInterface::ATOM);
    $plan=research_task_plan_create($pdo,$viewer,['agent_id'=>$program['agent_public_id'],'program_run_id'=>(int)$run['id'],'title'=>research_program_template_text((string)$program['plan_title_template'],$program,$sequence,$when),'objective'=>$objective,'priority'=>$program['priority'],'due_at'=>$due,'deliverable_type'=>$program['deliverable_type'],'deliverable_title'=>research_program_template_text((string)$program['deliverable_title_template'],$program,$sequence,$when),'tasks'=>$tasks],true);
    return research_task_plan_access($pdo,$viewer,(string)$plan['public_id'])??$plan;
}

function research_program_prepare_run(PDO $pdo,array $program,array $viewer,array $run): array {
    if($program['status']==='archived'||($run['trigger_type']!=='manual'&&$program['status']!=='active'))return ['status'=>'skipped','summary'=>'Program is paused, archived, or unavailable for scheduled execution.'];
    research_program_project($pdo,$viewer,['project_public_id'=>$program['project_public_id']]);
    $current=research_program_snapshot($pdo,$program);$previous=research_program_previous_snapshot($pdo,(int)$program['id'],(int)$run['id']);$inputHash=hash('sha256',json_encode($current,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$deltas=research_program_compare_snapshots($pdo,$program,$current,$previous,(int)$run['id']);$material=research_program_material_deltas($program,$deltas);$materialHash=hash('sha256',json_encode(array_column($material,'fingerprint'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$summary=['total_changes'=>count($deltas),'material_changes'=>count($material),'types'=>array_count_values(array_map(fn($d)=>(string)$d['type'],$deltas))];
    $pdo->prepare("UPDATE research_program_runs SET input_hash=?,material_hash=?,input_snapshot_json=?,delta_summary_json=?,material_change_count=?,summary=? WHERE id=? AND status='processing'")
      ->execute([$inputHash,$materialHash,json_encode($current,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),count($material),mb_substr(research_program_delta_summary($deltas,12),0,12000),(int)$run['id']]);
    if(!$material&&$program['quiet_mode']==='material_only')return ['status'=>'skipped','quiet'=>true,'summary'=>'No material Research changes since the previous Program run.','input_hash'=>$inputHash,'material_hash'=>$materialHash,'deltas'=>$deltas,'material'=>$material];
    $plan=research_program_create_plan($pdo,$program,$viewer,$run,$material?:$deltas);return ['status'=>'active','quiet'=>false,'summary'=>'Created recurring Research plan “'.$plan['title'].'” from '.count($material).' material change(s).','input_hash'=>$inputHash,'material_hash'=>$materialHash,'deltas'=>$deltas,'material'=>$material,'plan'=>$plan];
}

function research_program_complete_quiet_run(PDO $pdo,array $program,array $run,string $token,array $result): void {
    job_claim_complete($pdo,'research_program_runs',(int)$run['id'],$token,'skipped');$pdo->prepare("UPDATE research_program_runs SET quiet_suppressed=1,summary=? WHERE id=?")->execute([mb_substr((string)$result['summary'],0,12000),(int)$run['id']]);$pdo->prepare("UPDATE research_programs SET last_run_at=NOW(),last_input_hash=?,last_material_hash=?,run_count=run_count+1,quiet_run_count=quiet_run_count+1,updated_at=NOW() WHERE id=?")->execute([$result['input_hash']??null,$result['material_hash']??null,(int)$program['id']]);research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],(int)$run['id'],'quiet_run_suppressed','system',null,['summary'=>$result['summary']]);
}

function research_program_activate_run(PDO $pdo,array $program,array $run,string $token,array $result): void {
    job_claim_assert($pdo,'research_program_runs',(int)$run['id'],$token);$q=$pdo->prepare("UPDATE research_program_runs SET status='active',claim_token=NULL,lease_expires_at=NULL,last_error=NULL,summary=?,completed_at=NULL WHERE id=? AND status='processing' AND claim_token=?");$q->execute([mb_substr((string)$result['summary'],0,12000),(int)$run['id'],$token]);if($q->rowCount()!==1)throw new LostJobClaim('Program run lease was lost before activation.');$pdo->prepare("UPDATE research_programs SET last_run_at=NOW(),last_input_hash=?,last_material_hash=?,updated_at=NOW() WHERE id=?")->execute([$result['input_hash']??null,$result['material_hash']??null,(int)$program['id']]);research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],(int)$run['id'],'plan_created','agent',null,['plan_id'=>$result['plan']['public_id']??null,'material_changes'=>count($result['material']??[])]);
}

function research_program_tokens_used(PDO $pdo,int $runId): int {
    $q=$pdo->prepare("SELECT COALESCE(SUM(COALESCE(ar.input_tokens,0)+COALESCE(ar.output_tokens,0)),0) FROM research_task_plans rtp JOIN research_tasks rt ON rt.plan_id=rtp.id JOIN research_task_runs rtr ON rtr.task_id=rt.id JOIN ai_runs ar ON ar.public_id=rtr.ai_run_public_id WHERE rtp.program_run_id=?");$q->execute([$runId]);return (int)$q->fetchColumn();
}

function research_program_task_budget(PDO $pdo,int $programRunId,?int $modelId=null): array {
    $q=$pdo->prepare("SELECT rp.token_budget_per_run,rpr.id FROM research_program_runs rpr JOIN research_programs rp ON rp.id=rpr.program_id WHERE rpr.id=? LIMIT 1");$q->execute([$programRunId]);$row=$q->fetch();if(!$row)return ['allowed'=>true,'used'=>0,'budget'=>0,'reserve'=>0];
    $used=research_program_tokens_used($pdo,$programRunId);$reserve=6000;if($modelId){$m=$pdo->prepare("SELECT max_output_tokens FROM ai_models WHERE id=? LIMIT 1");$m->execute([$modelId]);$reserve+=max(1000,(int)($m->fetchColumn()?:2048));}$budget=(int)$row['token_budget_per_run'];return ['allowed'=>$used<$budget&&($used+$reserve)<=$budget,'used'=>$used,'budget'=>$budget,'reserve'=>$reserve];
}

function research_program_chat_update(PDO $pdo,array $program,string $body,array $metadata=[]): void {
    $public=ulid_like();$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,parent_message_id,body) VALUES(?,?,NULL,'agent',NULL,?)")->execute([$public,(int)$program['conversation_id'],mb_substr($body,0,12000)]);$messageId=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$program['conversation_id']]);$pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,message_id,payload_json) VALUES(?,'agent_message_created',?,?)")->execute([(int)$program['conversation_id'],$messageId,json_encode(array_merge(['source'=>'research_programs','program_id'=>$program['public_id']],$metadata),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
}

function research_program_reconcile_runs(PDO $pdo,int $limit=100): int {
    if(!research_programs_ready($pdo))return 0;$limit=max(1,min(500,$limit));$q=$pdo->query("SELECT rpr.id FROM research_program_runs rpr WHERE rpr.status='active' ORDER BY rpr.id LIMIT ".$limit);$count=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $runId){$rq=$pdo->prepare("SELECT rpr.*,rp.public_id program_public_id FROM research_program_runs rpr JOIN research_programs rp ON rp.id=rpr.program_id WHERE rpr.id=? LIMIT 1");$rq->execute([(int)$runId]);$run=$rq->fetch();if(!$run)continue;$program=research_program_by_id($pdo,(int)$run['program_id']);if(!$program)continue;$pq=$pdo->prepare("SELECT rtp.*,rtd.status deliverable_status,rwo.public_id document_public_id,rwo.title document_title FROM research_task_plans rtp LEFT JOIN research_task_deliverables rtd ON rtd.plan_id=rtp.id LEFT JOIN research_workspace_objects rwo ON rwo.id=rtd.workspace_object_id WHERE rtp.program_run_id=? LIMIT 1");$pq->execute([(int)$runId]);$plan=$pq->fetch();if(!$plan)continue;
        if($plan['status']==='archived'){$pdo->prepare("UPDATE research_program_runs SET status='failed',last_error='Generated Research plan was archived before completion.',completed_at=NOW() WHERE id=? AND status='active'")->execute([(int)$runId]);$pdo->prepare("UPDATE research_programs SET failure_count=failure_count+1,run_count=run_count+1,updated_at=NOW() WHERE id=?")->execute([(int)$program['id']]);research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],(int)$runId,'run_failed','system',null,['reason'=>'plan_archived']);$count++;continue;}
        if($plan['status']!=='completed')continue;$tokens=research_program_tokens_used($pdo,(int)$runId);$summary='Program run completed. Research plan “'.$plan['title'].'” completed with '.(int)$run['material_change_count'].' material change(s).';$pdo->prepare("UPDATE research_program_runs SET status='completed',tokens_used=?,summary=?,completed_at=NOW() WHERE id=? AND status='active'")->execute([$tokens,$summary,(int)$runId]);$pdo->prepare("UPDATE research_programs SET run_count=run_count+1,updated_at=NOW() WHERE id=?")->execute([(int)$program['id']]);research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],(int)$runId,'run_completed','agent',null,['plan_id'=>$plan['public_id'],'document_public_id'=>$plan['document_public_id']??null,'tokens_used'=>$tokens]);
        try{$viewer=research_program_owner($pdo,$program);research_program_chat_update($pdo,$program,$summary.(!empty($plan['document_title'])?' Deliverable: '.$plan['document_title'].'.':''),['run_id'=>$run['public_id'],'plan_id'=>$plan['public_id'],'document_public_id'=>$plan['document_public_id']??null]);notification_create($pdo,(int)$viewer['id'],null,'research_program_completed','research_program',(string)$program['public_id'],$summary,['allow_self'=>true,'dedupe_key'=>'research-program-complete:'.$run['public_id'],'group_key'=>'research-program:'.$program['public_id'],'context'=>['run_id'=>$run['public_id'],'plan_id'=>$plan['public_id'],'document_public_id'=>$plan['document_public_id']??null]]);}catch(Throwable $ignored){}
        $count++;
    }return $count;
}

function research_program_run_fail(PDO $pdo,array $program,array $run,string $token,Throwable $error): string {
    $state=job_claim_retry_or_fail($pdo,'research_program_runs',(int)$run['id'],$token,mb_substr($error->getMessage(),0,1000),(int)$run['attempts'],3,180);if($state==='failed'){$pdo->prepare("UPDATE research_programs SET failure_count=failure_count+1,run_count=run_count+1,updated_at=NOW() WHERE id=?")->execute([(int)$program['id']]);research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],(int)$run['id'],'run_failed','system',null,['error'=>mb_substr($error->getMessage(),0,1000)]);}return $state;
}

function research_program_summary(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_program_agent($pdo,$viewer,$agentPublic);$q=$pdo->prepare("SELECT status,COUNT(*) total FROM research_programs WHERE research_agent_id=? AND status<>'archived' GROUP BY status");$q->execute([(int)$agent['id']]);$programs=[];foreach($q->fetchAll() as $r)$programs[$r['status']]=(int)$r['total'];
    $q=$pdo->prepare("SELECT rpr.status,COUNT(*) total FROM research_program_runs rpr JOIN research_programs rp ON rp.id=rpr.program_id WHERE rp.research_agent_id=? AND rpr.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY rpr.status");$q->execute([(int)$agent['id']]);$runs=[];foreach($q->fetchAll() as $r)$runs[$r['status']]=(int)$r['total'];
    $q=$pdo->prepare("SELECT MIN(next_run_at) FROM research_programs WHERE research_agent_id=? AND status='active' AND next_run_at IS NOT NULL");$q->execute([(int)$agent['id']]);$next=$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM research_task_plans rtp JOIN research_program_runs rpr ON rpr.id=rtp.program_run_id JOIN research_programs rp ON rp.id=rpr.program_id JOIN research_tasks rt ON rt.plan_id=rtp.id WHERE rp.research_agent_id=? AND rt.status='review'");$q->execute([(int)$agent['id']]);$review=(int)$q->fetchColumn();
    return ['agent_public_id'=>$agentPublic,'programs'=>$programs,'runs_30d'=>$runs,'active'=>(int)($programs['active']??0),'paused'=>(int)($programs['paused']??0),'failed_runs'=>(int)($runs['failed']??0),'quiet_runs'=>(int)($runs['skipped']??0),'review_tasks'=>$review,'next_run_at'=>$next?:null];
}

function research_program_run_deltas(PDO $pdo,array $viewer,string $runPublic,int $limit=100): array {
    $run=research_program_run_row($pdo,$viewer,$runPublic);if(!$run)return [];$limit=max(1,min(300,$limit));$q=$pdo->prepare("SELECT public_id,delta_type,importance,ref_type,ref_public_id,summary,before_json,after_json,occurred_at FROM research_program_deltas WHERE run_id=? ORDER BY FIELD(importance,'high','important','info'),id LIMIT ".$limit);$q->execute([(int)$run['id']]);$out=$q->fetchAll()?:[];foreach($out as &$row){$row['before']=json_decode((string)($row['before_json']??''),true)?:[];$row['after']=json_decode((string)($row['after_json']??''),true)?:[];unset($row['before_json'],$row['after_json']);}unset($row);return $out;
}

function research_program_detail(PDO $pdo,array $viewer,string $publicId): ?array {
    $program=research_program_access($pdo,$viewer,$publicId);if(!$program)return null;$program['runs']=research_program_run_list($pdo,$viewer,$publicId,40);$program['memory']=research_program_memory($pdo,$program,12);$q=$pdo->prepare("SELECT revision_number,change_reason,edited_by_agent,created_at FROM research_program_versions WHERE program_id=? ORDER BY revision_number DESC LIMIT 20");$q->execute([(int)$program['id']]);$program['versions']=$q->fetchAll()?:[];return $program;
}

function research_program_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=20): void {
    if(!research_programs_ready($pdo))return;$limit=max(1,min(50,$limit));
    $q=$pdo->prepare("SELECT rp.public_id,rp.title,rp.next_run_at,rp.updated_at,ra.public_id agent_public_id,
      (SELECT COUNT(*) FROM research_program_runs rpr WHERE rpr.program_id=rp.id AND rpr.status='failed' AND rpr.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) recent_failures,
      (SELECT COUNT(*) FROM research_program_runs rpr JOIN research_task_plans rtp ON rtp.program_run_id=rpr.id JOIN research_tasks rt ON rt.plan_id=rtp.id WHERE rpr.program_id=rp.id AND rt.status='review') review_tasks
      FROM research_programs rp JOIN research_agents ra ON ra.id=rp.research_agent_id LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rp.status='active' AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?))
      ORDER BY recent_failures DESC,review_tasks DESC,rp.next_run_at ASC LIMIT ".$limit);$q->execute([(int)$viewer['id'],(int)$viewer['id'],(int)$viewer['id']]);
    foreach($q->fetchAll() as $r){$url='/research-programs.php?agent='.rawurlencode((string)$r['agent_public_id']).'&program='.rawurlencode((string)$r['public_id']);if((int)$r['recent_failures']>0)cognitive_feed_add($items,['key'=>cognitive_feed_key('research_program_failed','research_program',(string)$r['public_id'],(string)$r['updated_at']),'type'=>'research_program_failed','section'=>'needs_attention','priority'=>'high','created_at'=>$r['updated_at'],'score_extra'=>16,'title'=>'Research Program needs attention','body'=>$r['title'].' · '.(int)$r['recent_failures'].' failed run(s) in the last 7 days.','meta'=>['program_id'=>$r['public_id']],'actions'=>[cognitive_feed_action_link('Open Program',$url)]]);
        elseif((int)$r['review_tasks']>0)cognitive_feed_add($items,['key'=>cognitive_feed_key('research_program_review','research_program',(string)$r['public_id'],(string)$r['updated_at']),'type'=>'research_program_review','section'=>'needs_attention','priority'=>'high','created_at'=>$r['updated_at'],'score_extra'=>12,'title'=>'Recurring research is ready for review','body'=>$r['title'].' · '.(int)$r['review_tasks'].' task(s) need review.','meta'=>['program_id'=>$r['public_id']],'actions'=>[cognitive_feed_action_link('Open Program',$url)]]);
        elseif(!empty($r['next_run_at']))cognitive_feed_add($items,['key'=>cognitive_feed_key('research_program_next','research_program',(string)$r['public_id'],(string)$r['next_run_at']),'type'=>'research_program_next','section'=>'next_up','priority'=>'medium','created_at'=>$r['updated_at'],'score_extra'=>3,'title'=>'Recurring Research Program scheduled','body'=>$r['title'].' · next run '.$r['next_run_at'].'.','meta'=>['program_id'=>$r['public_id'],'next_run_at'=>$r['next_run_at']],'actions'=>[cognitive_feed_action_link('Open Program',$url)]]);
    }
}
