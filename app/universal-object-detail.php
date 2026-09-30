<?php
declare(strict_types=1);

/**
 * Phase 74 Section 5 — Universal Object Detail adapter.
 * Presentation-only composition over canonical object access, activity,
 * workspace objects and Section 3 links.
 */
function universal_object_detail_activity(PDO $pdo,array $viewer,string $type,string $publicId,int $limit=30): array {
    if(!function_exists('unified_activity_collect'))return [];
    $out=[];foreach(unified_activity_collect($pdo,$viewer,max(40,$limit*2)) as $item){
        $hit=false;$object=$item['object']??null;
        if(is_array($object)&&($object['type']??'')===$type&&($object['public_id']??'')===$publicId)$hit=true;
        if(!$hit)foreach(($item['context']??[]) as $ctx)if(($ctx['type']??'')===$type&&($ctx['public_id']??'')===$publicId){$hit=true;break;}
        if($hit)$out[]=$item;if(count($out)>=$limit)break;
    }
    return $out;
}
function universal_object_detail_links(PDO $pdo,array $viewer,string $type,string $publicId,int $limit=30): array {
    if(!research_object_navigation_ready($pdo))return [];$out=[];
    $q=$pdo->prepare("SELECT source_type,source_public_id,target_type,target_public_id,relationship,created_at
      FROM research_object_links
      WHERE (source_type=? AND source_public_id=?) OR (target_type=? AND target_public_id=?)
      ORDER BY id DESC LIMIT ".max(1,min(80,$limit*3)));
    $q->execute([$type,$publicId,$type,$publicId]);
    foreach($q->fetchAll() as $link){
        $otherType=((string)$link['source_type']===$type&&(string)$link['source_public_id']===$publicId)?(string)$link['target_type']:(string)$link['source_type'];
        $otherId=((string)$link['source_type']===$type&&(string)$link['source_public_id']===$publicId)?(string)$link['target_public_id']:(string)$link['source_public_id'];
        $d=research_object_descriptor($pdo,$viewer,['type'=>$otherType,'public_id'=>$otherId]);if(!$d)continue;
        $out[]=['relationship'=>(string)$link['relationship'],'created_at'=>(string)$link['created_at'],'object'=>$d];
        if(count($out)>=$limit)break;
    }
    return $out;
}
function universal_object_detail_files(PDO $pdo,array $viewer,array $row,int $limit=30): array {
    if(!installer_table_exists($pdo,'research_workspace_objects'))return [];
    $projectId=(int)($row['project_id']??0);if($projectId<1)return [];
    $q=$pdo->prepare("SELECT rwo.public_id,rwo.object_type,rwo.title,rwo.updated_at
      FROM research_workspace_objects rwo
      WHERE rwo.project_id=? AND rwo.archived_at IS NULL
      ORDER BY rwo.updated_at DESC,rwo.id DESC LIMIT ".max(1,min(60,$limit)));
    $q->execute([$projectId]);return $q->fetchAll()?:[];
}
function universal_object_detail_people(PDO $pdo,array $viewer,array $row,int $limit=30): array {
    $teamId=(int)($row['team_id']??0);if($teamId<1&&isset($row['project_id'])){
        try{$q=$pdo->prepare('SELECT team_id FROM research_projects WHERE id=?');$q->execute([(int)$row['project_id']]);$teamId=(int)($q->fetchColumn()?:0);}catch(Throwable $e){}
    }
    if($teamId<1)return [['public_id'=>(string)($viewer['public_id']??''),'display_name'=>(string)($viewer['display_name']??$viewer['username']??'You'),'username'=>(string)($viewer['username']??''),'role'=>'owner']];
    $q=$pdo->prepare("SELECT u.public_id,u.display_name,u.username,u.profile_image_url,tm.role
      FROM team_members tm JOIN users u ON u.id=tm.user_id
      WHERE tm.team_id=? AND u.status='active' ORDER BY FIELD(tm.role,'owner','admin','researcher','member','viewer'),u.display_name LIMIT ".max(1,min(60,$limit)));
    $q->execute([$teamId]);return $q->fetchAll()?:[];
}
function universal_object_detail(PDO $pdo,array $viewer,string $type,string $publicId): ?array {
    $type=research_object_type($type);$publicId=trim($publicId);if($type===''||$publicId==='')return null;
    $row=research_object_access($pdo,$viewer,$type,$publicId);if(!$row)return null;
    $descriptor=research_object_descriptor($pdo,$viewer,['type'=>$type,'public_id'=>$publicId]);if(!$descriptor)return null;
    research_object_recent_touch($pdo,$viewer,$type,$publicId,(string)$descriptor['title'],(string)$descriptor['url']);
    $overview=[];
    foreach(['status','priority','objective','description','research_question','statement','rationale','summary','monitoring_cadence','briefing_cadence','due_at','created_at','updated_at'] as $key){
        if(isset($row[$key])&&$row[$key]!==null&&$row[$key]!=='')$overview[$key]=$row[$key];
    }
    return [
      'descriptor'=>$descriptor,
      'overview'=>$overview,
      'activity'=>universal_object_detail_activity($pdo,$viewer,$type,$publicId,30),
      'files'=>universal_object_detail_files($pdo,$viewer,$row,30),
      'people'=>universal_object_detail_people($pdo,$viewer,$row,30),
      'links'=>universal_object_detail_links($pdo,$viewer,$type,$publicId,30),
      'agent_context'=>[
        'type'=>$type,'public_id'=>$publicId,'title'=>$descriptor['title'],
        'agent_public_id'=>$descriptor['agent_public_id']??'',
        'prompt'=>'Work with this '.strtolower((string)$descriptor['type_label']).': '.$descriptor['title']
      ]
    ];
}
