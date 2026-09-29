<?php
declare(strict_types=1);

/**
 * Phase 75 Section 1 — Research Home & Agent Launcher.
 *
 * This is a read-only composition layer over the canonical Research Agent,
 * Team and Intelligence Portfolio engines. It deliberately does not create
 * another research store, scheduler, task system or authority path.
 */
function research_home_dashboard(PDO $pdo,array $viewer,array $agents): array {
    $teams=[];$personal=0;
    foreach($agents as $agent){
        $teamPublic=trim((string)($agent['team_public_id']??''));
        if($teamPublic===''){$personal++;continue;}
        if(!isset($teams[$teamPublic]))$teams[$teamPublic]=[
            'public_id'=>$teamPublic,
            'name'=>(string)($agent['team_name']??'Team'),
            'agent_count'=>0,
            'latest_activity_at'=>null,
        ];
        $teams[$teamPublic]['agent_count']++;
        $activity=trim((string)($agent['activity_at']??''));
        if($activity!==''&&($teams[$teamPublic]['latest_activity_at']===null||strcmp($activity,(string)$teams[$teamPublic]['latest_activity_at'])>0))
            $teams[$teamPublic]['latest_activity_at']=$activity;
    }
    uasort($teams,fn(array $a,array $b)=>strcmp((string)($b['latest_activity_at']??''),(string)($a['latest_activity_at']??'')));

    $portfolios=[];
    try{
        if(function_exists('research_intelligence_portfolios_ready')&&research_intelligence_portfolios_ready($pdo)&&function_exists('research_intelligence_portfolio_list'))
            $portfolios=research_intelligence_portfolio_list($pdo,$viewer,8,false);
    }catch(Throwable $e){$portfolios=[];}

    $attention=0;
    try{
        if(function_exists('research_intelligence_portfolio_operations_ready')
            &&research_intelligence_portfolio_operations_ready($pdo)
            &&function_exists('research_intelligence_organization_command_center')
            &&function_exists('research_portfolios_attention_sections')
            &&function_exists('research_portfolios_attention_count')){
            $center=research_intelligence_organization_command_center($pdo,$viewer);
            $attention=research_portfolios_attention_count(research_portfolios_attention_sections($center));
        }
    }catch(Throwable $e){$attention=0;}

    $activity=[];
    foreach(array_slice($agents,0,8) as $agent){
        $activity[]=[
            'kind'=>'agent',
            'agent_public_id'=>(string)$agent['public_id'],
            'conversation_public_id'=>(string)$agent['conversation_public_id'],
            'name'=>(string)$agent['name'],
            'scope'=>(string)($agent['team_name']?:'Personal'),
            'summary'=>trim((string)($agent['last_message']??'')),
            'activity_at'=>(string)($agent['activity_at']??$agent['updated_at']??''),
        ];
    }

    return [
        'summary'=>[
            'agents'=>count($agents),
            'personal_agents'=>$personal,
            'team_agents'=>max(0,count($agents)-$personal),
            'teams'=>count($teams),
            'portfolios'=>count($portfolios),
            'attention'=>$attention,
        ],
        'teams'=>array_values($teams),
        'portfolios'=>$portfolios,
        'activity'=>$activity,
    ];
}

function research_home_time_label(?string $value): string {
    $value=trim((string)$value);if($value==='')return 'Ready';
    try{
        $dt=new DateTimeImmutable($value);$now=new DateTimeImmutable('now');$seconds=max(0,$now->getTimestamp()-$dt->getTimestamp());
        if($seconds<60)return 'Just now';
        if($seconds<3600)return (string)max(1,(int)floor($seconds/60)).'m ago';
        if($seconds<86400)return (string)max(1,(int)floor($seconds/3600)).'h ago';
        if($seconds<604800)return (string)max(1,(int)floor($seconds/86400)).'d ago';
        return $dt->format('M j');
    }catch(Throwable $e){return $value;}
}

function research_home_agent_scope(array $agent): string {
    if(!empty($agent['is_default']))return 'Default Agent';
    return trim((string)($agent['team_name']??''))!==''?(string)$agent['team_name']:'Personal';
}
