<?php
declare(strict_types=1);

function research_decisions_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_decisions')&&installer_table_exists($pdo,'research_decision_versions')&&installer_table_exists($pdo,'research_decision_refs')&&installer_table_exists($pdo,'research_decision_events');}
    catch(Throwable $e){return false;}
}

function research_decision_types(): array {return ['decision'=>'Decision','conclusion'=>'Conclusion','recommendation'=>'Recommendation'];}
function research_decision_statuses(): array {return ['draft'=>'Draft','proposed'=>'Proposed','accepted'=>'Accepted','rejected'=>'Rejected','deferred'=>'Deferred','superseded'=>'Superseded','reopened'=>'Reopened','archived'=>'Archived'];}
function research_decision_ref_roles(): array {return ['supports'=>'Supports','contradicts'=>'Contradicts','context'=>'Context','source'=>'Source','assumption'=>'Assumption','alternative'=>'Alternative'];}
function research_decision_text(string $value,int $max): string {return mb_substr(trim($value),0,$max);}
function research_decision_json(mixed $value): array {
    if(is_array($value))return $value;
    if(is_string($value)&&trim($value)!==''){try{$x=json_decode($value,true,512,JSON_THROW_ON_ERROR);return is_array($x)?$x:[];}catch(Throwable $e){return [];}}
    return [];
}

function research_decision_agent(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_task_agent($pdo,$viewer,trim($agentPublic));if(!$agent)throw new RuntimeException('Research Agent is unavailable.');
    return $agent;
}
function research_decision_project(PDO $pdo,array $viewer,array $agent): array {return research_task_project($pdo,$viewer,$agent);}

