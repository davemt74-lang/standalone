<?php
declare(strict_types=1);

function research_entity_types(): array {return ['person','company','organization','place','event','topic','date','other'];}
function research_entity_relation_types(): array {return ['related_to','part_of','located_in','works_for','owns','involved_in','occurred_at','associated_with'];}
function research_entity_normalized_name(string $name): string {
    $name=trim(preg_replace('/\s+/u',' ',$name)??$name);
    return mb_substr(mb_strtolower($name),0,255);
}

function research_entity_access(PDO $pdo,array $user,string $publicId): ?array {
    $q=$pdo->prepare('SELECT re.*,rp.public_id project_public_id,rp.title project_title FROM research_entities re JOIN research_projects rp ON rp.id=re.project_id WHERE re.public_id=? LIMIT 1');
    $q->execute([$publicId]);$entity=$q->fetch();if(!$entity)return null;
    $project=project_access($pdo,(int)$user['id'],(string)$entity['project_public_id']);if(!$project)return null;
    $entity['access_role']=$project['access_role'];return $entity;
}

function research_project_entity_rows(PDO $pdo,int $projectId): array {
    $q=$pdo->prepare("SELECT re.*,
      (SELECT COUNT(*) FROM research_entity_mentions rem WHERE rem.entity_id=re.id) mention_count,
      (SELECT COUNT(*) FROM research_entity_relations rr WHERE rr.source_entity_id=re.id OR rr.target_entity_id=re.id) relation_count
      FROM research_entities re WHERE re.project_id=? AND re.status<>'archived'
      ORDER BY FIELD(re.status,'suggested','confirmed'),re.entity_type,re.canonical_name");
    $q->execute([$projectId]);return $q->fetchAll();
}

function research_entity_mentions(PDO $pdo,int $projectId,int $entityId): array {
    $q=$pdo->prepare("SELECT rem.*,sv.version_number,sv.captured_at,s.public_id source_public_id,s.title source_title,s.canonical_url,
      a.public_id annotation_public_id,a.text_commentary,
      rc.public_id claim_public_id,rc.statement claim_statement,
      rf.public_id finding_public_id,rf.title finding_title
      FROM research_entity_mentions rem
      LEFT JOIN source_versions sv ON sv.id=rem.source_version_id
      LEFT JOIN sources s ON s.id=sv.source_id
      LEFT JOIN annotations a ON a.id=rem.annotation_id
      LEFT JOIN research_claims rc ON rc.id=rem.claim_id
      LEFT JOIN research_findings rf ON rf.id=rem.finding_id
      WHERE rem.project_id=? AND rem.entity_id=? ORDER BY rem.created_at DESC");
    $q->execute([$projectId,$entityId]);return $q->fetchAll();
}

function research_entity_relation_rows(PDO $pdo,int $projectId,?int $entityId=null): array {
    $sql="SELECT rr.*,se.public_id source_public_id,se.canonical_name source_name,se.entity_type source_type,
      te.public_id target_public_id,te.canonical_name target_name,te.entity_type target_type
      FROM research_entity_relations rr
      JOIN research_entities se ON se.id=rr.source_entity_id
      JOIN research_entities te ON te.id=rr.target_entity_id
      WHERE rr.project_id=?";
    $params=[$projectId];if($entityId!==null){$sql.=' AND (rr.source_entity_id=? OR rr.target_entity_id=?)';$params[]=$entityId;$params[]=$entityId;}
    $sql.=' ORDER BY rr.created_at DESC';$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll();
}

function research_project_entity_target(PDO $pdo,int $projectId,string $publicId): ?array {
    $q=$pdo->prepare("SELECT id,public_id,canonical_name,entity_type,status FROM research_entities WHERE project_id=? AND public_id=? AND status<>'archived' LIMIT 1");
    $q->execute([$projectId,$publicId]);return $q->fetch()?:null;
}

function research_project_entity_reference(PDO $pdo,int $projectId,string $type,string $publicId): ?array {
    if($type==='source_version'){
        $q=$pdo->prepare('SELECT s.current_version_id source_version_id,s.public_id,s.title,s.canonical_url,sv.version_number FROM project_sources ps JOIN sources s ON s.id=ps.source_id JOIN source_versions sv ON sv.id=s.current_version_id WHERE ps.project_id=? AND s.public_id=? LIMIT 1');
        $q->execute([$projectId,$publicId]);$r=$q->fetch();if(!$r)return null;return ['mention_type'=>'source_version','source_version_id'=>(int)$r['source_version_id'],'annotation_id'=>null,'claim_id'=>null,'finding_id'=>null,'label'=>(string)($r['title']?:$r['canonical_url']),'excerpt'=>null];
    }
    if($type==='annotation'){
        $q=$pdo->prepare("SELECT a.id annotation_id,a.source_version_id,a.text_commentary,a.public_id FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=? AND a.public_id=? AND a.status='published' LIMIT 1");
        $q->execute([$projectId,$publicId]);$r=$q->fetch();if(!$r)return null;return ['mention_type'=>'annotation','source_version_id'=>(int)$r['source_version_id'],'annotation_id'=>(int)$r['annotation_id'],'claim_id'=>null,'finding_id'=>null,'label'=>'Annotation '.$r['public_id'],'excerpt'=>mb_substr((string)$r['text_commentary'],0,1000)];
    }
    if($type==='claim'){
        $q=$pdo->prepare('SELECT id,public_id,statement FROM research_claims WHERE project_id=? AND public_id=? LIMIT 1');$q->execute([$projectId,$publicId]);$r=$q->fetch();if(!$r)return null;return ['mention_type'=>'claim','source_version_id'=>null,'annotation_id'=>null,'claim_id'=>(int)$r['id'],'finding_id'=>null,'label'=>'Claim '.$r['public_id'],'excerpt'=>mb_substr((string)$r['statement'],0,1000)];
    }
    if($type==='finding'){
        $q=$pdo->prepare('SELECT id,public_id,title,summary FROM research_findings WHERE project_id=? AND public_id=? LIMIT 1');$q->execute([$projectId,$publicId]);$r=$q->fetch();if(!$r)return null;return ['mention_type'=>'finding','source_version_id'=>null,'annotation_id'=>null,'claim_id'=>null,'finding_id'=>(int)$r['id'],'label'=>'Finding '.$r['title'],'excerpt'=>mb_substr((string)$r['summary'],0,1000)];
    }
    return null;
}

function research_entity_upsert(PDO $pdo,int $projectId,int $userId,string $name,string $type,?string $description,string $sourceType='manual',?string $aiRunPublicId=null): array {
    $name=trim($name);if($name==='')throw new RuntimeException('Entity name is required.');if(!in_array($type,research_entity_types(),true))$type='other';
    $normalized=research_entity_normalized_name($name);$q=$pdo->prepare('SELECT * FROM research_entities WHERE project_id=? AND entity_type=? AND normalized_name=? LIMIT 1');$q->execute([$projectId,$type,$normalized]);$existing=$q->fetch();
    if($existing){
        if($sourceType==='manual')$pdo->prepare("UPDATE research_entities SET status='confirmed',canonical_name=?,description=COALESCE(NULLIF(?,''),description),updated_at=NOW() WHERE id=?")->execute([$name,$description,(int)$existing['id']]);
        elseif($description&&!(string)$existing['description'])$pdo->prepare('UPDATE research_entities SET description=?,updated_at=NOW() WHERE id=?')->execute([$description,(int)$existing['id']]);
        $q=$pdo->prepare('SELECT * FROM research_entities WHERE id=?');$q->execute([(int)$existing['id']]);return $q->fetch();
    }
    $status=$sourceType==='ai'?'suggested':'confirmed';$public=ulid_like();$q=$pdo->prepare('INSERT INTO research_entities(public_id,project_id,created_by_user_id,entity_type,canonical_name,normalized_name,description,status,source_type,ai_run_public_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$public,$projectId,$userId,$type,mb_substr($name,0,255),$normalized,$description?:null,$status,$sourceType,$aiRunPublicId]);$q=$pdo->prepare('SELECT * FROM research_entities WHERE id=?');$q->execute([(int)$pdo->lastInsertId()]);return $q->fetch();
}

function research_entity_attach_reference(PDO $pdo,int $projectId,int $entityId,int $userId,string $type,string $publicId,?string $note=null): bool {
    $ref=research_project_entity_reference($pdo,$projectId,$type,$publicId);if(!$ref)return false;
    $targetColumn=['source_version'=>'source_version_id','annotation'=>'annotation_id','claim'=>'claim_id','finding'=>'finding_id'][$ref['mention_type']];$targetId=$ref[$targetColumn];
    $q=$pdo->prepare("SELECT id FROM research_entity_mentions WHERE entity_id=? AND mention_type=? AND $targetColumn=? LIMIT 1");$q->execute([$entityId,$ref['mention_type'],$targetId]);if($q->fetchColumn())return true;
    $q=$pdo->prepare('INSERT INTO research_entity_mentions(public_id,project_id,entity_id,added_by_user_id,mention_type,source_version_id,annotation_id,claim_id,finding_id,excerpt,note) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([ulid_like(),$projectId,$entityId,$userId,$ref['mention_type'],$ref['source_version_id'],$ref['annotation_id'],$ref['claim_id'],$ref['finding_id'],$ref['excerpt'],$note?:null]);return true;
}

function research_entity_graph_ai_context(PDO $pdo,int $projectId): array {
    $refs=[];$chunks=[];$entities=research_project_entity_rows($pdo,$projectId);
    foreach($entities as $e){
        $refs[]=['type'=>'entity','id'=>$e['public_id']];$mentions=[];foreach(research_entity_mentions($pdo,$projectId,(int)$e['id']) as $m){
            if($m['mention_type']==='source_version')$mentions[]='[SOURCE '.$m['source_public_id'].'] version '.$m['version_number'];
            elseif($m['mention_type']==='annotation')$mentions[]=$m['annotation_public_id']?'[ANNOTATION '.$m['annotation_public_id'].'] version '.$m['version_number']:'deleted annotation · [SOURCE '.$m['source_public_id'].'] version '.$m['version_number'];
            elseif($m['mention_type']==='claim')$mentions[]='[CLAIM '.$m['claim_public_id'].']';
            elseif($m['mention_type']==='finding')$mentions[]='[FINDING '.$m['finding_public_id'].']';
        }
        $chunks[]='[ENTITY '.$e['public_id']."]\nType: ".$e['entity_type']."\nStatus: ".$e['status']."\nName: ".$e['canonical_name'].($e['description']?"\nDescription: ".$e['description']:'').($mentions?"\nMentions: ".implode('; ',$mentions):'\nMentions: none');
    }
    foreach(research_entity_relation_rows($pdo,$projectId) as $r){$refs[]=['type'=>'entity_relation','id'=>$r['public_id']];$chunks[]='[ENTITY-RELATION '.$r['public_id'].'] [ENTITY '.$r['source_public_id'].'] '.$r['relation_type'].' [ENTITY '.$r['target_public_id'].']'.($r['note']?' · '.$r['note']:'');}
    return ['refs'=>$refs,'text'=>implode("\n\n",$chunks)];
}

function research_parse_entity_json(string $text): array {
    $text=trim($text);if(str_starts_with($text,'```')){$text=preg_replace('/^```(?:json)?\s*/i','',$text)??$text;$text=preg_replace('/\s*```$/','',$text)??$text;}
    $data=json_decode($text,true);if(!is_array($data))throw new RuntimeException('Entity extraction did not return valid JSON.');return $data;
}

function research_apply_ai_entities(PDO $pdo,int $projectId,int $userId,array $data,string $aiRunPublicId): array {
    $created=0;$linked=0;$relations=0;$map=[];
    foreach((array)($data['entities']??[]) as $item){if(!is_array($item))continue;$name=trim((string)($item['name']??''));$type=(string)($item['type']??'other');if($name===''||!in_array($type,research_entity_types(),true))continue;
        $entity=research_entity_upsert($pdo,$projectId,$userId,$name,$type,trim((string)($item['description']??''))?:null,'ai',$aiRunPublicId);$key=$type.'|'.research_entity_normalized_name($name);$map[$key]=$entity;if($entity['source_type']==='ai'&&$entity['ai_run_public_id']===$aiRunPublicId)$created++;
        foreach((array)($item['refs']??[]) as $ref){if(!is_array($ref))continue;$rt=strtolower((string)($ref['type']??''));$rid=trim((string)($ref['id']??''));$rt=['source'=>'source_version','annotation'=>'annotation','claim'=>'claim','finding'=>'finding'][$rt]??'';if($rt&&$rid&&research_entity_attach_reference($pdo,$projectId,(int)$entity['id'],$userId,$rt,$rid,'AI-suggested reference'))$linked++;}
    }
    foreach((array)($data['relationships']??[]) as $rel){if(!is_array($rel))continue;$st=(string)($rel['source_type']??'other');$tt=(string)($rel['target_type']??'other');$sk=$st.'|'.research_entity_normalized_name((string)($rel['source']??''));$tk=$tt.'|'.research_entity_normalized_name((string)($rel['target']??''));$type=(string)($rel['relation']??'related_to');if(!isset($map[$sk],$map[$tk])||!in_array($type,research_entity_relation_types(),true)||(int)$map[$sk]['id']===(int)$map[$tk]['id'])continue;
        $q=$pdo->prepare('INSERT IGNORE INTO research_entity_relations(public_id,project_id,source_entity_id,target_entity_id,added_by_user_id,relation_type,note) VALUES(?,?,?,?,?,?,?)');$q->execute([ulid_like(),$projectId,$map[$sk]['id'],$map[$tk]['id'],$userId,$type,trim((string)($rel['note']??''))?:null]);$relations+=$q->rowCount()>0?1:0;
    }
    return ['entities'=>$created,'mentions'=>$linked,'relations'=>$relations];
}
