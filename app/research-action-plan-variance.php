<?php
declare(strict_types=1);

function research_action_plan_variance_ready(PDO $pdo): bool {
    try{
        foreach(['research_action_plan_execution_baselines','research_action_plan_execution_observations','research_action_plan_variances'] as $table)
            if(!installer_table_exists($pdo,$table))return false;
        return research_action_plan_execution_ready($pdo);
    }catch(Throwable $e){return false;}
}
function research_action_plan_observation_types(): array {
    return ['progress'=>'Progress','success_measure'=>'Success measure','milestone'=>'Milestone','assumption'=>'Assumption','risk'=>'Risk','new_evidence'=>'New evidence','outcome_signal'=>'Outcome signal'];
}
function research_action_plan_observation_assessments(): array {
    return ['unknown'=>'Unknown','on_track'=>'On track','at_risk'=>'At risk','met'=>'Met','missed'=>'Missed','changed'=>'Changed'];
}
function research_action_plan_variance_types(): array {
    return ['schedule_delay'=>'Schedule delay','target_miss'=>'Target miss','assumption_changed'=>'Assumption changed','new_evidence'=>'New evidence','risk_realized'=>'Risk realized','scope_change'=>'Scope change','execution_deviation'=>'Execution deviation'];
}
function research_action_plan_variance_severities(): array {return ['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'];}
function research_action_plan_variance_value(mixed $value,int $depth=0): mixed {
    if($depth>4)return null;
    if($value===null||is_bool($value)||is_int($value)||is_float($value))return $value;
    if(is_string($value))return mb_substr(trim($value),0,12000);
    if(!is_array($value))return null;
    $out=[];$count=0;
    foreach($value as $k=>$v){
        if($count++>=80)break;
        $key=is_int($k)?$k:mb_substr((string)$k,0,120);
        $out[$key]=research_action_plan_variance_value($v,$depth+1);
    }
    return $out;
}
function research_action_plan_variance_json(mixed $value): mixed {
    if($value===null||$value==='')return null;
    if(is_array($value)||is_bool($value)||is_int($value)||is_float($value))return $value;
    if(!is_string($value))return null;
    $decoded=json_decode($value,true);return json_last_error()===JSON_ERROR_NONE?$decoded:$value;
}
function research_action_plan_execution_baseline_decode(array $row): array {
    $row['config']=research_action_plan_json($row['config_json']??null);
    $row['milestones']=research_action_plan_json($row['milestone_snapshot_json']??null);
    $row['tasks']=research_action_plan_json($row['task_snapshot_json']??null);
    return $row;
}
function research_action_plan_execution_baseline(PDO $pdo,array $viewer,string $planPublic): ?array {
    if(!research_action_plan_variance_ready($pdo))return null;
    $plan=research_action_plan_access($pdo,$viewer,trim($planPublic));if(!$plan)return null;
    $q=$pdo->prepare('SELECT * FROM research_action_plan_execution_baselines WHERE action_plan_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([(int)$plan['id']]);$row=$q->fetch();return $row?research_action_plan_execution_baseline_decode($row):null;
}
function research_action_plan_execution_snapshot(PDO $pdo,array $viewer,array $plan): array {
    $milestones=[];
    foreach(research_action_plan_milestones($pdo,$viewer,(string)$plan['public_id']) as $m){
        $milestones[]=[
          'public_id'=>(string)$m['public_id'],'title'=>(string)$m['title'],'status'=>(string)$m['status'],
          'target_on'=>(string)($m['target_on']??''),'completion_criteria'=>(array)($m['completion_criteria']??[]),
          'owner_user_public_id'=>(string)($m['owner_user_public_id']??'')
        ];
    }
    $tasks=[];$q=$pdo->prepare("SELECT rt.public_id,rt.title,rt.status,rt.task_type,rt.priority,rt.due_at,l.link_role,m.public_id milestone_public_id
      FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id LEFT JOIN research_action_plan_milestones m ON m.id=l.milestone_id
      WHERE l.action_plan_id=? ORDER BY rt.position,rt.id");$q->execute([(int)$plan['id']]);
    foreach($q->fetchAll()?:[] as $t)$tasks[]=[
      'public_id'=>(string)$t['public_id'],'title'=>(string)$t['title'],'status'=>(string)$t['status'],'task_type'=>(string)$t['task_type'],
      'priority'=>(string)$t['priority'],'due_at'=>(string)($t['due_at']??''),'link_role'=>(string)$t['link_role'],'milestone_public_id'=>(string)($t['milestone_public_id']??'')
    ];
    return ['config'=>research_action_plan_config($plan),'milestones'=>$milestones,'tasks'=>$tasks];
}
function research_action_plan_capture_execution_baseline(PDO $pdo,array $viewer,string $planPublic,bool $byAgent=false): array {
    if(!research_action_plan_variance_ready($pdo))throw new RuntimeException('Execution Evidence & Variance requires the latest database upgrade.');
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');
    research_action_plan_write_access($pdo,$viewer,$plan);
    if((string)$plan['status']!=='active')throw new InvalidArgumentException('An execution baseline can only be captured while the Action Plan is Active.');
    $snapshot=research_action_plan_execution_snapshot($pdo,$viewer,$plan);
    $q=$pdo->prepare('SELECT * FROM research_action_plan_execution_baselines WHERE action_plan_id=? AND action_plan_revision=? AND action_plan_config_hash=? LIMIT 1');
    $q->execute([(int)$plan['id'],(int)$plan['current_revision'],(string)$plan['config_hash']]);$existing=$q->fetch();
    if($existing)return research_action_plan_execution_baseline_decode($existing);
    $public=ulid_like();
    try{
        $pdo->prepare("INSERT INTO research_action_plan_execution_baselines(public_id,action_plan_id,action_plan_revision,action_plan_config_hash,config_json,milestone_snapshot_json,task_snapshot_json,captured_by_user_id,captured_by_agent)
          VALUES(?,?,?,?,?,?,?,?,?)")->execute([
            $public,(int)$plan['id'],(int)$plan['current_revision'],(string)$plan['config_hash'],
            json_encode($snapshot['config'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),
            json_encode($snapshot['milestones'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            json_encode($snapshot['tasks'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            (int)$viewer['id'],$byAgent?1:0
        ]);
        research_action_plan_event($pdo,$plan,'execution_baseline_captured',$byAgent?'agent':'user',(int)$viewer['id'],[
          'baseline_id'=>$public,'action_plan_revision'=>(int)$plan['current_revision'],'config_hash'=>(string)$plan['config_hash']
        ]);
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
    }
    $baseline=research_action_plan_execution_baseline($pdo,$viewer,$planPublic);
    if(!$baseline)throw new RuntimeException('Execution baseline could not be loaded.');return $baseline;
}
function research_action_plan_execution_subject(PDO $pdo,array $viewer,array $plan,array $baseline,array $input,string $observationType): array {
    $subjectType=(string)($input['subject_type']??'');
    if($subjectType===''){
        $subjectType=match($observationType){'success_measure'=>'success_measure','milestone'=>'milestone','assumption'=>'assumption','risk'=>'risk',default=>'action_plan'};
    }
    if(!in_array($subjectType,['action_plan','milestone','task','success_measure','assumption','risk'],true))throw new InvalidArgumentException('Invalid execution observation subject.');
    $milestoneId=null;$taskId=null;$subjectKey='';$expected=null;$config=(array)($baseline['config']??[]);
    if($subjectType==='milestone'){
        $public=trim((string)($input['milestone_id']??$input['subject_public_id']??''));if($public==='')throw new InvalidArgumentException('Milestone observation requires a milestone.');
        $m=research_action_plan_milestone_access($pdo,$viewer,$public);if(!$m||(int)$m['action_plan_id']!==(int)$plan['id'])throw new InvalidArgumentException('Milestone must belong to this Action Plan.');
        $milestoneId=(int)$m['id'];$subjectKey=$public;
        foreach((array)$baseline['milestones'] as $item)if((string)($item['public_id']??'')===$public){$expected=$item;break;}
        if($expected===null)$expected=['public_id'=>$public,'title'=>(string)$m['title'],'target_on'=>(string)($m['target_on']??''),'completion_criteria'=>research_action_plan_json($m['completion_criteria_json']??null)];
    }elseif($subjectType==='task'){
        $public=trim((string)($input['task_id']??$input['subject_public_id']??''));if($public==='')throw new InvalidArgumentException('Task observation requires a linked Research Task.');
        $t=research_action_plan_linked_task($pdo,$viewer,(string)$plan['public_id'],$public);if(!$t)throw new InvalidArgumentException('Task must be linked to this Action Plan.');
        $taskId=(int)$t['id'];$subjectKey=$public;
        foreach((array)$baseline['tasks'] as $item)if((string)($item['public_id']??'')===$public){$expected=$item;break;}
        if($expected===null)$expected=['public_id'=>$public,'title'=>(string)$t['title'],'due_at'=>(string)($t['due_at']??''),'priority'=>(string)$t['priority']];
    }elseif($subjectType==='success_measure'){
        $index=(int)($input['success_measure_index']??-1);$items=(array)($config['success_measures']??[]);
        if($index<0||!array_key_exists($index,$items))throw new InvalidArgumentException('A valid success_measure_index from the execution baseline is required.');
        $subjectKey='success_measure:'.$index;$expected=$items[$index];
    }elseif($subjectType==='assumption'){
        $index=(int)($input['assumption_index']??-1);$items=(array)($config['assumptions']??[]);
        if($index<0||!array_key_exists($index,$items))throw new InvalidArgumentException('A valid assumption_index from the execution baseline is required.');
        $subjectKey='assumption:'.$index;$expected=$items[$index];
    }elseif($subjectType==='risk'){
        $index=(int)($input['risk_index']??-1);$items=(array)($config['risks']??[]);
        if($index<0||!array_key_exists($index,$items))throw new InvalidArgumentException('A valid risk_index from the execution baseline is required.');
        $subjectKey='risk:'.$index;$expected=$items[$index];
    }else{
        $subjectKey='action_plan';$expected=['expected_result'=>$config['expected_result']??'','due_on'=>$config['due_on']??'','objective'=>$config['objective']??''];
    }
    if(array_key_exists('expected',$input))$expected=research_action_plan_variance_value($input['expected']);
    return ['subject_type'=>$subjectType,'subject_key'=>$subjectKey,'milestone_id'=>$milestoneId,'task_id'=>$taskId,'expected'=>$expected];
}
function research_action_plan_variance_row(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_action_plan_variance_ready($pdo))return null;
    $q=$pdo->prepare("SELECT v.*,rap.public_id action_plan_public_id,m.public_id milestone_public_id,rt.public_id task_public_id,
      cu.public_id created_by_user_public_id,ru.public_id resolved_by_user_public_id
      FROM research_action_plan_variances v JOIN research_action_plans rap ON rap.id=v.action_plan_id
      LEFT JOIN research_action_plan_milestones m ON m.id=v.milestone_id LEFT JOIN research_tasks rt ON rt.id=v.task_id
      LEFT JOIN users cu ON cu.id=v.created_by_user_id LEFT JOIN users ru ON ru.id=v.resolved_by_user_id WHERE v.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    if(!research_action_plan_access($pdo,$viewer,(string)$row['action_plan_public_id']))return null;
    $row['expected']=research_action_plan_variance_json($row['expected_json']??null);$row['actual']=research_action_plan_variance_json($row['actual_json']??null);return $row;
}
function research_action_plan_variance_insert(PDO $pdo,array $viewer,array $plan,array $baseline,array $spec,bool $byAgent=false): array {
    $type=(string)$spec['variance_type'];if(!isset(research_action_plan_variance_types()[$type]))throw new InvalidArgumentException('Invalid variance type.');
    $severity=(string)($spec['severity']??'medium');if(!isset(research_action_plan_variance_severities()[$severity]))$severity='medium';
    $summary=research_action_plan_text((string)($spec['summary']??''),12000);if($summary==='')throw new InvalidArgumentException('Variance summary is required.');
    $expected=research_action_plan_variance_value($spec['expected']??null);$actual=research_action_plan_variance_value($spec['actual']??null);
    $subjectType=(string)($spec['subject_type']??'action_plan');$subjectKey=research_action_plan_text((string)($spec['subject_key']??''),255);
    $fingerprint=(string)($spec['fingerprint']??'');if($fingerprint==='')$fingerprint=hash('sha256',json_encode([
      (int)$plan['id'],(int)$baseline['id'],$type,$subjectType,$subjectKey,$expected,$actual,(string)($spec['summary']??'')
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    $q=$pdo->prepare('SELECT public_id FROM research_action_plan_variances WHERE action_plan_id=? AND fingerprint=? LIMIT 1');$q->execute([(int)$plan['id'],$fingerprint]);$existing=(string)($q->fetchColumn()?:'');
    if($existing!=='')return ['variance'=>research_action_plan_variance_row($pdo,$viewer,$existing),'created'=>false];
    $public=ulid_like();$material=array_key_exists('material',$spec)?(bool)$spec['material']:in_array($severity,['high','critical'],true);
    try{
      $pdo->prepare("INSERT INTO research_action_plan_variances(public_id,action_plan_id,baseline_id,observation_id,milestone_id,task_id,variance_type,subject_type,subject_key,severity,material,summary,expected_json,actual_json,impact,response,fingerprint,created_by_user_id,created_by_agent)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
        $public,(int)$plan['id'],(int)$baseline['id'],$spec['observation_id']??null,$spec['milestone_id']??null,$spec['task_id']??null,$type,$subjectType,$subjectKey!==''?$subjectKey:null,$severity,$material?1:0,$summary,
        $expected===null?null:json_encode($expected,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),
        $actual===null?null:json_encode($actual,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),
        research_action_plan_text((string)($spec['impact']??''),12000)?:null,research_action_plan_text((string)($spec['response']??''),12000)?:null,
        $fingerprint,(int)$viewer['id'],$byAgent?1:0
      ]);
      research_action_plan_event($pdo,$plan,'execution_variance_recorded',$byAgent?'agent':'user',(int)$viewer['id'],['variance_id'=>$public,'variance_type'=>$type,'severity'=>$severity,'material'=>$material]);
      return ['variance'=>research_action_plan_variance_row($pdo,$viewer,$public),'created'=>true];
    }catch(PDOException $e){
      if((string)$e->getCode()!=='23000')throw $e;
      $q->execute([(int)$plan['id'],$fingerprint]);$existing=(string)($q->fetchColumn()?:'');
      return ['variance'=>$existing!==''?research_action_plan_variance_row($pdo,$viewer,$existing):null,'created'=>false];
    }
}
function research_action_plan_record_execution_observation(PDO $pdo,array $viewer,string $planPublic,array $input,bool $byAgent=false): array {
    if(!research_action_plan_variance_ready($pdo))throw new RuntimeException('Execution Evidence & Variance requires the latest database upgrade.');
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if(!in_array((string)$plan['status'],['active','paused','completed','cancelled'],true))throw new InvalidArgumentException('Execution evidence can only be recorded after Action Plan execution has begun.');
    $baseline=research_action_plan_execution_baseline($pdo,$viewer,$planPublic);
    if(!$baseline){
        if((string)$plan['status']!=='active')throw new InvalidArgumentException('This Action Plan has no execution baseline. Reactivate it before recording execution evidence.');
        $baseline=research_action_plan_capture_execution_baseline($pdo,$viewer,$planPublic,$byAgent);
    }
    $type=(string)($input['observation_type']??'progress');if(!isset(research_action_plan_observation_types()[$type]))throw new InvalidArgumentException('Invalid execution observation type.');
    $assessment=(string)($input['assessment']??'unknown');if(!isset(research_action_plan_observation_assessments()[$assessment]))throw new InvalidArgumentException('Invalid execution observation assessment.');
    $summary=research_action_plan_text((string)($input['summary']??''),12000);if($summary==='')throw new InvalidArgumentException('Execution observation summary is required.');
    $subject=research_action_plan_execution_subject($pdo,$viewer,$plan,$baseline,$input,$type);$expected=$subject['expected'];$actual=research_action_plan_variance_value($input['actual']??null);
    $observed=research_action_plan_date($input['observed_on']??gmdate('Y-m-d'),'Execution observation date')??gmdate('Y-m-d');
    $rawKey=trim((string)($input['idempotency_key']??''));if($rawKey==='')$rawKey=json_encode([(string)$plan['public_id'],(int)$baseline['id'],$type,$subject['subject_type'],$subject['subject_key'],$observed,$summary,$actual],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $idem=hash('sha256',$rawKey);$q=$pdo->prepare('SELECT public_id FROM research_action_plan_execution_observations WHERE action_plan_id=? AND idempotency_key=? LIMIT 1');$q->execute([(int)$plan['id'],$idem]);$existing=(string)($q->fetchColumn()?:'');
    if($existing!==''){
      $rows=research_action_plan_execution_observations($pdo,$viewer,$planPublic,200);foreach($rows as $row)if((string)$row['public_id']===$existing)return ['observation'=>$row,'variance'=>null,'reused'=>true];
    }
    $public=ulid_like();$material=(bool)($input['material']??false);$sourceType=research_action_plan_text((string)($input['source_type']??''),64);$sourcePublic=research_action_plan_text((string)($input['source_public_id']??''),80);
    $sourceSnapshot=array_key_exists('source_snapshot',$input)?research_action_plan_variance_value($input['source_snapshot']):null;
    $pdo->prepare("INSERT INTO research_action_plan_execution_observations(public_id,action_plan_id,baseline_id,milestone_id,task_id,observation_type,subject_type,subject_key,summary,expected_json,actual_json,assessment,source_type,source_public_id,source_snapshot_json,observed_on,material,idempotency_key,recorded_by_user_id,recorded_by_agent)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
      $public,(int)$plan['id'],(int)$baseline['id'],$subject['milestone_id'],$subject['task_id'],$type,$subject['subject_type'],$subject['subject_key']!==''?$subject['subject_key']:null,$summary,
      $expected===null?null:json_encode(research_action_plan_variance_value($expected),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),
      $actual===null?null:json_encode($actual,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),
      $assessment,$sourceType!==''?$sourceType:null,$sourcePublic!==''?$sourcePublic:null,$sourceSnapshot===null?null:json_encode($sourceSnapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),
      $observed,$material?1:0,$idem,(int)$viewer['id'],$byAgent?1:0
    ]);
    $observationId=(int)$pdo->lastInsertId();research_action_plan_event($pdo,$plan,'execution_observation_recorded',$byAgent?'agent':'user',(int)$viewer['id'],[
      'observation_id'=>$public,'observation_type'=>$type,'subject_type'=>$subject['subject_type'],'subject_key'=>$subject['subject_key'],'assessment'=>$assessment,'material'=>$material
    ]);
    $varianceType=(string)($input['variance_type']??'');
    if($varianceType===''&&$type==='success_measure'&&$assessment==='missed')$varianceType='target_miss';
    elseif($varianceType===''&&$type==='assumption'&&$assessment==='changed')$varianceType='assumption_changed';
    elseif($varianceType===''&&$type==='new_evidence'&&$material)$varianceType='new_evidence';
    elseif($varianceType===''&&$type==='risk'&&$material)$varianceType='risk_realized';
    elseif($varianceType===''&&in_array($assessment,['at_risk','missed'],true)&&$material)$varianceType='execution_deviation';
    if($varianceType===''&&$subject['subject_type']==='milestone'){
      $target=is_array($expected)?(string)($expected['target_on']??''):'';$actualDate=is_array($actual)?(string)($actual['completed_on']??$actual['date']??''):'';
      if($target!==''&&(($actualDate!==''&&$actualDate>$target)||($assessment==='missed'&&$observed>$target)))$varianceType='schedule_delay';
    }
    $variance=null;
    if($varianceType!==''){
      $severity=(string)($input['severity']??($material?'high':'medium'));
      $made=research_action_plan_variance_insert($pdo,$viewer,$plan,$baseline,[
        'variance_type'=>$varianceType,'subject_type'=>$subject['subject_type'],'subject_key'=>$subject['subject_key'],'milestone_id'=>$subject['milestone_id'],'task_id'=>$subject['task_id'],
        'observation_id'=>$observationId,'severity'=>$severity,'material'=>$material,'summary'=>(string)($input['variance_summary']??$summary),
        'expected'=>$expected,'actual'=>$actual,'impact'=>$input['impact']??'','response'=>$input['response']??'',
        'fingerprint'=>hash('sha256','observation|'.$observationId.'|'.$varianceType)
      ],$byAgent);$variance=$made['variance'];
    }
    $rows=research_action_plan_execution_observations($pdo,$viewer,$planPublic,200);$obs=null;foreach($rows as $row)if((string)$row['public_id']===$public){$obs=$row;break;}
    return ['observation'=>$obs,'variance'=>$variance,'reused'=>false];
}
function research_action_plan_execution_observations(PDO $pdo,array $viewer,string $planPublic,int $limit=200): array {
    if(!research_action_plan_variance_ready($pdo))return [];$plan=research_action_plan_access($pdo,$viewer,trim($planPublic));if(!$plan)return [];$limit=max(1,min(500,$limit));
    $q=$pdo->prepare("SELECT o.*,m.public_id milestone_public_id,rt.public_id task_public_id,u.public_id recorded_by_user_public_id
      FROM research_action_plan_execution_observations o LEFT JOIN research_action_plan_milestones m ON m.id=o.milestone_id LEFT JOIN research_tasks rt ON rt.id=o.task_id LEFT JOIN users u ON u.id=o.recorded_by_user_id
      WHERE o.action_plan_id=? ORDER BY o.observed_on DESC,o.id DESC LIMIT ".$limit);$q->execute([(int)$plan['id']]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row){$row['expected']=research_action_plan_variance_json($row['expected_json']??null);$row['actual']=research_action_plan_variance_json($row['actual_json']??null);$row['source_snapshot']=research_action_plan_variance_json($row['source_snapshot_json']??null);}unset($row);return $rows;
}
function research_action_plan_execution_variances(PDO $pdo,array $viewer,string $planPublic,int $limit=300): array {
    if(!research_action_plan_variance_ready($pdo))return [];$plan=research_action_plan_access($pdo,$viewer,trim($planPublic));if(!$plan)return [];$limit=max(1,min(500,$limit));
    $q=$pdo->prepare('SELECT public_id FROM research_action_plan_variances WHERE action_plan_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([(int)$plan['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$row=research_action_plan_variance_row($pdo,$viewer,(string)$public);if($row)$out[]=$row;}return $out;
}
function research_action_plan_refresh_execution_variances(PDO $pdo,array $viewer,string $planPublic,array $input=[],bool $byAgent=false): array {
    if(!research_action_plan_variance_ready($pdo))throw new RuntimeException('Execution Evidence & Variance requires the latest database upgrade.');
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    $baseline=research_action_plan_execution_baseline($pdo,$viewer,$planPublic);if(!$baseline){
      if((string)$plan['status']!=='active')throw new InvalidArgumentException('Execution variance refresh requires an existing execution baseline.');
      $baseline=research_action_plan_capture_execution_baseline($pdo,$viewer,$planPublic,$byAgent);
    }
    $asOf=research_action_plan_date($input['as_of']??gmdate('Y-m-d'),'Variance as-of date')??gmdate('Y-m-d');$created=[];
    $baseConfig=(array)$baseline['config'];$currentConfig=research_action_plan_config($plan);
    if((array)($baseConfig['assumptions']??[])!==(array)($currentConfig['assumptions']??[])){
      $made=research_action_plan_variance_insert($pdo,$viewer,$plan,$baseline,[
        'variance_type'=>'assumption_changed','subject_type'=>'assumption','subject_key'=>'assumptions','severity'=>'high','material'=>true,
        'summary'=>'Action Plan assumptions changed after the execution baseline was captured.','expected'=>$baseConfig['assumptions']??[],'actual'=>$currentConfig['assumptions']??[],
        'fingerprint'=>hash('sha256','assumptions|'.(int)$baseline['id'].'|'.hash('sha256',json_encode($currentConfig['assumptions']??[])))
      ],$byAgent);if($made['created']&&$made['variance'])$created[]=$made['variance'];
    }
    $expectedDue=(string)($baseConfig['due_on']??'');$completedOn=!empty($plan['completed_at'])?substr((string)$plan['completed_at'],0,10):'';
    if($expectedDue!==''&&(($completedOn!==''&&$completedOn>$expectedDue)||($completedOn===''&&!in_array((string)$plan['status'],['cancelled','archived'],true)&&$asOf>$expectedDue))){
      $made=research_action_plan_variance_insert($pdo,$viewer,$plan,$baseline,[
        'variance_type'=>'schedule_delay','subject_type'=>'action_plan','subject_key'=>'due_on','severity'=>'high','material'=>true,
        'summary'=>'Action Plan execution exceeded the baseline due date.','expected'=>['due_on'=>$expectedDue],'actual'=>['as_of'=>$asOf,'completed_on'=>$completedOn,'status'=>(string)$plan['status']],
        'fingerprint'=>hash('sha256','plan-due|'.(int)$baseline['id'].'|'.$expectedDue)
      ],$byAgent);if($made['created']&&$made['variance'])$created[]=$made['variance'];
    }
    $currentMilestones=[];foreach(research_action_plan_milestones($pdo,$viewer,$planPublic) as $m)$currentMilestones[(string)$m['public_id']]=$m;
    foreach((array)$baseline['milestones'] as $expected){
      $public=(string)($expected['public_id']??'');$target=(string)($expected['target_on']??'');if($public===''||$target==='')continue;$actual=$currentMilestones[$public]??null;if(!$actual)continue;
      $actualCompleted=!empty($actual['completed_at'])?substr((string)$actual['completed_at'],0,10):'';
      $late=($actualCompleted!==''&&$actualCompleted>$target)||($actualCompleted===''&&!in_array((string)$actual['status'],['completed','cancelled'],true)&&$asOf>$target);
      if(!$late)continue;
      $made=research_action_plan_variance_insert($pdo,$viewer,$plan,$baseline,[
        'variance_type'=>'schedule_delay','subject_type'=>'milestone','subject_key'=>$public,'milestone_id'=>(int)$actual['id'],'severity'=>'medium','material'=>true,
        'summary'=>'Milestone exceeded its execution-baseline target date.','expected'=>$expected,'actual'=>['status'=>(string)$actual['status'],'completed_on'=>$actualCompleted,'as_of'=>$asOf],
        'fingerprint'=>hash('sha256','milestone-due|'.(int)$baseline['id'].'|'.$public.'|'.$target)
      ],$byAgent);if($made['created']&&$made['variance'])$created[]=$made['variance'];
    }
    $currentTasks=[];$q=$pdo->prepare("SELECT rt.* FROM research_action_plan_task_links l JOIN research_tasks rt ON rt.id=l.task_id WHERE l.action_plan_id=?");$q->execute([(int)$plan['id']);foreach($q->fetchAll()?:[] as $t)$currentTasks[(string)$t['public_id']]=$t;
    foreach((array)$baseline['tasks'] as $expected){
      $public=(string)($expected['public_id']??'');$due=(string)($expected['due_at']??'');if($public===''||$due==='')continue;$actual=$currentTasks[$public]??null;if(!$actual)continue;$dueDate=substr($due,0,10);
      if(in_array((string)$actual['status'],['complete','done','archived'],true)||$asOf<=$dueDate)continue;
      $made=research_action_plan_variance_insert($pdo,$viewer,$plan,$baseline,[
        'variance_type'=>'schedule_delay','subject_type'=>'task','subject_key'=>$public,'task_id'=>(int)$actual['id'],'severity'=>'medium','material'=>false,
        'summary'=>'Linked Research Task exceeded its execution-baseline due date.','expected'=>$expected,'actual'=>['status'=>(string)$actual['status'],'as_of'=>$asOf],
        'fingerprint'=>hash('sha256','task-due|'.(int)$baseline['id'].'|'.$public.'|'.$dueDate)
      ],$byAgent);if($made['created']&&$made['variance'])$created[]=$made['variance'];
    }
    if($created)research_action_plan_event($pdo,$plan,'execution_variances_refreshed',$byAgent?'agent':'user',(int)$viewer['id'],['created'=>count($created),'as_of'=>$asOf]);
    return ['created'=>$created,'as_of'=>$asOf,'summary'=>research_action_plan_execution_variance_summary($pdo,$viewer,$planPublic)];
}
function research_action_plan_resolve_execution_variance(PDO $pdo,array $viewer,string $variancePublic,string $response='',bool $byAgent=false): array {
    if($byAgent)throw new InvalidArgumentException('Agent actions cannot resolve an execution variance without explicit human governance.');
    $variance=research_action_plan_variance_row($pdo,$viewer,trim($variancePublic));if(!$variance)throw new RuntimeException('Execution variance not found.');
    $plan=research_action_plan_detail($pdo,$viewer,(string)$variance['action_plan_public_id']);if(!$plan)throw new RuntimeException('Action Plan not found.');research_action_plan_write_access($pdo,$viewer,$plan);
    if((string)$variance['status']==='resolved')return $variance;
    $response=research_action_plan_text($response,12000);
    $pdo->prepare("UPDATE research_action_plan_variances SET status='resolved',response=?,resolved_by_user_id=?,resolved_at=NOW(),updated_at=NOW() WHERE id=?")
      ->execute([$response!==''?$response:null,(int)$viewer['id'],(int)$variance['id']]);
    research_action_plan_event($pdo,$plan,'execution_variance_resolved','user',(int)$viewer['id'],['variance_id'=>$variancePublic]);
    $fresh=research_action_plan_variance_row($pdo,$viewer,$variancePublic);if(!$fresh)throw new RuntimeException('Resolved execution variance could not be loaded.');return $fresh;
}
function research_action_plan_execution_variance_summary(PDO $pdo,array $viewer,string $planPublic): array {
    $rows=research_action_plan_execution_variances($pdo,$viewer,$planPublic,500);$summary=['total'=>count($rows),'open'=>0,'resolved'=>0,'material_open'=>0,'high_or_critical_open'=>0,'by_type'=>[]];
    foreach($rows as $row){$status=(string)$row['status'];if(isset($summary[$status]))$summary[$status]++;$type=(string)$row['variance_type'];$summary['by_type'][$type]=($summary['by_type'][$type]??0)+1;
      if($status==='open'&&!empty($row['material']))$summary['material_open']++;if($status==='open'&&in_array((string)$row['severity'],['high','critical'],true))$summary['high_or_critical_open']++;}
    ksort($summary['by_type']);return $summary;
}
function research_action_plan_execution_variance_detail(PDO $pdo,array $viewer,string $planPublic): array {
    $plan=research_action_plan_detail($pdo,$viewer,trim($planPublic));if(!$plan)throw new RuntimeException('Action Plan not found.');
    return ['action_plan'=>$plan,'baseline'=>research_action_plan_execution_baseline($pdo,$viewer,$planPublic),'observations'=>research_action_plan_execution_observations($pdo,$viewer,$planPublic,200),'variances'=>research_action_plan_execution_variances($pdo,$viewer,$planPublic,300),'summary'=>research_action_plan_execution_variance_summary($pdo,$viewer,$planPublic)];
}
