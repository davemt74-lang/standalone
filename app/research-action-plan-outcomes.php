<?php
declare(strict_types=1);

function research_action_plan_outcomes_ready(PDO $pdo): bool {
    try{
        return research_action_plan_variance_ready($pdo)
          && research_decision_outcomes_ready($pdo)
          && installer_table_exists($pdo,'research_action_plan_outcome_links');
    }catch(Throwable $e){return false;}
}
function research_action_plan_outcome_link(PDO $pdo,array $viewer,string $planPublic): ?array {
    if(!research_action_plan_outcomes_ready($pdo))return null;
    $plan=research_action_plan_access($pdo,$viewer,trim($planPublic));if(!$plan)return null;
    $q=$pdo->prepare("SELECT l.*,rdo.public_id decision_outcome_public_id,reb.public_id execution_baseline_public_id
      FROM research_action_plan_outcome_links l
      JOIN research_decision_outcomes rdo ON rdo.id=l.decision_outcome_id
      JOIN research_action_plan_execution_baselines reb ON reb.id=l.execution_baseline_id
      WHERE l.action_plan_id=? LIMIT 1");
    $q->execute([(int)$plan['id']]);$row=$q->fetch();if(!$row)return null;
    $row['handoff_snapshot']=research_action_plan_json($row['handoff_snapshot_json']??null);
    $row['outcome']=research_decision_outcome_access($pdo,$viewer,(string)$row['decision_outcome_public_id']);
    return $row;
}
function research_action_plan_outcome_preview(PDO $pdo,array $viewer,string $planPublic): array {
    if(!research_action_plan_outcomes_ready($pdo))throw new RuntimeException('Action Plan Outcome Handoff requires the latest database upgrade.');
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');
    $existing=research_action_plan_outcome_link($pdo,$viewer,(string)$plan['public_id']);
    $baseline=research_action_plan_execution_baseline($pdo,$viewer,(string)$plan['public_id']);
    if(!$baseline)throw new InvalidArgumentException('Action Plan has no immutable execution baseline.');
    $milestones=research_action_plan_milestones($pdo,$viewer,(string)$plan['public_id']);
    $tasks=[];$q=$pdo->prepare("SELECT rt.public_id,rt.title,rt.status,rt.task_type,rt.priority,rt.due_at,rt.completed_at,l.link_role,m.public_id milestone_public_id
      FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id
      LEFT JOIN research_action_plan_milestones m ON m.id=l.milestone_id
      WHERE l.action_plan_id=? ORDER BY rt.position,rt.id");
    $q->execute([(int)$plan['id']]);foreach($q->fetchAll()?:[] as $row)$tasks[]=$row;
    $observations=research_action_plan_execution_observations($pdo,$viewer,(string)$plan['public_id'],500);
    $variances=research_action_plan_execution_variances($pdo,$viewer,(string)$plan['public_id'],500);
    $measureEvidence=[];foreach($observations as $o){
      if((string)$o['subject_type']!=='success_measure')continue;
      $key=(string)($o['subject_key']??'');if(!preg_match('/^success_measure:(\d+)$/',$key,$m))continue;
      $idx=(int)$m[1];$measureEvidence[$idx][]=[
        'public_id'=>(string)$o['public_id'],'observed_on'=>(string)$o['observed_on'],'assessment'=>(string)$o['assessment'],
        'summary'=>(string)$o['summary'],'actual'=>$o['actual']??null
      ];
    }ksort($measureEvidence);
    $baselineConfig=(array)($baseline['config']??[]);
    $successMeasures=[];foreach((array)($baselineConfig['success_measures']??[]) as $i=>$measure)$successMeasures[]=[
      'index'=>(int)$i,'expected'=>$measure,'observations'=>$measureEvidence[(int)$i]??[]
    ];
    $milestoneCounts=[];foreach($milestones as $m){$s=(string)$m['status'];$milestoneCounts[$s]=($milestoneCounts[$s]??0)+1;}ksort($milestoneCounts);
    $taskCounts=[];foreach($tasks as $t){$s=(string)$t['status'];$taskCounts[$s]=($taskCounts[$s]??0)+1;}ksort($taskCounts);
    $varianceCounts=['total'=>count($variances),'open'=>0,'resolved'=>0,'material'=>0,'by_type'=>[]];
    foreach($variances as $v){
      $s=(string)$v['status'];if(isset($varianceCounts[$s]))$varianceCounts[$s]++;
      if(!empty($v['material']))$varianceCounts['material']++;
      $type=(string)$v['variance_type'];$varianceCounts['by_type'][$type]=($varianceCounts['by_type'][$type]??0)+1;
    }ksort($varianceCounts['by_type']);
    $expected=(string)($baselineConfig['expected_result']??$plan['expected_result']??'');
    $actualParts=[];$actualParts[]='Action Plan '.strtolower((string)$plan['status']).(!empty($plan['completed_at'])?' on '.substr((string)$plan['completed_at'],0,10):'.');
    if($milestones)$actualParts[]='Milestones: '.implode(', ',array_map(fn($k,$v)=>$k.' '.$v,array_keys($milestoneCounts),array_values($milestoneCounts))).'.';
    if($tasks)$actualParts[]='Tasks: '.implode(', ',array_map(fn($k,$v)=>$k.' '.$v,array_keys($taskCounts),array_values($taskCounts))).'.';
    foreach($successMeasures as $sm){
      $label=is_array($sm['expected'])?(string)($sm['expected']['label']??$sm['expected']['name']??('Measure '.($sm['index']+1))):('Measure '.($sm['index']+1));
      $latest=$sm['observations']?end($sm['observations']):null;if($latest)$actualParts[]=$label.': '.strtoupper((string)$latest['assessment']).' — '.(string)$latest['summary'];
    }
    $varianceParts=[];foreach(array_slice($variances,0,8) as $v)$varianceParts[]=strtoupper((string)$v['severity']).' '.str_replace('_',' ',(string)$v['variance_type']).': '.(string)$v['summary'];
    $snapshot=[
      'action_plan'=>[
        'public_id'=>(string)$plan['public_id'],'title'=>(string)$plan['title'],'status'=>(string)$plan['status'],'revision'=>(int)$plan['current_revision'],
        'config_hash'=>(string)$plan['config_hash'],'objective'=>(string)$plan['objective'],'expected_result'=>(string)$plan['expected_result'],
        'activated_at'=>(string)($plan['activated_at']??''),'completed_at'=>(string)($plan['completed_at']??'')
      ],
      'source_decision'=>[
        'public_id'=>(string)$plan['decision_public_id'],'status'=>(string)$plan['decision_status'],'revision'=>(int)$plan['decision_current_revision'],
        'source_revision'=>(int)$plan['source_decision_revision'],'source_status'=>(string)$plan['source_decision_status'],'source_stale'=>(bool)$plan['source_stale']
      ],
      'execution_baseline'=>[
        'public_id'=>(string)$baseline['public_id'],'action_plan_revision'=>(int)$baseline['action_plan_revision'],'action_plan_config_hash'=>(string)$baseline['action_plan_config_hash'],
        'config'=>$baselineConfig
      ],
      'success_measures'=>$successMeasures,
      'milestones'=>array_map(fn($m)=>[
        'public_id'=>(string)$m['public_id'],'title'=>(string)$m['title'],'status'=>(string)$m['status'],'target_on'=>(string)($m['target_on']??''),
        'completed_at'=>(string)($m['completed_at']??''),'completion_criteria'=>(array)($m['completion_criteria']??[])
      ],$milestones),
      'tasks'=>array_map(fn($t)=>[
        'public_id'=>(string)$t['public_id'],'title'=>(string)$t['title'],'status'=>(string)$t['status'],'task_type'=>(string)$t['task_type'],
        'priority'=>(string)$t['priority'],'due_at'=>(string)($t['due_at']??''),'completed_at'=>(string)($t['completed_at']??''),
        'link_role'=>(string)$t['link_role'],'milestone_public_id'=>(string)($t['milestone_public_id']??'')
      ],$tasks),
      'observations'=>array_map(fn($o)=>[
        'public_id'=>(string)$o['public_id'],'observation_type'=>(string)$o['observation_type'],'subject_type'=>(string)$o['subject_type'],
        'subject_key'=>(string)($o['subject_key']??''),'summary'=>(string)$o['summary'],'assessment'=>(string)$o['assessment'],
        'actual'=>$o['actual']??null,'observed_on'=>(string)$o['observed_on'],'material'=>(bool)$o['material']
      ],$observations),
      'variances'=>array_map(fn($v)=>[
        'public_id'=>(string)$v['public_id'],'variance_type'=>(string)$v['variance_type'],'subject_type'=>(string)$v['subject_type'],
        'subject_key'=>(string)($v['subject_key']??''),'severity'=>(string)$v['severity'],'material'=>(bool)$v['material'],
        'summary'=>(string)$v['summary'],'impact'=>(string)($v['impact']??''),'response'=>(string)($v['response']??''),'status'=>(string)$v['status']
      ],$variances)
    ];
    return [
      'ready_for_handoff'=>(string)$plan['status']==='completed'&&$existing===null,
      'action_plan'=>$plan,'execution_baseline'=>$baseline,'existing_handoff'=>$existing,
      'state_hash'=>research_action_plan_cognition_state_hash($pdo,$viewer,(string)$plan['public_id']),
      'expected_summary'=>$expected,'actual_summary_suggestion'=>implode(' ',$actualParts),
      'variance_summary_suggestion'=>$varianceParts?implode(' | ',$varianceParts):'No recorded execution variances.',
      'milestone_counts'=>$milestoneCounts,'task_counts'=>$taskCounts,'variance_counts'=>$varianceCounts,
      'success_measures'=>$successMeasures,'snapshot'=>$snapshot
    ];
}
function research_action_plan_record_outcome_handoff(PDO $pdo,array $viewer,string $planPublic,array $input,bool $byAgent=false): array {
    if($byAgent)throw new InvalidArgumentException('Agent actions cannot record a final Action Plan outcome without explicit human governance.');
    if(!research_action_plan_outcomes_ready($pdo))throw new RuntimeException('Action Plan Outcome Handoff requires the latest database upgrade.');
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if((string)$plan['status']!=='completed')throw new InvalidArgumentException('Complete the Action Plan before recording its final Decision outcome.');
    $existing=research_action_plan_outcome_link($pdo,$viewer,(string)$plan['public_id']);if($existing)return $existing;
    if(!array_key_exists('assessment',$input))throw new InvalidArgumentException('An explicit human outcome assessment is required.');
    $assessment=(string)$input['assessment'];if(!isset(research_decision_outcome_assessments()[$assessment]))throw new InvalidArgumentException('Invalid Decision outcome assessment.');
    $actual=research_action_plan_text((string)($input['actual_summary']??''),64000);if($actual==='')throw new InvalidArgumentException('Actual outcome summary is required.');
    $providedHash=strtolower(trim((string)($input['handoff_state_hash']??'')));if(!preg_match('/^[a-f0-9]{64}$/',$providedHash))throw new InvalidArgumentException('Exact Action Plan handoff state hash is required.');
    $preview=research_action_plan_outcome_preview($pdo,$viewer,(string)$plan['public_id']);
    if(!hash_equals((string)$preview['state_hash'],$providedHash))throw new RuntimeException('Action Plan execution state changed after the outcome preview. Review current execution results before recording the outcome.');
    $decision=research_decision_access($pdo,$viewer,(string)$plan['decision_public_id']);if(!$decision)throw new RuntimeException('Source Decision not found.');
    if(!in_array((string)$decision['status'],['accepted','rejected','deferred','superseded'],true))throw new InvalidArgumentException('The source Decision must have a recorded disposition before Outcome Memory can be updated.');
    $expected=research_action_plan_text((string)($input['expected_summary']??$preview['expected_summary']),64000);
    $variance=research_action_plan_text((string)($input['variance_summary']??$preview['variance_summary_suggestion']),64000);
    $lessons=research_action_plan_text((string)($input['lessons']??''),64000);
    $follow=(string)($input['follow_up_state']??'none');if(!isset(research_decision_outcome_follow_up_states()[$follow]))throw new InvalidArgumentException('Invalid outcome follow-up state.');
    $confidence=array_key_exists('confidence',$input)&&$input['confidence']!==''?(float)$input['confidence']:null;if($confidence!==null&&($confidence<0||$confidence>1))throw new InvalidArgumentException('Outcome confidence must be between 0 and 1.');
    $observed=trim((string)($input['observed_at']??''));if($observed==='')$observed=(string)($plan['completed_at']??date('Y-m-d H:i:s'));
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
      $lock=$pdo->prepare('SELECT id,status,current_revision,config_hash FROM research_action_plans WHERE id=? FOR UPDATE');$lock->execute([(int)$plan['id']]);$locked=$lock->fetch();
      if(!$locked||(string)$locked['status']!=='completed')throw new RuntimeException('Action Plan completion state changed before outcome recording.');
      $decisionLock=$pdo->prepare('SELECT status,current_revision,config_hash FROM research_decisions WHERE id=? FOR UPDATE');$decisionLock->execute([(int)$decision['id']]);$lockedDecision=$decisionLock->fetch();
      if(!$lockedDecision)throw new RuntimeException('Source Decision became unavailable before outcome recording.');
      $again=research_action_plan_outcome_link($pdo,$viewer,(string)$plan['public_id']);if($again){if($owns)$pdo->commit();return $again;}
      $freshPreview=research_action_plan_outcome_preview($pdo,$viewer,(string)$plan['public_id']);
      if(!hash_equals((string)$freshPreview['state_hash'],$providedHash))throw new RuntimeException('Action Plan execution state changed before outcome recording. Review current execution results and try again.');
      $outcome=research_decision_record_outcome($pdo,$viewer,(string)$decision['public_id'],[
        'assessment'=>$assessment,'expected_summary'=>$expected,'actual_summary'=>$actual,'variance_summary'=>$variance,'lessons'=>$lessons,
        'confidence'=>$confidence,'follow_up_state'=>$follow,'observed_at'=>$observed,
        'idempotency_key'=>'action_plan_outcome_handoff:'.(string)$plan['public_id'],
        'refs'=>[['type'=>'action_plan','public_id'=>(string)$plan['public_id'],'role'=>'source']]
      ],false);
      if(!$outcome)throw new RuntimeException('Decision Outcome Memory could not be recorded.');
      $snapshot=(array)$freshPreview['snapshot'];$snapshot['human_outcome']=[
        'assessment'=>$assessment,'expected_summary'=>$expected,'actual_summary'=>$actual,'variance_summary'=>$variance,
        'lessons'=>$lessons,'confidence'=>$confidence,'follow_up_state'=>$follow,'observed_at'=>$observed
      ];
      $public=ulid_like();$baseline=(array)$freshPreview['execution_baseline'];
      $pdo->prepare("INSERT INTO research_action_plan_outcome_links(public_id,action_plan_id,decision_outcome_id,execution_baseline_id,action_plan_revision,action_plan_config_hash,handoff_state_hash,handoff_snapshot_json,recorded_by_user_id)
        VALUES(?,?,?,?,?,?,?,?,?)")->execute([
          $public,(int)$plan['id'],(int)$outcome['id'],(int)$baseline['id'],(int)$plan['current_revision'],(string)$plan['config_hash'],$providedHash,
          json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),(int)$viewer['id']
      ]);
      research_action_plan_event($pdo,$plan,'action_plan_outcome_handoff_recorded','user',(int)$viewer['id'],[
        'outcome_handoff_id'=>$public,'decision_outcome_id'=>(string)$outcome['public_id'],'assessment'=>$assessment,'handoff_state_hash'=>$providedHash
      ]);
      research_decision_event($pdo,$decision,'decision_action_plan_outcome_handoff','user',(int)$viewer['id'],[
        'action_plan_id'=>(string)$plan['public_id'],'decision_outcome_id'=>(string)$outcome['public_id'],'assessment'=>$assessment,'handoff_state_hash'=>$providedHash
      ]);
      if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $link=research_action_plan_outcome_link($pdo,$viewer,(string)$plan['public_id']);if(!$link)throw new RuntimeException('Action Plan outcome handoff could not be loaded.');return $link;
}
