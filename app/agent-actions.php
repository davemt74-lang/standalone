<?php
declare(strict_types=1);

require_once __DIR__.'/research-workspace.php';

final class AgentActionStale extends RuntimeException {}
final class AgentActionForbidden extends RuntimeException {}

function agent_actions_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'agent_action_proposals')&&installer_table_exists($pdo,'agent_action_events');}
    catch(Throwable $e){return false;}
}

function agent_action_capabilities(): array {
    return [
      'research.create_task'=>[
        'label'=>'Create research task','description'=>'Create a bounded durable task inside the current Research Agent task system.',
        'arguments'=>['title'=>'string','description'=>'string optional','task_type'=>'general|find_source|verify_claim|review_source_change|compare_sources|synthesize|draft_deliverable|follow_up','priority'=>'low|medium|high|urgent optional','due_at'=>'date/time optional']
      ],
      'research.create_plan'=>[
        'label'=>'Create research plan','description'=>'Create a versioned Research plan with dependent tasks and a living deliverable. The user must confirm the plan before it is created.',
        'arguments'=>['title'=>'string','objective'=>'string','priority'=>'low|medium|high|urgent optional','due_at'=>'date/time optional','deliverable_type'=>'research_brief|competitive_analysis|due_diligence|source_digest|timeline|comparison|weekly_report|report|analysis|document','deliverable_title'=>'string optional','tasks'=>'array of task objects with title,description,task_type,priority,depends_on index array optional']
      ],
      'research.create_note'=>[
        'label'=>'Create research note','description'=>'Save a concise Research note to the current project.',
        'arguments'=>['body'=>'string']
      ],
      'research.create_document'=>[
        'label'=>'Create research document','description'=>'Create a durable editable Research document in the current Research Agent workspace. The completed document is posted back into the Agent conversation as a document card.',
        'arguments'=>['title'=>'string','body'=>'string','summary'=>'string optional','document_type'=>'document|research_brief|memo|report|analysis|source_summary|timeline|weekly_report']
      ],
      'research.create_sticky'=>[
        'label'=>'Create sticky note','description'=>'Pin a concise colored sticky note to the current Research Agent canvas after user confirmation.',
        'arguments'=>['body'=>'string','color'=>'yellow|pink|blue|green|purple|gray optional']
      ],
      'research.create_claim'=>[
        'label'=>'Create claim','description'=>'Create a new unverified Claim from the current evidence/context.',
        'arguments'=>['statement'=>'string','claim_type'=>'factual|disputed|prediction|interpretation|data_point']
      ],
      'research.attach_annotation_evidence'=>[
        'label'=>'Attach annotation evidence','description'=>'Attach an accessible annotation as evidence to an existing project Claim.',
        'arguments'=>['claim_id'=>'Annotated Claim public ID','annotation_id'=>'Annotated Annotation public ID','relationship'=>'supports|contradicts|context|primary','note'=>'string optional']
      ],
      'research.create_finding'=>[
        'label'=>'Create finding','description'=>'Create a draft Finding, optionally linked to existing project Claims.',
        'arguments'=>['title'=>'string','summary'=>'string','claim_ids'=>'array of Claim public IDs optional']
      ],
      'research.link_claims'=>[
        'label'=>'Link claims','description'=>'Create a typed relationship between two existing Claims in the project.',
        'arguments'=>['source_claim_id'=>'Claim public ID','target_claim_id'=>'Claim public ID','relation_type'=>'supports|contradicts|depends_on|refines|duplicates|context','note'=>'string optional']
      ],
    ];
}

function agent_action_capability_prompt(): string {
    $parts=[];
    foreach(agent_action_capabilities() as $key=>$cap){
        $args=[];foreach($cap['arguments'] as $name=>$type)$args[]=$name.': '.$type;
        $parts[]=$key.' — '.$cap['description'].' Arguments: '.implode(', ',$args).'.';
    }
    return implode("\n",$parts);
}

