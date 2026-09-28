<?php
declare(strict_types=1);

function research_action_plans_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_action_plans')&&installer_table_exists($pdo,'research_action_plan_versions')&&installer_table_exists($pdo,'research_action_plan_events');}
    catch(Throwable $e){return false;}
}
function research_action_plan_statuses(): array {return ['draft'=>'Draft','proposed'=>'Proposed','active'=>'Active','paused'=>'Paused','completed'=>'Completed','cancelled'=>'Cancelled','archived'=>'Archived'];}
function research_action_plan_priorities(): array {return ['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'];}
function research_action_plan_json(mixed $value): array {
    if(is_array($value))return $value;if(!is_string($value)||trim($value)==='')return [];
    $decoded=json_decode($value,true);return is_array($decoded)?$decoded:[];
}
function research_action_plan_text(string $value,int $max): string {return mb_substr(trim($value),0,$max);}
function research_action_plan_date(mixed $value,string $label): ?string {
    $value=trim((string)$value);if($value==='')return null;
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$dt||$dt->format('Y-m-d')!==$value)throw new InvalidArgumentException($label.' must use YYYY-MM-DD.');
    return $value;
}
function research_action_plan_success_measures(mixed $value): array {
    if(is_string($value))$value=preg_split('/\r?\n/',$value)?:[];if(!is_array($value))return [];
    $out=[];
    foreach(array_slice($value,0,30) as $item){
        if(is_scalar($item)){$label=research_action_plan_text((string)$item,500);$target='';}
        elseif(is_array($item)){$label=research_action_plan_text((string)($item['label']??$item['metric']??$item['name']??''),500);$target=research_action_plan_text((string)($item['target']??''),1000);}
        else continue;
        if($label==='')continue;$out[]=['label'=>$label,'target'=>$target];
    }
    return $out;
}
function research_action_plan_risks(mixed $value): array {
    if(is_string($value))$value=preg_split('/\r?\n/',$value)?:[];if(!is_array($value))return [];
    $out=[];
    foreach(array_slice($value,0,40) as $item){
        if(is_scalar($item)){$title=research_action_plan_text((string)$item,500);$detail='';$mitigation='';}
        elseif(is_array($item)){$title=research_action_plan_text((string)($item['title']??$item['label']??''),500);$detail=research_action_plan_text((string)($item['detail']??''),4000);$mitigation=research_action_plan_text((string)($item['mitigation']??''),4000);}
        else continue;
        if($title==='')continue;$out[]=['title'=>$title,'detail'=>$detail,'mitigation'=>$mitigation];
    }
    return $out;
}
function research_action_plan_assumptions(mixed $value): array {
    if(is_string($value))$value=preg_split('/\r?\n/',$value)?:[];if(!is_array($value))return [];
    $out=[];foreach(array_slice($value,0,40) as $item){if(!is_scalar($item))continue;$v=research_action_plan_text((string)$item,2000);if($v!=='')$out[]=$v;}return $out;
}
function research_action_plan_owner(PDO $pdo,array $viewer,array $decision,mixed $ownerPublic): array {
    $public=trim((string)$ownerPublic);
    if($public==='')$public=trim((string)($decision['accountable_user_public_id']??$viewer['public_id']??''));
    if($public==='')throw new InvalidArgumentException('Action Plan owner is required.');
    $agent=['team_id'=>$decision['team_id']??null];
    $ownerId=research_decision_accountable_user($pdo,$viewer,$agent,$public);
    if(!$ownerId)throw new InvalidArgumentException('Action Plan owner must have access to this Research Agent.');
    $q=$pdo->prepare('SELECT id,public_id,username,display_name FROM users WHERE id=? LIMIT 1');$q->execute([$ownerId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Action Plan owner is unavailable.');return $row;
}
function research_action_plan_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_action_plans_ready($pdo))return null;$publicId=trim($publicId);if($publicId==='')return null;
    $q=$pdo->prepare("SELECT rap.*,rd.public_id decision_public_id,rd.title decision_title,rd.status decision_status,rd.current_revision decision_current_revision,rd.config_hash decision_current_config_hash,
      ra.public_id agent_public_id,ra.name agent_name,ra.team_id,rp.public_id project_public_id,rp.title project_title,
      ou.public_id owner_user_public_id,ou.username owner_username,ou.display_name owner_display_name
      FROM research_action_plans rap
      JOIN research_decisions rd ON rd.id=rap.decision_id
      JOIN research_agents ra ON ra.id=rap.research_agent_id
      JOIN research_projects rp ON rp.id=rap.project_id
      LEFT JOIN users ou ON ou.id=rap.owner_user_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rap.public_id=? AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],$publicId,(int)$viewer['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}
function research_action_plan_by_id(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare("SELECT rap.*,rd.public_id decision_public_id,rd.title decision_title,rd.status decision_status,rd.current_revision decision_current_revision,rd.config_hash decision_current_config_hash,
      ra.public_id agent_public_id,ra.name agent_name,ra.team_id,rp.public_id project_public_id,rp.title project_title,
      ou.public_id owner_user_public_id,ou.username owner_username,ou.display_name owner_display_name
      FROM research_action_plans rap
      JOIN research_decisions rd ON rd.id=rap.decision_id
      JOIN research_agents ra ON ra.id=rap.research_agent_id
      JOIN research_projects rp ON rp.id=rap.project_id
      LEFT JOIN users ou ON ou.id=rap.owner_user_id
      WHERE rap.id=? LIMIT 1");
    $q->execute([$id]);return $q->fetch()?:null;
}
function research_action_plan_write_access(PDO $pdo,array $viewer,array $plan): array {
    $project=project_access($pdo,(int)$viewer['id'],(string)$plan['project_public_id']);if(!$project||!project_can_write($project))throw new RuntimeException('This Research workspace is read only.');return $project;
}
function research_action_plan_versions(PDO $pdo,int $planId,int $limit=50): array {
    $q=$pdo->prepare("SELECT public_id,revision_number,config_json,change_reason,edited_by_user_id,edited_by_agent,created_at FROM research_action_plan_versions WHERE action_plan_id=? ORDER BY revision_number DESC,id DESC LIMIT ".max(1,min(100,$limit)));
    $q->execute([$planId]);$rows=$q->fetchAll()?:[];foreach($rows as &$r)$r['config']=research_action_plan_json($r['config_json']??null);unset($r);return $rows;
}
function research_action_plan_events(PDO $pdo,int $planId,int $limit=100): array {
    $q=$pdo->prepare("SELECT public_id,event_type,actor_type,actor_user_id,payload_json,created_at FROM research_action_plan_events WHERE action_plan_id=? ORDER BY id DESC LIMIT ".max(1,min(300,$limit)));
    $q->execute([$planId]);$rows=$q->fetchAll()?:[];foreach($rows as &$r)$r['payload']=research_action_plan_json($r['payload_json']??null);unset($r);return $rows;
}
function research_action_plan_event(PDO $pdo,array $plan,string $type,string $actorType='system',?int $userId=null,array $payload=[]): void {
    $pdo->prepare("INSERT INTO research_action_plan_events(public_id,action_plan_id,project_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$plan['id'],(int)$plan['project_id'],mb_substr($type,0,64),in_array($actorType,['user','agent','system'],true)?$actorType:'system',$userId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION):null]);
}
function research_action_plan_config(array $plan): array {
    return [
      'title'=>(string)$plan['title'],'objective'=>(string)$plan['objective'],'expected_result'=>(string)$plan['expected_result'],
      'priority'=>(string)$plan['priority'],'owner_user_public_id'=>(string)($plan['owner_user_public_id']??''),
      'success_measures'=>research_action_plan_json($plan['success_measures_json']??null),'risks'=>research_action_plan_json($plan['risks_json']??null),
      'assumptions'=>research_action_plan_json($plan['assumptions_json']??null),'start_on'=>(string)($plan['start_on']??''),'due_on'=>(string)($plan['due_on']??''),
      'source_decision_public_id'=>(string)$plan['decision_public_id'],'source_decision_revision'=>(int)$plan['source_decision_revision'],
      'source_decision_config_hash'=>(string)$plan['source_decision_config_hash'],'source_decision_status'=>(string)$plan['source_decision_status']
    ];
}
function research_action_plan_hash(array $config): string {return hash('sha256',json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));}
function research_action_plan_snapshot(PDO $pdo,array $plan,string $reason,?int $userId,bool $byAgent=false): void {
    $config=research_action_plan_config($plan);
    $pdo->prepare("INSERT INTO research_action_plan_versions(public_id,action_plan_id,revision_number,config_json,change_reason,edited_by_user_id,edited_by_agent) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$plan['id'],(int)$plan['current_revision'],json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),research_action_plan_text($reason,1000)?:null,$userId,$byAgent?1:0]);
}
function research_action_plan_refresh_revision(PDO $pdo,int $planId,?int $userId,string $reason,bool $byAgent=false): array {
    $plan=research_action_plan_by_id($pdo,$planId);if(!$plan)throw new RuntimeException('Action Plan is unavailable.');
    $hash=research_action_plan_hash(research_action_plan_config($plan));if(hash_equals((string)$plan['config_hash'],$hash))return $plan;
    $revision=(int)$plan['current_revision']+1;$pdo->prepare('UPDATE research_action_plans SET current_revision=?,config_hash=?,updated_at=NOW() WHERE id=?')->execute([$revision,$hash,$planId]);
    $plan=research_action_plan_by_id($pdo,$planId);research_action_plan_snapshot($pdo,$plan,$reason,$userId,$byAgent);return $plan;
}
function research_action_plan_source_snapshot(PDO $pdo,array $decision): array {
    return [
      'decision_public_id'=>(string)$decision['public_id'],'decision_type'=>(string)$decision['decision_type'],'title'=>(string)$decision['title'],
      'statement'=>(string)$decision['statement'],'rationale'=>(string)($decision['rationale']??''),'confidence'=>$decision['confidence']!==null?(float)$decision['confidence']:null,
      'status'=>(string)$decision['status'],'revision'=>(int)$decision['current_revision'],'config_hash'=>(string)$decision['config_hash'],
      'accountable_user_public_id'=>(string)($decision['accountable_user_public_id']??''),'decided_at'=>(string)($decision['decided_at']??''),
      'config'=>research_decision_config($pdo,$decision)
    ];
}
function research_action_plan_source_stale(array $plan): bool {
    return (int)$plan['decision_current_revision']!==(int)$plan['source_decision_revision']
      || !hash_equals((string)$plan['source_decision_config_hash'],(string)$plan['decision_current_config_hash'])
      || (string)$plan['decision_status']!==(string)$plan['source_decision_status'];
}
function research_action_plan_detail(PDO $pdo,array $viewer,string $publicId): ?array {
    $plan=research_action_plan_access($pdo,$viewer,$publicId);if(!$plan)return null;
    $plan['success_measures']=research_action_plan_json($plan['success_measures_json']??null);$plan['risks']=research_action_plan_json($plan['risks_json']??null);$plan['assumptions']=research_action_plan_json($plan['assumptions_json']??null);
    $plan['source_decision_snapshot']=research_action_plan_json($plan['source_decision_snapshot_json']??null);$plan['source_stale']=research_action_plan_source_stale($plan);
    $plan['versions']=research_action_plan_versions($pdo,(int)$plan['id'],50);$plan['events']=research_action_plan_events($pdo,(int)$plan['id'],100);
    return $plan;
}
function research_action_plan_normalize(array $input,array $current=[]): array {
    $title=array_key_exists('title',$input)?research_action_plan_text((string)$input['title'],255):(string)($current['title']??'');
    $objective=array_key_exists('objective',$input)?research_action_plan_text((string)$input['objective'],64000):(string)($current['objective']??'');
    $expected=array_key_exists('expected_result',$input)?research_action_plan_text((string)$input['expected_result'],64000):(string)($current['expected_result']??'');
    if($title===''||$objective===''||$expected==='')throw new InvalidArgumentException('Action Plan title, objective, and expected result are required.');
    $priority=(string)($input['priority']??($current['priority']??'medium'));if(!isset(research_action_plan_priorities()[$priority]))throw new InvalidArgumentException('Invalid Action Plan priority.');
    $measures=array_key_exists('success_measures',$input)?research_action_plan_success_measures($input['success_measures']):research_action_plan_json($current['success_measures_json']??null);
    $risks=array_key_exists('risks',$input)?research_action_plan_risks($input['risks']):research_action_plan_json($current['risks_json']??null);
    $assumptions=array_key_exists('assumptions',$input)?research_action_plan_assumptions($input['assumptions']):research_action_plan_json($current['assumptions_json']??null);
    $start=array_key_exists('start_on',$input)?research_action_plan_date($input['start_on'],'Action Plan start date'):(($current['start_on']??null)?:null);
    $due=array_key_exists('due_on',$input)?research_action_plan_date($input['due_on'],'Action Plan due date'):(($current['due_on']??null)?:null);
    if($start&&$due&&$due<$start)throw new InvalidArgumentException('Action Plan due date cannot be before its start date.');
    return ['title'=>$title,'objective'=>$objective,'expected_result'=>$expected,'priority'=>$priority,'success_measures'=>$measures,'risks'=>$risks,'assumptions'=>$assumptions,'start_on'=>$start,'due_on'=>$due];
}
function research_action_plan_from_decision(PDO $pdo,array $viewer,string $decisionPublic,array $input,bool $byAgent=false): array {
    if(!research_action_plans_ready($pdo))throw new RuntimeException('Action Plan Ledger requires the latest database upgrade.');
    $decision=research_decision_detail($pdo,$viewer,trim($decisionPublic));if(!$decision)throw new RuntimeException('Decision not found.');
    $project=project_access($pdo,(int)$viewer['id'],(string)$decision['project_public_id']);if(!$project||!project_can_write($project))throw new RuntimeException('This Research workspace is read only.');
    if(!in_array((string)$decision['status'],['accepted','reopened'],true))throw new InvalidArgumentException('Only an Accepted or Reopened Decision can be handed into an Action Plan.');
    $defaults=$input;
    if(trim((string)($defaults['title']??''))==='')$defaults['title']='Action Plan · '.(string)$decision['title'];
    $x=research_action_plan_normalize($defaults);
    $owner=research_action_plan_owner($pdo,$viewer,$decision,$input['owner_user_id']??$input['owner_user_public_id']??'');
    $snapshot=research_action_plan_source_snapshot($pdo,$decision);$snapshotJson=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    $rawKey=trim((string)($input['idempotency_key']??''));if($rawKey==='')$rawKey=json_encode([(string)$decision['public_id'],(int)$decision['current_revision'],$x['title'],$x['objective'],$x['expected_result']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $idempotency=hash('sha256',$rawKey);$public=ulid_like();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
      $lock=$pdo->prepare('SELECT id,status,current_revision,config_hash FROM research_decisions WHERE id=? FOR UPDATE');$lock->execute([(int)$decision['id']]);$locked=$lock->fetch();
      if(!$locked)throw new RuntimeException('Decision is unavailable.');
      if((string)$locked['status']!==(string)$decision['status']||(int)$locked['current_revision']!==(int)$decision['current_revision']||!hash_equals((string)$locked['config_hash'],(string)$decision['config_hash']))throw new RuntimeException('Decision changed while the Action Plan was being created. Reload the Decision and try again.');
      $q=$pdo->prepare('SELECT public_id FROM research_action_plans WHERE decision_id=? AND idempotency_key=? LIMIT 1');$q->execute([(int)$decision['id'],$idempotency]);$existing=(string)($q->fetchColumn()?:'');
      if($existing!==''){if($owns)$pdo->commit();$row=research_action_plan_detail($pdo,$viewer,$existing);if(!$row)throw new RuntimeException('Existing Action Plan is unavailable.');return $row;}
      $draft=[
        'title'=>$x['title'],'objective'=>$x['objective'],'expected_result'=>$x['expected_result'],'priority'=>$x['priority'],'owner_user_public_id'=>(string)$owner['public_id'],
        'success_measures'=>$x['success_measures'],'risks'=>$x['risks'],'assumptions'=>$x['assumptions'],'start_on'=>(string)($x['start_on']??''),'due_on'=>(string)($x['due_on']??''),
        'source_decision_public_id'=>(string)$decision['public_id'],'source_decision_revision'=>(int)$decision['current_revision'],'source_decision_config_hash'=>(string)$decision['config_hash'],'source_decision_status'=>(string)$decision['status']
      ];
      $hash=research_action_plan_hash($draft);
      $pdo->prepare("INSERT INTO research_action_plans(public_id,decision_id,research_agent_id,project_id,created_by_user_id,owner_user_id,title,objective,expected_result,status,priority,success_measures_json,risks_json,assumptions_json,start_on,due_on,source_decision_revision,source_decision_config_hash,source_decision_status,source_decision_snapshot_json,idempotency_key,current_revision,config_hash)
        VALUES(?,?,?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?,1,?)")
        ->execute([$public,(int)$decision['id'],(int)$decision['research_agent_id'],(int)$decision['project_id'],(int)$viewer['id'],(int)$owner['id'],$x['title'],$x['objective'],$x['expected_result'],$x['priority'],
          json_encode($x['success_measures'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$x['risks']?json_encode($x['risks'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$x['assumptions']?json_encode($x['assumptions'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$x['start_on'],$x['due_on'],
          (int)$decision['current_revision'],(string)$decision['config_hash'],(string)$decision['status'],$snapshotJson,$idempotency,$hash]);
      $plan=research_action_plan_by_id($pdo,(int)$pdo->lastInsertId());if(!$plan)throw new RuntimeException('Action Plan could not be loaded.');
      $canonical=research_action_plan_hash(research_action_plan_config($plan));if(!hash_equals((string)$plan['config_hash'],$canonical)){$pdo->prepare('UPDATE research_action_plans SET config_hash=? WHERE id=?')->execute([$canonical,(int)$plan['id']]);$plan=research_action_plan_by_id($pdo,(int)$plan['id']);}
      research_action_plan_snapshot($pdo,$plan,'Initial Action Plan',(int)$viewer['id'],$byAgent);
      research_action_plan_event($pdo,$plan,'action_plan_created',$byAgent?'agent':'user',(int)$viewer['id'],['decision_public_id'=>$decision['public_id'],'decision_revision'=>(int)$decision['current_revision'],'decision_status'=>(string)$decision['status']]);
      research_decision_event($pdo,$decision,'decision_action_plan_created',$byAgent?'agent':'user',(int)$viewer['id'],['action_plan_id'=>$public,'decision_revision'=>(int)$decision['current_revision']]);
      if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=research_action_plan_detail($pdo,$viewer,$public);if(!$row)throw new RuntimeException('Action Plan could not be loaded.');return $row;
}
function research_action_plan_update(PDO $pdo,array $viewer,string $publicId,array $input,bool $byAgent=false): array {
    $plan=research_action_plan_detail($pdo,$viewer,$publicId);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if(in_array((string)$plan['status'],['completed','cancelled','archived'],true))throw new InvalidArgumentException('Completed, cancelled, and archived Action Plans are immutable.');
    $x=research_action_plan_normalize($input,$plan);$owner=research_action_plan_owner($pdo,$viewer,$plan,$input['owner_user_id']??$input['owner_user_public_id']??($plan['owner_user_public_id']??''));
    $pdo->prepare("UPDATE research_action_plans SET owner_user_id=?,title=?,objective=?,expected_result=?,priority=?,success_measures_json=?,risks_json=?,assumptions_json=?,start_on=?,due_on=?,updated_at=NOW() WHERE id=?")
      ->execute([(int)$owner['id'],$x['title'],$x['objective'],$x['expected_result'],$x['priority'],json_encode($x['success_measures'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$x['risks']?json_encode($x['risks'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$x['assumptions']?json_encode($x['assumptions'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$x['start_on'],$x['due_on'],(int)$plan['id']]);
    $fresh=research_action_plan_refresh_revision($pdo,(int)$plan['id'],(int)$viewer['id'],(string)($input['reason']??'Action Plan updated'),$byAgent);
    research_action_plan_event($pdo,$fresh,'action_plan_updated',$byAgent?'agent':'user',(int)$viewer['id'],['revision'=>(int)$fresh['current_revision']]);
    return research_action_plan_detail($pdo,$viewer,$publicId)??$fresh;
}
function research_action_plan_set_status(PDO $pdo,array $viewer,string $publicId,string $status,bool $byAgent=false): array {
    $plan=research_action_plan_detail($pdo,$viewer,$publicId);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if(!isset(research_action_plan_statuses()[$status]))throw new InvalidArgumentException('Invalid Action Plan status.');$current=(string)$plan['status'];if($current===$status)return $plan;
    $allowed=[
      'draft'=>['proposed','cancelled','archived'],'proposed'=>['draft','active','cancelled','archived'],
      'active'=>['paused','completed','cancelled'],'paused'=>['active','completed','cancelled'],
      'completed'=>['archived'],'cancelled'=>['archived'],'archived'=>[]
    ];
    if(!in_array($status,$allowed[$current]??[],true))throw new InvalidArgumentException('That Action Plan status transition is not allowed.');
    if($byAgent&&in_array($status,['active','completed','cancelled'],true))throw new InvalidArgumentException('Agent actions cannot activate, complete, or cancel an Action Plan without explicit human governance.');
    if(function_exists('research_action_plan_execution_transition_guard'))research_action_plan_execution_transition_guard($pdo,$viewer,$plan,$status);
    if($status==='active'){
      if((string)$plan['decision_status']!=='accepted')throw new InvalidArgumentException('The source Decision must currently be Accepted before an Action Plan can activate.');
      if(!empty($plan['source_stale']))throw new InvalidArgumentException('The source Decision changed after this Action Plan was created. Create or revise the Action Plan from current Decision state before activation.');
      if(count((array)$plan['success_measures'])<1)throw new InvalidArgumentException('At least one success measure is required before activation.');
    }
    $activated=$status==='active'&&empty($plan['activated_at']);$completed=$status==='completed';$cancelled=$status==='cancelled';
    $sql="UPDATE research_action_plans SET status=?,updated_at=NOW()";
    if($activated)$sql.=",activated_at=NOW()";if($completed)$sql.=",completed_at=NOW()";if($cancelled)$sql.=",cancelled_at=NOW()";$sql.=" WHERE id=?";
    $pdo->prepare($sql)->execute([$status,(int)$plan['id']]);$fresh=research_action_plan_by_id($pdo,(int)$plan['id']);
    if(function_exists('research_action_plan_sync_execution_task_plan'))research_action_plan_sync_execution_task_plan($pdo,$viewer,$fresh,$status);
    research_action_plan_event($pdo,$fresh,'action_plan_status_changed',$byAgent?'agent':'user',(int)$viewer['id'],['from'=>$current,'to'=>$status]);
    return research_action_plan_detail($pdo,$viewer,$publicId)??$fresh;
}
function research_action_plan_list(PDO $pdo,array $viewer,?string $agentPublic=null,?string $decisionPublic=null,int $limit=100): array {
    if(!research_action_plans_ready($pdo))return [];$uid=(int)$viewer['id'];$limit=max(1,min(250,$limit));$where=["rap.status<>'archived'"];$params=[$uid,$uid,$uid];
    if($agentPublic!==null&&trim($agentPublic)!==''){$where[]='ra.public_id=?';$params[]=trim($agentPublic);}
    if($decisionPublic!==null&&trim($decisionPublic)!==''){$where[]='rd.public_id=?';$params[]=trim($decisionPublic);}
    $q=$pdo->prepare("SELECT rap.public_id FROM research_action_plans rap JOIN research_decisions rd ON rd.id=rap.decision_id JOIN research_agents ra ON ra.id=rap.research_agent_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) AND ".implode(' AND ',$where)." ORDER BY rap.updated_at DESC,rap.id DESC LIMIT ".$limit);
    $q->execute($params);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_action_plan_detail($pdo,$viewer,(string)$id);if($row)$out[]=$row;}return $out;
}
function research_action_plan_summary(PDO $pdo,array $viewer): array {
    $items=research_action_plan_list($pdo,$viewer,null,null,250);$stats=['total'=>count($items),'draft'=>0,'proposed'=>0,'active'=>0,'paused'=>0,'completed'=>0,'cancelled'=>0,'stale_source'=>0];
    foreach($items as $plan){$status=(string)$plan['status'];if(isset($stats[$status]))$stats[$status]++;if(!empty($plan['source_stale']))$stats['stale_source']++;}return $stats;
}

function research_action_plan_execution_ready(PDO $pdo): bool {
    try{return research_action_plans_ready($pdo)&&installer_table_exists($pdo,'research_action_plan_milestones')&&installer_table_exists($pdo,'research_action_plan_milestone_dependencies')&&installer_table_exists($pdo,'research_action_plan_task_links');}
    catch(Throwable $e){return false;}
}
function research_action_plan_execution_task_plan(PDO $pdo,array $viewer,array $plan): ?array {
    $id=(int)($plan['execution_task_plan_id']??0);if($id<1)return null;
    $q=$pdo->prepare('SELECT public_id FROM research_task_plans WHERE id=? LIMIT 1');$q->execute([$id]);$public=(string)($q->fetchColumn()?:'');
    return $public!==''?research_task_plan_detail($pdo,$viewer,$public):null;
}
function research_action_plan_ensure_task_plan(PDO $pdo,array $viewer,array $plan,bool $createdByAgent=false): array {
    if(!research_action_plan_execution_ready($pdo))throw new RuntimeException('Action Plan execution requires the latest database upgrade.');
    research_action_plan_write_access($pdo,$viewer,$plan);
    if(in_array((string)$plan['status'],['completed','cancelled','archived'],true))throw new InvalidArgumentException('Execution tasks cannot be added to a completed, cancelled, or archived Action Plan.');
    $existing=research_action_plan_execution_task_plan($pdo,$viewer,$plan);
    if($existing){
      $target=(string)$plan['status']==='active'?'active':'paused';
      if(in_array((string)$existing['status'],['completed','active','paused'],true)&&(string)$existing['status']!==$target){
        $pdo->prepare("UPDATE research_task_plans SET status=?,completed_at=CASE WHEN ?='active' THEN NULL ELSE completed_at END,updated_at=NOW() WHERE id=?")->execute([$target,$target,(int)$existing['id']]);
        research_task_event($pdo,(int)$existing['project_id'],(int)$existing['id'],null,'action_plan_execution_plan_'.$target,'system',(int)$viewer['id'],['action_plan_id'=>(string)$plan['public_id']]);
        if($target==='active')research_task_queue_ready($pdo,(int)$existing['id'],(int)$viewer['id'],'replan');
        $existing=research_task_plan_detail($pdo,$viewer,(string)$existing['public_id'])??$existing;
      }
      return $existing;
    }
    $priority=(string)$plan['priority'];if($priority==='critical')$priority='urgent';
    $taskPlan=research_task_plan_create($pdo,$viewer,[
      'agent_id'=>(string)$plan['agent_public_id'],'title'=>'Execution · '.(string)$plan['title'],
      'objective'=>(string)$plan['objective'],'priority'=>$priority,'due_at'=>$plan['due_on']?((string)$plan['due_on'].' 23:59:59'):null,
      'deliverable_type'=>'document','deliverable_title'=>'Execution · '.(string)$plan['title'],
      'initial_status'=>(string)$plan['status']==='active'?'active':'paused','create_deliverable'=>false,'tasks'=>[]
    ],$createdByAgent);
    $pdo->prepare('UPDATE research_action_plans SET execution_task_plan_id=?,updated_at=NOW() WHERE id=? AND execution_task_plan_id IS NULL')->execute([(int)$taskPlan['id'],(int)$plan['id']]);
    $fresh=research_action_plan_by_id($pdo,(int)$plan['id']);research_action_plan_event($pdo,$fresh,'execution_task_plan_created',$createdByAgent?'agent':'user',(int)$viewer['id'],['task_plan_id'=>(string)$taskPlan['public_id']]);
    return research_task_plan_detail($pdo,$viewer,(string)$taskPlan['public_id'])??$taskPlan;
}
function research_action_plan_milestone_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_action_plan_execution_ready($pdo))return null;
    $q=$pdo->prepare("SELECT m.*,rap.public_id action_plan_public_id,rap.project_id,rap.research_agent_id,rap.status action_plan_status,
      ou.public_id owner_user_public_id,ou.username owner_username,ou.display_name owner_display_name
      FROM research_action_plan_milestones m JOIN research_action_plans rap ON rap.id=m.action_plan_id LEFT JOIN users ou ON ou.id=m.owner_user_id
      WHERE m.public_id=? LIMIT 1");$q->execute([trim($publicId)]);$m=$q->fetch();if(!$m)return null;
    $plan=research_action_plan_access($pdo,$viewer,(string)$m['action_plan_public_id']);if(!$plan)return null;$m['action_plan']=$plan;return $m;
}
function research_action_plan_milestone_dependencies(PDO $pdo,int $milestoneId): array {
    $q=$pdo->prepare("SELECT parent.public_id,parent.title,parent.status,d.dependency_type,parent.target_on
      FROM research_action_plan_milestone_dependencies d JOIN research_action_plan_milestones parent ON parent.id=d.depends_on_milestone_id
      WHERE d.milestone_id=? ORDER BY parent.position,parent.id");$q->execute([$milestoneId]);return $q->fetchAll()?:[];
}
function research_action_plan_milestone_tasks(PDO $pdo,array $viewer,int $milestoneId): array {
    $q=$pdo->prepare("SELECT rt.public_id FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id WHERE l.milestone_id=? ORDER BY rt.position,rt.id");$q->execute([$milestoneId]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$t=research_task_access($pdo,$viewer,(string)$public);if($t)$out[]=$t;}return $out;
}
function research_action_plan_milestone_execution_state(PDO $pdo,array $viewer,array $milestone): string {
    if((string)$milestone['status']==='cancelled')return 'cancelled';if((string)$milestone['status']==='completed')return 'completed';
    foreach(research_action_plan_milestone_dependencies($pdo,(int)$milestone['id']) as $dep)if((string)$dep['status']!=='completed')return 'blocked';
    $tasks=research_action_plan_milestone_tasks($pdo,$viewer,(int)$milestone['id']);if(!$tasks)return (string)$milestone['status']==='in_progress'?'in_progress':'ready';
    $done=0;$running=0;foreach($tasks as $task){if(in_array((string)$task['status'],['complete','done','archived'],true))$done++;elseif(in_array((string)$task['status'],['in_progress','researching','ready'],true))$running++;}
    if($done===count($tasks))return 'ready_to_complete';if($running>0||(string)$milestone['status']==='in_progress')return 'in_progress';return 'ready';
}
function research_action_plan_milestone_detail(PDO $pdo,array $viewer,string $publicId): ?array {
    $m=research_action_plan_milestone_access($pdo,$viewer,$publicId);if(!$m)return null;
    $m['completion_criteria']=research_action_plan_json($m['completion_criteria_json']??null);$m['dependencies']=research_action_plan_milestone_dependencies($pdo,(int)$m['id']);
    $m['tasks']=research_action_plan_milestone_tasks($pdo,$viewer,(int)$m['id']);$m['execution_state']=research_action_plan_milestone_execution_state($pdo,$viewer,$m);unset($m['action_plan']);return $m;
}
function research_action_plan_milestones(PDO $pdo,array $viewer,string $planPublic): array {
    $plan=research_action_plan_access($pdo,$viewer,$planPublic);if(!$plan)return [];
    $q=$pdo->prepare('SELECT public_id FROM research_action_plan_milestones WHERE action_plan_id=? ORDER BY position,id');$q->execute([(int)$plan['id']]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$m=research_action_plan_milestone_detail($pdo,$viewer,(string)$public);if($m)$out[]=$m;}return $out;
}
function research_action_plan_milestone_criteria(mixed $value): array {
    if(is_string($value))$value=preg_split('/\r?\n/',$value)?:[];if(!is_array($value))return [];
    $out=[];foreach(array_slice($value,0,30) as $item){if(!is_scalar($item))continue;$v=research_action_plan_text((string)$item,1000);if($v!=='')$out[]=$v;}return $out;
}
function research_action_plan_create_milestone(PDO $pdo,array $viewer,string $planPublic,array $input,bool $byAgent=false): array {
    $plan=research_action_plan_detail($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if(in_array((string)$plan['status'],['completed','cancelled','archived'],true))throw new InvalidArgumentException('Milestones cannot be added to a completed, cancelled, or archived Action Plan.');
    $title=research_action_plan_text((string)($input['title']??''),255);if($title==='')throw new InvalidArgumentException('Milestone title is required.');
    $description=research_action_plan_text((string)($input['description']??''),16000);$criteria=research_action_plan_milestone_criteria($input['completion_criteria']??[]);
    $target=research_action_plan_date($input['target_on']??null,'Milestone target date');$owner=research_action_plan_owner($pdo,$viewer,$plan,$input['owner_user_id']??$input['owner_user_public_id']??($plan['owner_user_public_id']??''));
    $q=$pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM research_action_plan_milestones WHERE action_plan_id=?');$q->execute([(int)$plan['id']]);$position=(int)$q->fetchColumn();$public=ulid_like();
    $pdo->prepare("INSERT INTO research_action_plan_milestones(public_id,action_plan_id,owner_user_id,title,description,status,completion_criteria_json,position,target_on,created_by_user_id) VALUES(?,?,?,?,?,'planned',?,?,?,?)")
      ->execute([$public,(int)$plan['id'],(int)$owner['id'],$title,$description!==''?$description:null,json_encode($criteria,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$position,$target,(int)$viewer['id']]);
    research_action_plan_event($pdo,$plan,'milestone_created',$byAgent?'agent':'user',(int)$viewer['id'],['milestone_id'=>$public,'title'=>$title]);
    return research_action_plan_milestone_detail($pdo,$viewer,$public)??['public_id'=>$public,'title'=>$title];
}
function research_action_plan_update_milestone(PDO $pdo,array $viewer,string $milestonePublic,array $input,bool $byAgent=false): array {
    $m=research_action_plan_milestone_access($pdo,$viewer,$milestonePublic);if(!$m)throw new RuntimeException('Action Plan milestone not found.');$plan=$m['action_plan'];research_action_plan_write_access($pdo,$viewer,$plan);
    if(in_array((string)$plan['status'],['completed','cancelled','archived'],true)||in_array((string)$m['status'],['completed','cancelled'],true))throw new InvalidArgumentException('Completed/cancelled milestones are immutable.');
    $title=array_key_exists('title',$input)?research_action_plan_text((string)$input['title'],255):(string)$m['title'];if($title==='')throw new InvalidArgumentException('Milestone title is required.');
    $description=array_key_exists('description',$input)?research_action_plan_text((string)$input['description'],16000):(string)($m['description']??'');
    $criteria=array_key_exists('completion_criteria',$input)?research_action_plan_milestone_criteria($input['completion_criteria']):research_action_plan_json($m['completion_criteria_json']??null);
    $target=array_key_exists('target_on',$input)?research_action_plan_date($input['target_on'],'Milestone target date'):(($m['target_on']??null)?:null);
    $owner=research_action_plan_owner($pdo,$viewer,$plan,$input['owner_user_id']??$input['owner_user_public_id']??($m['owner_user_public_id']??$plan['owner_user_public_id']??''));
    $pdo->prepare('UPDATE research_action_plan_milestones SET owner_user_id=?,title=?,description=?,completion_criteria_json=?,target_on=?,updated_at=NOW() WHERE id=?')
      ->execute([(int)$owner['id'],$title,$description!==''?$description:null,json_encode($criteria,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$target,(int)$m['id']]);
    research_action_plan_event($pdo,$plan,'milestone_updated',$byAgent?'agent':'user',(int)$viewer['id'],['milestone_id'=>$milestonePublic]);
    return research_action_plan_milestone_detail($pdo,$viewer,$milestonePublic)??$m;
}
function research_action_plan_milestone_dependency_cycle(PDO $pdo,int $milestoneId,int $dependsOnId): bool {
    if($milestoneId===$dependsOnId)return true;$front=[$dependsOnId];$seen=[];
    while($front){$current=array_shift($front);if($current===$milestoneId)return true;if(isset($seen[$current]))continue;$seen[$current]=true;
      $q=$pdo->prepare('SELECT depends_on_milestone_id FROM research_action_plan_milestone_dependencies WHERE milestone_id=?');$q->execute([$current]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $next)$front[]=(int)$next;
    }
    return false;
}
function research_action_plan_add_milestone_dependency(PDO $pdo,array $viewer,string $milestonePublic,string $dependsOnPublic,string $type='finish_to_start'): array {
    $m=research_action_plan_milestone_access($pdo,$viewer,$milestonePublic);$parent=research_action_plan_milestone_access($pdo,$viewer,$dependsOnPublic);
    if(!$m||!$parent)throw new RuntimeException('Action Plan milestone not found.');$plan=$m['action_plan'];research_action_plan_write_access($pdo,$viewer,$plan);
    if((int)$m['action_plan_id']!==(int)$parent['action_plan_id'])throw new InvalidArgumentException('Milestone dependencies must stay inside one Action Plan.');
    if((string)$m['status']!=='planned')throw new InvalidArgumentException('Milestone dependencies can only change before the milestone starts.');
    if(!in_array($type,['finish_to_start','blocking'],true))$type='finish_to_start';
    if(research_action_plan_milestone_dependency_cycle($pdo,(int)$m['id'],(int)$parent['id']))throw new InvalidArgumentException('Milestone dependency would create a cycle.');
    $pdo->prepare('INSERT INTO research_action_plan_milestone_dependencies(milestone_id,depends_on_milestone_id,dependency_type) VALUES(?,?,?) ON DUPLICATE KEY UPDATE dependency_type=VALUES(dependency_type)')
      ->execute([(int)$m['id'],(int)$parent['id'],$type]);research_action_plan_event($pdo,$plan,'milestone_dependency_added','user',(int)$viewer['id'],['milestone_id'=>$milestonePublic,'depends_on'=>$dependsOnPublic,'dependency_type'=>$type]);
    research_action_plan_requeue_milestone_tasks($pdo,$viewer,(int)$m['id']);return research_action_plan_milestone_detail($pdo,$viewer,$milestonePublic)??$m;
}
function research_action_plan_remove_milestone_dependency(PDO $pdo,array $viewer,string $milestonePublic,string $dependsOnPublic): array {
    $m=research_action_plan_milestone_access($pdo,$viewer,$milestonePublic);$parent=research_action_plan_milestone_access($pdo,$viewer,$dependsOnPublic);
    if(!$m||!$parent)throw new RuntimeException('Action Plan milestone not found.');$plan=$m['action_plan'];research_action_plan_write_access($pdo,$viewer,$plan);
    if((string)$m['status']!=='planned')throw new InvalidArgumentException('Milestone dependencies can only change before the milestone starts.');
    $pdo->prepare('DELETE FROM research_action_plan_milestone_dependencies WHERE milestone_id=? AND depends_on_milestone_id=?')->execute([(int)$m['id'],(int)$parent['id']]);
    research_action_plan_event($pdo,$plan,'milestone_dependency_removed','user',(int)$viewer['id'],['milestone_id'=>$milestonePublic,'depends_on'=>$dependsOnPublic]);research_action_plan_requeue_milestone_tasks($pdo,$viewer,(int)$m['id']);
    return research_action_plan_milestone_detail($pdo,$viewer,$milestonePublic)??$m;
}
function research_action_plan_linked_task(PDO $pdo,array $viewer,string $planPublic,string $taskPublic): ?array {
    $plan=research_action_plan_access($pdo,$viewer,$planPublic);if(!$plan)return null;$task=research_task_access($pdo,$viewer,$taskPublic);if(!$task)return null;
    $q=$pdo->prepare('SELECT milestone_id,link_role FROM research_action_plan_task_links WHERE action_plan_id=? AND task_id=? LIMIT 1');$q->execute([(int)$plan['id'],(int)$task['id']]);$link=$q->fetch();if(!$link)return null;$task['action_plan_public_id']=$planPublic;$task['milestone_id']=$link['milestone_id'];$task['link_role']=$link['link_role'];return $task;
}
function research_action_plan_task_dependency_cycle(PDO $pdo,int $taskId,int $dependsOnId): bool {
    if($taskId===$dependsOnId)return true;$front=[$dependsOnId];$seen=[];
    while($front){$current=array_shift($front);if($current===$taskId)return true;if(isset($seen[$current]))continue;$seen[$current]=true;
      $q=$pdo->prepare('SELECT depends_on_task_id FROM research_task_dependencies WHERE task_id=?');$q->execute([$current]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $next)$front[]=(int)$next;
    }return false;
}
function research_action_plan_add_task(PDO $pdo,array $viewer,string $planPublic,array $input,bool $byAgent=false): array {
    $plan=research_action_plan_detail($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if(in_array((string)$plan['status'],['completed','cancelled','archived'],true))throw new InvalidArgumentException('Execution tasks cannot be added to a completed, cancelled, or archived Action Plan.');
    $milestone=null;$milestonePublic=trim((string)($input['milestone_id']??''));if($milestonePublic!==''){$milestone=research_action_plan_milestone_access($pdo,$viewer,$milestonePublic);if(!$milestone||(int)$milestone['action_plan_id']!==(int)$plan['id'])throw new InvalidArgumentException('Milestone must belong to this Action Plan.');if((string)$milestone['status']==='cancelled')throw new InvalidArgumentException('Tasks cannot be added to a cancelled milestone.');}
    $taskPlan=research_action_plan_ensure_task_plan($pdo,$viewer,$plan,$byAgent);$agent=research_task_agent($pdo,$viewer,(string)$plan['agent_public_id']);$project=research_task_project($pdo,$viewer,$agent);
    $depends=[];$depIds=[];
    foreach(array_slice((array)($input['depends_on']??[]),0,40) as $public){$dep=research_action_plan_linked_task($pdo,$viewer,$planPublic,(string)$public);if(!$dep)throw new InvalidArgumentException('Task dependencies must belong to this Action Plan.');$depends[]=(string)$dep['public_id'];$depIds[]=(int)$dep['id'];}
    $q=$pdo->prepare("SELECT COALESCE(MAX(position),-1)+1 FROM research_tasks WHERE plan_id=?");$q->execute([(int)$taskPlan['id']]);$taskInput=$input;$taskInput['position']=(int)$q->fetchColumn();unset($taskInput['depends_on'],$taskInput['milestone_id'],$taskInput['link_role']);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
      $task=research_task_create($pdo,$viewer,$agent,$project,$taskInput,(int)$taskPlan['id'],$byAgent);
      $role=(string)($input['link_role']??'execution');if(!in_array($role,['execution','validation','supporting'],true))$role='execution';
      $pdo->prepare('INSERT INTO research_action_plan_task_links(action_plan_id,milestone_id,task_id,link_role,created_by_user_id) VALUES(?,?,?,?,?)')
        ->execute([(int)$plan['id'],$milestone?(int)$milestone['id']:null,(int)$task['id'],$role,(int)$viewer['id']]);
      foreach($depIds as $depId)$pdo->prepare("INSERT IGNORE INTO research_task_dependencies(task_id,depends_on_task_id,dependency_type) VALUES(?,?,'finish_to_start')")->execute([(int)$task['id'],$depId]);
      $next=(int)$taskPlan['current_revision']+1;$pdo->prepare('UPDATE research_task_plans SET current_revision=?,updated_at=NOW() WHERE id=?')->execute([$next,(int)$taskPlan['id']]);
      $q=$pdo->prepare('SELECT * FROM research_task_plans WHERE id=?');$q->execute([(int)$taskPlan['id']]);$freshTaskPlan=$q->fetch();research_task_plan_snapshot($pdo,$freshTaskPlan,'Action Plan execution task added',(int)$viewer['id'],$byAgent);
      research_action_plan_event($pdo,$plan,'execution_task_added',$byAgent?'agent':'user',(int)$viewer['id'],['task_id'=>(string)$task['public_id'],'milestone_id'=>$milestonePublic!==''?$milestonePublic:null,'link_role'=>$role,'depends_on'=>$depends]);
      if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    research_task_queue($pdo,(int)$task['id'],(int)$viewer['id'],'manual');return research_task_access($pdo,$viewer,(string)$task['public_id'])??$task;
}
function research_action_plan_set_task_dependencies(PDO $pdo,array $viewer,string $planPublic,string $taskPublic,array $dependsOn): array {
    $plan=research_action_plan_detail($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    $task=research_action_plan_linked_task($pdo,$viewer,$planPublic,$taskPublic);if(!$task)throw new RuntimeException('Action Plan execution task not found.');
    if(in_array((string)$task['status'],['researching','complete','done','archived'],true))throw new InvalidArgumentException('Task dependencies cannot change while running or after completion.');
    $ids=[];foreach(array_slice($dependsOn,0,40) as $public){$dep=research_action_plan_linked_task($pdo,$viewer,$planPublic,(string)$public);if(!$dep)throw new InvalidArgumentException('Task dependencies must belong to this Action Plan.');if(research_action_plan_task_dependency_cycle($pdo,(int)$task['id'],(int)$dep['id']))throw new InvalidArgumentException('Task dependency would create a cycle.');$ids[(int)$dep['id']]=(string)$dep['public_id'];}
    $pdo->prepare('DELETE FROM research_task_dependencies WHERE task_id=?')->execute([(int)$task['id']]);foreach(array_keys($ids) as $depId)$pdo->prepare("INSERT INTO research_task_dependencies(task_id,depends_on_task_id,dependency_type) VALUES(?,?,'finish_to_start')")->execute([(int)$task['id'],$depId]);
    $pdo->prepare("UPDATE research_tasks SET status='queued',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);research_task_event($pdo,(int)$plan['project_id'],(int)$task['plan_id'],(int)$task['id'],'dependencies_revised','user',(int)$viewer['id'],['depends_on'=>array_values($ids)]);
    research_action_plan_event($pdo,$plan,'execution_task_dependencies_updated','user',(int)$viewer['id'],['task_id'=>$taskPublic,'depends_on'=>array_values($ids)]);research_task_queue($pdo,(int)$task['id'],(int)$viewer['id'],'replan');
    return research_task_access($pdo,$viewer,$taskPublic)??$task;
}
function research_action_plan_task_execution_ready(PDO $pdo,int $taskId): bool {
    if(!research_action_plan_execution_ready($pdo))return true;
    $q=$pdo->prepare("SELECT rap.status action_plan_status,m.id milestone_id,m.status milestone_status
      FROM research_action_plan_task_links l JOIN research_action_plans rap ON rap.id=l.action_plan_id
      LEFT JOIN research_action_plan_milestones m ON m.id=l.milestone_id WHERE l.task_id=? LIMIT 1");$q->execute([$taskId]);$row=$q->fetch();if(!$row)return true;
    if((string)$row['action_plan_status']!=='active')return false;if(!$row['milestone_id'])return true;if((string)$row['milestone_status']!=='in_progress')return false;
    $q=$pdo->prepare("SELECT COUNT(*) FROM research_action_plan_milestone_dependencies d JOIN research_action_plan_milestones parent ON parent.id=d.depends_on_milestone_id WHERE d.milestone_id=? AND parent.status<>'completed'");$q->execute([(int)$row['milestone_id']]);return (int)$q->fetchColumn()===0;
}
function research_action_plan_requeue_milestone_tasks(PDO $pdo,array $viewer,int $milestoneId): void {
    $q=$pdo->prepare('SELECT task_id FROM research_action_plan_task_links WHERE milestone_id=?');$q->execute([$milestoneId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $taskId)research_task_queue($pdo,(int)$taskId,(int)$viewer['id'],'dependency_ready');
}
function research_action_plan_set_milestone_status(PDO $pdo,array $viewer,string $milestonePublic,string $status,bool $byAgent=false): array {
    $m=research_action_plan_milestone_access($pdo,$viewer,$milestonePublic);if(!$m)throw new RuntimeException('Action Plan milestone not found.');$plan=$m['action_plan'];research_action_plan_write_access($pdo,$viewer,$plan);
    if(!in_array($status,['planned','in_progress','completed','cancelled'],true))throw new InvalidArgumentException('Invalid milestone status.');$current=(string)$m['status'];if($current===$status)return research_action_plan_milestone_detail($pdo,$viewer,$milestonePublic)??$m;
    $allowed=['planned'=>['in_progress','cancelled'],'in_progress'=>['planned','completed','cancelled'],'completed'=>[],'cancelled'=>[]];if(!in_array($status,$allowed[$current]??[],true))throw new InvalidArgumentException('That milestone status transition is not allowed.');
    if($byAgent&&in_array($status,['completed','cancelled'],true))throw new InvalidArgumentException('Agent actions cannot complete or cancel an Action Plan milestone without explicit human governance.');
    if(in_array($status,['in_progress','completed'],true)&&(string)$plan['status']!=='active')throw new InvalidArgumentException('The Action Plan must be Active before milestone execution can start or complete.');
    if(in_array($status,['in_progress','completed'],true))foreach(research_action_plan_milestone_dependencies($pdo,(int)$m['id']) as $dep)if((string)$dep['status']!=='completed')throw new InvalidArgumentException('Milestone dependencies must be completed first.');
    if($status==='completed'){
      $tasks=research_action_plan_milestone_tasks($pdo,$viewer,(int)$m['id']);foreach($tasks as $task)if(!in_array((string)$task['status'],['complete','done','archived'],true))throw new InvalidArgumentException('All milestone execution tasks must be complete before the milestone can complete.');
      $criteria=research_action_plan_json($m['completion_criteria_json']??null);if(!$criteria)throw new InvalidArgumentException('Milestone completion criteria are required before completion.');
    }
    $sql=$status==='completed'?"UPDATE research_action_plan_milestones SET status=?,completed_at=NOW(),updated_at=NOW() WHERE id=?":"UPDATE research_action_plan_milestones SET status=?,updated_at=NOW() WHERE id=?";
    $pdo->prepare($sql)->execute([$status,(int)$m['id']]);research_action_plan_event($pdo,$plan,'milestone_status_changed',$byAgent?'agent':'user',(int)$viewer['id'],['milestone_id'=>$milestonePublic,'from'=>$current,'to'=>$status]);
    if($status==='in_progress')research_action_plan_requeue_milestone_tasks($pdo,$viewer,(int)$m['id']);
    if($status==='completed'){$q=$pdo->prepare('SELECT milestone_id FROM research_action_plan_milestone_dependencies WHERE depends_on_milestone_id=?');$q->execute([(int)$m['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $child)research_action_plan_requeue_milestone_tasks($pdo,$viewer,(int)$child);}
    return research_action_plan_milestone_detail($pdo,$viewer,$milestonePublic)??$m;
}
function research_action_plan_execution_transition_guard(PDO $pdo,array $viewer,array $plan,string $status): void {
    if(!research_action_plan_execution_ready($pdo))return;
    if($status==='completed'){
      foreach(research_action_plan_milestones($pdo,$viewer,(string)$plan['public_id']) as $m)if(!in_array((string)$m['status'],['completed','cancelled'],true))throw new InvalidArgumentException('All Action Plan milestones must be completed or cancelled before the Action Plan can complete.');
      $q=$pdo->prepare("SELECT COUNT(*) FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id WHERE l.action_plan_id=? AND rt.status NOT IN ('complete','done','archived')");$q->execute([(int)$plan['id']]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('All Action Plan execution tasks must be complete before the Action Plan can complete.');
    }
}
function research_action_plan_sync_execution_task_plan(PDO $pdo,array $viewer,array $plan,string $status): void {
    if(!research_action_plan_execution_ready($pdo)||(int)($plan['execution_task_plan_id']??0)<1)return;$taskPlan=research_action_plan_execution_task_plan($pdo,$viewer,$plan);if(!$taskPlan)return;
    if($status==='active'&&(string)$taskPlan['status']!=='active')research_task_plan_set_status($pdo,$viewer,(string)$taskPlan['public_id'],'active');
    elseif(in_array($status,['draft','proposed','paused','cancelled'],true)&&(string)$taskPlan['status']==='active')research_task_plan_set_status($pdo,$viewer,(string)$taskPlan['public_id'],'paused');
    elseif($status==='archived'&&(string)$taskPlan['status']!=='archived')research_task_plan_set_status($pdo,$viewer,(string)$taskPlan['public_id'],'archived');
}
function research_action_plan_execution_detail(PDO $pdo,array $viewer,string $planPublic): array {
    $plan=research_action_plan_detail($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Action Plan not found.');
    $taskPlan=research_action_plan_execution_task_plan($pdo,$viewer,$plan);$milestones=research_action_plan_milestones($pdo,$viewer,$planPublic);$unassigned=[];
    $q=$pdo->prepare("SELECT rt.public_id FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id WHERE l.action_plan_id=? AND l.milestone_id IS NULL ORDER BY rt.position,rt.id");$q->execute([(int)$plan['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$t=research_task_access($pdo,$viewer,(string)$public);if($t)$unassigned[]=$t;}
    return ['action_plan'=>$plan,'task_plan'=>$taskPlan,'milestones'=>$milestones,'unassigned_tasks'=>$unassigned];
}

function research_action_plan_follow_through_ready(PDO $pdo): bool {
    try{return research_action_plan_execution_ready($pdo)&&research_programs_ready($pdo)&&installer_table_exists($pdo,'research_action_plan_program_links');}
    catch(Throwable $e){return false;}
}
function research_action_plan_program_roles(): array {
    return [
      'execution_review'=>'Execution Review',
      'success_measure_check'=>'Success Measure Check',
      'evidence_refresh'=>'Evidence Refresh',
      'decision_follow_up'=>'Decision Follow-up'
    ];
}
function research_action_plan_program_defaults(string $role,array $plan): array {
    if(!isset(research_action_plan_program_roles()[$role]))throw new InvalidArgumentException('Invalid Action Plan follow-through Program role.');
    $priority=(string)$plan['priority']==='critical'?'urgent':(string)$plan['priority'];
    $base=[
      'agent_id'=>(string)$plan['agent_public_id'],'priority'=>$priority,'cadence'=>'weekly','timezone_name'=>'UTC','run_time_local'=>'09:00',
      'quiet_mode'=>'always','materiality_threshold'=>'any','catch_up_mode'=>'latest','max_concurrent_runs'=>1,'monthly_run_limit'=>31,
      'token_budget_per_run'=>60000,'max_tasks_per_run'=>6,'plan_due_offset_hours'=>48,'deliverable_type'=>'weekly_report',
      'plan_title_template'=>'{program} · {date}','deliverable_title_template'=>'{program} · {date}',
      'scope'=>['include_annotations'=>true,'include_workspace'=>true]
    ];
    $title=(string)$plan['title'];
    if($role==='execution_review')return array_merge($base,[
      'title'=>'Execution Review · '.$title,
      'objective'=>'Review Action Plan execution progress, milestone readiness, blockers, task completion, Decision provenance, and follow-up needs for “'.$title.'”.',
      'tasks'=>[
        ['title'=>'Review execution progress and blockers','description'=>'Review Action Plan status, milestones, linked execution tasks, blockers, dates, and material changes since the previous Program run.','task_type'=>'general','priority'=>$priority],
        ['title'=>'Identify governed follow-up','description'=>'Summarize what needs human attention next. Do not change Action Plan, milestone, task, Program, or Decision state.','task_type'=>'general','priority'=>$priority,'depends_on'=>[0]]
      ]
    ]);
    if($role==='success_measure_check')return array_merge($base,[
      'title'=>'Success Measure Review · '.$title,
      'objective'=>'Review the Action Plan success measures against currently available Research and execution evidence for “'.$title.'”.',
      'tasks'=>[
        ['title'=>'Review Action Plan success measures','description'=>'Assess each saved success measure against current evidence and execution state. Clearly distinguish observed evidence from missing measurement.','task_type'=>'general','priority'=>$priority],
        ['title'=>'Summarize measure gaps','description'=>'Identify success measures that need evidence, measurement, or human confirmation. Do not invent measurements.','task_type'=>'general','priority'=>$priority,'depends_on'=>[0]]
      ]
    ]);
    if($role==='evidence_refresh')return array_merge($base,[
      'title'=>'Execution Evidence Refresh · '.$title,'quiet_mode'=>'material_only','materiality_threshold'=>'important',
      'objective'=>'Refresh material Research evidence relevant to the Action Plan and surface changes that could affect execution for “'.$title.'”.',
      'tasks'=>[
        ['title'=>'Review material evidence changes','description'=>'Review material changes in current Research sources, Claims, contradictions, and Action Plan execution state.','task_type'=>'review_source_change','priority'=>$priority],
        ['title'=>'Explain execution impact','description'=>'Explain whether the changed evidence affects milestones, risks, assumptions, or the source Decision. Do not mutate execution state.','task_type'=>'general','priority'=>$priority,'depends_on'=>[0]]
      ]
    ]);
    return array_merge($base,[
      'title'=>'Decision Follow-up · '.$title,'quiet_mode'=>'material_only','materiality_threshold'=>'important','cadence'=>'monthly',
      'objective'=>'Review whether execution outcomes, blockers, changed assumptions, or evidence warrant human review of the source Decision for “'.$title.'”.',
      'tasks'=>[
        ['title'=>'Review Decision-to-execution alignment','description'=>'Compare current Action Plan execution state with the pinned source Decision, including Decision staleness and material execution changes.','task_type'=>'general','priority'=>$priority],
        ['title'=>'Identify Decision follow-up questions','description'=>'Identify evidence-backed reasons for human Decision review without changing Decision or reconsideration state.','task_type'=>'general','priority'=>$priority,'depends_on'=>[0]]
      ]
    ]);
}
function research_action_plan_program_link_row(PDO $pdo,array $viewer,string $programPublic): ?array {
    if(!research_action_plan_follow_through_ready($pdo))return null;
    $program=research_program_access($pdo,$viewer,$programPublic);if(!$program)return null;
    $q=$pdo->prepare("SELECT l.*,rap.public_id action_plan_public_id FROM research_action_plan_program_links l JOIN research_action_plans rap ON rap.id=l.action_plan_id WHERE l.program_id=? LIMIT 1");
    $q->execute([(int)$program['id']]);$link=$q->fetch();if(!$link)return null;
    $plan=research_action_plan_access($pdo,$viewer,(string)$link['action_plan_public_id']);if(!$plan)return null;
    $link['program']=$program;$link['action_plan']=$plan;return $link;
}
function research_action_plan_programs(PDO $pdo,array $viewer,string $planPublic): array {
    if(!research_action_plan_follow_through_ready($pdo))return [];
    $plan=research_action_plan_access($pdo,$viewer,$planPublic);if(!$plan)return [];
    $q=$pdo->prepare("SELECT rp.public_id,l.program_role,l.sync_with_action_plan,l.created_at,l.updated_at FROM research_action_plan_program_links l JOIN research_programs rp ON rp.id=l.program_id WHERE l.action_plan_id=? ORDER BY FIELD(l.program_role,'execution_review','success_measure_check','evidence_refresh','decision_follow_up'),rp.id");
    $q->execute([(int)$plan['id']]);$out=[];
    foreach($q->fetchAll() as $row){$program=research_program_detail($pdo,$viewer,(string)$row['public_id']);if(!$program)continue;$program['action_plan_program_role']=(string)$row['program_role'];$program['sync_with_action_plan']=(bool)$row['sync_with_action_plan'];$program['action_plan_public_id']=$planPublic;$out[]=$program;}
    return $out;
}
function research_action_plan_link_program(PDO $pdo,array $viewer,string $planPublic,string $programPublic,string $role='execution_review',bool $sync=true): array {
    if(!research_action_plan_follow_through_ready($pdo))throw new RuntimeException('Action Plan follow-through requires the latest database upgrade.');
    if(!isset(research_action_plan_program_roles()[$role]))throw new InvalidArgumentException('Invalid Action Plan follow-through Program role.');
    $plan=research_action_plan_detail($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if(in_array((string)$plan['status'],['completed','cancelled','archived'],true))throw new InvalidArgumentException('Follow-through Programs cannot be linked to a completed, cancelled, or archived Action Plan.');
    $program=research_program_access($pdo,$viewer,$programPublic);if(!$program)throw new RuntimeException('Research Program not found.');
    if((int)$program['research_agent_id']!==(int)$plan['research_agent_id']||(int)$program['project_id']!==(int)$plan['project_id'])throw new InvalidArgumentException('Follow-through Program must belong to the same Research Agent and project as the Action Plan.');
    if((string)$program['status']==='archived')throw new InvalidArgumentException('Archived Research Programs cannot be linked to an Action Plan.');
    $q=$pdo->prepare('SELECT action_plan_id,program_role FROM research_action_plan_program_links WHERE program_id=? LIMIT 1');$q->execute([(int)$program['id']]);$existing=$q->fetch();
    if($existing&&(int)$existing['action_plan_id']!==(int)$plan['id'])throw new InvalidArgumentException('That Research Program is already linked to another Action Plan.');
    $q=$pdo->prepare('SELECT program_id FROM research_action_plan_program_links WHERE action_plan_id=? AND program_role=? LIMIT 1');$q->execute([(int)$plan['id'],$role]);$roleProgram=(int)($q->fetchColumn()?:0);
    if($roleProgram>0&&$roleProgram!==(int)$program['id'])throw new InvalidArgumentException('This Action Plan already has a Program for that follow-through role.');
    if($existing)$pdo->prepare('UPDATE research_action_plan_program_links SET program_role=?,sync_with_action_plan=?,updated_at=NOW() WHERE action_plan_id=? AND program_id=?')->execute([$role,$sync?1:0,(int)$plan['id'],(int)$program['id']]);
    else $pdo->prepare('INSERT INTO research_action_plan_program_links(action_plan_id,program_id,program_role,sync_with_action_plan,created_by_user_id) VALUES(?,?,?,?,?)')->execute([(int)$plan['id'],(int)$program['id'],$role,$sync?1:0,(int)$viewer['id']]);
    research_action_plan_event($pdo,$plan,$existing?'follow_through_program_updated':'follow_through_program_linked','user',(int)$viewer['id'],['program_id'=>(string)$program['public_id'],'program_role'=>$role,'sync_with_action_plan'=>$sync]);
    research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],null,'action_plan_linked','user',(int)$viewer['id'],['action_plan_id'=>(string)$plan['public_id'],'program_role'=>$role,'sync_with_action_plan'=>$sync]);
    if($sync)research_action_plan_sync_programs($pdo,$viewer,$plan,(string)$plan['status']);
    $link=research_action_plan_program_link_row($pdo,$viewer,$programPublic);if(!$link)throw new RuntimeException('Action Plan follow-through Program link could not be loaded.');return $link;
}
function research_action_plan_create_program(PDO $pdo,array $viewer,string $planPublic,array $input,bool $byAgent=false): array {
    if(!research_action_plan_follow_through_ready($pdo))throw new RuntimeException('Action Plan follow-through requires the latest database upgrade.');
    $plan=research_action_plan_detail($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if(in_array((string)$plan['status'],['completed','cancelled','archived'],true))throw new InvalidArgumentException('Follow-through Programs cannot be created for a completed, cancelled, or archived Action Plan.');
    $role=(string)($input['program_role']??'execution_review');if(!isset(research_action_plan_program_roles()[$role]))throw new InvalidArgumentException('Invalid Action Plan follow-through Program role.');
    $q=$pdo->prepare("SELECT rp.public_id FROM research_action_plan_program_links l JOIN research_programs rp ON rp.id=l.program_id WHERE l.action_plan_id=? AND l.program_role=? LIMIT 1");$q->execute([(int)$plan['id'],$role]);$existing=(string)($q->fetchColumn()?:'');
    if($existing!==''){$link=research_action_plan_program_link_row($pdo,$viewer,$existing);if($link)return $link;throw new RuntimeException('Existing follow-through Program is unavailable.');}
    $defaults=research_action_plan_program_defaults($role,$plan);$programInput=array_replace($defaults,$input);$programInput['agent_id']=(string)$plan['agent_public_id'];
    $programInput['tasks']=array_key_exists('tasks',$input)?$input['tasks']:$defaults['tasks'];$programInput['scope']=array_key_exists('scope',$input)?$input['scope']:$defaults['scope'];
    $sync=array_key_exists('sync_with_action_plan',$input)?(bool)$input['sync_with_action_plan']:true;
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
      $program=research_program_create($pdo,$viewer,$programInput,$byAgent);
      $pdo->prepare('INSERT INTO research_action_plan_program_links(action_plan_id,program_id,program_role,sync_with_action_plan,created_by_user_id) VALUES(?,?,?,?,?)')
        ->execute([(int)$plan['id'],(int)$program['id'],$role,$sync?1:0,(int)$viewer['id']]);
      if($sync&&(string)$plan['status']!=='active'&&(string)$program['status']==='active')$program=research_program_set_status($pdo,$viewer,(string)$program['public_id'],'paused');
      research_action_plan_event($pdo,$plan,'follow_through_program_created',$byAgent?'agent':'user',(int)$viewer['id'],['program_id'=>(string)$program['public_id'],'program_role'=>$role,'sync_with_action_plan'=>$sync]);
      research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],null,'action_plan_linked',$byAgent?'agent':'user',(int)$viewer['id'],['action_plan_id'=>(string)$plan['public_id'],'program_role'=>$role,'sync_with_action_plan'=>$sync]);
      if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $link=research_action_plan_program_link_row($pdo,$viewer,(string)$program['public_id']);if(!$link)throw new RuntimeException('Follow-through Program could not be loaded.');return $link;
}
function research_action_plan_set_program_sync(PDO $pdo,array $viewer,string $planPublic,string $programPublic,bool $sync): array {
    $plan=research_action_plan_detail($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    $program=research_program_access($pdo,$viewer,$programPublic);if(!$program)throw new RuntimeException('Research Program not found.');
    $q=$pdo->prepare('UPDATE research_action_plan_program_links SET sync_with_action_plan=?,updated_at=NOW() WHERE action_plan_id=? AND program_id=?');$q->execute([$sync?1:0,(int)$plan['id'],(int)$program['id']]);if(!$q->rowCount())throw new RuntimeException('Follow-through Program link not found.');
    research_action_plan_event($pdo,$plan,'follow_through_program_sync_changed','user',(int)$viewer['id'],['program_id'=>$programPublic,'sync_with_action_plan'=>$sync]);
    if($sync)research_action_plan_sync_programs($pdo,$viewer,$plan,(string)$plan['status']);
    $link=research_action_plan_program_link_row($pdo,$viewer,$programPublic);if(!$link)throw new RuntimeException('Follow-through Program link could not be loaded.');return $link;
}
function research_action_plan_unlink_program(PDO $pdo,array $viewer,string $planPublic,string $programPublic): array {
    $plan=research_action_plan_detail($pdo,$viewer,$planPublic);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    $program=research_program_access($pdo,$viewer,$programPublic);if(!$program)throw new RuntimeException('Research Program not found.');
    $q=$pdo->prepare('DELETE FROM research_action_plan_program_links WHERE action_plan_id=? AND program_id=?');$q->execute([(int)$plan['id'],(int)$program['id']]);if(!$q->rowCount())throw new RuntimeException('Follow-through Program link not found.');
    research_action_plan_event($pdo,$plan,'follow_through_program_unlinked','user',(int)$viewer['id'],['program_id'=>$programPublic]);
    research_program_event($pdo,(int)$program['id'],(int)$program['project_id'],null,'action_plan_unlinked','user',(int)$viewer['id'],['action_plan_id'=>$planPublic]);
    return ['action_plan_id'=>$planPublic,'program_id'=>$programPublic,'unlinked'=>true];
}
function research_action_plan_sync_programs(PDO $pdo,array $viewer,array $plan,string $status): void {
    if(!research_action_plan_follow_through_ready($pdo))return;
    $q=$pdo->prepare("SELECT rp.public_id,rp.status FROM research_action_plan_program_links l JOIN research_programs rp ON rp.id=l.program_id WHERE l.action_plan_id=? AND l.sync_with_action_plan=1 ORDER BY rp.id");$q->execute([(int)$plan['id']]);
    foreach($q->fetchAll() as $row){
      $current=(string)$row['status'];if($current==='archived')continue;$target=$status==='active'?'active':($status==='archived'?'archived':'paused');if($current===$target)continue;
      research_program_set_status($pdo,$viewer,(string)$row['public_id'],$target);
    }
}
function research_action_plan_program_snapshot(PDO $pdo,array $program): ?array {
    if(!research_action_plan_follow_through_ready($pdo))return null;
    $q=$pdo->prepare("SELECT l.program_role,l.sync_with_action_plan,rap.*,rd.public_id decision_public_id,rd.status decision_current_status,rd.current_revision decision_current_revision,rd.config_hash decision_current_config_hash
      FROM research_action_plan_program_links l JOIN research_action_plans rap ON rap.id=l.action_plan_id JOIN research_decisions rd ON rd.id=rap.decision_id WHERE l.program_id=? LIMIT 1");
    $q->execute([(int)$program['id']]);$plan=$q->fetch();if(!$plan)return null;
    $sourceStale=(int)$plan['decision_current_revision']!==(int)$plan['source_decision_revision']||!hash_equals((string)$plan['source_decision_config_hash'],(string)$plan['decision_current_config_hash'])||(string)$plan['decision_current_status']!==(string)$plan['source_decision_status'];
    $today=gmdate('Y-m-d');$terminal=in_array((string)$plan['status'],['completed','cancelled','archived'],true);$overdue=!$terminal&&!empty($plan['due_on'])&&(string)$plan['due_on']<$today;
    $snapshot=[
      'public_id'=>(string)$plan['public_id'],'program_role'=>(string)$plan['program_role'],'sync_with_action_plan'=>(bool)$plan['sync_with_action_plan'],
      'status'=>(string)$plan['status'],'current_revision'=>(int)$plan['current_revision'],'priority'=>(string)$plan['priority'],
      'start_on'=>(string)($plan['start_on']??''),'due_on'=>(string)($plan['due_on']??''),'overdue'=>$overdue,
      'source_stale'=>$sourceStale,'source_decision_public_id'=>(string)$plan['decision_public_id'],'source_decision_status'=>(string)$plan['source_decision_status'],
      'decision_current_status'=>(string)$plan['decision_current_status'],'source_decision_revision'=>(int)$plan['source_decision_revision'],'decision_current_revision'=>(int)$plan['decision_current_revision'],
      'success_measures'=>research_action_plan_json($plan['success_measures_json']??null),'milestones'=>[],'task_counts'=>[]
    ];
    $q=$pdo->prepare("SELECT status,COUNT(*) total FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id WHERE l.action_plan_id=? GROUP BY status ORDER BY status");$q->execute([(int)$plan['id']]);foreach($q->fetchAll() as $r)$snapshot['task_counts'][(string)$r['status']]=(int)$r['total'];
    $q=$pdo->prepare("SELECT m.*,
      (SELECT COUNT(*) FROM research_action_plan_milestone_dependencies d JOIN research_action_plan_milestones parent ON parent.id=d.depends_on_milestone_id WHERE d.milestone_id=m.id AND parent.status<>'completed') blocked_parent_count
      FROM research_action_plan_milestones m WHERE m.action_plan_id=? ORDER BY m.position,m.id");$q->execute([(int)$plan['id']);
    foreach($q->fetchAll() as $m){
      $taskCounts=[];$tq=$pdo->prepare("SELECT rt.status,COUNT(*) total FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id WHERE l.milestone_id=? GROUP BY rt.status ORDER BY rt.status");$tq->execute([(int)$m['id']]);foreach($tq->fetchAll() as $r)$taskCounts[(string)$r['status']]=(int)$r['total'];
      $mTerminal=in_array((string)$m['status'],['completed','cancelled'],true);$mOverdue=!$mTerminal&&!empty($m['target_on'])&&(string)$m['target_on']<$today;
      $snapshot['milestones'][(string)$m['public_id']]=['title'=>(string)$m['title'],'status'=>(string)$m['status'],'target_on'=>(string)($m['target_on']??''),'overdue'=>$mOverdue,'blocked'=>(int)$m['blocked_parent_count']>0,'task_counts'=>$taskCounts];
    }
    ksort($snapshot['milestones']);ksort($snapshot['task_counts']);return $snapshot;
}
function research_action_plan_program_compare_snapshots(PDO $pdo,array $program,int $runId,array $after,?array $before): array {
    if($before===null)return [];$out=[];$planId=(string)$after['public_id'];
    if((string)($before['status']??'')!==(string)$after['status'])$out[]=research_program_add_delta($pdo,$program,$runId,'action_plan_status_changed','Action Plan status changed from '.($before['status']??'unknown').' to '.$after['status'].'.','action_plan',$planId,$before,$after,'important');
    if(empty($before['source_stale'])&&!empty($after['source_stale']))$out[]=research_program_add_delta($pdo,$program,$runId,'action_plan_source_stale','Action Plan source Decision changed after the execution plan was pinned.','action_plan',$planId,$before,$after,'high');
    if(!empty($before['source_stale'])&&empty($after['source_stale']))$out[]=research_program_add_delta($pdo,$program,$runId,'action_plan_source_current','Action Plan source Decision provenance is current again.','action_plan',$planId,$before,$after,'important');
    if(empty($before['overdue'])&&!empty($after['overdue']))$out[]=research_program_add_delta($pdo,$program,$runId,'action_plan_overdue','Action Plan passed its due date without reaching a terminal state.','action_plan',$planId,$before,$after,'high');
    $beforeMilestones=(array)($before['milestones']??[]);$afterMilestones=(array)($after['milestones']??[]);
    foreach($afterMilestones as $id=>$m){
      $old=$beforeMilestones[$id]??null;
      if($old===null){$out[]=research_program_add_delta($pdo,$program,$runId,'milestone_added','Action Plan milestone added: '.$m['title'].'.','milestone',(string)$id,[],$m,'important');continue;}
      if((string)($old['status']??'')!==(string)$m['status']){
        if((string)$m['status']==='in_progress')$type='milestone_started';elseif((string)$m['status']==='completed')$type='milestone_completed';else $type='execution_progress_changed';
        $out[]=research_program_add_delta($pdo,$program,$runId,$type,'Milestone “'.$m['title'].'” changed from '.($old['status']??'unknown').' to '.$m['status'].'.','milestone',(string)$id,$old,$m,$type==='execution_progress_changed'?'info':'important');
      }
      if(empty($old['blocked'])&&!empty($m['blocked']))$out[]=research_program_add_delta($pdo,$program,$runId,'milestone_blocked','Milestone blocked by incomplete parent work: '.$m['title'].'.','milestone',(string)$id,$old,$m,'high');
      if(!empty($old['blocked'])&&empty($m['blocked']))$out[]=research_program_add_delta($pdo,$program,$runId,'milestone_unblocked','Milestone dependency blocker cleared: '.$m['title'].'.','milestone',(string)$id,$old,$m,'important');
      if(empty($old['overdue'])&&!empty($m['overdue']))$out[]=research_program_add_delta($pdo,$program,$runId,'milestone_overdue','Milestone passed its target date: '.$m['title'].'.','milestone',(string)$id,$old,$m,'high');
      if((array)($old['task_counts']??[])!==(array)$m['task_counts'])$out[]=research_program_add_delta($pdo,$program,$runId,'execution_progress_changed','Execution task progress changed for milestone: '.$m['title'].'.','milestone',(string)$id,$old,$m,'info');
    }
    if((array)($before['task_counts']??[])!==(array)$after['task_counts'])$out[]=research_program_add_delta($pdo,$program,$runId,'execution_progress_changed','Action Plan execution task progress changed.','action_plan',$planId,['task_counts'=>$before['task_counts']??[]],['task_counts'=>$after['task_counts']],'info');
    return $out;
}

