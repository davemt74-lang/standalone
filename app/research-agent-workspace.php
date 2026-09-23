<?php
declare(strict_types=1);

function research_agent_workspace_ready(PDO $pdo): bool {
    try{
        return installer_table_exists($pdo,'research_workspace_objects')
            &&installer_table_exists($pdo,'research_workspace_bookmarks')
            &&installer_table_exists($pdo,'research_workspace_documents')
            &&installer_table_exists($pdo,'research_workspace_document_revisions')
            &&installer_table_exists($pdo,'research_workspace_stickies');
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
    if(!in_array((string)($project['access_role']??''),['owner','admin','researcher'],true))throw new RuntimeException('You have view-only access to this Research workspace.');
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
      rwd.document_type,rwd.content_html,rwd.plain_text document_plain_text,rwd.summary document_summary,rwd.revision_number,rwd.created_by_agent,rwd.last_edited_at,
      rws.body sticky_body,rws.color sticky_color,rws.position_x sticky_x,rws.position_y sticky_y,rws.width_px sticky_width,rws.height_px sticky_height,rws.z_index sticky_z,
      s.public_id source_public_id,s.title source_title
      FROM research_workspace_objects rwo
      JOIN research_projects rp ON rp.id=rwo.project_id
      LEFT JOIN research_agents ra ON ra.project_id=rp.id AND ra.status<>'archived'
      LEFT JOIN conversations ac ON ac.id=ra.conversation_id
      LEFT JOIN teams t ON t.id=rp.team_id
      JOIN users u ON u.id=rwo.created_by_user_id
      LEFT JOIN research_workspace_objects parent ON parent.id=rwo.parent_id
      LEFT JOIN research_workspace_bookmarks rwb ON rwb.object_id=rwo.id
      LEFT JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id
      LEFT JOIN research_workspace_stickies rws ON rws.object_id=rwo.id
      LEFT JOIN sources s ON s.id=rwb.source_id
      WHERE rwo.public_id=? LIMIT 1");
    $q->execute([$publicId]);$row=$q->fetch();if(!$row)return null;
    if(!$includeTrashed&&($row['status']??'')!=='active')return null;
    if(!empty($row['research_agent_public_id'])){
        if(!research_agent_access($pdo,$viewer,(string)$row['research_agent_public_id']))return null;
    }elseif(!project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']))return null;
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
      rwd.document_type,rwd.summary document_summary,rwd.revision_number,rwd.created_by_agent,rwd.last_edited_at,
      rws.body sticky_body,rws.color sticky_color,rws.position_x sticky_x,rws.position_y sticky_y,rws.width_px sticky_width,rws.height_px sticky_height,rws.z_index sticky_z,
      s.public_id source_public_id,s.title source_title
      FROM research_workspace_objects rwo
      JOIN research_projects rp ON rp.id=rwo.project_id
      LEFT JOIN teams t ON t.id=rp.team_id
      JOIN users u ON u.id=rwo.created_by_user_id
      LEFT JOIN research_workspace_objects parent ON parent.id=rwo.parent_id
      LEFT JOIN research_workspace_bookmarks rwb ON rwb.object_id=rwo.id
      LEFT JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id
      LEFT JOIN research_workspace_stickies rws ON rws.object_id=rwo.id
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
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException&&(string)$e->getCode()==='23000'){
            $q=$pdo->prepare("SELECT rwo.public_id FROM research_workspace_bookmarks rwb JOIN research_workspace_objects rwo ON rwo.id=rwb.object_id WHERE rwb.project_id=? AND rwb.url_hash=? LIMIT 1");
            $q->execute([(int)$project['id'],$hash]);$existingPublic=(string)($q->fetchColumn()?:'');
            if($existingPublic!==''){
                $existingObject=research_agent_workspace_object($pdo,$viewer,$existingPublic,false);
                if($existingObject)return $existingObject;
            }
        }
        throw $e;
    }
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
      WHERE rwo.status='active' AND ((rp.team_id IS NULL AND rp.owner_user_id=?) OR (rp.team_id IS NOT NULL AND tm.user_id=?))
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


function research_agent_workspace_plain_text(string $html): string {
    $text=preg_replace('/<br\s*\/?>/i',"\n",$html)??$html;
    $text=preg_replace('/<\/(p|div|h1|h2|h3|li|blockquote|pre|tr)>/i',"\n",$text)??$text;
    $text=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=preg_replace("/[ \t]+/u",' ',$text)??$text;
    $text=preg_replace("/\n{3,}/u","\n\n",$text)??$text;
    return mb_substr(trim($text),0,180000);
}

function research_agent_workspace_clean_html(string $html): string {
    $html=mb_substr($html,0,240000);
    $html=preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\1>#is','',$html)??$html;
    $allowed='<p><br><h1><h2><h3><strong><b><em><i><u><s><ul><ol><li><blockquote><pre><code><a><hr><table><thead><tbody><tr><th><td>';
    $html=strip_tags($html,$allowed);
    $html=preg_replace_callback('/<a\b[^>]*>/i',function($m){
        $tag=(string)$m[0];$href='';
        if(preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i',$tag,$x))$href=trim((string)$x[2]);
        elseif(preg_match('/\bhref\s*=\s*([^\s>]+)/i',$tag,$x))$href=trim((string)$x[1]);
        if($href===''||preg_match('/^(javascript|data|vbscript):/i',$href))return '<a>';
        if(!preg_match('#^(https?://|/|#)#i',$href))return '<a>';
        return '<a href="'.htmlspecialchars($href,ENT_QUOTES|ENT_HTML5,'UTF-8').'">';
    },$html)??$html;
    $html=preg_replace('/<(?!\/|a\b)([a-z0-9]+)\b[^>]*>/i','<$1>',$html)??$html;
    return trim($html);
}

function research_agent_workspace_document_type(string $type): string {
    $type=strtolower(trim($type));
    return in_array($type,['document','research_brief','memo','report','analysis','source_summary','timeline','weekly_report'],true)?$type:'document';
}

function research_agent_workspace_document_snapshot(PDO $pdo,int $objectId,string $title,string $html,string $plain,?string $summary,?int $editorId,int $revision): void {
    $pdo->prepare("INSERT INTO research_workspace_document_revisions(public_id,document_object_id,revision_number,title,content_html,plain_text,summary,edited_by_user_id) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([ulid_like(),$objectId,$revision,$title,$html!==''?$html:null,$plain!==''?$plain:null,$summary,$editorId]);
}

function research_agent_workspace_create_document(PDO $pdo,array $viewer,array $project,array $input,bool $createdByAgent=false): array {
    if(!research_agent_workspace_ready($pdo))throw new RuntimeException('Research Agent workspace requires the latest database upgrade.');
    research_agent_workspace_require_write($project);
    $title=mb_substr(trim((string)($input['title']??'')),0,240);if($title==='')$title='Untitled document';
    $parent=research_agent_workspace_parent($pdo,(int)$project['id'],(string)($input['parent_id']??''));
    $type=research_agent_workspace_document_type((string)($input['document_type']??'document'));
    $raw=(string)($input['content_html']??'');
    if($raw===''&&!empty($input['body'])){
        $parts=preg_split("/\n{2,}/u",trim((string)$input['body']))?:[];
        $raw=implode('',array_map(fn($p)=>'<p>'.nl2br(htmlspecialchars($p,ENT_QUOTES|ENT_HTML5,'UTF-8')).'</p>',$parts));
    }
    $html=research_agent_workspace_clean_html($raw);$plain=research_agent_workspace_plain_text($html);
    $summary=mb_substr(trim((string)($input['summary']??'')),0,5000);$hash=hash('sha256',$title."\n".$html);
    $public=ulid_like();$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO research_workspace_objects(public_id,project_id,parent_id,created_by_user_id,object_type,title) VALUES(?,?,?,?, 'document',?)")
          ->execute([$public,(int)$project['id'],$parent['id']??null,(int)$viewer['id'],$title]);
        $objectId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO research_workspace_documents(object_id,project_id,document_type,content_html,plain_text,summary,revision_number,content_hash,created_by_agent,last_edited_by_user_id,last_edited_at) VALUES(?,?,?,?,?,?,1,?,?,?,NOW())")
          ->execute([$objectId,(int)$project['id'],$type,$html!==''?$html:null,$plain!==''?$plain:null,$summary!==''?$summary:null,$hash,$createdByAgent?1:0,(int)$viewer['id']]);
        research_agent_workspace_document_snapshot($pdo,$objectId,$title,$html,$plain,$summary!==''?$summary:null,(int)$viewer['id'],1);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_agent_workspace_object($pdo,$viewer,$public,false)??['public_id'=>$public,'object_type'=>'document','title'=>$title];
}

function research_agent_workspace_save_document(PDO $pdo,array $viewer,string $publicId,array $input): array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,false);if(!$obj||$obj['object_type']!=='document')throw new RuntimeException('Document not found.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)($obj['research_agent_public_id']??''),(string)$obj['project_public_id']);if(!$project)throw new RuntimeException('Document not found.');
    research_agent_workspace_require_write($project);
    $base=max(0,(int)($input['base_revision']??0));$current=(int)($obj['revision_number']??1);
    if($base>0&&$base!==$current)throw new RuntimeException('This document changed in another session. Reload it before saving.');
    $title=mb_substr(trim((string)($input['title']??$obj['title'])),0,240);if($title==='')$title='Untitled document';
    $html=research_agent_workspace_clean_html((string)($input['content_html']??$obj['content_html']??''));$plain=research_agent_workspace_plain_text($html);
    $summary=mb_substr(trim((string)($input['summary']??$obj['document_summary']??'')),0,5000);$hash=hash('sha256',$title."\n".$html);
    $oldHash=(string)($obj['content_hash']??'');if($oldHash===$hash&&$title===(string)$obj['title'])return $obj;
    $next=$current+1;$pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE research_workspace_objects SET title=?,updated_at=NOW() WHERE id=?")->execute([$title,(int)$obj['id']]);
        $pdo->prepare("UPDATE research_workspace_documents SET content_html=?,plain_text=?,summary=?,revision_number=?,content_hash=?,last_edited_by_user_id=?,last_edited_at=NOW(),updated_at=NOW() WHERE object_id=?")
          ->execute([$html!==''?$html:null,$plain!==''?$plain:null,$summary!==''?$summary:null,$next,$hash,(int)$viewer['id'],(int)$obj['id']]);
        research_agent_workspace_document_snapshot($pdo,(int)$obj['id'],$title,$html,$plain,$summary!==''?$summary:null,(int)$viewer['id'],$next);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_agent_workspace_object($pdo,$viewer,$publicId,false)??$obj;
}

function research_agent_workspace_document_revisions(PDO $pdo,array $viewer,string $publicId,int $limit=30): array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,false);if(!$obj||$obj['object_type']!=='document')return [];
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT r.public_id,r.revision_number,r.title,r.summary,r.created_at,u.display_name editor_name,u.username editor_username
      FROM research_workspace_document_revisions r LEFT JOIN users u ON u.id=r.edited_by_user_id
      WHERE r.document_object_id=? ORDER BY r.revision_number DESC LIMIT ".$limit);
    $q->execute([(int)$obj['id']]);return $q->fetchAll()?:[];
}

