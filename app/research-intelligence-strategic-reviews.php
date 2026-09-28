<?php
declare(strict_types=1);

/**
 * Phase 73 Section 5 — Recurring Strategic Review.
 *
 * Reuses the existing Portfolio cycle as the recurring clock and the existing
 * Collaborative Research Review engine for assignments/responses/completion.
 * This layer only freezes deterministic Portfolio strategic state into an
 * immutable review packet and never changes Decision/Action Plan lifecycle.
 */

function research_intelligence_strategic_reviews_ready(PDO $pdo): bool {
    try{
        return installer_table_exists($pdo,'research_intelligence_strategic_reviews')
          && installer_table_exists($pdo,'research_intelligence_strategic_review_settings')
          && research_intelligence_portfolio_operations_ready($pdo)
          && research_reviews_ready($pdo);
    }catch(Throwable $e){return false;}
}
function research_intelligence_strategic_review_cadences(): array {
    return ['every_cycle'=>'Every Portfolio cycle','weekly'=>'Weekly when a Portfolio cycle runs','monthly'=>'Monthly when a Portfolio cycle runs','quarterly'=>'Quarterly when a Portfolio cycle runs'];
}
function research_intelligence_strategic_review_json(mixed $value): array {
    if(is_array($value))return $value;
    if(is_string($value)&&trim($value)!==''){try{$x=json_decode($value,true,512,JSON_THROW_ON_ERROR);return is_array($x)?$x:[];}catch(Throwable $e){return [];}}
    return [];
}
function research_intelligence_strategic_review_anchor_project(PDO $pdo,array $viewer,array $portfolio): ?array {
    $programs=research_intelligence_portfolio_programs($pdo,$viewer,$portfolio);if(!$programs)return null;
    $program=$programs[0];$project=project_access($pdo,(int)$viewer['id'],(string)$program['project_public_id']);return $project?:null;
}
function research_intelligence_strategic_review_candidate_reviewers(PDO $pdo,array $viewer,array $portfolio): array {
    $project=research_intelligence_strategic_review_anchor_project($pdo,$viewer,$portfolio);if(!$project)return [];
    $rows=research_review_eligible_reviewers($pdo,$viewer,(string)$project['public_id']);$out=[];
    foreach($rows as $r){if((int)$r['id']===(int)$viewer['id'])continue;$out[]=$r;}return $out;
}
function research_intelligence_strategic_review_settings(PDO $pdo,array $viewer,string $portfolioPublic): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$p)throw new RuntimeException('Portfolio not found.');
    if(!research_intelligence_strategic_reviews_ready($pdo))return ['ready'=>false,'status'=>'paused','cadence'=>'monthly','due_offset_hours'=>72,'reviewers'=>[],'last_review_at'=>null,'last_strategic_review_id'=>null];
    $q=$pdo->prepare('SELECT * FROM research_intelligence_strategic_review_settings WHERE portfolio_id=? LIMIT 1');$q->execute([(int)$p['id']]);$row=$q->fetch();
    if(!$row)return ['ready'=>true,'status'=>'paused','cadence'=>'monthly','due_offset_hours'=>72,'reviewers'=>[],'last_review_at'=>null,'last_strategic_review_id'=>null];
    return ['ready'=>true,'status'=>(string)$row['status'],'cadence'=>(string)$row['cadence'],'due_offset_hours'=>(int)$row['due_offset_hours'],
      'reviewers'=>research_intelligence_strategic_review_json($row['reviewer_config_json']??null),'last_review_at'=>$row['last_review_at'],
      'last_strategic_review_id'=>$row['last_strategic_review_id']!==null?(int)$row['last_strategic_review_id']:null,'updated_at'=>$row['updated_at']];
}
function research_intelligence_strategic_review_configure(PDO $pdo,array $viewer,string $portfolioPublic,array $input): array {
    if(!research_intelligence_strategic_reviews_ready($pdo))throw new RuntimeException('Recurring Strategic Review requires the latest database upgrade.');
    $p=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$p||!research_intelligence_portfolio_can_write($p))throw new RuntimeException('Strategic Review write access is unavailable.');
    $project=research_intelligence_strategic_review_anchor_project($pdo,$viewer,$p);if(!$project||!project_can_write($project))throw new RuntimeException('Add an accessible Research Program before configuring Strategic Review.');
    $status=(string)($input['status']??'paused');if(!in_array($status,['active','paused'],true))$status='paused';
    $cadence=(string)($input['cadence']??'monthly');if(!isset(research_intelligence_strategic_review_cadences()[$cadence]))$cadence='monthly';
    $due=max(1,min(720,(int)($input['due_offset_hours']??72)));
    $eligible=[];foreach(research_review_eligible_reviewers($pdo,$viewer,(string)$project['public_id']) as $r)if((int)$r['id']!==(int)$viewer['id'])$eligible[(string)$r['public_id']]=$r;
    $raw=(array)($input['reviewers']??[]);$reviewers=[];$seen=[];
    foreach(array_slice($raw,0,100) as $item){
        if(is_string($item))$item=['user_id'=>$item];if(!is_array($item))continue;
        $public=trim((string)($item['user_id']??$item['public_id']??''));if($public===''||isset($seen[$public])||!isset($eligible[$public]))continue;$seen[$public]=true;
        $role=(string)($item['role']??'reviewer');if(!in_array($role,['reviewer','approver'],true))$role='reviewer';
        $reviewers[]=['user_id'=>$public,'role'=>$role,'required'=>array_key_exists('required',$item)?(bool)$item['required']:true];
    }
    if($status==='active'&&!$reviewers)throw new InvalidArgumentException('Choose at least one current collaborator before activating recurring Strategic Review.');
    $json=json_encode($reviewers,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO research_intelligence_strategic_review_settings(portfolio_id,status,cadence,due_offset_hours,reviewer_config_json,configured_by_user_id)
      VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),cadence=VALUES(cadence),due_offset_hours=VALUES(due_offset_hours),reviewer_config_json=VALUES(reviewer_config_json),configured_by_user_id=VALUES(configured_by_user_id),updated_at=NOW()")
      ->execute([(int)$p['id'],$status,$cadence,$due,$json,(int)$viewer['id']]);
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'strategic_review_settings_updated','user',(int)$viewer['id'],['status'=>$status,'cadence'=>$cadence,'reviewer_count'=>count($reviewers),'due_offset_hours'=>$due]);
    return research_intelligence_strategic_review_settings($pdo,$viewer,$portfolioPublic);
}
function research_intelligence_strategic_review_due(array $settings,string $scheduledFor): bool {
    if(($settings['status']??'paused')!=='active'||empty($settings['reviewers']))return false;
    $last=trim((string)($settings['last_review_at']??''));if($last==='')return true;
    $from=new DateTimeImmutable($last,new DateTimeZone('UTC'));$at=new DateTimeImmutable($scheduledFor,new DateTimeZone('UTC'));$cadence=(string)($settings['cadence']??'monthly');
    $next=match($cadence){'every_cycle'=>$from,'weekly'=>$from->modify('+7 days'),'monthly'=>$from->modify('+1 month'),'quarterly'=>$from->modify('+3 months'),default=>$from->modify('+1 month')};
    return $cadence==='every_cycle'||$at>=$next;
}
function research_intelligence_strategic_review_execution_attention(array $rollup): array {
    $out=[];foreach((array)($rollup['decisions']??[]) as $decision)foreach((array)($decision['action_plans']??[]) as $plan){
        $reasons=[];if(!empty($plan['overdue']))$reasons[]='overdue';if((int)($plan['variance']['material_open']??0)>0)$reasons[]='material_variance';
        if((int)($plan['reviews']['open']??0)>0)$reasons[]='open_review';if(($plan['status']??'')==='completed'&&empty($plan['outcome_recorded']))$reasons[]='awaiting_outcome';
        if($reasons)$out[]=['decision_id'=>(string)$decision['public_id'],'decision_title'=>(string)$decision['title'],'action_plan_id'=>(string)$plan['public_id'],'action_plan_title'=>(string)$plan['title'],
          'status'=>(string)$plan['status'],'reasons'=>$reasons,'material_open_variances'=>(int)($plan['variance']['material_open']??0),'open_reviews'=>(int)($plan['reviews']['open']??0),'overdue'=>(bool)($plan['overdue']??false)];
    }
    usort($out,fn($a,$b)=>(count($b['reasons'])<=>count($a['reasons']))?:strcmp($a['action_plan_id'],$b['action_plan_id']));return array_slice($out,0,80);
}
function research_intelligence_strategic_review_packet(PDO $pdo,array $viewer,string $portfolioPublic): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$p)throw new RuntimeException('Portfolio not found.');
    $aggregate=research_intelligence_portfolio_aggregate($pdo,$viewer,$p,30);
    $execution=function_exists('research_intelligence_portfolio_decision_execution_rollup')?research_intelligence_portfolio_decision_execution_rollup($pdo,$viewer,(string)$p['public_id'],120):['summary'=>[],'decisions'=>[]];
    $patterns=function_exists('research_intelligence_portfolio_pattern_memory')?research_intelligence_portfolio_pattern_memory($pdo,$viewer,(string)$p['public_id']):['summary'=>[],'patterns'=>[]];
    $graph=function_exists('research_intelligence_portfolio_strategic_graph')?research_intelligence_portfolio_strategic_graph($pdo,$viewer,(string)$p['public_id'],false,400):['summary'=>[],'attention'=>[]];
    $patternRows=[];foreach(array_slice((array)($patterns['patterns']??[]),0,40) as $x)$patternRows[]=['id'=>(string)$x['public_id'],'type'=>(string)$x['pattern_type'],'label'=>(string)$x['label'],'decision_count'=>(int)$x['decision_count'],'evidence_count'=>(int)$x['evidence_count']];
    $state=[
      'portfolio'=>['public_id'=>(string)$p['public_id'],'title'=>(string)$p['title'],'objective'=>(string)$p['objective']],
      'aggregate'=>['summary'=>$aggregate['summary']??[],'trends'=>$aggregate['trends']??[],'risks'=>array_slice((array)($aggregate['risks']??[]),0,30),'opportunities'=>array_slice((array)($aggregate['opportunities']??[]),0,30),'cross_program'=>array_slice((array)($aggregate['cross_program']??[]),0,30)],
      'execution'=>['summary'=>$execution['summary']??[],'attention'=>research_intelligence_strategic_review_execution_attention($execution)],
      'pattern_memory'=>['summary'=>$patterns['summary']??[],'patterns'=>$patternRows],
      'strategic_graph'=>['summary'=>$graph['summary']??[],'attention'=>array_slice((array)($graph['attention']??[]),0,80)]
    ];
    $focus=[];$es=(array)$state['execution']['summary'];$gs=(array)$state['strategic_graph']['summary'];$ps=(array)$state['pattern_memory']['summary'];
    if((int)($es['high_or_critical_open_variances']??0)>0)$focus[]=['type'=>'execution_variance','severity'=>'high','summary'=>(int)$es['high_or_critical_open_variances'].' high/critical open execution variance(s).'];
    if((int)($es['overdue_action_plans']??0)>0)$focus[]=['type'=>'overdue_execution','severity'=>'high','summary'=>(int)$es['overdue_action_plans'].' overdue Action Plan(s).'];
    if((int)($es['completed_without_outcome']??0)>0)$focus[]=['type'=>'outcome_gap','severity'=>'watch','summary'=>(int)$es['completed_without_outcome'].' completed Action Plan(s) still awaiting Outcome Memory.'];
    if((int)($gs['high_or_critical_conflicts']??0)>0)$focus[]=['type'=>'strategic_conflict','severity'=>'high','summary'=>(int)$gs['high_or_critical_conflicts'].' high/critical strategic conflict(s).'];
    if((int)($gs['unresolved_dependencies']??0)>0)$focus[]=['type'=>'strategic_dependency','severity'=>'watch','summary'=>(int)$gs['unresolved_dependencies'].' unresolved strategic dependency relationship(s).'];
    if((int)($gs['stale_edges']??0)>0)$focus[]=['type'=>'stale_relationship','severity'=>'watch','summary'=>(int)$gs['stale_edges'].' strategic relationship(s) need current-state acknowledgement.'];
    if((int)($ps['active_patterns']??0)>0)$focus[]=['type'=>'organizational_learning','severity'=>'info','summary'=>(int)$ps['active_patterns'].' active cross-Decision learning pattern(s) are available for review.'];
    $state['review_focus']=$focus;$json=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);if($json===false)throw new RuntimeException('Strategic Review packet could not be encoded.');
    return ['captured_at'=>date('Y-m-d H:i:s'),'state'=>$state,'state_hash'=>hash('sha256',$json)];
}
function research_intelligence_strategic_review_resolve_reviewers(PDO $pdo,array $viewer,array $portfolio,array $settings): array {
    $project=research_intelligence_strategic_review_anchor_project($pdo,$viewer,$portfolio);if(!$project)throw new RuntimeException('Strategic Review requires an accessible Portfolio Program.');
    $eligible=[];foreach(research_review_eligible_reviewers($pdo,$viewer,(string)$project['public_id']) as $r)if((int)$r['id']!==(int)$viewer['id'])$eligible[(string)$r['public_id']]=$r;
    $ids=[];$options=[];foreach((array)($settings['reviewers']??[]) as $cfg){$public=(string)($cfg['user_id']??'');if(!isset($eligible[$public]))continue;$id=(int)$eligible[$public]['id'];$ids[]=$id;$options[$id]=['role'=>in_array((string)($cfg['role']??'reviewer'),['reviewer','approver'],true)?(string)$cfg['role']:'reviewer','required'=>array_key_exists('required',$cfg)?(bool)$cfg['required']:true];}
    if(!$ids)throw new RuntimeException('No configured Strategic Review reviewer currently has access to the Portfolio review project.');
    return ['project'=>$project,'reviewer_ids'=>array_values(array_unique($ids)),'assignment_options'=>$options];
}
function research_intelligence_strategic_review_access(PDO $pdo,array $viewer,string $publicId,bool $includeDrift=false): ?array {
    if(!research_intelligence_strategic_reviews_ready($pdo))return null;
    $q=$pdo->prepare("SELECT sr.*,p.public_id portfolio_public_id,p.title portfolio_title,rp.public_id project_public_id,rr.public_id collaborative_review_public_id
      FROM research_intelligence_strategic_reviews sr JOIN research_intelligence_portfolios p ON p.id=sr.portfolio_id JOIN research_projects rp ON rp.id=sr.project_id
      LEFT JOIN research_reviews rr ON rr.id=sr.collaborative_review_id WHERE sr.public_id=? LIMIT 1");$q->execute([trim($publicId)]);$r=$q->fetch();if(!$r)return null;
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,(string)$r['portfolio_public_id']);if(!$portfolio)return null;
    $r['packet']=research_intelligence_strategic_review_json($r['packet_json']??null);unset($r['packet_json']);
    $r['review']=$r['collaborative_review_public_id']?research_review_access($pdo,$viewer,(string)$r['collaborative_review_public_id']):null;
    if($r['review'])$r['review_aggregate']=research_review_aggregate($pdo,$r['review']);
    if($includeDrift){$current=research_intelligence_strategic_review_packet($pdo,$viewer,(string)$r['portfolio_public_id']);$r['current_state_hash']=$current['state_hash'];$r['current_drift']=!hash_equals((string)$r['packet_hash'],(string)$current['state_hash']);}
    return $r;
}
function research_intelligence_strategic_review_subject(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_intelligence_strategic_reviews_ready($pdo))return null;
    $q=$pdo->prepare("SELECT sr.*,p.public_id portfolio_public_id,p.title portfolio_title,rp.public_id project_public_id
      FROM research_intelligence_strategic_reviews sr JOIN research_intelligence_portfolios p ON p.id=sr.portfolio_id JOIN research_projects rp ON rp.id=sr.project_id WHERE sr.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$r=$q->fetch();if(!$r)return null;
    if(!research_intelligence_portfolio_access($pdo,$viewer,(string)$r['portfolio_public_id']))return null;
    $packet=research_intelligence_strategic_review_json($r['packet_json']??null);$state=(array)($packet['state']??[]);$focus=(array)($state['review_focus']??[]);
    $summary='Portfolio strategic review packet with '.count($focus).' deterministic review focus item(s).';
    return ['type'=>'strategic_review','public_id'=>(string)$r['public_id'],'project_id'=>(int)$r['project_id'],'project_public_id'=>(string)$r['project_public_id'],'project_title'=>(string)$r['portfolio_title'],
      'title'=>'Strategic Review: '.(string)$r['portfolio_title'],'hash'=>(string)$r['packet_hash'],'version_label'=>'Strategic Review packet '.(string)$r['created_at'],
      'url'=>'/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$r['portfolio_public_id']).'&strategic_review='.rawurlencode((string)$r['public_id']),
      'summary'=>$summary,'portfolio_public_id'=>(string)$r['portfolio_public_id'],'packet_hash'=>(string)$r['packet_hash']];
}
function research_intelligence_strategic_review_create(PDO $pdo,array $viewer,string $portfolioPublic,array $input=[]): array {
    if(!research_intelligence_strategic_reviews_ready($pdo))throw new RuntimeException('Recurring Strategic Review requires the latest database upgrade.');
    $p=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$p||!research_intelligence_portfolio_can_write($p))throw new RuntimeException('Strategic Review write access is unavailable.');
    $settings=research_intelligence_strategic_review_settings($pdo,$viewer,(string)$p['public_id']);$resolved=research_intelligence_strategic_review_resolve_reviewers($pdo,$viewer,$p,$settings);
    $trigger=(string)($input['trigger_type']??'manual');if(!in_array($trigger,['manual','cycle'],true))$trigger='manual';$cycleId=null;$cyclePublic=trim((string)($input['cycle_public_id']??''));$scheduled=trim((string)($input['scheduled_for']??''));
    if($cyclePublic!==''){$q=$pdo->prepare('SELECT id,scheduled_for FROM research_intelligence_portfolio_cycles WHERE public_id=? AND portfolio_id=? LIMIT 1');$q->execute([$cyclePublic,(int)$p['id']]);$cy=$q->fetch();if(!$cy)throw new RuntimeException('Portfolio cycle is unavailable.');$cycleId=(int)$cy['id'];if($scheduled==='')$scheduled=(string)$cy['scheduled_for'];}
    $slot=$cyclePublic!==''?$cyclePublic:($input['idempotency_key']??ulid_like());$dedupe=hash('sha256','strategic-review|'.$p['id'].'|'.$trigger.'|'.$slot);
    $q=$pdo->prepare('SELECT public_id FROM research_intelligence_strategic_reviews WHERE dedupe_key=? LIMIT 1');$q->execute([$dedupe]);$existing=(string)($q->fetchColumn()?:'');if($existing!=='')return research_intelligence_strategic_review_access($pdo,$viewer,$existing,true)??[];
    $packet=research_intelligence_strategic_review_packet($pdo,$viewer,(string)$p['public_id']);$q=$pdo->prepare('SELECT id,public_id,packet_hash FROM research_intelligence_strategic_reviews WHERE portfolio_id=? ORDER BY id DESC LIMIT 1');$q->execute([(int)$p['id']]);$prev=$q->fetch();$previousHash=$prev?(string)$prev['packet_hash']:null;$changed=$previousHash===null||!hash_equals($previousHash,(string)$packet['state_hash']);
    $public=ulid_like();$packetJson=json_encode($packet,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
    $pdo->prepare("INSERT INTO research_intelligence_strategic_reviews(public_id,portfolio_id,project_id,cycle_id,previous_strategic_review_id,trigger_type,scheduled_for,packet_json,packet_hash,previous_packet_hash,material_changed,dedupe_key,created_by_user_id)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$public,(int)$p['id'],(int)$resolved['project']['id'],$cycleId,$prev?(int)$prev['id']:null,$trigger,$scheduled!==''?$scheduled:null,$packetJson,(string)$packet['state_hash'],$previousHash,$changed?1:0,$dedupe,(int)$viewer['id']]);
    $id=(int)$pdo->lastInsertId();$dueBase=max(time(),$scheduled!==''?(strtotime($scheduled)?:time()):time());$due=date('Y-m-d H:i:s',$dueBase+((int)$settings['due_offset_hours']*3600));
    $instructions=trim((string)($input['instructions']??'Review this frozen Portfolio strategic packet. Evaluate material execution risk, cross-Decision learning, dependencies, conflicts, and whether follow-through or reconsideration should be proposed. Do not change Decision or Action Plan state from this review.'));
    try{
        $review=research_review_create($pdo,$viewer,'strategic_review',$public,$resolved['reviewer_ids'],$due,$instructions,$resolved['assignment_options']);
        $pdo->prepare('UPDATE research_intelligence_strategic_reviews SET collaborative_review_id=?,updated_at=NOW() WHERE id=?')->execute([(int)$review['id'],$id]);
        $pdo->prepare("INSERT INTO research_intelligence_strategic_review_settings(portfolio_id,status,cadence,due_offset_hours,reviewer_config_json,last_strategic_review_id,last_review_at,configured_by_user_id)
          VALUES(?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE last_strategic_review_id=VALUES(last_strategic_review_id),last_review_at=NOW(),updated_at=NOW()")
          ->execute([(int)$p['id'],(string)$settings['status'],(string)$settings['cadence'],(int)$settings['due_offset_hours'],json_encode($settings['reviewers'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$id,(int)$viewer['id']]);
        research_intelligence_portfolio_event($pdo,(int)$p['id'],'strategic_review_created',$trigger==='cycle'?'system':'user',$trigger==='cycle'?null:(int)$viewer['id'],['strategic_review_id'=>$public,'collaborative_review_id'=>$review['public_id'],'trigger_type'=>$trigger,'material_changed'=>$changed,'packet_hash'=>$packet['state_hash']]);
    }catch(Throwable $e){$pdo->prepare('DELETE FROM research_intelligence_strategic_reviews WHERE id=? AND collaborative_review_id IS NULL')->execute([$id]);throw $e;}
    return research_intelligence_strategic_review_access($pdo,$viewer,$public,true)??[];
}
function research_intelligence_strategic_review_maybe_create_for_cycle(PDO $pdo,array $viewer,array $portfolio,array $cycle): ?array {
    if(!research_intelligence_strategic_reviews_ready($pdo))return null;$settings=research_intelligence_strategic_review_settings($pdo,$viewer,(string)$portfolio['public_id']);$scheduled=(string)($cycle['scheduled_for']??date('Y-m-d H:i:s'));
    if(!research_intelligence_strategic_review_due($settings,$scheduled))return null;
    return research_intelligence_strategic_review_create($pdo,$viewer,(string)$portfolio['public_id'],['trigger_type'=>'cycle','cycle_public_id'=>(string)$cycle['public_id'],'scheduled_for'=>$scheduled]);
}
function research_intelligence_strategic_review_history(PDO $pdo,array $viewer,string $portfolioPublic,int $limit=30): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$p)return [];$limit=max(1,min(100,$limit));$q=$pdo->prepare('SELECT public_id FROM research_intelligence_strategic_reviews WHERE portfolio_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([(int)$p['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){$r=research_intelligence_strategic_review_access($pdo,$viewer,(string)$id,false);if($r)$out[]=$r;}return $out;
}
function research_intelligence_portfolio_strategic_review_summary(PDO $pdo,array $viewer,string $portfolioPublic): array {
    if(!research_intelligence_strategic_reviews_ready($pdo))return ['ready'=>false,'settings'=>[],'summary'=>[],'reviews'=>[]];
    $settings=research_intelligence_strategic_review_settings($pdo,$viewer,$portfolioPublic);$rows=research_intelligence_strategic_review_history($pdo,$viewer,$portfolioPublic,30);
    $summary=['total'=>count($rows),'open'=>0,'completed'=>0,'overdue'=>0,'changes_requested'=>0,'unresolved_objections'=>0,'current_drift'=>0];
    foreach($rows as &$r){$review=$r['review']??null;$agg=$r['review_aggregate']??[];if($review){if(($review['status']??'')==='open')$summary['open']++;if(($review['status']??'')==='completed')$summary['completed']++;if(($review['status']??'')==='open'&&!empty($review['due_at'])&&strtotime((string)$review['due_at'])<time())$summary['overdue']++;if(($agg['consensus']??'')==='changes_requested')$summary['changes_requested']++;if(($agg['consensus']??'')==='unresolved_objection')$summary['unresolved_objections']++;}}
    unset($r);if($rows){$latest=research_intelligence_strategic_review_access($pdo,$viewer,(string)$rows[0]['public_id'],true);if($latest&&!empty($latest['current_drift']))$summary['current_drift']=1;}
    return ['ready'=>true,'settings'=>$settings,'summary'=>$summary,'reviews'=>$rows];
}
function research_intelligence_organization_strategic_review_center(PDO $pdo,array $viewer): array {
    if(!research_intelligence_strategic_reviews_ready($pdo))return ['ready'=>false,'summary'=>[],'attention'=>[]];
    $dashboard=research_intelligence_portfolio_dashboard($pdo,$viewer);$summary=['configured_active'=>0,'total_reviews'=>0,'open'=>0,'completed'=>0,'overdue'=>0,'changes_requested'=>0,'unresolved_objections'=>0,'current_drift'=>0];$attention=[];
    foreach($dashboard['portfolios'] as $row){$x=research_intelligence_portfolio_strategic_review_summary($pdo,$viewer,(string)$row['public_id']);if(!$x['ready'])continue;if(($x['settings']['status']??'')==='active')$summary['configured_active']++;
      foreach(['total','open','completed','overdue','changes_requested','unresolved_objections','current_drift'] as $k){$dest=$k==='total'?'total_reviews':$k;$summary[$dest]+=(int)($x['summary'][$k]??0);}
      foreach($x['reviews'] as $r){$review=$r['review']??null;$agg=$r['review_aggregate']??[];if(!$review||($review['status']??'')!=='open')continue;$reasons=[];if(!empty($review['due_at'])&&strtotime((string)$review['due_at'])<time())$reasons[]='overdue';if(($agg['consensus']??'')==='changes_requested')$reasons[]='changes_requested';if(($agg['consensus']??'')==='unresolved_objection')$reasons[]='unresolved_objection';if($reasons)$attention[]=['portfolio_id'=>(string)$row['public_id'],'portfolio_title'=>(string)$row['title'],'strategic_review_id'=>(string)$r['public_id'],'review_id'=>(string)$review['public_id'],'due_at'=>$review['due_at'],'consensus'=>$agg['consensus']??'pending','reasons'=>$reasons];}
    }
    usort($attention,fn($a,$b)=>(in_array('overdue',$b['reasons'],true)<=>in_array('overdue',$a['reasons'],true))?:strcmp((string)$a['due_at'],(string)$b['due_at']));
    return ['ready'=>true,'summary'=>$summary,'attention'=>array_slice($attention,0,80),'generated_at'=>date('Y-m-d H:i:s')];
}
