<?php
declare(strict_types=1);

function research_missions_ready(PDO $pdo): bool {
    try{
        foreach(['research_missions','research_mission_versions','research_mission_criteria','research_mission_subquestions','research_mission_events'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}
function research_mission_statuses(): array {return ['draft'=>'Draft','active'=>'Active','blocked'=>'Blocked','review'=>'Review','completed'=>'Completed','cancelled'=>'Cancelled','archived'=>'Archived'];}
function research_mission_priorities(): array {return ['low'=>'Low','medium'=>'Medium','high'=>'High','urgent'=>'Urgent'];}
function research_mission_criterion_types(): array {return ['answer'=>'Answer','evidence'=>'Evidence','coverage'=>'Coverage','quality'=>'Quality','custom'=>'Custom'];}
function research_mission_subquestion_statuses(): array {return ['open'=>'Open','researching'=>'Researching','answered'=>'Answered','blocked'=>'Blocked','deferred'=>'Deferred'];}

function research_mission_agent(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_task_agent($pdo,$viewer,trim($agentPublic));if(!$agent)throw new RuntimeException('Research Agent is unavailable.');
    return $agent;
}
function research_mission_project(PDO $pdo,array $viewer,array $agent): array {return research_task_project($pdo,$viewer,$agent);}
function research_mission_json_object($value): array {
    if(is_array($value))return $value;
    if(is_string($value)&&trim($value)!==''){$decoded=json_decode($value,true);if(is_array($decoded))return $decoded;}
    return [];
}
function research_mission_text(string $value,int $limit): string {return mb_substr(trim($value),0,$limit);}
function research_mission_event(PDO $pdo,array $mission,string $event,string $actor='system',?int $actorUserId=null,array $payload=[]): void {
    if(!in_array($actor,['user','agent','system'],true))$actor='system';
    $pdo->prepare("INSERT INTO research_mission_events(public_id,mission_id,project_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$mission['id'],(int)$mission['project_id'],research_mission_text($event,64),$actor,$actorUserId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}
function research_mission_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_missions_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rm.*,ra.public_id agent_public_id,ra.name agent_name,ra.team_id,proj.public_id project_public_id,proj.title project_title,
      rtp.public_id plan_public_id,rprog.public_id program_public_id
      FROM research_missions rm
      JOIN research_agents ra ON ra.id=rm.research_agent_id
      JOIN research_projects proj ON proj.id=rm.project_id
      LEFT JOIN research_task_plans rtp ON rtp.id=rm.plan_id
      LEFT JOIN research_programs rprog ON rprog.id=rm.program_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rm.public_id=? AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($publicId),(int)$viewer['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}
function research_mission_by_id(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare("SELECT rm.*,ra.public_id agent_public_id,ra.name agent_name,proj.public_id project_public_id,proj.title project_title,
      rtp.public_id plan_public_id,rprog.public_id program_public_id
      FROM research_missions rm JOIN research_agents ra ON ra.id=rm.research_agent_id JOIN research_projects proj ON proj.id=rm.project_id
      LEFT JOIN research_task_plans rtp ON rtp.id=rm.plan_id LEFT JOIN research_programs rprog ON rprog.id=rm.program_id WHERE rm.id=? LIMIT 1");
    $q->execute([$id]);return $q->fetch()?:null;
}
function research_mission_criteria(PDO $pdo,int $missionId): array {
    $q=$pdo->prepare("SELECT * FROM research_mission_criteria WHERE mission_id=? ORDER BY position,id");$q->execute([$missionId]);return $q->fetchAll()?:[];
}
function research_mission_subquestions(PDO $pdo,int $missionId): array {
    $q=$pdo->prepare("SELECT rms.*,rt.public_id linked_task_public_id FROM research_mission_subquestions rms LEFT JOIN research_tasks rt ON rt.id=rms.linked_task_id WHERE rms.mission_id=? ORDER BY rms.position,rms.id");
    $q->execute([$missionId]);return $q->fetchAll()?:[];
}
function research_mission_events(PDO $pdo,int $missionId,int $limit=100): array {
    $limit=max(1,min(250,$limit));$q=$pdo->prepare("SELECT public_id,event_type,actor_type,actor_user_id,payload_json,created_at FROM research_mission_events WHERE mission_id=? ORDER BY id DESC LIMIT ".$limit);$q->execute([$missionId]);return $q->fetchAll()?:[];
}
function research_mission_config(PDO $pdo,array $mission): array {
    $criteria=[];foreach(research_mission_criteria($pdo,(int)$mission['id']) as $c)$criteria[]=[
      'public_id'=>(string)$c['public_id'],'criterion_type'=>(string)$c['criterion_type'],'label'=>(string)$c['label'],'description'=>(string)($c['description']??''),
      'target'=>research_mission_json_object($c['target_json']??null),'position'=>(int)$c['position']
    ];
    $questions=[];foreach(research_mission_subquestions($pdo,(int)$mission['id']) as $q)$questions[]=[
      'public_id'=>(string)$q['public_id'],'question'=>(string)$q['question'],'rationale'=>(string)($q['rationale']??''),'priority'=>(string)$q['priority'],'position'=>(int)$q['position']
    ];
    return [
      'title'=>(string)$mission['title'],'research_question'=>(string)$mission['research_question'],'objective'=>(string)$mission['objective'],
      'success_definition'=>(string)($mission['success_definition']??''),'priority'=>(string)$mission['priority'],
      'scope'=>research_mission_json_object($mission['scope_json']??null),'constraints'=>research_mission_json_object($mission['constraints_json']??null),
      'criteria'=>$criteria,'subquestions'=>$questions
    ];
}
function research_mission_hash(array $config): string {return hash('sha256',json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
function research_mission_snapshot(PDO $pdo,array $mission,string $reason='',?int $userId=null,bool $byAgent=false): void {
    $config=research_mission_config($pdo,$mission);
    $pdo->prepare("INSERT INTO research_mission_versions(public_id,mission_id,revision_number,config_json,change_reason,edited_by_user_id,edited_by_agent) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$mission['id'],(int)$mission['current_revision'],json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$reason!==''?research_mission_text($reason,1000):null,$userId,$byAgent?1:0]);
}
function research_mission_refresh_revision(PDO $pdo,int $missionId,?int $userId,string $reason,bool $byAgent=false): array {
    $mission=research_mission_by_id($pdo,$missionId);if(!$mission)throw new RuntimeException('Research Mission is unavailable.');
    $config=research_mission_config($pdo,$mission);$hash=research_mission_hash($config);
    if(hash_equals((string)$mission['config_hash'],$hash))return $mission;
    $revision=(int)$mission['current_revision']+1;
    $pdo->prepare("UPDATE research_missions SET current_revision=?,config_hash=?,updated_at=NOW() WHERE id=?")->execute([$revision,$hash,$missionId]);
    $mission=research_mission_by_id($pdo,$missionId);research_mission_snapshot($pdo,$mission,$reason,$userId,$byAgent);return $mission;
}
function research_mission_clean_criteria(array $items): array {
    $out=[];$position=0;foreach(array_slice($items,0,20) as $raw){
        if(is_string($raw))$raw=['label'=>$raw];if(!is_array($raw))continue;$label=research_mission_text((string)($raw['label']??''),255);if($label==='')continue;
        $type=(string)($raw['criterion_type']??$raw['type']??'custom');if(!isset(research_mission_criterion_types()[$type]))$type='custom';
        $out[]=['criterion_type'=>$type,'label'=>$label,'description'=>research_mission_text((string)($raw['description']??''),8000),'target'=>research_mission_json_object($raw['target']??[]),'position'=>$position++];
    }return $out;
}
function research_mission_clean_subquestions(array $items,string $defaultPriority='medium'): array {
    $out=[];$position=0;foreach(array_slice($items,0,30) as $raw){
        if(is_string($raw))$raw=['question'=>$raw];if(!is_array($raw))continue;$question=research_mission_text((string)($raw['question']??''),12000);if($question==='')continue;
        $priority=(string)($raw['priority']??$defaultPriority);if(!isset(research_mission_priorities()[$priority]))$priority=$defaultPriority;
        $out[]=['question'=>$question,'rationale'=>research_mission_text((string)($raw['rationale']??''),8000),'priority'=>$priority,'position'=>$position++];
    }return $out;
}
function research_mission_insert_criterion(PDO $pdo,int $missionId,array $criterion): string {
    $public=ulid_like();$pdo->prepare("INSERT INTO research_mission_criteria(public_id,mission_id,criterion_type,label,description,target_json,position) VALUES(?,?,?,?,?,?,?)")
      ->execute([$public,$missionId,$criterion['criterion_type'],$criterion['label'],$criterion['description']!==''?$criterion['description']:null,$criterion['target']?json_encode($criterion['target'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,(int)$criterion['position']]);return $public;
}
function research_mission_insert_subquestion(PDO $pdo,int $missionId,array $question): string {
    $public=ulid_like();$pdo->prepare("INSERT INTO research_mission_subquestions(public_id,mission_id,question,rationale,priority,position) VALUES(?,?,?,?,?,?)")
      ->execute([$public,$missionId,$question['question'],$question['rationale']!==''?$question['rationale']:null,$question['priority'],(int)$question['position']]);return $public;
}
function research_mission_create(PDO $pdo,array $viewer,array $input,bool $byAgent=false): array {
    if(!research_missions_ready($pdo))throw new RuntimeException('Research Missions require the latest database upgrade.');
    $agent=research_mission_agent($pdo,$viewer,(string)($input['agent_id']??''));$project=research_mission_project($pdo,$viewer,$agent);
    $title=research_mission_text((string)($input['title']??''),255);$question=research_mission_text((string)($input['research_question']??$input['question']??''),16000);$objective=research_mission_text((string)($input['objective']??''),16000);
    if($title===''||$question===''||$objective==='')throw new InvalidArgumentException('Mission title, research question, and objective are required.');
    $priority=(string)($input['priority']??'medium');if(!isset(research_mission_priorities()[$priority]))$priority='medium';
    $success=research_mission_text((string)($input['success_definition']??''),16000);$scope=research_mission_json_object($input['scope']??[]);$constraints=research_mission_json_object($input['constraints']??[]);
    $criteria=research_mission_clean_criteria((array)($input['success_criteria']??$input['criteria']??[]));$questions=research_mission_clean_subquestions((array)($input['subquestions']??[]),$priority);$public=ulid_like();
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $placeholder=str_repeat('0',64);
        $pdo->prepare("INSERT INTO research_missions(public_id,research_agent_id,project_id,created_by_user_id,title,research_question,objective,success_definition,status,priority,scope_json,constraints_json,current_revision,config_hash)
          VALUES(?,?,?,?,?,?,?,?, 'draft',?,?,?,1,?)")
          ->execute([$public,(int)$agent['id'],(int)$project['id'],(int)$viewer['id'],$title,$question,$objective,$success!==''?$success:null,$priority,$scope?json_encode($scope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$constraints?json_encode($constraints,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$placeholder]);
        $missionId=(int)$pdo->lastInsertId();
        foreach($criteria as $criterion)research_mission_insert_criterion($pdo,$missionId,$criterion);
        foreach($questions as $subquestion)research_mission_insert_subquestion($pdo,$missionId,$subquestion);
        $mission=research_mission_by_id($pdo,$missionId);$config=research_mission_config($pdo,$mission);$hash=research_mission_hash($config);
        $pdo->prepare("UPDATE research_missions SET config_hash=? WHERE id=?")->execute([$hash,$missionId]);$mission=research_mission_by_id($pdo,$missionId);
        research_mission_snapshot($pdo,$mission,'Initial mission',(int)$viewer['id'],$byAgent);
        research_mission_event($pdo,$mission,'mission_created',$byAgent?'agent':'user',(int)$viewer['id'],['criteria_count'=>count($criteria),'subquestion_count'=>count($questions)]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_mission_detail($pdo,$viewer,$public)??['public_id'=>$public,'title'=>$title];
}
function research_mission_update(PDO $pdo,array $viewer,string $publicId,array $input,bool $byAgent=false): array {
    $mission=research_mission_access($pdo,$viewer,$publicId);if(!$mission)throw new RuntimeException('Research Mission not found.');
    $title=research_mission_text((string)($input['title']??$mission['title']),255);$question=research_mission_text((string)($input['research_question']??$input['question']??$mission['research_question']),16000);$objective=research_mission_text((string)($input['objective']??$mission['objective']),16000);
    if($title===''||$question===''||$objective==='')throw new InvalidArgumentException('Mission title, research question, and objective are required.');
    $priority=(string)($input['priority']??$mission['priority']);if(!isset(research_mission_priorities()[$priority]))$priority=(string)$mission['priority'];
    $success=array_key_exists('success_definition',$input)?research_mission_text((string)$input['success_definition'],16000):(string)($mission['success_definition']??'');
    $scope=array_key_exists('scope',$input)?research_mission_json_object($input['scope']):research_mission_json_object($mission['scope_json']??null);
    $constraints=array_key_exists('constraints',$input)?research_mission_json_object($input['constraints']):research_mission_json_object($mission['constraints_json']??null);
    $pdo->prepare("UPDATE research_missions SET title=?,research_question=?,objective=?,success_definition=?,priority=?,scope_json=?,constraints_json=?,updated_at=NOW() WHERE id=?")
      ->execute([$title,$question,$objective,$success!==''?$success:null,$priority,$scope?json_encode($scope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$constraints?json_encode($constraints,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,(int)$mission['id']]);
    $fresh=research_mission_refresh_revision($pdo,(int)$mission['id'],(int)$viewer['id'],research_mission_text((string)($input['reason']??'Mission updated'),1000),$byAgent);
    research_mission_event($pdo,$fresh,'mission_updated',$byAgent?'agent':'user',(int)$viewer['id'],['revision'=>(int)$fresh['current_revision']]);
    return research_mission_detail($pdo,$viewer,$publicId)??$fresh;
}
function research_mission_set_status(PDO $pdo,array $viewer,string $publicId,string $status,bool $byAgent=false): array {
    $mission=research_mission_access($pdo,$viewer,$publicId);if(!$mission)throw new RuntimeException('Research Mission not found.');
    if(!isset(research_mission_statuses()[$status]))throw new InvalidArgumentException('Invalid Mission status.');
    $current=(string)$mission['status'];if($current===$status)return research_mission_detail($pdo,$viewer,$publicId)??$mission;
    $allowed=[
      'draft'=>['active','cancelled','archived'],'active'=>['blocked','review','completed','cancelled','archived'],'blocked'=>['active','review','cancelled','archived'],
      'review'=>['active','blocked','completed','cancelled','archived'],'completed'=>['active','archived'],'cancelled'=>['active','archived'],'archived'=>['active']
    ];
    if(!in_array($status,$allowed[$current]??[],true))throw new InvalidArgumentException('That Mission status transition is not allowed.');
    if($status==='active')$pdo->prepare("UPDATE research_missions SET status='active',started_at=COALESCE(started_at,NOW()),completed_at=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$mission['id']]);
    elseif($status==='completed')$pdo->prepare("UPDATE research_missions SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$mission['id']]);
    else $pdo->prepare("UPDATE research_missions SET status=?,updated_at=NOW() WHERE id=?")->execute([$status,(int)$mission['id']]);
    $fresh=research_mission_by_id($pdo,(int)$mission['id']);research_mission_event($pdo,$fresh,'mission_status_changed',$byAgent?'agent':'user',(int)$viewer['id'],['from'=>$current,'to'=>$status]);
    return research_mission_detail($pdo,$viewer,$publicId)??$fresh;
}
function research_mission_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=100): array {
    $agent=research_mission_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT rm.*,rtp.public_id plan_public_id,rprog.public_id program_public_id,
      (SELECT COUNT(*) FROM research_mission_criteria c WHERE c.mission_id=rm.id) criteria_count,
      (SELECT COUNT(*) FROM research_mission_criteria c WHERE c.mission_id=rm.id AND c.status='satisfied') criteria_satisfied,
      (SELECT COUNT(*) FROM research_mission_subquestions s WHERE s.mission_id=rm.id) subquestion_count,
      (SELECT COUNT(*) FROM research_mission_subquestions s WHERE s.mission_id=rm.id AND s.status='answered') subquestion_answered
      FROM research_missions rm LEFT JOIN research_task_plans rtp ON rtp.id=rm.plan_id LEFT JOIN research_programs rprog ON rprog.id=rm.program_id
      WHERE rm.research_agent_id=? ORDER BY FIELD(rm.status,'active','blocked','review','draft','completed','cancelled','archived'),rm.updated_at DESC LIMIT ".$limit);
    $q->execute([(int)$agent['id']]);return $q->fetchAll()?:[];
}
function research_mission_summary(PDO $pdo,array $viewer,string $agentPublic): array {
    $missions=research_mission_list($pdo,$viewer,$agentPublic,200);$statuses=array_fill_keys(array_keys(research_mission_statuses()),0);$criteria=['total'=>0,'satisfied'=>0];$questions=['total'=>0,'answered'=>0];
    foreach($missions as $m){$statuses[(string)$m['status']]=($statuses[(string)$m['status']]??0)+1;$criteria['total']+=(int)$m['criteria_count'];$criteria['satisfied']+=(int)$m['criteria_satisfied'];$questions['total']+=(int)$m['subquestion_count'];$questions['answered']+=(int)$m['subquestion_answered'];}
    return ['missions'=>count($missions),'statuses'=>$statuses,'criteria'=>$criteria,'subquestions'=>$questions];
}
function research_mission_detail(PDO $pdo,array $viewer,string $publicId): ?array {
    $mission=research_mission_access($pdo,$viewer,$publicId);if(!$mission)return null;
    $mission['scope']=research_mission_json_object($mission['scope_json']??null);$mission['constraints']=research_mission_json_object($mission['constraints_json']??null);
    $mission['criteria']=research_mission_criteria($pdo,(int)$mission['id']);$mission['subquestions']=research_mission_subquestions($pdo,(int)$mission['id']);$mission['events']=research_mission_events($pdo,(int)$mission['id'],100);
    $mission['plan']=!empty($mission['plan_public_id'])?research_mission_plan_detail($pdo,$viewer,$mission):null;
    $mission['progress']=research_mission_progress_for_row($pdo,$viewer,$mission);
    return $mission;
}
function research_mission_criterion_access(PDO $pdo,array $viewer,string $publicId): ?array {
    $q=$pdo->prepare("SELECT c.*,m.public_id mission_public_id FROM research_mission_criteria c JOIN research_missions m ON m.id=c.mission_id WHERE c.public_id=? LIMIT 1");$q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    return research_mission_access($pdo,$viewer,(string)$row['mission_public_id'])?$row:null;
}
function research_mission_add_criterion(PDO $pdo,array $viewer,string $missionPublic,array $input,bool $byAgent=false): array {
    $mission=research_mission_access($pdo,$viewer,$missionPublic);if(!$mission)throw new RuntimeException('Research Mission not found.');$items=research_mission_clean_criteria([$input]);if(!$items)throw new InvalidArgumentException('Criterion label is required.');
    $q=$pdo->prepare("SELECT COALESCE(MAX(position),-1)+1 FROM research_mission_criteria WHERE mission_id=?");$q->execute([(int)$mission['id']]);$items[0]['position']=(int)$q->fetchColumn();$public=research_mission_insert_criterion($pdo,(int)$mission['id'],$items[0]);
    $fresh=research_mission_refresh_revision($pdo,(int)$mission['id'],(int)$viewer['id'],'Success criterion added',$byAgent);research_mission_event($pdo,$fresh,'criterion_added',$byAgent?'agent':'user',(int)$viewer['id'],['criterion_id'=>$public]);
    return research_mission_criterion_access($pdo,$viewer,$public)??['public_id'=>$public];
}
function research_mission_update_criterion(PDO $pdo,array $viewer,string $criterionPublic,array $input,bool $byAgent=false): array {
    $criterion=research_mission_criterion_access($pdo,$viewer,$criterionPublic);if(!$criterion)throw new RuntimeException('Mission criterion not found.');$mission=research_mission_access($pdo,$viewer,(string)$criterion['mission_public_id']);
    $label=research_mission_text((string)($input['label']??$criterion['label']),255);if($label==='')throw new InvalidArgumentException('Criterion label is required.');$type=(string)($input['criterion_type']??$input['type']??$criterion['criterion_type']);if(!isset(research_mission_criterion_types()[$type]))$type=(string)$criterion['criterion_type'];
    $description=research_mission_text((string)($input['description']??$criterion['description']??''),8000);$target=array_key_exists('target',$input)?research_mission_json_object($input['target']):research_mission_json_object($criterion['target_json']??null);$status=(string)($input['status']??$criterion['status']);if(!in_array($status,['pending','satisfied','failed','waived'],true))throw new InvalidArgumentException('Invalid criterion status.');
    $evaluation=array_key_exists('evaluation',$input)?research_mission_json_object($input['evaluation']):research_mission_json_object($criterion['evaluation_json']??null);$evaluated=$status==='pending'?null:date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE research_mission_criteria SET criterion_type=?,label=?,description=?,target_json=?,status=?,evaluation_json=?,evaluated_at=?,updated_at=NOW() WHERE id=?")
      ->execute([$type,$label,$description!==''?$description:null,$target?json_encode($target,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$status,$evaluation?json_encode($evaluation,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$evaluated,(int)$criterion['id']]);
    $fresh=research_mission_refresh_revision($pdo,(int)$mission['id'],(int)$viewer['id'],'Success criterion updated',$byAgent);research_mission_event($pdo,$fresh,'criterion_updated',$byAgent?'agent':'user',(int)$viewer['id'],['criterion_id'=>$criterionPublic,'status'=>$status]);
    return research_mission_criterion_access($pdo,$viewer,$criterionPublic)??$criterion;
}
function research_mission_subquestion_access(PDO $pdo,array $viewer,string $publicId): ?array {
    $q=$pdo->prepare("SELECT s.*,m.public_id mission_public_id,rt.public_id linked_task_public_id FROM research_mission_subquestions s JOIN research_missions m ON m.id=s.mission_id LEFT JOIN research_tasks rt ON rt.id=s.linked_task_id WHERE s.public_id=? LIMIT 1");$q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    return research_mission_access($pdo,$viewer,(string)$row['mission_public_id'])?$row:null;
}
function research_mission_add_subquestion(PDO $pdo,array $viewer,string $missionPublic,array $input,bool $byAgent=false): array {
    $mission=research_mission_access($pdo,$viewer,$missionPublic);if(!$mission)throw new RuntimeException('Research Mission not found.');$items=research_mission_clean_subquestions([$input],(string)$mission['priority']);if(!$items)throw new InvalidArgumentException('Sub-question is required.');
    $q=$pdo->prepare("SELECT COALESCE(MAX(position),-1)+1 FROM research_mission_subquestions WHERE mission_id=?");$q->execute([(int)$mission['id']]);$items[0]['position']=(int)$q->fetchColumn();$public=research_mission_insert_subquestion($pdo,(int)$mission['id'],$items[0]);
    $fresh=research_mission_refresh_revision($pdo,(int)$mission['id'],(int)$viewer['id'],'Sub-question added',$byAgent);research_mission_event($pdo,$fresh,'subquestion_added',$byAgent?'agent':'user',(int)$viewer['id'],['subquestion_id'=>$public]);
    return research_mission_subquestion_access($pdo,$viewer,$public)??['public_id'=>$public];
}
function research_mission_update_subquestion(PDO $pdo,array $viewer,string $subquestionPublic,array $input,bool $byAgent=false): array {
    $sub=research_mission_subquestion_access($pdo,$viewer,$subquestionPublic);if(!$sub)throw new RuntimeException('Mission sub-question not found.');$mission=research_mission_access($pdo,$viewer,(string)$sub['mission_public_id']);
    $question=research_mission_text((string)($input['question']??$sub['question']),12000);if($question==='')throw new InvalidArgumentException('Sub-question is required.');$rationale=research_mission_text((string)($input['rationale']??$sub['rationale']??''),8000);
    $priority=(string)($input['priority']??$sub['priority']);if(!isset(research_mission_priorities()[$priority]))$priority=(string)$sub['priority'];$status=(string)($input['status']??$sub['status']);if(!isset(research_mission_subquestion_statuses()[$status]))throw new InvalidArgumentException('Invalid sub-question status.');
    $answer=array_key_exists('answer_summary',$input)?research_mission_text((string)$input['answer_summary'],64000):(string)($sub['answer_summary']??'');$confidence=array_key_exists('confidence',$input)&&$input['confidence']!==''?(float)$input['confidence']:($sub['confidence']!==null?(float)$sub['confidence']:null);if($confidence!==null&&($confidence<0||$confidence>1))throw new InvalidArgumentException('Sub-question confidence must be between 0 and 1.');
    $answered=$status==='answered'?($sub['answered_at']?:date('Y-m-d H:i:s')):null;
    $pdo->prepare("UPDATE research_mission_subquestions SET question=?,rationale=?,status=?,priority=?,answer_summary=?,confidence=?,answered_at=?,updated_at=NOW() WHERE id=?")
      ->execute([$question,$rationale!==''?$rationale:null,$status,$priority,$answer!==''?$answer:null,$confidence,$answered,(int)$sub['id']]);
    $fresh=research_mission_refresh_revision($pdo,(int)$mission['id'],(int)$viewer['id'],'Sub-question updated',$byAgent);research_mission_event($pdo,$fresh,'subquestion_updated',$byAgent?'agent':'user',(int)$viewer['id'],['subquestion_id'=>$subquestionPublic,'status'=>$status]);
    return research_mission_subquestion_access($pdo,$viewer,$subquestionPublic)??$sub;
}

function research_mission_plan_detail(PDO $pdo,array $viewer,array $mission): ?array {
    $public=trim((string)($mission['plan_public_id']??''));if($public==='')return null;
    $plan=research_task_plan_access($pdo,$viewer,$public);if(!$plan)return null;
    $plan['tasks']=research_task_plan_structure($pdo,(int)$plan['id']);
    return $plan;
}

function research_mission_plan_blueprint(PDO $pdo,array $mission): array {
    $subquestions=research_mission_subquestions($pdo,(int)$mission['id']);$tasks=[];$indexes=[];
    foreach($subquestions as $sub){
        $question=trim((string)$sub['question']);$short=preg_replace('/\s+/u',' ',$question)?:$question;
        $description='Mission sub-question: '.$question."\nMission objective: ".(string)$mission['objective'];
        if(trim((string)($sub['rationale']??''))!=='')$description.="\nWhy it matters: ".trim((string)$sub['rationale']);
        $description.="\nUse accessible Research evidence, preserve provenance, and identify uncertainty or contradictory evidence.";
        $indexes[]=count($tasks);
        $tasks[]=[
          'title'=>mb_substr('Investigate: '.$short,0,255),'description'=>mb_substr($description,0,12000),'task_type'=>'general',
          'priority'=>(string)$sub['priority'],'depends_on'=>[],
          'gates'=>[['type'=>'min_sources','required'=>['count'=>1]],['type'=>'citations','required'=>['count'=>1]]]
        ];
    }
    if(!$tasks){
        $indexes[] = 0;
        $tasks[]=[
          'title'=>'Investigate primary Mission question',
          'description'=>mb_substr('Primary question: '.(string)$mission['research_question']."\nObjective: ".(string)$mission['objective']."\nGather and verify cited Research evidence before synthesis.",0,12000),
          'task_type'=>'general','priority'=>(string)$mission['priority'],'depends_on'=>[],
          'gates'=>[['type'=>'min_sources','required'=>['count'=>2]],['type'=>'citations','required'=>['count'=>1]]]
        ];
    }
    $success=trim((string)($mission['success_definition']??''));
    $synthesis='Answer the Mission research question from the completed evidence work. Distinguish evidence from inference, surface contradictions and unresolved uncertainty, and explain whether the stated success definition has been met.';
    if($success!=='')$synthesis.="\nSuccess definition: ".$success;
    $tasks[]=[
      'title'=>'Synthesize Mission answer','description'=>mb_substr($synthesis,0,12000),'task_type'=>'synthesize',
      'priority'=>(string)$mission['priority'],'depends_on'=>$indexes
    ];
    return ['tasks'=>$tasks,'subquestion_count'=>count($subquestions),'task_count'=>count($tasks)];
}

function research_mission_create_plan(PDO $pdo,array $viewer,string $missionPublic,array $input=[],bool $byAgent=false): array {
    $mission=research_mission_access($pdo,$viewer,$missionPublic);if(!$mission)throw new RuntimeException('Research Mission not found.');
    if(in_array((string)$mission['status'],['completed','cancelled','archived'],true))throw new RuntimeException('Reactivate this Mission before creating a new execution plan.');
    if(!empty($mission['plan_public_id']))return research_mission_detail($pdo,$viewer,$missionPublic)??$mission;
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT plan_id FROM research_missions WHERE id=? FOR UPDATE');$lock->execute([(int)$mission['id']]);$existing=(int)($lock->fetchColumn()?:0);
        if($existing>0){
            if($owns)$pdo->commit();
            return research_mission_detail($pdo,$viewer,$missionPublic)??$mission;
        }
        $mission=research_mission_by_id($pdo,(int)$mission['id']);$blueprint=research_mission_plan_blueprint($pdo,$mission);
        $constraints=research_mission_json_object($mission['constraints_json']??null);
        $due=$input['due_at']??($constraints['deadline']??null);
        $plan=research_task_plan_create($pdo,$viewer,[
          'agent_id'=>(string)$mission['agent_public_id'],
          'title'=>research_mission_text((string)($input['title']??((string)$mission['title'].' — Mission Plan')),255),
          'objective'=>research_mission_text((string)$mission['objective']."\nPrimary question: ".(string)$mission['research_question'],16000),
          'priority'=>(string)$mission['priority'],'due_at'=>$due,
          'deliverable_type'=>(string)($input['deliverable_type']??'research_brief'),
          'deliverable_title'=>research_mission_text((string)($input['deliverable_title']??((string)$mission['title'].' — Mission Brief')),255),
          'tasks'=>$blueprint['tasks'],'initial_status'=>'paused','create_deliverable'=>false
        ],$byAgent);
        if(empty($plan['id'])){$resolved=research_task_plan_access($pdo,$viewer,(string)$plan['public_id']);if(!$resolved)throw new RuntimeException('Mission plan could not be resolved.');$plan=$resolved;}
        $planId=(int)$plan['id'];$pdo->prepare('UPDATE research_missions SET plan_id=?,updated_at=NOW() WHERE id=?')->execute([$planId,(int)$mission['id']]);
        $subquestions=research_mission_subquestions($pdo,(int)$mission['id']);
        if($subquestions){
            $q=$pdo->prepare('SELECT id FROM research_tasks WHERE plan_id=? ORDER BY position,id');$q->execute([$planId]);$taskIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
            foreach($subquestions as $i=>$sub)if(isset($taskIds[$i]))$pdo->prepare('UPDATE research_mission_subquestions SET linked_task_id=?,updated_at=NOW() WHERE id=?')->execute([$taskIds[$i],(int)$sub['id']]);
        }
        $fresh=research_mission_by_id($pdo,(int)$mission['id']);
        research_mission_event($pdo,$fresh,'mission_plan_created',$byAgent?'agent':'user',(int)$viewer['id'],[
          'plan_id'=>(string)$plan['public_id'],'task_count'=>(int)$blueprint['task_count'],'subquestion_count'=>(int)$blueprint['subquestion_count'],'plan_status'=>'paused'
        ]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_mission_detail($pdo,$viewer,$missionPublic)??$mission;
}

function research_mission_start(PDO $pdo,array $viewer,string $missionPublic,bool $byAgent=false): array {
    $mission=research_mission_access($pdo,$viewer,$missionPublic);if(!$mission)throw new RuntimeException('Research Mission not found.');
    if(empty($mission['plan_public_id']))throw new RuntimeException('Create and review the Mission plan before starting execution.');
    if(in_array((string)$mission['status'],['completed','cancelled','archived'],true))throw new RuntimeException('Reactivate this Mission before starting execution.');
    $plan=research_task_plan_access($pdo,$viewer,(string)$mission['plan_public_id']);if(!$plan)throw new RuntimeException('Mission plan is unavailable.');
    $changed=false;
    if((string)$mission['status']!=='active'){research_mission_set_status($pdo,$viewer,$missionPublic,'active',$byAgent);$changed=true;}
    if((string)$plan['status']!=='active'){research_task_plan_set_status($pdo,$viewer,(string)$plan['public_id'],'active');$changed=true;}
    research_task_refresh_deliverable($pdo,$viewer,(string)$plan['public_id']);
    if($changed){$fresh=research_mission_by_id($pdo,(int)$mission['id']);research_mission_event($pdo,$fresh,'mission_execution_started',$byAgent?'agent':'user',(int)$viewer['id'],['plan_id'=>(string)$plan['public_id']]);}
    return research_mission_detail($pdo,$viewer,$missionPublic)??$mission;
}

function research_mission_pause(PDO $pdo,array $viewer,string $missionPublic,bool $byAgent=false): array {
    $mission=research_mission_access($pdo,$viewer,$missionPublic);if(!$mission)throw new RuntimeException('Research Mission not found.');
    if(empty($mission['plan_public_id']))throw new RuntimeException('Mission plan is unavailable.');
    $plan=research_task_plan_access($pdo,$viewer,(string)$mission['plan_public_id']);if(!$plan)throw new RuntimeException('Mission plan is unavailable.');
    if((string)$plan['status']==='active'){
        research_task_plan_set_status($pdo,$viewer,(string)$plan['public_id'],'paused');
        $fresh=research_mission_by_id($pdo,(int)$mission['id']);research_mission_event($pdo,$fresh,'mission_execution_paused',$byAgent?'agent':'user',(int)$viewer['id'],['plan_id'=>(string)$plan['public_id']]);
    }
    return research_mission_detail($pdo,$viewer,$missionPublic)??$mission;
}

function research_mission_progress_for_row(PDO $pdo,array $viewer,array $mission): array {
    $missionId=(int)$mission['id'];$planId=(int)($mission['plan_id']??0);
    $task=['total'=>0,'completed'=>0,'blocked'=>0,'review'=>0,'active'=>0];$planStatus=null;$primaryAnswer=null;$primaryTaskPublic=null;
    if($planId>0){
        $q=$pdo->prepare("SELECT status,COUNT(*) c FROM research_tasks WHERE plan_id=? GROUP BY status");$q->execute([$planId]);
        foreach($q->fetchAll() as $r){$count=(int)$r['c'];$task['total']+=$count;$status=(string)$r['status'];if(in_array($status,['complete','done','archived'],true))$task['completed']+=$count;elseif(in_array($status,['failed','waiting'],true))$task['blocked']+=$count;elseif($status==='review')$task['review']+=$count;else $task['active']+=$count;}
        $q=$pdo->prepare('SELECT status FROM research_task_plans WHERE id=? LIMIT 1');$q->execute([$planId]);$planStatus=$q->fetchColumn()?:null;
        $q=$pdo->prepare("SELECT public_id,execution_summary FROM research_tasks WHERE plan_id=? AND task_type='synthesize' ORDER BY position DESC,id DESC LIMIT 1");$q->execute([$planId]);$synth=$q->fetch();
        if($synth){$primaryTaskPublic=(string)$synth['public_id'];$text=trim((string)($synth['execution_summary']??''));$primaryAnswer=$text!==''?$text:null;}
    }
    $q=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status='answered' THEN 1 ELSE 0 END) answered,SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) blocked,SUM(CASE WHEN confidence IS NOT NULL THEN 1 ELSE 0 END) confidence_count,AVG(confidence) confidence_avg FROM research_mission_subquestions WHERE mission_id=?");$q->execute([$missionId]);$questions=$q->fetch()?:[];
    $question=['total'=>(int)($questions['total']??0),'answered'=>(int)($questions['answered']??0),'blocked'=>(int)($questions['blocked']??0),'confidence_count'=>(int)($questions['confidence_count']??0),'confidence_average'=>$questions['confidence_avg']!==null?round((float)$questions['confidence_avg'],4):null];
    $q=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status='satisfied' THEN 1 ELSE 0 END) satisfied,SUM(CASE WHEN status='waived' THEN 1 ELSE 0 END) waived,SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed FROM research_mission_criteria WHERE mission_id=?");$q->execute([$missionId]);$criteriaRow=$q->fetch()?:[];
    $criteria=['total'=>(int)($criteriaRow['total']??0),'satisfied'=>(int)($criteriaRow['satisfied']??0),'waived'=>(int)($criteriaRow['waived']??0),'failed'=>(int)($criteriaRow['failed']??0)];
    $dimensions=[];
    if($task['total']>0)$dimensions['tasks']=(int)round(100*$task['completed']/$task['total']);
    if($question['total']>0)$dimensions['subquestions']=(int)round(100*$question['answered']/$question['total']);
    if($criteria['total']>0)$dimensions['criteria']=(int)round(100*($criteria['satisfied']+$criteria['waived'])/$criteria['total']);
    $overall=$dimensions?(int)round(array_sum($dimensions)/count($dimensions)):0;
    $blockers=[];
    if($planId>0){$q=$pdo->prepare("SELECT public_id,title,status,blocking_reason FROM research_tasks WHERE plan_id=? AND status IN ('failed','waiting') ORDER BY position,id LIMIT 20");$q->execute([$planId]);foreach($q->fetchAll() as $r)$blockers[]=['type'=>'task','public_id'=>(string)$r['public_id'],'title'=>(string)$r['title'],'status'=>(string)$r['status'],'detail'=>(string)($r['blocking_reason']??'')];}
    $q=$pdo->prepare("SELECT public_id,question,status FROM research_mission_subquestions WHERE mission_id=? AND status='blocked' ORDER BY position,id LIMIT 20");$q->execute([$missionId]);foreach($q->fetchAll() as $r)$blockers[]=['type'=>'subquestion','public_id'=>(string)$r['public_id'],'title'=>(string)$r['question'],'status'=>'blocked','detail'=>'Linked Mission research is blocked.'];
    $q=$pdo->prepare("SELECT public_id,label,status FROM research_mission_criteria WHERE mission_id=? AND status='failed' ORDER BY position,id LIMIT 20");$q->execute([$missionId]);foreach($q->fetchAll() as $r)$blockers[]=['type'=>'criterion','public_id'=>(string)$r['public_id'],'title'=>(string)$r['label'],'status'=>'failed','detail'=>'Mission success criterion is not met.'];
    $unresolved=[];
    $q=$pdo->prepare("SELECT public_id,question,status FROM research_mission_subquestions WHERE mission_id=? AND status<>'answered' ORDER BY position,id LIMIT 30");$q->execute([$missionId]);foreach($q->fetchAll() as $r)$unresolved[]=['type'=>'subquestion','public_id'=>(string)$r['public_id'],'title'=>(string)$r['question'],'status'=>(string)$r['status']];
    if($planId>0){$q=$pdo->prepare("SELECT public_id,title,status FROM research_tasks WHERE plan_id=? AND status NOT IN ('complete','done','archived') ORDER BY position,id LIMIT 30");$q->execute([$planId]);foreach($q->fetchAll() as $r)$unresolved[]=['type'=>'task','public_id'=>(string)$r['public_id'],'title'=>(string)$r['title'],'status'=>(string)$r['status']];}
    $evidenceFlags=['open_contradictions'=>0,'open_evidence_gaps'=>0];
    if(installer_table_exists($pdo,'research_autonomy_observations')){
        $q=$pdo->prepare("SELECT observation_type,COUNT(*) c FROM research_autonomy_observations WHERE project_id=? AND status='open' AND observation_type IN ('contradiction','evidence_gap') GROUP BY observation_type");$q->execute([(int)$mission['project_id']]);
        foreach($q->fetchAll() as $r){if($r['observation_type']==='contradiction')$evidenceFlags['open_contradictions']=(int)$r['c'];elseif($r['observation_type']==='evidence_gap')$evidenceFlags['open_evidence_gaps']=(int)$r['c'];}
    }
    $reasons=[];$planReady=$planId>0&&$planStatus==='completed';$questionsReady=$question['total']===0||$question['answered']===$question['total'];$criteriaReady=$criteria['total']===0||($criteria['satisfied']+$criteria['waived'])===$criteria['total'];$blockerCount=count($blockers);
    if(!$planReady)$reasons[]=$planId>0?'Mission Plan still has open work.':'Mission Plan has not been created.';
    if(!$questionsReady)$reasons[]=(string)($question['total']-$question['answered']).' Mission sub-question(s) remain unresolved.';
    if(!$criteriaReady)$reasons[]=(string)($criteria['total']-$criteria['satisfied']-$criteria['waived']).' success criterion/criteria remain unresolved.';
    if($blockerCount>0)$reasons[]=$blockerCount.' explicit blocker(s) require attention.';
    return [
      'percent_complete'=>$overall,'dimensions'=>$dimensions,'plan'=>['public_id'=>(string)($mission['plan_public_id']??''),'status'=>$planStatus]+$task,
      'subquestions'=>$question,'criteria'=>$criteria,'confidence'=>['average'=>$question['confidence_average'],'rated'=>$question['confidence_count'],'total'=>$question['total']],
      'primary_answer'=>['task_public_id'=>$primaryTaskPublic,'summary'=>$primaryAnswer],'blockers'=>$blockers,'unresolved'=>$unresolved,'evidence_flags'=>$evidenceFlags,
      'completion_readiness'=>['ready'=>$planReady&&$questionsReady&&$criteriaReady&&$blockerCount===0,'plan_ready'=>$planReady,'questions_ready'=>$questionsReady,'criteria_ready'=>$criteriaReady,'blockers_clear'=>$blockerCount===0,'reasons'=>$reasons]
    ];
}

function research_mission_progress(PDO $pdo,array $viewer,string $missionPublic): array {
    $mission=research_mission_access($pdo,$viewer,$missionPublic);if(!$mission)throw new RuntimeException('Research Mission not found.');
    return research_mission_progress_for_row($pdo,$viewer,$mission);
}

function research_mission_sync_execution(PDO $pdo,int $planId): void {
    if($planId<1||!research_missions_ready($pdo))return;
    $q=$pdo->prepare("SELECT rm.*,rtp.status plan_status,rtp.public_id plan_public_id FROM research_missions rm JOIN research_task_plans rtp ON rtp.id=rm.plan_id WHERE rm.plan_id=? LIMIT 1");$q->execute([$planId]);$mission=$q->fetch();if(!$mission)return;
    $q=$pdo->prepare("SELECT s.id,s.status,s.answer_summary,s.answered_at,rt.status task_status,rt.execution_summary,rt.completed_at FROM research_mission_subquestions s JOIN research_tasks rt ON rt.id=s.linked_task_id WHERE s.mission_id=? ORDER BY s.position,s.id");$q->execute([(int)$mission['id']]);$changed=0;$counts=['answered'=>0,'blocked'=>0,'researching'=>0];
    foreach($q->fetchAll() as $row){
        $taskStatus=(string)$row['task_status'];
        $next=in_array($taskStatus,['complete','done','archived'],true)?'answered':(in_array($taskStatus,['failed','waiting'],true)?'blocked':'researching');
        $counts[$next]++;
        $summary=trim((string)($row['execution_summary']??''));$answer=(string)($row['answer_summary']??'');if($next==='answered'&&$summary!=='')$answer=$summary;
        $answeredAt=$next==='answered'?((string)($row['completed_at']??'')!==''?(string)$row['completed_at']:gmdate('Y-m-d H:i:s')):null;
        if((string)$row['status']!==$next||(string)($row['answer_summary']??'')!==$answer||(string)($row['answered_at']??'')!==(string)($answeredAt??'')){
            $pdo->prepare("UPDATE research_mission_subquestions SET status=?,answer_summary=?,answered_at=?,updated_at=NOW() WHERE id=?")->execute([$next,$answer!==''?$answer:null,$answeredAt,(int)$row['id']]);$changed++;
        }
    }
    $statusChanged=false;$from=(string)$mission['status'];$to=$from;
    if((string)$mission['plan_status']==='completed'&&in_array($from,['active','blocked'],true))$to='review';
    elseif((string)$mission['plan_status']==='active'&&$from==='review')$to='active';
    if($to!==$from){$pdo->prepare('UPDATE research_missions SET status=?,updated_at=NOW() WHERE id=?')->execute([$to,(int)$mission['id']]);$statusChanged=true;}
    if($changed>0||$statusChanged){
        $fresh=research_mission_by_id($pdo,(int)$mission['id']);
        research_mission_event($pdo,$fresh,$statusChanged&&$to==='review'?'mission_ready_for_review':($statusChanged?'mission_execution_reopened':'mission_execution_synced'),'system',null,['plan_id'=>(string)$mission['plan_public_id'],'subquestions'=>$counts,'updated_subquestions'=>$changed,'mission_status'=>$to]);
    }
}

