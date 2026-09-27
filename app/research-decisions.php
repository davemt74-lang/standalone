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
      'source_mission_public_id'=>(string)($decision['source_mission_public_id']??''),'refs'=>$refs,
      'challenges'=>research_decision_challenge_config_rows($pdo,(int)$decision['id'])
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
    $decision['handoff']=research_decision_handoff_for_decision($pdo,(int)$decision['id']);
    $decision['challenges']=research_decision_challenges($pdo,(int)$decision['id']);
    $decision['outcomes']=research_decision_outcomes($pdo,$viewer,(int)$decision['id']);
    $decision['reconsiderations']=research_decision_reconsiderations($pdo,$viewer,(int)$decision['id']);
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

function research_decision_handoffs_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_decision_handoffs');}catch(Throwable $e){return false;}
}

function research_decision_handoff_for_decision(PDO $pdo,int $decisionId): ?array {
    if(!research_decision_handoffs_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rdh.*,rm.public_id mission_public_id,rd.public_id decision_public_id FROM research_decision_handoffs rdh JOIN research_missions rm ON rm.id=rdh.mission_id JOIN research_decisions rd ON rd.id=rdh.decision_id WHERE rdh.decision_id=? LIMIT 1");
    $q->execute([$decisionId]);$row=$q->fetch();if(!$row)return null;$row['snapshot']=research_decision_json($row['snapshot_json']??null);return $row;
}

function research_decision_mission_snapshot(array $mission): array {
    $progress=(array)($mission['progress']??[]);$criteria=[];$questions=[];
    foreach((array)($mission['criteria']??[]) as $c)$criteria[]=['public_id'=>(string)$c['public_id'],'label'=>(string)$c['label'],'status'=>(string)$c['status'],'evaluation'=>research_decision_json($c['evaluation_json']??null)];
    foreach((array)($mission['subquestions']??[]) as $q)$questions[]=['public_id'=>(string)$q['public_id'],'question'=>(string)$q['question'],'status'=>(string)$q['status'],'answer_summary'=>(string)($q['answer_summary']??''),'confidence'=>$q['confidence']!==null?(float)$q['confidence']:null,'linked_task_public_id'=>(string)($q['linked_task_public_id']??'')];
    return [
      'mission_public_id'=>(string)$mission['public_id'],'mission_revision'=>(int)$mission['current_revision'],'mission_config_hash'=>(string)$mission['config_hash'],
      'mission_status'=>(string)$mission['status'],'title'=>(string)$mission['title'],'research_question'=>(string)$mission['research_question'],'objective'=>(string)$mission['objective'],
      'success_definition'=>(string)($mission['success_definition']??''),'plan_public_id'=>(string)($mission['plan_public_id']??''),'plan_status'=>(string)($mission['plan']['status']??''),
      'program_public_id'=>(string)($mission['program_public_id']??''),'primary_answer'=>(string)($progress['primary_answer']['summary']??''),
      'percent_complete'=>(int)($progress['percent_complete']??0),'completion_readiness'=>(array)($progress['completion_readiness']??[]),
      'confidence'=>(array)($progress['confidence']??[]),'criteria'=>$criteria,'subquestions'=>$questions
    ];
}

function research_decision_mission_refs(array $mission): array {
    $refs=[['type'=>'mission','id'=>(string)$mission['public_id'],'role'=>'context','note'=>'Source Research Mission']];
    if(!empty($mission['plan_public_id']))$refs[]=['type'=>'plan','id'=>(string)$mission['plan_public_id'],'role'=>'context','note'=>'Mission execution Plan'];
    if(!empty($mission['program_public_id']))$refs[]=['type'=>'program','id'=>(string)$mission['program_public_id'],'role'=>'context','note'=>'Mission change-response Program'];
    foreach((array)($mission['plan']['tasks']??[]) as $task){
        foreach((array)($task['evidence_refs']??[]) as $e){
            $role=(string)($e['relationship']??'context');if($role==='primary')$role='source';if(!isset(research_decision_ref_roles()[$role]))$role='context';
            $refs[]=['type'=>(string)($e['ref_type']??''),'id'=>(string)($e['ref_public_id']??''),'role'=>$role,'note'=>research_decision_text('Mission task evidence: '.(string)($task['title']??''),2000)];
        }
    }
    $out=[];$seen=[];foreach($refs as $r){$key=strtolower((string)$r['type']).'|'.(string)$r['id'].'|'.(string)$r['role'];if(($r['id']??'')===''||isset($seen[$key]))continue;$seen[$key]=true;$out[]=$r;}return array_slice($out,0,100);
}

function research_decision_from_mission(PDO $pdo,array $viewer,string $missionPublic,array $input=[],bool $byAgent=false): array {
    if(!research_decision_handoffs_ready($pdo))throw new RuntimeException('Mission Decision handoff requires the latest database upgrade.');
    $mission=research_mission_detail($pdo,$viewer,trim($missionPublic));if(!$mission)throw new RuntimeException('Research Mission not found.');
    if(!in_array((string)$mission['status'],['review','completed'],true))throw new InvalidArgumentException('Only a Mission in Review or Completed state can be handed off to a Decision.');
    if((string)($mission['plan']['status']??'')!=='completed')throw new InvalidArgumentException('Mission execution must be complete before Decision handoff.');
    $answer=trim((string)($mission['progress']['primary_answer']['summary']??''));if($answer==='')throw new InvalidArgumentException('Mission synthesis is required before Decision handoff.');
    $snapshot=research_decision_mission_snapshot($mission);$snapshotJson=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);$stateHash=hash('sha256',$snapshotJson);
    $type=(string)($input['decision_type']??$input['type']??'conclusion');if(!isset(research_decision_types()[$type]))throw new InvalidArgumentException('Invalid decision type.');
    $title=research_decision_text((string)($input['title']??((string)$mission['title'].' — '.research_decision_types()[$type])),255);
    $statement=research_decision_text((string)($input['statement']??$answer),64000);if($statement==='')throw new InvalidArgumentException('Decision statement is required.');
    $idempotencyRaw=trim((string)($input['idempotency_key']??''));if($idempotencyRaw==='')$idempotencyRaw=json_encode(['mission'=>$mission['public_id'],'revision'=>$mission['current_revision'],'state'=>$stateHash,'type'=>$type,'title'=>$title,'statement'=>$statement],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    $idempotency=hash('sha256',$idempotencyRaw);
    $find=$pdo->prepare("SELECT rd.public_id FROM research_decision_handoffs rdh JOIN research_decisions rd ON rd.id=rdh.decision_id WHERE rdh.mission_id=? AND rdh.idempotency_key=? LIMIT 1");$find->execute([(int)$mission['id'],$idempotency]);$existing=(string)($find->fetchColumn()?:'');if($existing!=='')return research_decision_detail($pdo,$viewer,$existing)??['public_id'=>$existing];

    $refs=research_decision_mission_refs($mission);foreach(array_slice((array)($input['refs']??[]),0,40) as $ref)if(is_array($ref))$refs[]=$ref;
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT current_revision,status FROM research_missions WHERE id=? FOR UPDATE');$lock->execute([(int)$mission['id']]);$locked=$lock->fetch();
        if(!$locked||(int)$locked['current_revision']!==(int)$mission['current_revision']||(string)$locked['status']!==(string)$mission['status'])throw new RuntimeException('Mission changed during handoff. Reload it and try again.');
        $find->execute([(int)$mission['id'],$idempotency]);$existing=(string)($find->fetchColumn()?:'');
        if($existing!==''){if($owns)$pdo->commit();return research_decision_detail($pdo,$viewer,$existing)??['public_id'=>$existing];}
        $decision=research_decision_create($pdo,$viewer,[
          'agent_id'=>(string)$mission['agent_public_id'],'mission_id'=>(string)$mission['public_id'],'decision_type'=>$type,'title'=>$title,'statement'=>$statement,
          'rationale'=>(string)($input['rationale']??''),'confidence'=>$input['confidence']??'','assumptions'=>$input['assumptions']??[],
          'uncertainty'=>$input['uncertainty']??[],'alternatives'=>$input['alternatives']??[],'accountable_user_id'=>$input['accountable_user_id']??$input['accountable_user_public_id']??($viewer['public_id']??''),'refs'=>$refs
        ],$byAgent);
        $decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'proposed',$byAgent);
        $handoffPublic=ulid_like();$pdo->prepare("INSERT INTO research_decision_handoffs(public_id,mission_id,decision_id,mission_revision,mission_status,mission_state_hash,snapshot_json,requested_by_user_id,created_by_agent,idempotency_key) VALUES(?,?,?,?,?,?,?,?,?,?)")
          ->execute([$handoffPublic,(int)$mission['id'],(int)$decision['id'],(int)$mission['current_revision'],(string)$mission['status'],$stateHash,$snapshotJson,(int)$viewer['id'],$byAgent?1:0,$idempotency]);
        $fresh=research_decision_by_id($pdo,(int)$decision['id']);research_decision_event($pdo,$fresh,'decision_created_from_mission',$byAgent?'agent':'user',(int)$viewer['id'],['handoff_id'=>$handoffPublic,'mission_id'=>(string)$mission['public_id'],'mission_revision'=>(int)$mission['current_revision'],'mission_state_hash'=>$stateHash]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_decision_detail($pdo,$viewer,(string)$decision['public_id'])??$decision;
}

function research_decision_challenges_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_decision_challenges')&&installer_table_exists($pdo,'research_decision_challenge_refs');}
    catch(Throwable $e){return false;}
}
function research_decision_challenge_types(): array {
    return ['contradiction'=>'Contradiction','assumption'=>'Assumption','uncertainty'=>'Uncertainty','alternative'=>'Alternative','reversal_condition'=>'What would change this decision','question'=>'Open question'];
}
function research_decision_challenge_statuses(): array {return ['open'=>'Open','resolved'=>'Resolved','accepted'=>'Accepted concern','dismissed'=>'Dismissed'];}
function research_decision_challenge_severities(): array {return ['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'];}
function research_decision_challenge_ref_roles(): array {return ['supports_challenge'=>'Supports challenge','counters_challenge'=>'Counters challenge','context'=>'Context','source'=>'Source'];}

