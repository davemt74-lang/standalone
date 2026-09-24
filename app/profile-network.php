<?php
declare(strict_types=1);

function profile_network_public_topics(PDO $pdo,int $userId,int $limit=6): array {
    $limit=max(1,min(12,$limit));$out=[];
    try{
        $q=$pdo->prepare("SELECT de.public_id,de.canonical_name,de.entity_type,COUNT(*) mention_count
          FROM discovery_entity_mentions dem
          JOIN discovery_entities de ON de.id=dem.entity_id AND de.status='active'
          JOIN research_reports rr ON rr.public_id=dem.origin_report_public_id
          WHERE rr.created_by_user_id=? AND rr.visibility='public' AND rr.status='published'
          GROUP BY de.id ORDER BY mention_count DESC,de.canonical_name LIMIT ".$limit);
        $q->execute([$userId]);$out=$q->fetchAll()?:[];
    }catch(Throwable $e){}
    if($out)return $out;
    try{
        $q=$pdo->prepare("SELECT s.domain canonical_name,'domain' entity_type,COUNT(DISTINCT a.id) mention_count
          FROM annotations a JOIN sources s ON s.id=a.source_id
          WHERE a.user_id=? AND a.visibility='public' AND a.status='published' AND s.domain<>''
          GROUP BY s.domain ORDER BY mention_count DESC,s.domain LIMIT ".$limit);
        $q->execute([$userId]);$out=$q->fetchAll()?:[];
    }catch(Throwable $e){}
    return $out;
}

function profile_network_enrich_person(PDO $pdo,array $person,?array $viewer=null): array {
    $id=(int)($person['id']??0);
    if($id<=0&&!empty($person['public_id'])){$q=$pdo->prepare('SELECT id FROM users WHERE public_id=? LIMIT 1');$q->execute([(string)$person['public_id']]);$id=(int)($q->fetchColumn()?:0);}
    if($id<=0&&!empty($person['username'])){$q=$pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$q->execute([(string)$person['username']]);$id=(int)($q->fetchColumn()?:0);}
    if($id<=0)return $person;
    $person['id']=$id;$q=$pdo->prepare("SELECT
      (SELECT COUNT(*) FROM annotations a WHERE a.user_id=? AND a.visibility='public' AND a.status='published') annotation_count,
      (SELECT COUNT(*) FROM research_reports rr WHERE rr.created_by_user_id=? AND rr.visibility='public' AND rr.status='published') report_count,
      (SELECT COUNT(*) FROM follows f WHERE f.followed_user_id=?) follower_count");
    $q->execute([$id,$id,$id]);$person=array_merge($person,$q->fetch()?:[]);
    $person['topics']=profile_network_public_topics($pdo,$id,5);
    $viewerId=(int)($viewer['id']??0);$person['following']=false;$person['mutual_count']=0;
    if($viewerId&&$viewerId!==$id){
        $q=$pdo->prepare('SELECT 1 FROM follows WHERE follower_user_id=? AND followed_user_id=? LIMIT 1');$q->execute([$viewerId,$id]);$person['following']=(bool)$q->fetchColumn();
        $q=$pdo->prepare("SELECT COUNT(*) FROM follows mine JOIN follows theirs ON theirs.followed_user_id=mine.followed_user_id WHERE mine.follower_user_id=? AND theirs.follower_user_id=?");$q->execute([$viewerId,$id]);$person['mutual_count']=(int)$q->fetchColumn();
    }
    unset($person['id']);return $person;
}

function profile_network_discover_people(PDO $pdo,?array $viewer,string $term='',int $limit=48): array {
    $limit=max(1,min(100,$limit));$uid=(int)($viewer['id']??0);$term=trim($term);$params=[];$where=["u.status='active'","COALESCE(p.profile_visibility,'public')='public'","COALESCE(p.search_visibility,1)=1"];
    if($uid){$where[]='u.id<>?';$params[]=$uid;$where[]="NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=$uid AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=$uid))";}
    if($term!==''){$where[]='(u.username LIKE ? OR u.display_name LIKE ? OR u.bio LIKE ? OR EXISTS(SELECT 1 FROM research_reports rr WHERE rr.created_by_user_id=u.id AND rr.visibility="public" AND rr.status="published" AND (rr.title LIKE ? OR rr.summary LIKE ?)))';$like='%'.$term.'%';array_push($params,$like,$like,$like,$like,$like);}
    $sql="SELECT u.id,u.public_id,u.username,u.display_name,u.bio,u.profile_image_url,u.created_at FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE ".implode(' AND ',$where)." ORDER BY
      (SELECT COUNT(*) FROM research_reports rr WHERE rr.created_by_user_id=u.id AND rr.visibility='public' AND rr.status='published') DESC,
      (SELECT COUNT(*) FROM annotations a WHERE a.user_id=u.id AND a.visibility='public' AND a.status='published') DESC,u.created_at DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row=profile_network_enrich_person($pdo,$row,$viewer);unset($row);return $rows;
}

function profile_network_suggested_people(PDO $pdo,array $viewer,int $limit=12): array {
    $uid=(int)$viewer['id'];if($uid<1)return [];$candidates=profile_network_discover_people($pdo,$viewer,'',80);$out=[];
    $sharedSources=$pdo->prepare("SELECT COUNT(DISTINCT a2.source_id)
      FROM annotations a1 JOIN annotations a2 ON a2.source_id=a1.source_id
      WHERE a1.user_id=? AND a2.user_id=? AND a1.visibility='public' AND a1.status='published' AND a2.visibility='public' AND a2.status='published'");
    $sharedTopics=$pdo->prepare("SELECT COUNT(DISTINCT dm1.entity_id)
      FROM discovery_entity_mentions dm1 JOIN research_reports r1 ON r1.public_id=dm1.origin_report_public_id
      JOIN discovery_entity_mentions dm2 ON dm2.entity_id=dm1.entity_id
      JOIN research_reports r2 ON r2.public_id=dm2.origin_report_public_id
      WHERE r1.created_by_user_id=? AND r2.created_by_user_id=? AND r1.visibility='public' AND r1.status='published' AND r2.visibility='public' AND r2.status='published'");
    foreach($candidates as $person){
        if(!empty($person['following']))continue;$q=$pdo->prepare('SELECT id FROM users WHERE public_id=? LIMIT 1');$q->execute([(string)$person['public_id']]);$candidateId=(int)($q->fetchColumn()?:0);if(!$candidateId)continue;
        $sharedSources->execute([$uid,$candidateId]);$ss=(int)$sharedSources->fetchColumn();
        $st=0;try{$sharedTopics->execute([$uid,$candidateId]);$st=(int)$sharedTopics->fetchColumn();}catch(Throwable $e){}
        $mutual=(int)($person['mutual_count']??0);$score=$st*8+$mutual*5+$ss*3+min(4,(int)($person['report_count']??0));
        if($score<=0)continue;$reasons=[];if($st)$reasons[]=$st.' shared Research topic'.($st===1?'':'s');if($mutual)$reasons[]=$mutual.' mutual connection'.($mutual===1?'':'s');if($ss)$reasons[]=$ss.' shared public Source'.($ss===1?'':'s');
        $person['recommendation_score']=$score;$person['recommendation_reasons']=$reasons;$out[]=$person;
    }
    usort($out,static fn($a,$b)=>(int)$b['recommendation_score']<=>(int)$a['recommendation_score'] ?: strcmp((string)$a['display_name'],(string)$b['display_name']));
    return array_slice($out,0,max(1,min(30,$limit)));
}

function profile_network_following_activity(PDO $pdo,array $viewer,int $limit=30): array {
    $uid=(int)$viewer['id'];if($uid<1)return [];$limit=max(1,min(60,$limit));$rows=[];
    $block="NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=$uid AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=$uid))";
    $q=$pdo->prepare("SELECT rr.public_id,rr.title,rr.summary,rr.published_at created_at,rv.version_number,u.username,u.display_name,u.profile_image_url
      FROM research_reports rr JOIN research_report_versions rv ON rv.id=rr.current_version_id JOIN users u ON u.id=rr.created_by_user_id
      JOIN follows f ON f.followed_user_id=u.id AND f.follower_user_id=?
      WHERE rr.visibility='public' AND rr.status='published' AND $block ORDER BY rr.published_at DESC LIMIT ".$limit);$q->execute([$uid]);
    foreach($q->fetchAll() as $r)$rows[]=['kind'=>'research_report','created_at'=>(string)$r['created_at'],'row'=>$r];
    $q=$pdo->prepare("SELECT c.public_id,c.title,c.description,c.updated_at created_at,u.username,u.display_name,u.profile_image_url,
      (SELECT COUNT(*) FROM collection_items ci JOIN annotations a ON a.id=ci.annotation_id WHERE ci.collection_id=c.id AND a.visibility='public' AND a.status='published') item_count
      FROM collections c JOIN users u ON u.id=c.owner_user_id JOIN follows f ON f.followed_user_id=u.id AND f.follower_user_id=?
      WHERE c.visibility='public' AND $block ORDER BY c.updated_at DESC LIMIT ".$limit);$q->execute([$uid]);
    foreach($q->fetchAll() as $c)$rows[]=['kind'=>'collection','created_at'=>(string)$c['created_at'],'row'=>$c];
    usort($rows,static fn($a,$b)=>(strtotime((string)$b['created_at'])?:0)<=>(strtotime((string)$a['created_at'])?:0));return array_slice($rows,0,$limit);
}

function profile_network_notify_followers_report(PDO $pdo,array $publisher,array $publish): int {
    $report=(string)($publish['public_id']??'');if($report==='')return 0;$q=$pdo->prepare("SELECT visibility,status,title FROM research_reports WHERE public_id=? LIMIT 1");$q->execute([$report]);$r=$q->fetch();if(!$r||$r['visibility']!=='public'||$r['status']!=='published')return 0;
    $q=$pdo->prepare("SELECT f.follower_user_id FROM follows f JOIN users u ON u.id=f.follower_user_id AND u.status='active' WHERE f.followed_user_id=? AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=f.follower_user_id AND b.blocked_user_id=f.followed_user_id) OR (b.blocker_user_id=f.followed_user_id AND b.blocked_user_id=f.follower_user_id))");$q->execute([(int)$publisher['id']]);$sent=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid){$uid=(int)$uid;if(notification_create($pdo,$uid,(int)$publisher['id'],'followed_research_published','research_report',$report,$publisher['display_name'].' published Research: '.$r['title'],['category'=>'research','dedupe_key'=>'followed-research:'.$report.':v'.(int)($publish['version_number']??1).':'.$uid,'group_key'=>'followed-research:'.$report,'context'=>['version_number'=>(int)($publish['version_number']??1),'actor_public_id'=>$publisher['public_id']]]))$sent++;}
    return $sent;
}

function profile_network_report_plaintext(array $snapshot,string $summary=''): string {
    $parts=[];if(trim($summary)!=='')$parts[]=$summary;
    foreach((array)($snapshot['findings']??[]) as $f){$text=trim((string)(($f['title']??'').' '.($f['summary']??'')));if($text!=='')$parts[]=$text;}
    foreach((array)($snapshot['claims']??[]) as $c){$text=trim((string)($c['statement']??''));if($text!=='')$parts[]=$text;}
    foreach((array)($snapshot['entities']??[]) as $e){$text=trim((string)(($e['name']??'').' '.($e['description']??'')));if($text!=='')$parts[]=$text;}
    return mb_substr(implode("\n\n",$parts),0,200000);
}

function profile_network_add_to_agent(PDO $pdo,array $config,array $viewer,string $agentPublic,string $type,string $publicId): array {
    $project=research_agent_workspace_project($pdo,$viewer,$agentPublic);if(!$project)throw new RuntimeException('Research Agent is unavailable.');research_agent_workspace_require_write($project);
    if($type==='annotation'){
        $a=annotation_access($pdo,$publicId,$viewer);if(!$a||$a['status']!=='published')throw new RuntimeException('Annotation is unavailable.');
        $pdo->prepare('INSERT IGNORE INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$a['id'],(int)$viewer['id']]);
        return ['type'=>'annotation','agent_public_id'=>$agentPublic,'project_public_id'=>$project['public_id']];
    }
    if($type!=='research_report')throw new InvalidArgumentException('This public item cannot be added to Research.');
    $q=$pdo->prepare("SELECT rr.*,rv.id version_id,rv.version_number,rv.snapshot_json,rv.snapshot_hash,u.id author_id,u.username,u.display_name
      FROM research_reports rr JOIN research_report_versions rv ON rv.id=rr.current_version_id JOIN users u ON u.id=rr.created_by_user_id
      WHERE rr.public_id=? AND rr.visibility='public' AND rr.status='published' LIMIT 1");$q->execute([$publicId]);$r=$q->fetch();if(!$r||is_blocked($pdo,(int)$viewer['id'],(int)$r['author_id']))throw new RuntimeException('Published Research is unavailable.');
    $url=rtrim((string)($config['app']['base_url']??''),'/').'/research-report.php?id='.rawurlencode($publicId);if(!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('Annotated public URL is not configured.');
    $source=ensure_source($pdo,$url,(string)$r['title']);$snapshot=json_decode((string)$r['snapshot_json'],true)?:[];$text=profile_network_report_plaintext($snapshot,(string)($r['summary']??''));$hash=(string)$r['snapshot_hash'];
    $q=$pdo->prepare('SELECT id,content_hash,version_number FROM source_versions WHERE source_id=? ORDER BY version_number DESC LIMIT 1');$q->execute([(int)$source['id']]);$latest=$q->fetch();
    if(!$latest||!hash_equals((string)$latest['content_hash'],$hash)){$next=(int)($latest['version_number']??0)+1;$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,metadata_json) VALUES(?,?,?,?,?,?,?)')->execute([(int)$source['id'],$next,$url,(string)$r['title'],$text!==''?$text:null,$hash,json_encode(['origin'=>'annotated_public_research','research_report_public_id'=>$publicId,'report_version'=>(int)$r['version_number'],'author_username'=>$r['username']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$versionId=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE sources SET current_version_id=?,title=?,status='current',last_checked_at=NOW() WHERE id=?")->execute([$versionId,(string)$r['title'],(int)$source['id']]);}
    $pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$viewer['id']]);
    $bookmark=research_agent_workspace_create_bookmark($pdo,$viewer,$project,['url'=>$url,'title'=>(string)$r['title'],'description'=>'Published Research by '.$r['display_name'].' · version '.(int)$r['version_number']]);
    return ['type'=>'research_report','agent_public_id'=>$agentPublic,'project_public_id'=>$project['public_id'],'bookmark_public_id'=>$bookmark['public_id']??null];
}
