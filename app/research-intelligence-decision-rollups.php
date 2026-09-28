<?php
declare(strict_types=1);

/**
 * Phase 73 Section 2 — Portfolio Decision & Execution Rollups.
 *
 * Read-only projection across authoritative Phase 71/72 records. No parallel
 * Decision, Action Plan, variance, review, outcome, worker, or scheduler state.
 */

function research_intelligence_portfolio_execution_rollups_ready(PDO $pdo): bool {
    try{
        return research_intelligence_portfolio_native_decisions_ready($pdo)
          && research_action_plans_ready($pdo)
          && research_action_plan_variance_ready($pdo)
          && research_action_plan_outcomes_ready($pdo)
          && research_reviews_ready($pdo);
    }catch(Throwable $e){return false;}
}

function research_intelligence_portfolio_subject_reviews(PDO $pdo,array $viewer,string $type,string $publicId,int $limit=30): array {
    if(!research_reviews_ready($pdo))return ['open'=>0,'completed'=>0,'overdue'=>0,'stale'=>0,'changes_requested'=>0,'unresolved_objections'=>0,'items'=>[]];
    if(!in_array($type,['decision','action_plan'],true))return ['open'=>0,'completed'=>0,'overdue'=>0,'stale'=>0,'changes_requested'=>0,'unresolved_objections'=>0,'items'=>[]];
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT public_id FROM research_reviews WHERE subject_type=? AND subject_public_id=? ORDER BY status='open' DESC,id DESC LIMIT ".$limit);
    $q->execute([$type,trim($publicId)]);
    $out=['open'=>0,'completed'=>0,'overdue'=>0,'stale'=>0,'changes_requested'=>0,'unresolved_objections'=>0,'items'=>[]];
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $reviewPublic){
        $review=research_review_access($pdo,$viewer,(string)$reviewPublic);if(!$review)continue;
        $agg=research_review_aggregate($pdo,$review);$status=(string)$review['status'];
        if($status==='open')$out['open']++;elseif($status==='completed')$out['completed']++;
        $overdue=$status==='open'&&!empty($review['due_at'])&&strtotime((string)$review['due_at'])<time();if($overdue)$out['overdue']++;
        if(!empty($review['is_stale']))$out['stale']++;
        if(($agg['consensus']??'')==='changes_requested')$out['changes_requested']++;
        if(($agg['consensus']??'')==='unresolved_objection')$out['unresolved_objections']++;
        $out['items'][]=[
          'public_id'=>(string)$review['public_id'],'title'=>(string)$review['title'],'status'=>$status,'consensus'=>(string)($agg['consensus']??''),
          'due_at'=>$review['due_at']??null,'overdue'=>$overdue,'is_stale'=>(bool)($review['is_stale']??false)
        ];
    }
    return $out;
}

function research_intelligence_portfolio_action_plan_rollup(PDO $pdo,array $viewer,array $plan): array {
    $public=(string)$plan['public_id'];$variance=research_action_plan_execution_variance_summary($pdo,$viewer,$public);
    $outcome=research_action_plan_outcome_link($pdo,$viewer,$public);$reviews=research_intelligence_portfolio_subject_reviews($pdo,$viewer,'action_plan',$public,30);
    $milestones=research_action_plan_execution_ready($pdo)?research_action_plan_milestones($pdo,$viewer,$public):[];
    $milestoneSummary=['total'=>count($milestones),'pending'=>0,'active'=>0,'completed'=>0,'cancelled'=>0];
    foreach($milestones as $m){$s=(string)($m['status']??'pending');if(isset($milestoneSummary[$s]))$milestoneSummary[$s]++;}
    $tasks=['total'=>0,'open'=>0,'complete'=>0,'overdue'=>0];
    if(research_action_plan_execution_ready($pdo)){
        $q=$pdo->prepare("SELECT rt.status,rt.due_at FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id WHERE l.action_plan_id=?");
        $q->execute([(int)$plan['id']]);foreach($q->fetchAll()?:[] as $t){$tasks['total']++;$done=in_array((string)$t['status'],['complete','done','archived'],true);if($done)$tasks['complete']++;else{$tasks['open']++;if(!empty($t['due_at'])&&strtotime((string)$t['due_at'])<time())$tasks['overdue']++;}}
    }
    $status=(string)$plan['status'];$overdue=!in_array($status,['completed','cancelled','archived'],true)&&!empty($plan['due_on'])&&strtotime((string)$plan['due_on'])<strtotime(gmdate('Y-m-d'));
    return [
      'public_id'=>$public,'title'=>(string)$plan['title'],'status'=>$status,'priority'=>(string)$plan['priority'],
      'owner_user_public_id'=>(string)($plan['owner_user_public_id']??''),'due_on'=>$plan['due_on']??null,'overdue'=>$overdue,
      'source_stale'=>(bool)($plan['source_stale']??false),'current_revision'=>(int)$plan['current_revision'],
      'variance'=>$variance,'reviews'=>$reviews,'milestones'=>$milestoneSummary,'tasks'=>$tasks,
      'outcome_recorded'=>$outcome!==null,'outcome'=>$outcome?[
        'decision_outcome_public_id'=>(string)$outcome['decision_outcome_public_id'],
        'outcome_type'=>(string)($outcome['outcome']['outcome_type']??''),
        'created_at'=>(string)($outcome['created_at']??'')
      ]:null
    ];
}

