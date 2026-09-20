<?php
declare(strict_types=1);

function research_outcomes_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_outcome_events')&&installer_table_exists($pdo,'research_outcome_refs')&&installer_table_exists($pdo,'research_outcome_feedback');}
    catch(Throwable $e){return false;}
}

function research_outcome_decision_labels(): array {
    return [
      'accepted'=>'Accepted','rejected'=>'Rejected','dismissed'=>'Dismissed','executed'=>'Executed','completed'=>'Completed',
      'failed'=>'Failed','skipped'=>'Skipped','resolved'=>'Resolved','reopened'=>'Reopened','recorded'=>'Recorded','stale'=>'Stale'
    ];
}

function research_outcome_ref_access(PDO $pdo,array $viewer,string $type,string $publicId): bool {
    $type=strtolower(trim($type));$publicId=trim($publicId);if($publicId==='')return false;
    if(in_array($type,['project','research','research_project'],true))return project_access($pdo,(int)$viewer['id'],$publicId)!==null;
    if($type==='source')return source_access($pdo,$publicId,$viewer)!==null;
    if($type==='annotation')return annotation_access($pdo,$publicId,$viewer)!==null;
    if($type==='claim'){
        if(function_exists('research_claim_access'))return research_claim_access($pdo,$viewer,$publicId)!==null;
        $q=$pdo->prepare('SELECT rp.public_id FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id WHERE rc.public_id=? LIMIT 1');$q->execute([$publicId]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;
    }
    if($type==='finding'){
        if(function_exists('research_finding_access'))return research_finding_access($pdo,$viewer,$publicId)!==null;
        $q=$pdo->prepare('SELECT rp.public_id FROM research_findings rf JOIN research_projects rp ON rp.id=rf.project_id WHERE rf.public_id=? LIMIT 1');$q->execute([$publicId]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;
    }
    if($type==='task'){$q=$pdo->prepare('SELECT rp.public_id FROM research_tasks rt JOIN research_projects rp ON rp.id=rt.project_id WHERE rt.public_id=? LIMIT 1');$q->execute([$publicId]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;}
    if($type==='note'){$q=$pdo->prepare('SELECT rp.public_id FROM research_notes rn JOIN research_projects rp ON rp.id=rn.project_id WHERE rn.public_id=? LIMIT 1');$q->execute([$publicId]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;}
    if($type==='claim_evidence'){$q=$pdo->prepare('SELECT rp.public_id FROM claim_evidence ce JOIN research_claims rc ON rc.id=ce.claim_id JOIN research_projects rp ON rp.id=rc.project_id WHERE ce.public_id=? LIMIT 1');$q->execute([$publicId]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;}
    if($type==='claim_relation'){$q=$pdo->prepare('SELECT rp.public_id FROM claim_relations cr JOIN research_projects rp ON rp.id=cr.project_id WHERE cr.public_id=? LIMIT 1');$q->execute([$publicId]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;}
    if($type==='entity')return function_exists('research_entity_access')&&research_entity_access($pdo,$viewer,$publicId)!==null;
    if($type==='conversation')return function_exists('conversation_access')&&conversation_access($pdo,$viewer,$publicId)!==null;
    if($type==='automation')return function_exists('research_automation_access')&&research_automation_access($pdo,$viewer,$publicId)!==null;
    if($type==='cross_research_link')return function_exists('cross_research_link_access')&&cross_research_link_access($pdo,$viewer,$publicId)!==null;
    if($type==='agent_action'){
        $q=$pdo->prepare("SELECT rp.public_id FROM agent_action_proposals aap JOIN research_projects rp ON rp.id=aap.project_id WHERE aap.public_id=? AND aap.proposed_by_user_id=? LIMIT 1");$q->execute([$publicId,$viewer['id']]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;
    }
    if(in_array($type,['cognitive_observation','notification','automation_run','cross_research_suggestion'],true))return true;
    return false;
}

function research_outcome_normalize_refs(array $refs): array {
    $out=[];$seen=[];foreach($refs as $ref){
        if(!is_array($ref))continue;$type=strtolower(trim((string)($ref['type']??'')));$id=trim((string)($ref['public_id']??$ref['id']??''));$role=(string)($ref['role']??'context');
        if($type==='research')$type='project';if(!in_array($role,['context','source','result'],true))$role='context';if($type===''||$id==='')continue;$k=$type.'|'.$id.'|'.$role;if(isset($seen[$k]))continue;$seen[$k]=true;$out[]=['type'=>$type,'public_id'=>$id,'role'=>$role];
    }return $out;
}

function research_outcome_record(PDO $pdo,array $viewer,array $data): array {
    if(!research_outcomes_ready($pdo))return [];
    $eventType=mb_substr(trim((string)($data['event_type']??'decision')),0,64);$decision=mb_substr(trim((string)($data['decision_type']??'recorded')),0,40);$sourceType=mb_substr(trim((string)($data['source_type']??'manual')),0,64);
    $sourcePublic=trim((string)($data['source_public_id']??''));$objectType=trim((string)($data['object_type']??''));$objectPublic=trim((string)($data['object_public_id']??''));$resultType=trim((string)($data['result_type']??''));$resultPublic=trim((string)($data['result_public_id']??''));
    $title=mb_substr(trim((string)($data['title']??'Decision recorded')),0,255);if($title==='')$title='Decision recorded';$summary=mb_substr(trim((string)($data['summary']??'')),0,1200);$note=mb_substr(trim((string)($data['note']??'')),0,8000);
    $occurred=trim((string)($data['occurred_at']??''));if($occurred===''||strtotime($occurred)===false)$occurred=date('Y-m-d H:i:s');
    $projectId=null;$projectPublic=trim((string)($data['project_public_id']??''));if($projectPublic!==''){$p=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$p)throw new RuntimeException('Research project is unavailable.');$projectId=(int)$p['id'];}
    $refs=research_outcome_normalize_refs((array)($data['refs']??[]));if($projectPublic!=='')$refs[]= ['type'=>'project','public_id'=>$projectPublic,'role'=>'context'];if($objectType!==''&&$objectPublic!=='')$refs[]=['type'=>$objectType,'public_id'=>$objectPublic,'role'=>'source'];if($resultType!==''&&$resultPublic!=='')$refs[]=['type'=>$resultType,'public_id'=>$resultPublic,'role'=>'result'];$refs=research_outcome_normalize_refs($refs);
    foreach($refs as $ref)if(!research_outcome_ref_access($pdo,$viewer,$ref['type'],$ref['public_id']))throw new RuntimeException('Outcome context is no longer accessible.');
    $dedupeRaw=trim((string)($data['dedupe_key']??''));if($dedupeRaw==='')$dedupeRaw=implode('|',[$sourceType,$sourcePublic,$eventType,$decision,$objectType,$objectPublic,$resultType,$resultPublic,$occurred]);$dedupe=hash('sha256',$dedupeRaw);$public=ulid_like();
    $metadata=(array)($data['metadata']??[]);$metadataJson=$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null;$manual=!empty($data['is_manual'])?1:0;
    $pdo->prepare("INSERT INTO research_outcome_events(public_id,user_id,project_id,event_type,decision_type,source_type,source_public_id,object_type,object_public_id,title,summary,note,result_type,result_public_id,metadata_json,dedupe_key,is_manual,occurred_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),summary=VALUES(summary),note=IF(VALUES(note)<>'',VALUES(note),note),result_type=VALUES(result_type),result_public_id=VALUES(result_public_id),metadata_json=VALUES(metadata_json),occurred_at=VALUES(occurred_at),updated_at=NOW()")
      ->execute([$public,$viewer['id'],$projectId,$eventType,$decision,$sourceType,$sourcePublic?:null,$objectType?:null,$objectPublic?:null,$title,$summary?:null,$note?:null,$resultType?:null,$resultPublic?:null,$metadataJson,$dedupe,$manual,$occurred]);
    $q=$pdo->prepare('SELECT * FROM research_outcome_events WHERE user_id=? AND dedupe_key=? LIMIT 1');$q->execute([$viewer['id'],$dedupe]);$row=$q->fetch()?:[];if(!$row)return [];
    $pdo->prepare('DELETE FROM research_outcome_refs WHERE outcome_id=?')->execute([$row['id']]);$ins=$pdo->prepare('INSERT IGNORE INTO research_outcome_refs(outcome_id,ref_type,ref_public_id,ref_role) VALUES(?,?,?,?)');foreach($refs as $ref)$ins->execute([$row['id'],$ref['type'],$ref['public_id'],$ref['role']]);
    return $row;
}

function research_outcome_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_outcomes_ready($pdo))return null;$q=$pdo->prepare("SELECT roe.*,rp.public_id project_public_id,rp.title project_title,rof.usefulness,rof.follow_up_state,rof.comment feedback_comment,rof.updated_at feedback_updated_at FROM research_outcome_events roe LEFT JOIN research_projects rp ON rp.id=roe.project_id LEFT JOIN research_outcome_feedback rof ON rof.outcome_id=roe.id AND rof.user_id=roe.user_id WHERE roe.public_id=? AND roe.user_id=? LIMIT 1");$q->execute([trim($publicId),$viewer['id']]);$row=$q->fetch();if(!$row)return null;
    if(!empty($row['project_public_id'])&&!project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']))return null;$r=$pdo->prepare('SELECT ref_type,ref_public_id,ref_role FROM research_outcome_refs WHERE outcome_id=? ORDER BY ref_role,ref_type,ref_public_id');$r->execute([$row['id']]);$refs=$r->fetchAll();foreach($refs as $ref)if(!research_outcome_ref_access($pdo,$viewer,(string)$ref['ref_type'],(string)$ref['ref_public_id']))return null;$row['refs']=$refs;return $row;
}

function research_outcome_list(PDO $pdo,array $viewer,?string $projectPublic=null,?string $decision=null,int $limit=100): array {
    if(!research_outcomes_ready($pdo))return [];$limit=max(1,min(300,$limit));$params=[$viewer['id']];$where='roe.user_id=?';
    if($projectPublic!==null&&trim($projectPublic)!==''){$p=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$p)return [];$where.=' AND roe.project_id=?';$params[]=$p['id'];}
    if($decision!==null&&trim($decision)!==''){$where.=' AND roe.decision_type=?';$params[]=trim($decision);}
    $q=$pdo->prepare("SELECT roe.public_id FROM research_outcome_events roe WHERE $where ORDER BY roe.occurred_at DESC,roe.id DESC LIMIT ".$limit);$q->execute($params);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_outcome_access($pdo,$viewer,(string)$id);if($row)$out[]=$row;}return $out;
}

function research_outcome_feedback_set(PDO $pdo,array $viewer,string $publicId,?string $usefulness,string $followUp,string $comment=''): array {
    $row=research_outcome_access($pdo,$viewer,$publicId);if(!$row)throw new RuntimeException('Outcome is unavailable.');$usefulness=$usefulness!==null?trim($usefulness):null;if($usefulness!==null&&!in_array($usefulness,['helpful','not_helpful'],true))throw new InvalidArgumentException('Invalid usefulness feedback.');if(!in_array($followUp,['none','follow_up','resolved','reopened'],true))throw new InvalidArgumentException('Invalid follow-up state.');
    $comment=mb_substr(trim($comment),0,1000);$pdo->prepare("INSERT INTO research_outcome_feedback(user_id,outcome_id,usefulness,follow_up_state,comment) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE usefulness=VALUES(usefulness),follow_up_state=VALUES(follow_up_state),comment=VALUES(comment),updated_at=NOW()")->execute([$viewer['id'],$row['id'],$usefulness,$followUp,$comment?:null]);
    return research_outcome_access($pdo,$viewer,$publicId)??[];
}

function research_outcome_manual(PDO $pdo,array $viewer,array $input): array {
    $projectPublic=trim((string)($input['project_id']??''));$objectType=trim((string)($input['object_type']??''));$objectPublic=trim((string)($input['object_public_id']??''));if(($objectType==='')!==($objectPublic===''))throw new InvalidArgumentException('Choose both a linked object type and object ID, or leave both blank.');$decision=trim((string)($input['decision_type']??'recorded'));if(!isset(research_outcome_decision_labels()[$decision]))throw new InvalidArgumentException('Invalid decision type.');
    return research_outcome_record($pdo,$viewer,['event_type'=>'manual_decision','decision_type'=>$decision,'source_type'=>'manual','source_public_id'=>ulid_like(),'project_public_id'=>$projectPublic,'object_type'=>$objectType,'object_public_id'=>$objectPublic,'title'=>(string)($input['title']??'Decision recorded'),'summary'=>(string)($input['summary']??''),'note'=>(string)($input['note']??''),'is_manual'=>true,'occurred_at'=>date('Y-m-d H:i:s'),'dedupe_key'=>'manual:'.ulid_like()]);
}

function research_outcome_sync_agent_actions(PDO $pdo,array $viewer,int $limit=300): int {
    if(!agent_actions_ready($pdo))return 0;$q=$pdo->prepare("SELECT aap.*,rp.public_id project_public_id FROM agent_action_proposals aap JOIN research_projects rp ON rp.id=aap.project_id WHERE aap.proposed_by_user_id=? AND aap.status IN ('executed','rejected','stale','failed') ORDER BY aap.updated_at DESC LIMIT ".$limit);$q->execute([$viewer['id']]);$n=0;
    foreach($q->fetchAll() as $p){$decision=(string)$p['status'];$title=match($decision){'executed'=>'Agent proposal executed','rejected'=>'Agent proposal rejected','stale'=>'Agent proposal became stale',default=>'Agent proposal failed'};$refs=[['type'=>'agent_action','public_id'=>$p['public_id'],'role'=>'source']];if(!empty($p['result_type'])&&!empty($p['result_public_id']))$refs[]=['type'=>(string)$p['result_type'],'public_id'=>(string)$p['result_public_id'],'role'=>'result'];
        research_outcome_record($pdo,$viewer,['event_type'=>'agent_action','decision_type'=>$decision,'source_type'=>'agent_action','source_public_id'=>$p['public_id'],'project_public_id'=>$p['project_public_id'],'object_type'=>'agent_action','object_public_id'=>$p['public_id'],'result_type'=>$p['result_type'],'result_public_id'=>$p['result_public_id'],'title'=>$title,'summary'=>'Capability: '.(string)$p['capability_key'],'metadata'=>['capability_key'=>$p['capability_key'],'status'=>$p['status']],'refs'=>$refs,'occurred_at'=>$p['updated_at'],'dedupe_key'=>'agent_action:'.$p['public_id'].':'.$p['status']]);$n++;
    }return $n;
}

function research_outcome_sync_automations(PDO $pdo,array $viewer,int $limit=300): int {
    if(!research_automation_ready($pdo))return 0;$q=$pdo->prepare("SELECT rar.*,ra.public_id automation_public_id,ra.title automation_title,rp.public_id project_public_id FROM research_automation_runs rar JOIN research_automations ra ON ra.id=rar.automation_id JOIN research_projects rp ON rp.id=rar.project_id WHERE rar.user_id=? AND rar.status IN ('completed','failed','skipped') ORDER BY rar.id DESC LIMIT ".$limit);$q->execute([$viewer['id']]);$n=0;
    foreach($q->fetchAll() as $r){$decision=(string)$r['status'];research_outcome_record($pdo,$viewer,['event_type'=>'automation_run','decision_type'=>$decision,'source_type'=>'automation_run','source_public_id'=>$r['public_id'],'project_public_id'=>$r['project_public_id'],'object_type'=>'automation','object_public_id'=>$r['automation_public_id'],'title'=>'Automation '.ucfirst($decision).' · '.$r['automation_title'],'summary'=>mb_substr((string)($r['output_text']?:$r['last_error']?:''),0,1200),'metadata'=>['proposal_count'=>(int)$r['proposal_count'],'trigger_type'=>$r['trigger_type']],'refs'=>[['type'=>'automation','public_id'=>$r['automation_public_id'],'role'=>'source'],['type'=>'automation_run','public_id'=>$r['public_id'],'role'=>'result']],'occurred_at'=>$r['completed_at']?:$r['created_at'],'dedupe_key'=>'automation_run:'.$r['public_id'].':'.$r['status']]);$n++;}return $n;
}

function research_outcome_sync_cross_research(PDO $pdo,array $viewer,int $limit=300): int {
    if(!cross_research_ready($pdo))return 0;$q=$pdo->prepare("SELECT crd.*,crl.public_id link_public_id,sp.public_id source_project_public_id,tp.public_id target_project_public_id,crl.relation_type FROM cross_research_decisions crd LEFT JOIN cross_research_links crl ON crl.public_id=crd.link_public_id LEFT JOIN research_projects sp ON sp.id=crl.source_project_id LEFT JOIN research_projects tp ON tp.id=crl.target_project_id WHERE crd.user_id=? ORDER BY crd.updated_at DESC LIMIT ".$limit);$q->execute([$viewer['id']]);$n=0;
    foreach($q->fetchAll() as $d){$refs=[];$projectPublic='';if(!empty($d['source_project_public_id'])){$projectPublic=$d['source_project_public_id'];$refs[]=['type'=>'project','public_id'=>$d['source_project_public_id'],'role'=>'context'];}if(!empty($d['target_project_public_id']))$refs[]=['type'=>'project','public_id'=>$d['target_project_public_id'],'role'=>'context'];if(!empty($d['link_public_id']))$refs[]=['type'=>'cross_research_link','public_id'=>$d['link_public_id'],'role'=>'result'];research_outcome_record($pdo,$viewer,['event_type'=>'cross_research','decision_type'=>$d['decision'],'source_type'=>'cross_research_suggestion','source_public_id'=>$d['suggestion_key'],'project_public_id'=>$projectPublic,'object_type'=>'cross_research_suggestion','object_public_id'=>$d['suggestion_key'],'result_type'=>!empty($d['link_public_id'])?'cross_research_link':'','result_public_id'=>$d['link_public_id'],'title'=>$d['decision']==='accepted'?'Cross-Research relationship accepted':'Cross-Research suggestion dismissed','summary'=>!empty($d['relation_type'])?'Relationship: '.$d['relation_type']:'','refs'=>$refs,'occurred_at'=>$d['updated_at'],'dedupe_key'=>'cross_research:'.$d['suggestion_key'].':'.$d['decision']]);$n++;}return $n;
}

function research_outcome_sync_dismissals(PDO $pdo,array $viewer,int $limit=300): int {
    if(!cognitive_feed_ready($pdo))return 0;$q=$pdo->prepare("SELECT observation_key,observation_type,dismissed_at FROM cognitive_feed_dismissals WHERE user_id=? ORDER BY dismissed_at DESC LIMIT ".$limit);$q->execute([$viewer['id']]);$n=0;foreach($q->fetchAll() as $d){research_outcome_record($pdo,$viewer,['event_type'=>'cognitive_feed','decision_type'=>'dismissed','source_type'=>'cognitive_observation','source_public_id'=>$d['observation_key'],'object_type'=>'cognitive_observation','object_public_id'=>$d['observation_key'],'title'=>'Cognitive Feed item dismissed','summary'=>'Observation type: '.$d['observation_type'],'occurred_at'=>$d['dismissed_at'],'dedupe_key'=>'cognitive_dismissal:'.$d['observation_key']]);$n++;}return $n;
}

function research_outcome_sync(PDO $pdo,array $viewer): array {
    if(!research_outcomes_ready($pdo))return ['ready'=>false,'recorded'=>0];$counts=['agent_actions'=>research_outcome_sync_agent_actions($pdo,$viewer),'automations'=>research_outcome_sync_automations($pdo,$viewer),'cross_research'=>research_outcome_sync_cross_research($pdo,$viewer),'dismissals'=>research_outcome_sync_dismissals($pdo,$viewer)];$q=$pdo->prepare('SELECT COUNT(*) FROM research_outcome_events WHERE user_id=?');$q->execute([$viewer['id']]);return ['ready'=>true,'recorded'=>(int)$q->fetchColumn(),'sources'=>$counts];
}

function research_outcome_summary(PDO $pdo,array $viewer,?string $projectPublic=null): array {
    $items=research_outcome_list($pdo,$viewer,$projectPublic,null,300);$counts=[];$sourceCounts=[];$helpful=0;$notHelpful=0;$follow=0;$reopened=0;foreach($items as $r){$counts[$r['decision_type']]=($counts[$r['decision_type']]??0)+1;$sourceCounts[$r['source_type']]=($sourceCounts[$r['source_type']]??0)+1;if(($r['usefulness']??'')==='helpful')$helpful++;if(($r['usefulness']??'')==='not_helpful')$notHelpful++;if(($r['follow_up_state']??'')==='follow_up')$follow++;if(($r['follow_up_state']??'')==='reopened')$reopened++;}
    arsort($counts);arsort($sourceCounts);return ['total'=>count($items),'decisions'=>$counts,'sources'=>$sourceCounts,'helpful'=>$helpful,'not_helpful'=>$notHelpful,'follow_up'=>$follow,'reopened'=>$reopened];
}

function research_outcome_input_hash(PDO $pdo,array $viewer,string $projectPublic): string {
    $items=research_outcome_list($pdo,$viewer,$projectPublic,null,300);$rows=[];foreach($items as $r)$rows[]=[(string)$r['public_id'],(string)$r['decision_type'],(string)$r['updated_at'],(string)($r['usefulness']??''),(string)($r['follow_up_state']??''),(string)($r['feedback_comment']??'')];return hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function research_outcome_context(PDO $pdo,array $viewer,string $projectPublic,int $limit=15): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)return ['text'=>'','refs'=>[]];$items=research_outcome_list($pdo,$viewer,$projectPublic,null,$limit);if(!$items)return ['text'=>'','refs'=>[]];$lines=['[DECISION MEMORY / OUTCOMES]'];$refs=[];
    foreach($items as $r){$line=$r['occurred_at'].' · '.strtoupper((string)$r['decision_type']).' · '.$r['title'];if(!empty($r['summary']))$line.=' — '.mb_substr((string)$r['summary'],0,300);if(!empty($r['feedback_comment']))$line.=' · Feedback: '.mb_substr((string)$r['feedback_comment'],0,200);$lines[]=$line;foreach((array)$r['refs'] as $ref)$refs[]=['type'=>$ref['ref_type'],'id'=>$ref['ref_public_id']];}
    return ['text'=>implode("\n",$lines),'refs'=>$refs];
}

function research_outcome_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=12): void {
    if(!research_outcomes_ready($pdo))return;$q=$pdo->prepare("SELECT roe.public_id FROM research_outcome_events roe JOIN research_outcome_feedback rof ON rof.outcome_id=roe.id AND rof.user_id=roe.user_id WHERE roe.user_id=? AND rof.follow_up_state IN ('follow_up','reopened') ORDER BY rof.updated_at DESC LIMIT ".$limit);$q->execute([$viewer['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$r=research_outcome_access($pdo,$viewer,(string)$id);if(!$r)continue;$reopened=($r['follow_up_state']??'')==='reopened';$actions=[];if(!empty($r['project_public_id']))$actions[]=cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode((string)$r['project_public_id']));$actions[]=cognitive_feed_action_link('Decision Memory','/research-outcomes.php#outcome-'.rawurlencode((string)$r['public_id']));
        cognitive_feed_add($items,['key'=>cognitive_feed_key('outcome_follow_up','research_outcome',(string)$r['public_id'],(string)($r['feedback_updated_at']?:$r['updated_at'])),'type'=>'outcome_follow_up','section'=>'needs_attention','priority'=>$reopened?'high':'medium','created_at'=>(string)($r['feedback_updated_at']?:$r['updated_at']),'score_extra'=>$reopened?10:4,'title'=>$reopened?'Decision reopened: '.$r['title']:'Follow-up requested: '.$r['title'],'body'=>$r['summary']?:'This explicit outcome was marked for follow-up.','meta'=>['decision_type'=>$r['decision_type'],'source_type'=>$r['source_type']],'actions'=>$actions]);}
}