function agent_action_extract(string $text): array {
    $marker='<<ANNOTATED_ACTIONS>>';$pos=strrpos($text,$marker);
    if($pos===false)return ['body'=>trim($text),'actions'=>[]];
    $body=trim(substr($text,0,$pos));$json=trim(substr($text,$pos+strlen($marker)));
    if(str_starts_with($json,'```')){$json=preg_replace('/^```(?:json)?\s*/i','',$json)??$json;$json=preg_replace('/\s*```$/','',$json)??$json;}
    $actions=json_decode($json,true);
    if(!is_array($actions))return ['body'=>$body!==''?$body:'I was unable to prepare a valid Research action proposal.','actions'=>[]];
    if(array_is_list($actions)===false)$actions=[$actions];
    return ['body'=>$body!==''?$body:'I prepared the following Research action for your review.','actions'=>array_slice($actions,0,6)];
}

function agent_action_project_from_context(PDO $pdo,array $viewer,array $context): ?array {
    $projects=[];
    foreach($context as $item)if(($item['type']??'')==='research'){
        $p=project_access($pdo,(int)$viewer['id'],(string)$item['public_id']);if($p)$projects[(string)$p['public_id']]=$p;
    }
    return count($projects)===1?array_values($projects)[0]:null;
}

function agent_action_project_map(PDO $pdo,array $viewer,array $context): array {
    $projects=[];
    foreach($context as $item)if(($item['type']??'')==='research'){
        $p=project_access($pdo,(int)$viewer['id'],(string)$item['public_id']);if($p)$projects[(string)$p['public_id']]=$p;
    }
    return $projects;
}