function research_decision_accountable_user(PDO $pdo,array $viewer,array $agent,mixed $publicId): ?int {
    $public=trim((string)$publicId);if($public==='')return null;
    $q=$pdo->prepare("SELECT u.id FROM users u
      LEFT JOIN team_members tm ON tm.user_id=u.id AND tm.team_id=?
      WHERE u.public_id=? AND ((? IS NULL AND u.id=?) OR (? IS NOT NULL AND tm.user_id IS NOT NULL)) LIMIT 1");
    $team=$agent['team_id']??null;$q->execute([$team,$public,$team,(int)$viewer['id'],$team]);$id=$q->fetchColumn();
    if(!$id)throw new InvalidArgumentException('Accountable owner must have access to this Research Agent.');
    return (int)$id;
}

function research_decision_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_decisions_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rd.*,ra.public_id agent_public_id,ra.name agent_name,ra.team_id,
      rp.public_id project_public_id,rp.title project_title,
      cu.public_id created_by_user_public_id,au.public_id accountable_user_public_id,
      rm.public_id source_mission_public_id
      FROM research_decisions rd
      JOIN research_agents ra ON ra.id=rd.research_agent_id
      JOIN research_projects rp ON rp.id=rd.project_id
      JOIN users cu ON cu.id=rd.created_by_user_id
      LEFT JOIN users au ON au.id=rd.accountable_user_id
      LEFT JOIN research_missions rm ON rm.id=rd.source_mission_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rd.public_id=? AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($publicId),(int)$viewer['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}

function research_decision_by_id(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare("SELECT rd.*,ra.public_id agent_public_id,rp.public_id project_public_id,au.public_id accountable_user_public_id,rm.public_id source_mission_public_id
      FROM research_decisions rd JOIN research_agents ra ON ra.id=rd.research_agent_id JOIN research_projects rp ON rp.id=rd.project_id
      LEFT JOIN users au ON au.id=rd.accountable_user_id LEFT JOIN research_missions rm ON rm.id=rd.source_mission_id WHERE rd.id=? LIMIT 1");
    $q->execute([$id]);return $q->fetch()?:null;
}

function research_decision_versions(PDO $pdo,int $decisionId,int $limit=50): array {
    $q=$pdo->prepare("SELECT public_id,revision_number,config_json,change_reason,edited_by_user_id,edited_by_agent,created_at FROM research_decision_versions WHERE decision_id=? ORDER BY revision_number DESC,id DESC LIMIT ".max(1,min(100,$limit)));
    $q->execute([$decisionId]);$rows=$q->fetchAll()?:[];foreach($rows as &$r)$r['config']=research_decision_json($r['config_json']??null);unset($r);return $rows;
}
function research_decision_events(PDO $pdo,int $decisionId,int $limit=100): array {
    $q=$pdo->prepare("SELECT public_id,event_type,actor_type,actor_user_id,payload_json,created_at FROM research_decision_events WHERE decision_id=? ORDER BY id DESC LIMIT ".max(1,min(300,$limit)));
    $q->execute([$decisionId]);$rows=$q->fetchAll()?:[];foreach($rows as &$r)$r['payload']=research_decision_json($r['payload_json']??null);unset($r);return $rows;
}
function research_decision_refs(PDO $pdo,int $decisionId): array {
    $q=$pdo->prepare("SELECT public_id,ref_type,ref_public_id,ref_role,strength,note,added_by_user_id,added_by_agent,created_at,updated_at FROM research_decision_refs WHERE decision_id=? ORDER BY FIELD(ref_role,'supports','contradicts','source','assumption','alternative','context'),id");
    $q->execute([$decisionId]);return $q->fetchAll()?:[];
}

function research_decision_event(PDO $pdo,array $decision,string $type,string $actorType='system',?int $userId=null,array $payload=[]): void {
    $pdo->prepare("INSERT INTO research_decision_events(public_id,decision_id,project_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$decision['id'],(int)$decision['project_id'],mb_substr($type,0,64),$actorType,$userId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function research_decision_ref_normalize(array $raw): array {
    $type=strtolower(trim((string)($raw['type']??$raw['ref_type']??'')));$id=trim((string)($raw['id']??$raw['public_id']??$raw['ref_public_id']??''));
    if($type==='research_project'||$type==='research')$type='project';if($type==='research_plan')$type='plan';if($type==='research_program')$type='program';
    if($type==='research_mission')$type='mission';if($type==='research_review')$type='review';
    $role=(string)($raw['role']??$raw['ref_role']??'context');if(!isset(research_decision_ref_roles()[$role]))$role='context';
    $strength=array_key_exists('strength',$raw)&&$raw['strength']!==''?(float)$raw['strength']:null;if($strength!==null&&($strength<0||$strength>1))throw new InvalidArgumentException('Reference strength must be between 0 and 1.');
    return ['type'=>$type,'public_id'=>$id,'role'=>$role,'strength'=>$strength,'note'=>research_decision_text((string)($raw['note']??''),2000)];
}

function research_decision_ref_access(PDO $pdo,array $viewer,int $projectId,string $type,string $publicId): bool {
    $type=strtolower(trim($type));$publicId=trim($publicId);if($type===''||$publicId==='')return false;
    if($type==='project'){$q=$pdo->prepare('SELECT public_id FROM research_projects WHERE id=? AND public_id=?');$q->execute([$projectId,$publicId]);return (bool)$q->fetchColumn();}
    if($type==='mission'){if(!function_exists('research_mission_access'))return false;$r=research_mission_access($pdo,$viewer,$publicId);return $r&&(int)$r['project_id']===$projectId;}
    if($type==='source'){$q=$pdo->prepare('SELECT 1 FROM project_sources ps JOIN sources s ON s.id=ps.source_id WHERE ps.project_id=? AND s.public_id=? LIMIT 1');$q->execute([$projectId,$publicId]);return (bool)$q->fetchColumn();}
    if($type==='claim'&&function_exists('research_claim_access')){$r=research_claim_access($pdo,$viewer,$publicId);return $r&&(int)$r['project_id']===$projectId;}
    if($type==='finding'&&function_exists('research_finding_access')){$r=research_finding_access($pdo,$viewer,$publicId);return $r&&(int)$r['project_id']===$projectId;}
    if($type==='entity'&&function_exists('research_entity_access')){$r=research_entity_access($pdo,$viewer,$publicId);return $r&&(int)$r['project_id']===$projectId;}
    $map=[
      'task'=>['research_tasks','project_id'],'plan'=>['research_task_plans','project_id'],'program'=>['research_programs','project_id'],
      'document'=>['research_documents','project_id']
    ];
    if(isset($map[$type])){[$table,$projectCol]=$map[$type];try{$q=$pdo->prepare("SELECT 1 FROM {$table} WHERE {$projectCol}=? AND public_id=? LIMIT 1");$q->execute([$projectId,$publicId]);return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
    if($type==='report_version'){try{$q=$pdo->prepare('SELECT 1 FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id WHERE rr.project_id=? AND rv.public_id=? LIMIT 1');$q->execute([$projectId,$publicId]);return (bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
    if($type==='review'){if(!function_exists('research_review_access'))return false;$r=research_review_access($pdo,$viewer,$publicId);return $r&&(int)$r['project_id']===$projectId;}
    return false;
}

function research_decision_config(PDO $pdo,array $decision): array {
    $refs=[];foreach(research_decision_refs($pdo,(int)$decision['id']) as $r)$refs[]=[
      'ref_type'=>(string)$r['ref_type'],'ref_public_id'=>(string)$r['ref_public_id'],'ref_role'=>(string)$r['ref_role'],
      'strength'=>$r['strength']!==null?(float)$r['strength']:null,'note'=>(string)($r['note']??'')
    ];
    usort($refs,fn($a,$b)=>strcmp(implode('|',[$a['ref_role'],$a['ref_type'],$a['ref_public_id']]),implode('|',[$b['ref_role'],$b['ref_type'],$b['ref_public_id']])));
    return [
      'decision_type'=>(string)$decision['decision_type'],'title'=>(string)$decision['title'],'statement'=>(string)$decision['statement'],
      'rationale'=>(string)($decision['rationale']??''),'confidence'=>$decision['confidence']!==null?(float)$decision['confidence']:null,
      'assumptions'=>research_decision_json($decision['assumptions_json']??null),'uncertainty'=>research_decision_json($decision['uncertainty_json']??null),
      'alternatives'=>research_decision_json($decision['alternatives_json']??null),'accountable_user_public_id'=>(string)($decision['accountable_user_public_id']??''),
      'source_mission_public_id'=>(string)($decision['source_mission_public_id']??''),'refs'=>$refs
    ];
}
function research_decision_hash(array $config): string {return hash('sha256',json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));}
function research_decision_snapshot(PDO $pdo,array $decision,string $reason,?int $userId,bool $byAgent=false): void {
    $config=research_decision_config($pdo,$decision);
    $pdo->prepare("INSERT INTO research_decision_versions(public_id,decision_id,revision_number,config_json,change_reason,edited_by_user_id,edited_by_agent) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$decision['id'],(int)$decision['current_revision'],json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),research_decision_text($reason,1000)?:null,$userId,$byAgent?1:0]);
}
function research_decision_refresh_revision(PDO $pdo,int $decisionId,?int $userId,string $reason,bool $byAgent=false): array {
    $decision=research_decision_by_id($pdo,$decisionId);if(!$decision)throw new RuntimeException('Decision is unavailable.');
    $hash=research_decision_hash(research_decision_config($pdo,$decision));if(hash_equals((string)$decision['config_hash'],$hash))return $decision;
    $revision=(int)$decision['current_revision']+1;$pdo->prepare('UPDATE research_decisions SET current_revision=?,config_hash=?,updated_at=NOW() WHERE id=?')->execute([$revision,$hash,$decisionId]);
    $decision=research_decision_by_id($pdo,$decisionId);research_decision_snapshot($pdo,$decision,$reason,$userId,$byAgent);return $decision;
}

function research_decision_source_mission(PDO $pdo,array $viewer,array $agent,array $project,mixed $missionPublic): ?int {
    $public=trim((string)$missionPublic);if($public==='')return null;
    $mission=research_mission_access($pdo,$viewer,$public);if(!$mission||(int)$mission['research_agent_id']!==(int)$agent['id']||(int)$mission['project_id']!==(int)$project['id'])throw new InvalidArgumentException('Source Mission must belong to the same Research Agent and project.');
    return (int)$mission['id'];
}

function research_decision_store_ref(PDO $pdo,array $viewer,array $decision,array $raw,bool $byAgent=false,bool $refresh=true): array {
    $ref=research_decision_ref_normalize($raw);if($ref['type']===''||$ref['public_id']==='')throw new InvalidArgumentException('Reference type and ID are required.');
    if(!research_decision_ref_access($pdo,$viewer,(int)$decision['project_id'],$ref['type'],$ref['public_id']))throw new InvalidArgumentException('Decision reference is unavailable in this Research project.');
    $pdo->prepare("INSERT INTO research_decision_refs(public_id,decision_id,ref_type,ref_public_id,ref_role,strength,note,added_by_user_id,added_by_agent)
      VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE strength=VALUES(strength),note=VALUES(note),added_by_user_id=VALUES(added_by_user_id),added_by_agent=VALUES(added_by_agent),updated_at=NOW()")
      ->execute([ulid_like(),(int)$decision['id'],$ref['type'],$ref['public_id'],$ref['role'],$ref['strength'],$ref['note']!==''?$ref['note']:null,(int)$viewer['id'],$byAgent?1:0]);
    if($refresh){$fresh=research_decision_refresh_revision($pdo,(int)$decision['id'],(int)$viewer['id'],'Evidence lineage updated',$byAgent);research_decision_event($pdo,$fresh,'decision_reference_added',$byAgent?'agent':'user',(int)$viewer['id'],$ref);}
    $q=$pdo->prepare('SELECT public_id,ref_type,ref_public_id,ref_role,strength,note,added_by_user_id,added_by_agent,created_at,updated_at FROM research_decision_refs WHERE decision_id=? AND ref_type=? AND ref_public_id=? AND ref_role=? LIMIT 1');$q->execute([(int)$decision['id'],$ref['type'],$ref['public_id'],$ref['role']]);return $q->fetch()?:$ref;
}
function research_decision_remove_ref(PDO $pdo,array $viewer,string $decisionPublic,string $refPublic,bool $byAgent=false): array {
    $decision=research_decision_access($pdo,$viewer,$decisionPublic);if(!$decision)throw new RuntimeException('Decision not found.');
    $q=$pdo->prepare('SELECT * FROM research_decision_refs WHERE decision_id=? AND public_id=? LIMIT 1');$q->execute([(int)$decision['id'],trim($refPublic)]);$ref=$q->fetch();if(!$ref)throw new RuntimeException('Decision reference not found.');
    $pdo->prepare('DELETE FROM research_decision_refs WHERE id=?')->execute([(int)$ref['id']]);
    $fresh=research_decision_refresh_revision($pdo,(int)$decision['id'],(int)$viewer['id'],'Evidence lineage updated',$byAgent);
    research_decision_event($pdo,$fresh,'decision_reference_removed',$byAgent?'agent':'user',(int)$viewer['id'],['ref_type'=>$ref['ref_type'],'ref_public_id'=>$ref['ref_public_id'],'ref_role'=>$ref['ref_role']]);
    return research_decision_detail($pdo,$viewer,$decisionPublic)??$fresh;
}

function research_decision_detail(PDO $pdo,array $viewer,string $publicId): ?array {
    $decision=research_decision_access($pdo,$viewer,$publicId);if(!$decision)return null;
    $decision['assumptions']=research_decision_json($decision['assumptions_json']??null);$decision['uncertainty']=research_decision_json($decision['uncertainty_json']??null);$decision['alternatives']=research_decision_json($decision['alternatives_json']??null);
    $decision['refs']=research_decision_refs($pdo,(int)$decision['id']);$decision['versions']=research_decision_versions($pdo,(int)$decision['id'],50);$decision['events']=research_decision_events($pdo,(int)$decision['id'],100);
    return $decision;
}

function research_decision_create(PDO $pdo,array $viewer,array $input,bool $byAgent=false): array {
    if(!research_decisions_ready($pdo))throw new RuntimeException('Research Decisions require the latest database upgrade.');
    $agent=research_decision_agent($pdo,$viewer,(string)($input['agent_id']??''));$project=research_decision_project($pdo,$viewer,$agent);
    $type=(string)($input['decision_type']??$input['type']??'decision');if(!isset(research_decision_types()[$type]))throw new InvalidArgumentException('Invalid decision type.');
    $title=research_decision_text((string)($input['title']??''),255);$statement=research_decision_text((string)($input['statement']??$input['conclusion']??''),64000);
    if($title===''||$statement==='')throw new InvalidArgumentException('Decision title and statement are required.');
    $rationale=research_decision_text((string)($input['rationale']??''),64000);$confidence=array_key_exists('confidence',$input)&&$input['confidence']!==''?(float)$input['confidence']:null;if($confidence!==null&&($confidence<0||$confidence>1))throw new InvalidArgumentException('Decision confidence must be between 0 and 1.');
    $accountable=research_decision_accountable_user($pdo,$viewer,$agent,$input['accountable_user_id']??$input['accountable_user_public_id']??$viewer['public_id']??'');
    $missionId=research_decision_source_mission($pdo,$viewer,$agent,$project,$input['mission_id']??$input['source_mission_id']??'');
    $assumptions=research_decision_json($input['assumptions']??[]);$uncertainty=research_decision_json($input['uncertainty']??[]);$alternatives=research_decision_json($input['alternatives']??[]);
    $refs=array_slice((array)($input['refs']??$input['evidence_refs']??[]),0,100);$public=ulid_like();$placeholder=str_repeat('0',64);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO research_decisions(public_id,research_agent_id,project_id,created_by_user_id,accountable_user_id,source_mission_id,decision_type,title,statement,rationale,status,confidence,assumptions_json,uncertainty_json,alternatives_json,current_revision,config_hash)
          VALUES(?,?,?,?,?,?,?,?,?,?,'draft',?,?,?,?,1,?)")->execute([
          $public,(int)$agent['id'],(int)$project['id'],(int)$viewer['id'],$accountable,$missionId,$type,$title,$statement,$rationale!==''?$rationale:null,$confidence,
          $assumptions?json_encode($assumptions,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$uncertainty?json_encode($uncertainty,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,
          $alternatives?json_encode($alternatives,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$placeholder
        ]);
        $id=(int)$pdo->lastInsertId();$decision=research_decision_by_id($pdo,$id);
        foreach($refs as $ref)if(is_array($ref))research_decision_store_ref($pdo,$viewer,$decision,$ref,$byAgent,false);
        $decision=research_decision_by_id($pdo,$id);$hash=research_decision_hash(research_decision_config($pdo,$decision));$pdo->prepare('UPDATE research_decisions SET config_hash=? WHERE id=?')->execute([$hash,$id]);$decision=research_decision_by_id($pdo,$id);
        research_decision_snapshot($pdo,$decision,'Initial decision',(int)$viewer['id'],$byAgent);research_decision_event($pdo,$decision,'decision_created',$byAgent?'agent':'user',(int)$viewer['id'],['decision_type'=>$type,'reference_count'=>count(research_decision_refs($pdo,$id))]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_decision_detail($pdo,$viewer,$public)??['public_id'=>$public,'title'=>$title];
}

function research_decision_update(PDO $pdo,array $viewer,string $publicId,array $input,bool $byAgent=false): array {
    $decision=research_decision_access($pdo,$viewer,$publicId);if(!$decision)throw new RuntimeException('Decision not found.');
    $type=(string)($input['decision_type']??$input['type']??$decision['decision_type']);if(!isset(research_decision_types()[$type]))throw new InvalidArgumentException('Invalid decision type.');
    $title=research_decision_text((string)($input['title']??$decision['title']),255);$statement=research_decision_text((string)($input['statement']??$decision['statement']),64000);if($title===''||$statement==='')throw new InvalidArgumentException('Decision title and statement are required.');
    $rationale=array_key_exists('rationale',$input)?research_decision_text((string)$input['rationale'],64000):(string)($decision['rationale']??'');
    $confidence=array_key_exists('confidence',$input)?($input['confidence']===''?null:(float)$input['confidence']):($decision['confidence']!==null?(float)$decision['confidence']:null);if($confidence!==null&&($confidence<0||$confidence>1))throw new InvalidArgumentException('Decision confidence must be between 0 and 1.');
    $agent=research_decision_agent($pdo,$viewer,(string)$decision['agent_public_id']);$accountable=array_key_exists('accountable_user_id',$input)||array_key_exists('accountable_user_public_id',$input)?research_decision_accountable_user($pdo,$viewer,$agent,$input['accountable_user_id']??$input['accountable_user_public_id']):($decision['accountable_user_id']!==null?(int)$decision['accountable_user_id']:null);
    $assumptions=array_key_exists('assumptions',$input)?research_decision_json($input['assumptions']):research_decision_json($decision['assumptions_json']??null);
    $uncertainty=array_key_exists('uncertainty',$input)?research_decision_json($input['uncertainty']):research_decision_json($decision['uncertainty_json']??null);
    $alternatives=array_key_exists('alternatives',$input)?research_decision_json($input['alternatives']):research_decision_json($decision['alternatives_json']??null);
    $pdo->prepare("UPDATE research_decisions SET decision_type=?,title=?,statement=?,rationale=?,confidence=?,accountable_user_id=?,assumptions_json=?,uncertainty_json=?,alternatives_json=?,updated_at=NOW() WHERE id=?")->execute([
      $type,$title,$statement,$rationale!==''?$rationale:null,$confidence,$accountable,
      $assumptions?json_encode($assumptions,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$uncertainty?json_encode($uncertainty,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$alternatives?json_encode($alternatives,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,(int)$decision['id']
    ]);
    $fresh=research_decision_refresh_revision($pdo,(int)$decision['id'],(int)$viewer['id'],research_decision_text((string)($input['reason']??'Decision updated'),1000),$byAgent);research_decision_event($pdo,$fresh,'decision_updated',$byAgent?'agent':'user',(int)$viewer['id'],['revision'=>(int)$fresh['current_revision']]);
    return research_decision_detail($pdo,$viewer,$publicId)??$fresh;
}

function research_decision_set_status(PDO $pdo,array $viewer,string $publicId,string $status,bool $byAgent=false): array {
    $decision=research_decision_access($pdo,$viewer,$publicId);if(!$decision)throw new RuntimeException('Decision not found.');if(!isset(research_decision_statuses()[$status]))throw new InvalidArgumentException('Invalid decision status.');
    $current=(string)$decision['status'];if($current===$status)return research_decision_detail($pdo,$viewer,$publicId)??$decision;
    $allowed=[
      'draft'=>['proposed','accepted','rejected','deferred','archived'],'proposed'=>['draft','accepted','rejected','deferred','archived'],
      'accepted'=>['reopened','superseded','archived'],'rejected'=>['reopened','superseded','archived'],'deferred'=>['reopened','accepted','rejected','archived'],
      'reopened'=>['proposed','accepted','rejected','deferred','superseded','archived'],'superseded'=>['reopened','archived'],'archived'=>['reopened']
    ];
    if(!in_array($status,$allowed[$current]??[],true))throw new InvalidArgumentException('That decision status transition is not allowed.');
    if(in_array($status,['accepted','rejected','deferred'],true)&&trim((string)($decision['rationale']??''))==='')throw new InvalidArgumentException('A rationale is required before recording a decision outcome.');
    $decided=in_array($status,['accepted','rejected','deferred','superseded'],true)?'NOW()':($status==='reopened'?'NULL':'decided_at');
    $sql=$decided==='NOW()'?"UPDATE research_decisions SET status=?,decided_at=NOW(),updated_at=NOW() WHERE id=?":($decided==='NULL'?"UPDATE research_decisions SET status=?,decided_at=NULL,updated_at=NOW() WHERE id=?":"UPDATE research_decisions SET status=?,updated_at=NOW() WHERE id=?");
    $pdo->prepare($sql)->execute([$status,(int)$decision['id']]);$fresh=research_decision_by_id($pdo,(int)$decision['id']);
    research_decision_event($pdo,$fresh,'decision_status_changed',$byAgent?'agent':'user',(int)$viewer['id'],['from'=>$current,'to'=>$status]);
    return research_decision_detail($pdo,$viewer,$publicId)??$fresh;
}

function research_decision_add_ref(PDO $pdo,array $viewer,string $decisionPublic,array $input,bool $byAgent=false): array {
    $decision=research_decision_access($pdo,$viewer,$decisionPublic);if(!$decision)throw new RuntimeException('Decision not found.');return research_decision_store_ref($pdo,$viewer,$decision,$input,$byAgent,true);
}

function research_decision_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=100,?string $status=null): array {
    if(!research_decisions_ready($pdo))return [];$agent=research_decision_agent($pdo,$viewer,$agentPublic);$project=research_decision_project($pdo,$viewer,$agent);$limit=max(1,min(250,$limit));$params=[(int)$agent['id'],(int)$project['id']];$where='research_agent_id=? AND project_id=?';
    if($status!==null&&$status!==''){if(!isset(research_decision_statuses()[$status]))throw new InvalidArgumentException('Invalid decision status.');$where.=' AND status=?';$params[]=$status;}
    $q=$pdo->prepare("SELECT public_id FROM research_decisions WHERE {$where} ORDER BY FIELD(status,'proposed','reopened','draft','deferred','accepted','rejected','superseded','archived'),updated_at DESC,id DESC LIMIT ".$limit);$q->execute($params);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_decision_detail($pdo,$viewer,(string)$id);if($row)$out[]=$row;}return $out;
}
function research_decision_summary(PDO $pdo,array $viewer,string $agentPublic): array {
    if(!research_decisions_ready($pdo))return ['total'=>0,'statuses'=>[],'types'=>[]];$agent=research_decision_agent($pdo,$viewer,$agentPublic);$project=research_decision_project($pdo,$viewer,$agent);
    $q=$pdo->prepare('SELECT status,COUNT(*) c FROM research_decisions WHERE research_agent_id=? AND project_id=? GROUP BY status');$q->execute([(int)$agent['id'],(int)$project['id']]);$statuses=[];$total=0;foreach($q->fetchAll() as $r){$statuses[(string)$r['status']]=(int)$r['c'];$total+=(int)$r['c'];}
    $q=$pdo->prepare('SELECT decision_type,COUNT(*) c FROM research_decisions WHERE research_agent_id=? AND project_id=? GROUP BY decision_type');$q->execute([(int)$agent['id'],(int)$project['id']]);$types=[];foreach($q->fetchAll() as $r)$types[(string)$r['decision_type']]=(int)$r['c'];return ['total'=>$total,'statuses'=>$statuses,'types'=>$types];
}
