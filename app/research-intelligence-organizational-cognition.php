<?php
declare(strict_types=1);

/**
 * Phase 73 Section 7 — Organizational Agent Cognition & Governed Follow-Through.
 *
 * This is a deterministic, read-only cognition projection over authoritative
 * Phase 71–73 state. Durable writes continue to use existing governed stores:
 * agent_action_proposals, Decisions, Action Plans, Collaborative Review, and
 * Executive Strategic Briefings. No cognition ledger, worker, or scheduler.
 */

function research_intelligence_organizational_cognition_ready(PDO $pdo): bool {
    try{
        return research_intelligence_portfolios_ready($pdo)
          && research_intelligence_portfolio_execution_rollups_ready($pdo)
          && research_intelligence_pattern_memory_ready($pdo)
          && research_intelligence_strategic_graph_ready($pdo)
          && research_intelligence_strategic_reviews_ready($pdo)
          && research_intelligence_strategic_briefings_ready($pdo)
          && research_decision_reconsiderations_ready($pdo)
          && agent_actions_ready($pdo);
    }catch(Throwable $e){return false;}
}

function research_intelligence_organizational_severity_rank(string $severity): int {
    return match($severity){'critical'=>4,'high'=>3,'medium'=>2,'low'=>1,default=>0};
}
function research_intelligence_organizational_state_hash(PDO $pdo,array $viewer,string $portfolioPublic): string {
    $packet=research_intelligence_strategic_review_packet($pdo,$viewer,$portfolioPublic);
    $hash=(string)($packet['state_hash']??'');
    if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('Portfolio strategic state hash is unavailable.');
    return $hash;
}
function research_intelligence_organizational_signal(array &$signals,array $signal): void {
    $signal['severity']=in_array((string)($signal['severity']??''),['low','medium','high','critical'],true)?(string)$signal['severity']:'medium';
    $signal['provenance']=array_values(array_filter((array)($signal['provenance']??[]),fn($r)=>is_array($r)&&!empty($r['type'])&&!empty($r['id'])));
    $key=(string)($signal['key']??hash('sha256',(string)($signal['kind']??'signal').'|'.(string)($signal['object_type']??'').'|'.(string)($signal['object_id']??'').'|'.(string)($signal['title']??'')));
    $signal['key']=$key;
    if(!isset($signals[$key])||research_intelligence_organizational_severity_rank((string)$signal['severity'])>research_intelligence_organizational_severity_rank((string)$signals[$key]['severity']))$signals[$key]=$signal;
}
function research_intelligence_organizational_pattern_decision_ids(PDO $pdo,array $viewer,array $pattern): array {
    $ids=[];
    foreach((array)($pattern['members']??[]) as $member){
        $type=(string)($member['member_type']??'');$id=(string)($member['member_public_id']??'');if($id==='')continue;
        try{
            if($type==='decision')$ids[$id]=true;
            elseif($type==='action_plan'){$p=research_action_plan_detail($pdo,$viewer,$id);if($p&&!empty($p['decision_public_id']))$ids[(string)$p['decision_public_id']]=true;}
            elseif($type==='outcome'){$o=research_decision_outcome_access($pdo,$viewer,$id);if($o&&!empty($o['decision_public_id']))$ids[(string)$o['decision_public_id']]=true;}
            elseif($type==='variance'){$v=research_action_plan_variance_row($pdo,$viewer,$id);if($v&&!empty($v['action_plan_public_id'])){$p=research_action_plan_detail($pdo,$viewer,(string)$v['action_plan_public_id']);if($p&&!empty($p['decision_public_id']))$ids[(string)$p['decision_public_id']]=true;}}
        }catch(Throwable $ignored){}
    }
    return array_keys($ids);
}
function research_intelligence_organizational_portfolio_cognition(PDO $pdo,array $viewer,string $portfolioPublic,int $limit=80): array {
    if(!research_intelligence_organizational_cognition_ready($pdo))return ['ready'=>false,'summary'=>[],'signals'=>[],'analogues'=>[],'state_hash'=>''];
    $p=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$p)return ['ready'=>false,'summary'=>[],'signals'=>[],'analogues'=>[],'state_hash'=>''];
    $limit=max(10,min(200,$limit));$execution=research_intelligence_portfolio_decision_execution_rollup($pdo,$viewer,(string)$p['public_id'],250);
    $patterns=research_intelligence_portfolio_pattern_memory($pdo,$viewer,(string)$p['public_id']);$graph=research_intelligence_portfolio_strategic_graph($pdo,$viewer,(string)$p['public_id'],false,300);
    $reviews=research_intelligence_portfolio_strategic_review_summary($pdo,$viewer,(string)$p['public_id']);$briefs=research_intelligence_portfolio_strategic_briefing_summary($pdo,$viewer,(string)$p['public_id']);
    $stateHash=research_intelligence_organizational_state_hash($pdo,$viewer,(string)$p['public_id']);$signals=[];$decisionMap=[];

    foreach((array)($execution['decisions']??[]) as $d){
        $decisionMap[(string)$d['public_id']]=$d;
        if(in_array((string)$d['status'],['accepted','rejected','deferred','superseded'],true)){
            try{$detail=research_decision_detail($pdo,$viewer,(string)$d['public_id']);$rs=research_decision_reconsideration_signals($pdo,$viewer,(string)$d['public_id']);}catch(Throwable $e){$detail=null;$rs=['signals'=>[]];}
            $current=[];foreach((array)($rs['signals']??[]) as $s)if(in_array((string)($s['materiality']??''),['critical','high'],true))$current[]=$s;
            $open=false;if($detail)foreach((array)($detail['reconsiderations']??[]) as $case)if(in_array((string)($case['status']??''),['open','reviewing'],true)){$open=true;break;}
            if($current&&!$open){
                usort($current,fn($a,$b)=>research_intelligence_organizational_severity_rank((string)$b['materiality'])<=>research_intelligence_organizational_severity_rank((string)$a['materiality']));
                $top=$current[0];$prov=[['type'=>'decision','id'=>(string)$d['public_id']]];foreach(array_slice($current,0,5) as $s)if(!empty($s['source']['type'])&&!empty($s['source']['public_id']))$prov[]=['type'=>(string)$s['source']['type'],'id'=>(string)$s['source']['public_id']];
                research_intelligence_organizational_signal($signals,[
                  'kind'=>'decision_reconsideration','severity'=>(string)$top['materiality'],'object_type'=>'decision','object_id'=>(string)$d['public_id'],
                  'title'=>'Decision may warrant reconsideration · '.(string)$d['title'],
                  'summary'=>implode(' · ',array_slice(array_map(fn($s)=>(string)$s['reason'],$current),0,3)),
                  'recommended_capability'=>'research.decision.open_reconsideration','provenance'=>$prov
                ]);
            }
        }
        foreach((array)($d['action_plans']??[]) as $plan){
            $reasons=[];$severity='medium';
            if(!empty($plan['source_stale'])){$reasons[]='Source Decision changed after Action Plan provenance was pinned.';$severity='high';}
            if(!empty($plan['overdue'])){$reasons[]='Action Plan is overdue.';$severity='high';}
            if((int)($plan['variance']['high_or_critical_open']??0)>0){$reasons[]=(int)$plan['variance']['high_or_critical_open'].' high/critical execution variance(s) remain open.';$severity='critical';}
            elseif((int)($plan['variance']['material_open']??0)>0){$reasons[]=(int)$plan['variance']['material_open'].' material execution variance(s) remain open.';$severity='high';}
            if((string)$plan['status']==='completed'&&empty($plan['outcome_recorded'])){$reasons[]='Completed Action Plan has no Outcome Memory handoff.';$severity='high';}
            if($reasons)research_intelligence_organizational_signal($signals,[
              'kind'=>'execution_follow_through','severity'=>$severity,'object_type'=>'action_plan','object_id'=>(string)$plan['public_id'],
              'title'=>'Execution follow-through · '.(string)$plan['title'],'summary'=>implode(' · ',$reasons),
              'recommended_capability'=>'research.action_plan.add_task','provenance'=>[['type'=>'action_plan','id'=>(string)$plan['public_id']],['type'=>'decision','id'=>(string)$d['public_id']]]
            ]);
        }
    }

    foreach((array)($graph['attention']??[]) as $g){
        $severity=in_array((string)($g['materiality']??''),['critical','high'],true)?(string)$g['materiality']:'medium';
        research_intelligence_organizational_signal($signals,[
          'kind'=>'strategic_relationship','severity'=>$severity,'object_type'=>'strategic_edge','object_id'=>(string)$g['edge_id'],
          'title'=>'Strategic relationship needs reasoning · '.(string)$g['source_title'].' → '.(string)$g['target_title'],
          'summary'=>str_replace('_',' ',(string)$g['relation_type']).' · '.implode(' · ',array_map(fn($v)=>str_replace('_',' ',(string)$v),(array)($g['reasons']??[]))).($g['rationale']!==''?' · '.(string)$g['rationale']:''),
          'recommended_capability'=>'research.decision.open_reconsideration',
          'provenance'=>[['type'=>'strategic_edge','id'=>(string)$g['edge_id']],['type'=>(string)$g['source_type'],'id'=>(string)$g['source_public_id']],['type'=>(string)$g['target_type'],'id'=>(string)$g['target_public_id']]]
        ]);
    }

    foreach((array)($reviews['reviews']??[]) as $r){
        $review=$r['review']??null;$agg=(array)($r['review_aggregate']??[]);$reasons=[];$severity='medium';
        if($review&&($review['status']??'')==='open'&&!empty($review['due_at'])&&strtotime((string)$review['due_at'])<time()){$reasons[]='Strategic Review is overdue.';$severity='high';}
        if(($agg['consensus']??'')==='changes_requested'){$reasons[]='Strategic Review requested changes.';$severity='high';}
        if(($agg['consensus']??'')==='unresolved_objection'){$reasons[]='Strategic Review has an unresolved objection.';$severity='critical';}
        if(!empty($r['current_drift'])){$reasons[]='Current strategic state differs from the frozen review packet.';$severity=research_intelligence_organizational_severity_rank($severity)<3?'high':$severity;}
        if($reasons)research_intelligence_organizational_signal($signals,[
          'kind'=>'strategic_review_follow_through','severity'=>$severity,'object_type'=>'strategic_review','object_id'=>(string)$r['public_id'],
          'title'=>'Strategic Review follow-through · '.(string)$p['title'],'summary'=>implode(' · ',$reasons),
          'recommended_capability'=>'research.portfolio.create_strategic_review','provenance'=>[['type'=>'strategic_review','id'=>(string)$r['public_id']],['type'=>'portfolio','id'=>(string)$p['public_id']]]
        ]);
    }

    foreach((array)($briefs['briefings']??[]) as $b){
        $review=$b['team_review']??null;$agg=(array)($b['team_review_aggregate']??[]);$reasons=[];$severity='medium';
        if($review&&($review['status']??'')==='open'&&!empty($review['due_at'])&&strtotime((string)$review['due_at'])<time()){$reasons[]='Team Review is overdue.';$severity='high';}
        if(($agg['consensus']??'')==='changes_requested'){$reasons[]='Team Review requested changes.';$severity='high';}
        if(($agg['consensus']??'')==='unresolved_objection'){$reasons[]='Team Review has an unresolved objection.';$severity='critical';}
        if(!empty($b['current_drift'])){$reasons[]='Current strategic state has drifted from the frozen briefing.';$severity=research_intelligence_organizational_severity_rank($severity)<3?'high':$severity;}
        if($reasons)research_intelligence_organizational_signal($signals,[
          'kind'=>'strategic_briefing_follow_through','severity'=>$severity,'object_type'=>'strategic_briefing','object_id'=>(string)$b['public_id'],
          'title'=>'Executive Strategic Briefing follow-through · '.(string)$b['title'],'summary'=>implode(' · ',$reasons),
          'recommended_capability'=>'research.portfolio.create_strategic_briefing',
          'provenance'=>[['type'=>'strategic_briefing','id'=>(string)$b['public_id']],['type'=>'executive_briefing','id'=>(string)$b['executive_briefing_public_id']],['type'=>'portfolio','id'=>(string)$p['public_id']]]
        ]);
    }

    $analogues=[];
    foreach((array)($patterns['patterns']??[]) as $pattern){
        $ids=research_intelligence_organizational_pattern_decision_ids($pdo,$viewer,$pattern);if(count($ids)<2)continue;$decisions=[];
        foreach($ids as $id){$row=$decisionMap[$id]??null;if(!$row){try{$d=research_decision_detail($pdo,$viewer,$id);$row=$d?['public_id'=>$id,'title'=>$d['title'],'status'=>$d['status']]:null;}catch(Throwable $e){$row=null;}}if($row)$decisions[]=['public_id'=>(string)$row['public_id'],'title'=>(string)$row['title'],'status'=>(string)$row['status']];}
        if(count($decisions)<2)continue;
        $analogues[]=['pattern_id'=>(string)$pattern['public_id'],'pattern_type'=>(string)$pattern['pattern_type'],'label'=>(string)$pattern['label'],'summary'=>(string)$pattern['summary'],'decision_count'=>count($decisions),'evidence_count'=>(int)$pattern['evidence_count'],'decisions'=>$decisions,'provenance'=>array_merge([['type'=>'decision_pattern','id'=>(string)$pattern['public_id']]],array_map(fn($d)=>['type'=>'decision','id'=>(string)$d['public_id']],$decisions))];
    }
    usort($analogues,fn($a,$b)=>($b['decision_count']<=>$a['decision_count'])?:($b['evidence_count']<=>$a['evidence_count'])?:strcmp((string)$a['pattern_id'],(string)$b['pattern_id']));
    $signals=array_values($signals);usort($signals,fn($a,$b)=>research_intelligence_organizational_severity_rank((string)$b['severity'])<=>research_intelligence_organizational_severity_rank((string)$a['severity'])?:strcmp((string)$a['kind'],(string)$b['kind'])?:strcmp((string)$a['key'],(string)$b['key']));
    $summary=['signals'=>count($signals),'critical'=>0,'high'=>0,'decision_reconsideration'=>0,'execution_follow_through'=>0,'strategic_relationship'=>0,'review_follow_through'=>0,'briefing_follow_through'=>0,'analogues'=>count($analogues)];
    foreach($signals as $s){if(isset($summary[$s['severity']]))$summary[$s['severity']]++;$map=['decision_reconsideration'=>'decision_reconsideration','execution_follow_through'=>'execution_follow_through','strategic_relationship'=>'strategic_relationship','strategic_review_follow_through'=>'review_follow_through','strategic_briefing_follow_through'=>'briefing_follow_through'];if(isset($map[$s['kind']]))$summary[$map[$s['kind']]]++;}
    return ['ready'=>true,'portfolio'=>['public_id'=>(string)$p['public_id'],'title'=>(string)$p['title'],'objective'=>(string)$p['objective']],'state_hash'=>$stateHash,'summary'=>$summary,'signals'=>array_slice($signals,0,$limit),'analogues'=>array_slice($analogues,0,min(40,$limit)),'generated_at'=>date('Y-m-d H:i:s')];
}