function agent_action_clean_arguments(string $capability,array $args): array {
    $s=fn($v,$max)=>mb_substr(trim((string)$v),0,$max);
    if($capability==='research.create_task'){
        $title=$s($args['title']??'',255);if($title==='')throw new InvalidArgumentException('Task title is required.');
        $type=(string)($args['task_type']??'general');if(!isset(research_task_types()[$type]))$type='general';
        $priority=(string)($args['priority']??'medium');if(!isset(research_task_priorities()[$priority]))$priority='medium';
        return ['title'=>$title,'description'=>$s($args['description']??'',12000),'task_type'=>$type,'priority'=>$priority,'due_at'=>$s($args['due_at']??'',80)];
    }
    if($capability==='research.create_plan'){
        $title=$s($args['title']??'',255);$objective=$s($args['objective']??'',16000);if($title===''||$objective==='')throw new InvalidArgumentException('Plan title and objective are required.');
        $priority=(string)($args['priority']??'medium');if(!isset(research_task_priorities()[$priority]))$priority='medium';
        $deliverable=(string)($args['deliverable_type']??'research_brief');if(!isset(research_task_deliverable_types()[$deliverable]))$deliverable='research_brief';
        $tasks=[];foreach(array_slice(is_array($args['tasks']??null)?$args['tasks']:[],0,20) as $raw){if(!is_array($raw))continue;$taskTitle=$s($raw['title']??'',255);if($taskTitle==='')continue;$type=(string)($raw['task_type']??'general');if(!isset(research_task_types()[$type]))$type='general';$p=(string)($raw['priority']??$priority);if(!isset(research_task_priorities()[$p]))$p=$priority;$deps=[];foreach(array_slice((array)($raw['depends_on']??[]),0,12) as $d)$deps[]=max(0,(int)$d);$tasks[]=['title'=>$taskTitle,'description'=>$s($raw['description']??'',12000),'task_type'=>$type,'priority'=>$p,'depends_on'=>array_values(array_unique($deps))];}
        if(!$tasks)throw new InvalidArgumentException('A Research plan needs at least one task.');
        return ['title'=>$title,'objective'=>$objective,'priority'=>$priority,'due_at'=>$s($args['due_at']??'',80),'deliverable_type'=>$deliverable,'deliverable_title'=>$s($args['deliverable_title']??'',255),'tasks'=>$tasks];
    }
    if($capability==='research.create_note'){
        $body=$s($args['body']??'',10000);if($body==='')throw new InvalidArgumentException('Research note body is required.');return ['body'=>$body];
    }
    if($capability==='research.create_document'){
        $title=$s($args['title']??'',240);if($title==='')throw new InvalidArgumentException('Document title is required.');
        $body=$s($args['body']??'',60000);if($body==='')throw new InvalidArgumentException('Document body is required.');
        $summary=$s($args['summary']??'',5000);$type=research_agent_workspace_document_type((string)($args['document_type']??'document'));
        return ['title'=>$title,'body'=>$body,'summary'=>$summary,'document_type'=>$type];
    }
    if($capability==='research.create_sticky'){
        $body=$s($args['body']??'',10000);if($body==='')throw new InvalidArgumentException('Sticky note body is required.');
        return ['body'=>$body,'color'=>research_agent_workspace_sticky_color((string)($args['color']??'yellow'))];
    }
    if($capability==='research.create_claim'){
        $statement=$s($args['statement']??'',8000);if($statement==='')throw new InvalidArgumentException('Claim statement is required.');
        $type=(string)($args['claim_type']??'factual');if(!in_array($type,['factual','disputed','prediction','interpretation','data_point'],true))$type='factual';
        return ['statement'=>$statement,'claim_type'=>$type];
    }
    if($capability==='research.attach_annotation_evidence'){
        $claim=$s($args['claim_id']??'',64);$annotation=$s($args['annotation_id']??'',64);if($claim===''||$annotation==='')throw new InvalidArgumentException('Claim and Annotation IDs are required.');
        $rel=(string)($args['relationship']??'supports');if(!in_array($rel,['supports','contradicts','context','primary'],true))$rel='supports';
        return ['claim_id'=>$claim,'annotation_id'=>$annotation,'relationship'=>$rel,'note'=>$s($args['note']??'',2000)];
    }
    if($capability==='research.create_finding'){
        $title=$s($args['title']??'',250);$summary=$s($args['summary']??'',12000);if($title===''||$summary==='')throw new InvalidArgumentException('Finding title and summary are required.');
        $ids=[];foreach(array_slice(is_array($args['claim_ids']??null)?$args['claim_ids']:[],0,12) as $id){$id=$s($id,64);if($id!==''&&!in_array($id,$ids,true))$ids[]=$id;}
        return ['title'=>$title,'summary'=>$summary,'claim_ids'=>$ids];
    }
    if($capability==='research.link_claims'){
        $source=$s($args['source_claim_id']??'',64);$target=$s($args['target_claim_id']??'',64);if($source===''||$target===''||$source===$target)throw new InvalidArgumentException('Two distinct Claim IDs are required.');
        $type=(string)($args['relation_type']??'context');if(!in_array($type,['supports','contradicts','depends_on','refines','duplicates','context'],true))$type='context';
        return ['source_claim_id'=>$source,'target_claim_id'=>$target,'relation_type'=>$type,'note'=>$s($args['note']??'',2000)];
    }
    throw new InvalidArgumentException('Unsupported Agent capability.');
}

