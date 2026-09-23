<?php
declare(strict_types=1);

function research_tasks_ready(PDO $pdo): bool {
    try{
        foreach(['research_task_plans','research_task_plan_versions','research_tasks','research_task_dependencies','research_task_completion_gates','research_task_evidence_refs','research_task_jobs','research_task_runs','research_task_deliverables','research_task_events'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}

function research_task_priorities(): array {return ['low'=>'Low','medium'=>'Medium','high'=>'High','urgent'=>'Urgent'];}
function research_task_types(): array {return ['general'=>'General','find_source'=>'Find source','verify_claim'=>'Verify claim','review_source_change'=>'Review source change','compare_sources'=>'Compare sources','synthesize'=>'Synthesize','draft_deliverable'=>'Draft deliverable','follow_up'=>'Follow up'];}
function research_task_deliverable_types(): array {return ['research_brief'=>'Research Brief','competitive_analysis'=>'Competitive Analysis','due_diligence'=>'Due-Diligence Report','source_digest'=>'Source Digest','timeline'=>'Timeline','comparison'=>'Comparison','weekly_report'=>'Weekly Report','report'=>'Report','analysis'=>'Analysis','document'=>'Document'];}

function research_task_agent(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_agent_access($pdo,$viewer,trim($agentPublic));if(!$agent||($agent['status']??'')==='archived')throw new RuntimeException('Research Agent is unavailable.');
    return $agent;
}

function research_task_agent_by_id(PDO $pdo,int $agentId): ?array {
    $q=$pdo->prepare("SELECT ra.*,rp.public_id project_public_id,rp.title project_title,rp.owner_user_id project_owner_user_id,c.public_id conversation_public_id
      FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id JOIN conversations c ON c.id=ra.conversation_id
      WHERE ra.id=? AND ra.status='active' AND rp.status='active' LIMIT 1");$q->execute([$agentId]);return $q->fetch()?:null;
}

function research_task_owner(PDO $pdo,array $agent): array {
    $uid=(int)($agent['project_owner_user_id']??$agent['owner_user_id']??0);$q=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$q->execute([$uid]);$viewer=$q->fetch();
    if(!$viewer)throw new RuntimeException('Research Agent owner is unavailable.');return $viewer;
}

function research_task_project(PDO $pdo,array $viewer,array $agent): array {
    $project=project_access($pdo,(int)$viewer['id'],(string)$agent['project_public_id']);if(!$project||!project_can_write($project))throw new RuntimeException('This Research workspace is read only.');return $project;
}

function research_task_event(PDO $pdo,int $projectId,?int $planId,?int $taskId,string $event,string $actorType='system',?int $actorUserId=null,array $payload=[]): void {
    $actorType=in_array($actorType,['user','agent','system'],true)?$actorType:'system';
    $pdo->prepare("INSERT INTO research_task_events(public_id,plan_id,task_id,project_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?,?,?)")
      ->execute([ulid_like(),$planId,$taskId,$projectId,mb_substr(trim($event),0,64),$actorType,$actorUserId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function research_task_clean_due($value): ?string {
    $value=trim((string)$value);if($value==='')return null;try{$d=new DateTimeImmutable($value);return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){throw new InvalidArgumentException('Invalid due date.');}
}

function research_task_plan_hash(string $title,string $objective,string $priority,?string $due,string $deliverableType,string $deliverableTitle): string {
    return hash('sha256',json_encode([$title,$objective,$priority,$due,$deliverableType,$deliverableTitle],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function research_task_plan_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_tasks_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rtp.*,ra.public_id agent_public_id,ra.name agent_name,ra.conversation_id,c.public_id conversation_public_id,rp.public_id project_public_id,rp.title project_title
      FROM research_task_plans rtp JOIN research_agents ra ON ra.id=rtp.research_agent_id JOIN research_projects rp ON rp.id=rtp.project_id JOIN conversations c ON c.id=ra.conversation_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rtp.public_id=? AND rtp.status<>'archived' AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($publicId),(int)$viewer['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}

function research_task_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_tasks_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rt.*,rtp.public_id plan_public_id,rtp.title plan_title,ra.public_id agent_public_id,ra.name agent_name,rp.public_id project_public_id,rp.title project_title,c.public_id conversation_public_id
      FROM research_tasks rt LEFT JOIN research_task_plans rtp ON rtp.id=rt.plan_id LEFT JOIN research_agents ra ON ra.id=rt.research_agent_id
      JOIN research_projects rp ON rp.id=rt.project_id LEFT JOIN conversations c ON c.id=ra.conversation_id
      LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
      WHERE rt.public_id=? AND ((rp.team_id IS NULL AND rp.owner_user_id=?) OR (rp.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($publicId),(int)$viewer['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}

function research_task_plan_structure(PDO $pdo,int $planId): array {
    $q=$pdo->prepare("SELECT rt.public_id,rt.title,rt.description,rt.task_type,rt.priority,rt.status,rt.position,rt.due_at,
      GROUP_CONCAT(dep.public_id ORDER BY dep.position SEPARATOR ',') dependency_ids
      FROM research_tasks rt LEFT JOIN research_task_dependencies d ON d.task_id=rt.id LEFT JOIN research_tasks dep ON dep.id=d.depends_on_task_id
      WHERE rt.plan_id=? GROUP BY rt.id ORDER BY rt.position,rt.id");$q->execute([$planId]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row['dependencies']=array_values(array_filter(explode(',',(string)($row['dependency_ids']??''))));unset($row);
    return $rows;
}

function research_task_plan_snapshot(PDO $pdo,array $plan,string $reason='',?int $userId=null,bool $byAgent=false): void {
    $structure=research_task_plan_structure($pdo,(int)$plan['id']);
    $pdo->prepare("INSERT INTO research_task_plan_versions(public_id,plan_id,revision_number,title,objective,structure_json,change_reason,edited_by_user_id,edited_by_agent) VALUES(?,?,?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$plan['id'],(int)$plan['current_revision'],(string)$plan['title'],(string)$plan['objective'],json_encode($structure,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$reason!==''?mb_substr($reason,0,1000):null,$userId,$byAgent?1:0]);
}

function research_task_plan_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=100): array {
    $agent=research_task_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT rtp.*,
      SUM(CASE WHEN rt.status IN ('complete','done') THEN 1 ELSE 0 END) complete_tasks,
      SUM(CASE WHEN rt.status IN ('waiting','failed') THEN 1 ELSE 0 END) blocked_tasks,
      SUM(CASE WHEN rt.status='review' THEN 1 ELSE 0 END) review_tasks,
      COUNT(rt.id) total_tasks,
      rtd.public_id deliverable_public_id,rtd.status deliverable_status,rwo.public_id document_public_id,rwo.title document_title
      FROM research_task_plans rtp LEFT JOIN research_tasks rt ON rt.plan_id=rtp.id
      LEFT JOIN research_task_deliverables rtd ON rtd.plan_id=rtp.id LEFT JOIN research_workspace_objects rwo ON rwo.id=rtd.workspace_object_id
      WHERE rtp.research_agent_id=? AND rtp.status<>'archived'
      GROUP BY rtp.id,rtd.id,rwo.id ORDER BY FIELD(rtp.status,'active','draft','paused','completed'),rtp.updated_at DESC LIMIT ".$limit);
    $q->execute([(int)$agent['id']]);return $q->fetchAll()?:[];
}

function research_task_default_gates(string $type): array {
    return match($type){
      'find_source'=>[['type'=>'min_sources','required'=>['count'=>1]],['type'=>'citations','required'=>['count'=>1]]],
      'verify_claim'=>[['type'=>'min_sources','required'=>['count'=>2]],['type'=>'citations','required'=>['count'=>1]],['type'=>'no_open_contradictions','required'=>['value'=>true]]],
      'review_source_change'=>[['type'=>'min_sources','required'=>['count'=>1]],['type'=>'citations','required'=>['count'=>1]]],
      'compare_sources','synthesize'=>[['type'=>'min_sources','required'=>['count'=>2]],['type'=>'citations','required'=>['count'=>1]]],
      'draft_deliverable'=>[['type'=>'citations','required'=>['count'=>1]],['type'=>'human_review','required'=>['value'=>true]]],
      default=>[]
    };
}

function research_task_add_gates(PDO $pdo,int $taskId,array $gates): void {
    $allowed=['min_sources','primary_source','no_open_contradictions','fresh_evidence','citations','human_review'];
    foreach(array_slice($gates,0,12) as $raw){if(!is_array($raw))continue;$type=(string)($raw['type']??$raw['gate_type']??'');if(!in_array($type,$allowed,true))continue;$required=is_array($raw['required']??null)?$raw['required']:['value'=>$raw['value']??true];
        $pdo->prepare("INSERT INTO research_task_completion_gates(task_id,gate_type,required_json,status) VALUES(?,?,?,'pending') ON DUPLICATE KEY UPDATE required_json=VALUES(required_json),status='pending',detail=NULL,evaluated_at=NULL")->execute([$taskId,$type,json_encode($required,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }
}

function research_task_create(PDO $pdo,array $viewer,array $agent,array $project,array $input=[],?int $planId=null,bool $createdByAgent=false,?string $signalType=null,?string $signalPublic=null,?string $signalFingerprint=null): array {
    $title=mb_substr(trim((string)($input['title']??'')),0,255);if($title==='')throw new InvalidArgumentException('Task title is required.');
    $description=mb_substr(trim((string)($input['description']??'')),0,12000);
    $type=(string)($input['task_type']??'general');if(!isset(research_task_types()[$type]))$type='general';
    $priority=(string)($input['priority']??'medium');if(!isset(research_task_priorities()[$priority]))$priority='medium';
    $due=research_task_clean_due($input['due_at']??null);$position=max(0,(int)($input['position']??0));$public=ulid_like();
    $fingerprint=$signalFingerprint?:null;
    $stmt=$pdo->prepare("INSERT INTO research_tasks(public_id,project_id,research_agent_id,plan_id,created_by_user_id,title,description,task_type,status,due_at,priority,source_type,source_public_id,source_fingerprint,created_by_agent,position)
      VALUES(?,?,?,?,?,?,?,?, 'queued',?,?,?,?,?,?,?)");
    $stmt->execute([$public,(int)$project['id'],(int)$agent['id'],$planId,(int)$viewer['id'],$title,$description!==''?$description:null,$type,$due,$priority,$signalType,$signalPublic,$fingerprint,$createdByAgent?1:0,$position]);
    $taskId=(int)$pdo->lastInsertId();$gates=is_array($input['gates']??null)?$input['gates']:research_task_default_gates($type);research_task_add_gates($pdo,$taskId,$gates);
    research_task_event($pdo,(int)$project['id'],$planId,$taskId,'created',$createdByAgent?'agent':'user',(int)$viewer['id'],['task_type'=>$type,'priority'=>$priority,'source_type'=>$signalType,'source_public_id'=>$signalPublic]);
    return research_task_access($pdo,$viewer,$public)??['id'=>$taskId,'public_id'=>$public,'title'=>$title,'status'=>'queued'];
}

function research_task_plan_create(PDO $pdo,array $viewer,array $input,bool $createdByAgent=false): array {
    if(!research_tasks_ready($pdo))throw new RuntimeException('Research Tasks require the latest database upgrade.');
    $agent=research_task_agent($pdo,$viewer,(string)($input['agent_id']??''));$project=research_task_project($pdo,$viewer,$agent);
    $title=mb_substr(trim((string)($input['title']??'')),0,255);if($title==='')throw new InvalidArgumentException('Plan title is required.');
    $objective=mb_substr(trim((string)($input['objective']??'')),0,16000);if($objective==='')throw new InvalidArgumentException('Plan objective is required.');
    $priority=(string)($input['priority']??'medium');if(!isset(research_task_priorities()[$priority]))$priority='medium';$due=research_task_clean_due($input['due_at']??null);
    $deliverable=(string)($input['deliverable_type']??'research_brief');if(!isset(research_task_deliverable_types()[$deliverable]))$deliverable='research_brief';
    $deliverableTitle=mb_substr(trim((string)($input['deliverable_title']??'')),0,255);if($deliverableTitle==='')$deliverableTitle=$title;
    $hash=research_task_plan_hash($title,$objective,$priority,$due,$deliverable,$deliverableTitle);$public=ulid_like();$tasks=is_array($input['tasks']??null)?array_slice($input['tasks'],0,30):[];
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO research_task_plans(public_id,research_agent_id,project_id,created_by_user_id,created_by_agent,title,objective,status,priority,due_at,deliverable_type,deliverable_title,current_revision,plan_hash)
          VALUES(?,?,?,?,?,?,?,'active',?,?,?,?,1,?)")->execute([$public,(int)$agent['id'],(int)$project['id'],(int)$viewer['id'],$createdByAgent?1:0,$title,$objective,$priority,$due,$deliverable,$deliverableTitle,$hash]);
        $planId=(int)$pdo->lastInsertId();$created=[];$position=0;
        foreach($tasks as $raw){if(!is_array($raw))continue;$raw['position']=$position++;$created[]=research_task_create($pdo,$viewer,$agent,$project,$raw,$planId,$createdByAgent);}
        foreach($tasks as $idx=>$raw){if(!is_array($raw)||empty($raw['depends_on'])||!isset($created[$idx]))continue;$taskId=(int)$created[$idx]['id'];foreach(array_slice((array)$raw['depends_on'],0,12) as $depIndex){$di=(int)$depIndex;if($di<0||!isset($created[$di])||$di===$idx)continue;$pdo->prepare("INSERT IGNORE INTO research_task_dependencies(task_id,depends_on_task_id,dependency_type) VALUES(?,?,'finish_to_start')")->execute([$taskId,(int)$created[$di]['id']]);}}
        $q=$pdo->prepare('SELECT * FROM research_task_plans WHERE id=?');$q->execute([$planId]);$plan=$q->fetch();research_task_plan_snapshot($pdo,$plan,'Initial plan',(int)$viewer['id'],$createdByAgent);
        research_task_event($pdo,(int)$project['id'],$planId,null,'plan_created',$createdByAgent?'agent':'user',(int)$viewer['id'],['task_count'=>count($created),'deliverable_type'=>$deliverable]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    research_task_queue_ready($pdo,$planId,(int)$viewer['id'],'dependency_ready');
    research_task_refresh_deliverable($pdo,$viewer,$public);
    return research_task_plan_access($pdo,$viewer,$public)??['public_id'=>$public,'title'=>$title];
}

function research_task_plan_update(PDO $pdo,array $viewer,string $publicId,array $input,bool $byAgent=false): array {
    $plan=research_task_plan_access($pdo,$viewer,$publicId);if(!$plan)throw new RuntimeException('Research plan not found.');$agent=research_task_agent($pdo,$viewer,(string)$plan['agent_public_id']);research_task_project($pdo,$viewer,$agent);
    $title=mb_substr(trim((string)($input['title']??$plan['title'])),0,255);$objective=mb_substr(trim((string)($input['objective']??$plan['objective'])),0,16000);if($title===''||$objective==='')throw new InvalidArgumentException('Plan title and objective are required.');
    $priority=(string)($input['priority']??$plan['priority']);if(!isset(research_task_priorities()[$priority]))$priority=(string)$plan['priority'];$due=array_key_exists('due_at',$input)?research_task_clean_due($input['due_at']):$plan['due_at'];
    $deliverable=(string)($input['deliverable_type']??$plan['deliverable_type']);if(!isset(research_task_deliverable_types()[$deliverable]))$deliverable=(string)$plan['deliverable_type'];$deliverableTitle=mb_substr(trim((string)($input['deliverable_title']??$plan['deliverable_title']??$title)),0,255);
    $hash=research_task_plan_hash($title,$objective,$priority,$due,$deliverable,$deliverableTitle);if(hash_equals((string)$plan['plan_hash'],$hash))return $plan;$next=(int)$plan['current_revision']+1;
    $pdo->prepare("UPDATE research_task_plans SET title=?,objective=?,priority=?,due_at=?,deliverable_type=?,deliverable_title=?,current_revision=?,plan_hash=?,updated_at=NOW() WHERE id=?")
      ->execute([$title,$objective,$priority,$due,$deliverable,$deliverableTitle,$next,$hash,(int)$plan['id']]);
    $q=$pdo->prepare('SELECT * FROM research_task_plans WHERE id=?');$q->execute([(int)$plan['id']]);$fresh=$q->fetch();research_task_plan_snapshot($pdo,$fresh,mb_substr(trim((string)($input['reason']??'Plan updated')),0,1000),(int)$viewer['id'],$byAgent);
    research_task_event($pdo,(int)$plan['project_id'],(int)$plan['id'],null,'plan_revised',$byAgent?'agent':'user',(int)$viewer['id'],['revision'=>$next]);
    return research_task_plan_access($pdo,$viewer,$publicId)??$fresh;
}

function research_task_dependencies_complete(PDO $pdo,int $taskId): bool {
    $q=$pdo->prepare("SELECT COUNT(*) FROM research_task_dependencies d JOIN research_tasks parent ON parent.id=d.depends_on_task_id WHERE d.task_id=? AND parent.status NOT IN ('complete','done','archived')");$q->execute([$taskId]);return (int)$q->fetchColumn()===0;
}

function research_task_queue(PDO $pdo,int $taskId,?int $requestedByUserId=null,string $trigger='dependency_ready'): void {
    if(!research_tasks_ready($pdo)||!in_array($trigger,['manual','dependency_ready','signal','recovery','replan'],true))return;
    $q=$pdo->prepare("SELECT rt.id,rt.status,rt.plan_id,rt.research_agent_id,rt.project_id,rtp.status plan_status FROM research_tasks rt LEFT JOIN research_task_plans rtp ON rtp.id=rt.plan_id WHERE rt.id=? LIMIT 1");$q->execute([$taskId]);$task=$q->fetch();
    if(!$task||(int)$task['research_agent_id']<1||in_array((string)$task['status'],['complete','done','archived','researching'],true)||($task['plan_id']&&$task['plan_status']!=='active'))return;
    if(!research_task_dependencies_complete($pdo,$taskId)){$pdo->prepare("UPDATE research_tasks SET status='queued',blocking_reason='Waiting for dependent tasks.',updated_at=NOW() WHERE id=?")->execute([$taskId]);return;}
    $pdo->prepare("UPDATE research_tasks SET status='ready',blocking_reason=NULL,updated_at=NOW() WHERE id=? AND status NOT IN ('complete','done','archived','researching')")->execute([$taskId]);
    $pdo->prepare("INSERT INTO research_task_jobs(task_id,plan_id,research_agent_id,project_id,requested_by_user_id,trigger_type,status,available_at) VALUES(?,?,?,?,?,?,'queued',NOW())
      ON DUPLICATE KEY UPDATE requested_by_user_id=COALESCE(VALUES(requested_by_user_id),requested_by_user_id),trigger_type=VALUES(trigger_type),
      rerun_requested=CASE WHEN status='processing' THEN 1 ELSE rerun_requested END,status=CASE WHEN status='processing' THEN status ELSE 'queued' END,
      attempts=CASE WHEN status='processing' THEN attempts ELSE 0 END,claim_token=CASE WHEN status='processing' THEN claim_token ELSE NULL END,
      lease_expires_at=CASE WHEN status='processing' THEN lease_expires_at ELSE NULL END,available_at=CASE WHEN status='processing' THEN available_at ELSE NOW() END,
      started_at=CASE WHEN status='processing' THEN started_at ELSE NULL END,completed_at=CASE WHEN status='processing' THEN completed_at ELSE NULL END,last_error=CASE WHEN status='processing' THEN last_error ELSE NULL END")
      ->execute([$taskId,$task['plan_id']?:null,(int)$task['research_agent_id'],(int)$task['project_id'],$requestedByUserId,$trigger]);
}

function research_task_queue_ready(PDO $pdo,int $planId,?int $requestedByUserId=null,string $trigger='dependency_ready'): int {
    $q=$pdo->prepare("SELECT id FROM research_tasks WHERE plan_id=? AND status IN ('queued','open','ready') ORDER BY position,id");$q->execute([$planId]);$count=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $taskId)if(research_task_dependencies_complete($pdo,(int)$taskId)){research_task_queue($pdo,(int)$taskId,$requestedByUserId,$trigger);$count++;}return $count;
}

function research_task_input_hash(PDO $pdo,array $task): string {
    $deps=[];$q=$pdo->prepare("SELECT parent.public_id,parent.status,parent.completed_at FROM research_task_dependencies d JOIN research_tasks parent ON parent.id=d.depends_on_task_id WHERE d.task_id=? ORDER BY parent.id");$q->execute([(int)$task['id']]);$deps=$q->fetchAll()?:[];
    $gates=[];$q=$pdo->prepare("SELECT gate_type,required_json,status,waived_at FROM research_task_completion_gates WHERE task_id=? ORDER BY gate_type");$q->execute([(int)$task['id']]);foreach($q->fetchAll() as $g)$gates[]=[(string)$g['gate_type'],(string)$g['required_json'],(string)$g['status'],(string)($g['waived_at']??'')];
    $sources=[];$q=$pdo->prepare("SELECT s.public_id,s.status,s.current_version_id,COALESCE(sv.content_hash,'') content_hash FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE ps.project_id=? ORDER BY s.id");$q->execute([(int)$task['project_id']]);foreach($q->fetchAll() as $r)$sources[]=[(string)$r['public_id'],(string)$r['status'],(int)($r['current_version_id']??0),(string)$r['content_hash']];
    $claims=[];$q=$pdo->prepare("SELECT rc.public_id,rc.status,rc.updated_at,COUNT(ce.id) evidence_count,COALESCE(MAX(ce.id),0) evidence_revision FROM research_claims rc LEFT JOIN claim_evidence ce ON ce.claim_id=rc.id WHERE rc.project_id=? GROUP BY rc.id ORDER BY rc.id");$q->execute([(int)$task['project_id']]);foreach($q->fetchAll() as $r)$claims[]=[(string)$r['public_id'],(string)$r['status'],(string)$r['updated_at'],(int)$r['evidence_count'],(int)$r['evidence_revision']];
    $objects=[];if(installer_table_exists($pdo,'research_retrieval_documents')){$q=$pdo->prepare("SELECT d.object_type,d.object_public_id,COALESCE(d.source_updated_at,d.updated_at) evidence_updated_at FROM research_retrieval_documents d WHERE d.project_id=? AND NOT EXISTS(SELECT 1 FROM research_task_deliverables rtd JOIN research_workspace_objects rwo ON rwo.id=rtd.workspace_object_id WHERE rtd.project_id=d.project_id AND rwo.public_id=d.object_public_id) ORDER BY d.object_type,d.object_public_id");$q->execute([(int)$task['project_id']]);foreach($q->fetchAll() as $r)$objects[]=[(string)$r['object_type'],(string)$r['object_public_id'],(string)$r['evidence_updated_at']];}
    return hash('sha256',json_encode([
      (string)$task['public_id'],(string)$task['title'],(string)($task['description']??''),(string)$task['task_type'],(string)$task['priority'],(string)($task['due_at']??''),(int)($task['plan_revision']??0),
      $deps,$gates,$sources,$claims,$objects
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function research_task_execution_context(PDO $pdo,string $taskPublic): ?array {
    $q=$pdo->prepare("SELECT rt.*,rtp.public_id plan_public_id,rtp.title plan_title,rtp.objective,rtp.current_revision plan_revision,rp.public_id project_public_id,rp.title project_title,ra.public_id agent_public_id
      FROM research_tasks rt LEFT JOIN research_task_plans rtp ON rtp.id=rt.plan_id JOIN research_projects rp ON rp.id=rt.project_id LEFT JOIN research_agents ra ON ra.id=rt.research_agent_id WHERE rt.public_id=? LIMIT 1");$q->execute([$taskPublic]);$task=$q->fetch();if(!$task)return null;
    $workspace=function_exists('research_workspace_context')?research_workspace_context($pdo,(int)$task['project_id']):null;$refs=[['type'=>'research_project','id'=>$task['project_public_id']]];$chunks=[];
    foreach(array_slice((array)($workspace['snapshot']['claims']??[]),0,30) as $x){$refs[]=['type'=>'claim','id'=>$x['public_id']];$chunks[]='[CLAIM '.$x['public_id'].'] '.$x['status'].' · '.$x['statement'];}
    foreach(array_slice((array)($workspace['snapshot']['source_risks']??[]),0,20) as $x){$refs[]=['type'=>'source','id'=>$x['source_public_id']];$chunks[]='[SOURCE '.$x['source_public_id'].'] '.($x['title']?:$x['domain']).($x['latest_diff']?' · '.$x['latest_diff']:'');}
    $q=$pdo->prepare("SELECT s.public_id,s.title,s.domain,sv.extracted_text FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE ps.project_id=? ORDER BY ps.created_at DESC LIMIT 16");$q->execute([(int)$task['project_id']]);
    foreach($q->fetchAll() as $s){$refs[]=['type'=>'source','id'=>$s['public_id']];$chunks[]='[SOURCE '.$s['public_id'].'] '.($s['title']?:$s['domain'])."\n".mb_substr((string)($s['extracted_text']??''),0,1800);}
    if(function_exists('research_retrieval_context')&&research_retrieval_ready($pdo)&&!empty($task['research_agent_id'])){
        try{
            $agent=research_task_agent_by_id($pdo,(int)$task['research_agent_id']);if($agent){
                $viewer=research_task_owner($pdo,$agent);$query=trim((string)$task['title'].' '.(string)($task['description']??''));$retrieval=research_retrieval_context($pdo,[],$viewer,(string)$task['project_public_id'],$query,[],12);
                $dq=$pdo->prepare("SELECT rwo.public_id FROM research_task_deliverables rtd JOIN research_workspace_objects rwo ON rwo.id=rtd.workspace_object_id WHERE rtd.project_id=?");$dq->execute([(int)$task['project_id']]);$managed=array_fill_keys(array_map('strval',$dq->fetchAll(PDO::FETCH_COLUMN)?:[]),true);
                foreach((array)($retrieval['results']??[]) as $result){$rid=(string)($result['public_id']??'');if($rid!==''&&isset($managed[$rid]))continue;$type=(string)($result['object_type']??'');if($type===''||$rid==='')continue;$label='['.strtoupper($type).' '.$rid.(!empty($result['locator_label'])?' · '.$result['locator_label']:'').']';$chunks[]=$label."\n".(string)($result['title']??'')."\n".(string)($result['snippet']??'');$refs[]=['type'=>$type,'id'=>$rid,'locator'=>$result['locator']??[],'label'=>$result['citation']['label']??($result['title']??$rid)];}
            }
        }catch(Throwable $ignored){}
    }
    $allowed=[];foreach($refs as $ref){$type=(string)($ref['type']??'');$id=(string)($ref['id']??'');if($type!==''&&$id!=='')$allowed[$type.':'.$id]=true;}
    return ['task'=>$task,'workspace_text'=>(string)($workspace['text']??''),'evidence_text'=>implode("\n\n",$chunks),'refs'=>$refs,'allowed_refs'=>$allowed,'input_hash'=>research_task_input_hash($pdo,$task)];
}

function research_task_mark_processing(PDO $pdo,string $publicId): void {
    $pdo->prepare("UPDATE research_tasks SET status='researching',blocking_reason=NULL,started_at=COALESCE(started_at,NOW()),updated_at=NOW() WHERE public_id=? AND status IN ('ready','queued','waiting','failed')")->execute([trim($publicId)]);
}

function research_task_ref_accessible(PDO $pdo,int $projectId,string $type,string $public): bool {
    if($type==='source'){$q=$pdo->prepare("SELECT 1 FROM project_sources ps JOIN sources s ON s.id=ps.source_id WHERE ps.project_id=? AND s.public_id=? LIMIT 1");$q->execute([$projectId,$public]);return (bool)$q->fetchColumn();}
    if($type==='claim'){$q=$pdo->prepare("SELECT 1 FROM research_claims WHERE project_id=? AND public_id=? LIMIT 1");$q->execute([$projectId,$public]);return (bool)$q->fetchColumn();}
    if($type==='annotation'){$q=$pdo->prepare("SELECT 1 FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=? AND a.public_id=? LIMIT 1");$q->execute([$projectId,$public]);return (bool)$q->fetchColumn();}
    if(in_array($type,['document','upload','recording','bookmark','sticky'],true)){$q=$pdo->prepare("SELECT 1 FROM research_workspace_objects WHERE project_id=? AND public_id=? AND object_type=? AND status='active' LIMIT 1");$q->execute([$projectId,$public,$type]);return (bool)$q->fetchColumn();}
    return false;
}

function research_task_store_refs(PDO $pdo,int $taskId,int $projectId,array $refs): array {
    $stored=[];foreach(array_slice($refs,0,40) as $raw){if(!is_array($raw))continue;$type=strtolower(trim((string)($raw['type']??'')));$id=trim((string)($raw['id']??$raw['public_id']??''));if($id===''||!research_task_ref_accessible($pdo,$projectId,$type,$id))continue;
        $rel=(string)($raw['relationship']??'context');if(!in_array($rel,['supports','contradicts','context','primary'],true))$rel='context';$locator=mb_substr(trim((string)($raw['locator']??'')),0,500);
        $pdo->prepare("INSERT INTO research_task_evidence_refs(task_id,ref_type,ref_public_id,locator,relationship,added_by) VALUES(?,?,?,?,?,'agent') ON DUPLICATE KEY UPDATE locator=VALUES(locator)")->execute([$taskId,$type,$id,$locator!==''?$locator:null,$rel]);$stored[]=['type'=>$type,'id'=>$id,'locator'=>$locator,'relationship'=>$rel];
    }return $stored;
}

function research_task_independent_sources(PDO $pdo,int $projectId,array $refs): array {
    $sources=[];
    foreach($refs as $ref){
        $type=(string)$ref['ref_type'];$public=(string)$ref['ref_public_id'];
        if($type==='source'){$sources[$public]=true;continue;}
        if($type==='annotation'){$q=$pdo->prepare("SELECT s.public_id FROM annotations a JOIN sources s ON s.id=a.source_id WHERE a.public_id=? LIMIT 1");$q->execute([$public]);$source=(string)($q->fetchColumn()?:'');if($source!=='')$sources[$source]=true;}
    }
    return array_keys($sources);
}

function research_task_ref_fresh_timestamp(PDO $pdo,string $type,string $public): int {
    if($type==='source'){$q=$pdo->prepare("SELECT COALESCE(sv.captured_at,s.last_checked_at,s.created_at) FROM sources s LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE s.public_id=? LIMIT 1");$q->execute([$public]);return strtotime((string)($q->fetchColumn()?:''))?:0;}
    if($type==='annotation'){$q=$pdo->prepare("SELECT COALESCE(sv.captured_at,a.updated_at,a.created_at) FROM annotations a LEFT JOIN source_versions sv ON sv.id=a.source_version_id WHERE a.public_id=? LIMIT 1");$q->execute([$public]);return strtotime((string)($q->fetchColumn()?:''))?:0;}
    if(in_array($type,['document','upload','recording','bookmark','sticky'],true)){$q=$pdo->prepare("SELECT updated_at FROM research_workspace_objects WHERE public_id=? LIMIT 1");$q->execute([$public]);return strtotime((string)($q->fetchColumn()?:''))?:0;}
    if($type==='claim'){$q=$pdo->prepare("SELECT updated_at FROM research_claims WHERE public_id=? LIMIT 1");$q->execute([$public]);return strtotime((string)($q->fetchColumn()?:''))?:0;}
    return 0;
}

function research_task_gate_evaluate(PDO $pdo,array $task): array {
    $q=$pdo->prepare("SELECT * FROM research_task_completion_gates WHERE task_id=? ORDER BY id");$q->execute([(int)$task['id']]);$gates=$q->fetchAll()?:[];
    $q=$pdo->prepare("SELECT * FROM research_task_evidence_refs WHERE task_id=?");$q->execute([(int)$task['id']]);$refs=$q->fetchAll()?:[];$independentSources=research_task_independent_sources($pdo,(int)$task['project_id'],$refs);
    $claimRefs=array_values(array_unique(array_map(fn($r)=>(string)$r['ref_public_id'],array_filter($refs,fn($r)=>$r['ref_type']==='claim'))));$openContradictions=0;
    if($claimRefs&&installer_table_exists($pdo,'research_autonomy_observations')){$ph=implode(',',array_fill(0,count($claimRefs),'?'));$params=array_merge([(int)$task['project_id']],$claimRefs);$q=$pdo->prepare("SELECT COUNT(*) FROM research_autonomy_observations WHERE project_id=? AND status='open' AND observation_type='contradiction' AND subject_public_id IN ($ph)");$q->execute($params);$openContradictions=(int)$q->fetchColumn();}
    $results=[];$all=true;
    foreach($gates as $gate){$required=json_decode((string)$gate['required_json'],true)?:[];$passed=false;$detail='';
      switch($gate['gate_type']){
        case 'min_sources':$need=max(1,(int)($required['count']??1));$count=count($independentSources);$passed=$count>=$need;$detail="$count/$need independent source(s)";break;
        case 'primary_source':$count=count(array_filter($refs,fn($r)=>$r['relationship']==='primary'));$passed=$count>0;$detail=$passed?'Primary evidence attached.':'Primary evidence required.';break;
        case 'no_open_contradictions':$passed=!$claimRefs?false:$openContradictions===0;$detail=!$claimRefs?'A cited Claim is required for contradiction checking.':($passed?'No open contradiction for cited Claims.':$openContradictions.' open contradiction(s) for cited Claims.');break;
        case 'fresh_evidence':$days=max(1,(int)($required['days']??30));$cut=time()-($days*86400);$fresh=0;foreach($refs as $r)if(research_task_ref_fresh_timestamp($pdo,(string)$r['ref_type'],(string)$r['ref_public_id'])>=$cut)$fresh++;$passed=$fresh>0;$detail=$passed?'Fresh evidence is attached.':'Evidence newer than '.$days.' days is required.';break;
        case 'citations':$need=max(1,(int)($required['count']??1));$count=count($refs);$passed=$count>=$need;$detail="$count/$need citation(s)";break;
        case 'human_review':$passed=!empty($task['human_reviewed_at']);$detail=$passed?'Human review completed.':'Human review required.';break;
      }
      if((string)$gate['status']==='waived'){$passed=true;$detail='Gate waived by user.';}
      $status=$passed?'passed':'failed';$pdo->prepare("UPDATE research_task_completion_gates SET status=?,detail=?,evaluated_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$status,$detail,(int)$gate['id']]);$results[]=['type'=>$gate['gate_type'],'status'=>$status,'detail'=>$detail,'required'=>$required];if(!$passed)$all=false;
    }
    return ['pass'=>$all,'gates'=>$results,'evidence_count'=>count($refs),'independent_sources'=>count($independentSources),'open_contradictions'=>$openContradictions];
}

function research_task_apply_ai_output(PDO $pdo,string $taskPublic,string $output,string $aiRunPublic): array {
    $ctx=research_task_execution_context($pdo,$taskPublic);if(!$ctx)throw new RuntimeException('Research task is unavailable.');$task=$ctx['task'];$currentHash=research_task_input_hash($pdo,$task);
    $q=$pdo->prepare("SELECT * FROM research_task_runs WHERE task_id=? AND status='queued_ai' ORDER BY id DESC LIMIT 1");$q->execute([(int)$task['id']]);$run=$q->fetch();if(!$run)throw new RuntimeException('Research task execution run is unavailable.');
    if(!hash_equals((string)$run['input_hash'],$currentHash)){
        $pdo->prepare("UPDATE research_task_runs SET status='stale',last_error='Task or Research state changed during processing.',completed_at=NOW() WHERE id=?")->execute([(int)$run['id']]);
        $pdo->prepare("UPDATE research_tasks SET status='queued',blocking_reason='Research changed while this task was processing; a fresh run is required.',updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);research_task_queue($pdo,(int)$task['id'],null,'recovery');
        return ['stale'=>true];
    }
    $json=json_decode(trim($output),true);if(!is_array($json))throw new RuntimeException('Research task execution returned invalid JSON.');
    $summary=mb_substr(trim((string)($json['summary']??'')),0,30000);if($summary==='')throw new RuntimeException('Research task execution returned no summary.');
    $state=strtolower(trim((string)($json['state']??'review')));if(!in_array($state,['review','waiting'],true))$state='review';$blocking=mb_substr(trim((string)($json['blocking_reason']??'')),0,1000);
    $refs=research_task_store_refs($pdo,(int)$task['id'],(int)$task['project_id'],is_array($json['evidence_refs']??null)?$json['evidence_refs']:[]);
    $pdo->prepare("UPDATE research_tasks SET execution_summary=?,execution_refs_json=?,blocking_reason=?,updated_at=NOW() WHERE id=?")->execute([$summary,json_encode($refs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$blocking!==''?$blocking:null,(int)$task['id']]);
    $task['execution_summary']=$summary;$task['blocking_reason']=$blocking!==''?$blocking:null;$evaluation=research_task_gate_evaluate($pdo,$task);
    $newStatus=$state==='waiting'?'waiting':($evaluation['pass']?'complete':'review');$completed=$newStatus==='complete'?gmdate('Y-m-d H:i:s'):null;
    $pdo->prepare("UPDATE research_tasks SET status=?,completion_evaluation_json=?,completed_at=?,updated_at=NOW() WHERE id=?")->execute([$newStatus,json_encode($evaluation,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$completed,(int)$task['id']]);
    $pdo->prepare("UPDATE research_task_runs SET status=?,ai_run_public_id=?,summary=?,refs_json=?,gate_evaluation_json=?,completed_at=NOW() WHERE id=?")
      ->execute([$newStatus==='waiting'?'waiting':'completed',$aiRunPublic,$summary,json_encode($refs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($evaluation,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$run['id']]);
    research_task_event($pdo,(int)$task['project_id'],$task['plan_id']?(int)$task['plan_id']:null,(int)$task['id'],$newStatus==='complete'?'completed':$newStatus,'agent',null,['ai_run_public_id'=>$aiRunPublic,'evidence_count'=>count($refs)]);
    if($task['plan_id']){
        research_task_plan_recalculate($pdo,(int)$task['plan_id']);research_task_queue_ready($pdo,(int)$task['plan_id'],null,'dependency_ready');research_task_refresh_deliverable_by_plan_id($pdo,(int)$task['plan_id']);
        $label=$newStatus==='complete'?'completed':($newStatus==='review'?'is ready for review':'is waiting');
        research_task_chat_update($pdo,(int)$task['plan_id'],'Research task “'.$task['title'].'” '.$label.'. '.mb_substr($summary,0,700),['task_id'=>$taskPublic,'status'=>$newStatus]);
    }
    return ['stale'=>false,'status'=>$newStatus,'summary'=>$summary,'refs'=>$refs,'evaluation'=>$evaluation];
}

function research_task_mark_failed(PDO $pdo,string $taskPublic,string $message): void {
    $q=$pdo->prepare("SELECT id,project_id,plan_id FROM research_tasks WHERE public_id=? LIMIT 1");$q->execute([$taskPublic]);$task=$q->fetch();if(!$task)return;
    $pdo->prepare("UPDATE research_tasks SET status='failed',blocking_reason=?,updated_at=NOW() WHERE id=?")->execute([mb_substr($message,0,1000),(int)$task['id']]);
    $pdo->prepare("UPDATE research_task_runs SET status='failed',last_error=?,completed_at=NOW() WHERE task_id=? AND status IN ('processing','queued_ai') ORDER BY id DESC LIMIT 1")->execute([mb_substr($message,0,1000),(int)$task['id']]);
    research_task_event($pdo,(int)$task['project_id'],$task['plan_id']?(int)$task['plan_id']:null,(int)$task['id'],'failed','system',null,['error'=>mb_substr($message,0,1000)]);
}

function research_task_plan_recalculate(PDO $pdo,int $planId): void {
    $q=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status IN ('complete','done','archived') THEN 1 ELSE 0 END) completed FROM research_tasks WHERE plan_id=?");$q->execute([$planId]);$x=$q->fetch()?:['total'=>0,'completed'=>0];
    if((int)$x['total']>0&&(int)$x['total']===(int)$x['completed'])$pdo->prepare("UPDATE research_task_plans SET status='completed',completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=? AND status='active'")->execute([$planId]);
    else $pdo->prepare("UPDATE research_task_plans SET status=CASE WHEN status='completed' THEN 'active' ELSE status END,completed_at=CASE WHEN status='completed' THEN NULL ELSE completed_at END,updated_at=NOW() WHERE id=?")->execute([$planId]);
}

function research_task_deliverable_document_type(string $type): string {
    return match($type){'research_brief'=>'research_brief','weekly_report'=>'weekly_report','timeline'=>'timeline','report','source_digest'=>'report','competitive_analysis','due_diligence','comparison','analysis'=>'analysis',default=>'document'};
}

function research_task_deliverable_html(PDO $pdo,array $plan): array {
    $tasks=research_task_plan_structure($pdo,(int)$plan['id']);$esc=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $html='<h1>'.$esc($plan['deliverable_title']?:$plan['title']).'</h1><p><strong>Objective:</strong> '.$esc($plan['objective']).'</p><h2>Research status</h2><ul>';
    foreach($tasks as $task)$html.='<li><strong>'.$esc($task['title']).'</strong> — '.$esc(str_replace('_',' ',(string)$task['status'])).'</li>';$html.='</ul><h2>Findings by task</h2>';
    $q=$pdo->prepare("SELECT public_id,title,status,execution_summary FROM research_tasks WHERE plan_id=? ORDER BY position,id");$q->execute([(int)$plan['id']]);
    foreach($q->fetchAll() as $task){$html.='<h3>'.$esc($task['title']).'</h3>';if(trim((string)$task['execution_summary'])!=='')$html.='<p>'.$esc($task['execution_summary']).'</p>';else $html.='<p>Research task '.$esc($task['status']).'.</p>';
      $rq=$pdo->prepare("SELECT ref_type,ref_public_id,locator,relationship FROM research_task_evidence_refs tre JOIN research_tasks rt ON rt.id=tre.task_id WHERE rt.public_id=? ORDER BY tre.id");$rq->execute([$task['public_id']]);$refs=$rq->fetchAll();if($refs){$html.='<ul>';foreach($refs as $r)$html.='<li>['.$esc(strtoupper((string)$r['ref_type'])).' '.$esc($r['ref_public_id']).($r['locator']?' · '.$esc($r['locator']):'').'] '.$esc($r['relationship']).'</li>';$html.='</ul>';}}
    $html.='<h2>Open work</h2><p>'.(count(array_filter($tasks,fn($t)=>!in_array($t['status'],['complete','done','archived'],true)))?'This deliverable is still being maintained while tasks remain open.':'All planned tasks are complete.').'</p>';
    $state=hash('sha256',json_encode($tasks,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return ['html'=>$html,'state_hash'=>$state];
}

function research_task_refresh_deliverable(PDO $pdo,array $viewer,string $planPublic): ?array {
    $plan=research_task_plan_access($pdo,$viewer,$planPublic);if(!$plan)return null;$agent=research_task_agent($pdo,$viewer,(string)$plan['agent_public_id']);$project=research_task_project($pdo,$viewer,$agent);$body=research_task_deliverable_html($pdo,$plan);
    $q=$pdo->prepare("SELECT rtd.*,rwo.public_id object_public_id,rwd.revision_number FROM research_task_deliverables rtd JOIN research_workspace_objects rwo ON rwo.id=rtd.workspace_object_id JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id WHERE rtd.plan_id=? LIMIT 1");$q->execute([(int)$plan['id']]);$d=$q->fetch();
    if($d){
        if($d['status']==='finalized')return $d;
        if((int)$d['revision_number']!==(int)$d['managed_revision_number']){$pdo->prepare("UPDATE research_task_deliverables SET status='needs_review',updated_at=NOW() WHERE id=?")->execute([(int)$d['id']]);$d['status']='needs_review';return $d;}
        if(hash_equals((string)($d['last_task_state_hash']??''),(string)$body['state_hash']))return $d;
        $doc=research_agent_workspace_save_document($pdo,$viewer,(string)$d['object_public_id'],['title'=>(string)($plan['deliverable_title']?:$plan['title']),'content_html'=>$body['html'],'summary'=>'Living deliverable for '.$plan['title'],'base_revision'=>(int)$d['revision_number']]);
        $pdo->prepare("UPDATE research_task_deliverables SET status='active',managed_revision_number=?,last_task_state_hash=?,updated_at=NOW() WHERE id=?")->execute([(int)$doc['revision_number'],$body['state_hash'],(int)$d['id']]);research_task_event($pdo,(int)$plan['project_id'],(int)$plan['id'],null,'deliverable_updated','agent',null,['document_public_id'=>$doc['public_id'],'revision'=>(int)$doc['revision_number']]);if(function_exists('research_retrieval_queue_project'))research_retrieval_queue_project($pdo,(int)$plan['project_id']);return $doc;
    }
    $doc=research_agent_workspace_create_document($pdo,$viewer,$project,['title'=>(string)($plan['deliverable_title']?:$plan['title']),'document_type'=>research_task_deliverable_document_type((string)$plan['deliverable_type']),'content_html'=>$body['html'],'summary'=>'Living deliverable for '.$plan['title']],true);
    $obj=research_agent_workspace_object($pdo,$viewer,(string)$doc['public_id'],false);$public=ulid_like();$pdo->prepare("INSERT INTO research_task_deliverables(public_id,plan_id,research_agent_id,project_id,workspace_object_id,deliverable_type,status,managed_revision_number,last_task_state_hash) VALUES(?,?,?,?,?,?,'active',?,?)")
      ->execute([$public,(int)$plan['id'],(int)$plan['research_agent_id'],(int)$plan['project_id'],(int)$obj['id'],(string)$plan['deliverable_type'],(int)$doc['revision_number'],$body['state_hash']]);research_task_event($pdo,(int)$plan['project_id'],(int)$plan['id'],null,'deliverable_created','agent',null,['document_public_id'=>$doc['public_id']]);if(function_exists('research_retrieval_queue_project'))research_retrieval_queue_project($pdo,(int)$plan['project_id']);return $doc;
}

function research_task_refresh_deliverable_by_plan_id(PDO $pdo,int $planId): void {
    $q=$pdo->prepare("SELECT rtp.public_id,rtp.research_agent_id FROM research_task_plans rtp WHERE rtp.id=? LIMIT 1");$q->execute([$planId]);$row=$q->fetch();if(!$row)return;$agent=research_task_agent_by_id($pdo,(int)$row['research_agent_id']);if(!$agent)return;$viewer=research_task_owner($pdo,$agent);research_task_refresh_deliverable($pdo,$viewer,(string)$row['public_id']);
}

function research_task_review(PDO $pdo,array $viewer,string $taskPublic,bool $approve=true): array {
    $task=research_task_access($pdo,$viewer,$taskPublic);if(!$task)throw new RuntimeException('Research task not found.');$agent=research_task_agent($pdo,$viewer,(string)$task['agent_public_id']);research_task_project($pdo,$viewer,$agent);
    if($approve){$pdo->prepare("UPDATE research_tasks SET human_reviewed_at=NOW(),human_reviewed_by_user_id=?,updated_at=NOW() WHERE id=?")->execute([(int)$viewer['id'],(int)$task['id']]);$task['human_reviewed_at']=gmdate('Y-m-d H:i:s');$evaluation=research_task_gate_evaluate($pdo,$task);$status=$evaluation['pass']?'complete':'review';$pdo->prepare("UPDATE research_tasks SET status=?,completion_evaluation_json=?,completed_at=CASE WHEN ?='complete' THEN NOW() ELSE completed_at END,updated_at=NOW() WHERE id=?")->execute([$status,json_encode($evaluation,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$status,(int)$task['id']]);}
    else{$pdo->prepare("UPDATE research_tasks SET status='waiting',blocking_reason='Human review requested more work.',human_reviewed_at=NULL,human_reviewed_by_user_id=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);$status='waiting';}
    research_task_event($pdo,(int)$task['project_id'],$task['plan_id']?(int)$task['plan_id']:null,(int)$task['id'],$approve?'review_approved':'review_reopened','user',(int)$viewer['id']);if($task['plan_id']){research_task_plan_recalculate($pdo,(int)$task['plan_id']);research_task_queue_ready($pdo,(int)$task['plan_id'],(int)$viewer['id'],'dependency_ready');research_task_refresh_deliverable_by_plan_id($pdo,(int)$task['plan_id']);}
    return research_task_access($pdo,$viewer,$taskPublic)??$task;
}

function research_task_gate_waive(PDO $pdo,array $viewer,string $taskPublic,string $gateType): array {
    $task=research_task_access($pdo,$viewer,$taskPublic);if(!$task)throw new RuntimeException('Research task not found.');$agent=research_task_agent($pdo,$viewer,(string)$task['agent_public_id']);research_task_project($pdo,$viewer,$agent);
    $q=$pdo->prepare("UPDATE research_task_completion_gates SET status='waived',waived_by_user_id=?,waived_at=NOW(),evaluated_at=NOW(),detail='Gate waived by user.',updated_at=NOW() WHERE task_id=? AND gate_type=?");$q->execute([(int)$viewer['id'],(int)$task['id'],$gateType]);if(!$q->rowCount())throw new RuntimeException('Completion gate not found.');
    $evaluation=research_task_gate_evaluate($pdo,$task);if($evaluation['pass']&&trim((string)($task['execution_summary']??''))!=='')$pdo->prepare("UPDATE research_tasks SET status='complete',completion_evaluation_json=?,completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([json_encode($evaluation,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$task['id']]);
    research_task_event($pdo,(int)$task['project_id'],$task['plan_id']?(int)$task['plan_id']:null,(int)$task['id'],'gate_waived','user',(int)$viewer['id'],['gate_type'=>$gateType]);if($task['plan_id']){research_task_plan_recalculate($pdo,(int)$task['plan_id']);research_task_queue_ready($pdo,(int)$task['plan_id'],(int)$viewer['id'],'dependency_ready');research_task_refresh_deliverable_by_plan_id($pdo,(int)$task['plan_id']);}
    return research_task_access($pdo,$viewer,$taskPublic)??$task;
}

function research_task_plan_set_status(PDO $pdo,array $viewer,string $planPublic,string $status): array {
    $plan=research_task_plan_access($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Research plan not found.');$agent=research_task_agent($pdo,$viewer,(string)$plan['agent_public_id']);research_task_project($pdo,$viewer,$agent);
    if(!in_array($status,['active','paused','archived'],true))throw new InvalidArgumentException('Invalid plan status.');$pdo->prepare("UPDATE research_task_plans SET status=?,updated_at=NOW() WHERE id=?")->execute([$status,(int)$plan['id']]);
    if($status!=='active')$pdo->prepare("UPDATE research_task_jobs SET status='done',claim_token=NULL,lease_expires_at=NULL,completed_at=NOW(),rerun_requested=0 WHERE plan_id=? AND status='queued'")->execute([(int)$plan['id']]);else research_task_queue_ready($pdo,(int)$plan['id'],(int)$viewer['id'],'replan');
    research_task_event($pdo,(int)$plan['project_id'],(int)$plan['id'],null,'plan_'.$status,'user',(int)$viewer['id']);return research_task_plan_access($pdo,$viewer,$planPublic)??$plan;
}

function research_task_summary(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_task_agent($pdo,$viewer,$agentPublic);
    $q=$pdo->prepare("SELECT status,COUNT(*) total FROM research_tasks WHERE research_agent_id=? AND status<>'archived' GROUP BY status");$q->execute([(int)$agent['id']]);$tasks=[];foreach($q->fetchAll() as $r)$tasks[$r['status']]=(int)$r['total'];
    $q=$pdo->prepare("SELECT status,COUNT(*) total FROM research_task_plans WHERE research_agent_id=? AND status<>'archived' GROUP BY status");$q->execute([(int)$agent['id']]);$plans=[];foreach($q->fetchAll() as $r)$plans[$r['status']]=(int)$r['total'];
    return ['agent_public_id'=>$agentPublic,'tasks'=>$tasks,'plans'=>$plans,'active'=>(int)array_sum(array_intersect_key($tasks,array_flip(['queued','ready','researching','open','in_progress']))),'waiting'=>(int)(($tasks['waiting']??0)+($tasks['failed']??0)),'review'=>(int)($tasks['review']??0),'complete'=>(int)(($tasks['complete']??0)+($tasks['done']??0))];
}

function research_task_plan_detail(PDO $pdo,array $viewer,string $planPublic): ?array {
    $plan=research_task_plan_access($pdo,$viewer,$planPublic);if(!$plan)return null;$q=$pdo->prepare("SELECT public_id FROM research_tasks WHERE plan_id=? ORDER BY position,id");$q->execute([(int)$plan['id']]);$tasks=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$task=research_task_access($pdo,$viewer,(string)$id);if(!$task)continue;$g=$pdo->prepare("SELECT gate_type,status,detail,required_json FROM research_task_completion_gates WHERE task_id=? ORDER BY id");$g->execute([(int)$task['id']]);$task['gates']=$g->fetchAll()?:[];$tasks[]=$task;}
    $q=$pdo->prepare("SELECT rtd.*,rwo.public_id document_public_id,rwo.title document_title,rwd.revision_number FROM research_task_deliverables rtd JOIN research_workspace_objects rwo ON rwo.id=rtd.workspace_object_id JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id WHERE rtd.plan_id=? LIMIT 1");$q->execute([(int)$plan['id']]);$plan['deliverable']=$q->fetch()?:null;$plan['tasks']=$tasks;return $plan;
}

function research_task_signal_plan(PDO $pdo,array $viewer,array $agent,array $project): array {
    $q=$pdo->prepare("SELECT public_id FROM research_task_plans WHERE research_agent_id=? AND status IN ('active','paused') AND created_by_agent=1 AND title='Agent Research Queue' ORDER BY id LIMIT 1");$q->execute([(int)$agent['id']]);$public=(string)($q->fetchColumn()?:'');
    if($public!=='')return research_task_plan_access($pdo,$viewer,$public)??[];
    return research_task_plan_create($pdo,$viewer,['agent_id'=>$agent['public_id'],'title'=>'Agent Research Queue','objective'=>'Track and resolve evidence gaps, contradictions, and meaningful monitored changes that require follow-up.','priority'=>'high','deliverable_type'=>'research_brief','deliverable_title'=>$agent['name'].' Research Queue Brief','tasks'=>[]],true);
}

function research_task_sync_agent_signals(PDO $pdo,int $agentId): int {
    if(!research_tasks_ready($pdo))return 0;$agent=research_task_agent_by_id($pdo,$agentId);if(!$agent)return 0;$viewer=research_task_owner($pdo,$agent);$project=research_task_project($pdo,$viewer,$agent);$plan=research_task_signal_plan($pdo,$viewer,$agent,$project);if(!$plan)return 0;$count=0;
    if(installer_table_exists($pdo,'research_autonomy_observations')){$q=$pdo->prepare("SELECT public_id,observation_type,title,detail,severity FROM research_autonomy_observations WHERE project_id=? AND status='open' AND observation_type IN ('evidence_gap','contradiction') ORDER BY FIELD(severity,'high','medium','low','info'),updated_at DESC LIMIT 40");$q->execute([(int)$project['id']]);foreach($q->fetchAll() as $o){$fingerprint=hash('sha256','autonomy|'.$o['public_id']);$input=['title'=>(string)$o['title'],'description'=>(string)$o['detail'],'task_type'=>$o['observation_type']==='contradiction'?'verify_claim':'find_source','priority'=>$o['severity']==='high'?'high':'medium'];try{$task=research_task_create($pdo,$viewer,$agent,$project,$input,(int)$plan['id'],true,'autonomy_observation',(string)$o['public_id'],$fingerprint);research_task_queue($pdo,(int)$task['id'],null,'signal');$count++;}catch(PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}}}
    if(installer_table_exists($pdo,'research_monitor_events')){$q=$pdo->prepare("SELECT public_id,event_type,importance,summary FROM research_monitor_events WHERE project_id=? AND importance IN ('important','high') AND occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY id DESC LIMIT 60");$q->execute([(int)$project['id']]);foreach($q->fetchAll() as $e){$fingerprint=hash('sha256','monitor|'.$e['public_id']);$type=str_starts_with((string)$e['event_type'],'claim_')?'verify_claim':'review_source_change';$input=['title'=>'Follow up: '.mb_substr((string)$e['summary'],0,180),'description'=>(string)$e['summary'],'task_type'=>$type,'priority'=>$e['importance']==='high'?'high':'medium'];try{$task=research_task_create($pdo,$viewer,$agent,$project,$input,(int)$plan['id'],true,'monitor_event',(string)$e['public_id'],$fingerprint);research_task_queue($pdo,(int)$task['id'],null,'signal');$count++;}catch(PDOException $x){if((string)$x->getCode()!=='23000')throw $x;}}}
    if($count>0){$q=$pdo->prepare("UPDATE research_task_plans SET current_revision=current_revision+1,updated_at=NOW() WHERE id=?");$q->execute([(int)$plan['id']]);$q=$pdo->prepare("SELECT * FROM research_task_plans WHERE id=?");$q->execute([(int)$plan['id']]);$fresh=$q->fetch();research_task_plan_snapshot($pdo,$fresh,'Agent added tasks from new Research signals',null,true);research_task_refresh_deliverable($pdo,$viewer,(string)$plan['public_id']);}
    return $count;
}

function research_task_agent_for_project(PDO $pdo,array $viewer,string $projectPublic): ?array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)return null;
    $q=$pdo->prepare("SELECT public_id FROM research_agents WHERE project_id=? AND status='active' ORDER BY is_default DESC,id ASC LIMIT 1");$q->execute([(int)$project['id']]);$public=(string)($q->fetchColumn()?:'');
    return $public!==''?research_agent_access($pdo,$viewer,$public):null;
}

function research_task_update(PDO $pdo,array $viewer,string $taskPublic,array $input): array {
    $task=research_task_access($pdo,$viewer,$taskPublic);if(!$task)throw new RuntimeException('Research task not found.');if(($task['status']??'')==='researching')throw new RuntimeException('Wait for the active task run to finish before editing this task.');
    $agent=research_task_agent($pdo,$viewer,(string)$task['agent_public_id']);research_task_project($pdo,$viewer,$agent);
    $title=mb_substr(trim((string)($input['title']??$task['title'])),0,255);if($title==='')throw new InvalidArgumentException('Task title is required.');
    $description=mb_substr(trim((string)($input['description']??$task['description']??'')),0,12000);
    $type=(string)($input['task_type']??$task['task_type']);if(!isset(research_task_types()[$type]))$type=(string)$task['task_type'];
    $priority=(string)($input['priority']??$task['priority']);if(!isset(research_task_priorities()[$priority]))$priority=(string)$task['priority'];
    $due=array_key_exists('due_at',$input)?research_task_clean_due($input['due_at']):$task['due_at'];$typeChanged=$type!==(string)$task['task_type'];
    $pdo->prepare("UPDATE research_tasks SET title=?,description=?,task_type=?,priority=?,due_at=?,status='queued',blocking_reason=NULL,execution_summary=NULL,execution_refs_json=NULL,completion_evaluation_json=NULL,human_reviewed_at=NULL,human_reviewed_by_user_id=NULL,completed_at=NULL,updated_at=NOW() WHERE id=?")
      ->execute([$title,$description!==''?$description:null,$type,$priority,$due,(int)$task['id']]);
    if($typeChanged){$pdo->prepare('DELETE FROM research_task_completion_gates WHERE task_id=?')->execute([(int)$task['id']]);research_task_add_gates($pdo,(int)$task['id'],is_array($input['gates']??null)?$input['gates']:research_task_default_gates($type));}
    elseif(is_array($input['gates']??null)){research_task_add_gates($pdo,(int)$task['id'],$input['gates']);}
    $pdo->prepare('DELETE FROM research_task_evidence_refs WHERE task_id=?')->execute([(int)$task['id']]);
    research_task_event($pdo,(int)$task['project_id'],$task['plan_id']?(int)$task['plan_id']:null,(int)$task['id'],'revised','user',(int)$viewer['id'],['task_type'=>$type,'priority'=>$priority]);
    if($task['plan_id']){$q=$pdo->prepare("UPDATE research_task_plans SET current_revision=current_revision+1,status=CASE WHEN status='completed' THEN 'active' ELSE status END,completed_at=CASE WHEN status='completed' THEN NULL ELSE completed_at END,updated_at=NOW() WHERE id=?");$q->execute([(int)$task['plan_id']]);$q=$pdo->prepare("SELECT * FROM research_task_plans WHERE id=?");$q->execute([(int)$task['plan_id']]);$plan=$q->fetch();research_task_plan_snapshot($pdo,$plan,'Task revised',(int)$viewer['id'],false);}
    research_task_queue($pdo,(int)$task['id'],(int)$viewer['id'],'replan');if($task['plan_id'])research_task_refresh_deliverable_by_plan_id($pdo,(int)$task['plan_id']);
    return research_task_access($pdo,$viewer,$taskPublic)??$task;
}

function research_task_plan_add_task(PDO $pdo,array $viewer,string $planPublic,array $input,bool $createdByAgent=false): array {
    $plan=research_task_plan_access($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Research plan not found.');$agent=research_task_agent($pdo,$viewer,(string)$plan['agent_public_id']);$project=research_task_project($pdo,$viewer,$agent);
    if($plan['status']==='completed'){$pdo->prepare("UPDATE research_task_plans SET status='active',completed_at=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$plan['id']]);$plan['status']='active';$plan['completed_at']=null;}
    $q=$pdo->prepare("SELECT COALESCE(MAX(position),-1)+1 FROM research_tasks WHERE plan_id=?");$q->execute([(int)$plan['id']]);$input['position']=(int)$q->fetchColumn();
    $task=research_task_create($pdo,$viewer,$agent,$project,$input,(int)$plan['id'],$createdByAgent);
    foreach(array_slice((array)($input['depends_on']??[]),0,12) as $dependencyPublic){$dep=research_task_access($pdo,$viewer,(string)$dependencyPublic);if(!$dep||(int)$dep['plan_id']!==(int)$plan['id']||(int)$dep['id']===(int)$task['id'])continue;$pdo->prepare("INSERT IGNORE INTO research_task_dependencies(task_id,depends_on_task_id,dependency_type) VALUES(?,?,'finish_to_start')")->execute([(int)$task['id'],(int)$dep['id']]);}
    $next=(int)$plan['current_revision']+1;$pdo->prepare("UPDATE research_task_plans SET current_revision=?,updated_at=NOW() WHERE id=?")->execute([$next,(int)$plan['id']]);$q=$pdo->prepare("SELECT * FROM research_task_plans WHERE id=?");$q->execute([(int)$plan['id']]);$fresh=$q->fetch();research_task_plan_snapshot($pdo,$fresh,'Task added',(int)$viewer['id'],$createdByAgent);research_task_queue($pdo,(int)$task['id'],(int)$viewer['id'],'manual');research_task_refresh_deliverable($pdo,$viewer,$planPublic);return research_task_access($pdo,$viewer,(string)$task['public_id'])??$task;
}

function research_task_create_for_project(PDO $pdo,array $viewer,array $project,array $input,bool $createdByAgent=false): array {
    $agent=research_task_agent_for_project($pdo,$viewer,(string)$project['public_id']);if(!$agent)throw new RuntimeException('This Research project has no active Research Agent.');
    $plan=research_task_signal_plan($pdo,$viewer,$agent,$project);return research_task_plan_add_task($pdo,$viewer,(string)$plan['public_id'],$input,$createdByAgent);
}

function research_task_deliverable_resume(PDO $pdo,array $viewer,string $planPublic): array {
    $plan=research_task_plan_access($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Research plan not found.');$agent=research_task_agent($pdo,$viewer,(string)$plan['agent_public_id']);research_task_project($pdo,$viewer,$agent);
    $q=$pdo->prepare("SELECT rtd.id,rwo.public_id,rwd.revision_number FROM research_task_deliverables rtd JOIN research_workspace_objects rwo ON rwo.id=rtd.workspace_object_id JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id WHERE rtd.plan_id=? LIMIT 1");$q->execute([(int)$plan['id']]);$d=$q->fetch();if(!$d)throw new RuntimeException('Plan deliverable is unavailable.');
    $pdo->prepare("UPDATE research_task_deliverables SET status='active',managed_revision_number=?,last_task_state_hash=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$d['revision_number'],(int)$d['id']]);research_task_event($pdo,(int)$plan['project_id'],(int)$plan['id'],null,'deliverable_management_resumed','user',(int)$viewer['id'],['document_public_id'=>$d['public_id']]);research_task_refresh_deliverable($pdo,$viewer,$planPublic);return research_task_plan_detail($pdo,$viewer,$planPublic)??$plan;
}

function research_task_deliverable_finalize(PDO $pdo,array $viewer,string $planPublic): array {
    $plan=research_task_plan_access($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Research plan not found.');$agent=research_task_agent($pdo,$viewer,(string)$plan['agent_public_id']);research_task_project($pdo,$viewer,$agent);
    $q=$pdo->prepare("UPDATE research_task_deliverables SET status='finalized',finalized_at=NOW(),updated_at=NOW() WHERE plan_id=? AND status<>'finalized'");$q->execute([(int)$plan['id']]);if(!$q->rowCount())throw new RuntimeException('Plan deliverable is already finalized or unavailable.');research_task_event($pdo,(int)$plan['project_id'],(int)$plan['id'],null,'deliverable_finalized','user',(int)$viewer['id']);return research_task_plan_detail($pdo,$viewer,$planPublic)??$plan;
}

function research_task_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=24): void {
    if(!research_tasks_ready($pdo))return;$limit=max(1,min(60,$limit));
    $q=$pdo->prepare("SELECT rt.public_id task_public_id,rt.title,rt.status,rt.priority,rt.blocking_reason,rt.updated_at,rtp.public_id plan_public_id,rtp.title plan_title,ra.public_id agent_public_id
      FROM research_tasks rt JOIN research_task_plans rtp ON rtp.id=rt.plan_id JOIN research_agents ra ON ra.id=rt.research_agent_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rt.status IN ('review','waiting','failed','ready','researching') AND rtp.status='active'
        AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?))
      ORDER BY FIELD(rt.status,'review','failed','waiting','ready','researching'),FIELD(rt.priority,'urgent','high','medium','low'),rt.updated_at DESC LIMIT ".$limit);
    $q->execute([(int)$viewer['id'],(int)$viewer['id'],(int)$viewer['id']]);
    foreach($q->fetchAll() as $r){
        $status=(string)$r['status'];$section=in_array($status,['review','waiting','failed'],true)?'needs_attention':'next_up';$priority=in_array($status,['review','failed'],true)||$r['priority']==='urgent'?'high':($r['priority']==='high'?'high':'medium');
        $title=match($status){'review'=>'Research task is ready for review','waiting'=>'Research task is waiting','failed'=>'Research task needs recovery','researching'=>'Research task is in progress',default=>'Research task is ready'};
        $body=(string)$r['plan_title'].' · '.(string)$r['title'];if(trim((string)($r['blocking_reason']??''))!=='')$body.=' · '.(string)$r['blocking_reason'];
        $url='/research-tasks.php?agent='.rawurlencode((string)$r['agent_public_id']).'&plan='.rawurlencode((string)$r['plan_public_id']);
        $actions=[cognitive_feed_action_link('Open task',$url)];
        if($status==='review')$actions[]=cognitive_feed_action_agent('Ask Agent','Summarize this Research task result, its evidence, completion gates, and anything that still needs human review. Do not approve the task for me.',[['type'=>'research_task','public_id'=>(string)$r['task_public_id']]]);
        cognitive_feed_add($items,['key'=>cognitive_feed_key('research_task_'.$status,'research_task',(string)$r['task_public_id'],(string)$r['updated_at']),'type'=>'research_task_'.$status,'section'=>$section,'priority'=>$priority,'created_at'=>$r['updated_at'],'score_extra'=>$priority==='high'?14:5,'title'=>$title,'body'=>$body,'meta'=>['task_id'=>$r['task_public_id'],'plan_id'=>$r['plan_public_id'],'status'=>$status],'actions'=>$actions]);
    }
}

function research_task_chat_update(PDO $pdo,int $planId,string $message,array $metadata=[]): void {
    $q=$pdo->prepare("SELECT ra.conversation_id,rtp.public_id plan_public_id FROM research_task_plans rtp JOIN research_agents ra ON ra.id=rtp.research_agent_id WHERE rtp.id=? LIMIT 1");$q->execute([$planId]);$x=$q->fetch();if(!$x)return;
    $public=ulid_like();$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,parent_message_id,body) VALUES(?,?,NULL,'agent',NULL,?)")->execute([$public,(int)$x['conversation_id'],mb_substr($message,0,12000)]);$messageId=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$x['conversation_id']]);
    $pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,message_id,payload_json) VALUES(?,'agent_message_created',?,?)")->execute([(int)$x['conversation_id'],$messageId,json_encode(array_merge(['source'=>'research_tasks','plan_id'=>$x['plan_public_id']],$metadata),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
}
