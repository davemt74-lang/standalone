<?php
declare(strict_types=1);

/**
 * Phase 74 Section 7 — Legacy route & navigation compatibility.
 *
 * Legacy Research product URLs are translated into canonical Phase 74 surfaces
 * without changing APIs, storage, permissions, or the authoritative engines.
 * Object-specific legacy inspectors remain reachable; append legacy=1 when a
 * canonical surface needs to expose an advanced compatibility screen.
 */
function research_legacy_agent_by_project(PDO $pdo,array $viewer,string $projectPublicId): ?array {
    $projectPublicId=trim($projectPublicId);
    if($projectPublicId===''||!function_exists('research_agent_ready')||!research_agent_ready($pdo))return null;
    $q=$pdo->prepare("SELECT ra.public_id
      FROM research_agents ra
      JOIN research_projects rp ON rp.id=ra.project_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rp.public_id=? AND ra.status<>'archived'
        AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?))
      ORDER BY ra.is_default DESC,ra.updated_at DESC,ra.id DESC LIMIT 1");
    $q->execute([(int)$viewer['id'],$projectPublicId,(int)$viewer['id'],(int)$viewer['id']]);
    $public=(string)($q->fetchColumn()?:'');
    return $public!==''?research_agent_access($pdo,$viewer,$public):null;
}

function research_legacy_agent_from_query(PDO $pdo,array $viewer,array $query): ?array {
    $agentId=trim((string)($query['agent']??''));
    if($agentId!==''&&function_exists('research_agent_access')){
        $agent=research_agent_access($pdo,$viewer,$agentId);
        if($agent)return $agent;
    }
    $projectId=trim((string)($query['project']??$query['project_id']??''));
    return $projectId!==''?research_legacy_agent_by_project($pdo,$viewer,$projectId):null;
}

function research_legacy_query_url(string $path,array $query=[]): string {
    $clean=[];foreach($query as $key=>$value){if($value===null||$value==='')continue;$clean[(string)$key]=(string)$value;}
    return $path.($clean?'?'.http_build_query($clean,'','&',PHP_QUERY_RFC3986):'');
}

function research_legacy_route_target(PDO $pdo,array $viewer,string $route,array $query=[]): ?string {
    $route=basename($route);
    if(!empty($query['legacy']))return null;
    $agent=research_legacy_agent_from_query($pdo,$viewer,$query);
    $agentId=(string)($agent['public_id']??'');
    $projectId=trim((string)($query['id']??''));

    return match($route){
        'research-intelligence-command-center.php'=>research_portfolios_href('overview'),
        'research-portfolio.php'=>research_portfolios_href('overview'),
        'research-knowledge.php'=>($knowledgeAgent=$projectId!==''?research_legacy_agent_by_project($pdo,$viewer,$projectId):$agent)
            ?research_legacy_query_url('/research-agent-knowledge.php',['agent'=>(string)$knowledgeAgent['public_id'],'view'=>'library'])
            :null,
        'research-project.php'=>($projectId!==''&&($projectAgent=research_legacy_agent_by_project($pdo,$viewer,$projectId)))
            ?research_legacy_query_url('/home.php',['agent'=>(string)$projectAgent['conversation_public_id']])
            :null,
        'research-brief.php'=>($briefAgent=$projectId!==''?research_legacy_agent_by_project($pdo,$viewer,$projectId):$agent)
            ?research_legacy_query_url('/research-reports.php',['agent'=>(string)$briefAgent['public_id'],'view'=>'create','type'=>'research_brief'])
            :null,
        'research-outcomes.php'=>trim((string)($query['id']??''))!==''?null:research_legacy_query_url('/research-agent-research.php',['agent'=>$agentId,'view'=>'decisions']),
        'research-reviews.php'=>array_diff_key($query,['legacy'=>true])?null:research_portfolios_href('overview',null,['focus'=>'review']),
        'research-automations.php'=>trim((string)($query['id']??''))!==''?null:research_legacy_query_url('/research-agent-research.php',['agent'=>$agentId,'view'=>'recurring']),
        default=>null,
    };
}

function research_legacy_redirect_if_needed(PDO $pdo,array $viewer,string $route,array $query=[]): void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')return;
    $target=research_legacy_route_target($pdo,$viewer,$route,$query);
    if($target===null||$target==='')return;
    header('Cache-Control: private, no-store');
    header('Location: '.$target,true,302);
    exit;
}