function research_intelligence_organizational_cognition_center(PDO $pdo,array $viewer,int $limit=120): array {
    if(!research_intelligence_organizational_cognition_ready($pdo))return ['ready'=>false,'summary'=>[],'signals'=>[],'analogues'=>[]];
    $dashboard=research_intelligence_portfolio_dashboard($pdo,$viewer);$signals=[];$analogues=[];$summary=['portfolios'=>0,'signals'=>0,'critical'=>0,'high'=>0,'decision_reconsideration'=>0,'execution_follow_through'=>0,'strategic_relationship'=>0,'review_follow_through'=>0,'briefing_follow_through'=>0,'analogues'=>0];
    foreach((array)$dashboard['portfolios'] as $row){$c=research_intelligence_organizational_portfolio_cognition($pdo,$viewer,(string)$row['public_id'],80);if(!$c['ready'])continue;$summary['portfolios']++;
      foreach((array)$c['signals'] as $s)$signals[]=['portfolio_id'=>(string)$row['public_id'],'portfolio_title'=>(string)$row['title'],'portfolio_state_hash'=>(string)$c['state_hash']]+$s;
      foreach((array)$c['analogues'] as $a)$analogues[]=['portfolio_id'=>(string)$row['public_id'],'portfolio_title'=>(string)$row['title']]+$a;
    }
    usort($signals,fn($a,$b)=>research_intelligence_organizational_severity_rank((string)$b['severity'])<=>research_intelligence_organizational_severity_rank((string)$a['severity'])?:strcmp((string)$a['portfolio_title'],(string)$b['portfolio_title'])?:strcmp((string)$a['key'],(string)$b['key']));
    usort($analogues,fn($a,$b)=>($b['decision_count']<=>$a['decision_count'])?:($b['evidence_count']<=>$a['evidence_count'])?:strcmp((string)$a['portfolio_title'],(string)$b['portfolio_title']));
    foreach($signals as $s){$summary['signals']++;if(isset($summary[$s['severity']]))$summary[$s['severity']]++;$map=['decision_reconsideration'=>'decision_reconsideration','execution_follow_through'=>'execution_follow_through','strategic_relationship'=>'strategic_relationship','strategic_review_follow_through'=>'review_follow_through','strategic_briefing_follow_through'=>'briefing_follow_through'];if(isset($map[$s['kind']]))$summary[$map[$s['kind']]]++;}
    $summary['analogues']=count($analogues);
    return ['ready'=>true,'summary'=>$summary,'signals'=>array_slice($signals,0,max(10,min(250,$limit))),'analogues'=>array_slice($analogues,0,80),'generated_at'=>date('Y-m-d H:i:s')];
}

