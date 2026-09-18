<?php
declare(strict_types=1);

function source_integrity_label(string $state): string {
    return match($state){
        'source_unchanged'=>'Source unchanged',
        'source_updated'=>'Source updated',
        'passage_changed'=>'Referenced passage changed',
        'passage_missing'=>'Referenced passage missing',
        'source_unavailable'=>'Source unavailable',
        'source_restored'=>'Source restored',
        default=>'Source status unknown',
    };
}
function source_integrity_priority(string $state): int {
    return match($state){
        'source_unavailable'=>60,'passage_missing'=>50,'passage_changed'=>40,'source_restored'=>30,'source_updated'=>20,'source_unchanged'=>10,default=>0,
    };
}
function source_integrity_significant_tokens(string $text): array {
    $parts=preg_split('/[^\pL\pN]+/u',mb_strtolower($text))?:[];$out=[];
    foreach($parts as $p){if(mb_strlen($p)>=4)$out[$p]=true;if(count($out)>=80)break;}
    return array_keys($out);
}
function source_integrity_excerpt(string $selected,string $newText): ?string {
    $tokens=source_integrity_significant_tokens($selected);foreach($tokens as $token){$pos=mb_stripos($newText,$token);if($pos!==false){$start=max(0,$pos-180);return trim(mb_substr($newText,$start,460));}}
    return null;
}
function source_integrity_classify(string $selected,string $newText,string $eventType): array {
    if($eventType==='unavailable')return ['impact_type'=>'source_unavailable','current_excerpt'=>null];
    if($eventType==='restored')return ['impact_type'=>'source_restored','current_excerpt'=>source_integrity_excerpt($selected,$newText)];
    $needle=normalize_match_text($selected);$hay=normalize_match_text($newText);
    if($needle===''||$hay==='')return ['impact_type'=>'source_updated','current_excerpt'=>null];
    if(str_contains($hay,$needle))return ['impact_type'=>'source_updated','current_excerpt'=>mb_substr($selected,0,1200)];
    $tokens=source_integrity_significant_tokens($selected);if(!$tokens)return ['impact_type'=>'passage_missing','current_excerpt'=>null];
    $matched=0;foreach($tokens as $token)if(str_contains($hay,$token))$matched++;
    $ratio=$matched/max(1,count($tokens));
    return $ratio>=0.45
        ?['impact_type'=>'passage_changed','current_excerpt'=>source_integrity_excerpt($selected,$newText)]
        :['impact_type'=>'passage_missing','current_excerpt'=>null];
}
function source_integrity_analyze_event(PDO $pdo,int $eventId): array {
    $q=$pdo->prepare('SELECT sce.*,nv.extracted_text new_text,s.public_id source_public_id FROM source_change_events sce JOIN sources s ON s.id=sce.source_id JOIN source_versions nv ON nv.id=sce.new_version_id WHERE sce.id=? LIMIT 1');$q->execute([$eventId]);$event=$q->fetch();if(!$event)throw new RuntimeException('Source change event not found.');
    $newText=(string)($event['new_text']??'');$eventType=(string)$event['change_type'];
    $q=$pdo->prepare("SELECT a.id,a.public_id,a.user_id,a.visibility,a.team_id,c.selected_text FROM annotations a JOIN captures c ON c.id=a.capture_id WHERE a.source_id=? AND a.source_version_id<>? AND a.status IN ('published','restricted') ORDER BY a.id");
    $q->execute([$event['source_id'],$event['new_version_id']]);$annotations=$q->fetchAll();$strongest=$eventType==='unavailable'?'source_unavailable':($eventType==='restored'?'source_restored':'source_updated');$affected=0;$impacts=[];
    foreach($annotations as $a){
        $classified=source_integrity_classify((string)($a['selected_text']??''),$newText,$eventType);$impact=$classified['impact_type'];
        if(in_array($impact,['passage_changed','passage_missing','source_unavailable','source_restored'],true))$affected++;
        if(source_integrity_priority($impact)>source_integrity_priority($strongest))$strongest=$impact;
        $pdo->prepare('INSERT INTO source_annotation_impacts(source_change_event_id,annotation_id,impact_type,previous_excerpt,current_excerpt) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE impact_type=VALUES(impact_type),previous_excerpt=VALUES(previous_excerpt),current_excerpt=VALUES(current_excerpt)')
            ->execute([$eventId,$a['id'],$impact,mb_substr((string)($a['selected_text']??''),0,4000)?:null,$classified['current_excerpt']]);
        $impacts[]=['annotation_id'=>(int)$a['id'],'annotation_public_id'=>$a['public_id'],'user_id'=>(int)$a['user_id'],'visibility'=>$a['visibility'],'team_id'=>$a['team_id']?(int)$a['team_id']:null,'impact_type'=>$impact];
    }
    $pdo->prepare('UPDATE source_change_events SET impact_type=?,affected_annotation_count=?,target_changed=? WHERE id=?')->execute([$strongest,$affected,in_array($strongest,['passage_changed','passage_missing'],true)?1:0,$eventId]);
    return ['event_id'=>$eventId,'source_id'=>(int)$event['source_id'],'source_public_id'=>$event['source_public_id'],'impact_type'=>$strongest,'affected_annotation_count'=>$affected,'impacts'=>$impacts];
}
function source_integrity_recipients(PDO $pdo,int $sourceId,array $impacts): array {
    $ids=[];
    foreach($impacts as $i)$ids[(int)$i['user_id']]=true;
    $q=$pdo->prepare('SELECT user_id FROM source_watches WHERE source_id=?');$q->execute([$sourceId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[(int)$id]=true;
    $q=$pdo->prepare('SELECT DISTINCT rp.owner_user_id,rp.team_id FROM project_sources ps JOIN research_projects rp ON rp.id=ps.project_id WHERE ps.source_id=? UNION SELECT DISTINCT rp.owner_user_id,rp.team_id FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id JOIN research_projects rp ON rp.id=pa.project_id WHERE a.source_id=?');
    $q->execute([$sourceId,$sourceId]);foreach($q->fetchAll() as $p){$ids[(int)$p['owner_user_id']]=true;if($p['team_id']){$mq=$pdo->prepare('SELECT user_id FROM team_members WHERE team_id=?');$mq->execute([$p['team_id']]);foreach($mq->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[(int)$id]=true;}}
    foreach($impacts as $i)if($i['visibility']==='team'&&!empty($i['team_id'])){$mq=$pdo->prepare('SELECT user_id FROM team_members WHERE team_id=?');$mq->execute([$i['team_id']]);foreach($mq->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[(int)$id]=true;}
    return array_keys($ids);
}
function source_integrity_notify_event(PDO $pdo,int $eventId): int {
    $q=$pdo->prepare('SELECT sce.*,s.public_id source_public_id,s.title,s.domain FROM source_change_events sce JOIN sources s ON s.id=sce.source_id WHERE sce.id=?');$q->execute([$eventId]);$event=$q->fetch();if(!$event)return 0;
    $q=$pdo->prepare('SELECT sai.*,a.user_id,a.public_id annotation_public_id,a.visibility,a.team_id FROM source_annotation_impacts sai JOIN annotations a ON a.id=sai.annotation_id WHERE sai.source_change_event_id=?');$q->execute([$eventId]);$impacts=$q->fetchAll();
    $recipients=source_integrity_recipients($pdo,(int)$event['source_id'],$impacts);$sent=0;
    foreach($recipients as $userId){
        $state=(string)($event['impact_type']?:'source_updated');$annotationPublic=null;
        foreach($impacts as $i)if((int)$i['user_id']===(int)$userId&&source_integrity_priority($i['impact_type'])>source_integrity_priority($state)){$state=$i['impact_type'];$annotationPublic=$i['annotation_public_id'];}
        foreach($impacts as $i)if((int)$i['user_id']===(int)$userId&&source_integrity_priority($i['impact_type'])>=source_integrity_priority($state)){$state=$i['impact_type'];$annotationPublic=$i['annotation_public_id'];}
        $label=source_integrity_label($state);$title=(string)($event['title']?:$event['domain']?:'a watched source');
        $body=$label.' on '.$title.'.'.((int)$event['affected_annotation_count']>0?' '.(int)$event['affected_annotation_count'].' annotation'.((int)$event['affected_annotation_count']===1?'':'s').' affected.':'');
        $ok=notification_create($pdo,(int)$userId,null,'source_integrity','source',$event['source_public_id'],$body,[
            'category'=>'sources','dedupe_key'=>'source-change:'.$eventId,'group_key'=>'source:'.$event['source_public_id'],
            'context'=>['source_public_id'=>$event['source_public_id'],'source_change_event_id'=>$eventId,'integrity_state'=>$state,'annotation_public_id'=>$annotationPublic]
        ]);if($ok)$sent++;
    }
    return $sent;
}
function source_integrity_annotation_state(PDO $pdo,array $annotation): array {
    $id=(int)$annotation['id'];$sourceVersionId=(int)($annotation['source_version_id']??0);$currentVersionId=(int)($annotation['current_source_version_id']??$annotation['current_version_id']??0);
    $q=$pdo->prepare('SELECT sai.impact_type,sai.current_excerpt,sce.id event_id,sce.diff_summary,sce.created_at,pv.version_number previous_version_number,nv.version_number new_version_number FROM source_annotation_impacts sai JOIN source_change_events sce ON sce.id=sai.source_change_event_id LEFT JOIN source_versions pv ON pv.id=sce.previous_version_id JOIN source_versions nv ON nv.id=sce.new_version_id WHERE sai.annotation_id=? ORDER BY sce.id DESC LIMIT 1');
    $q->execute([$id]);$row=$q->fetch();
    if($row){$row['label']=source_integrity_label($row['impact_type']);return $row;}
    $state=$currentVersionId&&$sourceVersionId&&$currentVersionId!==$sourceVersionId?'source_updated':'source_unchanged';
    return ['impact_type'=>$state,'label'=>source_integrity_label($state),'event_id'=>null,'diff_summary'=>null,'current_excerpt'=>null];
}
function source_integrity_source_timeline(PDO $pdo,int $sourceId,int $limit=30): array {
    $q=$pdo->prepare('SELECT sce.*,pv.version_number previous_version_number,nv.version_number new_version_number FROM source_change_events sce LEFT JOIN source_versions pv ON pv.id=sce.previous_version_id JOIN source_versions nv ON nv.id=sce.new_version_id WHERE sce.source_id=? ORDER BY sce.id DESC LIMIT '.max(1,min(100,$limit)));$q->execute([$sourceId]);$rows=$q->fetchAll();
    foreach($rows as &$r){$r['impact_type']=$r['impact_type']?:match($r['change_type']){'unavailable'=>'source_unavailable','restored'=>'source_restored',default=>($r['target_changed']?'passage_changed':'source_updated')};$r['label']=source_integrity_label($r['impact_type']);}unset($r);return $rows;
}