function research_decision_challenge_ref_rows(PDO $pdo,int $challengeId): array {
    if(!research_decision_challenges_ready($pdo))return [];
    $q=$pdo->prepare("SELECT public_id,ref_type,ref_public_id,ref_role,strength,note,added_by_user_id,added_by_agent,created_at,updated_at FROM research_decision_challenge_refs WHERE challenge_id=? ORDER BY FIELD(ref_role,'supports_challenge','counters_challenge','source','context'),id");
    $q->execute([$challengeId]);return $q->fetchAll()?:[];
}
function research_decision_challenges(PDO $pdo,int $decisionId): array {
    if(!research_decision_challenges_ready($pdo))return [];
    $q=$pdo->prepare("SELECT * FROM research_decision_challenges WHERE decision_id=? ORDER BY FIELD(status,'open','accepted','resolved','dismissed'),FIELD(severity,'critical','high','medium','low'),updated_at DESC,id DESC");
    $q->execute([$decisionId]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row['refs']=research_decision_challenge_ref_rows($pdo,(int)$row['id']);unset($row);
    return $rows;
}
function research_decision_challenge_config_rows(PDO $pdo,int $decisionId): array {
    if(!research_decision_challenges_ready($pdo))return [];
    $rows=[];foreach(research_decision_challenges($pdo,$decisionId) as $row){
        $refs=[];foreach((array)$row['refs'] as $ref)$refs[]=[
          'ref_type'=>(string)$ref['ref_type'],'ref_public_id'=>(string)$ref['ref_public_id'],'ref_role'=>(string)$ref['ref_role'],
          'strength'=>$ref['strength']!==null?(float)$ref['strength']:null,'note'=>(string)($ref['note']??'')
        ];
        usort($refs,fn($a,$b)=>strcmp(implode('|',[$a['ref_role'],$a['ref_type'],$a['ref_public_id']]),implode('|',[$b['ref_role'],$b['ref_type'],$b['ref_public_id']])));
        $rows[]=[
          'public_id'=>(string)$row['public_id'],'challenge_type'=>(string)$row['challenge_type'],'title'=>(string)$row['title'],'detail'=>(string)($row['detail']??''),
          'severity'=>(string)$row['severity'],'status'=>(string)$row['status'],'resolution'=>(string)($row['resolution']??''),'refs'=>$refs
        ];
    }
    usort($rows,fn($a,$b)=>strcmp((string)$a['public_id'],(string)$b['public_id']));return $rows;
}
function research_decision_challenge_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_decision_challenges_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rdc.*,rd.public_id decision_public_id,rd.project_id FROM research_decision_challenges rdc JOIN research_decisions rd ON rd.id=rdc.decision_id WHERE rdc.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    if(!research_decision_access($pdo,$viewer,(string)$row['decision_public_id']))return null;
    $row['refs']=research_decision_challenge_ref_rows($pdo,(int)$row['id']);return $row;
}
function research_decision_challenge_normalize(array $input,array $current=[]): array {
    $type=(string)($input['challenge_type']??$input['type']??($current['challenge_type']??'question'));if(!isset(research_decision_challenge_types()[$type]))throw new InvalidArgumentException('Invalid challenge type.');
    $title=research_decision_text((string)($input['title']??($current['title']??'')),255);if($title==='')throw new InvalidArgumentException('Challenge title is required.');
    $detail=array_key_exists('detail',$input)?research_decision_text((string)$input['detail'],64000):(string)($current['detail']??'');
    $severity=(string)($input['severity']??($current['severity']??'medium'));if(!isset(research_decision_challenge_severities()[$severity]))throw new InvalidArgumentException('Invalid challenge severity.');
    return ['challenge_type'=>$type,'title'=>$title,'detail'=>$detail,'severity'=>$severity];
}
function research_decision_challenge_store_ref(PDO $pdo,array $viewer,array $challenge,array $raw,bool $byAgent=false,bool $refresh=true): array {
    $type=strtolower(trim((string)($raw['type']??$raw['ref_type']??'')));$id=trim((string)($raw['id']??$raw['public_id']??$raw['ref_public_id']??''));
    if($type==='research_project'||$type==='research')$type='project';if($type==='research_mission')$type='mission';if($type==='research_review')$type='review';
    $role=(string)($raw['role']??$raw['ref_role']??'context');if(!isset(research_decision_challenge_ref_roles()[$role]))$role='context';
    $strength=array_key_exists('strength',$raw)&&$raw['strength']!==''?(float)$raw['strength']:null;if($strength!==null&&($strength<0||$strength>1))throw new InvalidArgumentException('Challenge reference strength must be between 0 and 1.');
    $note=research_decision_text((string)($raw['note']??''),2000);
    if($type===''||$id==='')throw new InvalidArgumentException('Challenge reference type and ID are required.');
    if(!research_decision_ref_access($pdo,$viewer,(int)$challenge['project_id'],$type,$id))throw new InvalidArgumentException('Challenge reference is unavailable in this Research project.');
    $pdo->prepare("INSERT INTO research_decision_challenge_refs(public_id,challenge_id,ref_type,ref_public_id,ref_role,strength,note,added_by_user_id,added_by_agent)
      VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE strength=VALUES(strength),note=VALUES(note),added_by_user_id=VALUES(added_by_user_id),added_by_agent=VALUES(added_by_agent),updated_at=NOW()")
      ->execute([ulid_like(),(int)$challenge['id'],$type,$id,$role,$strength,$note!==''?$note:null,(int)$viewer['id'],$byAgent?1:0]);
    if($refresh){$fresh=research_decision_refresh_revision($pdo,(int)$challenge['decision_id'],(int)$viewer['id'],'Decision challenge evidence updated',$byAgent);research_decision_event($pdo,$fresh,'decision_challenge_reference_added',$byAgent?'agent':'user',(int)$viewer['id'],['challenge_id'=>$challenge['public_id'],'ref_type'=>$type,'ref_public_id'=>$id,'ref_role'=>$role]);}
    $q=$pdo->prepare("SELECT public_id,ref_type,ref_public_id,ref_role,strength,note,added_by_user_id,added_by_agent,created_at,updated_at FROM research_decision_challenge_refs WHERE challenge_id=? AND ref_type=? AND ref_public_id=? AND ref_role=? LIMIT 1");$q->execute([(int)$challenge['id'],$type,$id,$role]);return $q->fetch()?:[];
}
function research_decision_add_challenge(PDO $pdo,array $viewer,string $decisionPublic,array $input,bool $byAgent=false): array {
    $decision=research_decision_access($pdo,$viewer,$decisionPublic);if(!$decision)throw new RuntimeException('Decision not found.');if(!research_decision_challenges_ready($pdo))throw new RuntimeException('Decision Challenge Graph requires the latest database upgrade.');
    $x=research_decision_challenge_normalize($input);$public=ulid_like();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
      $pdo->prepare("INSERT INTO research_decision_challenges(public_id,decision_id,challenge_type,title,detail,severity,status,created_by_user_id,created_by_agent) VALUES(?,?,?,?,?,?,'open',?,?)")
        ->execute([$public,(int)$decision['id'],$x['challenge_type'],$x['title'],$x['detail']!==''?$x['detail']:null,$x['severity'],(int)$viewer['id'],$byAgent?1:0]);
      $challenge=research_decision_challenge_access($pdo,$viewer,$public);if(!$challenge)throw new RuntimeException('Decision challenge could not be loaded.');
      foreach(array_slice((array)($input['refs']??[]),0,60) as $ref)if(is_array($ref))research_decision_challenge_store_ref($pdo,$viewer,$challenge,$ref,$byAgent,false);
      $fresh=research_decision_refresh_revision($pdo,(int)$decision['id'],(int)$viewer['id'],'Decision challenge added',$byAgent);
      research_decision_event($pdo,$fresh,'decision_challenge_added',$byAgent?'agent':'user',(int)$viewer['id'],['challenge_id'=>$public,'challenge_type'=>$x['challenge_type'],'severity'=>$x['severity']]);
      if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_decision_challenge_access($pdo,$viewer,$public)??['public_id'=>$public];
}
function research_decision_update_challenge(PDO $pdo,array $viewer,string $challengePublic,array $input,bool $byAgent=false): array {
    $challenge=research_decision_challenge_access($pdo,$viewer,$challengePublic);if(!$challenge)throw new RuntimeException('Decision challenge not found.');$x=research_decision_challenge_normalize($input,$challenge);
    $pdo->prepare("UPDATE research_decision_challenges SET challenge_type=?,title=?,detail=?,severity=?,updated_at=NOW() WHERE id=?")
      ->execute([$x['challenge_type'],$x['title'],$x['detail']!==''?$x['detail']:null,$x['severity'],(int)$challenge['id']]);
    $fresh=research_decision_refresh_revision($pdo,(int)$challenge['decision_id'],(int)$viewer['id'],'Decision challenge updated',$byAgent);
    research_decision_event($pdo,$fresh,'decision_challenge_updated',$byAgent?'agent':'user',(int)$viewer['id'],['challenge_id'=>$challengePublic,'challenge_type'=>$x['challenge_type'],'severity'=>$x['severity']]);
    return research_decision_challenge_access($pdo,$viewer,$challengePublic)??$challenge;
}
function research_decision_set_challenge_status(PDO $pdo,array $viewer,string $challengePublic,string $status,string $resolution='',bool $byAgent=false): array {
    $challenge=research_decision_challenge_access($pdo,$viewer,$challengePublic);if(!$challenge)throw new RuntimeException('Decision challenge not found.');if(!isset(research_decision_challenge_statuses()[$status]))throw new InvalidArgumentException('Invalid challenge status.');
    $current=(string)$challenge['status'];if($current===$status)return $challenge;$allowed=['open'=>['resolved','accepted','dismissed'],'resolved'=>['open'],'accepted'=>['open','resolved','dismissed'],'dismissed'=>['open']];if(!in_array($status,$allowed[$current]??[],true))throw new InvalidArgumentException('That challenge status transition is not allowed.');
    $resolution=research_decision_text($resolution,64000);if($status!=='open'&&$resolution==='')throw new InvalidArgumentException('A resolution note is required when closing or accepting a challenge.');
    if($status==='open')$pdo->prepare("UPDATE research_decision_challenges SET status='open',resolution=NULL,resolved_by_user_id=NULL,resolved_at=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$challenge['id']]);
    else $pdo->prepare("UPDATE research_decision_challenges SET status=?,resolution=?,resolved_by_user_id=?,resolved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$status,$resolution,(int)$viewer['id'],(int)$challenge['id']]);
    $fresh=research_decision_refresh_revision($pdo,(int)$challenge['decision_id'],(int)$viewer['id'],'Decision challenge status changed',$byAgent);
    research_decision_event($pdo,$fresh,'decision_challenge_status_changed',$byAgent?'agent':'user',(int)$viewer['id'],['challenge_id'=>$challengePublic,'from'=>$current,'to'=>$status,'resolution'=>$resolution]);
    return research_decision_challenge_access($pdo,$viewer,$challengePublic)??$challenge;
}
function research_decision_add_challenge_ref(PDO $pdo,array $viewer,string $challengePublic,array $input,bool $byAgent=false): array {
    $challenge=research_decision_challenge_access($pdo,$viewer,$challengePublic);if(!$challenge)throw new RuntimeException('Decision challenge not found.');return research_decision_challenge_store_ref($pdo,$viewer,$challenge,$input,$byAgent,true);
}
function research_decision_remove_challenge_ref(PDO $pdo,array $viewer,string $challengePublic,string $refPublic,bool $byAgent=false): array {
    $challenge=research_decision_challenge_access($pdo,$viewer,$challengePublic);if(!$challenge)throw new RuntimeException('Decision challenge not found.');
    $q=$pdo->prepare("SELECT * FROM research_decision_challenge_refs WHERE challenge_id=? AND public_id=? LIMIT 1");$q->execute([(int)$challenge['id'],trim($refPublic)]);$ref=$q->fetch();if(!$ref)throw new RuntimeException('Challenge reference not found.');
    $pdo->prepare('DELETE FROM research_decision_challenge_refs WHERE id=?')->execute([(int)$ref['id']]);
    $fresh=research_decision_refresh_revision($pdo,(int)$challenge['decision_id'],(int)$viewer['id'],'Decision challenge evidence updated',$byAgent);
    research_decision_event($pdo,$fresh,'decision_challenge_reference_removed',$byAgent?'agent':'user',(int)$viewer['id'],['challenge_id'=>$challengePublic,'ref_type'=>$ref['ref_type'],'ref_public_id'=>$ref['ref_public_id'],'ref_role'=>$ref['ref_role']]);
    return research_decision_challenge_access($pdo,$viewer,$challengePublic)??$challenge;
}

function research_decision_evidence_graph(PDO $pdo,array $viewer,string $decisionPublic): array {
    $decision=research_decision_detail($pdo,$viewer,$decisionPublic);if(!$decision)throw new RuntimeException('Decision not found.');
    $nodes=[];$edges=[];$root='decision:'.$decision['public_id'];$nodes[$root]=['id'=>$root,'kind'=>'decision','public_id'=>$decision['public_id'],'label'=>$decision['title'],'status'=>$decision['status']];
    $addRefNode=function(array $ref)use(&$nodes): string{$id='ref:'.(string)$ref['ref_type'].':'.(string)$ref['ref_public_id'];if(!isset($nodes[$id]))$nodes[$id]=['id'=>$id,'kind'=>'reference','ref_type'=>$ref['ref_type'],'public_id'=>$ref['ref_public_id']];return $id;};
    foreach((array)$decision['refs'] as $ref){$nid=$addRefNode($ref);$edges[]=['from'=>$nid,'to'=>$root,'relationship'=>(string)$ref['ref_role'],'strength'=>$ref['strength']!==null?(float)$ref['strength']:null,'note'=>(string)($ref['note']??'')];}
    foreach((array)$decision['assumptions'] as $i=>$value){$id='assumption:'.$i;$nodes[$id]=['id'=>$id,'kind'=>'assumption','label'=>is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)];$edges[]=['from'=>$id,'to'=>$root,'relationship'=>'assumption'];}
    foreach((array)$decision['uncertainty'] as $i=>$value){$id='uncertainty:'.$i;$nodes[$id]=['id'=>$id,'kind'=>'uncertainty','label'=>is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)];$edges[]=['from'=>$id,'to'=>$root,'relationship'=>'uncertainty'];}
    foreach((array)$decision['alternatives'] as $i=>$value){$id='alternative:'.$i;$nodes[$id]=['id'=>$id,'kind'=>'alternative','label'=>is_scalar($value)?(string)$value:(string)($value['title']??json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))];$edges[]=['from'=>$id,'to'=>$root,'relationship'=>'alternative'];}
    $counts=['supports'=>0,'contradicts'=>0,'open_challenges'=>0,'high_open_challenges'=>0,'reversal_conditions'=>0];
    foreach((array)$decision['refs'] as $ref){if(($ref['ref_role']??'')==='supports')$counts['supports']++;if(($ref['ref_role']??'')==='contradicts')$counts['contradicts']++;}
    foreach((array)$decision['challenges'] as $challenge){
        $cid='challenge:'.$challenge['public_id'];$nodes[$cid]=['id'=>$cid,'kind'=>'challenge','public_id'=>$challenge['public_id'],'challenge_type'=>$challenge['challenge_type'],'label'=>$challenge['title'],'severity'=>$challenge['severity'],'status'=>$challenge['status'],'detail'=>$challenge['detail'],'resolution'=>$challenge['resolution']];
        $edges[]=['from'=>$cid,'to'=>$root,'relationship'=>(string)$challenge['challenge_type']];
        if((string)$challenge['status']==='open'){$counts['open_challenges']++;if(in_array((string)$challenge['severity'],['high','critical'],true))$counts['high_open_challenges']++;}
        if((string)$challenge['challenge_type']==='reversal_condition')$counts['reversal_conditions']++;
        foreach((array)$challenge['refs'] as $ref){$nid=$addRefNode($ref);$edges[]=['from'=>$nid,'to'=>$cid,'relationship'=>(string)$ref['ref_role'],'strength'=>$ref['strength']!==null?(float)$ref['strength']:null,'note'=>(string)($ref['note']??'')];}
    }
    return ['decision_id'=>(string)$decision['public_id'],'revision'=>(int)$decision['current_revision'],'counts'=>$counts,'nodes'=>array_values($nodes),'edges'=>$edges];
}

