<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat.php';
require_once __DIR__.'/proactive-intelligence.php';

function research_automation_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_automations')&&installer_table_exists($pdo,'research_automation_runs');}
    catch(Throwable $e){return false;}
}

function research_automation_workflows(): array {
    return [
      'briefing'=>['label'=>'Research Briefing','description'=>'Generate a read-only evidence-aware project briefing.','requires_ai'=>true,'requires_write'=>false],
      'review'=>['label'=>'Research Review','description'=>'Review the project and optionally prepare Stage 15 action proposals for confirmation.','requires_ai'=>true,'requires_write'=>true],
      'source_refresh'=>['label'=>'Source Refresh','description'=>'Queue the project sources through the existing Source Monitor.','requires_ai'=>false,'requires_write'=>true],
      'cross_research_review'=>['label'=>'Cross-Research Review','description'=>'Review explainable relationships between this project and other accessible Research.','requires_ai'=>false,'requires_write'=>false],
    ];
}

function research_automation_cadences(): array {
    return ['hourly'=>'Hourly','daily'=>'Daily','weekly'=>'Weekly','manual'=>'Manual only'];
}

function research_automation_timezone(string $name): DateTimeZone {
    $name=trim($name);if($name==='')$name='UTC';
    try{return new DateTimeZone($name);}catch(Throwable $e){throw new InvalidArgumentException('Invalid schedule timezone.');}
}

function research_automation_time(string $value): string {
    $value=trim($value);if($value==='')return '09:00:00';
    if(preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/',$value,$m)){
        $h=(int)$m[1];$min=(int)$m[2];$sec=isset($m[3])?(int)$m[3]:0;
        if($h<=23&&$min<=59&&$sec<=59)return sprintf('%02d:%02d:%02d',$h,$min,$sec);
    }
    throw new InvalidArgumentException('Invalid local run time.');
}

