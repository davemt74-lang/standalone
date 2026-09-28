<?php
declare(strict_types=1);

/**
 * Phase 73 Section 3 — Cross-Decision Learning & Pattern Memory.
 *
 * Deterministic only: exact normalized facts repeated across at least two native
 * Portfolio Decisions. Pattern memory never mutates Decision or execution state.
 */

function research_intelligence_pattern_memory_ready(PDO $pdo): bool {
    try{
        foreach(['research_intelligence_decision_pattern_runs','research_intelligence_decision_patterns','research_intelligence_decision_pattern_members'] as $table)
            if(!installer_table_exists($pdo,$table))return false;
        return research_intelligence_portfolio_execution_rollups_ready($pdo);
    }catch(Throwable $e){return false;}
}
function research_intelligence_pattern_canonical(mixed $value): mixed {
    if(is_array($value)){
        if(array_is_list($value))return array_map('research_intelligence_pattern_canonical',$value);
        ksort($value);$out=[];foreach($value as $k=>$v)$out[(string)$k]=research_intelligence_pattern_canonical($v);return $out;
    }
    if(is_string($value))return trim(preg_replace('/\s+/u',' ',$value)??$value);
    return $value;
}
function research_intelligence_pattern_text(mixed $value): array {
    $display='';
    if(is_scalar($value))$display=trim((string)$value);
    elseif(is_array($value)){
        foreach(['label','name','title','risk','assumption','lesson','description','text','summary'] as $key)if(isset($value[$key])&&is_scalar($value[$key])&&trim((string)$value[$key])!==''){$display=trim((string)$value[$key]);break;}
        if($display==='')$display=json_encode(research_intelligence_pattern_canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)?:'';
    }
    $display=mb_substr(trim(preg_replace('/\s+/u',' ',$display)??$display),0,4000);
    $normalized=mb_strtolower($display);$normalized=trim(preg_replace('/\s+/u',' ',$normalized)??$normalized);
    return ['display'=>$display,'normalized'=>$normalized];
}
function research_intelligence_pattern_member(string $type,string $public,string $role,string $decisionPublic,array $snapshot=[]): array {
    return ['member_type'=>$type,'member_public_id'=>$public,'member_role'=>$role,'decision_public_id'=>$decisionPublic,'snapshot'=>$snapshot];
}
function research_intelligence_pattern_add(array &$groups,string $type,string $normalized,string $label,array $member): void {
    if($normalized==='')return;$key=$type.'|'.$normalized;
    if(!isset($groups[$key]))$groups[$key]=['pattern_type'=>$type,'normalized_key'=>$normalized,'label'=>mb_substr($label,0,255),'members'=>[]];
    $memberKey=$member['member_type'].'|'.$member['member_public_id'].'|'.$member['member_role'];
    $groups[$key]['members'][$memberKey]=$member;
}
function research_intelligence_pattern_summary_text(string $type,string $label,int $decisionCount,int $evidenceCount): string {
    return match($type){
      'repeated_assumption'=>"Assumption repeated across {$decisionCount} Decisions: {$label}",
      'repeated_plan_risk'=>"Action Plan risk repeated across {$decisionCount} Decisions: {$label}",
      'recurring_variance_type'=>"Execution variance type recurred across {$decisionCount} Decisions: {$label}",
      'recurring_outcome_assessment'=>"Outcome assessment recurred across {$decisionCount} Decisions: {$label}",
      'expected_actual_variance'=>"Expected-versus-actual variance was explicitly recorded across {$decisionCount} Decisions ({$evidenceCount} outcomes).",
      'repeated_lesson'=>"Outcome lesson repeated across {$decisionCount} Decisions: {$label}",
      default=>"Cross-Decision pattern across {$decisionCount} Decisions."
    };
}
function research_intelligence_portfolio_pattern_candidates(PDO $pdo,array $viewer,array $portfolio): array {
    $groups=[];$source=[];
    foreach(research_intelligence_portfolio_decision_rows($pdo,$viewer,$portfolio,250) as $row){
        if(($row['record_kind']??'')!=='native'||empty($row['decision_public_id']))continue;
        $decision=research_decision_detail($pdo,$viewer,(string)$row['decision_public_id']);if(!$decision)continue;
        $decisionPublic=(string)$decision['public_id'];
        $source['decisions'][$decisionPublic]=['config_hash'=>(string)$decision['config_hash'],'revision'=>(int)$decision['current_revision'],'status'=>(string)$decision['status']];
        foreach((array)$decision['assumptions'] as $assumption){
            $x=research_intelligence_pattern_text($assumption);if($x['normalized']==='')continue;
            research_intelligence_pattern_add($groups,'repeated_assumption',$x['normalized'],$x['display'],
              research_intelligence_pattern_member('decision',$decisionPublic,'assumption',$decisionPublic,['assumption'=>$assumption]));
        }
        foreach((array)$decision['outcomes'] as $outcome){
            $outcomePublic=(string)$outcome['public_id'];$assessment=(string)$outcome['assessment'];
            $source['outcomes'][$outcomePublic]=['decision'=>$decisionPublic,'assessment'=>$assessment,'revision'=>(int)($outcome['current_revision']??($outcome['versions'][0]['revision_number']??1)),'updated_at'=>(string)($outcome['updated_at']??'')];
            if(in_array($assessment,['success','partial','failure','mixed'],true)){
                $label=ucwords(str_replace('_',' ',$assessment));
                research_intelligence_pattern_add($groups,'recurring_outcome_assessment',$assessment,$label,
                  research_intelligence_pattern_member('outcome',$outcomePublic,'assessment',$decisionPublic,['assessment'=>$assessment,'observed_at'=>(string)$outcome['observed_at']]));
            }
            $variance=trim((string)($outcome['variance_summary']??''));
            if($variance!=='')research_intelligence_pattern_add($groups,'expected_actual_variance','explicit_variance','Expected vs actual variance',
              research_intelligence_pattern_member('outcome',$outcomePublic,'variance',$decisionPublic,['assessment'=>$assessment,'variance_summary'=>$variance]));
            $lesson=research_intelligence_pattern_text((string)($outcome['lessons']??''));
            if($lesson['normalized']!=='')research_intelligence_pattern_add($groups,'repeated_lesson',$lesson['normalized'],$lesson['display'],
              research_intelligence_pattern_member('outcome',$outcomePublic,'lesson',$decisionPublic,['lesson'=>(string)$outcome['lessons'],'assessment'=>$assessment]));
        }
        foreach(research_action_plan_list($pdo,$viewer,null,$decisionPublic,100) as $plan){
            $planPublic=(string)$plan['public_id'];
            $source['plans'][$planPublic]=['decision'=>$decisionPublic,'config_hash'=>(string)$plan['config_hash'],'revision'=>(int)$plan['current_revision'],'status'=>(string)$plan['status']];
            foreach((array)$plan['risks'] as $risk){
                $x=research_intelligence_pattern_text($risk);if($x['normalized']==='')continue;
                research_intelligence_pattern_add($groups,'repeated_plan_risk',$x['normalized'],$x['display'],
                  research_intelligence_pattern_member('action_plan',$planPublic,'risk',$decisionPublic,['risk'=>$risk]));
            }
            foreach(research_action_plan_execution_variances($pdo,$viewer,$planPublic,500) as $variance){
                $variancePublic=(string)$variance['public_id'];$type=(string)$variance['variance_type'];
                $source['variances'][$variancePublic]=['plan'=>$planPublic,'decision'=>$decisionPublic,'type'=>$type,'severity'=>(string)$variance['severity'],'material'=>(bool)$variance['material'],'status'=>(string)$variance['status'],'updated_at'=>(string)$variance['updated_at']];
                $label=ucwords(str_replace('_',' ',$type));
                research_intelligence_pattern_add($groups,'recurring_variance_type',$type,$label,
                  research_intelligence_pattern_member('variance',$variancePublic,'variance_type',$decisionPublic,['variance_type'=>$type,'severity'=>(string)$variance['severity'],'material'=>(bool)$variance['material'],'status'=>(string)$variance['status']]));
            }
        }
    }
    $patterns=[];
    foreach($groups as $g){
        $members=array_values($g['members']);$decisions=[];$plans=[];$outcomes=[];
        foreach($members as $m){$decisions[$m['decision_public_id']]=true;if($m['member_type']==='action_plan')$plans[$m['member_public_id']]=true;if($m['member_type']==='outcome')$outcomes[$m['member_public_id']]=true;}
        if(count($decisions)<2)continue;
        usort($members,fn($a,$b)=>strcmp($a['member_type'].'|'.$a['member_public_id'].'|'.$a['member_role'],$b['member_type'].'|'.$b['member_public_id'].'|'.$b['member_role']));
        $fingerprint=hash('sha256',$g['pattern_type'].'|'.$g['normalized_key']);
        $patterns[]=[
          'pattern_type'=>$g['pattern_type'],'normalized_key'=>$g['normalized_key'],'normalized_key_hash'=>hash('sha256',$g['normalized_key']),
          'fingerprint'=>$fingerprint,'label'=>$g['label'],'summary'=>research_intelligence_pattern_summary_text($g['pattern_type'],$g['label'],count($decisions),count($members)),
          'evidence_count'=>count($members),'decision_count'=>count($decisions),'action_plan_count'=>count($plans),'outcome_count'=>count($outcomes),'members'=>$members
        ];
    }
    usort($patterns,fn($a,$b)=>strcmp($a['pattern_type'].'|'.$a['fingerprint'],$b['pattern_type'].'|'.$b['fingerprint']));
    ksort($source);foreach($source as &$bucket)if(is_array($bucket))ksort($bucket);unset($bucket);
    return ['patterns'=>$patterns,'source'=>$source];
}
function research_intelligence_portfolio_pattern_members(PDO $pdo,int $patternId): array {
    $q=$pdo->prepare('SELECT member_type,member_public_id,member_role,snapshot_json,created_at FROM research_intelligence_decision_pattern_members WHERE pattern_id=? ORDER BY member_type,member_public_id,member_role');
    $q->execute([$patternId]);$rows=$q->fetchAll()?:[];foreach($rows as &$row){$x=json_decode((string)($row['snapshot_json']??''),true);$row['snapshot']=is_array($x)?$x:[];unset($row['snapshot_json']);}unset($row);return $rows;
}
function research_intelligence_portfolio_pattern_list(PDO $pdo,array $viewer,string $portfolioPublic,bool $activeOnly=true,int $limit=100): array {
    if(!research_intelligence_pattern_memory_ready($pdo))return [];$portfolio=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$portfolio)return [];
    $limit=max(1,min(250,$limit));$where=$activeOnly?' AND active=1':'';
    $q=$pdo->prepare("SELECT * FROM research_intelligence_decision_patterns WHERE portfolio_id=?{$where} ORDER BY active DESC,decision_count DESC,evidence_count DESC,last_seen_at DESC,id DESC LIMIT ".$limit);
    $q->execute([(int)$portfolio['id']]);$rows=$q->fetchAll()?:[];foreach($rows as &$row){$row['members']=research_intelligence_portfolio_pattern_members($pdo,(int)$row['id']);}unset($row);return $rows;
}
function research_intelligence_portfolio_pattern_summary(PDO $pdo,array $viewer,string $portfolioPublic): array {
    $rows=research_intelligence_portfolio_pattern_list($pdo,$viewer,$portfolioPublic,true,250);
    $summary=['active_patterns'=>count($rows),'repeated_assumptions'=>0,'repeated_plan_risks'=>0,'recurring_variance_types'=>0,'recurring_outcome_assessments'=>0,'expected_actual_variance'=>0,'repeated_lessons'=>0];
    $map=['repeated_assumption'=>'repeated_assumptions','repeated_plan_risk'=>'repeated_plan_risks','recurring_variance_type'=>'recurring_variance_types','recurring_outcome_assessment'=>'recurring_outcome_assessments','expected_actual_variance'=>'expected_actual_variance','repeated_lesson'=>'repeated_lessons'];
    foreach($rows as $row)if(isset($map[$row['pattern_type']]))$summary[$map[$row['pattern_type']]]++;
    return $summary;
}
function research_intelligence_portfolio_pattern_refresh(PDO $pdo,array $viewer,string $portfolioPublic,string $trigger='manual'): array {
    if(!research_intelligence_pattern_memory_ready($pdo))throw new RuntimeException('Cross-Decision Pattern Memory requires the latest database upgrade.');
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$portfolio||!research_intelligence_portfolio_can_write($portfolio))throw new RuntimeException('Portfolio pattern refresh access is unavailable.');
    if(!in_array($trigger,['manual','cycle','test'],true))$trigger='manual';
    $built=research_intelligence_portfolio_pattern_candidates($pdo,$viewer,$portfolio);$patterns=$built['patterns'];
    $snapshot=[];foreach($patterns as $p)$snapshot[]=[
      'pattern_type'=>$p['pattern_type'],'fingerprint'=>$p['fingerprint'],'normalized_key_hash'=>$p['normalized_key_hash'],'label'=>$p['label'],
      'evidence_count'=>$p['evidence_count'],'decision_count'=>$p['decision_count'],'action_plan_count'=>$p['action_plan_count'],'outcome_count'=>$p['outcome_count'],
      'members'=>array_map(fn($m)=>[$m['member_type'],$m['member_public_id'],$m['member_role'],$m['decision_public_id']],$p['members'])
    ];
    $stateHash=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM research_intelligence_decision_pattern_runs WHERE portfolio_id=? AND state_hash=? LIMIT 1');$q->execute([(int)$portfolio['id'],$stateHash]);$run=$q->fetch();$reused=(bool)$run;
        if(!$run){
            $public=ulid_like();$pdo->prepare('INSERT INTO research_intelligence_decision_pattern_runs(public_id,portfolio_id,state_hash,pattern_count,triggered_by,created_by_user_id,snapshot_json) VALUES(?,?,?,?,?,?,?)')
              ->execute([$public,(int)$portfolio['id'],$stateHash,count($patterns),$trigger,(int)$viewer['id'],json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)]);
            $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM research_intelligence_decision_pattern_runs WHERE id=?');$q->execute([$id]);$run=$q->fetch();
        }
        $pdo->prepare('UPDATE research_intelligence_decision_patterns SET active=0,updated_at=NOW() WHERE portfolio_id=?')->execute([(int)$portfolio['id']]);
        foreach($patterns as $p){
            $q=$pdo->prepare('SELECT id,public_id FROM research_intelligence_decision_patterns WHERE portfolio_id=? AND fingerprint=? LIMIT 1');$q->execute([(int)$portfolio['id'],$p['fingerprint']]);$existing=$q->fetch();
            if($existing){$patternId=(int)$existing['id'];$pdo->prepare('UPDATE research_intelligence_decision_patterns SET pattern_type=?,normalized_key_hash=?,label=?,summary=?,evidence_count=?,decision_count=?,action_plan_count=?,outcome_count=?,last_seen_at=NOW(),active=1,last_run_id=?,updated_at=NOW() WHERE id=?')
              ->execute([$p['pattern_type'],$p['normalized_key_hash'],$p['label'],$p['summary'],$p['evidence_count'],$p['decision_count'],$p['action_plan_count'],$p['outcome_count'],(int)$run['id'],$patternId]);}
            else{$public=ulid_like();$pdo->prepare('INSERT INTO research_intelligence_decision_patterns(public_id,portfolio_id,fingerprint,pattern_type,normalized_key_hash,label,summary,evidence_count,decision_count,action_plan_count,outcome_count,last_run_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([$public,(int)$portfolio['id'],$p['fingerprint'],$p['pattern_type'],$p['normalized_key_hash'],$p['label'],$p['summary'],$p['evidence_count'],$p['decision_count'],$p['action_plan_count'],$p['outcome_count'],(int)$run['id']]);$patternId=(int)$pdo->lastInsertId();}
            $pdo->prepare('DELETE FROM research_intelligence_decision_pattern_members WHERE pattern_id=?')->execute([$patternId]);
            $ins=$pdo->prepare('INSERT INTO research_intelligence_decision_pattern_members(pattern_id,member_type,member_public_id,member_role,snapshot_json) VALUES(?,?,?,?,?)');
            foreach($p['members'] as $m)$ins->execute([$patternId,$m['member_type'],$m['member_public_id'],$m['member_role'],json_encode($m['snapshot'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)]);
        }
        research_intelligence_portfolio_event($pdo,(int)$portfolio['id'],'decision_pattern_memory_refreshed',$trigger==='cycle'?'system':'user',$trigger==='cycle'?null:(int)$viewer['id'],['run_id'=>(string)$run['public_id'],'state_hash'=>$stateHash,'pattern_count'=>count($patterns),'reused_run'=>$reused]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['run'=>$run,'reused_run'=>$reused,'state_hash'=>$stateHash,'summary'=>research_intelligence_portfolio_pattern_summary($pdo,$viewer,$portfolioPublic),'patterns'=>research_intelligence_portfolio_pattern_list($pdo,$viewer,$portfolioPublic,true,100)];
}
function research_intelligence_portfolio_pattern_memory(PDO $pdo,array $viewer,string $portfolioPublic): array {
    if(!research_intelligence_pattern_memory_ready($pdo))return ['ready'=>false,'summary'=>[],'patterns'=>[],'latest_run'=>null];
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,trim($portfolioPublic));if(!$portfolio)return ['ready'=>false,'summary'=>[],'patterns'=>[],'latest_run'=>null];
    $q=$pdo->prepare('SELECT public_id,state_hash,pattern_count,triggered_by,created_at FROM research_intelligence_decision_pattern_runs WHERE portfolio_id=? ORDER BY id DESC LIMIT 1');$q->execute([(int)$portfolio['id']]);$run=$q->fetch()?:null;
    return ['ready'=>true,'summary'=>research_intelligence_portfolio_pattern_summary($pdo,$viewer,$portfolioPublic),'patterns'=>research_intelligence_portfolio_pattern_list($pdo,$viewer,$portfolioPublic,true,100),'latest_run'=>$run];
}