function research_decision_outcomes_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_decision_outcomes')&&installer_table_exists($pdo,'research_decision_outcome_versions')&&research_outcomes_ready($pdo);}
    catch(Throwable $e){return false;}
}
function research_decision_outcome_assessments(): array {return ['unresolved'=>'Unresolved','success'=>'Success','partial'=>'Partial success','failure'=>'Failure','mixed'=>'Mixed'];}
function research_decision_outcome_follow_up_states(): array {return ['none'=>'None','follow_up'=>'Follow up','resolved'=>'Resolved','reopened'=>'Reopened'];}
function research_decision_outcome_phase20_type(string $assessment): string {
    return match($assessment){'success'=>'completed','failure'=>'failed',default=>'recorded'};
}
function research_decision_outcome_versions(PDO $pdo,int $decisionOutcomeId,int $limit=50): array {
    if(!research_decision_outcomes_ready($pdo))return [];
    $q=$pdo->prepare("SELECT public_id,revision_number,snapshot_json,change_reason,edited_by_user_id,edited_by_agent,created_at FROM research_decision_outcome_versions WHERE decision_outcome_id=? ORDER BY revision_number DESC,id DESC LIMIT ".max(1,min(100,$limit)));
    $q->execute([$decisionOutcomeId]);$rows=$q->fetchAll()?:[];foreach($rows as &$r)$r['snapshot']=research_decision_json($r['snapshot_json']??null);unset($r);return $rows;
}
function research_decision_outcome_event_refs(PDO $pdo,int $outcomeEventId): array {
    $q=$pdo->prepare("SELECT ref_type,ref_public_id,ref_role,created_at FROM research_outcome_refs WHERE outcome_id=? ORDER BY ref_role,ref_type,ref_public_id");
    $q->execute([$outcomeEventId]);return $q->fetchAll()?:[];
}
function research_decision_outcome_row(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare("SELECT rdo.*,roe.public_id outcome_event_public_id,roe.user_id outcome_event_user_id,roe.source_type outcome_event_source_type,roe.source_public_id outcome_event_source_public_id,roe.title outcome_event_title,roe.summary outcome_event_summary,roe.note outcome_event_note,roe.metadata_json outcome_event_metadata_json,roe.occurred_at outcome_event_occurred_at,rd.public_id decision_public_id
      FROM research_decision_outcomes rdo JOIN research_outcome_events roe ON roe.id=rdo.outcome_event_id JOIN research_decisions rd ON rd.id=rdo.decision_id WHERE rdo.id=? LIMIT 1");
    $q->execute([$id]);$row=$q->fetch();if(!$row)return null;
    $row['outcome_event_metadata']=research_decision_json($row['outcome_event_metadata_json']??null);$row['refs']=research_decision_outcome_event_refs($pdo,(int)$row['outcome_event_id']);$row['versions']=research_decision_outcome_versions($pdo,(int)$row['id'],50);return $row;
}
function research_decision_outcomes(PDO $pdo,array $viewer,int $decisionId,int $limit=100): array {
    if(!research_decision_outcomes_ready($pdo))return [];
    $decision=research_decision_by_id($pdo,$decisionId);if(!$decision||!research_decision_access($pdo,$viewer,(string)$decision['public_id']))return [];
    $q=$pdo->prepare("SELECT id FROM research_decision_outcomes WHERE decision_id=? ORDER BY observed_at DESC,id DESC LIMIT ".max(1,min(200,$limit)));$q->execute([$decisionId]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_decision_outcome_row($pdo,(int)$id);if($row)$out[]=$row;}return $out;
}
function research_decision_outcome_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_decision_outcomes_ready($pdo))return null;$q=$pdo->prepare("SELECT id,decision_id FROM research_decision_outcomes WHERE public_id=? LIMIT 1");$q->execute([trim($publicId)]);$base=$q->fetch();if(!$base)return null;
    $decision=research_decision_by_id($pdo,(int)$base['decision_id']);if(!$decision||!research_decision_access($pdo,$viewer,(string)$decision['public_id']))return null;return research_decision_outcome_row($pdo,(int)$base['id']);
}
function research_decision_outcome_snapshot_payload(array $row): array {
    return [
      'assessment'=>(string)$row['assessment'],'expected_summary'=>(string)($row['expected_summary']??''),'actual_summary'=>(string)$row['actual_summary'],
      'variance_summary'=>(string)($row['variance_summary']??''),'lessons'=>(string)($row['lessons']??''),
      'confidence'=>$row['confidence']!==null?(float)$row['confidence']:null,'follow_up_state'=>(string)$row['follow_up_state'],
      'observed_at'=>(string)$row['observed_at'],'outcome_event_public_id'=>(string)$row['outcome_event_public_id'],
      'refs'=>array_map(fn($r)=>['ref_type'=>(string)$r['ref_type'],'ref_public_id'=>(string)$r['ref_public_id'],'ref_role'=>(string)$r['ref_role']],(array)($row['refs']??[]))
    ];
}
function research_decision_outcome_write_version(PDO $pdo,array $row,string $reason,?int $userId,bool $byAgent=false): void {
    $q=$pdo->prepare("SELECT COALESCE(MAX(revision_number),0)+1 FROM research_decision_outcome_versions WHERE decision_outcome_id=?");$q->execute([(int)$row['id']]);$revision=(int)$q->fetchColumn();
    $pdo->prepare("INSERT INTO research_decision_outcome_versions(public_id,decision_outcome_id,revision_number,snapshot_json,change_reason,edited_by_user_id,edited_by_agent) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),(int)$row['id'],$revision,json_encode(research_decision_outcome_snapshot_payload($row),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),research_decision_text($reason,1000)?:null,$userId,$byAgent?1:0]);
}
function research_decision_outcome_normalize(array $input,array $current=[]): array {
    $assessment=(string)($input['assessment']??($current['assessment']??'unresolved'));if(!isset(research_decision_outcome_assessments()[$assessment]))throw new InvalidArgumentException('Invalid Decision outcome assessment.');
    $expected=array_key_exists('expected_summary',$input)?research_decision_text((string)$input['expected_summary'],64000):(string)($current['expected_summary']??'');
    $actual=array_key_exists('actual_summary',$input)?research_decision_text((string)$input['actual_summary'],64000):(string)($current['actual_summary']??'');if($actual==='')throw new InvalidArgumentException('Actual outcome summary is required.');
    $variance=array_key_exists('variance_summary',$input)?research_decision_text((string)$input['variance_summary'],64000):(string)($current['variance_summary']??'');
    $lessons=array_key_exists('lessons',$input)?research_decision_text((string)$input['lessons'],64000):(string)($current['lessons']??'');
    $confidence=array_key_exists('confidence',$input)?($input['confidence']===''?null:(float)$input['confidence']):($current&&$current['confidence']!==null?(float)$current['confidence']:null);if($confidence!==null&&($confidence<0||$confidence>1))throw new InvalidArgumentException('Outcome confidence must be between 0 and 1.');
    $follow=(string)($input['follow_up_state']??($current['follow_up_state']??'none'));if(!isset(research_decision_outcome_follow_up_states()[$follow]))throw new InvalidArgumentException('Invalid Decision outcome follow-up state.');
    $observed=trim((string)($input['observed_at']??($current['observed_at']??'')));if($observed===''||strtotime($observed)===false)$observed=date('Y-m-d H:i:s');
    return ['assessment'=>$assessment,'expected_summary'=>$expected,'actual_summary'=>$actual,'variance_summary'=>$variance,'lessons'=>$lessons,'confidence'=>$confidence,'follow_up_state'=>$follow,'observed_at'=>date('Y-m-d H:i:s',strtotime($observed))];
}
function research_decision_outcome_refs(PDO $pdo,array $viewer,array $decision,array $input): array {
    $refs=research_outcome_normalize_refs((array)($input['refs']??[]));
    $refs[]=['type'=>'decision','public_id'=>(string)$decision['public_id'],'role'=>'source'];$refs[]=['type'=>'project','public_id'=>(string)$decision['project_public_id'],'role'=>'context'];
    $refs=research_outcome_normalize_refs($refs);foreach($refs as $ref)if(!research_outcome_ref_access($pdo,$viewer,(string)$ref['type'],(string)$ref['public_id']))throw new InvalidArgumentException('Outcome evidence is unavailable in this Research context.');return $refs;
}
function research_decision_record_outcome(PDO $pdo,array $viewer,string $decisionPublic,array $input,bool $byAgent=false): array {
    if(!research_decision_outcomes_ready($pdo))throw new RuntimeException('Decision Outcome Memory requires the latest database upgrade.');
    $decision=research_decision_access($pdo,$viewer,$decisionPublic);if(!$decision)throw new RuntimeException('Decision not found.');
    if(!in_array((string)$decision['status'],['accepted','rejected','deferred','superseded'],true))throw new InvalidArgumentException('Record an explicit Decision disposition before recording its outcome.');
    $existingEventPublic=trim((string)($input['outcome_event_id']??$input['outcome_event_public_id']??''));$event=null;$normalizeInput=$input;
    if($existingEventPublic!==''){
      $eq=$pdo->prepare("SELECT * FROM research_outcome_events WHERE public_id=? AND user_id=? LIMIT 1");$eq->execute([$existingEventPublic,(int)$viewer['id']]);$event=$eq->fetch();
      if(!$event)throw new InvalidArgumentException('Outcome event is unavailable or is not owned by the current user.');
      if((int)($event['project_id']??0)!==(int)$decision['project_id'])throw new InvalidArgumentException('Outcome event must belong to the same Research project as the Decision.');
      $linked=$pdo->prepare("SELECT rdo.id,rd.public_id decision_public_id FROM research_decision_outcomes rdo JOIN research_decisions rd ON rd.id=rdo.decision_id WHERE rdo.outcome_event_id=? LIMIT 1");$linked->execute([(int)$event['id']]);$existingLink=$linked->fetch();
      if($existingLink){if((string)$existingLink['decision_public_id']===(string)$decision['public_id'])return research_decision_outcome_row($pdo,(int)$existingLink['id'])??[];throw new InvalidArgumentException('Outcome event is already linked to another Decision.');}
      if(trim((string)($normalizeInput['actual_summary']??''))==='')$normalizeInput['actual_summary']=(string)($event['summary']??'');
      if(trim((string)($normalizeInput['observed_at']??''))==='')$normalizeInput['observed_at']=(string)($event['occurred_at']??'');
    }
    $x=research_decision_outcome_normalize($normalizeInput);$refs=research_decision_outcome_refs($pdo,$viewer,$decision,$input);
    $public=ulid_like();$idempotencyRaw=trim((string)($input['idempotency_key']??''));if($idempotencyRaw==='')$idempotencyRaw=json_encode([(string)$decision['public_id'],$x['assessment'],$x['actual_summary'],$x['observed_at'],$existingEventPublic],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $idempotency=hash('sha256',$idempotencyRaw);$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
      $lock=$pdo->prepare("SELECT id FROM research_decisions WHERE id=? FOR UPDATE");$lock->execute([(int)$decision['id']]);
      $q=$pdo->prepare("SELECT id FROM research_decision_outcomes WHERE decision_id=? AND idempotency_key=? LIMIT 1");$q->execute([(int)$decision['id'],$idempotency]);$existing=(int)($q->fetchColumn()?:0);
      if($existing>0){if($owns)$pdo->commit();return research_decision_outcome_row($pdo,$existing)??[];}
      if(!$event)$event=research_outcome_record($pdo,$viewer,[
        'event_type'=>'decision_outcome','decision_type'=>research_decision_outcome_phase20_type($x['assessment']),'source_type'=>'research_decision','source_public_id'=>(string)$decision['public_id'],
        'project_public_id'=>(string)$decision['project_public_id'],'object_type'=>'decision','object_public_id'=>(string)$decision['public_id'],
        'title'=>'Decision outcome · '.(string)$decision['title'],'summary'=>$x['actual_summary'],'note'=>$x['lessons'],'refs'=>$refs,'is_manual'=>!$byAgent,
        'occurred_at'=>$x['observed_at'],'dedupe_key'=>'decision_outcome:'.(string)$decision['public_id'].':'.$idempotency,
        'metadata'=>['assessment'=>$x['assessment'],'expected_summary'=>$x['expected_summary'],'variance_summary'=>$x['variance_summary'],'confidence'=>$x['confidence'],'follow_up_state'=>$x['follow_up_state'],'decision_revision'=>(int)$decision['current_revision'],'decision_status'=>(string)$decision['status']]
      ]);
      if(!$event)throw new RuntimeException('Outcome Learning event could not be recorded.');
      if($existingEventPublic!==''){
        $ins=$pdo->prepare("INSERT IGNORE INTO research_outcome_refs(outcome_id,ref_type,ref_public_id,ref_role) VALUES(?,?,?,?)");
        foreach($refs as $ref)$ins->execute([(int)$event['id'],$ref['type'],$ref['public_id'],$ref['role']]);
      }
      $pdo->prepare("INSERT INTO research_decision_outcomes(public_id,decision_id,outcome_event_id,assessment,expected_summary,actual_summary,variance_summary,lessons,confidence,follow_up_state,recorded_by_user_id,created_by_agent,idempotency_key,observed_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$public,(int)$decision['id'],(int)$event['id'],$x['assessment'],$x['expected_summary']!==''?$x['expected_summary']:null,$x['actual_summary'],$x['variance_summary']!==''?$x['variance_summary']:null,$x['lessons']!==''?$x['lessons']:null,$x['confidence'],$x['follow_up_state'],(int)$viewer['id'],$byAgent?1:0,$idempotency,$x['observed_at']]);
      $row=research_decision_outcome_row($pdo,(int)$pdo->lastInsertId());if(!$row)throw new RuntimeException('Decision Outcome Memory could not be loaded.');
      research_decision_outcome_write_version($pdo,$row,'Initial observed outcome',(int)$viewer['id'],$byAgent);
      research_decision_event($pdo,$decision,'decision_outcome_recorded',$byAgent?'agent':'user',(int)$viewer['id'],['decision_outcome_id'=>$public,'outcome_event_id'=>$event['public_id'],'assessment'=>$x['assessment'],'follow_up_state'=>$x['follow_up_state']]);
      if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_decision_outcome_access($pdo,$viewer,$public)??$row;
}
function research_decision_update_outcome(PDO $pdo,array $viewer,string $outcomePublic,array $input,bool $byAgent=false): array {
    $row=research_decision_outcome_access($pdo,$viewer,$outcomePublic);if(!$row)throw new RuntimeException('Decision outcome not found.');$decision=research_decision_access($pdo,$viewer,(string)$row['decision_public_id']);if(!$decision)throw new RuntimeException('Decision not found.');
    $x=research_decision_outcome_normalize($input,$row);$refs=array_key_exists('refs',$input)?research_decision_outcome_refs($pdo,$viewer,$decision,$input):(array)$row['refs'];
    $pdo->prepare("UPDATE research_decision_outcomes SET assessment=?,expected_summary=?,actual_summary=?,variance_summary=?,lessons=?,confidence=?,follow_up_state=?,observed_at=?,updated_at=NOW() WHERE id=?")
      ->execute([$x['assessment'],$x['expected_summary']!==''?$x['expected_summary']:null,$x['actual_summary'],$x['variance_summary']!==''?$x['variance_summary']:null,$x['lessons']!==''?$x['lessons']:null,$x['confidence'],$x['follow_up_state'],$x['observed_at'],(int)$row['id']]);
    $metadata=['assessment'=>$x['assessment'],'expected_summary'=>$x['expected_summary'],'variance_summary'=>$x['variance_summary'],'confidence'=>$x['confidence'],'follow_up_state'=>$x['follow_up_state'],'decision_revision'=>(int)$decision['current_revision'],'decision_status'=>(string)$decision['status']];
    $ownedDecisionEvent=(string)($row['outcome_event_source_type']??'')==='research_decision'&&(string)($row['outcome_event_source_public_id']??'')===(string)$decision['public_id'];
    if($ownedDecisionEvent){
      $pdo->prepare("UPDATE research_outcome_events SET decision_type=?,title=?,summary=?,note=?,metadata_json=?,occurred_at=?,updated_at=NOW() WHERE id=?")
        ->execute([research_decision_outcome_phase20_type($x['assessment']),'Decision outcome · '.(string)$decision['title'],mb_substr($x['actual_summary'],0,1200),$x['lessons']!==''?$x['lessons']:null,json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$x['observed_at'],(int)$row['outcome_event_id']]);
      if(array_key_exists('refs',$input)){$pdo->prepare("DELETE FROM research_outcome_refs WHERE outcome_id=?")->execute([(int)$row['outcome_event_id']]);$ins=$pdo->prepare("INSERT IGNORE INTO research_outcome_refs(outcome_id,ref_type,ref_public_id,ref_role) VALUES(?,?,?,?)");foreach($refs as $ref)$ins->execute([(int)$row['outcome_event_id'],$ref['type']??$ref['ref_type'],$ref['public_id']??$ref['ref_public_id'],$ref['role']??$ref['ref_role']]);}
    }
    $fresh=research_decision_outcome_row($pdo,(int)$row['id']);if(!$fresh)throw new RuntimeException('Updated Decision outcome could not be loaded.');
    research_decision_outcome_write_version($pdo,$fresh,research_decision_text((string)($input['reason']??'Decision outcome updated'),1000),(int)$viewer['id'],$byAgent);
    research_decision_event($pdo,$decision,'decision_outcome_updated',$byAgent?'agent':'user',(int)$viewer['id'],['decision_outcome_id'=>$outcomePublic,'assessment'=>$x['assessment'],'follow_up_state'=>$x['follow_up_state']]);
    return research_decision_outcome_access($pdo,$viewer,$outcomePublic)??$fresh;
}
function research_decision_outcome_summary(PDO $pdo,array $viewer,string $decisionPublic): array {
    $decision=research_decision_access($pdo,$viewer,$decisionPublic);if(!$decision)return ['total'=>0,'assessments'=>[],'follow_up'=>0,'reopened'=>0];$items=research_decision_outcomes($pdo,$viewer,(int)$decision['id'],200);
    $assessments=[];$follow=0;$reopened=0;foreach($items as $r){$assessments[$r['assessment']]=($assessments[$r['assessment']]??0)+1;if($r['follow_up_state']==='follow_up')$follow++;if($r['follow_up_state']==='reopened')$reopened++;}return ['total'=>count($items),'assessments'=>$assessments,'follow_up'=>$follow,'reopened'=>$reopened];
}

function research_decision_reconsiderations_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_decision_reconsiderations')&&installer_table_exists($pdo,'research_decision_reconsideration_events');}
    catch(Throwable $e){return false;}
}
function research_decision_reconsideration_trigger_types(): array {
    return ['manual'=>'Manual review','evidence_change'=>'Evidence change','challenge'=>'Open challenge','outcome'=>'Observed outcome','reversal_condition'=>'Reversal condition','assumption'=>'Assumption changed'];
}
function research_decision_reconsideration_statuses(): array {return ['open'=>'Open','reviewing'=>'Reviewing','resolved'=>'Resolved','dismissed'=>'Dismissed'];}
function research_decision_reconsideration_actions(): array {return ['undetermined'=>'Undetermined','retain'=>'Retain','reopen'=>'Reopen','supersede'=>'Supersede','defer'=>'Defer'];}
function research_decision_reconsideration_materiality(): array {return ['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'];}

function research_decision_reconsideration_event(PDO $pdo,int $caseId,string $type,string $actorType,?int $userId,array $payload=[]): void {
    $pdo->prepare("INSERT INTO research_decision_reconsideration_events(public_id,reconsideration_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?)")
      ->execute([ulid_like(),$caseId,$type,in_array($actorType,['user','agent','system'],true)?$actorType:'system',$userId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION):null]);
}
function research_decision_reconsideration_events(PDO $pdo,int $caseId,int $limit=100): array {
    if(!research_decision_reconsiderations_ready($pdo))return [];
    $q=$pdo->prepare("SELECT public_id,event_type,actor_type,actor_user_id,payload_json,created_at FROM research_decision_reconsideration_events WHERE reconsideration_id=? ORDER BY id DESC LIMIT ".max(1,min(200,$limit)));
    $q->execute([$caseId]);$rows=$q->fetchAll()?:[];foreach($rows as &$r)$r['payload']=research_decision_json($r['payload_json']??null);unset($r);return $rows;
}
function research_decision_reconsideration_row(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare("SELECT rdr.*,rd.public_id decision_public_id,rd.project_id FROM research_decision_reconsiderations rdr JOIN research_decisions rd ON rd.id=rdr.decision_id WHERE rdr.id=? LIMIT 1");
    $q->execute([$id]);$row=$q->fetch();if(!$row)return null;$row['opening_snapshot']=research_decision_json($row['opening_snapshot_json']??null);$row['events']=research_decision_reconsideration_events($pdo,(int)$row['id'],100);return $row;
}
function research_decision_reconsideration_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_decision_reconsiderations_ready($pdo))return null;
    $q=$pdo->prepare("SELECT id,decision_id FROM research_decision_reconsiderations WHERE public_id=? LIMIT 1");$q->execute([trim($publicId)]);$base=$q->fetch();if(!$base)return null;
    $decision=research_decision_by_id($pdo,(int)$base['decision_id']);if(!$decision||!research_decision_access($pdo,$viewer,(string)$decision['public_id']))return null;return research_decision_reconsideration_row($pdo,(int)$base['id']);
}
function research_decision_reconsiderations(PDO $pdo,array $viewer,int $decisionId,int $limit=100): array {
    if(!research_decision_reconsiderations_ready($pdo))return [];$decision=research_decision_by_id($pdo,$decisionId);if(!$decision||!research_decision_access($pdo,$viewer,(string)$decision['public_id']))return [];
    $q=$pdo->prepare("SELECT id FROM research_decision_reconsiderations WHERE decision_id=? ORDER BY FIELD(status,'open','reviewing','resolved','dismissed'),updated_at DESC,id DESC LIMIT ".max(1,min(200,$limit)));$q->execute([$decisionId]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_decision_reconsideration_row($pdo,(int)$id);if($row)$out[]=$row;}return $out;
}
function research_decision_reconsideration_signals(PDO $pdo,array $viewer,string $decisionPublic): array {
    $decision=research_decision_detail($pdo,$viewer,$decisionPublic);if(!$decision)throw new RuntimeException('Decision not found.');
    $signals=[];$add=function(array $signal)use(&$signals): void{$key=implode('|',[(string)$signal['trigger_type'],(string)($signal['trigger_public_id']??''),(string)$signal['title']]);$signals[$key]=$signal;};
    foreach((array)$decision['refs'] as $ref)if((string)($ref['ref_role']??'')==='contradicts')$add([
      'trigger_type'=>'evidence_change','trigger_public_id'=>(string)$ref['public_id'],'materiality'=>(float)($ref['strength']??0)>=0.75?'high':'medium',
      'title'=>'Contradicting evidence is attached to this Decision','reason'=>'A Decision reference explicitly contradicts the current position.','source'=>['type'=>(string)$ref['ref_type'],'public_id'=>(string)$ref['ref_public_id']]
    ]);
    foreach((array)$decision['challenges'] as $challenge){
      if(!in_array((string)$challenge['status'],['open','accepted'],true))continue;$severity=(string)$challenge['severity'];$materiality=in_array($severity,['critical','high','medium','low'],true)?$severity:'medium';
      $trigger=(string)$challenge['challenge_type']==='reversal_condition'?'reversal_condition':((string)$challenge['challenge_type']==='assumption'?'assumption':'challenge');
      $add(['trigger_type'=>$trigger,'trigger_public_id'=>(string)$challenge['public_id'],'materiality'=>$materiality,'title'=>(string)$challenge['title'],'reason'=>(string)($challenge['detail']?:'An unresolved Decision challenge requires review.'),'source'=>['type'=>'decision_challenge','public_id'=>(string)$challenge['public_id']]]);
    }
    foreach((array)$decision['outcomes'] as $outcome){
      $assessment=(string)$outcome['assessment'];$follow=(string)$outcome['follow_up_state'];if($assessment==='success'&&$follow==='none')continue;
      $materiality=$assessment==='failure'?'critical':($assessment==='mixed'||$follow==='reopened'?'high':($assessment==='partial'||$follow==='follow_up'?'medium':'low'));
      $add(['trigger_type'=>'outcome','trigger_public_id'=>(string)$outcome['public_id'],'materiality'=>$materiality,'title'=>'Observed outcome may warrant Decision review','reason'=>(string)$outcome['actual_summary'],'source'=>['type'=>'decision_outcome','public_id'=>(string)$outcome['public_id'],'assessment'=>$assessment,'follow_up_state'=>$follow]]);
    }
    $rank=['critical'=>4,'high'=>3,'medium'=>2,'low'=>1];$signals=array_values($signals);usort($signals,fn($a,$b)=>($rank[$b['materiality']]??0)<=>($rank[$a['materiality']]??0)?:strcmp((string)$a['title'],(string)$b['title']));
    return ['decision_id'=>(string)$decision['public_id'],'decision_status'=>(string)$decision['status'],'decision_revision'=>(int)$decision['current_revision'],'signals'=>$signals,'counts'=>array_count_values(array_map(fn($s)=>(string)$s['materiality'],$signals))];
}
function research_decision_reconsideration_opening_snapshot(PDO $pdo,array $viewer,array $decision): array {
    $graph=research_decision_evidence_graph($pdo,$viewer,(string)$decision['public_id']);$outcomeSummary=research_decision_outcome_summary($pdo,$viewer,(string)$decision['public_id']);$signals=research_decision_reconsideration_signals($pdo,$viewer,(string)$decision['public_id']);
    return ['decision_public_id'=>(string)$decision['public_id'],'decision_revision'=>(int)$decision['current_revision'],'decision_status'=>(string)$decision['status'],'statement'=>(string)$decision['statement'],'rationale'=>(string)$decision['rationale'],'confidence'=>$decision['confidence']!==null?(float)$decision['confidence']:null,'graph_counts'=>$graph['counts'],'outcome_summary'=>$outcomeSummary,'signals'=>$signals['signals']];
}
function research_decision_open_reconsideration(PDO $pdo,array $viewer,string $decisionPublic,array $input,bool $byAgent=false): array {
    if(!research_decision_reconsiderations_ready($pdo))throw new RuntimeException('Decision Reconsideration requires the latest database upgrade.');
    $decision=research_decision_access($pdo,$viewer,$decisionPublic);if(!$decision)throw new RuntimeException('Decision not found.');
    if(!in_array((string)$decision['status'],['accepted','rejected','deferred','superseded'],true))throw new InvalidArgumentException('Only a recorded Decision disposition can enter reconsideration.');
    $trigger=(string)($input['trigger_type']??'manual');if(!isset(research_decision_reconsideration_trigger_types()[$trigger]))throw new InvalidArgumentException('Invalid reconsideration trigger type.');
    $triggerId=research_decision_text((string)($input['trigger_public_id']??''),160);
    $signals=research_decision_reconsideration_signals($pdo,$viewer,(string)$decision['public_id']);
    if($trigger!=='manual'){
      if($triggerId==='')throw new InvalidArgumentException('A reconsideration signal is required for this trigger type.');
      $matched=false;foreach((array)$signals['signals'] as $signal)if((string)$signal['trigger_type']===$trigger&&(string)($signal['trigger_public_id']??'')===$triggerId){$matched=true;break;}
      if(!$matched)throw new InvalidArgumentException('That reconsideration signal is no longer current.');
    }
    $title=research_decision_text((string)($input['title']??'Reconsider '.(string)$decision['title']),255);if($title==='')throw new InvalidArgumentException('Reconsideration title is required.');
    $reason=research_decision_text((string)($input['reason']??''),64000);if($reason==='')throw new InvalidArgumentException('Reconsideration reason is required.');
    $materiality=(string)($input['materiality']??'medium');if(!isset(research_decision_reconsideration_materiality()[$materiality]))throw new InvalidArgumentException('Invalid reconsideration materiality.');
    $snapshot=research_decision_reconsideration_opening_snapshot($pdo,$viewer,$decision);$snapshotJson=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);$hash=hash('sha256',$snapshotJson);
    $dup=$pdo->prepare("SELECT public_id FROM research_decision_reconsiderations WHERE decision_id=? AND trigger_type=? AND COALESCE(trigger_public_id,'')=? AND opening_context_hash=? AND status IN ('open','reviewing') ORDER BY id DESC LIMIT 1");$dup->execute([(int)$decision['id'],$trigger,$triggerId,$hash]);$existing=(string)($dup->fetchColumn()?:'');if($existing!=='')return research_decision_reconsideration_access($pdo,$viewer,$existing)??['public_id'=>$existing];
    $public=ulid_like();
    $pdo->prepare("INSERT INTO research_decision_reconsiderations(public_id,decision_id,trigger_type,trigger_public_id,title,reason,materiality,status,recommended_action,decision_revision_opened,decision_status_opened,opening_context_hash,opening_snapshot_json,opened_by_user_id,created_by_agent)
      VALUES(?,?,?,?,?,?,?,'open','undetermined',?,?,?,?,?,?)")->execute([$public,(int)$decision['id'],$trigger,$triggerId!==''?$triggerId:null,$title,$reason,$materiality,(int)$decision['current_revision'],(string)$decision['status'],$hash,$snapshotJson,(int)$viewer['id'],$byAgent?1:0]);
    $id=(int)$pdo->lastInsertId();research_decision_reconsideration_event($pdo,$id,'reconsideration_opened',$byAgent?'agent':'user',(int)$viewer['id'],['trigger_type'=>$trigger,'trigger_public_id'=>$triggerId,'materiality'=>$materiality,'decision_revision'=>(int)$decision['current_revision'],'decision_status'=>(string)$decision['status'],'opening_context_hash'=>$hash]);
    research_decision_event($pdo,$decision,'decision_reconsideration_opened',$byAgent?'agent':'user',(int)$viewer['id'],['reconsideration_id'=>$public,'trigger_type'=>$trigger,'materiality'=>$materiality,'opening_context_hash'=>$hash]);
    return research_decision_reconsideration_access($pdo,$viewer,$public)??['public_id'=>$public];
}
function research_decision_set_reconsideration_status(PDO $pdo,array $viewer,string $casePublic,string $status,array $input=[],bool $byAgent=false): array {
    $case=research_decision_reconsideration_access($pdo,$viewer,$casePublic);if(!$case)throw new RuntimeException('Decision reconsideration not found.');if(!isset(research_decision_reconsideration_statuses()[$status]))throw new InvalidArgumentException('Invalid reconsideration status.');
    if(!empty($case['applied_at']))throw new InvalidArgumentException('An applied reconsideration is immutable.');
    $current=(string)$case['status'];if($current===$status)return $case;$allowed=['open'=>['reviewing','resolved','dismissed'],'reviewing'=>['open','resolved','dismissed'],'resolved'=>['open'],'dismissed'=>['open']];if(!in_array($status,$allowed[$current]??[],true))throw new InvalidArgumentException('That reconsideration status transition is not allowed.');
    $action=(string)($input['recommended_action']??($case['recommended_action']??'undetermined'));if(!isset(research_decision_reconsideration_actions()[$action]))throw new InvalidArgumentException('Invalid reconsideration recommendation.');
    $resolution=research_decision_text((string)($input['resolution']??''),64000);if(in_array($status,['resolved','dismissed'],true)&&$resolution==='')throw new InvalidArgumentException('A resolution is required to close a reconsideration.');
    if($status==='resolved'&&$action==='undetermined')throw new InvalidArgumentException('A resolved reconsideration requires an explicit recommendation.');
    if($status==='open')$pdo->prepare("UPDATE research_decision_reconsiderations SET status='open',recommended_action='undetermined',resolution=NULL,resolved_by_user_id=NULL,resolved_at=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$case['id']]);
    elseif($status==='reviewing')$pdo->prepare("UPDATE research_decision_reconsiderations SET status='reviewing',recommended_action=?,resolution=NULL,resolved_by_user_id=NULL,resolved_at=NULL,updated_at=NOW() WHERE id=?")->execute([$action,(int)$case['id']]);
    else $pdo->prepare("UPDATE research_decision_reconsiderations SET status=?,recommended_action=?,resolution=?,resolved_by_user_id=?,resolved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$status,$action,$resolution,(int)$viewer['id'],(int)$case['id']]);
    research_decision_reconsideration_event($pdo,(int)$case['id'],'reconsideration_status_changed',$byAgent?'agent':'user',(int)$viewer['id'],['from'=>$current,'to'=>$status,'recommended_action'=>$action,'resolution'=>$resolution]);
    $decision=research_decision_by_id($pdo,(int)$case['decision_id']);if($decision)research_decision_event($pdo,$decision,'decision_reconsideration_status_changed',$byAgent?'agent':'user',(int)$viewer['id'],['reconsideration_id'=>$casePublic,'from'=>$current,'to'=>$status,'recommended_action'=>$action]);
    return research_decision_reconsideration_access($pdo,$viewer,$casePublic)??$case;
}
function research_decision_apply_reconsideration(PDO $pdo,array $viewer,string $casePublic,bool $byAgent=false): array {
    $case=research_decision_reconsideration_access($pdo,$viewer,$casePublic);if(!$case)throw new RuntimeException('Decision reconsideration not found.');if((string)$case['status']!=='resolved')throw new InvalidArgumentException('Resolve the reconsideration before applying it.');
    $decision=research_decision_detail($pdo,$viewer,(string)$case['decision_public_id']);if(!$decision)throw new RuntimeException('Decision not found.');
    if(!empty($case['applied_at']))return ['reconsideration'=>$case,'decision'=>$decision];
    if((int)$decision['current_revision']!==(int)$case['decision_revision_opened']||(string)$decision['status']!==(string)$case['decision_status_opened'])throw new InvalidArgumentException('Decision changed after reconsideration opened. Open a new reconsideration from current state.');
    $action=(string)$case['recommended_action'];if($action==='undetermined')throw new InvalidArgumentException('Reconsideration has no explicit recommendation.');$before=(string)$decision['status'];$after=$before;
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
      $lock=$pdo->prepare("SELECT applied_at FROM research_decision_reconsiderations WHERE id=? FOR UPDATE");$lock->execute([(int)$case['id']]);$applied=$lock->fetchColumn();
      if($applied){if($owns)$pdo->commit();return ['reconsideration'=>research_decision_reconsideration_access($pdo,$viewer,$casePublic),'decision'=>research_decision_detail($pdo,$viewer,(string)$decision['public_id'])];}
      if($action==='reopen'){$decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'reopened',$byAgent);$after=(string)$decision['status'];}
      elseif($action==='supersede'){$decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'superseded',$byAgent);$after=(string)$decision['status'];}
      elseif($action==='defer'){if((string)$decision['status']!=='reopened')$decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'reopened',$byAgent);$decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'deferred',$byAgent);$after=(string)$decision['status'];}
      elseif($action!=='retain')throw new InvalidArgumentException('Unsupported reconsideration action.');
      $pdo->prepare("UPDATE research_decision_reconsiderations SET applied_by_user_id=?,applied_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$viewer['id'],(int)$case['id']]);
      research_decision_reconsideration_event($pdo,(int)$case['id'],'reconsideration_applied',$byAgent?'agent':'user',(int)$viewer['id'],['recommended_action'=>$action,'decision_status_before'=>$before,'decision_status_after'=>$after]);
      research_decision_event($pdo,$decision,'decision_reconsideration_applied',$byAgent?'agent':'user',(int)$viewer['id'],['reconsideration_id'=>$casePublic,'recommended_action'=>$action,'status_before'=>$before,'status_after'=>$after]);
      if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['reconsideration'=>research_decision_reconsideration_access($pdo,$viewer,$casePublic),'decision'=>research_decision_detail($pdo,$viewer,(string)$decision['public_id'])];
}
function research_decision_evolution_timeline(PDO $pdo,array $viewer,string $decisionPublic,int $limit=250): array {
    $decision=research_decision_detail($pdo,$viewer,$decisionPublic);if(!$decision)throw new RuntimeException('Decision not found.');$items=[];
    foreach((array)$decision['versions'] as $v)$items[]=['kind'=>'decision_revision','public_id'=>(string)$v['public_id'],'timestamp'=>(string)$v['created_at'],'title'=>'Decision revision '.(int)$v['revision_number'],'meta'=>['revision'=>(int)$v['revision_number'],'reason'=>(string)($v['change_reason']??'')]];
    foreach((array)$decision['events'] as $e)$items[]=['kind'=>'decision_event','public_id'=>(string)$e['public_id'],'timestamp'=>(string)$e['created_at'],'title'=>(string)$e['event_type'],'meta'=>['payload'=>$e['payload']??[]]];
    foreach((array)$decision['outcomes'] as $o){$items[]=['kind'=>'decision_outcome','public_id'=>(string)$o['public_id'],'timestamp'=>(string)$o['observed_at'],'title'=>'Outcome: '.(string)$o['assessment'],'meta'=>['actual_summary'=>(string)$o['actual_summary'],'follow_up_state'=>(string)$o['follow_up_state']]];foreach((array)$o['versions'] as $v)$items[]=['kind'=>'outcome_revision','public_id'=>(string)$v['public_id'],'timestamp'=>(string)$v['created_at'],'title'=>'Outcome revision '.(int)$v['revision_number'],'meta'=>['revision'=>(int)$v['revision_number'],'reason'=>(string)($v['change_reason']??'')]];}
    foreach((array)$decision['reconsiderations'] as $r){$items[]=['kind'=>'reconsideration','public_id'=>(string)$r['public_id'],'timestamp'=>(string)$r['opened_at'],'title'=>(string)$r['title'],'meta'=>['status'=>(string)$r['status'],'materiality'=>(string)$r['materiality'],'recommended_action'=>(string)$r['recommended_action']]];foreach((array)$r['events'] as $e)$items[]=['kind'=>'reconsideration_event','public_id'=>(string)$e['public_id'],'timestamp'=>(string)$e['created_at'],'title'=>(string)$e['event_type'],'meta'=>['payload'=>$e['payload']??[]]];}
    usort($items,fn($a,$b)=>strcmp((string)$b['timestamp'],(string)$a['timestamp'])?:strcmp((string)$b['public_id'],(string)$a['public_id']));return ['decision_id'=>(string)$decision['public_id'],'current_status'=>(string)$decision['status'],'current_revision'=>(int)$decision['current_revision'],'items'=>array_slice($items,0,max(1,min(500,$limit)))];
}

