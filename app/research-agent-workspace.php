<?php
declare(strict_types=1);

function research_agent_workspace_ready(PDO $pdo): bool {
    try{
        return installer_table_exists($pdo,'research_workspace_objects')
            &&installer_table_exists($pdo,'research_workspace_bookmarks');
    }catch(Throwable $e){return false;}
}

function research_agent_workspace_project(PDO $pdo,array $viewer,string $agentPublic='',string $projectPublic=''): ?array {
    $agentPublic=trim($agentPublic);$projectPublic=trim($projectPublic);
    if($agentPublic!==''){
        $agent=research_agent_access($pdo,$viewer,$agentPublic);if(!$agent)return null;
        $project=project_access($pdo,(int)$viewer['id'],(string)$agent['project_public_id']);if(!$project)return null;
        $project['research_agent']=$agent;
        return $project;
    }
    if($projectPublic==='')return null;
    return project_access($pdo,(int)$viewer['id'],$projectPublic);
}

function research_agent_workspace_require_write(array $project): void {
    if(!project_can_write($project))throw new RuntimeException('You have view-only access to this Research workspace.');
}

function research_agent_workspace_parent(PDO $pdo,int $projectId,string $parentPublic=''): ?array {
    $parentPublic=trim($parentPublic);if($parentPublic==='')return null;
    $q=$pdo->prepare("SELECT * FROM research_workspace_objects WHERE public_id=? AND project_id=? AND object_type='folder' AND status='active' LIMIT 1");
    $q->execute([$parentPublic,$projectId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Choose an available folder in this Research Agent.');
    return $row;
}

function research_agent_workspace_object(PDO $pdo,array $viewer,string $publicId,bool $includeTrashed=true): ?array {
    if(!research_agent_workspace_ready($pdo))return null;
    $publicId=trim($publicId);if($publicId==='')return null;
    $q=$pdo->prepare("SELECT rwo.*,rp.public_id project_public_id,rp.title project_title,
      ra.public_id research_agent_public_id,ra.name research_agent_name,ac.public_id conversation_public_id,
      t.public_id team_public_id,t.name team_name,
      u.public_id creator_public_id,u.username creator_username,u.display_name creator_name,
      parent.public_id parent_public_id,parent.title parent_title,
      rwb.source_id,rwb.canonical_url,rwb.domain,rwb.description,rwb.favicon_url,rwb.preview_image_url,
      s.public_id source_public_id,s.title source_title
      FROM research_workspace_objects rwo
      JOIN research_projects rp ON rp.id=rwo.project_id
      LEFT JOIN research_agents ra ON ra.project_id=rp.id AND ra.status<>'archived'
      LEFT JOIN conversations ac ON ac.id=ra.conversation_id
      LEFT JOIN teams t ON t.id=rp.team_id
      JOIN users u ON u.id=rwo.created_by_user_id
      LEFT JOIN research_workspace_objects parent ON parent.id=rwo.parent_id
      LEFT JOIN research_workspace_bookmarks rwb ON rwb.object_id=rwo.id
      LEFT JOIN sources s ON s.id=rwb.source_id
      WHERE rwo.public_id=? LIMIT 1");
    $q->execute([$publicId]);$row=$q->fetch();if(!$row)return null;
    if(!$includeTrashed&&($row['status']??'')!=='active')return null;
    if(!project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']))return null;
    $row['metadata']=json_decode((string)($row['metadata_json']??''),true)?:[];
    return $row;
}

function research_agent_workspace_list(PDO $pdo,array $viewer,array $project,bool $trashed=false,int $limit=300): array {
    if(!research_agent_workspace_ready($pdo))return [];
    $limit=max(1,min(500,$limit));$status=$trashed?'trashed':'active';
    $q=$pdo->prepare("SELECT rwo.public_id,rwo.object_type,rwo.title,rwo.sort_order,rwo.metadata_json,rwo.status,rwo.created_at,rwo.updated_at,rwo.trashed_at,
      parent.public_id parent_public_id,parent.title parent_title,
      t.public_id team_public_id,t.name team_name,
      u.public_id creator_public_id,u.username creator_username,u.display_name creator_name,
      rwb.canonical_url,rwb.domain,rwb.description,rwb.favicon_url,rwb.preview_image_url,
      s.public_id source_public_id,s.title source_title
      FROM research_workspace_objects rwo
      JOIN research_projects rp ON rp.id=rwo.project_id
      LEFT JOIN teams t ON t.id=rp.team_id
      JOIN users u ON u.id=rwo.created_by_user_id
      LEFT JOIN research_workspace_objects parent ON parent.id=rwo.parent_id
      LEFT JOIN research_workspace_bookmarks rwb ON rwb.object_id=rwo.id
      LEFT JOIN sources s ON s.id=rwb.source_id
      WHERE rwo.project_id=? AND rwo.status=?
      ORDER BY CASE WHEN rwo.object_type='folder' THEN 0 ELSE 1 END,rwo.sort_order,rwo.title,rwo.id
      LIMIT ".$limit);
    $q->execute([(int)$project['id'],$status]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row['metadata']=json_decode((string)($row['metadata_json']??''),true)?:[];
    unset($row);
    return $rows;
}

function research_agent_workspace_create_folder(PDO $pdo,array $viewer,array $project,string $title,string $parentPublic=''): array {
    if(!research_agent_workspace_ready($pdo))throw new RuntimeException('Research Agent workspace requires the latest database upgrade.');
    research_agent_workspace_require_write($project);
    $title=mb_substr(trim($title),0,240);if($title==='')throw new InvalidArgumentException('Folder name is required.');
    $parent=research_agent_workspace_parent($pdo,(int)$project['id'],$parentPublic);
    $public=ulid_like();
    $pdo->prepare("INSERT INTO research_workspace_objects(public_id,project_id,parent_id,created_by_user_id,object_type,title) VALUES(?,?,?,?, 'folder',?)")
        ->execute([$public,(int)$project['id'],$parent['id']??null,(int)$viewer['id'],$title]);
    return research_agent_workspace_object($pdo,$viewer,$public,false)??['public_id'=>$public,'object_type'=>'folder','title'=>$title];
}

function research_agent_workspace_safe_asset_url(string $url): ?string {
    $url=trim($url);if($url==='')return null;
    if(strlen($url)>3000||!filter_var($url,FILTER_VALIDATE_URL))return null;
    $scheme=strtolower((string)(parse_url($url,PHP_URL_SCHEME)?:''));
    return in_array($scheme,['http','https'],true)?$url:null;
}

function research_agent_workspace_create_bookmark(PDO $pdo,array $viewer,array $project,array $input): array {
    if(!research_agent_workspace_ready($pdo))throw new RuntimeException('Research Agent workspace requires the latest database upgrade.');
    research_agent_workspace_require_write($project);
    $url=trim((string)($input['url']??''));if(!filter_var($url,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Enter a valid website URL.');
    $scheme=strtolower((string)(parse_url($url,PHP_URL_SCHEME)?:''));if(!in_array($scheme,['http','https'],true))throw new InvalidArgumentException('Bookmarks must use http or https.');
    $canonical=canonicalize_url($url);$hash=hash('sha256',$canonical);$domain=strtolower((string)(parse_url($canonical,PHP_URL_HOST)?:''));
    $parent=research_agent_workspace_parent($pdo,(int)$project['id'],(string)($input['parent_id']??''));
    $title=mb_substr(trim((string)($input['title']??'')),0,240);
    $description=mb_substr(trim((string)($input['description']??'')),0,5000);
    $source=ensure_source($pdo,$canonical,$title!==''?$title:null);
    if($title==='')$title=mb_substr(trim((string)($source['title']??''))?:($domain!==''?$domain:$canonical),0,240);

    $q=$pdo->prepare("SELECT rwo.public_id,rwo.status FROM research_workspace_bookmarks rwb JOIN research_workspace_objects rwo ON rwo.id=rwb.object_id WHERE rwb.project_id=? AND rwb.url_hash=? LIMIT 1");
    $q->execute([(int)$project['id'],$hash]);$existing=$q->fetch();
    if($existing){
        $obj=research_agent_workspace_object($pdo,$viewer,(string)$existing['public_id'],true);
        if(!$obj)throw new RuntimeException('Existing bookmark is unavailable.');
        $pdo->prepare("UPDATE research_workspace_objects SET parent_id=?,title=?,status='active',trashed_at=NULL,trashed_by_user_id=NULL,updated_at=NOW() WHERE id=?")
            ->execute([$parent['id']??null,$title,(int)$obj['id']]);
        $pdo->prepare("UPDATE research_workspace_bookmarks SET source_id=?,canonical_url=?,domain=?,description=?,favicon_url=?,preview_image_url=?,updated_at=NOW() WHERE object_id=?")
            ->execute([(int)$source['id'],$canonical,$domain?:null,$description!==''?$description:null,research_agent_workspace_safe_asset_url((string)($input['favicon_url']??'')),research_agent_workspace_safe_asset_url((string)($input['preview_image_url']??'')),(int)$obj['id']]);
        $pdo->prepare("INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)")->execute([(int)$project['id'],(int)$source['id'],(int)$viewer['id']]);
        return research_agent_workspace_object($pdo,$viewer,(string)$existing['public_id'],false)??$obj;
    }

    $public=ulid_like();$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO research_workspace_objects(public_id,project_id,parent_id,created_by_user_id,object_type,title) VALUES(?,?,?,?, 'bookmark',?)")
            ->execute([$public,(int)$project['id'],$parent['id']??null,(int)$viewer['id'],$title]);
        $objectId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO research_workspace_bookmarks(object_id,project_id,source_id,canonical_url,url_hash,domain,description,favicon_url,preview_image_url) VALUES(?,?,?,?,?,?,?,?,?)")
            ->execute([$objectId,(int)$project['id'],(int)$source['id'],$canonical,$hash,$domain?:null,$description!==''?$description:null,research_agent_workspace_safe_asset_url((string)($input['favicon_url']??'')),research_agent_workspace_safe_asset_url((string)($input['preview_image_url']??''))]);
        $pdo->prepare("INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)")->execute([(int)$project['id'],(int)$source['id'],(int)$viewer['id']]);
        $pdo->prepare("INSERT INTO source_monitor_jobs(source_id,priority,status) SELECT ?,2,'queued' WHERE NOT EXISTS(SELECT 1 FROM source_monitor_jobs WHERE source_id=? AND status IN ('queued','processing'))")->execute([(int)$source['id'],(int)$source['id']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_agent_workspace_object($pdo,$viewer,$public,false)??['public_id'=>$public,'object_type'=>'bookmark','title'=>$title,'canonical_url'=>$canonical];
}

function research_agent_workspace_rename(PDO $pdo,array $viewer,string $publicId,string $title): array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,true);if(!$obj)throw new RuntimeException('Workspace item not found.');
    $project=project_access($pdo,(int)$viewer['id'],(string)$obj['project_public_id']);if(!$project)throw new RuntimeException('Workspace item not found.');research_agent_workspace_require_write($project);
    $title=mb_substr(trim($title),0,240);if($title==='')throw new InvalidArgumentException('Name is required.');
    $pdo->prepare('UPDATE research_workspace_objects SET title=?,updated_at=NOW() WHERE id=?')->execute([$title,(int)$obj['id']]);
    return research_agent_workspace_object($pdo,$viewer,$publicId,true)??$obj;
}

function research_agent_workspace_folder_is_descendant(PDO $pdo,int $folderId,int $candidateParentId): bool {
    $id=$candidateParentId;$seen=[];
    while($id>0&&!isset($seen[$id])){$seen[$id]=true;if($id===$folderId)return true;$q=$pdo->prepare("SELECT parent_id FROM research_workspace_objects WHERE id=? AND object_type='folder' LIMIT 1");$q->execute([$id]);$id=(int)($q->fetchColumn()?:0);}
    return false;
}

function research_agent_workspace_move(PDO $pdo,array $viewer,string $publicId,string $parentPublic=''): array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,true);if(!$obj)throw new RuntimeException('Workspace item not found.');
    $project=project_access($pdo,(int)$viewer['id'],(string)$obj['project_public_id']);if(!$project)throw new RuntimeException('Workspace item not found.');research_agent_workspace_require_write($project);
    $parent=research_agent_workspace_parent($pdo,(int)$project['id'],$parentPublic);
    if($parent&&(int)$parent['id']===(int)$obj['id'])throw new InvalidArgumentException('A folder cannot contain itself.');
    if($parent&&$obj['object_type']==='folder'&&research_agent_workspace_folder_is_descendant($pdo,(int)$obj['id'],(int)$parent['id']))throw new InvalidArgumentException('A folder cannot be moved inside one of its descendants.');
    $pdo->prepare('UPDATE research_workspace_objects SET parent_id=?,updated_at=NOW() WHERE id=?')->execute([$parent['id']??null,(int)$obj['id']]);
    return research_agent_workspace_object($pdo,$viewer,$publicId,true)??$obj;
}

function research_agent_workspace_subtree_ids(PDO $pdo,int $projectId,int $rootId): array {
    $ids=[$rootId];$frontier=[$rootId];$seen=[$rootId=>true];
    while($frontier){
        $marks=implode(',',array_fill(0,count($frontier),'?'));$q=$pdo->prepare("SELECT id FROM research_workspace_objects WHERE project_id=? AND parent_id IN ($marks)");
        $q->execute(array_merge([$projectId],$frontier));$next=[];
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $raw){$id=(int)$raw;if($id<1||isset($seen[$id]))continue;$seen[$id]=true;$ids[]=$id;$next[]=$id;}
        $frontier=$next;
    }
    return $ids;
}

function research_agent_workspace_trash(PDO $pdo,array $viewer,string $publicId): array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,true);if(!$obj)throw new RuntimeException('Workspace item not found.');
    $project=project_access($pdo,(int)$viewer['id'],(string)$obj['project_public_id']);if(!$project)throw new RuntimeException('Workspace item not found.');research_agent_workspace_require_write($project);
    $ids=$obj['object_type']==='folder'?research_agent_workspace_subtree_ids($pdo,(int)$project['id'],(int)$obj['id']):[(int)$obj['id']];
    $marks=implode(',',array_fill(0,count($ids),'?'));$params=array_merge([(int)$viewer['id']],$ids);
    $pdo->prepare("UPDATE research_workspace_objects SET status='trashed',trashed_at=NOW(),trashed_by_user_id=?,updated_at=NOW() WHERE id IN ($marks)")->execute($params);
    return research_agent_workspace_object($pdo,$viewer,$publicId,true)??$obj;
}

function research_agent_workspace_restore(PDO $pdo,array $viewer,string $publicId): array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,true);if(!$obj)throw new RuntimeException('Workspace item not found.');
    $project=project_access($pdo,(int)$viewer['id'],(string)$obj['project_public_id']);if(!$project)throw new RuntimeException('Workspace item not found.');research_agent_workspace_require_write($project);
    $parentId=(int)($obj['parent_id']??0);
    if($parentId){$q=$pdo->prepare("SELECT status FROM research_workspace_objects WHERE id=? LIMIT 1");$q->execute([$parentId]);if((string)$q->fetchColumn()!=='active')$parentId=0;}
    $ids=$obj['object_type']==='folder'?research_agent_workspace_subtree_ids($pdo,(int)$project['id'],(int)$obj['id']):[(int)$obj['id']];
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $pdo->prepare("UPDATE research_workspace_objects SET status='active',trashed_at=NULL,trashed_by_user_id=NULL,updated_at=NOW() WHERE id IN ($marks)")->execute($ids);
    $pdo->prepare("UPDATE research_workspace_objects SET parent_id=? WHERE id=?")->execute([$parentId?:null,(int)$obj['id']]);
    return research_agent_workspace_object($pdo,$viewer,$publicId,false)??$obj;
}

function research_agent_workspace_bookmark_feed(PDO $pdo,array $viewer,int $limit=30): array {
    if(!research_agent_workspace_ready($pdo))return [];$limit=max(1,min(60,$limit));$uid=(int)$viewer['id'];
    $q=$pdo->prepare("SELECT rwo.public_id,rwo.title,rwo.created_at,rwo.updated_at,rwb.canonical_url,rwb.domain,rwb.description,rwb.preview_image_url,
      rp.public_id project_public_id,rp.title project_title,ra.public_id research_agent_public_id,ra.name research_agent_name,
      ac.public_id conversation_public_id,t.public_id team_public_id,t.name team_name,
      u.public_id creator_public_id,u.username creator_username,u.display_name creator_name,u.profile_image_url,
      parent.public_id parent_public_id,parent.title parent_title,s.public_id source_public_id
      FROM research_workspace_objects rwo
      JOIN research_workspace_bookmarks rwb ON rwb.object_id=rwo.id
      JOIN research_projects rp ON rp.id=rwo.project_id
      LEFT JOIN research_agents ra ON ra.project_id=rp.id AND ra.status<>'archived'
      LEFT JOIN conversations ac ON ac.id=ra.conversation_id
      LEFT JOIN teams t ON t.id=rp.team_id
      JOIN users u ON u.id=rwo.created_by_user_id
      LEFT JOIN research_workspace_objects parent ON parent.id=rwo.parent_id
      LEFT JOIN sources s ON s.id=rwb.source_id
      LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
      WHERE rwo.status='active' AND (rp.owner_user_id=? OR tm.user_id=?)
      ORDER BY rwo.created_at DESC,rwo.id DESC LIMIT ".$limit);
    $q->execute([$uid,$uid,$uid]);return $q->fetchAll()?:[];
}

function research_agent_workspace_bookmark_context(PDO $pdo,array $viewer,string $publicId): ?array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,false);if(!$obj||$obj['object_type']!=='bookmark')return null;
    $text='';$refs=[['type'=>'bookmark','id'=>$publicId],['type'=>'research_project','id'=>(string)$obj['project_public_id']]];
    if(!empty($obj['source_public_id'])){
        $q=$pdo->prepare("SELECT sv.extracted_text FROM sources s LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE s.public_id=? LIMIT 1");$q->execute([$obj['source_public_id']]);$text=(string)($q->fetchColumn()?:'');$refs[]=['type'=>'source','id'=>(string)$obj['source_public_id']];
    }
    return [
      'type'=>'bookmark','public_id'=>$publicId,'label'=>(string)$obj['title'],
      'text'=>"[BOOKMARK {$publicId}]\nResearch: {$obj['project_title']}\nTitle: {$obj['title']}\nURL: {$obj['canonical_url']}\nNotes: ".(string)($obj['description']??'').($text!==''?"\nCaptured source text: ".mb_substr($text,0,9000):''),
      'refs'=>$refs
    ];
}