function research_automation_next_run(string $cadence,string $timezoneName,string $runTime,?int $weekday,?DateTimeImmutable $fromUtc=null): ?string {
    if($cadence==='manual')return null;
    if(!in_array($cadence,['hourly','daily','weekly'],true))throw new InvalidArgumentException('Invalid automation cadence.');
    $utc=new DateTimeZone('UTC');$zone=research_automation_timezone($timezoneName);$fromUtc=$fromUtc?:new DateTimeImmutable('now',$utc);$fromUtc=$fromUtc->setTimezone($utc);
    if($cadence==='hourly')return $fromUtc->modify('+1 hour')->format('Y-m-d H:i:s');
    $local=$fromUtc->setTimezone($zone);$time=research_automation_time($runTime);[$h,$m,$s]=array_map('intval',explode(':',$time));
    $candidate=$local->setTime($h,$m,$s);
    if($cadence==='daily'){
        if($candidate<=$local)$candidate=$candidate->modify('+1 day');
        return $candidate->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    $weekday=$weekday===null?1:max(0,min(6,$weekday));$today=(int)$local->format('w');$delta=($weekday-$today+7)%7;
    if($delta>0)$candidate=$candidate->modify('+'.$delta.' days');
    if($candidate<=$local)$candidate=$candidate->modify('+7 days');
    return $candidate->setTimezone($utc)->format('Y-m-d H:i:s');
}

function research_automation_project(PDO $pdo,array $viewer,string $publicId,string $workflow): array {
    $defs=research_automation_workflows();if(!isset($defs[$workflow]))throw new InvalidArgumentException('Invalid automation workflow.');
    $project=project_access($pdo,(int)$viewer['id'],trim($publicId));if(!$project)throw new RuntimeException('Research project not found or unavailable.');
    if($defs[$workflow]['requires_write']&&!project_can_write($project))throw new RuntimeException('This workflow requires write access to the Research project.');
    if($defs[$workflow]['requires_ai']&&!agent_chat_available($pdo,$viewer))throw new RuntimeException('AI Research Automations require a Pro or administrator account.');
    return $project;
}

function research_automation_watch(PDO $pdo,array $viewer,?string $watchPublicId): array {
    $watchPublicId=trim((string)$watchPublicId);if($watchPublicId==='')throw new InvalidArgumentException('Choose an active Research watch for this trigger.');
    if(!proactive_intelligence_ready($pdo))throw new RuntimeException('Watch-triggered automations require Stage 17 Proactive Research Intelligence.');
    $q=$pdo->prepare("SELECT * FROM cognitive_watches WHERE public_id=? AND user_id=? AND status='active' LIMIT 1");$q->execute([$watchPublicId,$viewer['id']]);$watch=$q->fetch();
    if(!$watch)throw new RuntimeException('The selected Research watch is unavailable or paused.');
    return $watch;
}

function research_automation_active_count(PDO $pdo,int $userId): int {
    if(!research_automation_ready($pdo))return 0;$q=$pdo->prepare("SELECT COUNT(*) FROM research_automations WHERE user_id=? AND status<>'archived'");$q->execute([$userId]);return (int)$q->fetchColumn();
}

function research_automation_create(PDO $pdo,array $viewer,array $input): array {
    if(!research_automation_ready($pdo))throw new RuntimeException('Research Automation requires the Phase 18 database upgrade.');
    if(research_automation_active_count($pdo,(int)$viewer['id'])>=25)throw new RuntimeException('You can keep up to 25 active or paused Research Automations.');
    $title=mb_substr(trim((string)($input['title']??'')),0,190);if($title==='')throw new InvalidArgumentException('Automation title is required.');
    $workflow=strtolower(trim((string)($input['workflow_type']??'briefing')));$project=research_automation_project($pdo,$viewer,(string)($input['project_id']??''),$workflow);
    $trigger=in_array(($input['trigger_type']??'schedule'),['schedule','watch_alert'],true)?(string)$input['trigger_type']:'schedule';
    $cadence=strtolower(trim((string)($input['cadence']??'daily')));if(!isset(research_automation_cadences()[$cadence]))$cadence='daily';
    $timezone=trim((string)($input['timezone_name']??'UTC'));research_automation_timezone($timezone);
    $runTime=research_automation_time((string)($input['run_time_local']??'09:00'));$weekday=max(0,min(6,(int)($input['weekday']??1)));
    $prompt=mb_substr(trim((string)($input['prompt']??'')),0,4000);
    if($workflow==='briefing'&&$prompt==='')$prompt='Summarize meaningful changes, conflicting evidence, unresolved gaps, source risks, and the most useful next questions in this Research project.';
    if($workflow==='review'&&$prompt==='')$prompt='Review the current Research project for meaningful gaps, conflicts, weak evidence, and useful next actions. Propose bounded Research writes only when they would materially help.';
    if(in_array($workflow,['source_refresh','cross_research_review'],true))$prompt='';
    $watch=null;if($trigger==='watch_alert'){$watch=research_automation_watch($pdo,$viewer,(string)($input['watch_id']??''));$cadence='manual';}
    $next=$trigger==='schedule'?research_automation_next_run($cadence,$timezone,$runTime,$weekday):null;$public=ulid_like();
    $pdo->prepare("INSERT INTO research_automations(public_id,user_id,project_id,watch_id,title,workflow_type,trigger_type,cadence,timezone_name,run_time_local,weekday,prompt,status,next_run_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'active',?)")
      ->execute([$public,$viewer['id'],$project['id'],$watch['id']??null,$title,$workflow,$trigger,$cadence,$timezone,$runTime,$cadence==='weekly'?$weekday:null,$prompt!==''?$prompt:null,$next]);
    return research_automation_access($pdo,$viewer,$public)??[];
}

function research_automation_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_automation_ready($pdo))return null;
    $q=$pdo->prepare("SELECT ra.*,rp.public_id project_public_id,rp.title project_title,c.public_id conversation_public_id,cw.public_id watch_public_id,cw.watch_type,cw.query_text watch_query
      FROM research_automations ra JOIN research_projects rp ON rp.id=ra.project_id
      LEFT JOIN conversations c ON c.id=ra.conversation_id
      LEFT JOIN cognitive_watches cw ON cw.id=ra.watch_id
      WHERE ra.public_id=? AND ra.user_id=? LIMIT 1");
    $q->execute([trim($publicId),$viewer['id']]);$row=$q->fetch();if(!$row)return null;
    if(!project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']))return null;
    return $row;
}

function research_automation_list(PDO $pdo,array $viewer,int $limit=100): array {
    if(!research_automation_ready($pdo))return [];$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT public_id FROM research_automations WHERE user_id=? AND status<>'archived' ORDER BY status='active' DESC,updated_at DESC,id DESC LIMIT ".$limit);$q->execute([$viewer['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_automation_access($pdo,$viewer,(string)$id);if($row)$out[]=$row;}return $out;
}

function research_automation_update(PDO $pdo,array $viewer,string $publicId,array $input): array {
    $current=research_automation_access($pdo,$viewer,$publicId);if(!$current)throw new RuntimeException('Research Automation not found.');
    $title=mb_substr(trim((string)($input['title']??$current['title'])),0,190);if($title==='')throw new InvalidArgumentException('Automation title is required.');
    $workflow=strtolower(trim((string)($input['workflow_type']??$current['workflow_type'])));$projectPublic=(string)($input['project_id']??$current['project_public_id']);$project=research_automation_project($pdo,$viewer,$projectPublic,$workflow);
    $trigger=in_array(($input['trigger_type']??$current['trigger_type']),['schedule','watch_alert'],true)?(string)($input['trigger_type']??$current['trigger_type']):'schedule';
    $cadence=strtolower(trim((string)($input['cadence']??$current['cadence'])));if(!isset(research_automation_cadences()[$cadence]))$cadence='daily';
    $timezone=trim((string)($input['timezone_name']??$current['timezone_name']));research_automation_timezone($timezone);
    $runTime=research_automation_time((string)($input['run_time_local']??$current['run_time_local']??'09:00'));$weekday=max(0,min(6,(int)($input['weekday']??$current['weekday']??1)));
    $prompt=mb_substr(trim((string)($input['prompt']??$current['prompt']??'')),0,4000);if(in_array($workflow,['source_refresh','cross_research_review'],true))$prompt='';
    $watch=null;if($trigger==='watch_alert'){$watch=research_automation_watch($pdo,$viewer,(string)($input['watch_id']??$current['watch_public_id']??''));$cadence='manual';}
    $next=$trigger==='schedule'?research_automation_next_run($cadence,$timezone,$runTime,$weekday):null;
    $pdo->prepare("UPDATE research_automations SET project_id=?,watch_id=?,title=?,workflow_type=?,trigger_type=?,cadence=?,timezone_name=?,run_time_local=?,weekday=?,prompt=?,next_run_at=?,updated_at=NOW() WHERE id=? AND user_id=?")
      ->execute([$project['id'],$watch['id']??null,$title,$workflow,$trigger,$cadence,$timezone,$runTime,$cadence==='weekly'?$weekday:null,$prompt!==''?$prompt:null,$next,$current['id'],$viewer['id']]);
    return research_automation_access($pdo,$viewer,$publicId)??[];
}

function research_automation_set_status(PDO $pdo,array $viewer,string $publicId,string $status): bool {
    $row=research_automation_access($pdo,$viewer,$publicId);if(!$row)return false;if(!in_array($status,['active','paused','archived'],true))throw new InvalidArgumentException('Invalid automation status.');
    $next=$row['next_run_at'];
    if($status==='active'){
        research_automation_project($pdo,$viewer,(string)$row['project_public_id'],(string)$row['workflow_type']);
        if($row['trigger_type']==='watch_alert')research_automation_watch($pdo,$viewer,(string)($row['watch_public_id']??''));
        if($row['trigger_type']==='schedule')$next=research_automation_next_run((string)$row['cadence'],(string)$row['timezone_name'],(string)$row['run_time_local'],isset($row['weekday'])?(int)$row['weekday']:null);
    }
    if($status!=='active')$next=null;
    $q=$pdo->prepare('UPDATE research_automations SET status=?,next_run_at=?,updated_at=NOW() WHERE id=? AND user_id=?');$q->execute([$status,$next,$row['id'],$viewer['id']]);return $q->rowCount()===1;
}

function research_automation_enqueue(PDO $pdo,array $automation,string $triggerType,string $triggerKey,?string $scheduledFor=null,?string $triggerNotification=null): ?string {
    $public=ulid_like();$hash=hash('sha256',(string)$automation['id'].'|'.$triggerType.'|'.$triggerKey);
    try{
        $q=$pdo->prepare("INSERT IGNORE INTO research_automation_runs(public_id,automation_id,user_id,project_id,trigger_type,trigger_key,trigger_notification_public_id,scheduled_for,available_at)
          VALUES(?,?,?,?,?,?,?,?,NOW())");
        $q->execute([$public,$automation['id'],$automation['user_id'],$automation['project_id'],$triggerType,$hash,$triggerNotification,$scheduledFor]);
        if($q->rowCount()!==1)return null;return $public;
    }catch(PDOException $e){return null;}
}

function research_automation_enqueue_manual(PDO $pdo,array $viewer,string $publicId): string {
    $row=research_automation_access($pdo,$viewer,$publicId);if(!$row||$row['status']==='archived')throw new RuntimeException('Research Automation not found.');
    $id=research_automation_enqueue($pdo,$row,'manual',ulid_like(),null,null);if(!$id)throw new RuntimeException('Unable to queue Research Automation.');return $id;
}

function research_automation_enqueue_due(PDO $pdo,int $limit=50): int {
    if(!research_automation_ready($pdo))return 0;$limit=max(1,min(500,$limit));$count=0;
    for($i=0;$i<$limit;$i++){
        $pdo->beginTransaction();
        try{
            $q=$pdo->query("SELECT * FROM research_automations WHERE status='active' AND trigger_type='schedule' AND cadence<>'manual' AND next_run_at IS NOT NULL AND next_run_at<=NOW() ORDER BY next_run_at,id LIMIT 1 FOR UPDATE");
            $row=$q->fetch();if(!$row){$pdo->commit();break;}
            $scheduled=(string)$row['next_run_at'];$run=research_automation_enqueue($pdo,$row,'schedule',$scheduled,$scheduled,null);
            $from=new DateTimeImmutable($scheduled,new DateTimeZone('UTC'));$next=research_automation_next_run((string)$row['cadence'],(string)$row['timezone_name'],(string)$row['run_time_local'],isset($row['weekday'])?(int)$row['weekday']:null,$from);
            $pdo->prepare('UPDATE research_automations SET next_run_at=?,last_triggered_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$next,$row['id']]);
            $pdo->commit();if($run!==null)$count++;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    return $count;
}

function research_automation_enqueue_watch_alerts(PDO $pdo,int $limit=100): int {
    if(!research_automation_ready($pdo)||!proactive_intelligence_ready($pdo))return 0;$limit=max(1,min(500,$limit));$count=0;
    $q=$pdo->query("SELECT ra.*,cw.public_id watch_public_id FROM research_automations ra JOIN cognitive_watches cw ON cw.id=ra.watch_id WHERE ra.status='active' AND ra.trigger_type='watch_alert' AND cw.status='active' ORDER BY ra.id LIMIT ".$limit);
    foreach($q->fetchAll() as $automation){
        $n=$pdo->prepare("SELECT id,public_id FROM notifications WHERE user_id=? AND notification_type='research_proactive' AND id>? AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.watch_public_id'))=? ORDER BY id ASC LIMIT 20");
        $n->execute([$automation['user_id'],$automation['last_trigger_notification_id'],$automation['watch_public_id']]);$last=(int)$automation['last_trigger_notification_id'];
        foreach($n->fetchAll() as $notification){$last=max($last,(int)$notification['id']);if(research_automation_enqueue($pdo,$automation,'watch_alert',(string)$notification['public_id'],null,(string)$notification['public_id'])!==null)$count++;}
        if($last>(int)$automation['last_trigger_notification_id'])$pdo->prepare('UPDATE research_automations SET last_trigger_notification_id=?,last_triggered_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$last,$automation['id']]);
    }
    return $count;
}

function research_automation_claim(PDO $pdo): ?array {
    if(!research_automation_ready($pdo))return null;
    return job_claim($pdo,'research_automation_runs',"SELECT * FROM research_automation_runs WHERE status='queued' AND available_at<=NOW() ORDER BY available_at,id LIMIT 1",[],1200);
}

function research_automation_run_row(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_automation_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rar.*,ra.public_id automation_public_id,ra.title automation_title,ra.workflow_type,rp.public_id project_public_id,rp.title project_title
      FROM research_automation_runs rar JOIN research_automations ra ON ra.id=rar.automation_id JOIN research_projects rp ON rp.id=rar.project_id
      WHERE rar.public_id=? AND rar.user_id=? LIMIT 1");$q->execute([trim($publicId),$viewer['id']]);$row=$q->fetch();if(!$row)return null;
    if(!project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']))return null;return $row;
}

function research_automation_run_list(PDO $pdo,array $viewer,?string $automationPublicId=null,int $limit=50): array {
    if(!research_automation_ready($pdo))return [];$limit=max(1,min(200,$limit));$params=[$viewer['id']];$where='rar.user_id=?';
    if($automationPublicId!==null&&trim($automationPublicId)!==''){$a=research_automation_access($pdo,$viewer,$automationPublicId);if(!$a)return [];$where.=' AND rar.automation_id=?';$params[]=$a['id'];}
    $q=$pdo->prepare("SELECT rar.public_id FROM research_automation_runs rar WHERE $where ORDER BY rar.id DESC LIMIT ".$limit);$q->execute($params);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_automation_run_row($pdo,$viewer,(string)$id);if($row)$out[]=$row;}return $out;
}

function research_automation_conversation(PDO $pdo,array $viewer,array $automation): array {
    $existing=trim((string)($automation['conversation_public_id']??''));
    if($existing!==''){$c=agent_chat_access($pdo,$viewer,$existing);if($c)return $c;}
    $c=agent_chat_create($pdo,$viewer,'Automation · '.mb_substr((string)$automation['title'],0,165));
    $pdo->prepare('UPDATE research_automations SET conversation_id=?,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$c['id'],$automation['id'],$viewer['id']]);return $c;
}

function research_automation_previous_hash(PDO $pdo,int $automationId): ?string {
    $q=$pdo->prepare("SELECT input_hash FROM research_automation_runs WHERE automation_id=? AND status='completed' AND input_hash IS NOT NULL ORDER BY id DESC LIMIT 1");$q->execute([$automationId]);$v=$q->fetchColumn();return $v!==false?(string)$v:null;
}

function research_automation_notify(PDO $pdo,array $viewer,array $automation,array $run,string $type,string $body,array $context=[]): ?string {
    $dedupe=$type.':'.(string)$run['public_id'];$context=array_merge(['automation_public_id'=>$automation['public_id'],'run_public_id'=>$run['public_id'],'project_public_id'=>$automation['project_public_id']],$context);
    notification_create($pdo,(int)$viewer['id'],null,$type,'research_automation',(string)$automation['public_id'],$body,['allow_self'=>true,'dedupe_key'=>$dedupe,'group_key'=>'research-automation:'.$automation['public_id'],'context'=>$context]);
    $q=$pdo->prepare('SELECT public_id FROM notifications WHERE user_id=? AND dedupe_key=? LIMIT 1');$q->execute([$viewer['id'],$dedupe]);$v=$q->fetchColumn();return $v!==false?(string)$v:null;
}

function research_automation_source_refresh(PDO $pdo,array $project): string {
    $q=$pdo->prepare("SELECT s.id FROM project_sources ps JOIN sources s ON s.id=ps.source_id WHERE ps.project_id=? AND s.monitoring_enabled=1 ORDER BY ps.created_at");$q->execute([$project['id']]);$queued=0;$already=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $sourceId){
        $x=$pdo->prepare("SELECT 1 FROM source_monitor_jobs WHERE source_id=? AND status IN ('queued','processing') LIMIT 1");$x->execute([$sourceId]);if($x->fetchColumn()){$already++;continue;}
        $pdo->prepare("INSERT INTO source_monitor_jobs(source_id,priority,status,scheduled_at) VALUES(?,5,'queued',NOW())")->execute([$sourceId]);$queued++;
    }
    return 'Queued '.$queued.' project source'.($queued===1?'':'s').' for monitoring'.($already?' ('.$already.' already queued or processing).':'.');
}

function research_automation_execute(PDO $pdo,array $config,array $run): array {
    $aq=$pdo->prepare("SELECT ra.public_id FROM research_automations ra WHERE ra.id=? LIMIT 1");$aq->execute([$run['automation_id']]);$automationPublic=(string)($aq->fetchColumn()?:'');if($automationPublic==='')throw new RuntimeException('Automation no longer exists.');
    $uq=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$uq->execute([$run['user_id']]);$viewer=$uq->fetch();if(!$viewer)return ['status'=>'skipped','output'=>'Automation owner is no longer active.','proposals'=>0,'conversation'=>null,'ai_run'=>null,'input_hash'=>null];
    $automation=research_automation_access($pdo,$viewer,$automationPublic);if(!$automation||$automation['status']==='archived'||($run['trigger_type']!=='manual'&&$automation['status']!=='active'))return ['status'=>'skipped','output'=>'Automation is paused, archived, or no longer accessible for this trigger.','proposals'=>0,'conversation'=>null,'ai_run'=>null,'input_hash'=>null];
    $project=research_automation_project($pdo,$viewer,(string)$automation['project_public_id'],(string)$automation['workflow_type']);
    $inputHash=$automation['workflow_type']==='cross_research_review'&&function_exists('cross_research_input_hash')?cross_research_input_hash($pdo,$viewer,(string)$automation['project_public_id']):(function_exists('research_workspace_input_hash')?research_workspace_input_hash($pdo,(int)$project['id']):hash('sha256',$project['updated_at']??$project['public_id']));
    if($automation['workflow_type']!=='source_refresh'&&$run['trigger_type']==='schedule'){
        $previous=research_automation_previous_hash($pdo,(int)$automation['id']);
        if($previous!==null&&hash_equals($previous,$inputHash))return ['status'=>'skipped','output'=>'No Research state changes since the previous completed run.','proposals'=>0,'conversation'=>$automation['conversation_public_id']??null,'ai_run'=>null,'input_hash'=>$inputHash];
    }
    if($automation['workflow_type']==='source_refresh'){
        $output=research_automation_source_refresh($pdo,$project);return ['status'=>'completed','output'=>$output,'proposals'=>0,'conversation'=>null,'ai_run'=>null,'input_hash'=>$inputHash];
    }
    if($automation['workflow_type']==='cross_research_review'){
        if(!function_exists('cross_research_context')||!cross_research_ready($pdo))throw new RuntimeException('Cross-Research Intelligence is unavailable.');
        $cross=cross_research_context($pdo,$viewer,(string)$automation['project_public_id'],20);$lines=['Cross-Research review for '.$project['title'].'.'];
        if(empty($cross['suggestions']))$lines[]='No undecided explainable Cross-Research suggestions are currently available.';
        else foreach(array_slice((array)$cross['suggestions'],0,12) as $s)$lines[]='- '.$s['title'].' · '.$s['source_project_title'].' ↔ '.$s['target_project_title'].' · '.implode(' ',$s['reasons']);
        if(!empty($cross['links']))$lines[]='Accepted Cross-Research links: '.count($cross['links']).'.';
        return ['status'=>'completed','output'=>implode("\n",$lines),'proposals'=>0,'conversation'=>null,'ai_run'=>null,'input_hash'=>$inputHash];
    }

    $context=agent_chat_context_normalize($pdo,$viewer,[['type'=>'research','public_id'=>$automation['project_public_id']]]);if(!$context)throw new RuntimeException('Research project context is unavailable.');
    $conversation=research_automation_conversation($pdo,$viewer,$automation);$prompt=trim((string)$automation['prompt']);if($prompt==='')throw new RuntimeException('Automation prompt is empty.');
    $messageBody="[Research Automation: ".$automation['title']."]\n".$prompt;$client='automation-'.(string)$run['public_id'];
    $userMessage=conversation_message_create($pdo,$viewer,(string)$conversation['public_id'],$messageBody,null,$client);
    if($userMessage['created'])foreach($context as $item)$pdo->prepare('INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,?,?,?)')->execute([$userMessage['id'],$item['type'],$item['public_id'],json_encode(['label'=>$item['label'],'automation_id'=>$automation['public_id']],JSON_UNESCAPED_SLASHES)]);
    $isAdmin=($viewer['role']??'')==='admin';$model=$isAdmin?ai_setting_model_id($pdo,'admin',false):ai_setting_model_id($pdo,'pro',true);if(!$model)$model=ai_setting_model_id($pdo,'research',true);if(!$model)throw new RuntimeException('No AI model is configured for Research Automation.');ai_interactive_model_record($pdo,$viewer,$model);
    $contextText=implode("\n\n",array_map(fn($x)=>$x['text'],$context));$refs=[];foreach($context as $item)foreach($item['refs'] as $ref)$refs[]=$ref;
    $system='You are Annotated Research Automation. Work only from the attached permission-checked Research context. Distinguish evidence from inference, cite Annotated IDs, never invent source text or object IDs, and be concise.';
    if($automation['workflow_type']==='briefing')$system.=" This is a read-only briefing. Do not propose or claim to execute any Research write.";
    else{
        $system.=" You may PREPARE bounded Research write proposals, but you cannot execute them. If a write would materially help, append exactly one machine-readable block at the END using <<ANNOTATED_ACTIONS>> followed by a JSON array. Never claim a proposal was executed. Every write requires explicit user confirmation in Annotated. Only use project ID ".$automation['project_public_id'].". Capabilities:\n".agent_action_capability_prompt();
    }
    $aiPrompt="AUTOMATION REQUEST:\n".$prompt."\n\nCURRENT ANNOTATED RESEARCH CONTEXT:\n".$contextText;
    $ai=ai_run($pdo,$config,$viewer,$isAdmin?'admin':'pro','research_automation_'.$automation['workflow_type'],$model,$system,$aiPrompt,array_merge($refs,[['type'=>'research_automation','id'=>$automation['public_id']]]),'research_automation',$automation['public_id']);
    $parsed=agent_action_extract((string)$ai['text']);$assistant=agent_chat_insert_agent_message($pdo,$conversation,(string)$parsed['body'],(int)$userMessage['id']);$proposals=[];
    if($automation['workflow_type']==='review'&&agent_actions_ready($pdo))$proposals=agent_action_create_proposals($pdo,$viewer,$conversation,(int)$assistant['id'],$context,(array)$parsed['actions'],$refs);
    return ['status'=>'completed','output'=>(string)$parsed['body'],'proposals'=>count($proposals),'conversation'=>$conversation['public_id'],'ai_run'=>$ai['public_id'],'input_hash'=>$inputHash];
}

function research_automation_complete_run(PDO $pdo,array $viewer,array $automation,array $run,array $result): void {
    $status=(string)$result['status'];if(!in_array($status,['completed','skipped'],true))throw new InvalidArgumentException('Invalid automation result status.');
    $pdo->prepare("UPDATE research_automation_runs SET input_hash=?,output_text=?,ai_run_public_id=?,conversation_public_id=?,proposal_count=? WHERE id=? AND status='processing' AND claim_token=?")
      ->execute([$result['input_hash'],$result['output'],$result['ai_run'],$result['conversation'],$result['proposals'],$run['id'],$run['claim_token']]);
    job_claim_complete($pdo,'research_automation_runs',(int)$run['id'],(string)$run['claim_token'],$status);
    if($status==='completed')$pdo->prepare("UPDATE research_automations SET last_run_at=NOW(),run_count=run_count+1,failure_count=0,updated_at=NOW() WHERE id=?")->execute([$automation['id']]);
    else $pdo->prepare("UPDATE research_automations SET last_run_at=NOW(),run_count=run_count+1,updated_at=NOW() WHERE id=?")->execute([$automation['id']]);
    if($status==='completed'){
        $body=$automation['title'].' completed.'.((int)$result['proposals']>0?' '.(int)$result['proposals'].' Research action proposal'.((int)$result['proposals']===1?' is':'s are').' waiting for confirmation.':'');
        $notification=research_automation_notify($pdo,$viewer,$automation,$run,'research_automation_completed',$body,['conversation_public_id'=>$result['conversation'],'proposal_count'=>(int)$result['proposals']]);
        if($notification)$pdo->prepare('UPDATE research_automation_runs SET notification_public_id=? WHERE id=?')->execute([$notification,$run['id']]);
    }
}

function research_automation_fail_run(PDO $pdo,array $viewer,array $automation,array $run,Throwable $e): string {
    $status=job_claim_retry_or_fail($pdo,'research_automation_runs',(int)$run['id'],(string)$run['claim_token'],$e->getMessage(),(int)$run['attempts'],3,120);
    if($status==='failed'){
        $pdo->prepare('UPDATE research_automations SET failure_count=failure_count+1,last_run_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$automation['id']]);
        $q=$pdo->prepare('SELECT failure_count FROM research_automations WHERE id=?');$q->execute([$automation['id']]);$failures=(int)$q->fetchColumn();$paused=false;
        if($failures>=3){$pdo->prepare("UPDATE research_automations SET status='paused',next_run_at=NULL,updated_at=NOW() WHERE id=?")->execute([$automation['id']]);$paused=true;}
        research_automation_notify($pdo,$viewer,$automation,$run,'research_automation_failed',$automation['title'].' failed'.($paused?' repeatedly and was paused.':'.'),['error'=>mb_substr($e->getMessage(),0,500),'paused'=>$paused]);
    }
    return $status;
}