function research_intelligence_portfolio_decision_execution_rollup(PDO $pdo,array $viewer,string $portfolioPublic,int $limit=100): array {
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$portfolio)throw new RuntimeException('Portfolio not found.');
    if(!research_intelligence_portfolio_execution_rollups_ready($pdo))return ['ready'=>false,'summary'=>[],'decisions'=>[],'legacy_count'=>0];
    $limit=max(1,min(250,$limit));$rows=research_intelligence_portfolio_decision_rows($pdo,$viewer,$portfolio,$limit);
    $summary=[
      'native_decisions'=>0,'legacy_records'=>0,'draft_decisions'=>0,'proposed_decisions'=>0,'accepted_decisions'=>0,'reopened_decisions'=>0,
      'action_plans'=>0,'active_action_plans'=>0,'completed_action_plans'=>0,'overdue_action_plans'=>0,'stale_action_plans'=>0,
      'open_variances'=>0,'material_open_variances'=>0,'high_or_critical_open_variances'=>0,
      'open_reviews'=>0,'overdue_reviews'=>0,'outcomes_recorded'=>0,'completed_without_outcome'=>0
    ];
    $decisions=[];
    foreach($rows as $row){
        if(($row['record_kind']??'')!=='native'){$summary['legacy_records']++;continue;}
        $decisionPublic=(string)$row['decision_public_id'];$decision=research_decision_detail($pdo,$viewer,$decisionPublic);if(!$decision)continue;
        $summary['native_decisions']++;$ds=(string)$decision['status'];$key=$ds.'_decisions';if(isset($summary[$key]))$summary[$key]++;
        $decisionReviews=research_intelligence_portfolio_subject_reviews($pdo,$viewer,'decision',$decisionPublic,30);
        $summary['open_reviews']+=(int)$decisionReviews['open'];$summary['overdue_reviews']+=(int)$decisionReviews['overdue'];
        $plans=[];foreach(research_action_plan_list($pdo,$viewer,null,$decisionPublic,100) as $plan){
            $p=research_intelligence_portfolio_action_plan_rollup($pdo,$viewer,$plan);$plans[]=$p;$summary['action_plans']++;
            if($p['status']==='active')$summary['active_action_plans']++;if($p['status']==='completed')$summary['completed_action_plans']++;
            if($p['overdue'])$summary['overdue_action_plans']++;if($p['source_stale'])$summary['stale_action_plans']++;
            $summary['open_variances']+=(int)$p['variance']['open'];$summary['material_open_variances']+=(int)$p['variance']['material_open'];
            $summary['high_or_critical_open_variances']+=(int)$p['variance']['high_or_critical_open'];
            $summary['open_reviews']+=(int)$p['reviews']['open'];$summary['overdue_reviews']+=(int)$p['reviews']['overdue'];
            if($p['outcome_recorded'])$summary['outcomes_recorded']++;elseif($p['status']==='completed')$summary['completed_without_outcome']++;
        }
        $decisions[]=[
          'public_id'=>$decisionPublic,'title'=>(string)$decision['title'],'decision_type'=>(string)$decision['decision_type'],'status'=>$ds,
          'confidence'=>$decision['confidence']!==null?(float)$decision['confidence']:null,'decided_at'=>$decision['decided_at']??null,
          'current_revision'=>(int)$decision['current_revision'],'reviews'=>$decisionReviews,'action_plans'=>$plans
        ];
    }
    return ['ready'=>true,'summary'=>$summary,'decisions'=>$decisions,'legacy_count'=>$summary['legacy_records'],'generated_at'=>date('Y-m-d H:i:s')];
}
