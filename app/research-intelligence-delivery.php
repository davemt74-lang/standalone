<?php
declare(strict_types=1);

/**
 * Phase 69 — Research Intelligence Delivery & Subscriptions
 *
 * The existing Research Program worker remains the only scheduler.
 * Subscriptions bind one viewer to one Report Studio preset + Research Program.
 */

function research_intelligence_delivery_ready(PDO $pdo): bool {
    try{
        return research_report_studio_ready($pdo)
            &&research_programs_ready($pdo)
            &&installer_table_exists($pdo,'research_report_subscriptions')
            &&installer_table_exists($pdo,'research_report_deliveries')
            &&installer_table_exists($pdo,'research_report_delivery_events');
    }catch(Throwable $e){return false;}
}

function research_report_subscription_policies(): array {
    return [
      'every_run'=>'Every Program cycle',
      'if_changed'=>'Only when this Report scope changed',
      'material_change_only'=>'Only when material Program changes affect this Report',
      'if_stale'=>'Only when the previous Report is stale',
    ];
}

function research_report_subscription_statuses(): array {return ['active'=>'Active','paused'=>'Paused','archived'=>'Archived'];}

function research_report_delivery_event(PDO $pdo,?int $deliveryId,?int $subscriptionId,string $event,string $actor='system',?int $actorUserId=null,array $payload=[]): void {
    if(!research_intelligence_delivery_ready($pdo))return;
    $actor=in_array($actor,['user','agent','system'],true)?$actor:'system';
    $pdo->prepare("INSERT INTO research_report_delivery_events(public_id,delivery_id,subscription_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?,?)")
      ->execute([ulid_like(),$deliveryId,$subscriptionId,mb_substr(trim($event),0,64),$actor,$actorUserId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null]);
}

function research_report_subscription_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_intelligence_delivery_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rrs.*,ra.public_id agent_public_id,ra.name agent_name,ra.team_id,ra.owner_user_id,ra.conversation_id,c.public_id conversation_public_id,
      rp.public_id project_public_id,rp.title project_title,rrp.public_id preset_public_id,rrp.name preset_name,rrp.report_type,
      prog.public_id program_public_id,prog.title program_title,prog.status program_status,prog.cadence program_cadence,prog.next_run_at program_next_run_at,
      last_report.public_id last_report_public_id,last_report.title last_report_title,last_report.created_at last_report_created_at
      FROM research_report_subscriptions rrs
      JOIN research_agents ra ON ra.id=rrs.research_agent_id
      JOIN conversations c ON c.id=ra.conversation_id
      JOIN research_projects rp ON rp.id=rrs.project_id
      LEFT JOIN research_report_presets rrp ON rrp.id=rrs.preset_id
      LEFT JOIN research_programs prog ON prog.id=rrs.program_id
      LEFT JOIN research_system_reports last_report ON last_report.id=rrs.last_report_id
      WHERE rrs.public_id=? AND rrs.subscriber_user_id=? LIMIT 1");
    $q->execute([trim($publicId),(int)$viewer['id']]);$row=$q->fetch();if(!$row)return null;
    if(!research_agent_access($pdo,$viewer,(string)$row['agent_public_id']))return null;
    foreach(['notify_in_app','notify_agent_chat','include_summary','include_comparison'] as $key)$row[$key]=(int)$row[$key]===1;
    return $row;
}

function research_report_subscription_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=100,bool $includeArchived=false): array {
    if(!research_intelligence_delivery_ready($pdo))return [];
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));
    $sql="SELECT public_id FROM research_report_subscriptions WHERE research_agent_id=? AND subscriber_user_id=?".($includeArchived?'':" AND status<>'archived'")." ORDER BY updated_at DESC,id DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute([(int)$agent['id'],(int)$viewer['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){$row=research_report_subscription_access($pdo,$viewer,(string)$id);if($row)$out[]=$row;}
    return $out;
}

