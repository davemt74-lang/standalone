<?php
declare(strict_types=1);

function research_portfolio_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_portfolio_preferences');}
    catch(Throwable $e){return false;}
}

function research_portfolio_projects(PDO $pdo,array $viewer,int $limit=40): array {
    $limit=max(1,min(80,$limit));$uid=(int)$viewer['id'];
    $q=$pdo->prepare("SELECT DISTINCT rp.*,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role,
      COALESCE(rpp.pinned,0) portfolio_pinned
      FROM research_projects rp
      LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
      LEFT JOIN research_portfolio_preferences rpp ON rpp.project_id=rp.id AND rpp.user_id=?
      WHERE rp.status='active' AND (rp.owner_user_id=? OR tm.user_id=?)
      ORDER BY COALESCE(rpp.pinned,0) DESC,rp.updated_at DESC,rp.id DESC LIMIT ".$limit);
    $q->execute([$uid,$uid,$uid,$uid,$uid]);return $q->fetchAll();
}

function research_portfolio_set_pin(PDO $pdo,array $viewer,string $projectPublic,bool $pinned): bool {
    if(!research_portfolio_ready($pdo))throw new RuntimeException('Research Portfolio requires the Phase 23 database upgrade.');
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project)throw new RuntimeException('Research project is unavailable.');
    $pdo->prepare("INSERT INTO research_portfolio_preferences(user_id,project_id,pinned) VALUES(?,?,?) ON DUPLICATE KEY UPDATE pinned=VALUES(pinned),updated_at=NOW()")
      ->execute([$viewer['id'],$project['id'],$pinned?1:0]);return true;
}

