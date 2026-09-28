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
      'research.action_plan.add_task'=>[
        'label'=>'Add Action Plan execution task','description'=>'Add a normal existing Research Task to an Action Plan after confirmation. The current Action Plan state hash must still match.',
        'arguments'=>['action_plan_id'=>'Action Plan public ID','action_plan_state_hash'=>'exact State hash from Action Plan Strategic Memory','milestone_id'=>'milestone public ID optional','title'=>'string','description'=>'string optional','task_type'=>'general|find_source|verify_claim|review_source_change|compare_sources|synthesize|draft_deliverable|follow_up','priority'=>'low|medium|high|urgent optional','due_at'=>'date/time optional','link_role'=>'execution|validation|supporting optional','depends_on'=>'array of linked task public IDs optional']
      ],
      'research.action_plan.create_milestone'=>[
        'label'=>'Add Action Plan milestone','description'=>'Create a planned milestone in the current Action Plan after confirmation. This never starts or completes the milestone.',
        'arguments'=>['action_plan_id'=>'Action Plan public ID','action_plan_state_hash'=>'exact State hash from Action Plan Strategic Memory','title'=>'string','description'=>'string optional','completion_criteria'=>'array of strings optional','target_on'=>'YYYY-MM-DD optional']
      ],
      'research.action_plan.create_follow_through_program'=>[
        'label'=>'Create Action Plan follow-through Program','description'=>'Create an existing Research Program linked to the Action Plan after confirmation. Agent-created follow-through Programs are forced Paused and cannot begin recurring work automatically.',
        'arguments'=>['action_plan_id'=>'Action Plan public ID','action_plan_state_hash'=>'exact State hash from Action Plan Strategic Memory','program_role'=>'execution_review|success_measure_check|evidence_refresh|decision_follow_up','cadence'=>'hourly|daily|weekly|monthly|manual optional','timezone_name'=>'IANA timezone optional','run_time_local'=>'HH:MM optional','quiet_mode'=>'material_only|always optional','materiality_threshold'=>'any|important|high optional','sync_with_action_plan'=>'boolean optional']
      ],
      'research.action_plan.record_observation'=>[
        'label'=>'Record Action Plan execution observation','description'=>'Append confirmed expected-vs-actual execution evidence to the Action Plan ledger. This does not resolve variances or change execution/Decision state.',
        'arguments'=>['action_plan_id'=>'Action Plan public ID','action_plan_state_hash'=>'exact State hash from Action Plan Strategic Memory','observation_type'=>'progress|success_measure|milestone|assumption|risk|new_evidence|outcome_signal','subject_type'=>'action_plan|milestone|task|success_measure|assumption|risk optional','milestone_id'=>'milestone public ID optional','task_id'=>'task public ID optional','success_measure_index'=>'integer optional','assumption_index'=>'integer optional','risk_index'=>'integer optional','summary'=>'string','actual'=>'scalar/object/array optional','assessment'=>'unknown|on_track|at_risk|met|missed|changed','material'=>'boolean optional','severity'=>'low|medium|high|critical optional','variance_type'=>'schedule_delay|target_miss|assumption_changed|new_evidence|risk_realized|scope_change|execution_deviation optional','source_type'=>'string optional','source_public_id'=>'string optional','source_snapshot'=>'object optional','impact'=>'string optional','response'=>'string optional']
      ],
      'research.action_plan.open_decision_reconsideration'=>[
        'label'=>'Open Decision reconsideration from Action Plan','description'=>'Open a human-governed reconsideration case for the Action Plan source Decision after confirmation. This does not change Decision status or apply a recommendation.',
        'arguments'=>['action_plan_id'=>'Action Plan public ID','action_plan_state_hash'=>'exact State hash from Action Plan Strategic Memory','title'=>'string optional','reason'=>'string','materiality'=>'low|medium|high|critical optional']
      ],
      'research.portfolio.create_decision_draft'=>[
        'label'=>'Create Portfolio Decision draft','description'=>'Create a native Phase 71 Decision in draft state from current organizational cognition after confirmation. This never accepts, rejects, defers, reopens, or supersedes the Decision.',
        'arguments'=>['portfolio_id'=>'Intelligence Portfolio public ID','portfolio_state_hash'=>'exact strategic state hash from Organizational Strategic Cognition','title'=>'string','statement'=>'string','rationale'=>'string optional','decision_type'=>'decision|conclusion|recommendation optional','confidence'=>'0..1 optional','assumptions'=>'array optional','uncertainty'=>'array optional','alternatives'=>'array optional']
      ],
      'research.decision.create_action_plan_draft'=>[
        'label'=>'Create Action Plan draft','description'=>'Create a Phase 72 Action Plan in draft state from an Accepted or Reopened Decision after confirmation. This never activates the plan.',
        'arguments'=>['decision_id'=>'Decision public ID','decision_state_hash'=>'exact Decision state hash from Organizational Strategic Cognition','title'=>'string','objective'=>'string','expected_result'=>'string','priority'=>'low|medium|high|urgent optional','success_measures'=>'array of {label,target}','risks'=>'array optional','assumptions'=>'array optional','start_on'=>'YYYY-MM-DD optional','due_on'=>'YYYY-MM-DD optional']
      ],
      'research.decision.open_reconsideration'=>[
        'label'=>'Open Decision reconsideration','description'=>'Open a human-governed Decision reconsideration case from current organizational cognition after confirmation. This never changes Decision status or applies a recommendation.',
        'arguments'=>['decision_id'=>'Decision public ID','decision_state_hash'=>'exact Decision state hash from Organizational Strategic Cognition','title'=>'string optional','reason'=>'string','materiality'=>'low|medium|high|critical optional']
      ],
      'research.portfolio.create_strategic_review'=>[
        'label'=>'Create Strategic Review','description'=>'Freeze current Portfolio strategic state and send it through the existing Collaborative Review path after confirmation. This cannot change Decision or execution state.',
        'arguments'=>['portfolio_id'=>'Intelligence Portfolio public ID','portfolio_state_hash'=>'exact strategic state hash from Organizational Strategic Cognition','instructions'=>'string optional']
      ],
      'research.portfolio.create_strategic_briefing'=>[
        'label'=>'Create Executive Strategic Briefing','description'=>'Create a frozen Executive Strategic Briefing and its existing Team Review after confirmation. This cannot publish the document or bypass Team Review.',
        'arguments'=>['portfolio_id'=>'Intelligence Portfolio public ID','portfolio_state_hash'=>'exact strategic state hash from Organizational Strategic Cognition','strategic_review_id'=>'source Strategic Review public ID optional','source_packet_hash'=>'exact source packet hash when strategic_review_id is supplied','title'=>'string optional','window_days'=>'1..365 optional']
      ],
      'research.create_mission'=>[
        'label'=>'Create Research Mission','description'=>'Create a durable outcome-driven Research Mission in draft state. This does not create or start a Plan, Task queue, Program, or autonomous execution.',
        'arguments'=>['title'=>'string','research_question'=>'string','objective'=>'string','success_definition'=>'string optional','priority'=>'low|medium|high|urgent optional','success_criteria'=>'array of strings optional','subquestions'=>'array of strings optional']
      ],
      'research.create_plan'=>[
        'label'=>'Create research plan','description'=>'Create a versioned Research plan with dependent tasks and a living deliverable. The user must confirm the plan before it is created.',
        'arguments'=>['title'=>'string','objective'=>'string','priority'=>'low|medium|high|urgent optional','due_at'=>'date/time optional','deliverable_type'=>'research_brief|competitive_analysis|due_diligence|source_digest|timeline|comparison|weekly_report|report|analysis|document','deliverable_title'=>'string optional','tasks'=>'array of task objects with title,description,task_type,priority,depends_on index array optional']
      ],
      'research.create_program'=>[
        'label'=>'Create recurring Research Program','description'=>'Create a governed recurring intelligence Program that generates fresh Research plans and deliverables on a schedule. The user must confirm the Program before it is scheduled.',
        'arguments'=>['title'=>'string','objective'=>'string','cadence'=>'hourly|daily|weekly|monthly|manual','timezone_name'=>'IANA timezone optional','run_time_local'=>'HH:MM optional','weekday'=>'0-6 optional','day_of_month'=>'1-28 optional','priority'=>'low|medium|high|urgent optional','deliverable_type'=>'research_brief|competitive_analysis|due_diligence|source_digest|timeline|comparison|weekly_report|report|analysis|document','quiet_mode'=>'material_only|always optional','materiality_threshold'=>'any|important|high optional','catch_up_mode'=>'latest|skip optional','topics'=>'array optional','token_budget_per_run'=>'integer optional','monthly_run_limit'=>'integer optional']
      ],
      'research.prepare_publication_review'=>[
        'label'=>'Prepare document publication review','description'=>'Create a governed publication workflow for an existing Research document and request human review. The Agent cannot review, approve, or publish it.',
        'arguments'=>['document_id'=>'Research document public ID','reviewer_ids'=>'array of user public IDs','approver_ids'=>'array of user public IDs optional','required_approvals'=>'integer optional','due_at'=>'date/time optional','instructions'=>'string optional','visibility'=>'private|team|public optional']
      ],
      'research.publish_approved_document'=>[
        'label'=>'Publish approved Research document','description'=>'Publish an already-approved Phase 59 workflow as an immutable Research report version. All review and approval gates must already be satisfied and the user must confirm this action.',
        'arguments'=>['workflow_id'=>'Research publication workflow public ID']
      ],
      'research.record_portfolio_inference'=>[
        'label'=>'Record Portfolio inference','description'=>'Record an explicitly labeled Agent interpretation in a Phase 60 Intelligence Portfolio. The user must confirm it, and every inference must cite provenance already present in the Agent evidence set.',
        'arguments'=>['portfolio_id'=>'Intelligence Portfolio public ID','title'=>'string','body'=>'string','category'=>'trend|risk|opportunity|contradiction|decision|freshness|dependency|other','severity'=>'info|watch|high optional','confidence'=>'0..1 optional','provenance_refs'=>'array of {type,id,label optional}']
      ],
      'research.create_note'=>[
        'label'=>'Create research note','description'=>'Save a concise Research note to the current project.',
        'arguments'=>['body'=>'string']
      ],
      'research.create_document'=>[
        'label'=>'Create research document','description'=>'Create a durable editable Research document in the current Research Agent workspace. The completed document is posted back into the Agent conversation as a document card.',
        'arguments'=>['title'=>'string','body'=>'string','summary'=>'string optional','document_type'=>'document|research_brief|memo|report|analysis|source_summary|timeline|weekly_report']
      ],
      'research.create_system_report'=>[
        'label'=>'Run Research Report','description'=>'Run a governed per-Research-Agent System Report over the current project data. This creates a Report Run, not a Research Document. The user must confirm the report run.',
        'arguments'=>['report_type'=>'research_brief|evidence_audit|claims_verification|contradictions_gaps|source_freshness|entity_map|timeline|action_plan|full_intelligence|research_evolution|what_changed|confidence_contradictions|open_questions_evolution|entity_theme_evolution|mission_brief|mission_review','title'=>'string optional','depth'=>'quick|standard|deep optional','focus_query'=>'string optional','date_from'=>'YYYY-MM-DD optional','date_to'=>'YYYY-MM-DD optional','source_ids'=>'array optional','claim_ids'=>'array optional','finding_ids'=>'array optional','entity_ids'=>'array optional','folder_ids'=>'array optional','include_sections'=>'array optional']
      ],
      'research.create_document_from_report'=>[
        'label'=>'Create document from Report Run','description'=>'Create a durable editable Research Document from an existing Report Run after user confirmation. The Report Run remains unchanged.',
        'arguments'=>['report_id'=>'Report Run public ID','section_keys'=>'array optional']
      ],
      'research.create_report_subscription'=>[
        'label'=>'Create Report subscription','description'=>'Subscribe this user to a saved Report preset driven by an existing Research Program. The Program remains the scheduler and the user must confirm the subscription.',
        'arguments'=>['preset_id'=>'saved Report preset public ID','program_id'=>'Research Program public ID','name'=>'string optional','delivery_policy'=>'every_run|if_changed|material_change_only|if_stale optional','stale_after_hours'=>'integer optional','notify_in_app'=>'boolean optional','notify_agent_chat'=>'boolean optional','include_summary'=>'boolean optional','include_comparison'=>'boolean optional']
      ],
      'research.update_report_subscription'=>[
        'label'=>'Update Report subscription','description'=>'Change delivery policy or notification preferences for one of this user’s Report subscriptions. The user must confirm the change.',
        'arguments'=>['subscription_id'=>'Report subscription public ID','name'=>'string optional','delivery_policy'=>'every_run|if_changed|material_change_only|if_stale optional','stale_after_hours'=>'integer optional','notify_in_app'=>'boolean optional','notify_agent_chat'=>'boolean optional','include_summary'=>'boolean optional','include_comparison'=>'boolean optional']
      ],
      'research.set_report_subscription_status'=>[
        'label'=>'Pause, resume, or archive Report subscription','description'=>'Change the lifecycle status of one of this user’s Report subscriptions after confirmation.',
        'arguments'=>['subscription_id'=>'Report subscription public ID','status'=>'active|paused|archived']
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
    $projects=agent_action_project_map($pdo,$viewer,$context);
    return count($projects)===1?array_values($projects)[0]:null;
}

