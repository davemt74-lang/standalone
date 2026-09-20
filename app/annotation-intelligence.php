<?php
declare(strict_types=1);
require_once __DIR__.'/ai.php';

function annotation_intelligence_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'annotation_intelligence')&&installer_table_exists($pdo,'annotation_relationships');}
    catch(Throwable $e){return false;}
}

function annotation_intelligence_source_row(PDO $pdo,int $annotationId): ?array {
    $q=$pdo->prepare("SELECT a.id,a.public_id,a.user_id,a.visibility,a.team_id,a.status,a.text_commentary,a.source_id,a.source_version_id,
      c.selected_text,c.capture_type,c.media_title,c.media_author,
      COALESCE(at.edited_text,at.raw_text) transcript_text,
      s.public_id source_public_id,s.title source_title,s.domain,s.canonical_url,s.current_version_id
      FROM annotations a JOIN captures c ON c.id=a.capture_id JOIN sources s ON s.id=a.source_id
      LEFT JOIN annotation_transcripts at ON at.annotation_id=a.id
      WHERE a.id=? LIMIT 1");
    $q->execute([$annotationId]);return $q->fetch()?:null;
}

function annotation_intelligence_text(array $row): string {
    $parts=[];
    foreach([
      'Source title'=>(string)($row['source_title']??''),
      'Source domain'=>(string)($row['domain']??''),
      'Commentary'=>(string)($row['text_commentary']??''),
      'Captured text'=>(string)($row['selected_text']??''),
      'Transcript'=>(string)($row['transcript_text']??''),
      'Media title'=>(string)($row['media_title']??''),
      'Media author'=>(string)($row['media_author']??'')
    ] as $label=>$value){
        $value=trim($value);if($value!=='')$parts[]=$label.":\n".mb_substr($value,0,9000);
    }
    return trim(implode("\n\n",$parts));
}

function annotation_intelligence_input_hash(array $row): string {
    return hash('sha256',annotation_intelligence_text($row).'|'.(string)($row['source_version_id']??0));
}

function annotation_intelligence_has_analyzable_content(array $row): bool {
    foreach(['text_commentary','selected_text','transcript_text','media_title'] as $key)if(trim((string)($row[$key]??''))!=='')return true;
    return false;
}

function annotation_intelligence_terms(string $text): array {
    $text=mb_strtolower((string)preg_replace('/[^\pL\pN]+/u',' ',$text));
    $stop=array_flip(['the','and','that','this','with','from','have','has','for','are','was','were','but','not','you','your','they','their','its','into','about','would','could','should','will','can','than','then','when','where','what','which','who','why','how','our','out','all','any','some']);
    $terms=[];foreach(preg_split('/\s+/u',trim($text))?:[] as $term){if(mb_strlen($term)<3||isset($stop[$term]))continue;$terms[$term]=true;}
    return array_keys($terms);
}

function annotation_intelligence_similarity(string $a,string $b): float {
    $aa=annotation_intelligence_terms($a);$bb=annotation_intelligence_terms($b);if(!$aa||!$bb)return 0.0;
    $as=array_fill_keys($aa,true);$bs=array_fill_keys($bb,true);$intersection=count(array_intersect_key($as,$bs));$union=count($as+$bs);
    return $union?($intersection/$union):0.0;
}

function annotation_intelligence_candidate_rows(PDO $pdo,array $row,int $limit=24): array {
    $limit=max(1,min(40,$limit));$uid=(int)$row['user_id'];$team=(int)($row['team_id']??0);
    $sql="SELECT a.id,a.public_id,a.user_id,a.visibility,a.team_id,a.text_commentary,c.selected_text,
      COALESCE(at.edited_text,at.raw_text) transcript_text,s.public_id source_public_id,s.title source_title
      FROM annotations a JOIN captures c ON c.id=a.capture_id JOIN sources s ON s.id=a.source_id
      LEFT JOIN annotation_transcripts at ON at.annotation_id=a.id
      WHERE a.id<>? AND a.status='published'
        AND (a.user_id=? OR a.visibility='public'".($team>0?" OR (a.visibility='team' AND a.team_id=?)":"").")
      ORDER BY (a.source_id=? ) DESC,a.id DESC LIMIT $limit";
    $params=[(int)$row['id'],$uid];if($team>0)$params[]=$team;$params[]=(int)$row['source_id'];
    $q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll();
}

function annotation_intelligence_relation_upsert(PDO $pdo,int $sourceId,int $targetId,string $type,float $confidence,?string $rationale,string $generatedBy,?string $runPublicId=null,bool $symmetric=true): void {
    if($sourceId===$targetId)return;
    $allowed=['related','duplicate','corroborates','conflicts'];if(!in_array($type,$allowed,true))return;
    $confidence=max(0,min(1,$confidence));$rationale=$rationale!==null?mb_substr(trim($rationale),0,1000):null;
    $sql="INSERT INTO annotation_relationships(source_annotation_id,target_annotation_id,relation_type,confidence,rationale,generated_by,ai_run_public_id)
      VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE confidence=GREATEST(confidence,VALUES(confidence)),rationale=VALUES(rationale),generated_by=VALUES(generated_by),ai_run_public_id=VALUES(ai_run_public_id),updated_at=NOW()";
    $pdo->prepare($sql)->execute([$sourceId,$targetId,$type,$confidence,$rationale,$generatedBy,$runPublicId]);
    if($symmetric)$pdo->prepare($sql)->execute([$targetId,$sourceId,$type,$confidence,$rationale,$generatedBy,$runPublicId]);
}

function annotation_intelligence_seed_relationships(PDO $pdo,array $row): int {
    $sourceText=trim((string)($row['selected_text']??''));if($sourceText==='')$sourceText=annotation_intelligence_text($row);
    $normalized=normalize_match_text($sourceText);$created=0;
    foreach(annotation_intelligence_candidate_rows($pdo,$row) as $candidate){
        $candidateText=trim((string)($candidate['selected_text']??''));if($candidateText==='')$candidateText=trim((string)($candidate['text_commentary']??'').' '.(string)($candidate['transcript_text']??''));
        if($candidateText==='')continue;$candidateNormalized=normalize_match_text($candidateText);
        if(mb_strlen($normalized)>=80&&$normalized===$candidateNormalized){
            annotation_intelligence_relation_upsert($pdo,(int)$row['id'],(int)$candidate['id'],'duplicate',0.99,'Captured text is effectively identical.','deterministic');$created++;continue;
        }
        $sim=annotation_intelligence_similarity($sourceText,$candidateText);
        if($sim>=0.70){
            annotation_intelligence_relation_upsert($pdo,(int)$row['id'],(int)$candidate['id'],'related',min(0.95,max(0.70,$sim)),'High deterministic text/topic overlap.','deterministic');$created++;
        }
    }
    return $created;
}

function annotation_intelligence_queue(PDO $pdo,int $annotationId,?int $requestedByUserId=null,int $priority=5): bool {
    if(!annotation_intelligence_ready($pdo))return false;$row=annotation_intelligence_source_row($pdo,$annotationId);if(!$row||$row['status']!=='published'||!annotation_intelligence_has_analyzable_content($row))return false;
    $hash=annotation_intelligence_input_hash($row);
    $q=$pdo->prepare('SELECT status,input_hash FROM annotation_intelligence WHERE annotation_id=?');$q->execute([$annotationId]);$existing=$q->fetch();
    if($existing&&$existing['status']==='ready'&&hash_equals((string)$existing['input_hash'],$hash))return false;
    $changed=$existing&&!hash_equals((string)($existing['input_hash']??''),$hash);
    if($changed)$pdo->prepare("DELETE FROM annotation_relationships WHERE (source_annotation_id=? OR target_annotation_id=?)")->execute([$annotationId,$annotationId]);
    $pdo->prepare("INSERT INTO annotation_intelligence(annotation_id,status,input_hash,queued_at,last_error)
      VALUES(?,'pending',?,NOW(),NULL)
      ON DUPLICATE KEY UPDATE status=IF(input_hash=VALUES(input_hash) AND status='processing','processing','pending'),input_hash=VALUES(input_hash),queued_at=NOW(),last_error=NULL")->execute([$annotationId,$hash]);
    annotation_intelligence_seed_relationships($pdo,$row);
    $q=$pdo->prepare("SELECT 1 FROM ai_jobs WHERE task_type='annotation_intelligence' AND object_type='annotation' AND object_public_id=? AND status IN ('queued','processing') LIMIT 1");$q->execute([$row['public_id']]);
    if(!$q->fetchColumn())ai_queue_job($pdo,null,'annotation_intelligence',null,'annotation',(string)$row['public_id'],['input_hash'=>$hash],$priority);
    return true;
}

function annotation_intelligence_parse_json(string $text): array {
    $text=trim($text);if(str_starts_with($text,'```')){$text=(string)preg_replace('/^\`\`\`(?:json)?\s*/i','',$text);$text=(string)preg_replace('/\s*\`\`\`$/','',$text);}
    $data=json_decode($text,true);if(!is_array($data))throw new RuntimeException('Annotation intelligence model returned invalid JSON.');
    return $data;
}

function annotation_intelligence_apply_ai_output(PDO $pdo,string $annotationPublicId,string $output,string $runPublicId,int $modelId): array {
    $q=$pdo->prepare("SELECT a.id FROM annotations a WHERE a.public_id=? AND a.status='published' LIMIT 1");$q->execute([$annotationPublicId]);$annotationId=(int)($q->fetchColumn()?:0);if(!$annotationId)throw new RuntimeException('Annotation missing.');
    $row=annotation_intelligence_source_row($pdo,$annotationId);if(!$row)throw new RuntimeException('Annotation intelligence source missing.');
    $data=annotation_intelligence_parse_json($output);
    $summary=mb_substr(trim((string)($data['summary']??'')),0,1200);if($summary==='')throw new RuntimeException('Annotation intelligence summary is empty.');
    $topics=[];foreach(is_array($data['topics']??null)?$data['topics']:[] as $topic){$topic=mb_substr(trim((string)$topic),0,80);if($topic!==''&&!in_array($topic,$topics,true))$topics[]=$topic;if(count($topics)>=12)break;}
    $entities=[];foreach(is_array($data['entities']??null)?$data['entities']:[] as $entity){if(!is_array($entity))continue;$name=mb_substr(trim((string)($entity['name']??'')),0,160);$type=mb_substr(trim((string)($entity['type']??'entity')),0,60);if($name!=='')$entities[]=['name'=>$name,'type'=>$type?:'entity'];if(count($entities)>=20)break;}
    $claims=[];foreach(is_array($data['claims']??null)?$data['claims']:[] as $claim){if(!is_array($claim))continue;$statement=mb_substr(trim((string)($claim['statement']??'')),0,1000);$certainty=in_array((string)($claim['certainty']??''),['explicit','inferred'],true)?(string)$claim['certainty']:'inferred';if($statement!=='')$claims[]=['statement'=>$statement,'certainty'=>$certainty];if(count($claims)>=12)break;}
    $confidence=max(0,min(1,(float)($data['confidence']??0.65)));$hash=annotation_intelligence_input_hash($row);$storedModelId=$modelId>0?$modelId:null;
    $pdo->prepare("INSERT INTO annotation_intelligence(annotation_id,status,input_hash,summary,topics_json,entities_json,claims_json,confidence,model_id,ai_run_public_id,prompt_version,last_error,processed_at)
      VALUES(?,'ready',?,?,?,?,?,?,?,?,'phase13-v1',NULL,NOW())
      ON DUPLICATE KEY UPDATE status='ready',input_hash=VALUES(input_hash),summary=VALUES(summary),topics_json=VALUES(topics_json),entities_json=VALUES(entities_json),claims_json=VALUES(claims_json),confidence=VALUES(confidence),model_id=VALUES(model_id),ai_run_public_id=VALUES(ai_run_public_id),prompt_version='phase13-v1',last_error=NULL,processed_at=NOW()")
      ->execute([$annotationId,$hash,$summary,json_encode($topics,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($entities,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($claims,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$confidence,$storedModelId,$runPublicId]);
    $pdo->prepare("DELETE FROM annotation_relationships WHERE generated_by='ai' AND (source_annotation_id=? OR target_annotation_id=?)")->execute([$annotationId,$annotationId]);
    $candidates=annotation_intelligence_candidate_rows($pdo,$row,40);$allowed=[];foreach($candidates as $candidate)$allowed[(string)$candidate['public_id']]=$candidate;
    foreach(is_array($data['relationships']??null)?$data['relationships']:[] as $rel){
        if(!is_array($rel))continue;$target=(string)($rel['annotation_id']??'');$type=(string)($rel['type']??'related');if(!isset($allowed[$target])||!in_array($type,['related','duplicate','corroborates','conflicts'],true))continue;
        annotation_intelligence_relation_upsert($pdo,$annotationId,(int)$allowed[$target]['id'],$type,(float)($rel['confidence']??0.6),(string)($rel['rationale']??''),'ai',$runPublicId);
    }
    annotation_intelligence_seed_relationships($pdo,$row);
    return ['annotation_id'=>$annotationPublicId,'summary'=>$summary,'topics'=>$topics,'entities'=>$entities,'claims'=>$claims,'confidence'=>$confidence];
}

function annotation_intelligence_mark_processing(PDO $pdo,string $annotationPublicId): void {
    $pdo->prepare("UPDATE annotation_intelligence ai JOIN annotations a ON a.id=ai.annotation_id SET ai.status='processing',ai.last_error=NULL WHERE a.public_id=?")->execute([$annotationPublicId]);
}
function annotation_intelligence_mark_failed(PDO $pdo,string $annotationPublicId,string $message): void {
    $pdo->prepare("UPDATE annotation_intelligence ai JOIN annotations a ON a.id=ai.annotation_id SET ai.status='failed',ai.last_error=? WHERE a.public_id=?")->execute([mb_substr($message,0,1000),$annotationPublicId]);
}

function annotation_intelligence_record(PDO $pdo,int $annotationId): ?array {
    if(!annotation_intelligence_ready($pdo))return null;$q=$pdo->prepare('SELECT * FROM annotation_intelligence WHERE annotation_id=? LIMIT 1');$q->execute([$annotationId]);$r=$q->fetch();if(!$r)return null;
    foreach(['topics_json'=>'topics','entities_json'=>'entities','claims_json'=>'claims'] as $json=>$key){$decoded=json_decode((string)($r[$json]??''),true);$r[$key]=is_array($decoded)?$decoded:[];unset($r[$json]);}
    $r['confidence']=$r['confidence']!==null?(float)$r['confidence']:null;return $r;
}

function annotation_intelligence_visible_relationships(PDO $pdo,int $annotationId,?array $viewer,int $limit=8): array {
    if(!annotation_intelligence_ready($pdo))return [];$limit=max(1,min(20,$limit));
    $q=$pdo->prepare("SELECT ar.relation_type,ar.confidence,ar.rationale,ar.generated_by,a.public_id,a.text_commentary,c.selected_text,s.title source_title,s.domain
      FROM annotation_relationships ar JOIN annotations a ON a.id=ar.target_annotation_id JOIN captures c ON c.id=a.capture_id JOIN sources s ON s.id=a.source_id
      WHERE ar.source_annotation_id=? ORDER BY FIELD(ar.relation_type,'conflicts','corroborates','duplicate','related'),ar.confidence DESC LIMIT 30");
    $q->execute([$annotationId]);$out=[];
    foreach($q->fetchAll() as $row){$access=annotation_access($pdo,(string)$row['public_id'],$viewer);if(!$access||$access['status']!=='published')continue;$row['confidence']=(float)$row['confidence'];$out[]=$row;if(count($out)>=$limit)break;}
    return $out;
}

function annotation_intelligence_attach(PDO $pdo,array $annotation,?array $viewer=null): array {
    $id=(int)($annotation['id']??$annotation['internal_id']??0);if(!$id){$q=$pdo->prepare('SELECT id FROM annotations WHERE public_id=?');$q->execute([(string)($annotation['public_id']??'')]);$id=(int)($q->fetchColumn()?:0);}
    if(!$id){$annotation['intelligence']=null;return $annotation;}
    $record=annotation_intelligence_record($pdo,$id);if($record){$record['relationships']=annotation_intelligence_visible_relationships($pdo,$id,$viewer,8);}$annotation['intelligence']=$record;return $annotation;
}

function annotation_intelligence_context_text(PDO $pdo,string $annotationPublicId,?array $viewer): string {
    $access=annotation_access($pdo,$annotationPublicId,$viewer);if(!$access)return '';$record=annotation_intelligence_record($pdo,(int)$access['id']);if(!$record||($record['status']??'')!=='ready')return '';
    $lines=['Derived annotation intelligence (AI-assisted; captured evidence remains authoritative):','Summary: '.(string)$record['summary']];
    if($record['topics'])$lines[]='Topics: '.implode(', ',array_map('strval',$record['topics']));
    if($record['entities'])$lines[]='Entities: '.implode(', ',array_map(fn($e)=>(string)($e['name']??''),$record['entities']));
    foreach(array_slice($record['claims'],0,6) as $claim)$lines[]='Claim ('.($claim['certainty']??'inferred').'): '.($claim['statement']??'');
    foreach(annotation_intelligence_visible_relationships($pdo,(int)$access['id'],$viewer,5) as $rel)$lines[]='Relationship: '.($rel['relation_type']??'related').' [ANNOTATION '.($rel['public_id']??'').'] confidence '.number_format((float)($rel['confidence']??0),2);
    return implode("\n",$lines);
}