function research_portfolio_automation_map(PDO $pdo,array $viewer,array $projectIds): array {
    if(!$projectIds||!function_exists('research_automation_ready')||!research_automation_ready($pdo))return [];$in=implode(',',array_map('intval',$projectIds));$out=[];
    $q=$pdo->prepare("SELECT project_id,
      SUM(status='active') active_count,SUM(status='paused') paused_count,
      SUM(status='active' AND failure_count>0) failing_count,MAX(last_run_at) last_run_at,MAX(failure_count) max_failure_count
      FROM research_automations WHERE user_id=? AND status<>'archived' AND project_id IN ($in) GROUP BY project_id");$q->execute([$viewer['id']]);
    foreach($q->fetchAll() as $r)$out[(int)$r['project_id']]=['active'=>(int)$r['active_count'],'paused'=>(int)$r['paused_count'],'failing'=>(int)$r['failing_count'],'last_run_at'=>$r['last_run_at'],'max_failure_count'=>(int)$r['max_failure_count'],'recent_failed_runs'=>0];
    $q=$pdo->prepare("SELECT project_id,COUNT(*) failures FROM research_automation_runs WHERE user_id=? AND project_id IN ($in) AND status='failed' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY project_id");$q->execute([$viewer['id']]);foreach($q->fetchAll() as $r){$id=(int)$r['project_id'];if(!isset($out[$id]))$out[$id]=['active'=>0,'paused'=>0,'failing'=>0,'last_run_at'=>null,'max_failure_count'=>0,'recent_failed_runs'=>0];$out[$id]['recent_failed_runs']=(int)$r['failures'];}
    return $out;
}

function research_portfolio_agent_action_map(PDO $pdo,array $viewer,array $projectIds): array {
    if(!$projectIds||!function_exists('agent_actions_ready')||!agent_actions_ready($pdo))return [];$in=implode(',',array_map('intval',$projectIds));$q=$pdo->prepare("SELECT project_id,COUNT(*) pending_count,MIN(expires_at) next_expiry FROM agent_action_proposals WHERE proposed_by_user_id=? AND project_id IN ($in) AND status='pending' AND expires_at>NOW() GROUP BY project_id");$q->execute([$viewer['id']]);$out=[];foreach($q->fetchAll() as $r)$out[(int)$r['project_id']]=['pending'=>(int)$r['pending_count'],'next_expiry'=>$r['next_expiry']];return $out;
}

function research_portfolio_cross_map(PDO $pdo,array $viewer): array {
    if(!function_exists('cross_research_ready')||!cross_research_ready($pdo))return [];$out=[];foreach(cross_research_suggestions($pdo,$viewer,null,300,false) as $s){
        foreach(['source','target'] as $side){$pid=(string)$s[$side.'_project_public_id'];if(!isset($out[$pid]))$out[$pid]=['total'=>0,'high'=>0,'conflicts'=>0,'opportunities'=>0,'items'=>[]];$out[$pid]['total']++;if(($s['priority']??'')==='high')$out[$pid]['high']++;if(in_array((string)$s['type'],['evidence_label_conflict','claim_status_difference'],true))$out[$pid]['conflicts']++;if((string)$s['type']==='evidence_reuse')$out[$pid]['opportunities']++;if(count($out[$pid]['items'])<6)$out[$pid]['items'][]=['key'=>$s['key'],'type'=>$s['type'],'title'=>$s['title'],'priority'=>$s['priority'],'other_project'=>$side==='source'?$s['target_project_title']:$s['source_project_title']];}
    }return $out;
}

function research_portfolio_reason(string $level,string $type,string $title,string $detail,string $href): array {
    return ['level'=>$level,'type'=>$type,'title'=>$title,'detail'=>$detail,'href'=>$href];
}

function research_portfolio_project(PDO $pdo,array $viewer,array $project,array $automationMap,array $agentMap,array $crossMap): array {
    $pid=(int)$project['id'];$public=(string)$project['public_id'];$snapshot=research_workspace_deterministic_snapshot($pdo,$pid);$reviews=function_exists('research_reviews_ready')&&research_reviews_ready($pdo)?research_review_project_summary($pdo,$viewer,$public):['open'=>0,'assigned_to_me'=>0,'changes_requested'=>0,'unresolved_objections'=>0,'overdue'=>0,'stale'=>0,'items'=>[]];$impact=function_exists('change_impact_ready')&&change_impact_ready($pdo)?change_impact_project_summary($pdo,$viewer,$public,10):['events'=>0,'unresolved'=>0,'high'=>0,'affected_claims'=>0,'affected_findings'=>0,'affected_reports'=>0,'items'=>[]];$outcomes=function_exists('research_outcomes_ready')&&research_outcomes_ready($pdo)?research_outcome_summary($pdo,$viewer,$public):['total'=>0,'follow_up'=>0,'reopened'=>0,'helpful'=>0,'not_helpful'=>0];$automation=$automationMap[$pid]??['active'=>0,'paused'=>0,'failing'=>0,'last_run_at'=>null,'max_failure_count'=>0,'recent_failed_runs'=>0];$agent=$agentMap[$pid]??['pending'=>0,'next_expiry'=>null];$cross=$crossMap[$public]??['total'=>0,'high'=>0,'conflicts'=>0,'opportunities'=>0,'items'=>[]];
    $reasons=[];$high=0;$watch=0;
    $add=function(array $reason)use(&$reasons,&$high,&$watch){$reasons[]=$reason;if($reason['level']==='high')$high++;elseif($reason['level']==='medium')$watch++;};
    if($impact['unresolved']>0)$add(research_portfolio_reason($impact['high']>0?'high':'medium','change_impact','Source changes affect downstream Research',$impact['unresolved'].' unresolved downstream impact item(s).','/research-impact.php?project='.rawurlencode($public)));
    if($reviews['overdue']>0)$add(research_portfolio_reason('high','overdue_reviews','Review deadline missed',$reviews['overdue'].' review(s) are overdue.','/research-reviews.php?scope=assigned'));
    if($reviews['changes_requested']>0)$add(research_portfolio_reason('high','changes_requested','Review changes requested',$reviews['changes_requested'].' open review(s) request changes.','/research-reviews.php?scope=changes'));
    if($reviews['unresolved_objections']>0)$add(research_portfolio_reason('high','review_objection','Unresolved review objection',$reviews['unresolved_objections'].' review(s) contain an unresolved objection.','/research-reviews.php?scope=changes'));
    if($reviews['stale']>0)$add(research_portfolio_reason('high','stale_reviews','Reviews based on earlier Research',$reviews['stale'].' open review(s) are stale.','/research-reviews.php?scope=all'));
    if($reviews['assigned_to_me']>0)$add(research_portfolio_reason('medium','assigned_reviews','Review assigned to you',$reviews['assigned_to_me'].' open review(s) are assigned to you.','/research-reviews.php?scope=assigned'));
    $highConflicts=count(array_filter($snapshot['conflicts'],fn($x)=>(string)($x['priority']??'')==='high'));if($highConflicts>0)$add(research_portfolio_reason('high','research_conflicts','Conflicting Research evidence',$highConflicts.' high-priority conflict(s) need resolution.','/research-project.php?id='.rawurlencode($public).'#research-now'));
    $highGaps=count(array_filter($snapshot['gaps'],fn($x)=>(string)($x['priority']??'')==='high'));if($highGaps>0)$add(research_portfolio_reason('high','evidence_gaps','Claims missing evidence',$highGaps.' high-priority evidence gap(s).','/research-project.php?id='.rawurlencode($public).'#research-now'));
    if($automation['failing']>0||$automation['recent_failed_runs']>0)$add(research_portfolio_reason('high','automation_failure','Research automation needs attention',max($automation['failing'],$automation['recent_failed_runs']).' recent/failing automation signal(s).','/research-automations.php'));
    if($agent['pending']>0)$add(research_portfolio_reason('high','pending_agent_action','Agent proposal waiting for confirmation',$agent['pending'].' proposal(s) require explicit user action.','/home.php'));
    if(($outcomes['reopened']??0)>0)$add(research_portfolio_reason('high','reopened_outcomes','Decision Memory item reopened',$outcomes['reopened'].' prior outcome(s) were reopened.','/research-outcomes.php?project='.rawurlencode($public)));
    if(($outcomes['follow_up']??0)>0)$add(research_portfolio_reason('medium','follow_up_outcomes','Decision Memory follow-up',$outcomes['follow_up'].' outcome(s) still need follow-up.','/research-outcomes.php?project='.rawurlencode($public)));
    if(($snapshot['counts']['open_tasks']??0)>0)$add(research_portfolio_reason('medium','open_tasks','Open Research tasks',(string)$snapshot['counts']['open_tasks'].' task(s) remain open or in progress.','/research-project.php?id='.rawurlencode($public).'#research-now'));
    if(count($snapshot['source_risks'])>0&&$impact['unresolved']===0)$add(research_portfolio_reason('medium','source_risks','Recent Source changes',count($snapshot['source_risks']).' Source risk signal(s) are present.','/research-project.php?id='.rawurlencode($public).'#research-now'));
    if($cross['conflicts']>0)$add(research_portfolio_reason('high','cross_conflict','Cross-Research conflict',$cross['conflicts'].' cross-project conflict signal(s).','/cross-research.php?project='.rawurlencode($public)));
    elseif($cross['opportunities']>0||$cross['total']>0)$add(research_portfolio_reason('medium','cross_opportunity','Related Research available',$cross['total'].' related Research suggestion(s).','/cross-research.php?project='.rawurlencode($public)));
    $state=$high>0?'needs_attention':($watch>0?'watch':'clear');
    return ['project'=>['id'=>$pid,'public_id'=>$public,'title'=>$project['title'],'description'=>$project['description'],'access_role'=>$project['access_role'],'team_id'=>$project['team_id'],'updated_at'=>$project['updated_at'],'pinned'=>(bool)$project['portfolio_pinned']],'attention_state'=>$state,'high_reason_count'=>$high,'watch_reason_count'=>$watch,'reasons'=>$reasons,'workspace'=>$snapshot,'reviews'=>$reviews,'impact'=>$impact,'outcomes'=>$outcomes,'automation'=>$automation,'agent_actions'=>$agent,'cross_research'=>$cross];
}

function research_portfolio_compose(PDO $pdo,array $viewer,int $limit=40): array {
    if(!research_portfolio_ready($pdo))return ['ready'=>false,'projects'=>[],'summary'=>[]];$projects=research_portfolio_projects($pdo,$viewer,$limit);if(!$projects)return ['ready'=>true,'projects'=>[],'summary'=>['total'=>0,'needs_attention'=>0,'watch'=>0,'clear'=>0,'pinned'=>0,'assigned_reviews'=>0,'unresolved_impact'=>0,'pending_agent_actions'=>0,'automation_failures'=>0]];
    $ids=array_map(fn($p)=>(int)$p['id'],$projects);$automations=research_portfolio_automation_map($pdo,$viewer,$ids);$agent=research_portfolio_agent_action_map($pdo,$viewer,$ids);$cross=research_portfolio_cross_map($pdo,$viewer);$items=[];$summary=['total'=>0,'needs_attention'=>0,'watch'=>0,'clear'=>0,'pinned'=>0,'assigned_reviews'=>0,'unresolved_impact'=>0,'pending_agent_actions'=>0,'automation_failures'=>0,'review_objections'=>0,'evidence_conflicts'=>0];
    foreach($projects as $project){$item=research_portfolio_project($pdo,$viewer,$project,$automations,$agent,$cross);$items[]=$item;$summary['total']++;$summary[$item['attention_state']]++;if($item['project']['pinned'])$summary['pinned']++;$summary['assigned_reviews']+=$item['reviews']['assigned_to_me'];$summary['unresolved_impact']+=$item['impact']['unresolved'];$summary['pending_agent_actions']+=$item['agent_actions']['pending'];$summary['automation_failures']+=max($item['automation']['failing'],$item['automation']['recent_failed_runs']);$summary['review_objections']+=$item['reviews']['unresolved_objections'];$summary['evidence_conflicts']+=count($item['workspace']['conflicts']);}
    usort($items,function($a,$b){if($a['project']['pinned']!==$b['project']['pinned'])return $a['project']['pinned']?-1:1;$rank=['needs_attention'=>3,'watch'=>2,'clear'=>1];$cmp=($rank[$b['attention_state']]??0)<=>($rank[$a['attention_state']]??0);if($cmp!==0)return $cmp;$cmp=$b['high_reason_count']<=>$a['high_reason_count'];if($cmp!==0)return $cmp;return strcmp((string)$b['project']['updated_at'],(string)$a['project']['updated_at']);});
    return ['ready'=>true,'projects'=>$items,'summary'=>$summary,'generated_at'=>date('Y-m-d H:i:s')];
}

function research_portfolio_filter(array $portfolio,array $filters): array {
    $attention=trim((string)($filters['attention']??''));$access=trim((string)($filters['access']??''));$query=mb_strtolower(trim((string)($filters['q']??'')));$pinned=!empty($filters['pinned']);$items=[];
    foreach($portfolio['projects'] as $item){$p=$item['project'];if($attention!==''&&$item['attention_state']!==$attention)continue;if($access!==''&&(string)$p['access_role']!==$access)continue;if($pinned&&!$p['pinned'])continue;if($query!==''&&!str_contains(mb_strtolower((string)$p['title'].' '.(string)$p['description']),$query))continue;$items[]=$item;}$portfolio['projects']=$items;$portfolio['filtered_count']=count($items);return $portfolio;
}

function research_portfolio_context(PDO $pdo,array $viewer,int $limit=12): array {
    $portfolio=research_portfolio_compose($pdo,$viewer,40);$items=array_slice($portfolio['projects'],0,max(1,min(20,$limit)));$lines=['[RESEARCH PORTFOLIO]'];$s=$portfolio['summary'];$lines[]='Projects '.$s['total'].'; needs attention '.$s['needs_attention'].'; watch '.$s['watch'].'; clear '.$s['clear'].'; assigned reviews '.$s['assigned_reviews'].'; unresolved source impacts '.$s['unresolved_impact'].'; pending Agent actions '.$s['pending_agent_actions'].'; automation failures '.$s['automation_failures'].'.';$refs=[];
    foreach($items as $item){$p=$item['project'];$top=array_slice($item['reasons'],0,4);$lines[]=$p['title'].' ['.$item['attention_state'].']'.($p['pinned']?' [PINNED]':'').($top?' — '.implode('; ',array_map(fn($r)=>$r['title'],$top)):' — no current attention signals').' [PROJECT '.$p['public_id'].']';$refs[]=['type'=>'research_project','id'=>$p['public_id']];}
    return ['text'=>implode("\n",$lines),'refs'=>$refs,'portfolio'=>$portfolio];
}

function research_portfolio_agent_handoff(PDO $pdo,array $viewer,?string $focusProject=null): ?array {
    $portfolio=research_portfolio_compose($pdo,$viewer,40);if(!$portfolio['ready'])return null;$projects=$portfolio['projects'];if($focusProject!==null&&trim($focusProject)!=='')$projects=array_values(array_filter($projects,fn($x)=>(string)$x['project']['public_id']===trim($focusProject)));else $projects=array_slice($projects,0,8);if(!$projects)return null;$context=[];$lines=[];foreach($projects as $item){$p=$item['project'];$context[]=['type'=>'research','public_id'=>$p['public_id']];$lines[]=$p['title'].' — '.$item['attention_state'].($item['reasons']?' — '.implode('; ',array_map(fn($r)=>$r['title'],array_slice($item['reasons'],0,5))):' — no current attention signals');}
    $prompt='Review my Research portfolio using only the attached permission-checked project context. Portfolio state: '.implode(' | ',$lines).'. Summarize what needs my attention, what is blocked, what has unresolved evidence or reviews, what can continue, and any cross-project opportunity worth inspecting. Explain every recommendation using the concrete project signals. Do not invent a health score, automatically change Research, or prioritize based on hidden behavioral profiling.';
    return ['prompt'=>$prompt,'context'=>$context,'portfolio'=>$portfolio];
}

function research_portfolio_input_hash(PDO $pdo,array $viewer): string {
    $p=research_portfolio_compose($pdo,$viewer,40);$compact=[];foreach($p['projects'] as $x)$compact[]=['project'=>$x['project']['public_id'],'pinned'=>$x['project']['pinned'],'state'=>$x['attention_state'],'reasons'=>array_map(fn($r)=>[$r['type'],$r['level'],$r['detail']],$x['reasons'])];return hash('sha256',json_encode($compact,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