function research_intelligence_organizational_agent_context(PDO $pdo,array $viewer,string $agentPublic,int $limit=6): array {
    if(!research_intelligence_organizational_cognition_ready($pdo))return ['text'=>'','refs'=>[]];
    $agent=research_agent_access($pdo,$viewer,trim($agentPublic));if(!$agent)return ['text'=>'','refs'=>[]];$projectId=(int)($agent['project_id']??0);if($projectId<1)return ['text'=>'','refs'=>[]];
    $q=$pdo->prepare("SELECT DISTINCT rip.public_id FROM research_intelligence_portfolios rip JOIN research_intelligence_portfolio_programs ipp ON ipp.portfolio_id=rip.id JOIN research_programs rp ON rp.id=ipp.program_id WHERE rp.project_id=? AND rip.status='active' ORDER BY rip.updated_at DESC,rip.id DESC LIMIT 8");$q->execute([$projectId]);
    $lines=['[ORGANIZATIONAL STRATEGIC COGNITION]','This is deterministic, permission-checked organizational state. Use it to explain analogues, risks, reconsideration signals, and governed next steps. Never claim a Decision, Action Plan, review, briefing, publication, task, or Agent action changed unless the application confirms that write.'];$refs=[];$shown=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $portfolioPublic){
        if($shown++>=$limit)break;$c=research_intelligence_organizational_portfolio_cognition($pdo,$viewer,(string)$portfolioPublic,30);if(!$c['ready'])continue;$p=$c['portfolio'];
        $lines[]='[PORTFOLIO '.(string)$p['public_id'].'] '.(string)$p['title']."
Strategic state hash: ".(string)$c['state_hash']."
Signals: ".(int)$c['summary']['signals'].' · critical '.(int)$c['summary']['critical'].' · high '.(int)$c['summary']['high'].' · analogues '.(int)$c['summary']['analogues'];
        $refs[]=['type'=>'portfolio','id'=>(string)$p['public_id']];
        foreach(array_slice((array)$c['signals'],0,10) as $s){$extra='';if($s['object_type']==='decision'){try{$extra="
Decision state hash: ".research_decision_review_state_hash($pdo,$viewer,(string)$s['object_id']);}catch(Throwable $ignored){}}elseif($s['object_type']==='action_plan'){try{$extra="
Action Plan state hash: ".research_action_plan_cognition_state_hash($pdo,$viewer,(string)$s['object_id']);}catch(Throwable $ignored){}}
          $lines[]='['.strtoupper((string)$s['severity']).' '.strtoupper(str_replace('_',' ',(string)$s['kind'])).'] '.(string)$s['title']."
".(string)$s['summary'].$extra."
Governed proposal capability: ".(string)($s['recommended_capability']??'none');
          foreach((array)$s['provenance'] as $r)$refs[]=$r;
        }
        foreach(array_slice((array)$c['analogues'],0,6) as $a){$names=implode(' | ',array_map(fn($d)=>(string)$d['title'].' ['.(string)$d['public_id'].']',(array)$a['decisions']));$lines[]='[DECISION ANALOGUE '.(string)$a['pattern_id'].'] '.(string)$a['summary']."
Linked Decisions: ".$names;foreach((array)$a['provenance'] as $r)$refs[]=$r;}
        $lines[]='Follow-through rule for this Portfolio: propose only. For Portfolio capabilities copy the exact Portfolio strategic state hash above. Draft Decisions remain draft; Action Plans remain draft; reconsideration only opens a case; Strategic Reviews and Strategic Briefings only enter their existing human review paths. Existing lifecycle/approval gates remain authoritative.';
    }
    $seen=[];$dedup=[];foreach($refs as $r){$k=(string)($r['type']??'').'|'.(string)($r['id']??'');if($k==='|'||isset($seen[$k]))continue;$seen[$k]=true;$dedup[]=$r;}
    return ['text'=>$shown?implode("

",$lines):'','refs'=>$dedup,'agent_id'=>$agentPublic];
}

function research_intelligence_organizational_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=24): void {
    if(!research_intelligence_organizational_cognition_ready($pdo)||!function_exists('cognitive_feed_add'))return;$center=research_intelligence_organizational_cognition_center($pdo,$viewer,$limit);$shown=0;
    foreach((array)$center['signals'] as $s){if($shown++>=$limit)break;$priority=in_array((string)$s['severity'],['critical','high'],true)?'high':'medium';$actions=[cognitive_feed_action_link('Open Portfolio','/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$s['portfolio_id']))];
      $anchorProject='';try{$p=research_intelligence_portfolio_access($pdo,$viewer,(string)$s['portfolio_id']);if($p){$a=research_intelligence_portfolio_anchor_program($pdo,$viewer,$p);$anchorProject=(string)$a['project']['public_id'];}}catch(Throwable $ignored){}
      if($anchorProject!==''&&function_exists('cognitive_feed_action_agent'))$actions[]=cognitive_feed_action_agent('Ask Agent','Explain this organizational strategic signal, show the supporting Decisions/Action Plans/reviews/briefings, compare relevant prior Decision analogues, and propose only the safest governed follow-through. Do not execute or claim any state change.',[['type'=>'research','public_id'=>$anchorProject]]);
      cognitive_feed_add($items,['key'=>cognitive_feed_key('organizational_cognition',(string)$s['object_type'],(string)$s['object_id'],(string)$s['portfolio_state_hash'].'|'.(string)$s['key']),'type'=>'organizational_cognition','section'=>'needs_attention','priority'=>$priority,'score_extra'=>$s['severity']==='critical'?18:10,'created_at'=>date('Y-m-d H:i:s'),'title'=>(string)$s['title'],'body'=>(string)$s['portfolio_title'].' · '.(string)$s['summary'],'meta'=>['portfolio'=>(string)$s['portfolio_title'],'kind'=>(string)$s['kind'],'severity'=>(string)$s['severity'],'provenance'=>(array)$s['provenance']],'actions'=>$actions]);
    }
}
