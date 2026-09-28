<?php
declare(strict_types=1);

/**
 * Phase 73 Section 4 — Strategic Dependency & Conflict Graph.
 *
 * Explicit user-authored relationships over authoritative Decision/Action Plan
 * nodes. The graph detects staleness and structural cycles but never mutates
 * source Decision or execution state.
 */

function research_intelligence_strategic_graph_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_intelligence_strategic_edges')&&installer_table_exists($pdo,'research_intelligence_strategic_edge_events')&&research_intelligence_portfolio_execution_rollups_ready($pdo);}
    catch(Throwable $e){return false;}
}
function research_intelligence_strategic_relation_types(): array {
    return [
      'depends_on'=>'Depends on','supports'=>'Supports','conflicts_with'=>'Conflicts with','duplicates'=>'Duplicates',
      'supersedes'=>'Supersedes','blocks'=>'Blocks','materially_affects'=>'Materially affects'
    ];
}
function research_intelligence_strategic_symmetric_relations(): array {return ['conflicts_with','duplicates'];}
function research_intelligence_strategic_cycle_relations(): array {return ['depends_on','blocks','supersedes'];}
function research_intelligence_strategic_node_key(string $type,string $publicId): string {return $type.'|'.$publicId;}

function research_intelligence_strategic_node_portfolios(PDO $pdo,array $viewer,string $type,int $sourceId): array {
    if($type==='decision'){
        $q=$pdo->prepare("SELECT DISTINCT p.public_id FROM research_intelligence_portfolio_decision_links l JOIN research_intelligence_portfolios p ON p.id=l.portfolio_id WHERE l.decision_id=? ORDER BY p.id");
        $q->execute([$sourceId]);
    }else{
        $q=$pdo->prepare("SELECT DISTINCT p.public_id FROM research_action_plans ap JOIN research_intelligence_portfolio_decision_links l ON l.decision_id=ap.decision_id JOIN research_intelligence_portfolios p ON p.id=l.portfolio_id WHERE ap.id=? ORDER BY p.id");
        $q->execute([$sourceId]);
    }
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $public)if(research_intelligence_portfolio_access($pdo,$viewer,(string)$public))$out[]=(string)$public;
    return array_values(array_unique($out));
}