function agent_action_event(PDO $pdo,int $proposalId,string $event,?int $actorUserId,array $payload=[]): void {
    $pdo->prepare('INSERT INTO agent_action_events(proposal_id,event_type,actor_user_id,payload_json) VALUES(?,?,?,?)')
      ->execute([$proposalId,$event,$actorUserId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function agent_action_proposal_row(PDO $pdo,array $viewer,string $publicId): ?array {
    $q=$pdo->prepare("SELECT aap.*,rp.public_id project_public_id,rp.title project_title
      FROM agent_action_proposals aap JOIN research_projects rp ON rp.id=aap.project_id
      WHERE aap.public_id=? AND aap.proposed_by_user_id=? LIMIT 1");
    $q->execute([$publicId,$viewer['id']]);$r=$q->fetch();if(!$r)return null;
    $r['arguments']=json_decode((string)$r['arguments_json'],true)?:[];$r['provenance']=json_decode((string)$r['provenance_json'],true)?:[];$r['result']=json_decode((string)($r['result_json']??''),true)?:null;
    unset($r['arguments_json'],$r['provenance_json'],$r['result_json']);return $r;
}

function agent_action_message_proposals(PDO $pdo,array $viewer,int $messageId): array {
    if(!agent_actions_ready($pdo))return [];
    $q=$pdo->prepare("SELECT public_id FROM agent_action_proposals WHERE assistant_message_id=? AND proposed_by_user_id=? ORDER BY id");
    $q->execute([$messageId,$viewer['id']]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$r=agent_action_proposal_row($pdo,$viewer,(string)$id);if($r)$out[]=$r;}return $out;
}

function agent_action_ref_set(array $refs): array {
    $set=[];foreach($refs as $ref){if(!is_array($ref))continue;$type=strtolower(trim((string)($ref['type']??'')));$id=trim((string)($ref['id']??''));if($type!==''&&$id!=='')$set[$type.':'.$id]=true;}return $set;
}
function agent_action_validate_project_arguments(PDO $pdo,array $viewer,array $project,string $capability,array $args,array $refs): bool {
    $projectId=(int)$project['id'];$seen=agent_action_ref_set($refs);
    if($capability==='research.attach_annotation_evidence'){
        if(!isset($seen['claim:'.$args['claim_id']],$seen['annotation:'.$args['annotation_id']]))return false;
        if(!agent_action_claim_row($pdo,$projectId,(string)$args['claim_id']))return false;
        return annotation_access($pdo,(string)$args['annotation_id'],$viewer)!==null;
    }
    if($capability==='research.create_finding'){
        foreach((array)$args['claim_ids'] as $id)if(!isset($seen['claim:'.$id])||!agent_action_claim_row($pdo,$projectId,(string)$id))return false;
        return true;
    }
    if($capability==='research.link_claims'){
        if(!isset($seen['claim:'.$args['source_claim_id']],$seen['claim:'.$args['target_claim_id']]))return false;
        return agent_action_claim_row($pdo,$projectId,(string)$args['source_claim_id'])!==null&&agent_action_claim_row($pdo,$projectId,(string)$args['target_claim_id'])!==null;
    }
    return true;
}

function agent_action_create_proposals(PDO $pdo,array $viewer,array $conversation,int $assistantMessageId,array $context,array $rawActions,array $refs): array {
    if(!agent_actions_ready($pdo)||!$rawActions)return [];$capabilities=agent_action_capabilities();$projects=agent_action_project_map($pdo,$viewer,$context);if(!$projects)return [];
    $out=[];
    foreach(array_slice($rawActions,0,6) as $raw){
        if(!is_array($raw))continue;$cap=(string)($raw['capability']??'');if(!isset($capabilities[$cap]))continue;
        $projectPublic=trim((string)($raw['project_id']??''));if($projectPublic===''&&count($projects)===1)$projectPublic=(string)array_key_first($projects);
        $project=$projects[$projectPublic]??null;if(!$project||!project_can_write($project))continue;
        try{$args=agent_action_clean_arguments($cap,is_array($raw['arguments']??null)?$raw['arguments']:[]);}catch(Throwable $e){continue;}
        if(!agent_action_validate_project_arguments($pdo,$viewer,$project,$cap,$args,$refs))continue;
        $hash=research_workspace_input_hash($pdo,(int)$project['id']);$argsJson=json_encode($args,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $provenance=['refs'=>$refs,'conversation_id'=>$conversation['public_id'],'assistant_message_id'=>$assistantMessageId,'project_id'=>$projectPublic];
        $dedupe=hash('sha256',$viewer['id'].'|'.$conversation['id'].'|'.$assistantMessageId.'|'.$project['id'].'|'.$cap.'|'.$argsJson);
        $public=ulid_like();
        $insert=$pdo->prepare("INSERT IGNORE INTO agent_action_proposals(public_id,conversation_id,assistant_message_id,proposed_by_user_id,project_id,capability_key,arguments_json,provenance_json,project_state_hash,dedupe_key,expires_at)
          VALUES(?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))");
        $insert->execute([$public,$conversation['id'],$assistantMessageId,$viewer['id'],$project['id'],$cap,$argsJson,json_encode($provenance,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$hash,$dedupe]);
        if($insert->rowCount()>0){$proposalId=(int)$pdo->lastInsertId();agent_action_event($pdo,$proposalId,'proposed',(int)$viewer['id'],['capability'=>$cap]);$row=agent_action_proposal_row($pdo,$viewer,$public);if($row)$out[]=$row;}
        else{$q=$pdo->prepare('SELECT public_id FROM agent_action_proposals WHERE dedupe_key=?');$q->execute([$dedupe]);$existing=(string)($q->fetchColumn()?:'');if($existing){$row=agent_action_proposal_row($pdo,$viewer,$existing);if($row)$out[]=$row;}}
    }
    return $out;
}

function agent_action_claim_row(PDO $pdo,int $projectId,string $publicId): ?array {
    $q=$pdo->prepare('SELECT * FROM research_claims WHERE project_id=? AND public_id=? LIMIT 1');$q->execute([$projectId,$publicId]);return $q->fetch()?:null;
}

function agent_action_execute_capability(PDO $pdo,array $viewer,array $project,string $capability,array $args): array {
    $projectId=(int)$project['id'];$userId=(int)$viewer['id'];
    if($capability==='research.create_task'){
        if(function_exists('research_tasks_ready')&&research_tasks_ready($pdo)){
            $task=research_task_create_for_project($pdo,$viewer,$project,$args,true);
            return ['type'=>'task','public_id'=>(string)$task['public_id'],'label'=>(string)$task['title'],'url'=>'/research-tasks.php?agent='.rawurlencode((string)$task['agent_public_id']).'&task='.rawurlencode((string)$task['public_id'])];
        }
        $public=ulid_like();$pdo->prepare("INSERT INTO research_tasks(public_id,project_id,created_by_user_id,title,description,task_type,status) VALUES(?,?,?,?,?,?,'open')")
          ->execute([$public,$projectId,$userId,$args['title'],$args['description']!==''?$args['description']:null,$args['task_type']]);
        return ['type'=>'task','public_id'=>$public,'label'=>$args['title'],'url'=>'/research-project.php?id='.rawurlencode((string)$project['public_id']).'#tasks'];
    }
    if($capability==='research.create_plan'){
        if(!function_exists('research_tasks_ready')||!research_tasks_ready($pdo))throw new RuntimeException('Research Plans require the latest database upgrade.');
        $agent=research_task_agent_for_project($pdo,$viewer,(string)$project['public_id']);if(!$agent)throw new RuntimeException('This project has no active Research Agent.');
        $args['agent_id']=$agent['public_id'];$plan=research_task_plan_create($pdo,$viewer,$args,true);
        return ['type'=>'research_plan','public_id'=>(string)$plan['public_id'],'label'=>(string)$plan['title'],'url'=>'/research-tasks.php?agent='.rawurlencode((string)$agent['public_id']).'&plan='.rawurlencode((string)$plan['public_id'])];
    }
    if($capability==='research.create_note'){
        $public=ulid_like();$pdo->prepare('INSERT INTO research_notes(public_id,project_id,user_id,body) VALUES(?,?,?,?)')->execute([$public,$projectId,$userId,$args['body']]);
        return ['type'=>'note','public_id'=>$public,'label'=>'Research note','url'=>'/research-project.php?id='.rawurlencode((string)$project['public_id']).'#notes'];
    }
    if($capability==='research.create_document'){
        if(!function_exists('research_agent_workspace_create_document'))throw new RuntimeException('Research document workspace is unavailable.');
        $document=research_agent_workspace_create_document($pdo,$viewer,$project,$args,true);
        $conversation=trim((string)($document['conversation_public_id']??''));
        return [
          'type'=>'document','public_id'=>(string)$document['public_id'],'label'=>(string)$document['title'],
          'url'=>$conversation!==''?'/home.php?agent='.rawurlencode($conversation).'&doc='.rawurlencode((string)$document['public_id']):'/research-project.php?id='.rawurlencode((string)$project['public_id']),
          'revision_number'=>(int)($document['revision_number']??1),'document_type'=>(string)($document['document_type']??$args['document_type'])
        ];
    }
    if($capability==='research.create_sticky'){
        if(!function_exists('research_agent_workspace_create_sticky'))throw new RuntimeException('Research sticky workspace is unavailable.');
        $sticky=research_agent_workspace_create_sticky($pdo,$viewer,$project,$args);
        return ['type'=>'sticky','public_id'=>(string)$sticky['public_id'],'label'=>'Sticky note','color'=>(string)($sticky['sticky_color']??$args['color'])];
    }
    if($capability==='research.create_claim'){
        $public=ulid_like();$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,?,?,'unverified')")
          ->execute([$public,$projectId,$userId,$args['statement'],$args['claim_type']]);
        if(function_exists('data_attribution_try_capture_object'))data_attribution_try_capture_object($pdo,$userId,'claim',$public);
        return ['type'=>'claim','public_id'=>$public,'label'=>mb_substr($args['statement'],0,100),'url'=>'/research-claim.php?id='.rawurlencode($public)];
    }
    if($capability==='research.attach_annotation_evidence'){
        $claim=agent_action_claim_row($pdo,$projectId,$args['claim_id']);if(!$claim)throw new RuntimeException('Claim is no longer available in this project.');
        $annotation=annotation_access($pdo,$args['annotation_id'],$viewer);if(!$annotation)throw new RuntimeException('Annotation evidence is no longer accessible.');
        $q=$pdo->prepare('SELECT source_version_id FROM annotations WHERE id=?');$q->execute([$annotation['id']]);$versionId=(int)($q->fetchColumn()?:0);if(!$versionId)throw new RuntimeException('Annotation evidence has no captured source version.');
        $q=$pdo->prepare('SELECT public_id FROM claim_evidence WHERE claim_id=? AND annotation_id=? AND relationship=? LIMIT 1');$q->execute([$claim['id'],$annotation['id'],$args['relationship']]);$existing=(string)($q->fetchColumn()?:'');
        $public=$existing?:ulid_like();if(!$existing)$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,annotation_id,source_version_id,relationship,note) VALUES(?,?,?,'annotation',?,?,?,?)")
          ->execute([$public,$claim['id'],$userId,$annotation['id'],$versionId,$args['relationship'],$args['note']!==''?$args['note']:null]);
        if(function_exists('data_attribution_try_capture_object'))data_attribution_try_capture_object($pdo,$userId,'claim',$args['claim_id']);
        return ['type'=>'claim_evidence','public_id'=>$public,'label'=>'Annotation evidence attached','url'=>'/research-claim.php?id='.rawurlencode($args['claim_id'])];
    }
    if($capability==='research.create_finding'){
        $claimRows=[];foreach($args['claim_ids'] as $id){$claim=agent_action_claim_row($pdo,$projectId,$id);if(!$claim)throw new RuntimeException('One or more proposed Claims are no longer in this project.');$claimRows[]=$claim;}
        $public=ulid_like();$pdo->prepare("INSERT INTO research_findings(public_id,project_id,created_by_user_id,title,summary,status) VALUES(?,?,?,?,?,'draft')")->execute([$public,$projectId,$userId,$args['title'],$args['summary']]);$findingId=(int)$pdo->lastInsertId();
        $position=0;foreach($claimRows as $claim)$pdo->prepare("INSERT INTO finding_claims(finding_id,claim_id,added_by_user_id,relationship,position) VALUES(?,?,?,'supports',?)")->execute([$findingId,$claim['id'],$userId,$position++]);
        if(function_exists('data_attribution_try_capture_object'))data_attribution_try_capture_object($pdo,$userId,'finding',$public);
        return ['type'=>'finding','public_id'=>$public,'label'=>$args['title'],'url'=>'/research-finding.php?id='.rawurlencode($public)];
    }
    if($capability==='research.link_claims'){
        $source=agent_action_claim_row($pdo,$projectId,$args['source_claim_id']);$target=agent_action_claim_row($pdo,$projectId,$args['target_claim_id']);if(!$source||!$target)throw new RuntimeException('One or more proposed Claims are no longer in this project.');
        $q=$pdo->prepare('SELECT public_id FROM claim_relations WHERE source_claim_id=? AND target_claim_id=? AND relation_type=? LIMIT 1');$q->execute([$source['id'],$target['id'],$args['relation_type']]);$existing=(string)($q->fetchColumn()?:'');
        $public=$existing?:ulid_like();if(!$existing)$pdo->prepare('INSERT INTO claim_relations(public_id,project_id,source_claim_id,target_claim_id,added_by_user_id,relation_type,note) VALUES(?,?,?,?,?,?,?)')
          ->execute([$public,$projectId,$source['id'],$target['id'],$userId,$args['relation_type'],$args['note']!==''?$args['note']:null]);
        if(function_exists('data_provenance_try_edge_record'))data_provenance_try_edge_record($pdo,'claim',(string)$source['public_id'],null,(string)$args['relation_type'],'claim',(string)$target['public_id'],null,$userId);
        return ['type'=>'claim_relation','public_id'=>$public,'label'=>ucfirst(str_replace('_',' ',$args['relation_type'])).' Claims','url'=>'/research-graph.php?id='.rawurlencode((string)$project['public_id'])];
    }
    throw new RuntimeException('Unsupported Agent capability.');
}

function agent_action_confirm_execute(PDO $pdo,array $viewer,string $proposalPublicId): array {
    if(!agent_actions_ready($pdo))throw new RuntimeException('Agent action runtime is unavailable.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM agent_action_proposals WHERE public_id=? AND proposed_by_user_id=? FOR UPDATE');$q->execute([$proposalPublicId,$viewer['id']]);$proposal=$q->fetch();if(!$proposal)throw new AgentActionForbidden('Agent action proposal not found.');
        if($proposal['status']==='executed'){$result=json_decode((string)($proposal['result_json']??''),true)?:[];$pdo->commit();return ['proposal_id'=>$proposalPublicId,'status'=>'executed','result'=>$result,'deduplicated'=>true];}
        if($proposal['status']!=='pending')throw new RuntimeException('This Agent action is no longer pending.');
        if(strtotime((string)$proposal['expires_at'])<time()){$pdo->prepare("UPDATE agent_action_proposals SET status='stale',error_text='Proposal expired before confirmation.' WHERE id=?")->execute([$proposal['id']]);agent_action_event($pdo,(int)$proposal['id'],'stale',(int)$viewer['id'],['reason'=>'expired']);$pdo->commit();throw new AgentActionStale('This Agent action proposal expired. Ask the Agent to propose it again.');}
        $project=project_access($pdo,(int)$viewer['id'],(string)(function()use($pdo,$proposal){$q=$pdo->prepare('SELECT public_id FROM research_projects WHERE id=?');$q->execute([$proposal['project_id']]);return $q->fetchColumn()?:'';})());
        if(!$project||!project_can_write($project))throw new AgentActionForbidden('You no longer have permission to change this Research project.');
        if(!empty($project['team_id'])&&function_exists('research_agent_access')&&installer_table_exists($pdo,'research_agents')){
            $aq=$pdo->prepare("SELECT public_id FROM research_agents WHERE project_id=? AND status<>'archived' ORDER BY id LIMIT 1");$aq->execute([(int)$project['id']]);$agentPublic=(string)($aq->fetchColumn()?:'');
            if($agentPublic!==''&&!research_agent_access($pdo,$viewer,$agentPublic))throw new AgentActionForbidden('You are no longer a member of the Team that owns this Research Agent.');
        }
        $currentHash=research_workspace_input_hash($pdo,(int)$project['id']);if(!hash_equals((string)$proposal['project_state_hash'],$currentHash)){
            $pdo->prepare("UPDATE agent_action_proposals SET status='stale',error_text='Research project changed after proposal.' WHERE id=?")->execute([$proposal['id']]);agent_action_event($pdo,(int)$proposal['id'],'stale',(int)$viewer['id'],['reason'=>'project_state_changed']);$pdo->commit();throw new AgentActionStale('The Research project changed after this proposal. Ask the Agent to review the current state and propose the action again.');
        }
        $args=json_decode((string)$proposal['arguments_json'],true);if(!is_array($args))throw new RuntimeException('Stored Agent action arguments are invalid.');
        $pdo->prepare("UPDATE agent_action_proposals SET status='confirmed',confirmed_at=NOW(),error_text=NULL WHERE id=?")->execute([$proposal['id']]);agent_action_event($pdo,(int)$proposal['id'],'confirmed',(int)$viewer['id']);
        $result=agent_action_execute_capability($pdo,$viewer,$project,(string)$proposal['capability_key'],$args);
        if(($result['type']??'')==='document'&&function_exists('research_agent_workspace_post_document_to_chat')){
            $posted=research_agent_workspace_post_document_to_chat($pdo,$viewer,(string)$result['public_id'],!empty($proposal['assistant_message_id'])?(int)$proposal['assistant_message_id']:null);
            if($posted)$result['chat_message_public_id']=$posted['public_id'];
        }
        $pdo->prepare("UPDATE agent_action_proposals SET status='executed',result_type=?,result_public_id=?,result_json=?,executed_at=NOW(),error_text=NULL WHERE id=?")
          ->execute([(string)($result['type']??''),(string)($result['public_id']??''),json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$proposal['id']]);
        agent_action_event($pdo,(int)$proposal['id'],'executed',(int)$viewer['id'],$result);
        $pdo->commit();if(research_workspace_ready($pdo)){try{research_workspace_queue($pdo,(int)$project['id'],2);}catch(Throwable $ignored){}}if(function_exists('research_outcomes_ready')&&research_outcomes_ready($pdo)){try{research_outcome_sync_agent_actions($pdo,$viewer,20);}catch(Throwable $ignored){}}
        return ['proposal_id'=>$proposalPublicId,'status'=>'executed','result'=>$result,'deduplicated'=>false];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();throw $e;
    }
}

function agent_action_reject(PDO $pdo,array $viewer,string $proposalPublicId): array {
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM agent_action_proposals WHERE public_id=? AND proposed_by_user_id=? FOR UPDATE');$q->execute([$proposalPublicId,$viewer['id']]);$proposal=$q->fetch();if(!$proposal)throw new AgentActionForbidden('Agent action proposal not found.');
        if($proposal['status']==='rejected'){$pdo->commit();return ['proposal_id'=>$proposalPublicId,'status'=>'rejected','deduplicated'=>true];}
        if($proposal['status']!=='pending')throw new RuntimeException('This Agent action is no longer pending.');
        $pdo->prepare("UPDATE agent_action_proposals SET status='rejected',rejected_at=NOW() WHERE id=?")->execute([$proposal['id']]);agent_action_event($pdo,(int)$proposal['id'],'rejected',(int)$viewer['id']);$pdo->commit();if(function_exists('research_outcomes_ready')&&research_outcomes_ready($pdo)){try{research_outcome_sync_agent_actions($pdo,$viewer,20);}catch(Throwable $ignored){}}
        return ['proposal_id'=>$proposalPublicId,'status'=>'rejected','deduplicated'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
