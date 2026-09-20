<?php
declare(strict_types=1);

function cross_research_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'cross_research_links')&&installer_table_exists($pdo,'cross_research_decisions');}
    catch(Throwable $e){return false;}
}

function cross_research_relation_types(): array {
    return [
      'related'=>'Related','follow_up'=>'Follow-up','supports'=>'Supports','contradicts'=>'Contradicts',
      'duplicates'=>'Duplicates','refines'=>'Refines','depends_on'=>'Depends on','same_entity'=>'Same entity',
      'shared_source'=>'Shared source','shared_annotation'=>'Shared annotation','shared_evidence'=>'Shared evidence',
      'evidence_reuse'=>'Evidence reuse','competes'=>'Competes','derivative'=>'Derivative','context'=>'Context'
    ];
}

function cross_research_project_relation_types(): array {
    $all=cross_research_relation_types();$keys=['related','follow_up','supports','contradicts','depends_on','competes','derivative','context'];$out=[];foreach($keys as $k)$out[$k]=$all[$k];return $out;
}

function cross_research_object_access(PDO $pdo,array $viewer,string $type,string $publicId,?string $projectPublic=null): bool {
    $type=strtolower(trim($type));$publicId=trim($publicId);if($publicId==='')return false;
    if($projectPublic!==null&&$projectPublic!==''&&!project_access($pdo,(int)$viewer['id'],$projectPublic))return false;
    if($type==='project')return project_access($pdo,(int)$viewer['id'],$publicId)!==null;
    if($type==='source')return source_access($pdo,$publicId,$viewer)!==null;
    if($type==='annotation')return annotation_access($pdo,$publicId,$viewer)!==null;
    if($type==='entity')return function_exists('research_entity_access')&&research_entity_access($pdo,$viewer,$publicId)!==null;
    if($type==='claim'){$q=$pdo->prepare('SELECT rp.public_id FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id WHERE rc.public_id=? LIMIT 1');$q->execute([$publicId]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;}
    if($type==='finding'){$q=$pdo->prepare('SELECT rp.public_id FROM research_findings rf JOIN research_projects rp ON rp.id=rf.project_id WHERE rf.public_id=? LIMIT 1');$q->execute([$publicId]);$p=(string)($q->fetchColumn()?:'');return $p!==''&&project_access($pdo,(int)$viewer['id'],$p)!==null;}
    return false;
}

function cross_research_accessible_projects(PDO $pdo,array $viewer,int $limit=40): array {
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT DISTINCT rp.*,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role
      FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
      WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.updated_at DESC,rp.id DESC LIMIT ".$limit);
    $q->execute([$viewer['id'],$viewer['id'],$viewer['id'],$viewer['id']]);return $q->fetchAll();
}

function cross_research_project_maps(PDO $pdo,array $viewer,?string $focusPublic=null): array {
    $projects=cross_research_accessible_projects($pdo,$viewer,40);$byId=[];$byPublic=[];
    foreach($projects as $p){$byId[(int)$p['id']]=$p;$byPublic[(string)$p['public_id']]=$p;}
    $focus=null;if($focusPublic!==null&&trim($focusPublic)!==''){$focus=$byPublic[trim($focusPublic)]??null;if(!$focus)throw new RuntimeException('Research project not found or unavailable.');}
    return [$projects,$byId,$byPublic,$focus];
}

function cross_research_pair_allowed(?array $focus,int $a,int $b): bool {
    return $a!==$b&&(!$focus||(int)$focus['id']===$a||(int)$focus['id']===$b);
}

function cross_research_key(string $type,array $aProject,array $bProject,string $aObject,string $bObject,string $revision=''): string {
    $tuples=[(string)$aProject['public_id'].':'.$aObject,(string)$bProject['public_id'].':'.$bObject];sort($tuples,SORT_STRING);
    return hash('sha256',$type.'|'.$tuples[0].'|'.$tuples[1].'|'.$revision);
}

function cross_research_candidate(string $type,array $aProject,array $bProject,string $objectType,string $aObject,string $bObject,string $title,string $body,array $reasons,int $score,string $priority,string $suggestedRelation,string $createdAt,array $meta=[]): array {
    $revision=hash('sha256',json_encode([$body,$reasons,$meta],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return [
      'key'=>cross_research_key($type,$aProject,$bProject,$aObject,$bObject,$revision),
      'type'=>$type,'object_type'=>$objectType,'source_object_public_id'=>$aObject,'target_object_public_id'=>$bObject,
      'source_project_id'=>(int)$aProject['id'],'source_project_public_id'=>(string)$aProject['public_id'],'source_project_title'=>(string)$aProject['title'],
      'target_project_id'=>(int)$bProject['id'],'target_project_public_id'=>(string)$bProject['public_id'],'target_project_title'=>(string)$bProject['title'],
      'title'=>$title,'body'=>$body,'reasons'=>array_values(array_filter(array_map('trim',$reasons))),'score'=>max(0,min(100,$score)),
      'priority'=>$priority,'suggested_relation'=>$suggestedRelation,'created_at'=>$createdAt,'meta'=>$meta
    ];
}

function cross_research_add_candidate(array &$out,array $candidate): void {
    $key=(string)$candidate['key'];if(!isset($out[$key])||(int)$candidate['score']>(int)$out[$key]['score'])$out[$key]=$candidate;
}

function cross_research_claim_tokens(string $text): array {
    $text=mb_strtolower($text);$text=preg_replace('/[^\p{L}\p{N}]+/u',' ',$text)??'';$parts=preg_split('/\s+/u',trim($text))?:[];
    $stop=array_fill_keys(['the','a','an','and','or','of','to','in','on','for','with','by','as','at','is','are','was','were','be','been','that','this','it','from','has','have','had','will','would','can','could','may','might','than','then'],true);
    $tokens=[];foreach($parts as $p){if(mb_strlen($p)<3||isset($stop[$p]))continue;$tokens[$p]=true;}return array_keys($tokens);
}

function cross_research_claim_similarity(string $a,string $b): array {
    $ta=cross_research_claim_tokens($a);$tb=cross_research_claim_tokens($b);if(count($ta)<3||count($tb)<3)return ['score'=>0.0,'shared'=>[]];
    $sa=array_fill_keys($ta,true);$sb=array_fill_keys($tb,true);$shared=array_values(array_intersect($ta,$tb));$union=array_unique(array_merge($ta,$tb));
    $j=count($shared)/max(1,count($union));$contain=count($shared)/max(1,min(count($ta),count($tb)));$score=($j*0.7)+($contain*0.3);
    return ['score'=>$score,'shared'=>array_slice($shared,0,12)];
}

function cross_research_decisions(PDO $pdo,array $viewer): array {
    if(!cross_research_ready($pdo))return [];$q=$pdo->prepare('SELECT suggestion_key,decision,link_public_id FROM cross_research_decisions WHERE user_id=?');$q->execute([$viewer['id']]);$out=[];
    foreach($q->fetchAll() as $r)$out[(string)$r['suggestion_key']]=$r;return $out;
}

function cross_research_suggestions(PDO $pdo,array $viewer,?string $focusPublic=null,int $limit=100,bool $includeDecided=false): array {
    if(!cross_research_ready($pdo))return [];
    [$projects,$byId,$byPublic,$focus]=cross_research_project_maps($pdo,$viewer,$focusPublic);if(count($projects)<2)return [];
    $ids=array_keys($byId);$in=implode(',',array_map('intval',$ids));$out=[];$now=date('Y-m-d H:i:s');

    // Same canonical Source across projects.
    $rows=$pdo->query("SELECT ps.project_id,s.id source_id,s.public_id source_public_id,s.title,s.domain,ps.created_at FROM project_sources ps JOIN sources s ON s.id=ps.source_id WHERE ps.project_id IN ($in) ORDER BY ps.created_at DESC LIMIT 1200")->fetchAll();
    $groups=[];foreach($rows as $r)$groups[(int)$r['source_id']][]=$r;
    foreach($groups as $group){$n=count($group);for($i=0;$i<$n;$i++)for($j=$i+1;$j<$n;$j++){
        $a=(int)$group[$i]['project_id'];$b=(int)$group[$j]['project_id'];if(!cross_research_pair_allowed($focus,$a,$b))continue;
        $p1=$byId[$a];$p2=$byId[$b];$src=(string)$group[$i]['source_public_id'];$label=(string)($group[$i]['title']?:$group[$i]['domain']?:'Source');
        cross_research_add_candidate($out,cross_research_candidate('shared_source',$p1,$p2,'source',$src,$src,'Same Source appears in two Research projects',
          $label.' is included in both '.$p1['title'].' and '.$p2['title'].',['Exact same canonical Source record.'],84,'medium','shared_source',max((string)$group[$i]['created_at'],(string)$group[$j]['created_at']),['source_public_id'=>$src,'source_label'=>$label]));
    }}

    // Same exact Annotation assigned to multiple projects.
    $rows=$pdo->query("SELECT pa.project_id,a.id annotation_id,a.public_id annotation_public_id,a.text_commentary,s.title source_title,s.domain,pa.created_at FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id JOIN sources s ON s.id=a.source_id WHERE pa.project_id IN ($in) AND a.status='published' ORDER BY pa.created_at DESC LIMIT 1200")->fetchAll();
    $annotationRows=$rows;$groups=[];foreach($rows as $r)$groups[(int)$r['annotation_id']][]=$r;
    foreach($groups as $group){$n=count($group);for($i=0;$i<$n;$i++)for($j=$i+1;$j<$n;$j++){
        $a=(int)$group[$i]['project_id'];$b=(int)$group[$j]['project_id'];if(!cross_research_pair_allowed($focus,$a,$b))continue;
        $p1=$byId[$a];$p2=$byId[$b];$ann=(string)$group[$i]['annotation_public_id'];$label=trim((string)$group[$i]['text_commentary']);if($label==='')$label=(string)($group[$i]['source_title']?:$group[$i]['domain']?:'Annotation');
        cross_research_add_candidate($out,cross_research_candidate('shared_annotation',$p1,$p2,'annotation',$ann,$ann,'Same evidence Annotation is used in two projects',
          mb_substr($label,0,420),['Exact same Annotation record is attached to both projects.'],92,'high','shared_annotation',max((string)$group[$i]['created_at'],(string)$group[$j]['created_at']),['annotation_public_id'=>$ann]));
    }}

    // Same normalized Entity identity across projects.
    $rows=$pdo->query("SELECT re.id,re.public_id,re.project_id,re.entity_type,re.canonical_name,re.normalized_name,re.status,re.updated_at FROM research_entities re WHERE re.project_id IN ($in) AND re.status<>'archived' ORDER BY re.updated_at DESC LIMIT 1500")->fetchAll();
    $entityRows=$rows;$groups=[];foreach($rows as $r)$groups[(string)$r['entity_type'].'|'.(string)$r['normalized_name']][]=$r;
    foreach($groups as $group){$n=count($group);if($n<2)continue;for($i=0;$i<$n;$i++)for($j=$i+1;$j<$n;$j++){
        $a=(int)$group[$i]['project_id'];$b=(int)$group[$j]['project_id'];if(!cross_research_pair_allowed($focus,$a,$b))continue;$p1=$byId[$a];$p2=$byId[$b];
        cross_research_add_candidate($out,cross_research_candidate('same_entity',$p1,$p2,'entity',(string)$group[$i]['public_id'],(string)$group[$j]['public_id'],'Same Entity appears across Research',
          (string)$group[$i]['canonical_name'].' appears as a '.(string)$group[$i]['entity_type'].' in both projects.',['Entity type matches.','Normalized entity name matches exactly.'],88,'medium','same_entity',max((string)$group[$i]['updated_at'],(string)$group[$j]['updated_at']),['entity_type'=>$group[$i]['entity_type'],'normalized_name'=>$group[$i]['normalized_name'],'entity_name'=>$group[$i]['canonical_name']));
    }}

    // Claims: deterministic wording overlap only. Do not infer semantic agreement/disagreement from text alone.
    $claims=$pdo->query("SELECT id,public_id,project_id,statement,status,updated_at FROM research_claims WHERE project_id IN ($in) ORDER BY updated_at DESC LIMIT 240")->fetchAll();
    $count=count($claims);for($i=0;$i<$count;$i++)for($j=$i+1;$j<$count;$j++){
        $a=(int)$claims[$i]['project_id'];$b=(int)$claims[$j]['project_id'];if(!cross_research_pair_allowed($focus,$a,$b))continue;
        $sim=cross_research_claim_similarity((string)$claims[$i]['statement'],(string)$claims[$j]['statement']);if($sim['score']<0.66||count($sim['shared'])<3)continue;
        $p1=$byId[$a];$p2=$byId[$b];$differentStatus=(string)$claims[$i]['status']!==(string)$claims[$j]['status'];$type=$differentStatus?'claim_status_difference':'claim_overlap';$priority=$differentStatus?'high':'medium';
        $body='Claim A: '.mb_substr((string)$claims[$i]['statement'],0,280).' · Claim B: '.mb_substr((string)$claims[$j]['statement'],0,280);
        $reasons=['Deterministic token overlap '.(string)round($sim['score']*100).'%.','Shared terms: '.implode(', ',$sim['shared']).'.'];
        if($differentStatus)$reasons[]='The two Claim records currently have different statuses ('.$claims[$i]['status'].' vs '.$claims[$j]['status'].'); this does not by itself mean the statements contradict.';
        cross_research_add_candidate($out,cross_research_candidate($type,$p1,$p2,'claim',(string)$claims[$i]['public_id'],(string)$claims[$j]['public_id'],$differentStatus?'Similar Claims have different Research status':'Potentially overlapping Claims across projects',
          $body,$reasons,$differentStatus?89:(72+(int)round($sim['score']*18)),$priority,'related',max((string)$claims[$i]['updated_at'],(string)$claims[$j]['updated_at']),['similarity'=>$sim['score'],'shared_terms'=>$sim['shared'],'source_status'=>$claims[$i]['status'],'target_status'=>$claims[$j]['status']]));
    }

    // Exact claim evidence reuse / evidence-label disagreement.
    $evidence=$pdo->query("SELECT c.project_id,c.public_id claim_public_id,c.statement,c.status,ce.relationship,ce.source_version_id,sv.source_id,s.public_id source_public_id,s.title source_title,s.domain,ce.created_at
      FROM claim_evidence ce JOIN research_claims c ON c.id=ce.claim_id JOIN source_versions sv ON sv.id=ce.source_version_id JOIN sources s ON s.id=sv.source_id
      WHERE c.project_id IN ($in) ORDER BY ce.created_at DESC LIMIT 1800")->fetchAll();
    $pairEvidence=[];$groups=[];foreach($evidence as $r)$groups[(int)$r['source_version_id']][]=$r;
    foreach($groups as $group){$n=count($group);for($i=0;$i<$n;$i++)for($j=$i+1;$j<$n;$j++){
        $a=(int)$group[$i]['project_id'];$b=(int)$group[$j]['project_id'];if(!cross_research_pair_allowed($focus,$a,$b))continue;
        $tuple=[(string)$group[$i]['claim_public_id'],(string)$group[$j]['claim_public_id']];sort($tuple,SORT_STRING);$pk=$tuple[0].'|'.$tuple[1];
        if(!isset($pairEvidence[$pk]))$pairEvidence[$pk]=['a'=>$group[$i],'b'=>$group[$j],'versions'=>0,'opposite'=>false,'sources'=>[],'last'=>''];
        $pairEvidence[$pk]['versions']++;$r1=(string)$group[$i]['relationship'];$r2=(string)$group[$j]['relationship'];if(($r1==='supports'&&$r2==='contradicts')||($r1==='contradicts'&&$r2==='supports'))$pairEvidence[$pk]['opposite']=true;
        $pairEvidence[$pk]['sources'][(string)$group[$i]['source_public_id']]=(string)($group[$i]['source_title']?:$group[$i]['domain']?:'Source');$pairEvidence[$pk]['last']=max($pairEvidence[$pk]['last'],(string)$group[$i]['created_at'],(string)$group[$j]['created_at']);
    }}
    foreach($pairEvidence as $x){$a=(int)$x['a']['project_id'];$b=(int)$x['b']['project_id'];$p1=$byId[$a];$p2=$byId[$b];$opposite=(bool)$x['opposite'];$sourceNames=array_slice(array_values($x['sources']),0,4);
        $reasons=['The Claims cite '.(int)$x['versions'].' identical Source Version'.((int)$x['versions']===1?'':'s').'.','Shared evidence: '.implode(', ',$sourceNames).'.'];
        if($opposite)$reasons[]='At least one identical Source Version is labeled supports in one Claim and contradicts in the other.';
        cross_research_add_candidate($out,cross_research_candidate($opposite?'evidence_label_conflict':'shared_claim_evidence',$p1,$p2,'claim',(string)$x['a']['claim_public_id'],(string)$x['b']['claim_public_id'],$opposite?'Same evidence is classified differently across Claims':'Claims reuse the same exact evidence',
          mb_substr((string)$x['a']['statement'],0,220).' ↔ '.mb_substr((string)$x['b']['statement'],0,220),$reasons,$opposite?98:94,$opposite?'high':'medium',$opposite?'context':'shared_evidence',$x['last'],['shared_versions'=>(int)$x['versions'],'opposite_evidence_labels'=>$opposite,'source_public_ids'=>array_keys($x['sources'])]));
    }

    // Evidence reuse opportunity: annotation from a Source already in another project, but Annotation itself is not attached there.
    $sourceProjects=[];foreach($rows=[] as $noop){} // placeholder to keep following structure explicit
    $psRows=$pdo->query("SELECT project_id,source_id FROM project_sources WHERE project_id IN ($in)")->fetchAll();foreach($psRows as $r)$sourceProjects[(int)$r['source_id']][(int)$r['project_id']]=true;
    $attached=[];foreach($annotationRows as $r)$attached[(int)$r['annotation_id']][(int)$r['project_id']]=true;
    $annotationSourceRows=$pdo->query("SELECT pa.project_id,a.id annotation_id,a.public_id annotation_public_id,a.source_id,a.text_commentary,s.title source_title,s.domain,pa.created_at
      FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id JOIN sources s ON s.id=a.source_id
      WHERE pa.project_id IN ($in) AND a.status='published' ORDER BY pa.created_at DESC LIMIT 400")->fetchAll();
    $reuseCount=0;foreach($annotationSourceRows as $r){$sourceProject=(int)$r['project_id'];foreach(array_keys($sourceProjects[(int)$r['source_id']]??[]) as $targetProject){
        if($targetProject===$sourceProject||isset($attached[(int)$r['annotation_id']][$targetProject])||!cross_research_pair_allowed($focus,$sourceProject,$targetProject))continue;
        $p1=$byId[$sourceProject];$p2=$byId[$targetProject];$label=trim((string)$r['text_commentary']);if($label==='')$label=(string)($r['source_title']?:$r['domain']?:'Annotation');
        cross_research_add_candidate($out,cross_research_candidate('evidence_reuse',$p1,$p2,'annotation',(string)$r['annotation_public_id'],(string)$r['annotation_public_id'],'Evidence from a shared Source may belong in another project',
          mb_substr($label,0,420),['The Annotation is in '.$p1['title'].' but not '.$p2['title'].'.','Its canonical Source is already included in '.$p2['title'].'.','Annotated is not asserting that this evidence supports any Claim; review before attaching it.'],70,'medium','evidence_reuse',(string)$r['created_at'],['annotation_public_id'=>$r['annotation_public_id'],'target_missing_annotation'=>true]));
        if(++$reuseCount>=40)break 2;
    }}

    // Aggregate explicit project-level relationship suggestions from factual signals above.
    $pairStats=[];foreach($out as $c){
        if($c['type']==='evidence_reuse')continue;$pids=[(string)$c['source_project_public_id'],(string)$c['target_project_public_id']];sort($pids,SORT_STRING);$pk=implode('|',$pids);
        if(!isset($pairStats[$pk]))$pairStats[$pk]=['a'=>$c['source_project_id'],'b'=>$c['target_project_id'],'types'=>[],'score'=>0,'last'=>$c['created_at']];
        $pairStats[$pk]['types'][$c['type']]=($pairStats[$pk]['types'][$c['type']]??0)+1;$pairStats[$pk]['score']=max($pairStats[$pk]['score'],(int)$c['score']);$pairStats[$pk]['last']=max((string)$pairStats[$pk]['last'],(string)$c['created_at']);
    }
    foreach($pairStats as $stat){$signalCount=array_sum($stat['types']);if($signalCount<2&&!isset($stat['types']['shared_annotation'])&&!isset($stat['types']['evidence_label_conflict']))continue;$p1=$byId[(int)$stat['a']];$p2=$byId[(int)$stat['b']];
        $labels=[];foreach($stat['types'] as $type=>$n)$labels[]=$n.' '.str_replace('_',' ',$type);$score=min(96,64+min(24,$signalCount*5)+($stat['score']>=94?6:0));
        cross_research_add_candidate($out,cross_research_candidate('related_projects',$p1,$p2,'project',(string)$p1['public_id'],(string)$p2['public_id'],'These Research projects share multiple evidence signals',
          $p1['title'].' ↔ '.$p2['title'],['Detected: '.implode('; ',$labels).'.'],$score,'medium','related',(string)$stat['last'],['signal_counts'=>$stat['types']]));
    }

    $decisions=cross_research_decisions($pdo,$viewer);$items=[];
    foreach($out as $key=>$c){$decision=$decisions[$key]??null;$c['decision']=$decision['decision']??null;$c['link_public_id']=$decision['link_public_id']??null;if(!$includeDecided&&$decision)continue;$items[]=$c;}
    usort($items,function($a,$b){$p=['high'=>3,'medium'=>2,'low'=>1];$cmp=($p[$b['priority']]??0)<=>($p[$a['priority']]??0);if($cmp!==0)return $cmp;$cmp=(int)$b['score']<=>(int)$a['score'];if($cmp!==0)return $cmp;return strcmp((string)$b['created_at'],(string)$a['created_at']);});
    return array_slice($items,0,max(1,min(300,$limit)));
}

function cross_research_find_suggestion(PDO $pdo,array $viewer,string $key): ?array {
    $key=strtolower(trim($key));if(!preg_match('/^[a-f0-9]{64}$/',$key))return null;
    foreach(cross_research_suggestions($pdo,$viewer,null,300,true) as $item)if(hash_equals((string)$item['key'],$key))return $item;return null;
}

function cross_research_link_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!cross_research_ready($pdo))return null;$q=$pdo->prepare("SELECT l.*,sp.public_id source_project_public_id,sp.title source_project_title,tp.public_id target_project_public_id,tp.title target_project_title
      FROM cross_research_links l JOIN research_projects sp ON sp.id=l.source_project_id JOIN research_projects tp ON tp.id=l.target_project_id WHERE l.public_id=? AND l.user_id=? LIMIT 1");$q->execute([trim($publicId),$viewer['id']]);$row=$q->fetch();if(!$row)return null;
    if(!project_access($pdo,(int)$viewer['id'],(string)$row['source_project_public_id'])||!project_access($pdo,(int)$viewer['id'],(string)$row['target_project_public_id']))return null;return $row;
}

function cross_research_links(PDO $pdo,array $viewer,?string $focusPublic=null,int $limit=100): array {
    if(!cross_research_ready($pdo))return [];$limit=max(1,min(300,$limit));$params=[$viewer['id']];$where='l.user_id=?';
    if($focusPublic!==null&&trim($focusPublic)!==''){$p=project_access($pdo,(int)$viewer['id'],trim($focusPublic));if(!$p)return [];$where.=' AND (l.source_project_id=? OR l.target_project_id=?)';$params[]=$p['id'];$params[]=$p['id'];}
    $q=$pdo->prepare("SELECT l.public_id FROM cross_research_links l WHERE $where ORDER BY l.updated_at DESC,l.id DESC LIMIT ".$limit);$q->execute($params);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$r=cross_research_link_access($pdo,$viewer,(string)$id);if($r)$out[]=$r;}return $out;
}

function cross_research_accept(PDO $pdo,array $viewer,string $key,?string $relation=null): array {
    if(!cross_research_ready($pdo))throw new RuntimeException('Cross-Research Intelligence requires the Phase 19 database upgrade.');$candidate=cross_research_find_suggestion($pdo,$viewer,$key);if(!$candidate)throw new RuntimeException('Cross-Research suggestion is no longer available.');
    $relations=cross_research_relation_types();$relation=trim((string)$relation);if($relation===''||!isset($relations[$relation]))$relation=(string)$candidate['suggested_relation'];
    if(!isset($relations[$relation]))$relation='related';$public=ulid_like();
    $pdo->prepare("INSERT INTO cross_research_links(public_id,user_id,source_project_id,target_project_id,object_type,source_object_public_id,target_object_public_id,relation_type,rationale,suggestion_key)
      VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE rationale=VALUES(rationale),suggestion_key=VALUES(suggestion_key),updated_at=NOW()")
      ->execute([$public,$viewer['id'],$candidate['source_project_id'],$candidate['target_project_id'],$candidate['object_type'],$candidate['source_object_public_id'],$candidate['target_object_public_id'],$relation,mb_substr(implode(' ',$candidate['reasons']),0,1000),$candidate['key']]);
    $q=$pdo->prepare("SELECT public_id FROM cross_research_links WHERE user_id=? AND source_project_id=? AND target_project_id=? AND object_type=? AND source_object_public_id=? AND target_object_public_id=? AND relation_type=? LIMIT 1");
    $q->execute([$viewer['id'],$candidate['source_project_id'],$candidate['target_project_id'],$candidate['object_type'],$candidate['source_object_public_id'],$candidate['target_object_public_id'],$relation]);$linkPublic=(string)$q->fetchColumn();
    $pdo->prepare("INSERT INTO cross_research_decisions(user_id,suggestion_key,decision,link_public_id) VALUES(?,?,'accepted',?) ON DUPLICATE KEY UPDATE decision='accepted',link_public_id=VALUES(link_public_id),updated_at=NOW()")->execute([$viewer['id'],$candidate['key'],$linkPublic]);
    return cross_research_link_access($pdo,$viewer,$linkPublic)??[];
}

function cross_research_reject(PDO $pdo,array $viewer,string $key): bool {
    $candidate=cross_research_find_suggestion($pdo,$viewer,$key);if(!$candidate)return false;
    $pdo->prepare("INSERT INTO cross_research_decisions(user_id,suggestion_key,decision,link_public_id) VALUES(?,?,'rejected',NULL) ON DUPLICATE KEY UPDATE decision='rejected',link_public_id=NULL,updated_at=NOW()")->execute([$viewer['id'],$candidate['key']]);return true;
}

function cross_research_restore_decision(PDO $pdo,array $viewer,string $key): bool {
    if(!cross_research_ready($pdo))return false;$q=$pdo->prepare('DELETE FROM cross_research_decisions WHERE user_id=? AND suggestion_key=?');$q->execute([$viewer['id'],strtolower(trim($key))]);return $q->rowCount()>0;
}

function cross_research_create_project_link(PDO $pdo,array $viewer,string $sourcePublic,string $targetPublic,string $relation,string $rationale=''): array {
    $source=project_access($pdo,(int)$viewer['id'],trim($sourcePublic));$target=project_access($pdo,(int)$viewer['id'],trim($targetPublic));if(!$source||!$target)throw new RuntimeException('Both Research projects must be accessible.');if((int)$source['id']===(int)$target['id'])throw new InvalidArgumentException('Choose two different Research projects.');
    $relations=cross_research_relation_types();if(!isset($relations[$relation]))throw new InvalidArgumentException('Invalid project relationship.');$public=ulid_like();
    $pdo->prepare("INSERT INTO cross_research_links(public_id,user_id,source_project_id,target_project_id,object_type,source_object_public_id,target_object_public_id,relation_type,rationale)
      VALUES(?,?,?,?, 'project',?,?,?,?) ON DUPLICATE KEY UPDATE rationale=VALUES(rationale),updated_at=NOW()")->execute([$public,$viewer['id'],$source['id'],$target['id'],$source['public_id'],$target['public_id'],$relation,mb_substr(trim($rationale),0,1000)]);
    $q=$pdo->prepare("SELECT public_id FROM cross_research_links WHERE user_id=? AND source_project_id=? AND target_project_id=? AND object_type='project' AND source_object_public_id=? AND target_object_public_id=? AND relation_type=? LIMIT 1");$q->execute([$viewer['id'],$source['id'],$target['id'],$source['public_id'],$target['public_id'],$relation]);$id=(string)$q->fetchColumn();return cross_research_link_access($pdo,$viewer,$id)??[];
}

function cross_research_delete_link(PDO $pdo,array $viewer,string $publicId): bool {
    if(!cross_research_ready($pdo))return false;$q=$pdo->prepare('SELECT suggestion_key FROM cross_research_links WHERE public_id=? AND user_id=? LIMIT 1');$q->execute([trim($publicId),$viewer['id']]);$suggestion=$q->fetchColumn();if($suggestion===false)return false;
    $pdo->beginTransaction();try{$pdo->prepare('DELETE FROM cross_research_links WHERE public_id=? AND user_id=?')->execute([trim($publicId),$viewer['id']]);if($suggestion)$pdo->prepare('DELETE FROM cross_research_decisions WHERE user_id=? AND suggestion_key=?')->execute([$viewer['id'],$suggestion]);$pdo->commit();return true;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function cross_research_entity_threads(PDO $pdo,array $viewer,?string $focusPublic=null,int $limit=30): array {
    [$projects,$byId,$byPublic,$focus]=cross_research_project_maps($pdo,$viewer,$focusPublic);if(count($projects)<2)return [];$in=implode(',',array_map('intval',array_keys($byId)));
    $rows=$pdo->query("SELECT re.public_id,re.project_id,re.entity_type,re.canonical_name,re.normalized_name,re.status,re.updated_at,(SELECT COUNT(*) FROM research_entity_mentions rem WHERE rem.entity_id=re.id) mention_count FROM research_entities re WHERE re.project_id IN ($in) AND re.status<>'archived' ORDER BY re.updated_at DESC LIMIT 1500")->fetchAll();$groups=[];
    foreach($rows as $r){$key=$r['entity_type'].'|'.$r['normalized_name'];$groups[$key][]=$r;}$out=[];
    foreach($groups as $group){$projectIds=array_values(array_unique(array_map(fn($r)=>(int)$r['project_id'],$group)));if(count($projectIds)<2)continue;if($focus&&!in_array((int)$focus['id'],$projectIds,true))continue;$projectsOut=[];$mentions=0;foreach($group as $r){$p=$byId[(int)$r['project_id']]??null;if(!$p)continue;$projectsOut[]=['public_id'=>$p['public_id'],'title'=>$p['title'],'entity_public_id'=>$r['public_id'],'status'=>$r['status'],'mentions'=>(int)$r['mention_count']];$mentions+=(int)$r['mention_count'];}
        $out[]=['entity_type'=>$group[0]['entity_type'],'canonical_name'=>$group[0]['canonical_name'],'normalized_name'=>$group[0]['normalized_name'],'project_count'=>count($projectIds),'mention_count'=>$mentions,'projects'=>$projectsOut,'updated_at'=>max(array_column($group,'updated_at'))];
    }
    usort($out,fn($a,$b)=>$b['project_count']<=>$a['project_count']?:$b['mention_count']<=>$a['mention_count']?:strcmp((string)$b['updated_at'],(string)$a['updated_at']));return array_slice($out,0,max(1,min(100,$limit)));
}

function cross_research_context(PDO $pdo,array $viewer,string $projectPublic,int $limit=12): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)return ['text'=>'','refs'=>[],'suggestions'=>[],'links'=>[]];
    $suggestions=cross_research_suggestions($pdo,$viewer,$projectPublic,$limit,false);$links=cross_research_links($pdo,$viewer,$projectPublic,$limit);$lines=['[CROSS-RESEARCH INTELLIGENCE]'];$refs=[];
    foreach($suggestions as $s){$lines[]='Suggested '.$s['type'].': '.$s['title'].' — '.implode(' ',$s['reasons']).' [PROJECT '.$s['source_project_public_id'].'] [PROJECT '.$s['target_project_public_id'].']';$refs[]=['type'=>'research_project','id'=>$s['source_project_public_id']];$refs[]=['type'=>'research_project','id'=>$s['target_project_public_id']];if(in_array($s['object_type'],['source','annotation','claim','entity'],true)){$refs[]=['type'=>$s['object_type'],'id'=>$s['source_object_public_id']];if($s['target_object_public_id']!==$s['source_object_public_id'])$refs[]=['type'=>$s['object_type'],'id'=>$s['target_object_public_id']];}}
    foreach($links as $l)$lines[]='Accepted '.$l['relation_type'].': '.$l['source_project_title'].' ↔ '.$l['target_project_title'].($l['rationale']?' — '.$l['rationale']:'');
    $seen=[];$refs=array_values(array_filter($refs,function($r)use(&$seen){$k=$r['type'].':'.$r['id'];if(isset($seen[$k]))return false;$seen[$k]=true;return true;}));
    return ['text'=>count($lines)>1?implode("\n",$lines):'','refs'=>$refs,'suggestions'=>$suggestions,'links'=>$links];
}

function cross_research_input_hash(PDO $pdo,array $viewer,string $projectPublic): string {
    $ctx=cross_research_context($pdo,$viewer,$projectPublic,40);return hash('sha256',json_encode([$ctx['suggestions'],$ctx['links']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function cross_research_agent_handoff(PDO $pdo,array $viewer,string $key): ?array {
    $s=cross_research_find_suggestion($pdo,$viewer,$key);if(!$s)return null;$context=[['type'=>'research','public_id'=>$s['source_project_public_id']],['type'=>'research','public_id'=>$s['target_project_public_id']]];
    if($s['object_type']==='source')$context[]=['type'=>'source','public_id'=>$s['source_object_public_id']];
    if($s['object_type']==='annotation')$context[]=['type'=>'annotation','public_id'=>$s['source_object_public_id']];
    $prompt='Review this Cross-Research relationship: '.$s['title'].'. Reasons: '.implode(' ',$s['reasons']).' Compare only permission-checked evidence from both projects. Explain whether the relationship is useful and what, if anything, I should verify next. Do not merge projects or move/attach evidence automatically.';
    return ['prompt'=>$prompt,'context'=>$context,'suggestion'=>$s];
}

function cross_research_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=14): void {
    if(!cross_research_ready($pdo))return;foreach(cross_research_suggestions($pdo,$viewer,null,$limit,false) as $s){
        $conflict=in_array($s['type'],['evidence_label_conflict','claim_status_difference'],true);$opportunity=$s['type']==='evidence_reuse';$section=$conflict?'needs_attention':($opportunity?'opportunities':'related_research');$type=$conflict?'cross_project_conflict':($opportunity?'cross_research_opportunity':'cross_research_found');
        $context=[['type'=>'research','public_id'=>$s['source_project_public_id']],['type'=>'research','public_id'=>$s['target_project_public_id']]];
        $actions=[cognitive_feed_action_link('Review relationship','/cross-research.php?project='.rawurlencode((string)$s['source_project_public_id']).'#suggestion-'.substr((string)$s['key'],0,12)),cognitive_feed_action_agent($conflict?'Compare projects':'Ask Agent','Review this Cross-Research relationship: '.$s['title'].'. Explain the evidence for the relationship, its limits, and what I should verify next. Do not make Research changes without confirmation.',$context)];
        if($s['object_type']==='claim'){$actions[]=cognitive_feed_action_link('Open first claim','/research-claim.php?id='.rawurlencode((string)$s['source_object_public_id']));$actions[]=cognitive_feed_action_link('Open second claim','/research-claim.php?id='.rawurlencode((string)$s['target_object_public_id']));}
        elseif($s['object_type']==='entity'){$actions[]=cognitive_feed_action_link('Open entity','/entity.php?id='.rawurlencode((string)$s['source_object_public_id']));}
        elseif($s['object_type']==='source')$actions[]=cognitive_feed_action_link('Open source','/source.php?id='.rawurlencode((string)$s['source_object_public_id']));
        elseif($s['object_type']==='annotation')$actions[]=cognitive_feed_action_link('Open annotation','/annotation.php?id='.rawurlencode((string)$s['source_object_public_id']));
        cognitive_feed_add($items,['key'=>cognitive_feed_key($type,'cross_research',(string)$s['key'],(string)$s['created_at']),'type'=>$type,'section'=>$section,'priority'=>$conflict?'high':'medium','created_at'=>$s['created_at'],'score_extra'=>max(0,(int)round(((int)$s['score']-70)/5)),
          'title'=>$s['title'],'body'=>$s['body'],'meta'=>['source_project'=>$s['source_project_title'],'target_project'=>$s['target_project_title'],'relationship'=>$s['type'],'explanation'=>implode(' ',$s['reasons'])],'actions'=>$actions]);
    }
}