function research_decision_agent_context(PDO $pdo,array $viewer,string $agentPublic,int $limit=8): array {
    if(!research_decisions_ready($pdo))return ['text'=>'','refs'=>[]];
    $limit=max(1,min(16,$limit));$rows=research_decision_list($pdo,$viewer,$agentPublic,100);if(!$rows)return ['text'=>'','refs'=>[]];
    $rank=['reopened'=>0,'proposed'=>1,'deferred'=>2,'accepted'=>3,'rejected'=>4,'superseded'=>5,'draft'=>6,'archived'=>7];
    usort($rows,fn($a,$b)=>(($rank[(string)$a['status']]??9)<=>($rank[(string)$b['status']]??9))?:strcmp((string)$b['updated_at'],(string)$a['updated_at']));
    $lines=["[DECISION MEMORY]","Decision state is durable application state. Explain it, but never claim a Decision was accepted, rejected, reopened, superseded, deferred, or otherwise changed unless the stored state says so."];
    $refs=[];$shown=0;
    foreach($rows as $d){
        if($shown>=$limit||($d['status']??'')==='archived')continue;$shown++;
        $signals=research_decision_reconsideration_signals($pdo,$viewer,(string)$d['public_id']);$outcomes=(array)($d['outcomes']??[]);$latest=$outcomes[0]??null;$openCases=0;foreach((array)($d['reconsiderations']??[]) as $case)if(in_array((string)$case['status'],['open','reviewing'],true))$openCases++;
        $line='[DECISION '.(string)$d['public_id'].'] '.(string)$d['title'].' · '.strtoupper((string)$d['decision_type']).' · status '.(string)$d['status'].' · revision '.(int)$d['current_revision'];
        $line.="
Statement: ".mb_substr((string)$d['statement'],0,1200);
        if(trim((string)($d['rationale']??''))!=='')$line.="
Rationale: ".mb_substr((string)$d['rationale'],0,1500);
        if($d['confidence']!==null)$line.="
Decision confidence: ".number_format((float)$d['confidence'],2,'.','');
        $counts=(array)($signals['counts']??[]);$signalTotal=count((array)($signals['signals']??[]));if($signalTotal)$line.="
Review signals: ".$signalTotal." total · critical ".(int)($counts['critical']??0)." · high ".(int)($counts['high']??0)." · open/reviewing cases ".$openCases;
        if($latest)$line.="
Latest observed outcome: ".strtoupper((string)$latest['assessment']).' · '.mb_substr((string)$latest['actual_summary'],0,900).($latest['follow_up_state']!=='none'?' · follow-up '.(string)$latest['follow_up_state']:'');
        $lines[]=$line;
        foreach((array)$d['refs'] as $ref)$refs[]=['type'=>(string)$ref['ref_type'],'id'=>(string)$ref['ref_public_id']];
        if(!empty($d['source_mission_public_id']))$refs[]=['type'=>'mission','id'=>(string)$d['source_mission_public_id']];
    }
    $seen=[];$dedup=[];foreach($refs as $ref){$k=$ref['type'].'|'.$ref['id'];if(isset($seen[$k]))continue;$seen[$k]=true;$dedup[]=$ref;}
    return ['text'=>$shown?implode("

",$lines):'','refs'=>$dedup,'agent_id'=>$agentPublic];
}

function research_decision_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=20): void {
    if(!research_decisions_ready($pdo)||!function_exists('cognitive_feed_projects'))return;$limit=max(1,min(60,$limit));$shown=0;
    foreach(cognitive_feed_projects($pdo,$viewer,8) as $project){
        if($shown>=$limit)break;$q=$pdo->prepare("SELECT rd.public_id,ra.public_id agent_public_id FROM research_decisions rd JOIN research_agents ra ON ra.id=rd.research_agent_id WHERE rd.project_id=? AND rd.status<>'archived' ORDER BY rd.updated_at DESC,rd.id DESC LIMIT 40");$q->execute([(int)$project['id']]);
        foreach($q->fetchAll() as $row){
            if($shown>=$limit)break;$d=research_decision_detail($pdo,$viewer,(string)$row['public_id']);if(!$d)continue;
            $signals=research_decision_reconsideration_signals($pdo,$viewer,(string)$d['public_id']);$signalRows=(array)($signals['signals']??[]);$critical=0;$high=0;foreach($signalRows as $s){if(($s['materiality']??'')==='critical')$critical++;if(($s['materiality']??'')==='high')$high++;}
            $openCases=[];foreach((array)($d['reconsiderations']??[]) as $case)if(in_array((string)$case['status'],['open','reviewing'],true))$openCases[]=$case;
            if(!$openCases&&$critical===0&&$high===0&&(string)$d['status']!=='reopened')continue;
            $priority=$critical>0?'critical':(($high>0||$openCases||(string)$d['status']==='reopened')?'high':'medium');
            $body=[];if($critical||$high)$body[]=($critical+$high).' high-impact review signal'.(($critical+$high)===1?'':'s');if($openCases)$body[]=count($openCases).' active reconsideration case'.(count($openCases)===1?'':'s');if((string)$d['status']==='reopened')$body[]='Decision is reopened';
            $actions=[cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode((string)$project['public_id']))];
            if(function_exists('cognitive_feed_action_agent'))$actions[]=cognitive_feed_action_agent('Ask Agent why', 'Explain why the Decision "'.(string)$d['title'].'" needs review, what evidence changed, and what would change the Decision. Do not change Decision state.',[['type'=>'research','public_id'=>(string)$project['public_id']]]);
            cognitive_feed_add($items,[
              'key'=>cognitive_feed_key('decision_reconsideration','research_decision',(string)$d['public_id'],(string)$d['updated_at'].'|'.(string)$d['status'].'|'.$critical.'|'.$high.'|'.count($openCases)),
              'type'=>'decision_reconsideration','section'=>'needs_attention','priority'=>$priority,'created_at'=>(string)$d['updated_at'],
              'score_extra'=>$critical>0?14:($high>0?9:6),'title'=>'Decision needs review: '.(string)$d['title'],
              'body'=>implode(' · ',$body).'.','meta'=>['decision_id'=>(string)$d['public_id'],'decision_status'=>(string)$d['status'],'critical_signals'=>$critical,'high_signals'=>$high,'active_reconsiderations'=>count($openCases)],
              'actions'=>$actions
            ]);$shown++;
        }
    }
}