function research_intelligence_strategic_node(PDO $pdo,array $viewer,string $type,string $publicId): ?array {
    $type=trim($type);$publicId=trim($publicId);if(!in_array($type,['decision','action_plan'],true)||$publicId==='')return null;
    if($type==='decision'){
        $d=research_decision_detail($pdo,$viewer,$publicId);if(!$d)return null;
        $stateHash=hash('sha256',json_encode([
          'public_id'=>(string)$d['public_id'],'status'=>(string)$d['status'],'current_revision'=>(int)$d['current_revision'],
          'config_hash'=>(string)$d['config_hash'],'decided_at'=>(string)($d['decided_at']??'')
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $challengeCounts=['contradicting_refs'=>0,'open_challenges'=>0,'high_open_challenges'=>0,'reversal_conditions'=>0];
        foreach((array)$d['refs'] as $ref)if(($ref['ref_role']??'')==='contradicts')$challengeCounts['contradicting_refs']++;
        foreach((array)$d['challenges'] as $challenge){if(($challenge['status']??'')==='open'){$challengeCounts['open_challenges']++;if(in_array((string)($challenge['severity']??''),['high','critical'],true))$challengeCounts['high_open_challenges']++;}if(($challenge['challenge_type']??'')==='reversal_condition')$challengeCounts['reversal_conditions']++;}
        return [
          'type'=>'decision','public_id'=>(string)$d['public_id'],'internal_id'=>(int)$d['id'],'title'=>(string)$d['title'],'status'=>(string)$d['status'],
          'project_public_id'=>(string)$d['project_public_id'],'revision'=>(int)$d['current_revision'],'config_hash'=>(string)$d['config_hash'],'state_hash'=>$stateHash,
          'challenge_counts'=>$challengeCounts,'portfolio_ids'=>research_intelligence_strategic_node_portfolios($pdo,$viewer,'decision',(int)$d['id'])
        ];
    }
    $p=research_action_plan_detail($pdo,$viewer,$publicId);if(!$p)return null;
    $stateHash=function_exists('research_action_plan_review_state_hash')?research_action_plan_review_state_hash($pdo,$viewer,$publicId)
      :hash('sha256',json_encode(['public_id'=>$publicId,'status'=>(string)$p['status'],'current_revision'=>(int)$p['current_revision'],'config_hash'=>(string)$p['config_hash']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return [
      'type'=>'action_plan','public_id'=>(string)$p['public_id'],'internal_id'=>(int)$p['id'],'title'=>(string)$p['title'],'status'=>(string)$p['status'],
      'project_public_id'=>(string)$p['project_public_id'],'revision'=>(int)$p['current_revision'],'config_hash'=>(string)$p['config_hash'],'state_hash'=>$stateHash,
      'decision_public_id'=>(string)$p['decision_public_id'],'portfolio_ids'=>research_intelligence_strategic_node_portfolios($pdo,$viewer,'action_plan',(int)$p['id'])
    ];
}
function research_intelligence_strategic_available_nodes(PDO $pdo,array $viewer,int $limit=400): array {
    if(!research_intelligence_strategic_graph_ready($pdo))return [];$limit=max(1,min(800,$limit));$out=[];
    $dashboard=research_intelligence_portfolio_dashboard($pdo,$viewer);
    foreach($dashboard['portfolios'] as $p){
        $portfolio=research_intelligence_portfolio_access($pdo,$viewer,(string)$p['public_id']);if(!$portfolio)continue;
        foreach(research_intelligence_portfolio_decision_rows($pdo,$viewer,$portfolio,250) as $row){
            if(($row['record_kind']??'')!=='native'||empty($row['decision_public_id']))continue;
            $d=research_intelligence_strategic_node($pdo,$viewer,'decision',(string)$row['decision_public_id']);if($d)$out[research_intelligence_strategic_node_key('decision',(string)$d['public_id'])]=$d;
            foreach(research_action_plan_list($pdo,$viewer,null,(string)$row['decision_public_id'],100) as $plan){
                $ap=research_intelligence_strategic_node($pdo,$viewer,'action_plan',(string)$plan['public_id']);if($ap)$out[research_intelligence_strategic_node_key('action_plan',(string)$ap['public_id'])]=$ap;
            }
        }
        if(count($out)>=$limit)break;
    }
    $rows=array_values($out);usort($rows,fn($a,$b)=>strcmp($a['type'].'|'.$a['title'].'|'.$a['public_id'],$b['type'].'|'.$b['title'].'|'.$b['public_id']));return array_slice($rows,0,$limit);
}
function research_intelligence_strategic_portfolio_nodes(PDO $pdo,array $viewer,string $portfolioPublic): array {
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$portfolio)return [];$out=[];
    foreach(research_intelligence_portfolio_decision_rows($pdo,$viewer,$portfolio,250) as $row){
        if(($row['record_kind']??'')!=='native'||empty($row['decision_public_id']))continue;
        $d=research_intelligence_strategic_node($pdo,$viewer,'decision',(string)$row['decision_public_id']);if($d)$out[]=$d;
        foreach(research_action_plan_list($pdo,$viewer,null,(string)$row['decision_public_id'],100) as $plan){$ap=research_intelligence_strategic_node($pdo,$viewer,'action_plan',(string)$plan['public_id']);if($ap)$out[]=$ap;}
    }
    return $out;
}
function research_intelligence_strategic_normalize_endpoints(string $relation,array $source,array $target): array {
    if(in_array($relation,research_intelligence_strategic_symmetric_relations(),true)){
        $a=research_intelligence_strategic_node_key($source['type'],$source['public_id']);$b=research_intelligence_strategic_node_key($target['type'],$target['public_id']);
        if(strcmp($a,$b)>0)return [$target,$source];
    }
    return [$source,$target];
}
function research_intelligence_strategic_cycle_exists(PDO $pdo,string $relation,array $source,array $target): bool {
    if(!in_array($relation,research_intelligence_strategic_cycle_relations(),true))return false;
    $goal=research_intelligence_strategic_node_key($source['type'],$source['public_id']);$start=research_intelligence_strategic_node_key($target['type'],$target['public_id']);
    $q=$pdo->prepare('SELECT source_type,source_public_id,target_type,target_public_id FROM research_intelligence_strategic_edges WHERE active=1 AND relation_type=?');$q->execute([$relation]);
    $adj=[];foreach($q->fetchAll()?:[] as $row)$adj[research_intelligence_strategic_node_key((string)$row['source_type'],(string)$row['source_public_id'])][]=research_intelligence_strategic_node_key((string)$row['target_type'],(string)$row['target_public_id']);
    $stack=[$start];$seen=[];while($stack){$node=array_pop($stack);if($node===$goal)return true;if(isset($seen[$node]))continue;$seen[$node]=true;foreach($adj[$node]??[] as $next)$stack[]=$next;}return false;
}
function research_intelligence_strategic_edge_snapshot(array $edge,array $source,array $target): array {
    return [
      'public_id'=>(string)$edge['public_id'],'relation_type'=>(string)$edge['relation_type'],
      'source'=>['type'=>$source['type'],'public_id'=>$source['public_id'],'title'=>$source['title'],'status'=>$source['status'],'revision'=>$source['revision']??null,'state_hash'=>$source['state_hash']],
      'target'=>['type'=>$target['type'],'public_id'=>$target['public_id'],'title'=>$target['title'],'status'=>$target['status'],'revision'=>$target['revision']??null,'state_hash'=>$target['state_hash']],
      'rationale'=>(string)$edge['rationale'],'confidence'=>$edge['confidence']!==null?(float)$edge['confidence']:null,'materiality'=>(string)($edge['materiality']??'medium'),'active'=>(bool)$edge['active'],'removal_reason'=>(string)($edge['removal_reason']??'')
    ];
}
function research_intelligence_strategic_edge_event(PDO $pdo,int $edgeId,string $type,?int $userId,array $snapshot): void {
    $pdo->prepare('INSERT INTO research_intelligence_strategic_edge_events(public_id,edge_id,event_type,actor_user_id,snapshot_json) VALUES(?,?,?,?,?)')
      ->execute([ulid_like(),$edgeId,$type,$userId,json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)]);
}
function research_intelligence_strategic_edge_access(PDO $pdo,array $viewer,string $edgePublic): ?array {
    if(!research_intelligence_strategic_graph_ready($pdo))return null;
    $q=$pdo->prepare('SELECT e.*,p.public_id created_in_portfolio_public_id,p.title created_in_portfolio_title FROM research_intelligence_strategic_edges e JOIN research_intelligence_portfolios p ON p.id=e.created_in_portfolio_id WHERE e.public_id=? LIMIT 1');$q->execute([trim($edgePublic)]);$edge=$q->fetch();if(!$edge)return null;
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,(string)$edge['created_in_portfolio_public_id']);if(!$portfolio)return null;
    $source=research_intelligence_strategic_node($pdo,$viewer,(string)$edge['source_type'],(string)$edge['source_public_id']);$target=research_intelligence_strategic_node($pdo,$viewer,(string)$edge['target_type'],(string)$edge['target_public_id']);if(!$source||!$target)return null;
    $edge['source']=$source;$edge['target']=$target;$edge['source_stale']=!hash_equals((string)$edge['source_state_hash'],(string)$source['state_hash']);$edge['target_stale']=!hash_equals((string)$edge['target_state_hash'],(string)$target['state_hash']);$edge['stale']=$edge['source_stale']||$edge['target_stale'];
    $edge['cross_portfolio']=count(array_intersect($source['portfolio_ids'],$target['portfolio_ids']))===0;
    return $edge;
}
function research_intelligence_strategic_edge_upsert(PDO $pdo,array $viewer,string $portfolioPublic,array $input): array {
    if(!research_intelligence_strategic_graph_ready($pdo))throw new RuntimeException('Strategic Dependency Graph requires the latest database upgrade.');
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$portfolio||!research_intelligence_portfolio_can_write($portfolio))throw new RuntimeException('Strategic Graph write access is unavailable.');
    $relation=(string)($input['relation_type']??'');if(!isset(research_intelligence_strategic_relation_types()[$relation]))throw new InvalidArgumentException('Invalid strategic relationship.');
    $source=research_intelligence_strategic_node($pdo,$viewer,(string)($input['source_type']??''),(string)($input['source_public_id']??''));$target=research_intelligence_strategic_node($pdo,$viewer,(string)($input['target_type']??''),(string)($input['target_public_id']??''));if(!$source||!$target)throw new RuntimeException('Strategic Graph endpoint is unavailable.');
    if(!in_array((string)$portfolio['public_id'],$source['portfolio_ids'],true))throw new InvalidArgumentException('The source node must belong to the selected Portfolio.');
    if(!$target['portfolio_ids'])throw new InvalidArgumentException('The target node must belong to an accessible Intelligence Portfolio.');
    if($source['type']===$target['type']&&$source['public_id']===$target['public_id'])throw new InvalidArgumentException('A strategic relationship cannot point to itself.');
    if(in_array($relation,['duplicates','supersedes'],true)&&$source['type']!==$target['type'])throw new InvalidArgumentException('Duplicate and supersede relationships must connect the same strategic object type.');
    [$source,$target]=research_intelligence_strategic_normalize_endpoints($relation,$source,$target);
    $rationale=mb_substr(trim((string)($input['rationale']??'')),0,12000);if($rationale==='')throw new InvalidArgumentException('Strategic relationship rationale is required.');
    $confidence=array_key_exists('confidence',$input)&&$input['confidence']!==''?(float)$input['confidence']:null;if($confidence!==null&&($confidence<0||$confidence>1))throw new InvalidArgumentException('Strategic relationship confidence must be between 0 and 1.');
    $materiality=(string)($input['materiality']??'medium');if(!in_array($materiality,['low','medium','high','critical'],true))throw new InvalidArgumentException('Invalid strategic relationship materiality.');
    if(research_intelligence_strategic_cycle_exists($pdo,$relation,$source,$target))throw new InvalidArgumentException('That relationship would create a strategic '.$relation.' cycle.');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM research_intelligence_strategic_edges WHERE source_type=? AND source_public_id=? AND target_type=? AND target_public_id=? AND relation_type=? FOR UPDATE');$q->execute([$source['type'],$source['public_id'],$target['type'],$target['public_id'],$relation]);$edge=$q->fetch();
        if($edge&&$edge['active']){
            if($owns)$pdo->commit();return research_intelligence_strategic_edge_access($pdo,$viewer,(string)$edge['public_id'])??$edge;
        }
        if($edge){
            $pdo->prepare('UPDATE research_intelligence_strategic_edges SET created_in_portfolio_id=?,rationale=?,confidence=?,materiality=?,source_state_hash=?,target_state_hash=?,active=1,created_by_user_id=?,removal_reason=NULL,removed_by_user_id=NULL,removed_at=NULL,updated_at=NOW() WHERE id=?')
              ->execute([(int)$portfolio['id'],$rationale,$confidence,$materiality,$source['state_hash'],$target['state_hash'],(int)$viewer['id'],(int)$edge['id']]);
            $q=$pdo->prepare('SELECT * FROM research_intelligence_strategic_edges WHERE id=?');$q->execute([(int)$edge['id']]);$edge=$q->fetch();research_intelligence_strategic_edge_event($pdo,(int)$edge['id'],'restored',(int)$viewer['id'],research_intelligence_strategic_edge_snapshot($edge,$source,$target));
        }else{
            $public=ulid_like();$pdo->prepare('INSERT INTO research_intelligence_strategic_edges(public_id,created_in_portfolio_id,source_type,source_public_id,target_type,target_public_id,relation_type,rationale,confidence,materiality,source_state_hash,target_state_hash,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([$public,(int)$portfolio['id'],$source['type'],$source['public_id'],$target['type'],$target['public_id'],$relation,$rationale,$confidence,$materiality,$source['state_hash'],$target['state_hash'],(int)$viewer['id']]);
            $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM research_intelligence_strategic_edges WHERE id=?');$q->execute([$id]);$edge=$q->fetch();research_intelligence_strategic_edge_event($pdo,$id,'created',(int)$viewer['id'],research_intelligence_strategic_edge_snapshot($edge,$source,$target));
        }
        research_intelligence_portfolio_event($pdo,(int)$portfolio['id'],'strategic_edge_recorded','user',(int)$viewer['id'],['edge_id'=>(string)$edge['public_id'],'relation_type'=>$relation,'materiality'=>$materiality,'source_type'=>$source['type'],'source_public_id'=>$source['public_id'],'target_type'=>$target['type'],'target_public_id'=>$target['public_id']]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return research_intelligence_strategic_edge_access($pdo,$viewer,(string)$edge['public_id'])??$edge;
}
function research_intelligence_strategic_edge_refresh(PDO $pdo,array $viewer,string $edgePublic,array $input=[]): array {
    $edge=research_intelligence_strategic_edge_access($pdo,$viewer,$edgePublic);if(!$edge)throw new RuntimeException('Strategic relationship not found.');if(!$edge['active'])throw new RuntimeException('Removed strategic relationship cannot be refreshed.');
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,(string)$edge['created_in_portfolio_public_id']);if(!$portfolio||!research_intelligence_portfolio_can_write($portfolio))throw new RuntimeException('Strategic Graph write access is unavailable.');
    $rationale=array_key_exists('rationale',$input)?mb_substr(trim((string)$input['rationale']),0,12000):(string)$edge['rationale'];if($rationale==='')throw new InvalidArgumentException('Strategic relationship rationale is required.');
    $confidence=array_key_exists('confidence',$input)&&$input['confidence']!==''?(float)$input['confidence']:($edge['confidence']!==null?(float)$edge['confidence']:null);if($confidence!==null&&($confidence<0||$confidence>1))throw new InvalidArgumentException('Strategic relationship confidence must be between 0 and 1.');
    $materiality=(string)($input['materiality']??$edge['materiality']??'medium');if(!in_array($materiality,['low','medium','high','critical'],true))throw new InvalidArgumentException('Invalid strategic relationship materiality.');
    $pdo->prepare('UPDATE research_intelligence_strategic_edges SET rationale=?,confidence=?,materiality=?,source_state_hash=?,target_state_hash=?,updated_at=NOW() WHERE id=?')->execute([$rationale,$confidence,$materiality,$edge['source']['state_hash'],$edge['target']['state_hash'],(int)$edge['id']]);
    $fresh=research_intelligence_strategic_edge_access($pdo,$viewer,$edgePublic);research_intelligence_strategic_edge_event($pdo,(int)$edge['id'],'updated',(int)$viewer['id'],research_intelligence_strategic_edge_snapshot($fresh,$fresh['source'],$fresh['target']));return $fresh;
}
function research_intelligence_strategic_edge_remove(PDO $pdo,array $viewer,string $edgePublic,string $reason): array {
    $edge=research_intelligence_strategic_edge_access($pdo,$viewer,$edgePublic);if(!$edge)throw new RuntimeException('Strategic relationship not found.');
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,(string)$edge['created_in_portfolio_public_id']);if(!$portfolio||!research_intelligence_portfolio_can_write($portfolio))throw new RuntimeException('Strategic Graph write access is unavailable.');
    if(!$edge['active'])return $edge;$reason=mb_substr(trim($reason),0,12000);if($reason==='')throw new InvalidArgumentException('A removal reason is required.');
    $snapshot=research_intelligence_strategic_edge_snapshot($edge,$edge['source'],$edge['target']);$snapshot['removal_reason']=$reason;
    research_intelligence_strategic_edge_event($pdo,(int)$edge['id'],'removed',(int)$viewer['id'],$snapshot);
    $pdo->prepare('UPDATE research_intelligence_strategic_edges SET active=0,removal_reason=?,removed_by_user_id=?,removed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$reason,(int)$viewer['id'],(int)$edge['id']]);
    research_intelligence_portfolio_event($pdo,(int)$portfolio['id'],'strategic_edge_removed','user',(int)$viewer['id'],['edge_id'=>$edgePublic,'relation_type'=>(string)$edge['relation_type'],'reason'=>$reason]);
    return research_intelligence_strategic_edge_access($pdo,$viewer,$edgePublic)??$edge;
}
function research_intelligence_strategic_edge_events(PDO $pdo,array $viewer,string $edgePublic,int $limit=100): array {
    $edge=research_intelligence_strategic_edge_access($pdo,$viewer,$edgePublic);if(!$edge)return [];$limit=max(1,min(300,$limit));
    $q=$pdo->prepare('SELECT public_id,event_type,actor_user_id,snapshot_json,created_at FROM research_intelligence_strategic_edge_events WHERE edge_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([(int)$edge['id']]);$rows=$q->fetchAll()?:[];foreach($rows as &$r){$x=json_decode((string)$r['snapshot_json'],true);$r['snapshot']=is_array($x)?$x:[];unset($r['snapshot_json']);}unset($r);return $rows;
}
function research_intelligence_portfolio_strategic_graph(PDO $pdo,array $viewer,string $portfolioPublic,bool $includeRemoved=false,int $limit=300): array {
    if(!research_intelligence_strategic_graph_ready($pdo))return ['ready'=>false,'summary'=>[],'edges'=>[],'nodes'=>[],'attention'=>[]];
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$portfolio)throw new RuntimeException('Portfolio not found.');$limit=max(1,min(600,$limit));
    $nodes=research_intelligence_strategic_portfolio_nodes($pdo,$viewer,(string)$portfolio['public_id']);$keys=[];foreach($nodes as $n)$keys[research_intelligence_strategic_node_key($n['type'],$n['public_id'])]=true;
    $where=$includeRemoved?'':' AND active=1';$q=$pdo->query("SELECT public_id FROM research_intelligence_strategic_edges WHERE 1=1{$where} ORDER BY active DESC,updated_at DESC,id DESC LIMIT ".$limit*3);
    $edges=[];$degrees=[];$summary=['active_edges'=>0,'removed_edges'=>0,'dependencies'=>0,'supports'=>0,'conflicts'=>0,'duplicates'=>0,'supersedes'=>0,'blocks'=>0,'materially_affects'=>0,'stale_edges'=>0,'cross_portfolio_edges'=>0];
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $edgePublic){
        $edge=research_intelligence_strategic_edge_access($pdo,$viewer,(string)$edgePublic);if(!$edge)continue;
        $sk=research_intelligence_strategic_node_key((string)$edge['source_type'],(string)$edge['source_public_id']);$tk=research_intelligence_strategic_node_key((string)$edge['target_type'],(string)$edge['target_public_id']);if(!isset($keys[$sk])&&!isset($keys[$tk]))continue;
        $edges[]=$edge;if($edge['active'])$summary['active_edges']++;else $summary['removed_edges']++;
        $map=['depends_on'=>'dependencies','supports'=>'supports','conflicts_with'=>'conflicts','duplicates'=>'duplicates','supersedes'=>'supersedes','blocks'=>'blocks','materially_affects'=>'materially_affects'];if($edge['active']&&isset($map[$edge['relation_type']]))$summary[$map[$edge['relation_type']]]++;
        if($edge['active']&&$edge['stale'])$summary['stale_edges']++;if($edge['active']&&$edge['cross_portfolio'])$summary['cross_portfolio_edges']++;
        if($edge['active']){$degrees[$sk]=($degrees[$sk]??0)+1;$degrees[$tk]=($degrees[$tk]??0)+1;}
        if(count($edges)>=$limit)break;
    }
    foreach($nodes as &$n)$n['degree']=$degrees[research_intelligence_strategic_node_key($n['type'],$n['public_id'])]??0;unset($n);
    $attention=[];foreach($edges as $e)if($e['active']&&($e['stale']||in_array($e['relation_type'],['conflicts_with','blocks'],true)))$attention[]=[
      'edge_id'=>(string)$e['public_id'],'relation_type'=>(string)$e['relation_type'],'stale'=>(bool)$e['stale'],'cross_portfolio'=>(bool)$e['cross_portfolio'],
      'source_type'=>(string)$e['source_type'],'source_public_id'=>(string)$e['source_public_id'],'source_title'=>(string)$e['source']['title'],
      'target_type'=>(string)$e['target_type'],'target_public_id'=>(string)$e['target_public_id'],'target_title'=>(string)$e['target']['title'],'rationale'=>(string)$e['rationale']
    ];
    usort($attention,fn($a,$b)=>(($b['stale']?4:0)+($b['relation_type']==='blocks'?2:0)+($b['relation_type']==='conflicts_with'?1:0))<=>(($a['stale']?4:0)+($a['relation_type']==='blocks'?2:0)+($a['relation_type']==='conflicts_with'?1:0)));
    return ['ready'=>true,'summary'=>$summary,'edges'=>$edges,'nodes'=>$nodes,'attention'=>$attention,'generated_at'=>date('Y-m-d H:i:s')];
}
function research_intelligence_organization_strategic_graph(PDO $pdo,array $viewer): array {
    $dashboard=research_intelligence_portfolio_dashboard($pdo,$viewer);$summary=['active_edges'=>0,'conflicts'=>0,'blocks'=>0,'stale_edges'=>0,'cross_portfolio_edges'=>0];$attention=[];$seen=[];
    foreach($dashboard['portfolios'] as $row){$g=research_intelligence_portfolio_strategic_graph($pdo,$viewer,(string)$row['public_id'],false,300);foreach(array_keys($summary) as $k)$summary[$k]+=(int)($g['summary'][$k]??0);
      foreach($g['attention'] as $a)if(!isset($seen[$a['edge_id']])){$seen[$a['edge_id']]=true;$attention[]=['portfolio_id'=>(string)$row['public_id'],'portfolio_title'=>(string)$row['title']]+$a;}}
    /* Edges present in two accessible Portfolios are counted twice above; de-duplicate organization totals from actual accessible unique edges. */
    $unique=[];foreach($dashboard['portfolios'] as $row){$g=research_intelligence_portfolio_strategic_graph($pdo,$viewer,(string)$row['public_id'],false,300);foreach($g['edges'] as $e)if($e['active'])$unique[$e['public_id']]=$e;}
    $summary=['active_edges'=>count($unique),'conflicts'=>0,'blocks'=>0,'stale_edges'=>0,'cross_portfolio_edges'=>0];foreach($unique as $e){if($e['relation_type']==='conflicts_with')$summary['conflicts']++;if($e['relation_type']==='blocks')$summary['blocks']++;if($e['stale'])$summary['stale_edges']++;if($e['cross_portfolio'])$summary['cross_portfolio_edges']++;}
    usort($attention,fn($a,$b)=>(($b['stale']?4:0)+($b['relation_type']==='blocks'?2:0)+($b['relation_type']==='conflicts_with'?1:0))<=>(($a['stale']?4:0)+($a['relation_type']==='blocks'?2:0)+($a['relation_type']==='conflicts_with'?1:0)));
    return ['summary'=>$summary,'attention'=>array_slice($attention,0,80),'generated_at'=>date('Y-m-d H:i:s')];
}
