<?php
declare(strict_types=1);

/**
 * Phase 74 Section 3 — canonical UI navigation around existing research objects.
 * This layer stores only cross-object navigation metadata. Domain ownership and
 * persistence remain in the existing Research Agent/Mission/Task/etc engines.
 */
function research_object_navigation_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_object_links')&&installer_table_exists($pdo,'user_object_shortcuts');}
    catch(Throwable $e){return false;}
}
function research_object_type(string $type): string {
    $type=strtolower(trim($type));
    return in_array($type,['research_agent','portfolio','mission','task','program','decision','action_plan','report','document','team','source'],true)?$type:'';
}
function research_object_context_from_input(array $input): array {
    $type=research_object_type((string)($input['context_type']??''));$id=trim((string)($input['context_id']??''));
    return $type!==''&&$id!==''?['type'=>$type,'public_id'=>$id]:[];
}
function research_object_access(PDO $pdo,array $viewer,string $type,string $publicId): ?array {
    $type=research_object_type($type);$publicId=trim($publicId);if($type===''||$publicId==='')return null;
    try{
        return match($type){
            'research_agent'=>function_exists('research_agent_access')?research_agent_access($pdo,$viewer,$publicId):null,
            'portfolio'=>function_exists('research_intelligence_portfolio_access')?research_intelligence_portfolio_access($pdo,$viewer,$publicId):null,
            'mission'=>function_exists('research_mission_access')?research_mission_access($pdo,$viewer,$publicId):null,
            'task'=>function_exists('research_task_access')?research_task_access($pdo,$viewer,$publicId):null,
            'program'=>function_exists('research_program_access')?research_program_access($pdo,$viewer,$publicId):null,
            'decision'=>function_exists('research_decision_access')?research_decision_access($pdo,$viewer,$publicId):null,
            'action_plan'=>function_exists('research_action_plan_access')?research_action_plan_access($pdo,$viewer,$publicId):null,
            'report'=>function_exists('research_system_report_access')?research_system_report_access($pdo,$viewer,$publicId):null,
            default=>null
        };
    }catch(Throwable $e){return null;}
}
function research_object_context_from_request(PDO $pdo,array $viewer): array {
    $map=['portfolio'=>'portfolio','mission'=>'mission','task'=>'task','program'=>'program','decision'=>'decision','action_plan'=>'action_plan','report'=>'report'];
    foreach($map as $key=>$type){
        $id=trim((string)($_GET[$key]??''));if($id!==''&&research_object_access($pdo,$viewer,$type,$id))return ['type'=>$type,'public_id'=>$id];
    }
    $agent=trim((string)($_GET['agent']??''));
    if($agent!==''){
        $row=research_object_access($pdo,$viewer,'research_agent',$agent);
        if(!$row&&function_exists('research_agent_by_conversation'))$row=research_agent_by_conversation($pdo,$viewer,$agent);
        if($row)return ['type'=>'research_agent','public_id'=>(string)$row['public_id']];
    }
    return [];
}
function research_object_context_agent_public(PDO $pdo,array $viewer,array $context): string {
    $type=research_object_type((string)($context['type']??''));$id=trim((string)($context['public_id']??''));
    if($type===''||$id==='')return '';
    $row=research_object_access($pdo,$viewer,$type,$id);if(!$row)return '';
    if($type==='research_agent')return (string)($row['public_id']??'');
    return trim((string)($row['agent_public_id']??''));
}
function research_object_url(string $type,array $row): string {
    $id=rawurlencode((string)($row['public_id']??''));$agent=rawurlencode((string)($row['agent_public_id']??''));
    return match($type){
        'research_agent'=>'/home.php?agent='.rawurlencode((string)($row['conversation_public_id']??$row['public_id']??'')),
        'portfolio'=>'/research-intelligence-portfolios.php?portfolio='.$id,
        'mission'=>'/research-missions.php?agent='.$agent.'&mission='.$id,
        'task'=>'/research-tasks.php?agent='.$agent.'&task='.$id,
        'program'=>'/research-programs.php?agent='.$agent.'&program='.$id,
        'decision'=>'/research-decisions.php?decision='.$id,
        'action_plan'=>'/research-action-plans.php?action_plan='.$id,
        'report'=>'/research-reports.php?agent='.$agent.'&report='.$id,
        default=>''
    };
}
function research_object_descriptor(PDO $pdo,array $viewer,array $context): ?array {
    $type=research_object_type((string)($context['type']??''));$id=trim((string)($context['public_id']??''));if($type===''||$id==='')return null;
    $row=research_object_access($pdo,$viewer,$type,$id);if(!$row)return null;
    $title=(string)($row['title']??$row['name']??'Untitled');
    $labels=['research_agent'=>'Research Agent','portfolio'=>'Portfolio','mission'=>'Mission','task'=>'Task','program'=>'Program','decision'=>'Decision','action_plan'=>'Action Plan','report'=>'Report'];
    return [
      'type'=>$type,'type_label'=>$labels[$type]??ucwords(str_replace('_',' ',$type)),'public_id'=>$id,'title'=>$title,
      'status'=>(string)($row['status']??''),'agent_public_id'=>(string)($row['agent_public_id']??($type==='research_agent'?$row['public_id']??'':'')),
      'agent_name'=>(string)($row['agent_name']??($type==='research_agent'?$row['name']??'':'')),
      'team_name'=>(string)($row['team_name']??''),'url'=>research_object_url($type,$row)
    ];
}
function research_object_link_create(PDO $pdo,array $viewer,array $source,string $targetType,string $targetPublicId,string $relationship='created_from'): void {
    if(!research_object_navigation_ready($pdo))return;
    $sourceType=research_object_type((string)($source['type']??''));$sourceId=trim((string)($source['public_id']??''));
    $targetType=research_object_type($targetType);$targetPublicId=trim($targetPublicId);
    if($sourceType===''||$sourceId===''||$targetType===''||$targetPublicId===''||($sourceType===$targetType&&$sourceId===$targetPublicId))return;
    if(!research_object_access($pdo,$viewer,$sourceType,$sourceId))return;
    $relationship=preg_replace('/[^a-z0-9_]+/','_',strtolower($relationship))?:'related';
    $pdo->prepare("INSERT IGNORE INTO research_object_links(public_id,source_type,source_public_id,target_type,target_public_id,relationship,created_by_user_id) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),$sourceType,$sourceId,$targetType,$targetPublicId,mb_substr($relationship,0,48),(int)$viewer['id']]);
}
function research_object_recent_touch(PDO $pdo,array $viewer,string $type,string $publicId,string $title,string $url): void {
    if(!research_object_navigation_ready($pdo))return;$type=research_object_type($type);$publicId=trim($publicId);$url=trim($url);if($type===''||$publicId===''||$url==='')return;
    $pdo->prepare("INSERT INTO user_object_shortcuts(user_id,object_type,object_public_id,title,url,last_opened_at) VALUES(?,?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE title=VALUES(title),url=VALUES(url),last_opened_at=NOW(),updated_at=NOW()")
      ->execute([(int)$viewer['id'],$type,$publicId,mb_substr(trim($title),0,255),mb_substr($url,0,1000)]);
}
function research_object_shortcuts(PDO $pdo,array $viewer,int $limit=8): array {
    if(!research_object_navigation_ready($pdo))return [];$limit=max(1,min(20,$limit));
    $q=$pdo->prepare("SELECT object_type,object_public_id,title,url,pinned_at,last_opened_at FROM user_object_shortcuts WHERE user_id=? ORDER BY (pinned_at IS NULL),pinned_at DESC,last_opened_at DESC LIMIT ".$limit);
    $q->execute([(int)$viewer['id']]);return $q->fetchAll()?:[];
}
function research_object_pin_set(PDO $pdo,array $viewer,string $type,string $publicId,bool $pinned): bool {
    if(!research_object_navigation_ready($pdo))throw new RuntimeException('Object shortcuts require the latest database upgrade.');
    $type=research_object_type($type);$publicId=trim($publicId);if($type===''||$publicId==='')throw new InvalidArgumentException('Invalid object.');
    $row=research_object_access($pdo,$viewer,$type,$publicId);if(!$row)throw new RuntimeException('Object not found.');
    $d=research_object_descriptor($pdo,$viewer,['type'=>$type,'public_id'=>$publicId]);if(!$d)throw new RuntimeException('Object not found.');
    research_object_recent_touch($pdo,$viewer,$type,$publicId,(string)$d['title'],(string)$d['url']);
    $pdo->prepare("UPDATE user_object_shortcuts SET pinned_at=".($pinned?'NOW()':'NULL').",updated_at=NOW() WHERE user_id=? AND object_type=? AND object_public_id=?")
      ->execute([(int)$viewer['id'],$type,$publicId]);
    return $pinned;
}
