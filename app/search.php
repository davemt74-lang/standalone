<?php
declare(strict_types=1);

require_once __DIR__.'/feed.php';

function search_normalize_query(string $term): string {
    return trim((string)preg_replace('/\s+/u',' ',mb_substr($term,0,500)));
}
function search_normalized_name(string $name): string {
    return mb_substr(mb_strtolower(trim((string)preg_replace('/\s+/u',' ',$name))),0,255);
}
function search_filters_from_input(array $input): array {
    $sortCandidate=(string)($input['sort']??'relevance');$sort=in_array($sortCandidate,['relevance','newest','discussed'],true)?$sortCandidate:'relevance';
    $typeCandidate=(string)($input['type']??'all');$type=in_array($typeCandidate,['all','sources','annotations','people','reports','entities','projects','research'],true)?$typeCandidate:'all';
    $integrity=in_array((string)($input['integrity']??''),['','source_unchanged','source_updated','passage_changed','passage_missing','source_unavailable','source_restored'],true)?(string)($input['integrity']??''):'';
    $media=in_array((string)($input['media']??''),['','text','screenshot','video_clip','audio_clip','audio_commentary'],true)?(string)($input['media']??''):'';
    $dateFrom=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($input['date_from']??''))?(string)$input['date_from']:'';
    $dateTo=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($input['date_to']??''))?(string)$input['date_to']:'';
    return [
        'type'=>$type,'sort'=>$sort,'domain'=>mb_substr(trim((string)($input['domain']??'')),0,255),
        'author'=>mb_substr(trim((string)($input['author']??'')),0,80),'media'=>$media,'integrity'=>$integrity,
        'date_from'=>$dateFrom,'date_to'=>$dateTo,'source'=>mb_substr(trim((string)($input['source']??'')),0,64),
        'team'=>mb_substr(trim((string)($input['team']??'')),0,64),'project'=>mb_substr(trim((string)($input['project']??'')),0,64),
    ];
}
function search_filter_query_string(array $filters): string {
    $out=[];foreach($filters as $k=>$v)if($v!==''&&$v!=='all'&&!($k==='sort'&&$v==='relevance'))$out[$k]=$v;return http_build_query($out);
}
function search_team_scope(PDO $pdo,?array $viewer,string $publicId): ?array {
    if(!$viewer||$publicId==='')return null;$q=$pdo->prepare('SELECT t.id,t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE t.public_id=? AND tm.user_id=? LIMIT 1');$q->execute([$publicId,$viewer['id']]);return $q->fetch()?:null;
}
function search_project_scope(PDO $pdo,?array $viewer,string $publicId): ?array {
    if(!$viewer||$publicId==='')return null;return project_access($pdo,(int)$viewer['id'],$publicId);
}
function search_annotation_access_sql(?array $viewer,string $alias='a'): array {
    $uid=(int)($viewer['id']??0);if(!$uid)return ["$alias.status='published' AND $alias.visibility='public'",[]];
    $sql="$alias.status='published' AND (
      $alias.visibility='public'
      OR $alias.user_id=?
      OR ($alias.visibility='team' AND $alias.team_id IS NOT NULL AND EXISTS(SELECT 1 FROM team_members stm WHERE stm.team_id=$alias.team_id AND stm.user_id=?))
      OR EXISTS(SELECT 1 FROM project_annotations spa JOIN research_projects srp ON srp.id=spa.project_id LEFT JOIN team_members spm ON spm.team_id=srp.team_id AND spm.user_id=? WHERE spa.annotation_id=$alias.id AND (srp.owner_user_id=? OR spm.user_id IS NOT NULL))
    )";
    return [$sql,[$uid,$uid,$uid,$uid]];
}
function search_source_visible(PDO $pdo,string $publicId,?array $viewer): ?array {
    $q=$pdo->prepare("SELECT * FROM sources WHERE public_id=? AND COALESCE(moderation_status,'visible')='visible' LIMIT 1");$q->execute([$publicId]);$s=$q->fetch();if(!$s)return null;[$access,$params]=search_annotation_access_sql($viewer,'a');$q=$pdo->prepare("SELECT 1 FROM annotations a WHERE a.source_id=? AND $access LIMIT 1");$q->execute(array_merge([$s['id']],$params));if($q->fetchColumn())return $s;if(!$viewer)return null;$uid=(int)$viewer['id'];$q=$pdo->prepare('SELECT 1 FROM project_sources ps JOIN research_projects rp ON rp.id=ps.project_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE ps.source_id=? AND (rp.owner_user_id=? OR tm.user_id IS NOT NULL) LIMIT 1');$q->execute([$uid,$s['id'],$uid]);return $q->fetchColumn()?$s:null;
}
function search_report_visible(PDO $pdo,array $report,?array $viewer): bool {
    if(($report['status']??'')!=='published')return false;
    if(($report['visibility']??'')==='public')return true;
    if(!$viewer)return false;$uid=(int)$viewer['id'];if((int)$report['created_by_user_id']===$uid||(int)($report['owner_user_id']??0)===$uid)return true;
    $teamId=(int)($report['team_id']??0);if($teamId<1)return false;$q=$pdo->prepare('SELECT role FROM team_members WHERE team_id=? AND user_id=?');$q->execute([$teamId,$uid]);$role=$q->fetchColumn();if($role===false)return false;
    return $report['visibility']==='team'||in_array((string)$role,['owner','admin'],true);
}
function search_annotation_score(array $row,string $term): int {
    $needle=mb_strtolower($term);$score=0;
    foreach([['source_title',40],['text_commentary',32],['selected_text',24],['transcript_text',14],['display_name',8]] as [$key,$weight]){
        $value=mb_strtolower((string)($row[$key]??''));if($value==='')continue;if($value===$needle)$score+=$weight*3;elseif(str_contains($value,$needle))$score+=$weight;
    }
    return $score;
}
function search_unified(PDO $pdo,string $rawTerm,?array $viewer,array $rawFilters=[],bool $recordRecent=true): array {
    $term=search_normalize_query($rawTerm);$filters=search_filters_from_input($rawFilters);$empty=['sources'=>[],'annotations'=>[],'people'=>[],'reports'=>[],'entities'=>[],'projects'=>[],'research'=>[],'meta'=>['query'=>$term,'filters'=>$filters,'total'=>0]];
    if(mb_strlen($term)<2)return $empty;
    $like='%'.$term.'%';$prefix=mb_substr($term,0,max(2,min(5,mb_strlen($term)))).'%';$fuzzy=str_contains($term,' ')?0:1;$uid=(int)($viewer['id']??0);
    $team=$filters['team']!==''?search_team_scope($pdo,$viewer,$filters['team']):null;if($filters['team']!==''&&!$team)return $empty;
    $project=$filters['project']!==''?search_project_scope($pdo,$viewer,$filters['project']):null;if($filters['project']!==''&&!$project)return $empty;
    $sourceFilter=null;if($filters['source']!==''){$sourceFilter=search_source_visible($pdo,$filters['source'],$viewer);if(!$sourceFilter)return $empty;}

    [$accessSql,$accessParams]=search_annotation_access_sql($viewer,'a');$blockSql=feed_block_sql($viewer,'a.user_id');
    $where=["$accessSql","$blockSql","COALESCE(s.moderation_status,'visible')='visible'","(a.text_commentary LIKE ? OR c.selected_text LIKE ? OR t.raw_text LIKE ? OR t.edited_text LIKE ? OR s.title LIKE ? OR s.domain LIKE ?)"];
    $params=array_merge($accessParams,[$like,$like,$like,$like,$like,$like]);
    if($filters['domain']!==''){$where[]='s.domain=?';$params[]=$filters['domain'];}
    if($filters['author']!==''){$where[]='(u.username=? OR u.public_id=?)';$params[]=$filters['author'];$params[]=$filters['author'];}
    if($filters['media']!==''){$where[]=$filters['media']==='audio_commentary'?'a.audio_commentary_path IS NOT NULL':'c.capture_type=?';if($filters['media']!=='audio_commentary')$params[]=$filters['media'];}
    if($filters['date_from']!==''){$where[]='a.published_at>=?';$params[]=$filters['date_from'].' 00:00:00';}
    if($filters['date_to']!==''){$where[]='a.published_at<?';$params[]=date('Y-m-d H:i:s',strtotime($filters['date_to'].' +1 day'));}
    if($sourceFilter){$where[]='a.source_id=?';$params[]=$sourceFilter['id'];}
    if($team){$where[]='a.team_id=?';$params[]=$team['id'];}
    if($project){$where[]='EXISTS(SELECT 1 FROM project_annotations spa WHERE spa.project_id=? AND spa.annotation_id=a.id)';$params[]=$project['id'];}
    $sql="SELECT DISTINCT a.id internal_id,a.public_id,a.source_version_id,a.text_commentary,a.published_at,a.visibility,u.public_id author_public_id,u.username,u.display_name,s.id source_internal_id,s.public_id source_public_id,s.title source_title,s.domain,s.current_version_id,c.selected_text,c.capture_type,c.media_provider,c.media_title,COALESCE(t.edited_text,t.raw_text) transcript_text,(SELECT COUNT(*) FROM comments cm WHERE cm.annotation_id=a.id AND COALESCE(cm.moderation_status,'visible')='visible') comment_count FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id LEFT JOIN annotation_transcripts t ON t.annotation_id=a.id WHERE ".implode(' AND ',$where)." ORDER BY a.id DESC LIMIT 120";
    $q=$pdo->prepare($sql);$q->execute($params);$annotations=$q->fetchAll();
    foreach($annotations as &$a){$a['integrity']=source_integrity_annotation_state($pdo,['id'=>(int)$a['internal_id'],'source_version_id'=>(int)$a['source_version_id'],'current_source_version_id'=>(int)$a['current_version_id']]);$a['score']=search_annotation_score($a,$term);unset($a['internal_id'],$a['source_internal_id']);}unset($a);
    if($filters['integrity']!=='')$annotations=array_values(array_filter($annotations,fn($a)=>($a['integrity']['impact_type']??'')===$filters['integrity']));
    usort($annotations,function($a,$b)use($filters){if($filters['sort']==='newest')return strcmp((string)$b['published_at'],(string)$a['published_at']);if($filters['sort']==='discussed')return ((int)$b['comment_count']<=>(int)$a['comment_count'])?:strcmp((string)$b['published_at'],(string)$a['published_at']);return ((int)$b['score']<=>(int)$a['score'])?:strcmp((string)$b['published_at'],(string)$a['published_at']);});
    $annotations=array_slice($annotations,0,40);

    $sources=[];$q=$pdo->prepare("SELECT s.id,s.public_id,s.title,s.domain,s.canonical_url,s.status,s.current_version_id FROM sources s WHERE COALESCE(s.moderation_status,'visible')='visible' AND (s.title LIKE ? OR s.domain LIKE ? OR s.canonical_url LIKE ? OR (?=1 AND SOUNDEX(COALESCE(s.title,''))=SOUNDEX(?))) ORDER BY CASE WHEN s.title LIKE ? THEN 0 WHEN s.domain LIKE ? THEN 1 ELSE 2 END,s.id DESC LIMIT 80");$q->execute([$like,$like,$like,$fuzzy,$term,$like,$prefix]);
    foreach($q->fetchAll() as $s){
        $accessible=search_source_visible($pdo,(string)$s['public_id'],$viewer);if(!$accessible)continue;if($filters['domain']!==''&&$s['domain']!==$filters['domain'])continue;if($sourceFilter&&(int)$s['id']!==(int)$sourceFilter['id'])continue;
        if($project){$pq=$pdo->prepare('SELECT 1 FROM project_sources WHERE project_id=? AND source_id=? UNION SELECT 1 FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=? AND a.source_id=? LIMIT 1');$pq->execute([$project['id'],$s['id'],$project['id'],$s['id']]);if(!$pq->fetchColumn())continue;}
        [$ca,$cp]=search_annotation_access_sql($viewer,'aa');$cq=$pdo->prepare("SELECT COUNT(*) FROM annotations aa WHERE aa.source_id=? AND $ca");$cq->execute(array_merge([$s['id']],$cp));$s['annotation_count']=(int)$cq->fetchColumn();unset($s['id'],$s['current_version_id']);$sources[]=$s;if(count($sources)>=24)break;
    }

    $people=[];$userBlock=$uid?" AND NOT EXISTS(SELECT 1 FROM blocks ub WHERE (ub.blocker_user_id=$uid AND ub.blocked_user_id=u.id) OR (ub.blocker_user_id=u.id AND ub.blocked_user_id=$uid))":'';
    $q=$pdo->prepare("SELECT u.public_id,u.username,u.display_name,u.bio,u.profile_image_url FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.status='active' AND COALESCE(p.profile_visibility,'public')='public' AND COALESCE(p.search_visibility,1)=1 AND (u.username LIKE ? OR u.display_name LIKE ? OR u.bio LIKE ? OR (?=1 AND SOUNDEX(u.display_name)=SOUNDEX(?)))".$userBlock." ORDER BY CASE WHEN u.username=? THEN 0 WHEN u.display_name LIKE ? THEN 1 ELSE 2 END,u.display_name LIMIT 24");$q->execute([$like,$like,$like,$fuzzy,$term,$term,$prefix]);$people=$q->fetchAll();if(function_exists('profile_network_enrich_person'))foreach($people as &$person)$person=profile_network_enrich_person($pdo,$person,$viewer);unset($person);

    $reports=[];$reportWhere=["rr.status='published'","(rr.title LIKE ? OR rr.summary LIKE ? OR rv.snapshot_json LIKE ?)"];$rp=[$like,$like,$like];
    if($project){$reportWhere[]='rr.project_id=?';$rp[]=$project['id'];}
    if($filters['date_from']!==''){$reportWhere[]='rr.published_at>=?';$rp[]=$filters['date_from'].' 00:00:00';}
    if($filters['date_to']!==''){$reportWhere[]='rr.published_at<?';$rp[]=date('Y-m-d H:i:s',strtotime($filters['date_to'].' +1 day'));}
    $q=$pdo->prepare("SELECT rr.*,rv.version_number,rv.snapshot_json,u.username,u.display_name,rp.owner_user_id,rp.team_id,rp.public_id project_public_id,rp.title project_title FROM research_reports rr JOIN research_report_versions rv ON rv.id=rr.current_version_id JOIN users u ON u.id=rr.created_by_user_id JOIN research_projects rp ON rp.id=rr.project_id WHERE ".implode(' AND ',$reportWhere)." ORDER BY rr.published_at DESC LIMIT 60");$q->execute($rp);
    foreach($q->fetchAll() as $r){if(!search_report_visible($pdo,$r,$viewer))continue;if($uid&&is_blocked($pdo,$uid,(int)$r['created_by_user_id']))continue;$snapshot=json_decode((string)$r['snapshot_json'],true);$matches=[];if(is_array($snapshot)){foreach(($snapshot['findings']??[]) as $f)if(stripos((string)(($f['title']??'').' '.($f['summary']??'')),$term)!==false)$matches[]=['type'=>'finding','id'=>$f['id']??'','label'=>'Finding: '.($f['title']??''),'anchor'=>'finding-'.($f['id']??'')];foreach(($snapshot['claims']??[]) as $x)if(stripos((string)($x['statement']??''),$term)!==false)$matches[]=['type'=>'claim','id'=>$x['id']??'','label'=>'Claim: '.($x['statement']??''),'anchor'=>'claim-'.($x['id']??'')];foreach(($snapshot['entities']??[]) as $e)if(stripos((string)(($e['name']??'').' '.($e['description']??'')),$term)!==false)$matches[]=['type'=>'entity','id'=>$e['id']??'','label'=>'Entity: '.($e['name']??''),'anchor'=>'entity-'.($e['id']??'')];}$r['matches']=array_slice($matches,0,8);unset($r['snapshot_json'],$r['id'],$r['project_id'],$r['current_version_id'],$r['created_by_user_id'],$r['owner_user_id'],$r['team_id']);$reports[]=$r;if(count($reports)>=30)break;}

    $projects=[];$research=[];if($viewer){
        $q=$pdo->prepare("SELECT DISTINCT rp.public_id,rp.title,rp.description,rp.owner_user_id,rp.team_id,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE (rp.owner_user_id=? OR tm.user_id=?) AND (rp.title LIKE ? OR rp.description LIKE ?) ORDER BY rp.updated_at DESC LIMIT 30");$q->execute([$uid,$uid,$uid,$uid,$like,$like]);$projects=$q->fetchAll();foreach($projects as &$p)unset($p['owner_user_id'],$p['team_id']);unset($p);if($project)$projects=array_values(array_filter($projects,fn($p)=>$p['public_id']===$project['public_id']));
        $researchParams=[$uid,$uid,$uid,$uid,$like,$like,$like,$like,$like,$like];$projectClause='';if($project){$projectClause=' AND rp.id=?';$researchParams[]=$project['id'];}
        $sql="SELECT 'claim' object_type,rc.public_id,rc.statement title,rc.statement snippet,rp.public_id project_public_id,rp.title project_title,rc.created_at FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE (rp.owner_user_id=? OR tm.user_id=?) AND rc.statement LIKE ?$projectClause
              UNION ALL SELECT 'finding',rf.public_id,rf.title,rf.summary,rp.public_id,rp.title,rf.created_at FROM research_findings rf JOIN research_projects rp ON rp.id=rf.project_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE (rp.owner_user_id=? OR tm.user_id=?) AND (rf.title LIKE ? OR rf.summary LIKE ?)".($project?' AND rp.id=?':'')."
              ORDER BY created_at DESC LIMIT 40";
        if($project){$researchParams=[$uid,$uid,$uid,$like,$project['id'],$uid,$uid,$uid,$like,$like,$project['id']];}else{$researchParams=[$uid,$uid,$uid,$like,$uid,$uid,$uid,$like,$like];}
        $q=$pdo->prepare($sql);$q->execute($researchParams);$research=$q->fetchAll();
    }

    $entities=search_entity_results($pdo,$term,$viewer,$project,30);
    $out=['sources'=>$sources,'annotations'=>$annotations,'people'=>$people,'reports'=>$reports,'entities'=>$entities,'projects'=>$projects,'research'=>$research];
    if($filters['type']!=='all'){foreach(array_keys($out) as $key)if($key!==$filters['type'])$out[$key]=[];}
    $total=0;foreach($out as $v)if(is_array($v))$total+=count($v);$out['meta']=['query'=>$term,'filters'=>$filters,'total'=>$total];
    if($viewer&&$recordRecent)search_record_recent($pdo,$viewer,$term,$filters);
    return $out;
}
function search_entity_results(PDO $pdo,string $term,?array $viewer,?array $project=null,int $limit=30): array {
    $like='%'.$term.'%';$entities=[];
    try{$fuzzy=str_contains($term,' ')?0:1;$q=$pdo->prepare("SELECT de.public_id,de.entity_type,de.canonical_name,de.description,COUNT(DISTINCT CONCAT(dem.object_type,':',dem.object_public_id)) mention_count,MAX(dem.updated_at) last_activity FROM discovery_entities de LEFT JOIN discovery_entity_mentions dem ON dem.entity_id=de.id WHERE de.status='active' AND (de.canonical_name LIKE ? OR de.description LIKE ? OR EXISTS(SELECT 1 FROM discovery_entity_aliases da WHERE da.entity_id=de.id AND da.alias_name LIKE ?) OR (?=1 AND SOUNDEX(de.canonical_name)=SOUNDEX(?))) GROUP BY de.id ORDER BY mention_count DESC,de.canonical_name LIMIT ".$limit);$q->execute([$like,$like,$like,$fuzzy,$term]);foreach($q->fetchAll() as $e){$e['scope']='public';$entities[]=$e;}}catch(PDOException $e){}
    if($viewer){$uid=(int)$viewer['id'];$params=[$uid,$uid,$uid,$like,$like];$projectClause='';if($project){$projectClause=' AND rp.id=?';$params[]=$project['id'];}$q=$pdo->prepare("SELECT re.public_id,re.entity_type,re.canonical_name,re.description,(SELECT COUNT(*) FROM research_entity_mentions rem WHERE rem.entity_id=re.id) mention_count,rp.public_id project_public_id,rp.title project_title FROM research_entities re JOIN research_projects rp ON rp.id=re.project_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE (rp.owner_user_id=? OR tm.user_id=?) AND re.status<>'archived' AND (re.canonical_name LIKE ? OR re.description LIKE ?)$projectClause ORDER BY mention_count DESC,re.canonical_name LIMIT ".$limit);$q->execute($params);foreach($q->fetchAll() as $e){$e['scope']='research';$entities[]=$e;}}
    return array_slice($entities,0,$limit);
}
function search_record_recent(PDO $pdo,array $viewer,string $term,array $filters): void {
    if($term==='')return;$json=json_encode($filters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$term.'|'.$json);
    try{$pdo->prepare('INSERT INTO search_recent_queries(user_id,query_hash,query_text,filters_json,use_count,last_used_at) VALUES(?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE query_text=VALUES(query_text),filters_json=VALUES(filters_json),use_count=use_count+1,last_used_at=NOW()')->execute([$viewer['id'],$hash,$term,$json]);$pdo->prepare('DELETE FROM search_recent_queries WHERE user_id=? AND query_hash NOT IN (SELECT query_hash FROM (SELECT query_hash FROM search_recent_queries WHERE user_id=? ORDER BY last_used_at DESC LIMIT 20) x)')->execute([$viewer['id'],$viewer['id']]);}catch(PDOException $e){}
}
function search_recent(PDO $pdo,array $viewer,int $limit=10): array {
    $q=$pdo->prepare('SELECT query_text,filters_json,use_count,last_used_at FROM search_recent_queries WHERE user_id=? ORDER BY last_used_at DESC LIMIT '.max(1,min(20,$limit)));$q->execute([$viewer['id']]);$rows=$q->fetchAll();foreach($rows as &$r){$r['filters']=json_decode((string)$r['filters_json'],true)?:[];unset($r['filters_json']);}unset($r);return $rows;
}
function search_saved_access(PDO $pdo,array $viewer,string $publicId): ?array {
    $q=$pdo->prepare('SELECT ss.*,t.public_id team_public_id,t.name team_name,rp.public_id project_public_id,rp.title project_title FROM saved_searches ss LEFT JOIN teams t ON t.id=ss.team_id LEFT JOIN research_projects rp ON rp.id=ss.project_id WHERE ss.public_id=? LIMIT 1');$q->execute([$publicId]);$s=$q->fetch();if(!$s)return null;$uid=(int)$viewer['id'];if((int)$s['owner_user_id']===$uid)return $s;
    if($s['visibility']==='team'&&$s['team_id']){$q=$pdo->prepare('SELECT 1 FROM team_members WHERE team_id=? AND user_id=?');$q->execute([$s['team_id'],$uid]);if($q->fetchColumn())return $s;}
    if($s['visibility']==='project'&&$s['project_public_id']&&project_access($pdo,$uid,(string)$s['project_public_id']))return $s;return null;
}
function search_saved_create(PDO $pdo,array $viewer,string $title,string $term,array $filters,string $visibility='private',?string $scopePublicId=null,bool $alerts=false): array {
    $title=trim($title);$term=search_normalize_query($term);if($title===''||$term==='')throw new InvalidArgumentException('Saved search title and query are required.');$visibility=in_array($visibility,['private','team','project'],true)?$visibility:'private';$teamId=null;$projectId=null;
    if($visibility==='team'){$team=search_team_scope($pdo,$viewer,(string)$scopePublicId);if(!$team)throw new RuntimeException('Team access required.');$teamId=$team['id'];}
    if($visibility==='project'){$project=search_project_scope($pdo,$viewer,(string)$scopePublicId);if(!$project)throw new RuntimeException('Research access required.');$projectId=$project['id'];}
    $public=ulid_like();$cleanFilters=search_filters_from_input($filters);$pdo->prepare('INSERT INTO saved_searches(public_id,owner_user_id,title,query_text,filters_json,visibility,team_id,project_id,alerts_enabled) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$public,$viewer['id'],mb_substr($title,0,180),$term,json_encode($cleanFilters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$visibility,$teamId,$projectId,$alerts?1:0]);$id=(int)$pdo->lastInsertId();if($alerts)search_saved_seed_matches($pdo,$id,$viewer,$term,$cleanFilters);return ['public_id'=>$public];
}
function search_saved_list(PDO $pdo,array $viewer): array {
    $uid=(int)$viewer['id'];$q=$pdo->prepare("SELECT DISTINCT ss.public_id,ss.title,ss.query_text,ss.filters_json,ss.visibility,ss.alerts_enabled,ss.updated_at,t.public_id team_public_id,t.name team_name,rp.public_id project_public_id,rp.title project_title FROM saved_searches ss LEFT JOIN teams t ON t.id=ss.team_id LEFT JOIN team_members tm ON tm.team_id=ss.team_id AND tm.user_id=? LEFT JOIN research_projects rp ON rp.id=ss.project_id LEFT JOIN team_members rpm ON rpm.team_id=rp.team_id AND rpm.user_id=? WHERE ss.owner_user_id=? OR (ss.visibility='team' AND tm.user_id IS NOT NULL) OR (ss.visibility='project' AND (rp.owner_user_id=? OR rpm.user_id IS NOT NULL)) ORDER BY ss.updated_at DESC");$q->execute([$uid,$uid,$uid,$uid]);$rows=$q->fetchAll();foreach($rows as &$r){$r['filters']=json_decode((string)$r['filters_json'],true)?:[];unset($r['filters_json']);$r['alerts_enabled']=(bool)$r['alerts_enabled'];}unset($r);return $rows;
}
function search_saved_delete(PDO $pdo,array $viewer,string $publicId): bool {
    $q=$pdo->prepare('DELETE FROM saved_searches WHERE public_id=? AND owner_user_id=?');$q->execute([$publicId,$viewer['id']]);return $q->rowCount()>0;
}
function search_saved_seed_matches(PDO $pdo,int $savedSearchId,array $viewer,string $term,array $filters): void {
    $results=search_unified($pdo,$term,$viewer,$filters,false);foreach(search_flatten_results($results) as $obj)$pdo->prepare('INSERT IGNORE INTO saved_search_matches(saved_search_id,object_type,object_public_id,notified_at) VALUES(?,?,?,NOW())')->execute([$savedSearchId,$obj['type'],$obj['id']]);
}
function search_saved_alert_toggle(PDO $pdo,array $viewer,string $publicId,bool $enabled): bool {
    $q=$pdo->prepare('SELECT * FROM saved_searches WHERE public_id=? AND owner_user_id=? LIMIT 1');$q->execute([$publicId,$viewer['id']]);$saved=$q->fetch();if(!$saved)return false;$pdo->prepare('UPDATE saved_searches SET alerts_enabled=? WHERE id=?')->execute([$enabled?1:0,$saved['id']]);if($enabled){$filters=json_decode((string)$saved['filters_json'],true)?:[];search_saved_seed_matches($pdo,(int)$saved['id'],$viewer,(string)$saved['query_text'],$filters);}return true;
}
function search_flatten_results(array $results): array {
    $out=[];foreach(['sources'=>'source','annotations'=>'annotation','reports'=>'research_report','people'=>'person'] as $key=>$type)foreach(($results[$key]??[]) as $r)if(!empty($r['public_id']))$out[]=['type'=>$type,'id'=>(string)$r['public_id'],'label'=>(string)($r['title']??$r['display_name']??$r['text_commentary']??$r['public_id'])];foreach(($results['entities']??[]) as $e)if(($e['scope']??'')==='public'&&!empty($e['public_id']))$out[]=['type'=>'research_entity','id'=>(string)$e['public_id'],'label'=>(string)$e['canonical_name']];return $out;
}
function search_run_saved_alert(PDO $pdo,array $saved,array $owner): int {
    $filters=json_decode((string)($saved['filters_json']??''),true)?:[];$results=search_unified($pdo,(string)$saved['query_text'],$owner,$filters,false);$new=0;
    foreach(search_flatten_results($results) as $obj){$q=$pdo->prepare('INSERT IGNORE INTO saved_search_matches(saved_search_id,object_type,object_public_id) VALUES(?,?,?)');$q->execute([$saved['id'],$obj['type'],$obj['id']]);if($q->rowCount()!==1)continue;$new++;notification_create($pdo,(int)$owner['id'],null,'saved_search_match','saved_search',(string)$saved['public_id'],'New match for saved search “'.$saved['title'].'”: '.mb_substr($obj['label'],0,180),['category'=>'research','dedupe_key'=>'saved-search:'.$saved['id'].':'.$obj['type'].':'.$obj['id'],'group_key'=>'saved-search:'.$saved['public_id'],'context'=>['saved_search_public_id'=>$saved['public_id'],'match_type'=>$obj['type'],'match_public_id'=>$obj['id']]]);$pdo->prepare('UPDATE saved_search_matches SET notified_at=NOW() WHERE saved_search_id=? AND object_type=? AND object_public_id=?')->execute([$saved['id'],$obj['type'],$obj['id']]);}
    $pdo->prepare('UPDATE saved_searches SET last_alerted_at=NOW() WHERE id=?')->execute([$saved['id']]);return $new;
}
function search_saved_alert_worker(PDO $pdo,int $limit=50): int {
    $q=$pdo->query("SELECT ss.*,u.public_id user_public_id,u.username,u.display_name,u.email,u.role,u.status FROM saved_searches ss JOIN users u ON u.id=ss.owner_user_id WHERE ss.alerts_enabled=1 AND u.status='active' ORDER BY COALESCE(ss.last_alerted_at,'1970-01-01') ASC LIMIT ".max(1,min(200,$limit)));$count=0;foreach($q->fetchAll() as $s){$owner=['id'=>$s['owner_user_id'],'public_id'=>$s['user_public_id'],'username'=>$s['username'],'display_name'=>$s['display_name'],'email'=>$s['email'],'role'=>$s['role'],'status'=>$s['status']];$count+=search_run_saved_alert($pdo,$s,$owner);}return $count;
}
function search_saved_notification_access(PDO $pdo,array $viewer,string $publicId): bool {return search_saved_access($pdo,$viewer,$publicId)!==null;}
function search_remove_public_report_entities(PDO $pdo,string $reportPublicId): void {
    try{$pdo->prepare('DELETE FROM discovery_entity_mentions WHERE origin_report_public_id=?')->execute([$reportPublicId]);}catch(PDOException $e){}
}
function search_sync_public_report_entities(PDO $pdo,string $reportPublicId): int {
    $q=$pdo->prepare("SELECT rr.public_id,rv.snapshot_json FROM research_reports rr JOIN research_report_versions rv ON rv.id=rr.current_version_id WHERE rr.public_id=? AND rr.status='published' AND rr.visibility='public' LIMIT 1");$q->execute([$reportPublicId]);$r=$q->fetch();if(!$r)return 0;$snapshot=json_decode((string)$r['snapshot_json'],true);if(!is_array($snapshot))return 0;$pdo->prepare('DELETE FROM discovery_entity_mentions WHERE origin_report_public_id=?')->execute([$reportPublicId]);$count=0;
    foreach(($snapshot['entities']??[]) as $e){$name=trim((string)($e['name']??''));if($name==='')continue;$type=in_array((string)($e['type']??'other'),['person','company','organization','place','event','topic','date','other'],true)?(string)$e['type']:'other';$norm=search_normalized_name($name);$aq=$pdo->prepare("SELECT de.id FROM discovery_entity_aliases da JOIN discovery_entities de ON de.id=da.entity_id WHERE da.normalized_alias=? AND de.entity_type=? AND de.status='active' ORDER BY de.id LIMIT 1");$aq->execute([$norm,$type]);$entityId=(int)($aq->fetchColumn()?:0);if(!$entityId){$pdo->prepare("INSERT INTO discovery_entities(public_id,entity_type,canonical_name,normalized_name,description,status) VALUES(?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE canonical_name=IF(status='active',VALUES(canonical_name),canonical_name),description=IF(status='active',COALESCE(NULLIF(VALUES(description),''),description),description),updated_at=NOW()")->execute([ulid_like(),$type,$name,$norm,mb_substr(trim((string)($e['description']??'')),0,4000)?:null]);$q=$pdo->prepare("SELECT id FROM discovery_entities WHERE entity_type=? AND normalized_name=? AND status='active'");$q->execute([$type,$norm]);$entityId=(int)($q->fetchColumn()?:0);}if(!$entityId)continue;$pdo->prepare("INSERT INTO discovery_entity_mentions(entity_id,object_type,object_public_id,origin_report_public_id,mention_weight,excerpt) VALUES(?,'research_report',?,?,3.00,?) ON DUPLICATE KEY UPDATE mention_weight=VALUES(mention_weight),excerpt=VALUES(excerpt),updated_at=NOW()")->execute([$entityId,$reportPublicId,$reportPublicId,mb_substr((string)($e['description']??''),0,1000)?:null]);$count++;
        foreach(($e['mentions']??[]) as $m){if(!empty($m['annotation_id'])&&annotation_access($pdo,(string)$m['annotation_id'],null)){$aq=$pdo->prepare('SELECT source_id,text_commentary FROM annotations WHERE public_id=?');$aq->execute([$m['annotation_id']]);$a=$aq->fetch();if($a)$pdo->prepare("INSERT INTO discovery_entity_mentions(entity_id,object_type,object_public_id,origin_report_public_id,source_id,mention_weight,excerpt) VALUES(?,'annotation',?,?,?,2.00,?) ON DUPLICATE KEY UPDATE mention_weight=GREATEST(mention_weight,VALUES(mention_weight)),excerpt=VALUES(excerpt),updated_at=NOW()")->execute([$entityId,$m['annotation_id'],$reportPublicId,$a['source_id'],mb_substr((string)$a['text_commentary'],0,1000)?:null]);}if(!empty($m['source_id'])&&source_access($pdo,(string)$m['source_id'],null)){$sq=$pdo->prepare('SELECT id,title FROM sources WHERE public_id=?');$sq->execute([$m['source_id']]);$src=$sq->fetch();if($src)$pdo->prepare("INSERT INTO discovery_entity_mentions(entity_id,object_type,object_public_id,origin_report_public_id,source_id,mention_weight,excerpt) VALUES(?,'source',?,?,?,1.50,?) ON DUPLICATE KEY UPDATE mention_weight=GREATEST(mention_weight,VALUES(mention_weight)),updated_at=NOW()")->execute([$entityId,$m['source_id'],$reportPublicId,$src['id'],mb_substr((string)$src['title'],0,1000)]);}}
    }return $count;
}
function search_rebuild_public_entities(PDO $pdo,int $limit=500): int {
    $pdo->exec("DELETE dem FROM discovery_entity_mentions dem LEFT JOIN research_reports rr ON rr.public_id=dem.origin_report_public_id AND rr.status='published' AND rr.visibility='public' WHERE rr.id IS NULL");
    $q=$pdo->query("SELECT public_id FROM research_reports WHERE status='published' AND visibility='public' ORDER BY updated_at DESC LIMIT ".max(1,min(2000,$limit)));$count=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$count+=search_sync_public_report_entities($pdo,(string)$id);return $count;
}
function search_discovery_entity(PDO $pdo,string $publicId,?array $viewer): ?array {
    $q=$pdo->prepare("SELECT * FROM discovery_entities WHERE public_id=? AND status='active' LIMIT 1");$q->execute([$publicId]);$e=$q->fetch();if(!$e)return null;$q=$pdo->prepare('SELECT alias_name FROM discovery_entity_aliases WHERE entity_id=? ORDER BY alias_name');$q->execute([$e['id']]);$e['aliases']=$q->fetchAll(PDO::FETCH_COLUMN);$q=$pdo->prepare('SELECT object_type,object_public_id,MAX(mention_weight) mention_weight,MAX(excerpt) excerpt,MAX(updated_at) updated_at FROM discovery_entity_mentions WHERE entity_id=? GROUP BY object_type,object_public_id ORDER BY mention_weight DESC,updated_at DESC LIMIT 100');$q->execute([$e['id']]);$mentions=[];
    foreach($q->fetchAll() as $m){$ok=false;$url=null;$label=null;if($m['object_type']==='annotation'){$a=annotation_access($pdo,(string)$m['object_public_id'],$viewer);$ok=$a!==null;$url='/annotation.php?id='.rawurlencode((string)$m['object_public_id']);if($ok){$aq=$pdo->prepare('SELECT text_commentary FROM annotations WHERE public_id=?');$aq->execute([$m['object_public_id']]);$label=(string)($aq->fetchColumn()?:'Annotation');}}elseif($m['object_type']==='source'){$src=source_access($pdo,(string)$m['object_public_id'],$viewer);$ok=$src!==null;$url='/source.php?id='.rawurlencode((string)$m['object_public_id']);$label=$src['title']??'Source';}elseif($m['object_type']==='research_report'){$rq=$pdo->prepare('SELECT rr.*,rp.owner_user_id,rp.team_id FROM research_reports rr JOIN research_projects rp ON rp.id=rr.project_id WHERE rr.public_id=?');$rq->execute([$m['object_public_id']]);$r=$rq->fetch();$ok=$r&&search_report_visible($pdo,$r,$viewer);$url='/research-report.php?id='.rawurlencode((string)$m['object_public_id']);$label=$r['title']??'Research report';}if($ok){$m['url']=$url;$m['label']=$label;$mentions[]=$m;}}
    $e['mentions']=$mentions;return $e;
}
function search_related_sources(PDO $pdo,string $sourcePublicId,?array $viewer,int $limit=8): array {
    $source=search_source_visible($pdo,$sourcePublicId,$viewer);if(!$source)return [];[$a1,$p1]=search_annotation_access_sql($viewer,'a1');[$a2,$p2]=search_annotation_access_sql($viewer,'a2');$b1=feed_block_sql($viewer,'a1.user_id');$b2=feed_block_sql($viewer,'a2.user_id');
    $sql="SELECT s2.public_id,s2.title,s2.domain,s2.canonical_url,COUNT(DISTINCT a2.user_id) shared_contributors FROM annotations a1 JOIN annotations a2 ON a2.user_id=a1.user_id AND a2.source_id<>a1.source_id JOIN sources s2 ON s2.id=a2.source_id WHERE a1.source_id=? AND $a1 AND $a2 AND $b1 AND $b2 AND COALESCE(s2.moderation_status,'visible')='visible' GROUP BY s2.id ORDER BY shared_contributors DESC,MAX(a2.published_at) DESC LIMIT 40";
    $q=$pdo->prepare($sql);$q->execute(array_merge([$source['id']],$p1,$p2));$out=[];foreach($q->fetchAll() as $r){if(source_access($pdo,(string)$r['public_id'],$viewer))$out[]=$r;if(count($out)>=$limit)break;}return $out;
}
function search_related_annotations(PDO $pdo,string $annotationPublicId,?array $viewer,int $limit=8): array {
    $base=annotation_access($pdo,$annotationPublicId,$viewer);if(!$base)return [];[$access,$params]=search_annotation_access_sql($viewer,'a');$block=feed_block_sql($viewer,'a.user_id');
    $q=$pdo->prepare("SELECT DISTINCT a.id,a.public_id,a.text_commentary,a.published_at,u.username,u.display_name,s.public_id source_public_id,s.title source_title,s.domain,c.selected_text,a.source_version_id,s.current_version_id FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id WHERE a.id<>? AND a.source_id=? AND $access AND $block AND COALESCE(s.moderation_status,'visible')='visible' ORDER BY a.published_at DESC LIMIT 30");$q->execute(array_merge([$base['id'],$base['source_id']],$params));$rows=$q->fetchAll();
    try{$eq=$pdo->prepare("SELECT DISTINCT other.object_public_id FROM discovery_entity_mentions mine JOIN discovery_entity_mentions other ON other.entity_id=mine.entity_id AND other.object_type='annotation' WHERE mine.object_type='annotation' AND mine.object_public_id=? AND other.object_public_id<>? LIMIT 30");$eq->execute([$annotationPublicId,$annotationPublicId]);foreach($eq->fetchAll(PDO::FETCH_COLUMN) as $pid){$a=annotation_access($pdo,(string)$pid,$viewer);if(!$a)continue;$exists=false;foreach($rows as $r)if($r['public_id']===$pid){$exists=true;break;}if($exists)continue;$rq=$pdo->prepare('SELECT a.id,a.public_id,a.text_commentary,a.published_at,u.username,u.display_name,s.public_id source_public_id,s.title source_title,s.domain,c.selected_text,a.source_version_id,s.current_version_id FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id WHERE a.public_id=?');$rq->execute([$pid]);if($row=$rq->fetch())$rows[]=$row;}}catch(PDOException $e){}
    $out=[];foreach($rows as $r){$r['integrity']=source_integrity_annotation_state($pdo,['id'=>(int)$r['id'],'source_version_id'=>(int)$r['source_version_id'],'current_source_version_id'=>(int)$r['current_version_id']]);unset($r['id'],$r['source_version_id'],$r['current_version_id']);$out[]=$r;if(count($out)>=$limit)break;}return $out;
}
function search_recommendations(PDO $pdo,array $viewer,int $limit=12): array {
    $uid=(int)$viewer['id'];[$access,$params]=search_annotation_access_sql($viewer,'a');$block=feed_block_sql($viewer,'a.user_id');$sql="SELECT DISTINCT a.public_id,a.text_commentary,a.published_at,u.display_name,u.username,s.public_id source_public_id,s.title source_title,s.domain,c.selected_text FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id LEFT JOIN follows f ON f.followed_user_id=a.user_id AND f.follower_user_id=? LEFT JOIN source_watches sw ON sw.source_id=a.source_id AND sw.user_id=? WHERE $access AND $block AND COALESCE(s.moderation_status,'visible')='visible' AND a.user_id<>? AND (f.followed_user_id IS NOT NULL OR sw.source_id IS NOT NULL) ORDER BY a.published_at DESC LIMIT ".max(1,min(50,$limit));$q=$pdo->prepare($sql);$q->execute(array_merge([$uid,$uid],$params,[$uid]));return $q->fetchAll();
}
function search_explore_intelligence(PDO $pdo,?array $viewer): array {
    $trending=$pdo->query("SELECT s.public_id,s.title,s.domain,COUNT(DISTINCT a.id) annotation_count,COUNT(DISTINCT c.id) comment_count,COUNT(DISTINCT a.user_id) contributor_count,MAX(a.published_at) last_activity FROM sources s JOIN annotations a ON a.source_id=s.id AND a.visibility='public' AND a.status='published' AND a.published_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) LEFT JOIN comments c ON c.annotation_id=a.id AND COALESCE(c.moderation_status,'visible')='visible' WHERE COALESCE(s.moderation_status,'visible')='visible' GROUP BY s.id ORDER BY (COUNT(DISTINCT a.id)*3+COUNT(DISTINCT c.id)*2+COUNT(DISTINCT a.user_id)) DESC,last_activity DESC LIMIT 12")->fetchAll();
    $topics=[];try{$topics=$pdo->query("SELECT de.public_id,de.entity_type,de.canonical_name,COUNT(DISTINCT dem.origin_report_public_id) mention_count,MAX(dem.updated_at) last_activity FROM discovery_entities de JOIN discovery_entity_mentions dem ON dem.entity_id=de.id WHERE de.status='active' AND dem.updated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY de.id ORDER BY mention_count DESC,last_activity DESC LIMIT 16")->fetchAll();}catch(PDOException $e){}
    return ['trending'=>$trending,'topics'=>$topics,'recommendations'=>$viewer?search_recommendations($pdo,$viewer,12):[]];
}

function search_admin_entity_rename(PDO $pdo,array $admin,string $publicId,string $name,?string $description=null): bool {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Admin access required.');$name=trim($name);if($name==='')throw new InvalidArgumentException('Entity name is required.');
    $q=$pdo->prepare("SELECT id,entity_type FROM discovery_entities WHERE public_id=? AND status='active' LIMIT 1");$q->execute([$publicId]);$e=$q->fetch();if(!$e)return false;$norm=search_normalized_name($name);
    $q=$pdo->prepare('SELECT id FROM discovery_entities WHERE entity_type=? AND normalized_name=? AND id<>? LIMIT 1');$q->execute([$e['entity_type'],$norm,$e['id']]);if($q->fetchColumn())throw new RuntimeException('An entity with that normalized name already exists.');
    $old=$pdo->prepare('SELECT canonical_name,normalized_name FROM discovery_entities WHERE id=?');$old->execute([$e['id']]);$prior=$old->fetch();if($prior)$pdo->prepare('INSERT IGNORE INTO discovery_entity_aliases(entity_id,alias_name,normalized_alias) VALUES(?,?,?)')->execute([$e['id'],$prior['canonical_name'],$prior['normalized_name']]);$pdo->prepare('UPDATE discovery_entities SET canonical_name=?,normalized_name=?,description=?,updated_at=NOW() WHERE id=?')->execute([mb_substr($name,0,255),$norm,$description!==null?mb_substr(trim($description),0,4000):null,$e['id']]);return true;
}
function search_admin_entity_alias(PDO $pdo,array $admin,string $publicId,string $alias): bool {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Admin access required.');$alias=trim($alias);if($alias==='')throw new InvalidArgumentException('Alias is required.');$q=$pdo->prepare("SELECT id FROM discovery_entities WHERE public_id=? AND status='active' LIMIT 1");$q->execute([$publicId]);$id=(int)($q->fetchColumn()?:0);if(!$id)return false;$pdo->prepare('INSERT IGNORE INTO discovery_entity_aliases(entity_id,alias_name,normalized_alias) VALUES(?,?,?)')->execute([$id,mb_substr($alias,0,255),search_normalized_name($alias)]);return true;
}
function search_admin_entity_merge(PDO $pdo,array $admin,string $fromPublicId,string $intoPublicId): bool {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Admin access required.');if($fromPublicId===$intoPublicId)throw new InvalidArgumentException('Choose two different entities.');
    $pdo->beginTransaction();try{$q=$pdo->prepare("SELECT * FROM discovery_entities WHERE public_id=? FOR UPDATE");$q->execute([$fromPublicId]);$from=$q->fetch();$q->execute([$intoPublicId]);$into=$q->fetch();if(!$from||!$into||$from['status']!=='active'||$into['status']!=='active')throw new RuntimeException('Entity not available for merge.');if($from['entity_type']!==$into['entity_type'])throw new RuntimeException('Only entities of the same type can be merged.');
        $q=$pdo->prepare('SELECT object_type,object_public_id,origin_report_public_id,source_id,mention_weight,excerpt FROM discovery_entity_mentions WHERE entity_id=?');$q->execute([$from['id']]);foreach($q->fetchAll() as $m)$pdo->prepare('INSERT INTO discovery_entity_mentions(entity_id,object_type,object_public_id,origin_report_public_id,source_id,mention_weight,excerpt) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE mention_weight=GREATEST(mention_weight,VALUES(mention_weight)),excerpt=COALESCE(VALUES(excerpt),excerpt),updated_at=NOW()')->execute([$into['id'],$m['object_type'],$m['object_public_id'],$m['origin_report_public_id'],$m['source_id'],$m['mention_weight'],$m['excerpt']]);
        $q=$pdo->prepare('SELECT alias_name,normalized_alias FROM discovery_entity_aliases WHERE entity_id=?');$q->execute([$from['id']]);foreach($q->fetchAll() as $a)$pdo->prepare('INSERT IGNORE INTO discovery_entity_aliases(entity_id,alias_name,normalized_alias) VALUES(?,?,?)')->execute([$into['id'],$a['alias_name'],$a['normalized_alias']]);$pdo->prepare('INSERT IGNORE INTO discovery_entity_aliases(entity_id,alias_name,normalized_alias) VALUES(?,?,?)')->execute([$into['id'],$from['canonical_name'],$from['normalized_name']]);
        $pdo->prepare("UPDATE discovery_entities SET status='merged',merged_into_id=?,updated_at=NOW() WHERE id=?")->execute([$into['id'],$from['id']]);$pdo->commit();return true;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