function agent_action_project_map(PDO $pdo,array $viewer,array $context): array {
    $projects=[];
    foreach($context as $item){
        $type=(string)($item['type']??'');$id=(string)($item['public_id']??'');
        if($type==='research'){
            $p=project_access($pdo,(int)$viewer['id'],$id);if($p)$projects[(string)$p['public_id']]=$p;
        }elseif($type==='report'&&function_exists('research_system_report_access')){
            $report=research_system_report_access($pdo,$viewer,$id);if($report){$p=project_access($pdo,(int)$viewer['id'],(string)$report['project_public_id']);if($p)$projects[(string)$p['public_id']]=$p;}
        }
    }
    return $projects;
}

function agent_action_task_types(): array {
    return function_exists('research_task_types')?research_task_types():['general'=>'General','find_source'=>'Find source','verify_claim'=>'Verify claim','review_source_change'=>'Review source change','compare_sources'=>'Compare sources','synthesize'=>'Synthesize','draft_deliverable'=>'Draft deliverable','follow_up'=>'Follow up'];
}
function agent_action_task_priorities(): array {
    return function_exists('research_task_priorities')?research_task_priorities():['low'=>'Low','medium'=>'Medium','high'=>'High','urgent'=>'Urgent'];
}
function agent_action_deliverable_types(): array {
    return function_exists('research_task_deliverable_types')?research_task_deliverable_types():['research_brief'=>'Research Brief','competitive_analysis'=>'Competitive Analysis','due_diligence'=>'Due-Diligence Report','source_digest'=>'Source Digest','timeline'=>'Timeline','comparison'=>'Comparison','weekly_report'=>'Weekly Report','report'=>'Report','analysis'=>'Analysis','document'=>'Document'];
}
function agent_action_program_cadences(): array {
    return function_exists('research_program_cadences')?research_program_cadences():['hourly'=>'Hourly','daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','manual'=>'Manual only'];
}
function agent_action_program_quiet_modes(): array {
    return function_exists('research_program_quiet_modes')?research_program_quiet_modes():['material_only'=>'Material changes only','always'=>'Every run'];
}
function agent_action_program_materiality(): array {
    return function_exists('research_program_materiality_levels')?research_program_materiality_levels():['any'=>'Any','important'=>'Important','high'=>'High'];
}
function agent_action_program_catchup(): array {
    return function_exists('research_program_catch_up_modes')?research_program_catch_up_modes():['latest'=>'Latest missed cycle','skip'=>'Skip stale cycles'];
}

