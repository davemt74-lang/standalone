<?php
declare(strict_types=1);

/**
 * Phase 79 Section 3 — Agent Memory / Knowledge Management.
 *
 * Governance layer over canonical Research retrieval. It never replaces the
 * retrieval index or the underlying knowledge objects; it controls whether an
 * indexed object may be supplied to an Agent, records user corrections, and
 * keeps an append-only usage/audit trail.
 */

function research_memory_ready(PDO $pdo): bool {
    try{
        foreach(['research_memory_controls','research_memory_events','research_memory_usage'] as $table)
            if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}

function research_memory_project_access(PDO $pdo,array $viewer,string $projectPublic): array {
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));
    if(!$project)throw new RuntimeException('Research project is unavailable.');
    return $project;
}

function research_memory_control_row(PDO $pdo,int $projectId,string $type,string $publicId): ?array {
    if(!research_memory_ready($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM research_memory_controls WHERE project_id=? AND object_type=? AND object_public_id=? LIMIT 1');
    $q->execute([$projectId,trim($type),trim($publicId)]);
    return $q->fetch()?:null;
}

function research_memory_privacy_state(array $project,string $objectType,array $metadata=[]): string {
    $visibility=strtolower(trim((string)($metadata['visibility']??'')));
    if(in_array($visibility,['private','team','public'],true))return $visibility;
    if(!empty($project['team_id']))return 'team';
    return 'private';
}

function research_memory_source_kind(string $objectType,array $metadata=[]): string {
    if(in_array($objectType,['annotation','source','bookmark','upload','recording'],true))return 'captured';
    if($objectType==='document'&&!empty($metadata['source_report_id']))return 'generated_report';
    if(in_array($objectType,['claim','finding','entity','claim_relation','entity_relation'],true))return 'structured_insight';
    if(in_array($objectType,['task','program','report','document','sticky'],true))return 'workspace';
    if(!empty($metadata['vp3'])||!empty($metadata['provider']))return 'connected';
    return 'inherited';
}

function research_memory_snapshot(?array $control): array {
    return [
      'retrieval_state'=>(string)($control['retrieval_state']??'inherit'),
      'correction_text'=>trim((string)($control['correction_text']??'')),
      'correction_hash'=>$control['correction_hash']??null,
    ];
}

function research_memory_upsert(PDO $pdo,array $viewer,string $projectPublic,string $objectType,string $objectPublic,string $retrievalState,string $correctionText): array {
    if(!research_memory_ready($pdo))throw new RuntimeException('Agent Memory requires the latest database upgrade.');
    $project=research_memory_project_access($pdo,$viewer,$projectPublic);
    if(!project_can_write($project))throw new RuntimeException('You do not have permission to manage this Agent memory.');
    $objectType=trim($objectType);$objectPublic=trim($objectPublic);
    if($objectType===''||$objectPublic==='')throw new InvalidArgumentException('Knowledge object is required.');
    $retrievalState=strtolower(trim($retrievalState));if(!in_array($retrievalState,['inherit','include','exclude'],true))throw new InvalidArgumentException('Invalid retrieval state.');
    $correctionText=mb_substr(trim($correctionText),0,12000);
    $before=research_memory_control_row($pdo,(int)$project['id'],$objectType,$objectPublic);
    $beforeSnap=research_memory_snapshot($before);
    $correctionHash=$correctionText!==''?hash('sha256',$correctionText):null;
    $public=$before['public_id']??ulid_like();

    $pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO research_memory_controls(public_id,project_id,object_type,object_public_id,retrieval_state,correction_text,correction_hash,corrected_by_user_id,corrected_at,created_by_user_id)
          VALUES(?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE retrieval_state=VALUES(retrieval_state),correction_text=VALUES(correction_text),correction_hash=VALUES(correction_hash),
            corrected_by_user_id=VALUES(corrected_by_user_id),corrected_at=VALUES(corrected_at),updated_at=NOW()")
          ->execute([$public,(int)$project['id'],$objectType,$objectPublic,$retrievalState,$correctionText!==''?$correctionText:null,$correctionHash,
            $correctionText!==''?(int)$viewer['id']:null,$correctionText!==''?date('Y-m-d H:i:s'):null,(int)$viewer['id']]);
        $after=research_memory_control_row($pdo,(int)$project['id'],$objectType,$objectPublic);
        if(!$after)throw new RuntimeException('Unable to save Agent memory control.');
        $afterSnap=research_memory_snapshot($after);
        $event='created';
        if($before){
            if($beforeSnap['correction_text']!==$afterSnap['correction_text'])$event=$afterSnap['correction_text']===''?'correction_cleared':'corrected';
            elseif($beforeSnap['retrieval_state']!==$afterSnap['retrieval_state'])$event=$afterSnap['retrieval_state']==='inherit'?'restored':'retrieval_changed';
        }
        if(!$before||$beforeSnap!==$afterSnap){
            $pdo->prepare('INSERT INTO research_memory_events(public_id,control_id,project_id,actor_user_id,event_type,before_json,after_json) VALUES(?,?,?,?,?,?,?)')
              ->execute([ulid_like(),(int)$after['id'],(int)$project['id'],(int)$viewer['id'],$event,
                $before?json_encode($beforeSnap,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,
                json_encode($afterSnap,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    if(function_exists('research_retrieval_queue_project'))research_retrieval_queue_project($pdo,(int)$project['id']);
    return research_memory_control_row($pdo,(int)$project['id'],$objectType,$objectPublic)??[];
}

function research_memory_apply_result(PDO $pdo,array $viewer,array $project,array $result): ?array {
    if(!research_memory_ready($pdo))return $result;
    $type=(string)($result['object_type']??'');$id=(string)($result['public_id']??$result['object_public_id']??'');
    if($type===''||$id==='')return $result;
    $control=research_memory_control_row($pdo,(int)$project['id'],$type,$id);
    $metadata=(array)($result['metadata']??[]);
    $result['memory']=[
      'retrieval_state'=>(string)($control['retrieval_state']??'inherit'),
      'privacy_state'=>research_memory_privacy_state($project,$type,$metadata),
      'source_kind'=>research_memory_source_kind($type,$metadata),
      'corrected'=>trim((string)($control['correction_text']??''))!=='',
      'corrected_at'=>$control['corrected_at']??null,
    ];
    if(($control['retrieval_state']??'inherit')==='exclude')return null;
    $correction=trim((string)($control['correction_text']??''));
    if($correction!==''){
        $original=trim((string)($result['snippet']??''));
        $result['snippet']="USER CORRECTION (authoritative user-provided note): ".$correction.($original!==''?"\n\nORIGINAL CAPTURED KNOWLEDGE:\n".$original:'');
    }
    return $result;
}

function research_memory_record_usage(PDO $pdo,array $viewer,array $agent,array $results,?int $messageId=null): void {
    if(!research_memory_ready($pdo)||!$results)return;
    $projectId=(int)($agent['project_id']??0);$agentId=(int)($agent['id']??0);
    if($projectId<1)return;
    $q=$pdo->prepare('INSERT INTO research_memory_usage(public_id,project_id,research_agent_id,user_id,conversation_message_id,object_type,object_public_id,locator_label) VALUES(?,?,?,?,?,?,?,?)');
    $seen=[];
    foreach($results as $result){
        $type=trim((string)($result['object_type']??''));$id=trim((string)($result['public_id']??''));
        if($type===''||$id==='')continue;$key=$type.':'.$id;if(isset($seen[$key]))continue;$seen[$key]=true;
        try{$q->execute([ulid_like(),$projectId,$agentId?:null,(int)$viewer['id'],$messageId?:null,$type,$id,mb_substr(trim((string)($result['locator_label']??'')),0,190)?:null]);}catch(Throwable $e){}
    }
}

function research_memory_usage_summary(PDO $pdo,int $projectId,string $type,string $publicId): array {
    if(!research_memory_ready($pdo))return ['count'=>0,'last_used_at'=>null];
    $q=$pdo->prepare('SELECT COUNT(*) usage_count,MAX(created_at) last_used_at FROM research_memory_usage WHERE project_id=? AND object_type=? AND object_public_id=?');
    $q->execute([$projectId,$type,$publicId]);$r=$q->fetch()?:[];
    return ['count'=>(int)($r['usage_count']??0),'last_used_at'=>$r['last_used_at']??null];
}

function research_memory_history(PDO $pdo,array $viewer,string $projectPublic,string $type,string $publicId,int $limit=50): array {
    $project=research_memory_project_access($pdo,$viewer,$projectPublic);if(!research_memory_ready($pdo))return [];
    $q=$pdo->prepare("SELECT e.public_id,e.event_type,e.before_json,e.after_json,e.created_at,u.public_id actor_public_id,u.display_name,u.username
      FROM research_memory_events e JOIN research_memory_controls c ON c.id=e.control_id JOIN users u ON u.id=e.actor_user_id
      WHERE e.project_id=? AND c.object_type=? AND c.object_public_id=? ORDER BY e.id DESC LIMIT ".max(1,min(100,$limit)));
    $q->execute([(int)$project['id'],trim($type),trim($publicId)]);
    $out=[];foreach($q->fetchAll()?:[] as $r){$r['before']=json_decode((string)($r['before_json']??''),true);$r['after']=json_decode((string)($r['after_json']??''),true);unset($r['before_json'],$r['after_json']);$out[]=$r;}return $out;
}

function research_memory_catalog(PDO $pdo,array $viewer,string $projectPublic,int $limit=120): array {
    $project=research_memory_project_access($pdo,$viewer,$projectPublic);$limit=max(1,min(250,$limit));
    if(!research_retrieval_ready($pdo))return [];
    $q=$pdo->prepare("SELECT d.object_type,d.object_public_id,d.title,d.source_status,d.source_updated_at,d.metadata_json,d.updated_at,
      c.retrieval_state,c.correction_text,c.corrected_at,
      COALESCE(u.usage_count,0) usage_count,u.last_used_at
      FROM research_retrieval_documents d
      LEFT JOIN research_memory_controls c ON c.project_id=d.project_id AND c.object_type=d.object_type AND c.object_public_id=d.object_public_id
      LEFT JOIN (
        SELECT project_id,object_type,object_public_id,COUNT(*) usage_count,MAX(created_at) last_used_at
        FROM research_memory_usage WHERE project_id=? GROUP BY project_id,object_type,object_public_id
      ) u ON u.project_id=d.project_id AND u.object_type=d.object_type AND u.object_public_id=d.object_public_id
      WHERE d.project_id=? ORDER BY COALESCE(u.last_used_at,d.source_updated_at,d.updated_at) DESC,d.id DESC LIMIT ".$limit);
    $q->execute([(int)$project['id'],(int)$project['id']]);$out=[];
    foreach($q->fetchAll()?:[] as $r){
        $metadata=json_decode((string)($r['metadata_json']??''),true)?:[];
        $out[]=[
          'object_type'=>(string)$r['object_type'],'public_id'=>(string)$r['object_public_id'],'title'=>(string)$r['title'],
          'source_status'=>(string)$r['source_status'],'updated_at'=>$r['source_updated_at']?:$r['updated_at'],
          'retrieval_state'=>(string)($r['retrieval_state']??'inherit'),'correction_text'=>(string)($r['correction_text']??''),
          'corrected_at'=>$r['corrected_at']??null,'usage_count'=>(int)$r['usage_count'],'last_used_at'=>$r['last_used_at']??null,
          'privacy_state'=>research_memory_privacy_state($project,(string)$r['object_type'],$metadata),
          'source_kind'=>research_memory_source_kind((string)$r['object_type'],$metadata),'metadata'=>$metadata,
        ];
    }
    return $out;
}

function research_memory_summary(PDO $pdo,array $viewer,string $projectPublic): array {
    $items=research_memory_catalog($pdo,$viewer,$projectPublic,250);
    $summary=['total'=>count($items),'excluded'=>0,'corrected'=>0,'used'=>0,'private'=>0,'team'=>0,'public'=>0];
    foreach($items as $item){
        if($item['retrieval_state']==='exclude')$summary['excluded']++;
        if(trim((string)$item['correction_text'])!=='')$summary['corrected']++;
        if((int)$item['usage_count']>0)$summary['used']++;
        if(isset($summary[$item['privacy_state']]))$summary[$item['privacy_state']]++;
    }
    return $summary;
}