function research_decision_report_snapshot(PDO $pdo,array $viewer,array $project,string $visibility): array {
    if(!research_decisions_ready($pdo))return [];$public=$visibility==='public';$q=$pdo->prepare("SELECT public_id FROM research_decisions WHERE project_id=? AND status<>'archived' ORDER BY updated_at,id");$q->execute([(int)$project['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        $d=research_decision_detail($pdo,$viewer,(string)$id);if(!$d)continue;if($public&&!in_array((string)$d['status'],['accepted','superseded'],true))continue;
        $item=['id'=>(string)$d['public_id'],'type'=>(string)$d['decision_type'],'status'=>(string)$d['status'],'title'=>(string)$d['title'],'statement'=>(string)$d['statement'],'rationale'=>(string)($d['rationale']??''),'confidence'=>$d['confidence']!==null?(float)$d['confidence']:null,'revision'=>(int)$d['current_revision'],'decided_at'=>$d['decided_at'],'source_mission_id'=>(string)($d['source_mission_public_id']??''),'evidence'=>[],'outcomes'=>[]];
        foreach((array)$d['refs'] as $ref){$edge=['type'=>(string)$ref['ref_type'],'id'=>(string)$ref['ref_public_id'],'role'=>(string)$ref['ref_role'],'strength'=>$ref['strength']!==null?(float)$ref['strength']:null];if(!$public)$edge['note']=(string)($ref['note']??'');$item['evidence'][]=$edge;}
        foreach((array)$d['outcomes'] as $o)$item['outcomes'][]=['id'=>(string)$o['public_id'],'assessment'=>(string)$o['assessment'],'expected'=>(string)($o['expected_summary']??''),'actual'=>(string)$o['actual_summary'],'variance'=>(string)($o['variance_summary']??''),'lessons'=>(string)($o['lessons']??''),'confidence'=>$o['confidence']!==null?(float)$o['confidence']:null,'follow_up_state'=>(string)$o['follow_up_state'],'observed_at'=>(string)$o['observed_at']];
        if(!$public){
            $item['challenges']=array_map(fn($ch)=>['id'=>(string)$ch['public_id'],'type'=>(string)$ch['challenge_type'],'title'=>(string)$ch['title'],'detail'=>(string)($ch['detail']??''),'severity'=>(string)$ch['severity'],'status'=>(string)$ch['status'],'resolution'=>(string)($ch['resolution']??'')],(array)$d['challenges']);
            $item['reconsiderations']=array_map(fn($case)=>['id'=>(string)$case['public_id'],'trigger_type'=>(string)$case['trigger_type'],'title'=>(string)$case['title'],'reason'=>(string)$case['reason'],'materiality'=>(string)$case['materiality'],'status'=>(string)$case['status'],'recommended_action'=>(string)$case['recommended_action'],'resolution'=>(string)($case['resolution']??''),'opened_at'=>(string)$case['opened_at'],'applied_at'=>(string)($case['applied_at']??'')],(array)$d['reconsiderations']);
        }
        $out[]=$item;
    }
    return $out;
}

function research_decision_review_snapshot(PDO $pdo,array $viewer,string $decisionPublic): array {
    $d=research_decision_detail($pdo,$viewer,$decisionPublic);if(!$d)throw new RuntimeException('Decision not found.');
    $outcomes=[];foreach((array)$d['outcomes'] as $o)$outcomes[]=[
      'public_id'=>(string)$o['public_id'],'assessment'=>(string)$o['assessment'],'follow_up_state'=>(string)$o['follow_up_state'],
      'observed_at'=>(string)$o['observed_at'],'latest_revision'=>(int)(($o['versions'][0]['revision_number']??0))
    ];
    usort($outcomes,fn($a,$b)=>strcmp($a['public_id'],$b['public_id']));
    $reconsiderations=[];foreach((array)$d['reconsiderations'] as $r)$reconsiderations[]=[
      'public_id'=>(string)$r['public_id'],'status'=>(string)$r['status'],'recommended_action'=>(string)$r['recommended_action'],
      'materiality'=>(string)$r['materiality'],'opening_context_hash'=>(string)$r['opening_context_hash'],'applied_at'=>(string)($r['applied_at']??'')
    ];
    usort($reconsiderations,fn($a,$b)=>strcmp($a['public_id'],$b['public_id']));
    return [
      'public_id'=>(string)$d['public_id'],'decision_type'=>(string)$d['decision_type'],'status'=>(string)$d['status'],
      'current_revision'=>(int)$d['current_revision'],'config_hash'=>(string)$d['config_hash'],'decided_at'=>(string)($d['decided_at']??''),
      'outcomes'=>$outcomes,'reconsiderations'=>$reconsiderations
    ];
}
function research_decision_review_state_hash(PDO $pdo,array $viewer,string $decisionPublic): string {
    return research_decision_hash(research_decision_review_snapshot($pdo,$viewer,$decisionPublic));
}
function research_decision_reconsideration_review_state_hash(PDO $pdo,array $viewer,string $casePublic): string {
    $r=research_decision_reconsideration_access($pdo,$viewer,$casePublic);if(!$r)throw new RuntimeException('Decision reconsideration not found.');
    $payload=[
      'public_id'=>(string)$r['public_id'],'decision_public_id'=>(string)$r['decision_public_id'],'trigger_type'=>(string)$r['trigger_type'],
      'trigger_public_id'=>(string)($r['trigger_public_id']??''),'title'=>(string)$r['title'],'reason'=>(string)$r['reason'],
      'materiality'=>(string)$r['materiality'],'status'=>(string)$r['status'],'recommended_action'=>(string)$r['recommended_action'],
      'resolution'=>(string)($r['resolution']??''),'decision_revision_opened'=>(int)$r['decision_revision_opened'],
      'decision_status_opened'=>(string)$r['decision_status_opened'],'opening_context_hash'=>(string)$r['opening_context_hash'],
      'resolved_at'=>(string)($r['resolved_at']??''),'applied_at'=>(string)($r['applied_at']??'')
    ];
    return research_decision_hash($payload);
}
function research_decision_review_overview(PDO $pdo,array $viewer,string $decisionPublic): array {
    if(!function_exists('research_reviews_ready')||!research_reviews_ready($pdo))return ['total'=>0,'open'=>0,'completed'=>0,'latest'=>null,'consensus'=>null];
    $q=$pdo->prepare("SELECT public_id FROM research_reviews WHERE subject_type='decision' AND subject_public_id=? ORDER BY id DESC LIMIT 25");$q->execute([$decisionPublic]);
    $total=0;$open=0;$completed=0;$latest=null;$consensus=null;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$r=research_review_access($pdo,$viewer,(string)$id);if(!$r)continue;$total++;if($r['status']==='open')$open++;if($r['status']==='completed')$completed++;if($latest===null){$a=research_review_aggregate($pdo,$r);$latest=['public_id'=>$r['public_id'],'status'=>$r['status'],'is_stale'=>(bool)$r['is_stale'],'consensus'=>$a['consensus'],'counts'=>$a['counts'],'created_at'=>$r['created_at']];$consensus=$a['consensus'];}}
    return ['total'=>$total,'open'=>$open,'completed'=>$completed,'latest'=>$latest,'consensus'=>$consensus];
}
function research_decision_command_center(PDO $pdo,array $viewer,string $scope='all',int $limit=150): array {
    if(!research_decisions_ready($pdo))return ['ready'=>false,'generated_at'=>date('Y-m-d H:i:s'),'stats'=>[],'decisions'=>[]];
    $scope=in_array($scope,['all','attention','proposed','reopened','decided','outcomes','reviews'],true)?$scope:'all';$limit=max(1,min(250,$limit));$uid=(int)$viewer['id'];
    $q=$pdo->prepare("SELECT DISTINCT rd.public_id,rd.updated_at,rd.id FROM research_decisions rd JOIN research_agents ra ON ra.id=rd.research_agent_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rd.status<>'archived' AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?))
      ORDER BY rd.updated_at DESC,rd.id DESC LIMIT ".$limit);
    $q->execute([$uid,$uid,$uid]);$rows=[];$stats=['total'=>0,'attention'=>0,'proposed'=>0,'reopened'=>0,'decided'=>0,'with_outcomes'=>0,'open_reviews'=>0];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
      $d=research_decision_detail($pdo,$viewer,(string)$id);if(!$d)continue;$signals=research_decision_reconsideration_signals($pdo,$viewer,(string)$id);
      $critical=(int)($signals['counts']['critical']??0);$high=(int)($signals['counts']['high']??0);$activeCases=0;foreach((array)$d['reconsiderations'] as $case)if(in_array((string)$case['status'],['open','reviewing'],true))$activeCases++;
      $outcomeSummary=research_decision_outcome_summary($pdo,$viewer,(string)$id);$review=research_decision_review_overview($pdo,$viewer,(string)$id);$reviewConcern=in_array((string)($review['consensus']??''),['changes_requested','unresolved_objection','mixed_review'],true);
      $attention=in_array((string)$d['status'],['proposed','reopened'],true)||$critical>0||$high>0||$activeCases>0||$reviewConcern||!empty($review['latest']['is_stale']);
      $reasons=[];if((string)$d['status']==='proposed')$reasons[]='Awaiting disposition';if((string)$d['status']==='reopened')$reasons[]='Reopened';
      if($critical)$reasons[]=$critical.' critical signal'.($critical===1?'':'s');if($high)$reasons[]=$high.' high signal'.($high===1?'':'s');if($activeCases)$reasons[]=$activeCases.' active reconsideration'.($activeCases===1?'':'s');
      if($reviewConcern)$reasons[]='Team review has unresolved feedback';if(!empty($review['latest']['is_stale']))$reasons[]='Latest team review is stale';
      $d['command']=['attention'=>$attention,'attention_reasons'=>$reasons,'signals'=>$signals,'active_reconsiderations'=>$activeCases,'outcome_summary'=>$outcomeSummary,'review'=>$review,
        'review_url'=>'/research-reviews.php?type=decision&subject='.rawurlencode((string)$d['public_id']),'project_url'=>'/research-project.php?id='.rawurlencode((string)$d['project_public_id'])];
      $stats['total']++;if($attention)$stats['attention']++;if($d['status']==='proposed')$stats['proposed']++;if($d['status']==='reopened')$stats['reopened']++;if(in_array((string)$d['status'],['accepted','rejected','deferred','superseded'],true))$stats['decided']++;if(($outcomeSummary['total']??0)>0)$stats['with_outcomes']++;$stats['open_reviews']+=(int)$review['open'];
      $show=match($scope){'attention'=>$attention,'proposed'=>$d['status']==='proposed','reopened'=>$d['status']==='reopened','decided'=>in_array((string)$d['status'],['accepted','rejected','deferred','superseded'],true),'outcomes'=>($outcomeSummary['total']??0)>0,'reviews'=>$review['total']>0,default=>true};
      if($show)$rows[]=$d;
    }
    usort($rows,function($a,$b){$aa=!empty($a['command']['attention'])?1:0;$bb=!empty($b['command']['attention'])?1:0;if($aa!==$bb)return $bb<=>$aa;return strcmp((string)$b['updated_at'],(string)$a['updated_at']);});
    return ['ready'=>true,'generated_at'=>date('Y-m-d H:i:s'),'scope'=>$scope,'stats'=>$stats,'decisions'=>$rows];
}