function agent_action_clean_arguments(string $capability,array $args): array {
    $s=fn($v,$max)=>mb_substr(trim((string)$v),0,$max);
    if($capability==='research.create_task'){
        $title=$s($args['title']??'',255);if($title==='')throw new InvalidArgumentException('Task title is required.');
        $type=(string)($args['task_type']??'general');if(!isset(agent_action_task_types()[$type]))$type='general';
        $priority=(string)($args['priority']??'medium');if(!isset(agent_action_task_priorities()[$priority]))$priority='medium';
        return ['title'=>$title,'description'=>$s($args['description']??'',12000),'task_type'=>$type,'priority'=>$priority,'due_at'=>$s($args['due_at']??'',80)];
    }
    if(str_starts_with($capability,'research.action_plan.')){
        $plan=$s($args['action_plan_id']??'',64);$stateHash=strtolower($s($args['action_plan_state_hash']??'',64));
        if($plan===''||!preg_match('/^[a-f0-9]{64}$/',$stateHash))throw new InvalidArgumentException('Action Plan ID and exact state hash are required.');
        if($capability==='research.action_plan.add_task'){
            $title=$s($args['title']??'',255);if($title==='')throw new InvalidArgumentException('Task title is required.');
            $type=(string)($args['task_type']??'general');if(!isset(agent_action_task_types()[$type]))$type='general';
            $priority=(string)($args['priority']??'medium');if(!isset(agent_action_task_priorities()[$priority]))$priority='medium';
            $role=(string)($args['link_role']??'execution');if(!in_array($role,['execution','validation','supporting'],true))$role='execution';
            $depends=[];foreach(array_slice((array)($args['depends_on']??[]),0,40) as $id){$id=$s($id,64);if($id!==''&&!in_array($id,$depends,true))$depends[]=$id;}
            return ['action_plan_id'=>$plan,'action_plan_state_hash'=>$stateHash,'milestone_id'=>$s($args['milestone_id']??'',64),'title'=>$title,'description'=>$s($args['description']??'',12000),'task_type'=>$type,'priority'=>$priority,'due_at'=>$s($args['due_at']??'',80),'link_role'=>$role,'depends_on'=>$depends];
        }
        if($capability==='research.action_plan.create_milestone'){
            $title=$s($args['title']??'',255);if($title==='')throw new InvalidArgumentException('Milestone title is required.');
            $criteria=[];foreach(array_slice((array)($args['completion_criteria']??[]),0,30) as $item){$item=$s($item,1000);if($item!==''&&!in_array($item,$criteria,true))$criteria[]=$item;}
            return ['action_plan_id'=>$plan,'action_plan_state_hash'=>$stateHash,'title'=>$title,'description'=>$s($args['description']??'',16000),'completion_criteria'=>$criteria,'target_on'=>$s($args['target_on']??'',10)];
        }
        if($capability==='research.action_plan.create_follow_through_program'){
            $role=(string)($args['program_role']??'execution_review');if(!in_array($role,['execution_review','success_measure_check','evidence_refresh','decision_follow_up'],true))throw new InvalidArgumentException('Invalid follow-through Program role.');
            $cadence=(string)($args['cadence']??'weekly');if(!isset(agent_action_program_cadences()[$cadence]))$cadence='weekly';
            $quiet=(string)($args['quiet_mode']??'material_only');if(!isset(agent_action_program_quiet_modes()[$quiet]))$quiet='material_only';
            $materiality=(string)($args['materiality_threshold']??'important');if(!isset(agent_action_program_materiality()[$materiality]))$materiality='important';
            return ['action_plan_id'=>$plan,'action_plan_state_hash'=>$stateHash,'program_role'=>$role,'cadence'=>$cadence,'timezone_name'=>$s($args['timezone_name']??'UTC',64),'run_time_local'=>$s($args['run_time_local']??'09:00',8),'quiet_mode'=>$quiet,'materiality_threshold'=>$materiality,'sync_with_action_plan'=>array_key_exists('sync_with_action_plan',$args)?(bool)$args['sync_with_action_plan']:true];
        }
        if($capability==='research.action_plan.record_observation'){
            $type=(string)($args['observation_type']??'progress');if(!in_array($type,['progress','success_measure','milestone','assumption','risk','new_evidence','outcome_signal'],true))throw new InvalidArgumentException('Invalid execution observation type.');
            $subject=array_key_exists('subject_type',$args)?(string)$args['subject_type']:'';if($subject!==''&&!in_array($subject,['action_plan','milestone','task','success_measure','assumption','risk'],true))throw new InvalidArgumentException('Invalid execution observation subject.');
            $assessment=(string)($args['assessment']??'unknown');if(!in_array($assessment,['unknown','on_track','at_risk','met','missed','changed'],true))$assessment='unknown';
            $severity=(string)($args['severity']??'medium');if(!in_array($severity,['low','medium','high','critical'],true))$severity='medium';
            $variance=(string)($args['variance_type']??'');if($variance!==''&&!in_array($variance,['schedule_delay','target_miss','assumption_changed','new_evidence','risk_realized','scope_change','execution_deviation'],true))throw new InvalidArgumentException('Invalid execution variance type.');
            $summary=$s($args['summary']??'',12000);if($summary==='')throw new InvalidArgumentException('Execution observation summary is required.');
            $cleanValue=fn($v)=>function_exists('research_action_plan_variance_value')?research_action_plan_variance_value($v):$v;
            return ['action_plan_id'=>$plan,'action_plan_state_hash'=>$stateHash,'observation_type'=>$type,'subject_type'=>$subject,'milestone_id'=>$s($args['milestone_id']??'',64),'task_id'=>$s($args['task_id']??'',64),'success_measure_index'=>(int)($args['success_measure_index']??-1),'assumption_index'=>(int)($args['assumption_index']??-1),'risk_index'=>(int)($args['risk_index']??-1),'summary'=>$summary,'actual'=>$cleanValue($args['actual']??null),'assessment'=>$assessment,'material'=>(bool)($args['material']??false),'severity'=>$severity,'variance_type'=>$variance,'source_type'=>$s($args['source_type']??'',64),'source_public_id'=>$s($args['source_public_id']??'',80),'source_snapshot'=>$cleanValue($args['source_snapshot']??null),'impact'=>$s($args['impact']??'',12000),'response'=>$s($args['response']??'',12000)];
        }
        if($capability==='research.action_plan.open_decision_reconsideration'){
            $reason=$s($args['reason']??'',16000);if($reason==='')throw new InvalidArgumentException('Decision reconsideration reason is required.');
            $materiality=(string)($args['materiality']??'high');if(!in_array($materiality,['low','medium','high','critical'],true))$materiality='high';
            return ['action_plan_id'=>$plan,'action_plan_state_hash'=>$stateHash,'title'=>$s($args['title']??'',255),'reason'=>$reason,'materiality'=>$materiality];
        }
    }
    if($capability==='research.portfolio.create_decision_draft'){
        $portfolio=$s($args['portfolio_id']??'',64);$stateHash=strtolower($s($args['portfolio_state_hash']??'',64));$title=$s($args['title']??'',255);$statement=$s($args['statement']??'',32000);
        if($portfolio===''||!preg_match('/^[a-f0-9]{64}$/',$stateHash)||$title===''||$statement==='')throw new InvalidArgumentException('Portfolio, exact strategic state hash, Decision title, and statement are required.');
        $type=(string)($args['decision_type']??'decision');if(!isset(research_decision_types()[$type]))$type='decision';$confidence=array_key_exists('confidence',$args)&&$args['confidence']!==''?max(0,min(1,(float)$args['confidence'])):null;
        $cleanList=function($value,$maxItems=30,$maxChars=4000)use($s){$out=[];foreach(array_slice((array)$value,0,$maxItems) as $item){if(is_array($item)){$item=array_map(fn($v)=>is_scalar($v)?mb_substr(trim((string)$v),0,$maxChars):$v,$item);$out[]=$item;}else{$v=$s($item,$maxChars);if($v!=='')$out[]=$v;}}return $out;};
        return ['portfolio_id'=>$portfolio,'portfolio_state_hash'=>$stateHash,'title'=>$title,'statement'=>$statement,'rationale'=>$s($args['rationale']??'',32000),'decision_type'=>$type,'confidence'=>$confidence,'assumptions'=>$cleanList($args['assumptions']??[]),'uncertainty'=>$cleanList($args['uncertainty']??[]),'alternatives'=>$cleanList($args['alternatives']??[])];
    }
    if($capability==='research.decision.create_action_plan_draft'){
        $decision=$s($args['decision_id']??'',64);$stateHash=strtolower($s($args['decision_state_hash']??'',64));$title=$s($args['title']??'',255);$objective=$s($args['objective']??'',32000);$expected=$s($args['expected_result']??'',32000);
        if($decision===''||!preg_match('/^[a-f0-9]{64}$/',$stateHash)||$title===''||$objective===''||$expected==='')throw new InvalidArgumentException('Decision, exact Decision state hash, title, objective, and expected result are required.');
        $priority=(string)($args['priority']??'medium');if(!isset(agent_action_task_priorities()[$priority]))$priority='medium';$measures=[];
        foreach(array_slice((array)($args['success_measures']??[]),0,30) as $m){if(!is_array($m))continue;$label=$s($m['label']??'',255);$target=$s($m['target']??'',2000);if($label!=='')$measures[]=['label'=>$label,'target'=>$target];}
        if(!$measures)throw new InvalidArgumentException('Action Plan draft requires at least one success measure.');
        $cleanList=function($value)use($s){$out=[];foreach(array_slice((array)$value,0,30) as $item){if(is_array($item)){$clean=[];foreach($item as $k=>$v)if(is_scalar($v))$clean[(string)$k]=$s($v,4000);if($clean)$out[]=$clean;}else{$v=$s($item,4000);if($v!=='')$out[]=$v;}}return $out;};
        return ['decision_id'=>$decision,'decision_state_hash'=>$stateHash,'title'=>$title,'objective'=>$objective,'expected_result'=>$expected,'priority'=>$priority,'success_measures'=>$measures,'risks'=>$cleanList($args['risks']??[]),'assumptions'=>$cleanList($args['assumptions']??[]),'start_on'=>$s($args['start_on']??'',10),'due_on'=>$s($args['due_on']??'',10)];
    }
    if($capability==='research.decision.open_reconsideration'){
        $decision=$s($args['decision_id']??'',64);$stateHash=strtolower($s($args['decision_state_hash']??'',64));$reason=$s($args['reason']??'',16000);
        if($decision===''||!preg_match('/^[a-f0-9]{64}$/',$stateHash)||$reason==='')throw new InvalidArgumentException('Decision, exact Decision state hash, and reconsideration reason are required.');
        $materiality=(string)($args['materiality']??'high');if(!in_array($materiality,['low','medium','high','critical'],true))$materiality='high';
        return ['decision_id'=>$decision,'decision_state_hash'=>$stateHash,'title'=>$s($args['title']??'',255),'reason'=>$reason,'materiality'=>$materiality];
    }
    if($capability==='research.portfolio.create_strategic_review'){
        $portfolio=$s($args['portfolio_id']??'',64);$stateHash=strtolower($s($args['portfolio_state_hash']??'',64));
        if($portfolio===''||!preg_match('/^[a-f0-9]{64}$/',$stateHash))throw new InvalidArgumentException('Portfolio and exact strategic state hash are required.');
        return ['portfolio_id'=>$portfolio,'portfolio_state_hash'=>$stateHash,'instructions'=>$s($args['instructions']??'',8000)];
    }
    if($capability==='research.portfolio.create_strategic_briefing'){
        $portfolio=$s($args['portfolio_id']??'',64);$stateHash=strtolower($s($args['portfolio_state_hash']??'',64));$source=$s($args['strategic_review_id']??'',64);$packet=strtolower($s($args['source_packet_hash']??'',64));
        if($portfolio===''||!preg_match('/^[a-f0-9]{64}$/',$stateHash))throw new InvalidArgumentException('Portfolio and exact strategic state hash are required.');
        if($source!==''&&!preg_match('/^[a-f0-9]{64}$/',$packet))throw new InvalidArgumentException('An exact source packet hash is required when a Strategic Review source is supplied.');
        return ['portfolio_id'=>$portfolio,'portfolio_state_hash'=>$stateHash,'strategic_review_id'=>$source,'source_packet_hash'=>$packet,'title'=>$s($args['title']??'',240),'window_days'=>max(1,min(365,(int)($args['window_days']??30)))];
    }
    if($capability==='research.create_mission'){
        $title=$s($args['title']??'',255);$question=$s($args['research_question']??$args['question']??'',16000);$objective=$s($args['objective']??'',16000);
        if($title===''||$question===''||$objective==='')throw new InvalidArgumentException('Mission title, research question, and objective are required.');
        $priority=(string)($args['priority']??'medium');if(!isset(agent_action_task_priorities()[$priority]))$priority='medium';
        $criteria=[];foreach(array_slice((array)($args['success_criteria']??[]),0,20) as $label){$label=$s($label,255);if($label!==''&&!in_array($label,$criteria,true))$criteria[]=$label;}
        $questions=[];foreach(array_slice((array)($args['subquestions']??[]),0,30) as $questionText){$questionText=$s($questionText,12000);if($questionText!==''&&!in_array($questionText,$questions,true))$questions[]=$questionText;}
        return ['title'=>$title,'research_question'=>$question,'objective'=>$objective,'success_definition'=>$s($args['success_definition']??'',16000),'priority'=>$priority,'success_criteria'=>$criteria,'subquestions'=>$questions];
    }
    if($capability==='research.create_plan'){
        $title=$s($args['title']??'',255);$objective=$s($args['objective']??'',16000);if($title===''||$objective==='')throw new InvalidArgumentException('Plan title and objective are required.');
        $priority=(string)($args['priority']??'medium');if(!isset(agent_action_task_priorities()[$priority]))$priority='medium';
        $deliverable=(string)($args['deliverable_type']??'research_brief');if(!isset(agent_action_deliverable_types()[$deliverable]))$deliverable='research_brief';
        $tasks=[];foreach(array_slice(is_array($args['tasks']??null)?$args['tasks']:[],0,20) as $raw){if(!is_array($raw))continue;$taskTitle=$s($raw['title']??'',255);if($taskTitle==='')continue;$type=(string)($raw['task_type']??'general');if(!isset(agent_action_task_types()[$type]))$type='general';$p=(string)($raw['priority']??$priority);if(!isset(agent_action_task_priorities()[$p]))$p=$priority;$deps=[];foreach(array_slice((array)($raw['depends_on']??[]),0,12) as $d)$deps[]=max(0,(int)$d);$tasks[]=['title'=>$taskTitle,'description'=>$s($raw['description']??'',12000),'task_type'=>$type,'priority'=>$p,'depends_on'=>array_values(array_unique($deps))];}
        if(!$tasks)throw new InvalidArgumentException('A Research plan needs at least one task.');
        return ['title'=>$title,'objective'=>$objective,'priority'=>$priority,'due_at'=>$s($args['due_at']??'',80),'deliverable_type'=>$deliverable,'deliverable_title'=>$s($args['deliverable_title']??'',255),'tasks'=>$tasks];
    }
    if($capability==='research.create_program'){
        $title=$s($args['title']??'',255);$objective=$s($args['objective']??'',16000);if($title===''||$objective==='')throw new InvalidArgumentException('Program title and objective are required.');
        $cadence=(string)($args['cadence']??'weekly');if(!isset(agent_action_program_cadences()[$cadence]))$cadence='weekly';
        $priority=(string)($args['priority']??'medium');if(!isset(agent_action_task_priorities()[$priority]))$priority='medium';
        $deliverable=(string)($args['deliverable_type']??'weekly_report');if(!isset(agent_action_deliverable_types()[$deliverable]))$deliverable='weekly_report';
        $quiet=(string)($args['quiet_mode']??'material_only');if(!isset(agent_action_program_quiet_modes()[$quiet]))$quiet='material_only';
        $materiality=(string)($args['materiality_threshold']??'important');if(!isset(agent_action_program_materiality()[$materiality]))$materiality='important';
        $catch=(string)($args['catch_up_mode']??'latest');if(!isset(agent_action_program_catchup()[$catch]))$catch='latest';
        $topics=[];foreach(array_slice((array)($args['topics']??[]),0,30) as $topic){$topic=$s($topic,160);if($topic!==''&&!in_array($topic,$topics,true))$topics[]=$topic;}
        return ['title'=>$title,'objective'=>$objective,'cadence'=>$cadence,'timezone_name'=>$s($args['timezone_name']??'UTC',64),'run_time_local'=>$s($args['run_time_local']??'09:00',8),'weekday'=>max(0,min(6,(int)($args['weekday']??1))),'day_of_month'=>max(1,min(28,(int)($args['day_of_month']??1))),'priority'=>$priority,'deliverable_type'=>$deliverable,'quiet_mode'=>$quiet,'materiality_threshold'=>$materiality,'catch_up_mode'=>$catch,'scope'=>['topics'=>$topics,'include_annotations'=>true,'include_workspace'=>true],'token_budget_per_run'=>max(1000,min(2000000,(int)($args['token_budget_per_run']??60000))),'monthly_run_limit'=>max(1,min(1000,(int)($args['monthly_run_limit']??31)))];
    }
    if($capability==='research.prepare_publication_review'){
        $document=$s($args['document_id']??'',64);if($document==='')throw new InvalidArgumentException('Research document ID is required.');
        $reviewers=[];foreach(array_slice((array)($args['reviewer_ids']??[]),0,30) as $id){$id=$s($id,64);if($id!==''&&!in_array($id,$reviewers,true))$reviewers[]=$id;}if(!$reviewers)throw new InvalidArgumentException('At least one reviewer is required.');
        $approvers=[];foreach(array_slice((array)($args['approver_ids']??[]),0,30) as $id){$id=$s($id,64);if($id!==''&&!in_array($id,$approvers,true))$approvers[]=$id;}
        $visibility=(string)($args['visibility']??'private');if(!in_array($visibility,['private','team','public'],true))$visibility='private';
        return ['document_id'=>$document,'reviewer_ids'=>$reviewers,'approver_ids'=>$approvers,'required_approvals'=>max(1,min(20,(int)($args['required_approvals']??count($reviewers)))),'due_at'=>$s($args['due_at']??'',80),'instructions'=>$s($args['instructions']??'',8000),'visibility'=>$visibility];
    }
    if($capability==='research.publish_approved_document'){
        $workflow=$s($args['workflow_id']??'',64);if($workflow==='')throw new InvalidArgumentException('Publication workflow ID is required.');return ['workflow_id'=>$workflow];
    }
    if($capability==='research.record_portfolio_inference'){
        $portfolio=$s($args['portfolio_id']??'',64);$title=$s($args['title']??'',255);$body=$s($args['body']??'',12000);
        if($portfolio===''||$title===''||$body==='')throw new InvalidArgumentException('Portfolio, inference title, and explanation are required.');
        $category=(string)($args['category']??'other');if(!in_array($category,['trend','risk','opportunity','contradiction','decision','freshness','dependency','other'],true))$category='other';
        $severity=(string)($args['severity']??'info');if(!in_array($severity,['info','watch','high'],true))$severity='info';
        $confidence=array_key_exists('confidence',$args)?max(0,min(1,(float)$args['confidence'])):null;$refs=[];
        foreach(array_slice((array)($args['provenance_refs']??[]),0,60) as $ref){if(!is_array($ref))continue;$type=$s(strtolower((string)($ref['type']??'')),40);$id=$s($ref['id']??'',80);if($type===''||$id==='')continue;$refs[]=['type'=>$type,'id'=>$id,'label'=>$s($ref['label']??'',255)];}
        if(!$refs)throw new InvalidArgumentException('Portfolio inference requires explicit provenance references.');
        return ['portfolio_id'=>$portfolio,'title'=>$title,'body'=>$body,'category'=>$category,'severity'=>$severity,'confidence'=>$confidence,'provenance_refs'=>$refs];
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
    if($capability==='research.create_system_report'){
        $type=$s($args['report_type']??'research_brief',64);
        $allowed=function_exists('research_system_report_types')?research_system_report_types():array_fill_keys(['research_brief','evidence_audit','claims_verification','contradictions_gaps','source_freshness','entity_map','timeline','action_plan','full_intelligence'],[]);
        if(!isset($allowed[$type]))throw new InvalidArgumentException('Unknown System Report type.');
        $o=function_exists('research_report_studio_options')?research_report_studio_options($args):[];
        return ['report_type'=>$type,'title'=>$s($args['title']??'',240)]+$o;
    }
    if($capability==='research.create_document_from_report'){
        $report=$s($args['report_id']??'',64);if($report==='')throw new InvalidArgumentException('Report Run ID is required.');
        $sections=[];foreach(array_slice((array)($args['section_keys']??[]),0,40) as $key){$key=$s($key,80);if($key!==''&&!in_array($key,$sections,true))$sections[]=$key;}
        return ['report_id'=>$report,'section_keys'=>$sections];
    }
    if($capability==='research.create_report_subscription'){
        $preset=$s($args['preset_id']??'',64);$program=$s($args['program_id']??'',64);if($preset===''||$program==='')throw new InvalidArgumentException('Report preset and Research Program IDs are required.');
        $policy=(string)($args['delivery_policy']??'material_change_only');$policies=function_exists('research_report_subscription_policies')?research_report_subscription_policies():array_fill_keys(['every_run','if_changed','material_change_only','if_stale'],true);if(!isset($policies[$policy]))$policy='material_change_only';
        return ['preset_id'=>$preset,'program_id'=>$program,'name'=>$s($args['name']??'',190),'delivery_policy'=>$policy,'stale_after_hours'=>max(1,min(8760,(int)($args['stale_after_hours']??168))),
          'notify_in_app'=>array_key_exists('notify_in_app',$args)?(bool)$args['notify_in_app']:true,'notify_agent_chat'=>array_key_exists('notify_agent_chat',$args)?(bool)$args['notify_agent_chat']:true,
          'include_summary'=>array_key_exists('include_summary',$args)?(bool)$args['include_summary']:true,'include_comparison'=>array_key_exists('include_comparison',$args)?(bool)$args['include_comparison']:true];
    }
    if($capability==='research.update_report_subscription'){
        $id=$s($args['subscription_id']??'',64);if($id==='')throw new InvalidArgumentException('Report subscription ID is required.');$out=['subscription_id'=>$id];
        if(array_key_exists('name',$args))$out['name']=$s($args['name'],190);
        if(array_key_exists('delivery_policy',$args)){$policy=(string)$args['delivery_policy'];$policies=function_exists('research_report_subscription_policies')?research_report_subscription_policies():array_fill_keys(['every_run','if_changed','material_change_only','if_stale'],true);if(!isset($policies[$policy]))throw new InvalidArgumentException('Invalid Report subscription delivery policy.');$out['delivery_policy']=$policy;}
        if(array_key_exists('stale_after_hours',$args))$out['stale_after_hours']=max(1,min(8760,(int)$args['stale_after_hours']));
        foreach(['notify_in_app','notify_agent_chat','include_summary','include_comparison'] as $key)if(array_key_exists($key,$args))$out[$key]=(bool)$args[$key];
        return $out;
    }
    if($capability==='research.set_report_subscription_status'){
        $id=$s($args['subscription_id']??'',64);$status=(string)($args['status']??'');if($id===''||!in_array($status,['active','paused','archived'],true))throw new InvalidArgumentException('Report subscription ID and a valid status are required.');
        return ['subscription_id'=>$id,'status'=>$status];
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
    if(str_starts_with($capability,'research.portfolio.')){
        $portfolio=(string)($args['portfolio_id']??'');if($portfolio===''||!isset($seen['portfolio:'.$portfolio]))return false;
        if(!function_exists('research_intelligence_portfolio_contains_project')||!research_intelligence_portfolio_contains_project($pdo,$viewer,$portfolio,$projectId))return false;
        if(!function_exists('research_intelligence_organizational_state_hash'))return false;
        try{$current=research_intelligence_organizational_state_hash($pdo,$viewer,$portfolio);}catch(Throwable $e){return false;}
        if(!hash_equals($current,(string)($args['portfolio_state_hash']??'')))return false;
        if($capability==='research.portfolio.create_strategic_briefing'&&!empty($args['strategic_review_id'])){
            $source=(string)$args['strategic_review_id'];if(!isset($seen['strategic_review:'.$source]))return false;
            if(!function_exists('research_intelligence_strategic_review_access'))return false;$review=research_intelligence_strategic_review_access($pdo,$viewer,$source,false);
            if(!$review||(string)$review['portfolio_public_id']!==$portfolio||!hash_equals((string)$review['packet_hash'],(string)$args['source_packet_hash']))return false;
        }
        return true;
    }
    if(str_starts_with($capability,'research.decision.')){
        $decisionPublic=(string)($args['decision_id']??'');if($decisionPublic===''||!isset($seen['decision:'.$decisionPublic]))return false;
        if(!function_exists('research_decision_detail')||!function_exists('research_decision_review_state_hash'))return false;$decision=research_decision_detail($pdo,$viewer,$decisionPublic);
        if(!$decision||(int)$decision['project_id']!==$projectId)return false;
        try{$current=research_decision_review_state_hash($pdo,$viewer,$decisionPublic);}catch(Throwable $e){return false;}
        if(!hash_equals($current,(string)($args['decision_state_hash']??'')))return false;
        if($capability==='research.decision.create_action_plan_draft'&&!in_array((string)$decision['status'],['accepted','reopened'],true))return false;
        if($capability==='research.decision.open_reconsideration'&&!in_array((string)$decision['status'],['accepted','rejected','deferred','superseded'],true))return false;
        return true;
    }
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
    if($capability==='research.create_document_from_report'){
        if(!isset($seen['report:'.$args['report_id']]))return false;
        if(!function_exists('research_system_report_access'))return false;
        $report=research_system_report_access($pdo,$viewer,(string)$args['report_id']);
        return $report&&(int)$report['project_id']===$projectId;
    }
    if($capability==='research.create_report_subscription'){
        if(!function_exists('research_report_subscription_validate_links'))return false;
        $aq=$pdo->prepare("SELECT public_id FROM research_agents WHERE project_id=? AND status<>'archived' ORDER BY is_default DESC,id LIMIT 1");$aq->execute([$projectId]);$agentPublic=(string)($aq->fetchColumn()?:'');if($agentPublic==='')return false;
        try{research_report_subscription_validate_links($pdo,$viewer,$agentPublic,(string)$args['preset_id'],(string)$args['program_id']);return true;}catch(Throwable $e){return false;}
    }
    if(in_array($capability,['research.update_report_subscription','research.set_report_subscription_status'],true)){
        if(!function_exists('research_report_subscription_access'))return false;$sub=research_report_subscription_access($pdo,$viewer,(string)$args['subscription_id']);
        return $sub&&(int)$sub['project_id']===$projectId;
    }
    if($capability==='research.prepare_publication_review'){
        if(!isset($seen['document:'.$args['document_id']]))return false;
        if(!function_exists('research_agent_workspace_object'))return false;$doc=research_agent_workspace_object($pdo,$viewer,(string)$args['document_id'],false);if(!$doc||(int)$doc['project_id']!==$projectId||($doc['object_type']??'')!=='document')return false;
        $eligible=[];foreach(research_review_eligible_reviewers($pdo,$viewer,(string)$project['public_id']) as $person)$eligible[(string)$person['public_id']]=true;
        foreach(array_merge((array)$args['reviewer_ids'],(array)$args['approver_ids']) as $public)if(!isset($eligible[(string)$public]))return false;
        return true;
    }
    if($capability==='research.publish_approved_document'){
        if(!function_exists('research_publication_workflow_access'))return false;$w=research_publication_workflow_access($pdo,$viewer,(string)$args['workflow_id']);return $w&&(int)$w['project_id']===$projectId;
    }
    if($capability==='research.record_portfolio_inference'){
        if(!function_exists('research_intelligence_portfolio_contains_project')||!research_intelligence_portfolio_contains_project($pdo,$viewer,(string)$args['portfolio_id'],$projectId))return false;
        foreach((array)$args['provenance_refs'] as $ref)if(!isset($seen[(string)$ref['type'].':'.(string)$ref['id']]))return false;
        return true;
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

function agent_action_action_plan_for_project(PDO $pdo,array $viewer,array $project,array $args): array {
    if(!function_exists('research_action_plan_cognition_ready')||!research_action_plan_cognition_ready($pdo))throw new RuntimeException('Action Plan Strategic Follow-Through requires the latest application state.');
    $planPublic=trim((string)($args['action_plan_id']??''));$stateHash=trim((string)($args['action_plan_state_hash']??''));
    if($planPublic===''||$stateHash==='')throw new InvalidArgumentException('Action Plan ID and state hash are required.');
    if($pdo->inTransaction()){$lock=$pdo->prepare('SELECT id FROM research_action_plans WHERE public_id=? FOR UPDATE');$lock->execute([$planPublic]);if(!$lock->fetchColumn())throw new RuntimeException('Action Plan not found.');}
    $plan=research_action_plan_cognition_assert_state($pdo,$viewer,$planPublic,$stateHash);
    if((int)$plan['project_id']!==(int)$project['id'])throw new AgentActionForbidden('That Action Plan does not belong to the confirmed Research project.');
    return $plan;
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
    if(str_starts_with($capability,'research.action_plan.')){
        $plan=agent_action_action_plan_for_project($pdo,$viewer,$project,$args);$planPublic=(string)$plan['public_id'];
        if($capability==='research.action_plan.add_task'){
            $task=research_action_plan_add_task($pdo,$viewer,$planPublic,[
              'milestone_id'=>(string)($args['milestone_id']??''),'title'=>(string)$args['title'],'description'=>(string)($args['description']??''),
              'task_type'=>(string)($args['task_type']??'general'),'priority'=>(string)($args['priority']??'medium'),'due_at'=>(string)($args['due_at']??''),
              'link_role'=>(string)($args['link_role']??'execution'),'depends_on'=>(array)($args['depends_on']??[])
            ],true);
            return ['type'=>'action_plan_task','public_id'=>(string)$task['public_id'],'label'=>(string)$task['title'],'status'=>(string)$task['status'],'action_plan_id'=>$planPublic,'url'=>'/research-tasks.php?agent='.rawurlencode((string)$plan['agent_public_id']).'&task='.rawurlencode((string)$task['public_id'])];
        }
        if($capability==='research.action_plan.create_milestone'){
            $m=research_action_plan_create_milestone($pdo,$viewer,$planPublic,[
              'title'=>(string)$args['title'],'description'=>(string)($args['description']??''),'completion_criteria'=>(array)($args['completion_criteria']??[]),'target_on'=>(string)($args['target_on']??'')
            ],true);
            return ['type'=>'action_plan_milestone','public_id'=>(string)$m['public_id'],'label'=>(string)$m['title'],'status'=>(string)($m['status']??'planned'),'action_plan_id'=>$planPublic,'url'=>'/research-project.php?id='.rawurlencode((string)$plan['project_public_id'])];
        }
        if($capability==='research.action_plan.create_follow_through_program'){
            $link=research_action_plan_create_program($pdo,$viewer,$planPublic,[
              'program_role'=>(string)$args['program_role'],'cadence'=>(string)$args['cadence'],'timezone_name'=>(string)$args['timezone_name'],'run_time_local'=>(string)$args['run_time_local'],
              'quiet_mode'=>(string)$args['quiet_mode'],'materiality_threshold'=>(string)$args['materiality_threshold'],'sync_with_action_plan'=>(bool)$args['sync_with_action_plan']
            ],true);
            $program=(array)($link['program']??[]);
            return ['type'=>'action_plan_program','public_id'=>(string)($program['public_id']??''),'label'=>(string)($program['title']??research_action_plan_program_roles()[(string)$args['program_role']]),'status'=>(string)($program['status']??'paused'),'program_role'=>(string)$args['program_role'],'action_plan_id'=>$planPublic,'url'=>'/research-programs.php?agent='.rawurlencode((string)$plan['agent_public_id']).'&program='.rawurlencode((string)($program['public_id']??''))];
        }
        if($capability==='research.action_plan.record_observation'){
            $input=$args;unset($input['action_plan_id'],$input['action_plan_state_hash']);$result=research_action_plan_record_execution_observation($pdo,$viewer,$planPublic,$input,true);$obs=(array)($result['observation']??[]);$variance=(array)($result['variance']??[]);
            return ['type'=>'action_plan_observation','public_id'=>(string)($obs['public_id']??''),'label'=>(string)($obs['summary']??$args['summary']),'assessment'=>(string)($obs['assessment']??$args['assessment']),'variance_id'=>(string)($variance['public_id']??''),'variance_type'=>(string)($variance['variance_type']??''),'action_plan_id'=>$planPublic,'url'=>'/research-project.php?id='.rawurlencode((string)$plan['project_public_id'])];
        }
        if($capability==='research.action_plan.open_decision_reconsideration'){
            if(!function_exists('research_decision_open_reconsideration'))throw new RuntimeException('Decision Reconsideration is unavailable.');
            $case=research_decision_open_reconsideration($pdo,$viewer,(string)$plan['decision_public_id'],[
              'trigger_type'=>'manual','title'=>(string)($args['title']!==''?$args['title']:'Reconsider '.(string)$plan['decision_title']),
              'reason'=>(string)$args['reason'],'materiality'=>(string)$args['materiality']
            ],true);
            return ['type'=>'decision_reconsideration','public_id'=>(string)$case['public_id'],'label'=>(string)$case['title'],'status'=>(string)$case['status'],'action_plan_id'=>$planPublic,'decision_id'=>(string)$plan['decision_public_id'],'url'=>'/research-project.php?id='.rawurlencode((string)$plan['project_public_id'])];
        }
        throw new RuntimeException('Unsupported Action Plan Agent capability.');
    }
    if(str_starts_with($capability,'research.portfolio.')){
        $portfolioPublic=(string)$args['portfolio_id'];
        if(!function_exists('research_intelligence_organizational_state_hash'))throw new RuntimeException('Organizational Strategic Cognition is unavailable.');
        $current=research_intelligence_organizational_state_hash($pdo,$viewer,$portfolioPublic);
        if(!hash_equals($current,(string)$args['portfolio_state_hash']))throw new AgentActionStale('Portfolio strategic state changed after this proposal. Ask the Agent to reason from current state and propose it again.');
        if(!research_intelligence_portfolio_contains_project($pdo,$viewer,$portfolioPublic,$projectId))throw new AgentActionForbidden('That Portfolio no longer belongs to this confirmed Research project.');
        if($capability==='research.portfolio.create_decision_draft'){
            $input=$args;unset($input['portfolio_id'],$input['portfolio_state_hash']);$input['idempotency_key']='agent-s7-decision|'.$portfolioPublic.'|'.$current.'|'.hash('sha256',json_encode([$input['title'],$input['statement'],$input['rationale']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $result=research_intelligence_portfolio_create_native_decision($pdo,$viewer,$portfolioPublic,$input,true);$d=(array)$result['decision'];
            return ['type'=>'decision','public_id'=>(string)$d['public_id'],'label'=>(string)$d['title'],'status'=>(string)$d['status'],'portfolio_id'=>$portfolioPublic,'url'=>'/research-decisions.php?agent='.rawurlencode((string)$d['agent_public_id']).'&decision='.rawurlencode((string)$d['public_id'])];
        }
        if($capability==='research.portfolio.create_strategic_review'){
            $review=research_intelligence_strategic_review_create($pdo,$viewer,$portfolioPublic,['trigger_type'=>'manual','idempotency_key'=>'agent-s7-review|'.$portfolioPublic.'|'.$current,'instructions'=>(string)$args['instructions']]);
            return ['type'=>'strategic_review','public_id'=>(string)$review['public_id'],'label'=>'Strategic Review','status'=>(string)(($review['review']['status']??'open')),'team_review_id'=>(string)($review['collaborative_review_public_id']??''),'portfolio_id'=>$portfolioPublic,'url'=>!empty($review['collaborative_review_public_id'])?'/research-reviews.php?id='.rawurlencode((string)$review['collaborative_review_public_id']):'/research-intelligence-portfolios.php?portfolio='.rawurlencode($portfolioPublic)];
        }
        if($capability==='research.portfolio.create_strategic_briefing'){
            $source=(string)$args['strategic_review_id'];if($source!==''){$sr=research_intelligence_strategic_review_access($pdo,$viewer,$source,false);if(!$sr||(string)$sr['portfolio_public_id']!==$portfolioPublic||!hash_equals((string)$sr['packet_hash'],(string)$args['source_packet_hash']))throw new AgentActionStale('The selected Strategic Review source changed or is unavailable.');}
            $brief=research_intelligence_strategic_briefing_create($pdo,$viewer,$portfolioPublic,['idempotency_key'=>'agent-s7-brief|'.$portfolioPublic.'|'.$current.'|'.$source,'strategic_review_id'=>$source,'title'=>(string)$args['title'],'window_days'=>(int)$args['window_days']]);
            return ['type'=>'strategic_briefing','public_id'=>(string)$brief['public_id'],'label'=>(string)$brief['title'],'status'=>(string)($brief['team_review']['status']??'open'),'team_review_id'=>(string)($brief['team_review_public_id']??''),'executive_briefing_id'=>(string)$brief['executive_briefing_public_id'],'portfolio_id'=>$portfolioPublic,'url'=>!empty($brief['team_review_public_id'])?'/research-reviews.php?id='.rawurlencode((string)$brief['team_review_public_id']):'/research-intelligence-portfolios.php?portfolio='.rawurlencode($portfolioPublic)];
        }
        throw new RuntimeException('Unsupported Portfolio Agent capability.');
    }
    if(str_starts_with($capability,'research.decision.')){
        $decisionPublic=(string)$args['decision_id'];$decision=research_decision_detail($pdo,$viewer,$decisionPublic);if(!$decision||(int)$decision['project_id']!==$projectId)throw new AgentActionForbidden('That Decision is no longer available in this confirmed Research project.');
        $current=research_decision_review_state_hash($pdo,$viewer,$decisionPublic);if(!hash_equals($current,(string)$args['decision_state_hash']))throw new AgentActionStale('Decision state changed after this proposal. Ask the Agent to reason from current state and propose it again.');
        if($capability==='research.decision.create_action_plan_draft'){
            $input=$args;unset($input['decision_id'],$input['decision_state_hash']);$input['idempotency_key']='agent-s7-plan|'.$decisionPublic.'|'.$current.'|'.hash('sha256',json_encode([$input['title'],$input['objective'],$input['expected_result']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $plan=research_action_plan_from_decision($pdo,$viewer,$decisionPublic,$input,true);
            return ['type'=>'action_plan','public_id'=>(string)$plan['public_id'],'label'=>(string)$plan['title'],'status'=>(string)$plan['status'],'decision_id'=>$decisionPublic,'url'=>'/research-action-plans.php?action_plan='.rawurlencode((string)$plan['public_id'])];
        }
        if($capability==='research.decision.open_reconsideration'){
            $case=research_decision_open_reconsideration($pdo,$viewer,$decisionPublic,['trigger_type'=>'manual','title'=>(string)($args['title']!==''?$args['title']:'Reconsider '.(string)$decision['title']),'reason'=>(string)$args['reason'],'materiality'=>(string)$args['materiality']],true);
            return ['type'=>'decision_reconsideration','public_id'=>(string)$case['public_id'],'label'=>(string)$case['title'],'status'=>(string)$case['status'],'decision_id'=>$decisionPublic,'url'=>'/research-decisions.php?agent='.rawurlencode((string)$decision['agent_public_id']).'&decision='.rawurlencode($decisionPublic)];
        }
        throw new RuntimeException('Unsupported Decision Agent capability.');
    }
    if($capability==='research.create_mission'){
        if(!function_exists('research_missions_ready')||!research_missions_ready($pdo))throw new RuntimeException('Research Missions require the latest database upgrade.');
        $agent=research_task_agent_for_project($pdo,$viewer,(string)$project['public_id']);if(!$agent)throw new RuntimeException('This project has no active Research Agent.');
        $input=$args;$input['agent_id']=$agent['public_id'];$input['success_criteria']=array_map(fn($label)=>['label'=>$label],(array)$args['success_criteria']);$input['subquestions']=array_map(fn($q)=>['question'=>$q,'priority'=>$args['priority']],(array)$args['subquestions']);
        $mission=research_mission_create($pdo,$viewer,$input,true);
        return ['type'=>'mission','public_id'=>(string)$mission['public_id'],'label'=>(string)$mission['title'],'url'=>'/research-missions.php?agent='.rawurlencode((string)$agent['public_id']).'&mission='.rawurlencode((string)$mission['public_id']),'status'=>(string)$mission['status']];
    }
    if($capability==='research.create_plan'){
        if(!function_exists('research_tasks_ready')||!research_tasks_ready($pdo))throw new RuntimeException('Research Plans require the latest database upgrade.');
        $agent=research_task_agent_for_project($pdo,$viewer,(string)$project['public_id']);if(!$agent)throw new RuntimeException('This project has no active Research Agent.');
        $args['agent_id']=$agent['public_id'];$plan=research_task_plan_create($pdo,$viewer,$args,true);
        return ['type'=>'research_plan','public_id'=>(string)$plan['public_id'],'label'=>(string)$plan['title'],'url'=>'/research-tasks.php?agent='.rawurlencode((string)$agent['public_id']).'&plan='.rawurlencode((string)$plan['public_id'])];
    }
    if($capability==='research.create_program'){
        if(!function_exists('research_programs_ready')||!research_programs_ready($pdo))throw new RuntimeException('Research Programs require the latest database upgrade.');
        $agent=research_task_agent_for_project($pdo,$viewer,(string)$project['public_id']);if(!$agent)throw new RuntimeException('This project has no active Research Agent.');
        $args['agent_id']=$agent['public_id'];$program=research_program_create($pdo,$viewer,$args,true);
        return ['type'=>'research_program','public_id'=>(string)$program['public_id'],'label'=>(string)$program['title'],'url'=>'/research-programs.php?agent='.rawurlencode((string)$agent['public_id']).'&program='.rawurlencode((string)$program['public_id'])];
    }
    if($capability==='research.prepare_publication_review'){
        if(!function_exists('research_publications_ready')||!research_publications_ready($pdo))throw new RuntimeException('Collaborative Publishing requires the latest database upgrade.');
        $doc=research_agent_workspace_object($pdo,$viewer,(string)$args['document_id'],false);if(!$doc||(int)$doc['project_id']!==$projectId)throw new RuntimeException('Research document is no longer available in this project.');
        $workflow=research_publication_workflow_create($pdo,$viewer,(string)$args['document_id'],['visibility'=>$args['visibility'],'required_approvals'=>$args['required_approvals']]);
        $eligible=[];foreach(research_review_eligible_reviewers($pdo,$viewer,(string)$project['public_id']) as $person)$eligible[(string)$person['public_id']]=$person;
        $reviewers=[];foreach((array)$args['reviewer_ids'] as $public){$person=$eligible[(string)$public]??null;if($person)$reviewers[]=['user_id'=>(int)$person['id'],'role'=>in_array($public,(array)$args['approver_ids'],true)?'approver':'reviewer','required'=>true];}
        foreach((array)$args['approver_ids'] as $public){if(in_array($public,(array)$args['reviewer_ids'],true))continue;$person=$eligible[(string)$public]??null;if($person)$reviewers[]=['user_id'=>(int)$person['id'],'role'=>'approver','required'=>true];}
        $workflow=research_publication_request_review($pdo,$viewer,(string)$workflow['public_id'],$reviewers,(string)$args['due_at'],(string)$args['instructions']);
        return ['type'=>'research_publication','public_id'=>(string)$workflow['public_id'],'label'=>(string)$workflow['title'],'url'=>'/research-publications.php?workflow='.rawurlencode((string)$workflow['public_id']),'status'=>(string)$workflow['status']];
    }
    if($capability==='research.publish_approved_document'){
        if(!function_exists('research_publication_publish'))throw new RuntimeException('Collaborative Publishing is unavailable.');$result=research_publication_publish($pdo,$viewer,(string)$args['workflow_id']);
        return ['type'=>'research_report','public_id'=>(string)$result['public_id'],'label'=>'Published Research version '.(int)$result['version_number'],'url'=>'/research-report.php?id='.rawurlencode((string)$result['public_id']).'&v='.(int)$result['version_number']];
    }
    if($capability==='research.record_portfolio_inference'){
        if(!function_exists('research_intelligence_portfolio_add_inference'))throw new RuntimeException('Research Intelligence Portfolios require the latest database upgrade.');
        $insight=research_intelligence_portfolio_add_inference($pdo,$viewer,(string)$args['portfolio_id'],$args,true);
        return ['type'=>'portfolio_inference','public_id'=>(string)$insight['public_id'],'label'=>(string)$insight['title'],'url'=>'/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$args['portfolio_id'])];
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
    if($capability==='research.create_system_report'){
        if(!function_exists('research_system_report_generate')||!research_report_studio_ready($pdo))throw new RuntimeException('Research Agent Report Studio requires the latest database upgrade.');
        $q=$pdo->prepare("SELECT public_id FROM research_agents WHERE project_id=? AND status='active' ORDER BY is_default DESC,id LIMIT 1");$q->execute([$projectId]);$agentPublic=(string)($q->fetchColumn()?:'');
        if($agentPublic==='')throw new RuntimeException('This project has no active Research Agent.');
        $cfg=is_array($GLOBALS['config']??null)?$GLOBALS['config']:[];
        $report=research_system_report_generate($pdo,$cfg,$viewer,$agentPublic,(string)$args['report_type'],(string)$args['title'],true,null,$args,null,null,'agent');
        return ['type'=>'research_system_report','public_id'=>(string)$report['public_id'],'label'=>(string)$report['title'],'url'=>'/research-reports.php?agent='.rawurlencode($agentPublic).'&report='.rawurlencode((string)$report['public_id']),'document_public_id'=>null];
    }
    if($capability==='research.create_document_from_report'){
        if(!function_exists('research_report_studio_create_document')||!research_report_studio_ready($pdo))throw new RuntimeException('Research Agent Report Studio requires the latest database upgrade.');
        $q=$pdo->prepare("SELECT public_id FROM research_agents WHERE project_id=? AND status='active' ORDER BY is_default DESC,id LIMIT 1");$q->execute([$projectId]);$agentPublic=(string)($q->fetchColumn()?:'');
        if($agentPublic==='')throw new RuntimeException('This project has no active Research Agent.');
        $doc=research_report_studio_create_document($pdo,$viewer,$agentPublic,(string)$args['report_id'],(array)$args['section_keys']);
        return ['type'=>'document','public_id'=>(string)$doc['public_id'],'label'=>(string)$doc['title'],'url'=>'/home.php?agent='.rawurlencode((string)($doc['conversation_public_id']??'')).'&doc='.rawurlencode((string)$doc['public_id']),'document_type'=>'report'];
    }
    if($capability==='research.create_report_subscription'){
        if(!function_exists('research_intelligence_delivery_ready')||!research_intelligence_delivery_ready($pdo))throw new RuntimeException('Research Intelligence Delivery requires the latest database upgrade.');
        $agent=research_task_agent_for_project($pdo,$viewer,(string)$project['public_id']);if(!$agent)throw new RuntimeException('This project has no active Research Agent.');
        $sub=research_report_subscription_create($pdo,$viewer,(string)$agent['public_id'],$args,true);
        return ['type'=>'research_report_subscription','public_id'=>(string)$sub['public_id'],'label'=>(string)$sub['name'],'url'=>'/research-reports.php?agent='.rawurlencode((string)$agent['public_id']).'&view=subscriptions&subscription='.rawurlencode((string)$sub['public_id']),'status'=>(string)$sub['status']];
    }
    if($capability==='research.update_report_subscription'){
        if(!function_exists('research_report_subscription_update'))throw new RuntimeException('Research Intelligence Delivery is unavailable.');
        $sub=research_report_subscription_update($pdo,$viewer,(string)$args['subscription_id'],$args,true);
        return ['type'=>'research_report_subscription','public_id'=>(string)$sub['public_id'],'label'=>(string)$sub['name'],'url'=>'/research-reports.php?agent='.rawurlencode((string)$sub['agent_public_id']).'&view=subscriptions&subscription='.rawurlencode((string)$sub['public_id']),'status'=>(string)$sub['status']];
    }
    if($capability==='research.set_report_subscription_status'){
        if(!function_exists('research_report_subscription_set_status'))throw new RuntimeException('Research Intelligence Delivery is unavailable.');
        $sub=research_report_subscription_set_status($pdo,$viewer,(string)$args['subscription_id'],(string)$args['status'],true);
        return ['type'=>'research_report_subscription','public_id'=>(string)$sub['public_id'],'label'=>(string)$sub['name'],'url'=>'/research-reports.php?agent='.rawurlencode((string)$sub['agent_public_id']).'&view=subscriptions&subscription='.rawurlencode((string)$sub['public_id']),'status'=>(string)$sub['status']];
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
        $section7Governed=in_array((string)$proposal['capability_key'],[
          'research.portfolio.create_decision_draft','research.decision.create_action_plan_draft','research.decision.open_reconsideration',
          'research.portfolio.create_strategic_review','research.portfolio.create_strategic_briefing'
        ],true);
        if($section7Governed){
            $provenance=json_decode((string)($proposal['provenance_json']??''),true);$proposalRefs=[];
            if(is_array($provenance))$proposalRefs=array_is_list($provenance)?$provenance:(array)($provenance['refs']??[]);
            if(!agent_action_validate_project_arguments($pdo,$viewer,$project,(string)$proposal['capability_key'],$args,$proposalRefs)){
                $pdo->prepare("UPDATE agent_action_proposals SET status='stale',error_text='Governed source state changed after proposal.' WHERE id=?")->execute([$proposal['id']]);
                agent_action_event($pdo,(int)$proposal['id'],'stale',(int)$viewer['id'],['reason'=>'governed_source_state_changed']);$pdo->commit();
                throw new AgentActionStale('The governed source state changed after this proposal. Ask the Agent to review current state and propose the action again.');
            }
        }
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