function research_report_subscription_validate_links(PDO $pdo,array $viewer,string $agentPublic,string $presetPublic,string $programPublic): array {
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);
    $project=research_agent_workspace_project($pdo,$viewer,$agentPublic);if(!$project)throw new RuntimeException('Research Agent workspace not found.');
    research_agent_workspace_require_write($project);
    $preset=research_report_studio_preset_access($pdo,$viewer,$presetPublic);if(!$preset||($preset['status']??'')!=='active'||!hash_equals((string)$preset['agent_public_id'],$agentPublic))throw new InvalidArgumentException('Choose an active Report preset from this Research Agent.');
    $program=research_program_access($pdo,$viewer,$programPublic);if(!$program||!hash_equals((string)$program['agent_public_id'],$agentPublic)||($program['status']??'')==='archived')throw new InvalidArgumentException('Choose a non-archived Research Program from this Research Agent.');
    if(!empty($preset['program_public_id'])&&!hash_equals((string)$preset['program_public_id'],$programPublic))throw new InvalidArgumentException('This preset is already associated with a different Research Program.');
    return [$agent,$project,$preset,$program];
}

function research_report_subscription_create(PDO $pdo,array $viewer,string $agentPublic,array $input,bool $byAgent=false): array {
    if(!research_intelligence_delivery_ready($pdo))throw new RuntimeException('Research Intelligence Delivery requires the latest database upgrade.');
    $presetPublic=trim((string)($input['preset_id']??''));$programPublic=trim((string)($input['program_id']??''));
    if($presetPublic===''||$programPublic==='')throw new InvalidArgumentException('A saved Report preset and Research Program are required.');
    [$agent,$project,$preset,$program]=research_report_subscription_validate_links($pdo,$viewer,$agentPublic,$presetPublic,$programPublic);
    $policy=(string)($input['delivery_policy']??'material_change_only');if(!isset(research_report_subscription_policies()[$policy]))$policy='material_change_only';
    $stale=max(1,min(8760,(int)($input['stale_after_hours']??168)));
    $name=mb_substr(trim((string)($input['name']??'')),0,190);if($name==='')$name=(string)$preset['name'];
    $notifyInApp=array_key_exists('notify_in_app',$input)?!empty($input['notify_in_app']):true;
    $notifyChat=array_key_exists('notify_agent_chat',$input)?!empty($input['notify_agent_chat']):true;
    $includeSummary=array_key_exists('include_summary',$input)?!empty($input['include_summary']):true;
    $includeComparison=array_key_exists('include_comparison',$input)?!empty($input['include_comparison']):true;
    $q=$pdo->prepare("SELECT public_id FROM research_report_subscriptions WHERE subscriber_user_id=? AND preset_id=? AND program_id=? LIMIT 1");
    $q->execute([(int)$viewer['id'],(int)$preset['id'],(int)$program['id']]);$existing=(string)($q->fetchColumn()?:'');
    if($existing!==''){
        $pdo->prepare("UPDATE research_report_subscriptions SET name=?,status='active',delivery_policy=?,stale_after_hours=?,notify_in_app=?,notify_agent_chat=?,include_summary=?,include_comparison=?,updated_at=NOW() WHERE public_id=? AND subscriber_user_id=?")
          ->execute([$name,$policy,$stale,$notifyInApp?1:0,$notifyChat?1:0,$includeSummary?1:0,$includeComparison?1:0,$existing,(int)$viewer['id']]);
        $row=research_report_subscription_access($pdo,$viewer,$existing);if(!$row)throw new RuntimeException('Could not reactivate Report subscription.');
        research_report_delivery_event($pdo,null,(int)$row['id'],'subscription_reactivated',$byAgent?'agent':'user',(int)$viewer['id'],['delivery_policy'=>$policy]);
        return $row;
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM research_report_subscriptions WHERE research_agent_id=? AND subscriber_user_id=? AND status<>'archived'");$q->execute([(int)$agent['id'],(int)$viewer['id']]);
    if((int)$q->fetchColumn()>=40)throw new RuntimeException('A user can keep up to 40 active or paused Report subscriptions per Research Agent.');
    $public=ulid_like();
    $pdo->prepare("INSERT INTO research_report_subscriptions(public_id,research_agent_id,project_id,subscriber_user_id,preset_id,program_id,name,status,delivery_policy,stale_after_hours,notify_in_app,notify_agent_chat,include_summary,include_comparison)
      VALUES(?,?,?,?,?,?,?,'active',?,?,?,?,?,?)")
      ->execute([$public,(int)$agent['id'],(int)$project['id'],(int)$viewer['id'],(int)$preset['id'],(int)$program['id'],$name,$policy,$stale,$notifyInApp?1:0,$notifyChat?1:0,$includeSummary?1:0,$includeComparison?1:0]);
    $id=(int)$pdo->lastInsertId();research_report_delivery_event($pdo,null,$id,'subscription_created',$byAgent?'agent':'user',(int)$viewer['id'],['delivery_policy'=>$policy,'preset_id'=>$presetPublic,'program_id'=>$programPublic]);
    return research_report_subscription_access($pdo,$viewer,$public)??['public_id'=>$public];
}

function research_report_subscription_update(PDO $pdo,array $viewer,string $publicId,array $input,bool $byAgent=false): array {
    $sub=research_report_subscription_access($pdo,$viewer,$publicId);if(!$sub)throw new RuntimeException('Report subscription not found.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)$sub['agent_public_id']);if(!$project)throw new RuntimeException('Report subscription not found.');research_agent_workspace_require_write($project);
    $policy=(string)($input['delivery_policy']??$sub['delivery_policy']);if(!isset(research_report_subscription_policies()[$policy]))$policy=(string)$sub['delivery_policy'];
    $stale=max(1,min(8760,(int)($input['stale_after_hours']??$sub['stale_after_hours'])));
    $name=mb_substr(trim((string)($input['name']??$sub['name'])),0,190);if($name==='')$name=(string)$sub['name'];
    $notifyInApp=array_key_exists('notify_in_app',$input)?!empty($input['notify_in_app']):(bool)$sub['notify_in_app'];
    $notifyChat=array_key_exists('notify_agent_chat',$input)?!empty($input['notify_agent_chat']):(bool)$sub['notify_agent_chat'];
    $includeSummary=array_key_exists('include_summary',$input)?!empty($input['include_summary']):(bool)$sub['include_summary'];
    $includeComparison=array_key_exists('include_comparison',$input)?!empty($input['include_comparison']):(bool)$sub['include_comparison'];
    $pdo->prepare("UPDATE research_report_subscriptions SET name=?,delivery_policy=?,stale_after_hours=?,notify_in_app=?,notify_agent_chat=?,include_summary=?,include_comparison=?,updated_at=NOW() WHERE id=?")
      ->execute([$name,$policy,$stale,$notifyInApp?1:0,$notifyChat?1:0,$includeSummary?1:0,$includeComparison?1:0,(int)$sub['id']]);
    research_report_delivery_event($pdo,null,(int)$sub['id'],'subscription_updated',$byAgent?'agent':'user',(int)$viewer['id'],['delivery_policy'=>$policy]);
    return research_report_subscription_access($pdo,$viewer,$publicId)??$sub;
}

function research_report_subscription_set_status(PDO $pdo,array $viewer,string $publicId,string $status,bool $byAgent=false): array {
    $sub=research_report_subscription_access($pdo,$viewer,$publicId);if(!$sub)throw new RuntimeException('Report subscription not found.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)$sub['agent_public_id']);if(!$project)throw new RuntimeException('Report subscription not found.');research_agent_workspace_require_write($project);
    if(!isset(research_report_subscription_statuses()[$status]))throw new InvalidArgumentException('Invalid Report subscription status.');
    if($status==='active')research_report_subscription_validate_links($pdo,$viewer,(string)$sub['agent_public_id'],(string)$sub['preset_public_id'],(string)$sub['program_public_id']);
    $pdo->prepare('UPDATE research_report_subscriptions SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,(int)$sub['id']]);
    research_report_delivery_event($pdo,null,(int)$sub['id'],'subscription_'.$status,$byAgent?'agent':'user',(int)$viewer['id']);
    return research_report_subscription_access($pdo,$viewer,$publicId)??array_merge($sub,['status'=>$status]);
}

function research_report_delivery_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_intelligence_delivery_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rrd.*,rrs.public_id subscription_public_id,rrs.name subscription_name,ra.public_id agent_public_id,ra.name agent_name,
      rp.public_id project_public_id,rp.title project_title,rrp.public_id preset_public_id,rrp.name preset_name,prog.public_id program_public_id,prog.title program_title,
      prun.public_id program_run_public_id,report.public_id report_public_id,report.title report_title,prev.public_id previous_report_public_id
      FROM research_report_deliveries rrd
      LEFT JOIN research_report_subscriptions rrs ON rrs.id=rrd.subscription_id
      JOIN research_agents ra ON ra.id=rrd.research_agent_id JOIN research_projects rp ON rp.id=rrd.project_id
      LEFT JOIN research_report_presets rrp ON rrp.id=rrd.preset_id LEFT JOIN research_programs prog ON prog.id=rrd.program_id
      LEFT JOIN research_program_runs prun ON prun.id=rrd.program_run_id LEFT JOIN research_system_reports report ON report.id=rrd.report_id
      LEFT JOIN research_system_reports prev ON prev.id=rrd.previous_report_id
      WHERE rrd.public_id=? AND rrd.subscriber_user_id=? LIMIT 1");
    $q->execute([trim($publicId),(int)$viewer['id']]);$row=$q->fetch();if(!$row)return null;
    if(!research_agent_access($pdo,$viewer,(string)$row['agent_public_id']))return null;
    $row['comparison']=json_decode((string)($row['comparison_json']??''),true)?:[];$row['channels']=json_decode((string)($row['channels_json']??''),true)?:[];
    return $row;
}

function research_report_delivery_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=100,bool $includeSuppressed=true): array {
    if(!research_intelligence_delivery_ready($pdo))return [];
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(250,$limit));
    $sql="SELECT public_id FROM research_report_deliveries WHERE research_agent_id=? AND subscriber_user_id=?".($includeSuppressed?'':" AND status IN ('delivered','viewed')")." ORDER BY id DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute([(int)$agent['id'],(int)$viewer['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){$row=research_report_delivery_access($pdo,$viewer,(string)$id);if($row)$out[]=$row;}
    return $out;
}

function research_report_delivery_mark_viewed(PDO $pdo,array $viewer,string $publicId): array {
    $row=research_report_delivery_access($pdo,$viewer,$publicId);if(!$row)throw new RuntimeException('Intelligence delivery not found.');
    if((string)$row['status']==='delivered'){
        $pdo->prepare("UPDATE research_report_deliveries SET status='viewed',viewed_at=NOW() WHERE id=? AND status='delivered'")->execute([(int)$row['id']]);
        research_report_delivery_event($pdo,(int)$row['id'],$row['subscription_id']!==null?(int)$row['subscription_id']:null,'viewed','user',(int)$viewer['id']);
    }
    return research_report_delivery_access($pdo,$viewer,$publicId)??$row;
}

function research_intelligence_delivery_program_run(PDO $pdo,int $runId): ?array {
    $q=$pdo->prepare("SELECT rpr.*,rp.public_id program_public_id FROM research_program_runs rpr JOIN research_programs rp ON rp.id=rpr.program_id WHERE rpr.id=? LIMIT 1");$q->execute([$runId]);return $q->fetch()?:null;
}

function research_intelligence_delivery_subscriber(PDO $pdo,array $sub): ?array {
    $uid=(int)($sub['subscriber_user_id']??0);if($uid<=0)return null;$q=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$q->execute([$uid]);return $q->fetch()?:null;
}

function research_intelligence_delivery_reserve(PDO $pdo,array $sub,?array $run,string $trigger,string $reason='processing'): array {
    $slot=$run?(string)$run['public_id']:ulid_like();$dedupe=hash('sha256','research-intelligence-delivery|'.(int)$sub['id'].'|'.$trigger.'|'.$slot);
    $q=$pdo->prepare('SELECT id,public_id,status,report_id FROM research_report_deliveries WHERE dedupe_key=? LIMIT 1');$q->execute([$dedupe]);$existing=$q->fetch();
    if($existing){
        $retryable=in_array((string)$existing['status'],['pending','failed'],true);
        return ['existing'=>true,'retryable'=>$retryable,'id'=>(int)$existing['id'],'public_id'=>(string)$existing['public_id'],'row_public_id'=>(string)$existing['public_id'],'report_id'=>$existing['report_id']!==null?(int)$existing['report_id']:null,'dedupe_key'=>$dedupe];
    }
    $public=ulid_like();$pdo->prepare("INSERT INTO research_report_deliveries(public_id,subscription_id,research_agent_id,project_id,subscriber_user_id,preset_id,program_id,program_run_id,trigger_type,status,reason_code,dedupe_key,material_change_count,channels_json)
      VALUES(?,?,?,?,?,?,?,?,?,'pending',?,?,?,?)")
      ->execute([$public,(int)$sub['id'],(int)$sub['research_agent_id'],(int)$sub['project_id'],$sub['subscriber_user_id']!==null?(int)$sub['subscriber_user_id']:null,$sub['preset_id']!==null?(int)$sub['preset_id']:null,$sub['program_id']!==null?(int)$sub['program_id']:null,$run?(int)$run['id']:null,$trigger,$reason,$dedupe,$run?(int)($run['material_change_count']??0):0,json_encode(['in_app'=>(bool)$sub['notify_in_app'],'agent_chat'=>(bool)$sub['notify_agent_chat']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $id=(int)$pdo->lastInsertId();research_report_delivery_event($pdo,$id,(int)$sub['id'],'reserved','system',null,['trigger'=>$trigger]);
    return ['existing'=>false,'id'=>$id,'public_id'=>$public,'dedupe_key'=>$dedupe];
}

function research_intelligence_delivery_suppress(PDO $pdo,array $reservation,array $sub,string $reason,string $summary,?array $run=null): array {
    if(!empty($reservation['existing']))return ['status'=>'deduplicated','delivery_id'=>$reservation['row_public_id']];
    $pdo->prepare("UPDATE research_report_deliveries SET status='suppressed',reason_code=?,summary=? WHERE id=?")
      ->execute([$reason,mb_substr($summary,0,12000),(int)$reservation['id']]);
    research_report_delivery_event($pdo,(int)$reservation['id'],(int)$sub['id'],'suppressed','system',null,['reason'=>$reason]);
    return ['status'=>'suppressed','delivery_id'=>$reservation['public_id'],'reason'=>$reason];
}

function research_intelligence_delivery_should_run(PDO $pdo,array $config,array $viewer,array $sub,?array $run,string $trigger): array {
    if($trigger==='manual')return ['run'=>true,'reason'=>'manual_delivery','previous'=>null,'freshness'=>null];
    $previous=null;if(!empty($sub['last_report_public_id']))$previous=research_system_report_access($pdo,$viewer,(string)$sub['last_report_public_id']);
    if(!$previous)return ['run'=>true,'reason'=>'initial_delivery','previous'=>null,'freshness'=>null];
    $policy=(string)$sub['delivery_policy'];$freshness=research_report_studio_freshness($pdo,$config,$viewer,$previous);
    if($policy==='every_run')return ['run'=>true,'reason'=>'every_program_run','previous'=>$previous,'freshness'=>$freshness];
    if($policy==='material_change_only'){
        if((int)($run['material_change_count']??0)<1)return ['run'=>false,'reason'=>'no_material_program_change','previous'=>$previous,'freshness'=>$freshness];
        if(($freshness['state']??'current')==='current')return ['run'=>false,'reason'=>'report_scope_unchanged','previous'=>$previous,'freshness'=>$freshness];
        return ['run'=>true,'reason'=>'material_change','previous'=>$previous,'freshness'=>$freshness];
    }
    if($policy==='if_changed'){
        return ['run'=>(($freshness['state']??'current')!=='current'),'reason'=>(($freshness['state']??'current')!=='current'?'report_changed':'report_unchanged'),'previous'=>$previous,'freshness'=>$freshness];
    }
    $age=time()-(strtotime((string)$previous['created_at'])?:time());$staleHours=max(1,(int)$sub['stale_after_hours']);
    $stale=$age>=($staleHours*3600)||($freshness['state']??'')==='stale';
    return ['run'=>$stale,'reason'=>$stale?'report_stale':'report_not_stale','previous'=>$previous,'freshness'=>$freshness];
}

function research_intelligence_delivery_summary(array $sub,array $report,?array $comparison,int $materialCount): string {
    $parts=[];$parts[]=(string)$report['title'].' is ready.';
    if($materialCount>0)$parts[]=$materialCount.' material Research '.($materialCount===1?'change':'changes').' were detected in the Program cycle.';
    if(!empty($sub['include_comparison'])&&$comparison){
        $d=(array)($comparison['diff']??[]);$material=(int)($d['material_change_count']??0);$parts[]='Compared with the previous Report Run: '.$material.' material Claim/Finding/Source '.($material===1?'change':'changes').'.';
    }
    if(!empty($sub['include_summary'])&&trim((string)($report['rendered_summary']??''))!=='')$parts[]=mb_substr(trim((string)$report['rendered_summary']),0,1200);
    return mb_substr(implode(' ',$parts),0,12000);
}

function research_intelligence_delivery_chat_update(PDO $pdo,array $sub,array $report,string $body,string $deliveryPublic): bool {
    if(empty($sub['notify_agent_chat']))return false;
    if(!empty($sub['team_id']))return false; // user-specific subscriptions must not create private-preference messages in a shared Team Agent chat.
    $conversationId=(int)($sub['conversation_id']??0);if($conversationId<=0)return false;
    $public=ulid_like();$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,parent_message_id,body) VALUES(?,?,NULL,'agent',NULL,?)")
      ->execute([$public,$conversationId,mb_substr($body,0,12000)]);$messageId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$conversationId]);
    if(installer_table_exists($pdo,'conversation_message_attachments'))$pdo->prepare("INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,'report',?,?)")
      ->execute([$messageId,(string)$report['public_id'],json_encode(['label'=>(string)$report['title'],'source'=>'research_intelligence_delivery','delivery_id'=>$deliveryPublic],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,message_id,payload_json) VALUES(?,'agent_message_created',?,?)")
      ->execute([$conversationId,$messageId,json_encode(['source'=>'research_intelligence_delivery','delivery_id'=>$deliveryPublic,'report_id'=>$report['public_id'],'subscription_id'=>$sub['public_id']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    return true;
}

function research_intelligence_delivery_complete(PDO $pdo,array $viewer,array $reservation,array $sub,array $report,?array $previous,?array $comparison,?array $run,string $reason): array {
    $diff=$comparison?(array)($comparison['diff']??[]):[];$signature=hash('sha256',json_encode([
      'state_hash'=>(string)$report['input_state_hash'],'report_type'=>(string)$report['report_type'],
      'diff'=>$diff,'material_count'=>(int)($run['material_change_count']??0)
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    $summary=research_intelligence_delivery_summary($sub,$report,$comparison,(int)($run['material_change_count']??0));
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE research_report_deliveries SET status='delivered',reason_code=?,report_id=?,previous_report_id=?,change_signature=?,summary=?,comparison_json=?,delivered_at=NOW() WHERE id=?")
          ->execute([$reason,(int)$report['id'],$previous?(int)$previous['id']:null,$signature,$summary,$comparison?json_encode($comparison['diff'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,(int)$reservation['id']]);
        $pdo->prepare("UPDATE research_report_subscriptions SET last_report_id=?,last_state_hash=?,last_change_signature=?,last_delivered_at=NOW(),updated_at=NOW() WHERE id=?")
          ->execute([(int)$report['id'],(string)$report['input_state_hash'],$signature,(int)$sub['id']]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    $channels=['in_app'=>false,'agent_chat'=>false];
    try{
        if(!empty($sub['notify_in_app']))$channels['in_app']=notification_create($pdo,(int)$viewer['id'],null,'research_report_delivery','research_report_delivery',(string)$reservation['public_id'],$summary,[
          'allow_self'=>true,'dedupe_key'=>'research-report-delivery:'.$reservation['public_id'],'group_key'=>'research-report-subscription:'.$sub['public_id'],
          'context'=>['agent_public_id'=>$sub['agent_public_id'],'report_public_id'=>$report['public_id'],'delivery_public_id'=>$reservation['public_id'],'subscription_public_id'=>$sub['public_id']]
        ]);
    }catch(Throwable $e){error_log('[Annotated Research Delivery notification] '.$e->getMessage());}
    try{$channels['agent_chat']=research_intelligence_delivery_chat_update($pdo,$sub,$report,$summary,(string)$reservation['public_id']);}catch(Throwable $e){error_log('[Annotated Research Delivery chat] '.$e->getMessage());}
    try{$pdo->prepare("UPDATE research_report_deliveries SET channels_json=? WHERE id=?")->execute([json_encode($channels,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$reservation['id']]);}catch(Throwable $e){error_log('[Annotated Research Delivery channel-audit] '.$e->getMessage());}
    try{research_report_delivery_event($pdo,(int)$reservation['id'],(int)$sub['id'],'delivered','agent',null,['report_id'=>$report['public_id'],'channels'=>$channels,'reason'=>$reason]);}catch(Throwable $e){error_log('[Annotated Research Delivery event] '.$e->getMessage());}
    return ['status'=>'delivered','delivery_id'=>$reservation['public_id'],'report_id'=>$report['public_id'],'channels'=>$channels,'summary'=>$summary];
}

function research_intelligence_delivery_process_subscription(PDO $pdo,array $config,array $sub,?array $run,string $trigger): array {
    $viewer=research_intelligence_delivery_subscriber($pdo,$sub);
    $reservation=research_intelligence_delivery_reserve($pdo,$sub,$run,$trigger);
    if(!empty($reservation['existing'])&&empty($reservation['retryable']))return ['status'=>'deduplicated','delivery_id'=>$reservation['row_public_id']];
    if(!$viewer){
        $pdo->prepare("UPDATE research_report_subscriptions SET status='paused',updated_at=NOW() WHERE id=?")->execute([(int)$sub['id']]);
        return research_intelligence_delivery_suppress($pdo,$reservation,$sub,'subscriber_unavailable','Subscription paused because the subscriber account is unavailable.',$run);
    }
    if(($sub['status']??'')==='archived')return research_intelligence_delivery_suppress($pdo,$reservation,$sub,'subscription_archived','Subscription is archived.',$run);
    if(($sub['status']??'')!=='active'&&$trigger!=='manual')return research_intelligence_delivery_suppress($pdo,$reservation,$sub,'subscription_not_active','Subscription is not active for scheduled delivery.',$run);
    if(empty($sub['preset_public_id'])||empty($sub['program_public_id']))return research_intelligence_delivery_suppress($pdo,$reservation,$sub,'subscription_dependency_missing','Subscription is missing its Report preset or Research Program.',$run);
    $program=research_program_access($pdo,$viewer,(string)$sub['program_public_id']);$preset=research_report_studio_preset_access($pdo,$viewer,(string)$sub['preset_public_id']);
    if(!$program||!$preset){
        $pdo->prepare("UPDATE research_report_subscriptions SET status='paused',updated_at=NOW() WHERE id=?")->execute([(int)$sub['id']]);
        return research_intelligence_delivery_suppress($pdo,$reservation,$sub,'subscription_dependency_unavailable','Subscription paused because its preset or Program is unavailable.',$run);
    }
    $decision=research_intelligence_delivery_should_run($pdo,$config,$viewer,$sub,$run,$trigger);
    if(empty($decision['run']))return research_intelligence_delivery_suppress($pdo,$reservation,$sub,(string)$decision['reason'],'No intelligence delivery was needed for this Program cycle.',$run);
    $report=null;
    try{
        if(!empty($reservation['report_id'])){
            $q=$pdo->prepare('SELECT public_id FROM research_system_reports WHERE id=? LIMIT 1');$q->execute([(int)$reservation['report_id']]);$reportPublic=(string)($q->fetchColumn()?:'');
            if($reportPublic!=='')$report=research_system_report_access($pdo,$viewer,$reportPublic);
        }
        if(!$report)$report=research_report_studio_run_preset($pdo,$config,$viewer,(string)$sub['agent_public_id'],(string)$sub['preset_public_id'],true,'program');
        try{$pdo->prepare("UPDATE research_report_deliveries SET status='pending',report_id=?,reason_code='processing',summary=NULL WHERE id=?")->execute([(int)$report['id'],(int)$reservation['id']]);}catch(Throwable $ignored){}
        $previous=$decision['previous']??null;$comparison=null;
        if($previous)try{$comparison=research_report_studio_compare($pdo,$config,$viewer,(string)$previous['public_id'],(string)$report['public_id']);}catch(Throwable $ignored){}
        return research_intelligence_delivery_complete($pdo,$viewer,$reservation,$sub,$report,$previous,$comparison,$run,(string)$decision['reason']);
    }catch(Throwable $e){
        $reportId=is_array($report)&&!empty($report['id'])?(int)$report['id']:null;
        $pdo->prepare("UPDATE research_report_deliveries SET status='failed',report_id=COALESCE(?,report_id),reason_code='generation_failed',summary=? WHERE id=?")
          ->execute([$reportId,mb_substr($e->getMessage(),0,12000),(int)$reservation['id']]);
        try{research_report_delivery_event($pdo,(int)$reservation['id'],(int)$sub['id'],'failed','system',null,['error'=>mb_substr($e->getMessage(),0,1000)]);}catch(Throwable $ignored){}
        return ['status'=>'failed','delivery_id'=>$reservation['public_id'],'error'=>$e->getMessage()];
    }
}

function research_intelligence_delivery_process_program_run(PDO $pdo,array $program,int $runId,string $trigger='program_completed'): array {
    if(!research_intelligence_delivery_ready($pdo))return [];
    if(!in_array($trigger,['program_completed','program_quiet'],true))$trigger='program_completed';
    $run=research_intelligence_delivery_program_run($pdo,$runId);if(!$run)return [];
    $q=$pdo->prepare("SELECT public_id FROM research_report_subscriptions WHERE program_id=? AND research_agent_id=? AND status='active' ORDER BY id");
    $q->execute([(int)$program['id'],(int)$program['research_agent_id']]);$results=[];$config=is_array($GLOBALS['config']??null)?$GLOBALS['config']:[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $public){
        $uidq=$pdo->prepare("SELECT subscriber_user_id FROM research_report_subscriptions WHERE public_id=?");$uidq->execute([(string)$public]);$uid=(int)($uidq->fetchColumn()?:0);if($uid<=0){$pdo->prepare("UPDATE research_report_subscriptions SET status='paused',updated_at=NOW() WHERE public_id=?")->execute([(string)$public]);continue;}
        $vq=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$vq->execute([$uid]);$viewer=$vq->fetch();
        if(!$viewer){$pdo->prepare("UPDATE research_report_subscriptions SET status='paused',updated_at=NOW() WHERE public_id=?")->execute([(string)$public]);continue;}
        $sub=research_report_subscription_access($pdo,$viewer,(string)$public);if(!$sub)continue;
        try{$results[]=research_intelligence_delivery_process_subscription($pdo,$config,$sub,$run,$trigger);}catch(Throwable $e){error_log('[Annotated Research Delivery '.$public.'] '.$e->getMessage());}
    }
    return $results;
}

function research_intelligence_delivery_run_manual(PDO $pdo,array $config,array $viewer,string $subscriptionPublic): array {
    $sub=research_report_subscription_access($pdo,$viewer,$subscriptionPublic);if(!$sub)throw new RuntimeException('Report subscription not found.');
    if(($sub['status']??'')==='archived')throw new RuntimeException('Archived Report subscriptions cannot deliver.');
    return research_intelligence_delivery_process_subscription($pdo,$config,$sub,null,'manual');
}

function research_intelligence_delivery_agent_context(PDO $pdo,array $viewer,string $agentPublic): string {
    if(!research_intelligence_delivery_ready($pdo))return '';
    $subs=research_report_subscription_list($pdo,$viewer,$agentPublic,12,false);$deliveries=research_report_delivery_list($pdo,$viewer,$agentPublic,8,false);
    $parts=['[RESEARCH INTELLIGENCE DELIVERY]'];
    if(!$subs)$parts[]='No active or paused Report subscriptions for this user.';
    foreach($subs as $s)$parts[]='SUBSCRIPTION '.$s['public_id'].': '.$s['name'].' · '.str_replace('_',' ',(string)$s['delivery_policy']).' · '.$s['status'].' · Program '.$s['program_title'].' ('.$s['program_cadence'].') · Preset '.$s['preset_name'].'.';
    foreach($deliveries as $d)$parts[]='DELIVERY '.$d['public_id'].': '.$d['status'].' · '.($d['report_title']?:$d['preset_name']).' · '.($d['summary']?:'No summary').' · '.$d['created_at'].'.';
    return mb_substr(implode("\n",$parts),0,12000);
}

function research_intelligence_delivery_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=12): void {
    if(!research_intelligence_delivery_ready($pdo))return;$limit=max(1,min(30,$limit));
    $q=$pdo->prepare("SELECT public_id FROM research_report_deliveries WHERE subscriber_user_id=? AND status='delivered' AND delivered_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY id DESC LIMIT ".$limit);
    $q->execute([(int)$viewer['id']]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){
        $d=research_report_delivery_access($pdo,$viewer,(string)$id);if(!$d)continue;$priority=(int)$d['material_change_count']>0?'high':'medium';
        cognitive_feed_add($items,[
          'key'=>cognitive_feed_key('research_report_delivery','delivery',(string)$d['public_id'],(string)($d['change_signature']??$d['created_at'])),
          'type'=>'research_report_delivery','section'=>(int)$d['material_change_count']>0?'needs_attention':'recent_changes','priority'=>$priority,'created_at'=>$d['delivered_at']?:$d['created_at'],
          'title'=>'Research intelligence ready: '.(string)($d['report_title']?:$d['preset_name']),
          'body'=>(string)($d['summary']?:'A subscribed Research Report is ready.'),
          'meta'=>['agent'=>$d['agent_name'],'program'=>$d['program_title'],'material_changes'=>(int)$d['material_change_count']],
          'actions'=>array_values(array_filter([
            !empty($d['report_public_id'])?cognitive_feed_action_link('Open Report','/research-reports.php?agent='.rawurlencode((string)$d['agent_public_id']).'&view=inbox&delivery='.rawurlencode((string)$d['public_id']).'&report='.rawurlencode((string)$d['report_public_id'])):null,
            cognitive_feed_action_agent('What changed?','Explain this intelligence delivery, what changed since the previous Report Run, why it matters, and what I should review next.',[['type'=>'report','public_id'=>(string)$d['report_public_id']]])
          ]))
        ]);
    }
}
