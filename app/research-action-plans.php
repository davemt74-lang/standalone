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
    $value=trim((string)$value;if($value==='')return null;
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
function research_action_plan_source_snapshot(array $decision): array {
    return [
      'decision_public_id'=>(string)$decision['public_id'],'decision_type'=>(string)$decision['decision_type'],'title'=>(string)$decision['title'],
      'statement'=>(string)$decision['statement'],'rationale'=>(string)($decision['rationale']??''),'confidence'=>$decision['confidence']!==null?(float)$decision['confidence']:null,
      'status'=>(string)$decision['status'],'revision'=>(int)$decision['current_revision'],'config_hash'=>(string)$decision['config_hash'],
      'accountable_user_public_id'=>(string)($decision['accountable_user_public_id']??''),'decided_at'=>(string)($decision['decided_at']??''),
      'config'=>research_decision_config($decision)
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
    $snapshot=research_action_plan_source_snapshot($decision);$snapshotJson=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
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
    if($status==='active'){
      if((string)$plan['decision_status']!=='accepted')throw new InvalidArgumentException('The source Decision must currently be Accepted before an Action Plan can activate.');
      if(!empty($plan['source_stale']))throw new InvalidArgumentException('The source Decision changed after this Action Plan was created. Create or revise the Action Plan from current Decision state before activation.');
      if(count((array)$plan['success_measures'])<1)throw new InvalidArgumentException('At least one success measure is required before activation.');
    }
    $activated=$status==='active'&&empty($plan['activated_at']);$completed=$status==='completed';$cancelled=$status==='cancelled';
    $sql="UPDATE research_action_plans SET status=?,updated_at=NOW()";
    if($activated)$sql.=",activated_at=NOW()";if($completed)$sql.=",completed_at=NOW()";if($cancelled)$sql.=",cancelled_at=NOW()";$sql.=" WHERE id=?";
    $pdo->prepare($sql)->execute([$status,(int)$plan['id']]);$fresh=research_action_plan_by_id($pdo,(int)$plan['id']);
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
