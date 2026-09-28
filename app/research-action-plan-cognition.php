<?php
declare(strict_types=1);

function research_action_plan_cognition_ready(PDO $pdo): bool {
    try{
        return research_action_plan_variance_ready($pdo)
          && research_action_plan_follow_through_ready($pdo)
          && research_decisions_ready($pdo);
    }catch(Throwable $e){return false;}
}
function research_action_plan_cognition_state_hash(PDO $pdo,array $viewer,string $planPublic): string {
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');
    $state=[
      'plan'=>[
        'public_id'=>(string)$plan['public_id'],'revision'=>(int)$plan['current_revision'],'config_hash'=>(string)$plan['config_hash'],
        'status'=>(string)$plan['status'],'updated_at'=>(string)$plan['updated_at'],
        'source_stale'=>(bool)$plan['source_stale'],'decision_status'=>(string)$plan['decision_status'],
        'decision_revision'=>(int)$plan['decision_current_revision'],'decision_hash'=>(string)$plan['decision_current_config_hash']
      ],
      'milestones'=>[],'tasks'=>[],'programs'=>[],'variances'=>[],'observations'=>[]
    ];
    $q=$pdo->prepare("SELECT public_id,status,target_on,completed_at,updated_at FROM research_action_plan_milestones WHERE action_plan_id=? ORDER BY id");$q->execute([(int)$plan['id']]);
    foreach($q->fetchAll()?:[] as $row)$state['milestones'][]=$row;
    $q=$pdo->prepare("SELECT rt.public_id,rt.status,rt.due_at,rt.updated_at,l.link_role,m.public_id milestone_public_id
      FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id LEFT JOIN research_action_plan_milestones m ON m.id=l.milestone_id
      WHERE l.action_plan_id=? ORDER BY rt.id");$q->execute([(int)$plan['id']]);
    foreach($q->fetchAll()?:[] as $row)$state['tasks'][]=$row;
    if(research_action_plan_follow_through_ready($pdo)){
      $q=$pdo->prepare("SELECT rp.public_id,rp.status,rp.updated_at,l.program_role,l.sync_with_action_plan FROM research_action_plan_program_links l JOIN research_programs rp ON rp.id=l.program_id WHERE l.action_plan_id=? ORDER BY rp.id");$q->execute([(int)$plan['id']]);
      foreach($q->fetchAll()?:[] as $row)$state['programs'][]=$row;
    }
    if(research_action_plan_variance_ready($pdo)){
      $q=$pdo->prepare("SELECT public_id,variance_type,severity,material,status,updated_at FROM research_action_plan_variances WHERE action_plan_id=? ORDER BY id");$q->execute([(int)$plan['id']]);
      foreach($q->fetchAll()?:[] as $row)$state['variances'][]=$row;
      $q=$pdo->prepare("SELECT public_id,observation_type,subject_type,subject_key,assessment,material,observed_on,created_at FROM research_action_plan_execution_observations WHERE action_plan_id=? ORDER BY id");$q->execute([(int)$plan['id']]);
      foreach($q->fetchAll()?:[] as $row)$state['observations'][]=$row;
    }
    return hash('sha256',json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
}
function research_action_plan_cognition_assert_state(PDO $pdo,array $viewer,string $planPublic,string $expectedHash): array {
    $expectedHash=trim($expectedHash);if($expectedHash==='')throw new InvalidArgumentException('Action Plan state hash is required.');
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');
    $current=research_action_plan_cognition_state_hash($pdo,$viewer,(string)$plan['public_id']);
    if(!hash_equals($expectedHash,$current))throw new AgentActionStale('The Action Plan changed after this proposal. Ask the Agent to review current execution state and propose the action again.');
    return $plan;
}
function research_action_plan_cognition_snapshot(PDO $pdo,array $viewer,string $planPublic): array {
    if(!research_action_plan_cognition_ready($pdo))throw new RuntimeException('Action Plan cognition requires the latest application state.');
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');
    $baseline=research_action_plan_execution_baseline($pdo,$viewer,(string)$plan['public_id']);
    $milestones=research_action_plan_milestones($pdo,$viewer,(string)$plan['public_id']);
    $programs=research_action_plan_programs($pdo,$viewer,(string)$plan['public_id']);
    $variances=research_action_plan_execution_variances($pdo,$viewer,(string)$plan['public_id'],100);
    $observations=research_action_plan_execution_observations($pdo,$viewer,(string)$plan['public_id'],20);
    $openVariances=array_values(array_filter($variances,fn($v)=>(string)$v['status']==='open'));
    $taskCounts=[];$taskRows=[];
    $q=$pdo->prepare("SELECT rt.public_id,rt.title,rt.status,rt.priority,rt.due_at,rt.blocking_reason,l.link_role,m.public_id milestone_public_id
      FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id LEFT JOIN research_action_plan_milestones m ON m.id=l.milestone_id
      WHERE l.action_plan_id=? ORDER BY rt.position,rt.id");$q->execute([(int)$plan['id']]);
    foreach($q->fetchAll()?:[] as $row){$taskRows[]=$row;$st=(string)$row['status'];$taskCounts[$st]=($taskCounts[$st]??0)+1;}ksort($taskCounts);
    $milestoneCounts=[];$blockedMilestones=[];$overdueMilestones=[];$today=gmdate('Y-m-d');
    foreach($milestones as $m){$st=(string)$m['status'];$milestoneCounts[$st]=($milestoneCounts[$st]??0)+1;if((string)($m['execution_state']??'')==='blocked')$blockedMilestones[]=$m;if(!in_array($st,['completed','cancelled'],true)&&!empty($m['target_on'])&&(string)$m['target_on']<$today)$overdueMilestones[]=$m;}ksort($milestoneCounts);
    $overdueTasks=[];foreach($taskRows as $t){$due=substr((string)($t['due_at']??''),0,10);if($due!==''&&$due<$today&&!in_array((string)$t['status'],['complete','done','archived'],true))$overdueTasks[]=$t;}
    $planOverdue=!in_array((string)$plan['status'],['completed','cancelled','archived'],true)&&!empty($plan['due_on'])&&(string)$plan['due_on']<$today;
    $decisionReview=[];$attention=[];
    if(!empty($plan['source_stale']))$decisionReview[]='The source Decision changed after the Action Plan provenance was pinned.';
    foreach($openVariances as $v){
      $type=(string)$v['variance_type'];$severity=(string)$v['severity'];$material=!empty($v['material']);
      if($material&&in_array($type,['assumption_changed','new_evidence','risk_realized'],true)&&in_array($severity,['high','critical'],true))$decisionReview[]=(string)$v['summary'];
      if($material||in_array($severity,['high','critical'],true))$attention[]=(string)$v['summary'];
    }
    if($planOverdue)$attention[]='The Action Plan is past its due date.';
    if($blockedMilestones)$attention[]=count($blockedMilestones).' milestone'.(count($blockedMilestones)===1?' is':'s are').' blocked.';
    if($overdueMilestones)$attention[]=count($overdueMilestones).' milestone'.(count($overdueMilestones)===1?' is':'s are').' overdue.';
    if($overdueTasks)$attention[]=count($overdueTasks).' linked Research Task'.(count($overdueTasks)===1?' is':'s are').' overdue.';
    $decisionReview=array_values(array_unique(array_filter($decisionReview)));$attention=array_values(array_unique(array_filter($attention)));
    $status=(string)$plan['status'];
    if(in_array($status,['completed','cancelled','archived'],true))$strategic='closed';
    elseif($decisionReview)$strategic='decision_review';
    elseif($attention||$status==='paused')$strategic='needs_attention';
    else $strategic='continue_execution';
    return [
      'action_plan'=>$plan,'state_hash'=>research_action_plan_cognition_state_hash($pdo,$viewer,(string)$plan['public_id']),
      'strategic_state'=>$strategic,'decision_review_reasons'=>$decisionReview,'attention_reasons'=>$attention,
      'baseline'=>$baseline,'milestones'=>$milestones,'milestone_counts'=>$milestoneCounts,'blocked_milestones'=>$blockedMilestones,'overdue_milestones'=>$overdueMilestones,
      'tasks'=>$taskRows,'task_counts'=>$taskCounts,'overdue_tasks'=>$overdueTasks,'programs'=>$programs,
      'open_variances'=>$openVariances,'recent_observations'=>array_slice($observations,0,8),'plan_overdue'=>$planOverdue
    ];
}
function research_action_plan_cognition_label(string $state): string {
    return match($state){'decision_review'=>'MAY AFFECT UNDERLYING DECISION','needs_attention'=>'NEEDS ATTENTION','closed'=>'CLOSED',default=>'CONTINUE EXECUTION'};
}
function research_action_plan_agent_context(PDO $pdo,array $viewer,string $agentPublic,int $limit=6): array {
    if(!research_action_plan_cognition_ready($pdo))return ['text'=>'','refs'=>[]];
    $plans=research_action_plan_list($pdo,$viewer,$agentPublic,null,100);if(!$plans)return ['text'=>'','refs'=>[]];$limit=max(1,min(12,$limit));
    $rank=['decision_review'=>0,'needs_attention'=>1,'continue_execution'=>2,'closed'=>3];$snapshots=[];
    foreach($plans as $plan){if((string)$plan['status']==='archived')continue;try{$snapshots[]=research_action_plan_cognition_snapshot($pdo,$viewer,(string)$plan['public_id']);}catch(Throwable $ignored){}}
    usort($snapshots,fn($a,$b)=>(($rank[(string)$a['strategic_state']]??9)<=>($rank[(string)$b['strategic_state']]??9))?:strcmp((string)$b['action_plan']['updated_at'],(string)$a['action_plan']['updated_at']));
    $lines=["[ACTION PLAN STRATEGIC MEMORY]","Action Plan, Decision, milestone, task, Program, observation, and variance state below is durable application state. Explain expected-vs-actual lineage and propose governed follow-through, but never claim execution state changed unless the application confirms it."];
    $refs=[];$shown=0;
    foreach($snapshots as $s){
      if($shown++>=$limit)break;$p=$s['action_plan'];$state=(string)$s['strategic_state'];$line='[ACTION PLAN '.(string)$p['public_id'].'] '.(string)$p['title'].' · '.research_action_plan_cognition_label($state).' · status '.(string)$p['status'].' · revision '.(int)$p['current_revision'];
      $line.="
State hash: ".(string)$s['state_hash'];
      $line.="
Source Decision: ".(string)$p['decision_public_id'].' · pinned '.(string)$p['source_decision_status'].' rev '.(int)$p['source_decision_revision'].' · current '.(string)$p['decision_status'].' rev '.(int)$p['decision_current_revision'].' · stale '.(!empty($p['source_stale'])?'yes':'no');
      $line.="
Objective: ".mb_substr((string)$p['objective'],0,900);
      $line.="
Expected result: ".mb_substr((string)$p['expected_result'],0,900);
      if($s['decision_review_reasons'])$line.="
Decision-review reasons: ".implode(' | ',array_slice($s['decision_review_reasons'],0,4));
      if($s['attention_reasons'])$line.="
Attention reasons: ".implode(' | ',array_slice($s['attention_reasons'],0,5));
      $mc=[];foreach($s['milestone_counts'] as $k=>$v)$mc[]=$k.' '.$v;$tc=[];foreach($s['task_counts'] as $k=>$v)$tc[]=$k.' '.$v;
      $line.="
Milestones: ".($mc?implode(', ',$mc):'none')." · Tasks: ".($tc?implode(', ',$tc):'none');
      if($s['open_variances']){
        $vs=[];foreach(array_slice($s['open_variances'],0,5) as $v)$vs[]=strtoupper((string)$v['severity']).' '.str_replace('_',' ',(string)$v['variance_type']).': '.mb_substr((string)$v['summary'],0,360);
        $line.="
Open variances: ".implode(' | ',$vs);
      }
      if($s['recent_observations']){
        $os=[];foreach(array_slice($s['recent_observations'],0,3) as $o)$os[]=(string)$o['observed_on'].' '.str_replace('_',' ',(string)$o['observation_type']).' '.strtoupper((string)$o['assessment']).': '.mb_substr((string)$o['summary'],0,300);
        $line.="
Recent execution evidence: ".implode(' | ',$os);
      }
      if($s['programs']){$ps=[];foreach(array_slice($s['programs'],0,4) as $pr)$ps[]=(string)$pr['action_plan_program_role'].' '.(string)$pr['status'];$line.="
Follow-through Programs: ".implode(', ',$ps);}
      $line.="
Governance: analyze and propose only. Activation/completion/cancellation, variance resolution, and Decision status changes remain human-governed. For an Action Plan proposal, copy the exact State hash above into action_plan_state_hash.";
      $lines[]=$line;$refs[]=['type'=>'action_plan','id'=>(string)$p['public_id']];$refs[]=['type'=>'decision','id'=>(string)$p['decision_public_id']];$refs[]=['type'=>'research_project','id'=>(string)$p['project_public_id']];
      foreach(array_slice($s['open_variances'],0,8) as $v)$refs[]=['type'=>'action_plan_variance','id'=>(string)$v['public_id']];
    }
    $seen=[];$dedup=[];foreach($refs as $ref){$k=$ref['type'].'|'.$ref['id'];if(isset($seen[$k]))continue;$seen[$k]=true;$dedup[]=$ref;}
    return ['text'=>$shown?implode("

",$lines):'','refs'=>$dedup,'agent_id'=>$agentPublic];
}
function research_action_plan_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=20): void {
    if(!research_action_plan_cognition_ready($pdo)||!function_exists('cognitive_feed_projects'))return;$limit=max(1,min(50,$limit));$shown=0;
    foreach(cognitive_feed_projects($pdo,$viewer,8) as $project){
      if($shown>=$limit)break;$q=$pdo->prepare("SELECT rap.public_id,ra.public_id agent_public_id FROM research_action_plans rap JOIN research_agents ra ON ra.id=rap.research_agent_id WHERE rap.project_id=? AND rap.status IN ('active','paused','proposed') ORDER BY rap.updated_at DESC,rap.id DESC LIMIT 30");$q->execute([(int)$project['id']]);
      foreach($q->fetchAll()?:[] as $row){
        if($shown>=$limit)break;try{$s=research_action_plan_cognition_snapshot($pdo,$viewer,(string)$row['public_id']);}catch(Throwable $ignored){continue;}
        $state=(string)$s['strategic_state'];if(!in_array($state,['decision_review','needs_attention'],true))continue;$p=$s['action_plan'];
        $priority=$state==='decision_review'?'critical':(!empty($s['open_variances'])?'high':'medium');
        $reasons=$state==='decision_review'?$s['decision_review_reasons']:$s['attention_reasons'];$body=implode(' · ',array_slice($reasons,0,3));if($body==='')$body='Execution state needs review.';
        $actions=[cognitive_feed_action_link('Open Research','/research-project.php?id='.rawurlencode((string)$project['public_id']))];
        if(function_exists('cognitive_feed_action_agent'))$actions[]=cognitive_feed_action_agent('Ask Agent why','Explain why Action Plan "'.(string)$p['title'].'" is marked '.research_action_plan_cognition_label($state).', what changed from its execution baseline, and what governed follow-through should be considered. Do not execute or claim any state change.',[['type'=>'research','public_id'=>(string)$project['public_id']]]);
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('action_plan_cognition','action_plan',(string)$p['public_id'],(string)$s['state_hash']),
          'type'=>'action_plan_cognition','section'=>'needs_attention','priority'=>$priority,'created_at'=>(string)$p['updated_at'],
          'score_extra'=>$state==='decision_review'?15:8,'title'=>($state==='decision_review'?'Action Plan may affect its Decision: ':'Action Plan needs attention: ').(string)$p['title'],
          'body'=>$body,'meta'=>['action_plan_id'=>(string)$p['public_id'],'strategic_state'=>$state,'state_hash'=>(string)$s['state_hash'],'open_variances'=>count($s['open_variances'])],
          'actions'=>$actions
        ]);$shown++;
      }
    }
}