function research_agent_workspace_restore_document_revision(PDO $pdo,array $viewer,string $documentPublicId,string $revisionPublicId,int $baseRevision): array {
    $obj=research_agent_workspace_object($pdo,$viewer,$documentPublicId,false);if(!$obj||$obj['object_type']!=='document')throw new RuntimeException('Document not found.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)($obj['research_agent_public_id']??''),(string)$obj['project_public_id']);if(!$project)throw new RuntimeException('Document not found.');
    research_agent_workspace_require_write($project);
    if($baseRevision>0&&$baseRevision!==(int)$obj['revision_number'])throw new RuntimeException('This document changed in another session. Reload it before restoring history.');
    $q=$pdo->prepare("SELECT * FROM research_workspace_document_revisions WHERE public_id=? AND document_object_id=? LIMIT 1");$q->execute([$revisionPublicId,(int)$obj['id']]);$revision=$q->fetch();if(!$revision)throw new RuntimeException('Document revision not found.');
    return research_agent_workspace_save_document($pdo,$viewer,$documentPublicId,[
      'title'=>$revision['title'],'content_html'=>$revision['content_html']??'','summary'=>$revision['summary']??'','base_revision'=>(int)$obj['revision_number']
    ]);
}

function research_agent_workspace_stickies(PDO $pdo,array $viewer,array $project): array {
    if(!research_agent_workspace_ready($pdo))return [];
    $q=$pdo->prepare("SELECT rwo.public_id,rwo.title,rwo.created_at,rwo.updated_at,rws.body,rws.color,rws.position_x,rws.position_y,rws.width_px,rws.height_px,rws.z_index,
      u.display_name creator_name,u.username creator_username
      FROM research_workspace_objects rwo JOIN research_workspace_stickies rws ON rws.object_id=rwo.id JOIN users u ON u.id=rwo.created_by_user_id
      WHERE rwo.project_id=? AND rwo.object_type='sticky' AND rwo.status='active' ORDER BY rws.z_index,rwo.id");
    $q->execute([(int)$project['id']]);return $q->fetchAll()?:[];
}

function research_agent_workspace_sticky_color(string $color): string {
    $color=strtolower(trim($color));return in_array($color,['yellow','pink','blue','green','purple','gray'],true)?$color:'yellow';
}

function research_agent_workspace_create_sticky(PDO $pdo,array $viewer,array $project,array $input=[]): array {
    if(!research_agent_workspace_ready($pdo))throw new RuntimeException('Research Agent workspace requires the latest database upgrade.');
    research_agent_workspace_require_write($project);
    $body=mb_substr(trim((string)($input['body']??'')),0,10000);$title=mb_substr(trim((string)($input['title']??'')),0,240);if($title==='')$title='Sticky note';
    $color=research_agent_workspace_sticky_color((string)($input['color']??'yellow'));
    $x=max(0,min(4000,(int)($input['x']??32)));$y=max(0,min(8000,(int)($input['y']??96)));
    $w=max(180,min(520,(int)($input['width']??240)));$h=max(120,min(600,(int)($input['height']??190)));
    $q=$pdo->prepare("SELECT COALESCE(MAX(rws.z_index),0)+1 FROM research_workspace_stickies rws WHERE rws.project_id=?");$q->execute([(int)$project['id']]);$z=max(1,(int)$q->fetchColumn());
    $public=ulid_like();$pdo->beginTransaction();
    try{
      $pdo->prepare("INSERT INTO research_workspace_objects(public_id,project_id,parent_id,created_by_user_id,object_type,title) VALUES(?,?,NULL,?,'sticky',?)")
        ->execute([$public,(int)$project['id'],(int)$viewer['id'],$title]);$objectId=(int)$pdo->lastInsertId();
      $pdo->prepare("INSERT INTO research_workspace_stickies(object_id,project_id,body,color,position_x,position_y,width_px,height_px,z_index) VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([$objectId,(int)$project['id'],$body,$color,$x,$y,$w,$h,$z]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_agent_workspace_object($pdo,$viewer,$public,false)??['public_id'=>$public,'object_type'=>'sticky','sticky_body'=>$body,'sticky_color'=>$color];
}

function research_agent_workspace_update_sticky(PDO $pdo,array $viewer,string $publicId,array $input): array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,false);if(!$obj||$obj['object_type']!=='sticky')throw new RuntimeException('Sticky note not found.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)($obj['research_agent_public_id']??''),(string)$obj['project_public_id']);if(!$project)throw new RuntimeException('Sticky note not found.');
    research_agent_workspace_require_write($project);
    $body=array_key_exists('body',$input)?mb_substr((string)$input['body'],0,10000):(string)($obj['sticky_body']??'');
    $color=array_key_exists('color',$input)?research_agent_workspace_sticky_color((string)$input['color']):(string)($obj['sticky_color']??'yellow');
    $x=max(0,min(4000,(int)($input['x']??$obj['sticky_x']??32)));$y=max(0,min(8000,(int)($input['y']??$obj['sticky_y']??96)));
    $w=max(180,min(520,(int)($input['width']??$obj['sticky_width']??240)));$h=max(120,min(600,(int)($input['height']??$obj['sticky_height']??190)));
    $z=max(1,min(1000000,(int)($input['z']??$obj['sticky_z']??1)));
    $pdo->prepare("UPDATE research_workspace_stickies SET body=?,color=?,position_x=?,position_y=?,width_px=?,height_px=?,z_index=?,updated_at=NOW() WHERE object_id=?")
      ->execute([$body,$color,$x,$y,$w,$h,$z,(int)$obj['id']]);
    return research_agent_workspace_object($pdo,$viewer,$publicId,false)??$obj;
}

function research_agent_workspace_document_context(PDO $pdo,array $viewer,string $publicId): ?array {
    $obj=research_agent_workspace_object($pdo,$viewer,$publicId,false);if(!$obj||$obj['object_type']!=='document')return null;
    return [
      'type'=>'document','public_id'=>$publicId,'label'=>(string)$obj['title'],
      'text'=>"[RESEARCH DOCUMENT {$publicId}]\nResearch: {$obj['project_title']}\nTitle: {$obj['title']}\nType: ".(string)($obj['document_type']??'document')."\nSummary: ".(string)($obj['document_summary']??'')."\nContent:\n".mb_substr((string)($obj['document_plain_text']??''),0,18000),
      'refs'=>[['type'=>'document','id'=>$publicId],['type'=>'research_project','id'=>(string)$obj['project_public_id']]]
    ];
}

function research_agent_workspace_attach_document_to_agent_message(PDO $pdo,array $viewer,string $documentPublicId,int $messageId): void {
    if($messageId<=0)return;$obj=research_agent_workspace_object($pdo,$viewer,$documentPublicId,false);if(!$obj||$obj['object_type']!=='document')return;
    $q=$pdo->prepare("SELECT COUNT(*) FROM conversation_message_attachments WHERE message_id=? AND attachment_type='document' AND object_public_id=?");
    $q->execute([$messageId,$documentPublicId]);if((int)$q->fetchColumn()>0)return;
    $pdo->prepare("INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,'document',?,NULL)")
      ->execute([$messageId,$documentPublicId]);
}
