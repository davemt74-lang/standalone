<?php
declare(strict_types=1);

/**
 * Phase 67/68 — Research Agent Knowledge, System Reports & Report Studio
 *
 * System Reports are deterministic views over authoritative Research project
 * state. Phase 68 separates Report Runs from optional derived Research Docs.
 */

function research_system_reports_ready(PDO $pdo): bool {
    try{
        if(!installer_table_exists($pdo,'research_system_reports')||!installer_table_exists($pdo,'research_system_report_events')||!installer_table_exists($pdo,'research_report_presets')||!research_agent_workspace_ready($pdo))return false;
        $db=(string)($pdo->query('SELECT DATABASE()')->fetchColumn()?:'');if($db==='')return false;
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='research_system_reports' AND COLUMN_NAME IN ('rendered_html','knowledge_manifest_json','parameters_json','sections_json')");
        $q->execute([$db]);return (int)$q->fetchColumn()===4;
    }catch(Throwable $e){return false;}
}

function research_system_report_types(): array {
    $scope=['project','focus_query','date_range','sources','claims','findings','entities','folders'];
    return [
      'research_brief'=>['label'=>'Research Brief','description'=>'A concise state-of-research briefing: strongest findings, evidence, risks, gaps and next actions.','category'=>'overview','default_depth'=>'standard','scope'=>$scope,'sections'=>['research_state','strongest_findings','open_gaps','contradictions','next_research_actions','verification_review_workflow']],
      'evidence_audit'=>['label'=>'Evidence Audit','description'=>'Audits the evidence corpus, source coverage, provenance, freshness and unsupported areas.','category'=>'evidence','default_depth'=>'standard','scope'=>$scope,'sections'=>['evidence_inventory','recent_indexed_evidence','source_risks','unsupported_or_thinly_supported_knowledge','provenance_verification_reproducibility']],
      'claims_verification'=>['label'=>'Claims & Verification','description'=>'Processes structured Claims by verification state, evidence depth, support and contradiction.','category'=>'knowledge','default_depth'=>'standard','scope'=>$scope,'sections'=>['claim_verification_matrix','evidence_gaps','conflicting_claims']],
      'contradictions_gaps'=>['label'=>'Contradictions & Gaps','description'=>'Focuses on disputed knowledge, conflicting evidence and missing evidence.','category'=>'intelligence','default_depth'=>'standard','scope'=>$scope,'sections'=>['evidence_gaps','contradictions_disputes','source_risks_that_may_affect_conclusions','recommended_follow_up']],
      'source_freshness'=>['label'=>'Source Freshness & Change','description'=>'Surfaces source recency, changed evidence, source risks and downstream research impact.','category'=>'evidence','default_depth'=>'standard','scope'=>$scope,'sections'=>['source_inventory_freshness','current_source_risks','recent_monitoring_changes']],
      'entity_map'=>['label'=>'Entities & Relationships','description'=>'Summarizes people, companies, organizations, places, topics and relationships in the project.','category'=>'knowledge','default_depth'=>'standard','scope'=>$scope,'sections'=>['entity_map']],
      'timeline'=>['label'=>'Research Timeline','description'=>'Reconstructs the project chronologically from Claims, evidence, Findings and source changes.','category'=>'history','default_depth'=>'standard','scope'=>$scope,'sections'=>['research_timeline']],
      'action_plan'=>['label'=>'Research Action Plan','description'=>'Turns current gaps, risks, monitoring signals and task state into prioritized follow-up.','category'=>'work','default_depth'=>'standard','scope'=>$scope,'sections'=>['priority_research_actions','active_tasks','evidence_gaps','contradictions','workflow_outcomes_downstream_impact']],
      'full_intelligence'=>['label'=>'Full Intelligence Report','description'=>'A comprehensive synthesis across evidence, Claims, Findings, entities, monitoring, work and open questions.','category'=>'overview','default_depth'=>'deep','scope'=>$scope,'sections'=>['research_state','findings','claims_verification','evidence_gaps','contradictions','source_risks','entities','next_actions','extended_research_intelligence']],
      'research_evolution'=>['label'=>'Research Evolution Brief','description'=>'Explains how this Research Agent’s knowledge has evolved over time, including major milestones, strengthening and weakening conclusions, and persistent change.','category'=>'history','default_depth'=>'deep','scope'=>$scope,'sections'=>['evolution_summary','major_milestones','strengthening_and_weakening','persistent_change','what_to_review_next']],
      'what_changed'=>['label'=>'What Changed Brief','description'=>'Summarizes meaningful Research changes since prior longitudinal state, emphasizing material Claim, Finding, Source, contradiction, and open-question movement.','category'=>'history','default_depth'=>'standard','scope'=>$scope,'sections'=>['change_summary','material_changes','resolved_items','new_questions_and_contradictions','next_review']],
      'confidence_contradictions'=>['label'=>'Confidence & Contradictions Brief','description'=>'Tracks how Claim confidence and contradictions have strengthened, weakened, become disputed, verified, or resolved over time.','category'=>'intelligence','default_depth'=>'deep','scope'=>$scope,'sections'=>['confidence_movement','verification_milestones','contradiction_history','at_risk_knowledge']],
      'open_questions_evolution'=>['label'=>'Open Questions Brief','description'=>'Tracks unresolved questions and evidence gaps across time, including newly opened, persistent, changed, and resolved questions.','category'=>'intelligence','default_depth'=>'standard','scope'=>$scope,'sections'=>['open_question_state','new_questions','persistent_questions','resolved_questions','recommended_follow_up']],
      'entity_theme_evolution'=>['label'=>'Entity & Theme Evolution Brief','description'=>'Shows how important entities, relationships, and recurring Research themes are changing across longitudinal state.','category'=>'knowledge','default_depth'=>'deep','scope'=>$scope,'sections'=>['entity_movement','relationship_changes','emerging_themes','persistent_themes','implications']],
    ];
}

function research_system_report_type(string $type): array {
    $types=research_system_report_types();$type=strtolower(trim($type));
    if(!isset($types[$type]))throw new InvalidArgumentException('Unknown System Report type.');
    return ['key'=>$type]+$types[$type];
}

function research_system_report_agent(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_agent_access($pdo,$viewer,trim($agentPublic));
    if(!$agent)throw new RuntimeException('Research Agent not found.');
    return $agent;
}

function research_system_report_event(PDO $pdo,int $reportId,string $event,string $actor='system',?int $userId=null,array $payload=[]): void {
    if(!research_system_reports_ready($pdo)||$reportId<1)return;
    if(!in_array($actor,['user','agent','system'],true))$actor='system';
    $pdo->prepare("INSERT INTO research_system_report_events(public_id,report_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?)")
      ->execute([ulid_like(),$reportId,mb_substr(trim($event),0,64),$actor,$userId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function research_system_report_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_system_reports_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rsr.*,ra.public_id agent_public_id,ra.name agent_name,rp.public_id project_public_id,rp.title project_title,
      rwo.public_id document_public_id,rwo.title document_title,rwd.revision_number document_revision,rrp.public_id preset_public_id,rrp.name preset_name,parent.public_id refreshed_from_public_id
      FROM research_system_reports rsr
      JOIN research_agents ra ON ra.id=rsr.research_agent_id
      JOIN research_projects rp ON rp.id=rsr.project_id
      LEFT JOIN research_workspace_objects rwo ON rwo.id=rsr.document_object_id
      LEFT JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id
      LEFT JOIN research_report_presets rrp ON rrp.id=rsr.preset_id
      LEFT JOIN research_system_reports parent ON parent.id=rsr.refreshed_from_report_id
      WHERE rsr.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    if(!research_agent_access($pdo,$viewer,(string)$row['agent_public_id']))return null;
    $row['evidence_refs']=json_decode((string)($row['evidence_refs_json']??''),true)?:[];
    $row['metrics']=json_decode((string)($row['metrics_json']??''),true)?:[];
    $row['parameters']=json_decode((string)($row['parameters_json']??''),true)?:[];
    $row['scope']=json_decode((string)($row['scope_json']??''),true)?:[];
    $row['sections']=json_decode((string)($row['sections_json']??''),true)?:[];
    $row['knowledge_manifest']=json_decode((string)($row['knowledge_manifest_json']??''),true)?:[];
    return $row;
}

function research_system_report_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=50): array {
    if(!research_system_reports_ready($pdo))return [];
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT rsr.public_id,rsr.report_type,rsr.title,rsr.status,rsr.input_state_hash,rsr.freshness_state,rsr.generation_mode,rsr.created_at,rsr.updated_at,
      rsr.document_created_at,rsr.refreshed_from_report_id,rwo.public_id document_public_id,rwd.revision_number document_revision,rrp.public_id preset_public_id,rrp.name preset_name
      FROM research_system_reports rsr
      LEFT JOIN research_workspace_objects rwo ON rwo.id=rsr.document_object_id
      LEFT JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id
      LEFT JOIN research_report_presets rrp ON rrp.id=rsr.preset_id
      WHERE rsr.research_agent_id=? AND rsr.status='ready' ORDER BY rsr.created_at DESC,rsr.id DESC LIMIT ".$limit);
    $q->execute([(int)$agent['id']]);$rows=$q->fetchAll()?:[];$types=research_system_report_types();
    foreach($rows as &$row){$row['type_label']=$types[(string)$row['report_type']]['label']??ucwords(str_replace('_',' ',(string)$row['report_type']));}unset($row);
    return $rows;
}

function research_system_report_findings(PDO $pdo,int $projectId,int $limit=50): array {
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT rf.public_id,rf.title,rf.summary,rf.status,rf.updated_at,
      (SELECT COUNT(*) FROM finding_claims fc WHERE fc.finding_id=rf.id) claim_count
      FROM research_findings rf WHERE rf.project_id=? AND rf.status<>'archived'
      ORDER BY FIELD(rf.status,'final','draft'),rf.updated_at DESC LIMIT ".$limit);
    $q->execute([$projectId]);return $q->fetchAll()?:[];
}

function research_system_report_active_tasks(PDO $pdo,int $agentId,int $limit=30): array {
    if(!function_exists('research_tasks_ready')||!research_tasks_ready($pdo))return [];$limit=max(1,min(80,$limit));
    $q=$pdo->prepare("SELECT public_id,title,description,task_type,priority,status,due_at,updated_at
      FROM research_tasks WHERE research_agent_id=? AND status NOT IN ('complete','done','archived')
      ORDER BY FIELD(priority,'urgent','high','medium','low'),due_at IS NULL,due_at,updated_at DESC LIMIT ".$limit);
    $q->execute([$agentId]);return $q->fetchAll()?:[];
}

function research_system_report_active_programs(PDO $pdo,int $agentId,int $limit=20): array {
    if(!function_exists('research_programs_ready')||!research_programs_ready($pdo))return [];$limit=max(1,min(50,$limit));
    $q=$pdo->prepare("SELECT public_id,title,objective,status,cadence,next_run_at,updated_at
      FROM research_programs WHERE research_agent_id=? AND status<>'archived'
      ORDER BY FIELD(status,'active','paused'),next_run_at IS NULL,next_run_at,updated_at DESC LIMIT ".$limit);
    $q->execute([$agentId]);return $q->fetchAll()?:[];
}


function research_system_report_component_error(string $component,Throwable $e,array &$diagnostics): void {
    $diagnostics[]=['component'=>$component,'status'=>'unavailable'];
    error_log('[Annotated System Report '.$component.'] '.$e->getMessage());
}

function research_system_report_coverage_counts(PDO $pdo,int $projectId): array {
    $counts=[];
    foreach([
      'claims'=>"SELECT COUNT(*) FROM research_claims WHERE project_id=?",
      'findings'=>"SELECT COUNT(*) FROM research_findings WHERE project_id=? AND status<>'archived'",
      'entities'=>"SELECT COUNT(*) FROM research_entities WHERE project_id=? AND status<>'archived'",
      'claim_relations'=>"SELECT COUNT(*) FROM claim_relations WHERE project_id=?",
      'entity_relations'=>"SELECT COUNT(*) FROM research_entity_relations WHERE project_id=?",
      'sources'=>"SELECT COUNT(*) FROM project_sources WHERE project_id=?",
      'tasks'=>"SELECT COUNT(*) FROM research_tasks WHERE project_id=? AND status NOT IN ('done','complete','archived')",
      'programs'=>"SELECT COUNT(*) FROM research_programs WHERE project_id=? AND status<>'archived'"
    ] as $key=>$sql){
        try{$q=$pdo->prepare($sql);$q->execute([$projectId]);$counts[$key]=(int)$q->fetchColumn();}
        catch(Throwable $e){$counts[$key]=null;}
    }
    return $counts;
}

function research_system_report_review_context(PDO $pdo,array $viewer,string $projectPublic,int $limit=10): array {
    if(!function_exists('research_review_project_summary'))return ['text'=>'','refs'=>[]];
    $s=research_review_project_summary($pdo,$viewer,$projectPublic);if(empty($s['open']))return ['text'=>'','refs'=>[]];
    $lines=['[COLLABORATIVE RESEARCH REVIEW]','Open reviews: '.(int)$s['open'].'; changes requested: '.(int)$s['changes_requested'].'; unresolved objections: '.(int)$s['unresolved_objections'].'; overdue: '.(int)$s['overdue'].'; stale: '.(int)$s['stale'].'.'];$refs=[];
    foreach(array_slice((array)($s['items']??[]),0,max(1,min(30,$limit))) as $item){$lines[]='- '.(string)$item['title'].' · '.(string)$item['consensus'].(!empty($item['is_stale'])?' · stale':'').(!empty($item['due_at'])?' · due '.$item['due_at']:'').' [REVIEW '.(string)$item['public_id'].']';$refs[]=['type'=>'research_review','id'=>(string)$item['public_id']];}
    return ['text'=>implode("\n",$lines),'refs'=>$refs];
}

function research_system_report_extended_intelligence(PDO $pdo,array $viewer,string $projectPublic,int $projectId,array &$diagnostics): array {
    $out=[];$add=function(string $key,callable $fn)use(&$out,&$diagnostics): void{
        try{$ctx=$fn();if(!is_array($ctx))return;$text=trim((string)($ctx['text']??''));$refs=is_array($ctx['refs']??null)?array_values($ctx['refs']):[];if($text!==''||$refs)$out[$key]=['text'=>$text,'refs'=>$refs];}
        catch(Throwable $e){research_system_report_component_error($key,$e,$diagnostics);}
    };
    if(function_exists('research_workspace_context'))$add('workspace_synthesis',fn()=>research_workspace_context($pdo,$projectId));
    if(function_exists('research_reviews_ready')&&research_reviews_ready($pdo))$add('reviews',fn()=>research_system_report_review_context($pdo,$viewer,$projectPublic,10));
    if(function_exists('change_impact_ready')&&change_impact_ready($pdo))$add('change_impact',fn()=>change_impact_context($pdo,$viewer,$projectPublic,8));
    if(function_exists('provenance_ready')&&provenance_ready($pdo))$add('provenance',fn()=>provenance_project_context($pdo,$viewer,$projectPublic));
    if(function_exists('research_verification_ready')&&research_verification_ready($pdo))$add('verification',fn()=>research_verification_context($pdo,$viewer,$projectPublic,12));
    if(function_exists('research_evidence_packs_ready')&&research_evidence_packs_ready($pdo))$add('evidence_packs',fn()=>research_evidence_pack_project_context($pdo,$viewer,$projectPublic,5));
    if(function_exists('research_workflow_state'))$add('workflow',fn()=>research_workflow_context($pdo,$viewer,$projectPublic));
    return $out;
}

function research_system_report_extended_html(array $snapshot,array $keys=[]): string {
    $ctx=(array)($snapshot['extended_intelligence']??[]);if(!$ctx)return research_system_report_empty('No additional Research intelligence is currently available.');
    if(!$keys)$keys=array_keys($ctx);$html='';
    foreach($keys as $key){if(empty($ctx[$key]['text']))continue;$label=ucwords(str_replace('_',' ',$key));$html.='<h3>'.research_system_report_escape($label).'</h3><p>'.nl2br(research_system_report_escape((string)$ctx[$key]['text'])).'</p>';}
    return $html!==''?$html:research_system_report_empty('No additional Research intelligence is currently available for this report.');
}

function research_system_report_authoritative_corpus_hash(PDO $pdo,int $projectId): string {
    if(!function_exists('research_retrieval_collect_records')||!function_exists('research_retrieval_records_hash'))return '';
    $records=research_retrieval_collect_records($pdo,$projectId);
    $records=array_values(array_filter($records,function($record){
        if(($record['object_type']??'')==='report')return false;
        if(($record['object_type']??'')==='document'&&!empty($record['metadata']['source_report_id']))return false;
        return true;
    }));
    return research_retrieval_records_hash($records);
}

function research_system_report_snapshot(PDO $pdo,array $config,array $viewer,array $agent): array {
    $projectId=(int)$agent['project_id'];$projectPublic=(string)$agent['project_public_id'];$diagnostics=[];
    $workspace=function_exists('research_workspace_deterministic_snapshot')?research_workspace_deterministic_snapshot($pdo,$projectId):[];
    $claims=function_exists('research_workspace_claim_rows')?research_workspace_claim_rows($pdo,$projectId):[];
    $findings=research_system_report_findings($pdo,$projectId,60);
    $entities=function_exists('research_project_entity_rows')?research_project_entity_rows($pdo,$projectId):[];
    $claimRelations=function_exists('research_project_graph_rows')?research_project_graph_rows($pdo,$projectId):[];
    $entityRelations=function_exists('research_entity_relation_rows')?research_entity_relation_rows($pdo,$projectId):[];
    $timeline=function_exists('research_project_timeline')?research_project_timeline($pdo,$projectId):[];
    $extended=research_system_report_extended_intelligence($pdo,$viewer,$projectPublic,$projectId,$diagnostics);
    $monitoring=[];$monitorEvents=[];
    if(function_exists('research_monitor_ready')&&research_monitor_ready($pdo)){
        try{$monitoring=research_monitor_summary($pdo,$viewer,(string)$agent['public_id']);$monitorEvents=research_monitor_events($pdo,$viewer,(string)$agent['public_id'],30);}
        catch(Throwable $e){research_system_report_component_error('monitoring',$e,$diagnostics);}
    }
    $tasks=[];$taskSummary=[];
    if(function_exists('research_tasks_ready')&&research_tasks_ready($pdo)){
        try{$taskSummary=research_task_summary($pdo,$viewer,(string)$agent['public_id']);$tasks=research_system_report_active_tasks($pdo,(int)$agent['id']);}
        catch(Throwable $e){research_system_report_component_error('tasks',$e,$diagnostics);}
    }
    $programs=[];$programSummary=[];
    if(function_exists('research_programs_ready')&&research_programs_ready($pdo)){
        try{$programSummary=research_program_summary($pdo,$viewer,(string)$agent['public_id']);$programs=research_system_report_active_programs($pdo,(int)$agent['id']);}
        catch(Throwable $e){research_system_report_component_error('programs',$e,$diagnostics);}
    }
    $recent=[];$index=[];
    if(function_exists('research_retrieval_ready')&&research_retrieval_ready($pdo)){
        try{$search=research_retrieval_search($pdo,$config,$viewer,$projectPublic,'',['exclude_report_derivatives'=>true],60,false);$recent=array_slice((array)($search['results']??[]),0,24);$index=$search['index']??[];$authoritativeHash=research_system_report_authoritative_corpus_hash($pdo,$projectId);if($authoritativeHash!=='')$index['input_hash']=$authoritativeHash;}
        catch(Throwable $e){research_system_report_component_error('retrieval',$e,$diagnostics);}
    }
    $q=$pdo->prepare("SELECT s.public_id,s.title,s.domain,s.status,s.last_checked_at,sv.version_number,sv.captured_at,
      EXISTS(SELECT 1 FROM source_change_events sce WHERE sce.source_id=s.id AND sce.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) changed_30d
      FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id
      WHERE ps.project_id=? ORDER BY COALESCE(sv.captured_at,s.last_checked_at) DESC,s.id DESC LIMIT 100");
    $q->execute([$projectId]);$sourceRows=$q->fetchAll()?:[];

    $totals=research_system_report_coverage_counts($pdo,$projectId);
    $included=[
      'claims'=>count($claims),'findings'=>count($findings),'entities'=>count($entities),
      'claim_relations'=>count($claimRelations),'entity_relations'=>count($entityRelations),
      'sources'=>count($sourceRows),'tasks'=>count($tasks),'programs'=>count($programs)
    ];
    $truncated=[];foreach($included as $key=>$count)if(isset($totals[$key])&&$totals[$key]!==null&&$count<(int)$totals[$key])$truncated[$key]=['included'=>$count,'total'=>(int)$totals[$key]];

    $snapshot=[
      'schema'=>'annotated-system-report-v1',
      'agent'=>['public_id'=>(string)$agent['public_id'],'name'=>(string)$agent['name'],'description'=>(string)($agent['description']??'')],
      'project'=>['public_id'=>$projectPublic,'title'=>(string)$agent['project_title']],
      'workspace'=>$workspace,
      'claims'=>$claims,
      'findings'=>$findings,
      'entities'=>$entities,
      'claim_relations'=>$claimRelations,
      'entity_relations'=>$entityRelations,
      'sources'=>$sourceRows,
      'timeline'=>$timeline,
      'monitoring'=>['summary'=>$monitoring,'events'=>$monitorEvents],
      'tasks'=>['summary'=>$taskSummary,'items'=>$tasks],
      'programs'=>['summary'=>$programSummary,'items'=>$programs],
      'recent_evidence'=>$recent,
      'retrieval_index'=>$index,
      'extended_intelligence'=>$extended,
      'coverage'=>['totals'=>$totals,'included'=>$included,'truncated'=>$truncated],
      'diagnostics'=>$diagnostics,
    ];
    $stateBasis=$snapshot;
    $stateBasis['retrieval_index']=[
      'input_hash'=>$index['input_hash']??null,
      'document_count'=>(int)($index['document_count']??0),
      'chunk_count'=>(int)($index['chunk_count']??0)
    ];
    $snapshot['state_hash']=hash('sha256',json_encode($stateBasis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    if(function_exists('research_longitudinal_report_data')&&research_longitudinal_ready($pdo)){
        try{$snapshot['longitudinal']=research_longitudinal_report_data($pdo,$viewer,(string)$agent['public_id'],30);}
        catch(Throwable $e){research_system_report_component_error('longitudinal',$e,$diagnostics);$snapshot['longitudinal']=['ready'=>false,'summary'=>[]];}
    }else $snapshot['longitudinal']=['ready'=>false,'summary'=>[]];
    $snapshot['generated_at']=date('c');
    return $snapshot;
}

function research_system_report_ref_bundle(array $snapshot,int $limit=1000): array {
    $limit=max(50,min(5000,$limit));$refs=[];$seen=[];$totalUnique=0;
    $push=function(string $type,string $id,?string $label=null)use(&$refs,&$seen,&$totalUnique,$limit): void{
        $id=trim($id);if($id==='')return;$key=$type.':'.$id;if(isset($seen[$key]))return;$seen[$key]=true;$totalUnique++;
        if(count($refs)>=$limit)return;
        $refs[]=['type'=>$type,'id'=>$id]+($label!==null&&$label!==''?['label'=>mb_substr($label,0,220)]:[]);
    };
    foreach((array)($snapshot['recent_evidence']??[]) as $r)$push((string)($r['object_type']??'evidence'),(string)($r['public_id']??''),(string)($r['title']??''));
    foreach((array)($snapshot['claims']??[]) as $r)$push('claim',(string)($r['public_id']??''),(string)($r['statement']??''));
    foreach((array)($snapshot['findings']??[]) as $r)$push('finding',(string)($r['public_id']??''),(string)($r['title']??''));
    foreach((array)($snapshot['entities']??[]) as $r)$push('entity',(string)($r['public_id']??''),(string)($r['canonical_name']??''));
    foreach((array)($snapshot['sources']??[]) as $r)$push('source',(string)($r['public_id']??''),(string)($r['title']??$r['domain']??''));
    foreach((array)($snapshot['claim_relations']??[]) as $r)$push('claim_relation',(string)($r['public_id']??''),(string)($r['relation_type']??'Claim relationship'));
    foreach((array)($snapshot['entity_relations']??[]) as $r)$push('entity_relation',(string)($r['public_id']??''),(string)($r['relation_type']??'Entity relationship'));
    foreach((array)($snapshot['tasks']['items']??[]) as $r)$push('task',(string)($r['public_id']??''),(string)($r['title']??''));
    foreach((array)($snapshot['programs']['items']??[]) as $r)$push('program',(string)($r['public_id']??''),(string)($r['title']??''));
    foreach((array)($snapshot['extended_intelligence']??[]) as $ctx)foreach((array)($ctx['refs']??[]) as $r)$push((string)($r['type']??'research'),(string)($r['id']??''),(string)($r['label']??''));
    return ['refs'=>$refs,'total_unique'=>$totalUnique,'limit'=>$limit,'truncated'=>$totalUnique>count($refs)];
}
function research_system_report_refs(array $snapshot,int $limit=1000): array {return research_system_report_ref_bundle($snapshot,$limit)['refs'];}

function research_system_report_escape(string $v): string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function research_system_report_li(string $title,string $detail='',string $meta=''): string {
    $h='<li><strong>'.research_system_report_escape($title).'</strong>';
    if($meta!=='')$h.=' <em>'.research_system_report_escape($meta).'</em>';
    if($detail!=='')$h.='<div>'.research_system_report_escape($detail).'</div>';
    return $h.'</li>';
}
function research_system_report_section(string $title,string $body): string {
    return '<h2>'.research_system_report_escape($title).'</h2>'.$body;
}
function research_system_report_empty(string $text): string {return '<p>'.research_system_report_escape($text).'</p>';}
function research_system_report_limit_note(int $shown,int $total,string $label): string {
    return $total>$shown?'<p><small>Showing '.research_system_report_escape((string)$shown).' of '.research_system_report_escape((string)$total).' '.research_system_report_escape($label).'.</small></p>':'';
}

function research_system_report_summary_block(array $s): string {
    $c=(array)($s['workspace']['counts']??[]);
    $pairs=[
      'Sources'=>(int)($c['sources']??count((array)($s['sources']??[]))),
      'Annotations'=>(int)($c['annotations']??0),
      'Claims'=>(int)($c['claims']??count((array)($s['claims']??[]))),
      'Findings'=>(int)($c['findings']??count((array)($s['findings']??[]))),
      'Open tasks'=>(int)($c['open_tasks']??($s['tasks']['summary']['active']??0)),
      'Recent source changes'=>(int)($c['recent_source_changes']??0),
    ];
    $html='<ul>';foreach($pairs as $label=>$value)$html.=research_system_report_li($label,(string)$value);return $html.'</ul>';
}

function research_system_report_claim_matrix(array $claims): string {
    if(!$claims)return research_system_report_empty('No structured Claims are recorded yet.');
    $e='research_system_report_escape';$html='<table><thead><tr><th>Claim</th><th>Status</th><th>Evidence</th><th>Supports</th><th>Contradicts</th><th>Primary</th></tr></thead><tbody>';
    foreach($claims as $c)$html.='<tr><td>'.$e((string)$c['statement']).'</td><td>'.$e((string)$c['status']).'</td><td>'.(int)($c['evidence_count']??0).'</td><td>'.(int)($c['supports_count']??0).'</td><td>'.(int)($c['contradicts_count']??0).'</td><td>'.(int)($c['primary_count']??0).'</td></tr>';
    return $html.'</tbody></table>';
}


function research_system_report_common_sections(array $s): array {
    $workspace=(array)($s['workspace']??[]);$gaps=(array)($workspace['gaps']??[]);$conflicts=(array)($workspace['conflicts']??[]);$risks=(array)($workspace['source_risks']??[]);$next=(array)($workspace['next_actions']??[]);
    $findings=(array)($s['findings']??[]);$recent=(array)($s['recent_evidence']??[]);
    $shownFindings=array_slice($findings,0,function_exists('research_report_studio_limit')?research_report_studio_limit($s,6,12,30):12);$findingsHtml='<ul>';foreach($shownFindings as $x)$findingsHtml.=research_system_report_li((string)$x['title'],(string)($x['summary']??''),(string)($x['status']??''));$findingsHtml.='</ul>'.research_system_report_limit_note(count($shownFindings),count($findings),'Findings');if(!$findings)$findingsHtml=research_system_report_empty('No active Findings are recorded yet.');
    $shownGaps=array_slice($gaps,0,function_exists('research_report_studio_limit')?research_report_studio_limit($s,8,20,50):20);$gapsHtml='<ul>';foreach($shownGaps as $x)$gapsHtml.=research_system_report_li((string)($x['title']??'Evidence gap'),(string)($x['detail']??''),(string)($x['priority']??''));$gapsHtml.='</ul>'.research_system_report_limit_note(count($shownGaps),count($gaps),'evidence gaps');if(!$gaps)$gapsHtml=research_system_report_empty('No structured evidence gaps are currently detected.');
    $shownConflicts=array_slice($conflicts,0,function_exists('research_report_studio_limit')?research_report_studio_limit($s,8,20,50):20);$conflictsHtml='<ul>';foreach($shownConflicts as $x)$conflictsHtml.=research_system_report_li((string)($x['title']??'Conflict'),(string)($x['detail']??''),(string)($x['priority']??''));$conflictsHtml.='</ul>'.research_system_report_limit_note(count($shownConflicts),count($conflicts),'contradictions');if(!$conflicts)$conflictsHtml=research_system_report_empty('No structured contradictions are currently detected.');
    $shownRisks=array_slice($risks,0,function_exists('research_report_studio_limit')?research_report_studio_limit($s,8,20,50):20);$risksHtml='<ul>';foreach($shownRisks as $x)$risksHtml.=research_system_report_li((string)($x['title']??$x['domain']??'Source'),(string)($x['latest_diff']??''),!empty($x['target_changed'])?'changed':'review');$risksHtml.='</ul>'.research_system_report_limit_note(count($shownRisks),count($risks),'source risks');if(!$risks)$risksHtml=research_system_report_empty('No current source risks are detected.');
    $shownNext=array_slice($next,0,function_exists('research_report_studio_limit')?research_report_studio_limit($s,6,15,40):15);$nextHtml='<ol>';foreach($shownNext as $x)$nextHtml.=research_system_report_li((string)($x['title']??'Next step'),(string)($x['reason']??$x['detail']??''),(string)($x['priority']??''));$nextHtml.='</ol>'.research_system_report_limit_note(count($shownNext),count($next),'next actions');if(!$next)$nextHtml=research_system_report_empty('No deterministic next actions are currently queued by Research Intelligence.');
    $shownRecent=array_slice($recent,0,function_exists('research_report_studio_limit')?research_report_studio_limit($s,12,24,60):24);$recentHtml='<ul>';foreach($shownRecent as $r)$recentHtml.=research_system_report_li((string)($r['title']??'Evidence'),(string)($r['snippet']??''),strtoupper((string)($r['object_type']??'evidence')).(!empty($r['locator_label'])?' · '.$r['locator_label']:''));$recentHtml.='</ul>'.research_system_report_limit_note(count($shownRecent),count($recent),'recent evidence items');if(!$recent)$recentHtml=research_system_report_empty('No indexed Research evidence is available.');
    return compact('findingsHtml','gapsHtml','conflictsHtml','risksHtml','nextHtml','recentHtml');
}

function research_system_report_render(string $type,array $s): array {
    $meta=research_system_report_type($type);$esc='research_system_report_escape';
    $project=(string)($s['project']['title']??'Research');$title=$project.' — '.$meta['label'];
    $body='<h1>'.$esc($title).'</h1><p><strong>Research Agent:</strong> '.$esc((string)($s['agent']['name']??'Research Agent')).'<br><strong>Generated:</strong> '.$esc((string)($s['generated_at']??date('c'))).'<br><strong>Data state:</strong> <code>'.$esc(substr((string)($s['state_hash']??''),0,16)).'</code></p>';
    $coverage=(array)($s['coverage']??[]);$truncated=(array)($coverage['truncated']??[]);$diagnostics=(array)($s['diagnostics']??[]);
    if($truncated||$diagnostics){
        $body.='<aside><strong>Coverage notice</strong><ul>';
        foreach($truncated as $key=>$row)$body.='<li>'.$esc(ucwords(str_replace('_',' ',$key))).': '.$esc((string)($row['included']??0)).' of '.$esc((string)($row['total']??0)).' records are rendered in this report. The data-state hash still tracks the complete indexed project corpus.</li>';
        foreach($diagnostics as $row)$body.='<li>'.$esc(ucwords(str_replace('_',' ',(string)($row['component']??'component')))).' data was unavailable during generation.</li>';
        $body.='</ul></aside>';
    }
    $sections=research_system_report_common_sections($s);$claims=(array)($s['claims']??[]);$entities=(array)($s['entities']??[]);$sources=(array)($s['sources']??[]);

    if($type==='research_brief'){
        $body.=research_system_report_section('Research state',research_system_report_summary_block($s));
        $body.=research_system_report_section('Strongest Findings',$sections['findingsHtml']);
        $body.=research_system_report_section('Open gaps',$sections['gapsHtml']);
        $body.=research_system_report_section('Contradictions',$sections['conflictsHtml']);
        $body.=research_system_report_section('Next research actions',$sections['nextHtml']);
        $body.=research_system_report_section('Verification, review & workflow',research_system_report_extended_html($s,['verification','reviews','workflow','change_impact']));
    } elseif($type==='evidence_audit'){
        $body.=research_system_report_section('Evidence inventory',research_system_report_summary_block($s));
        $body.=research_system_report_section('Recent indexed evidence',$sections['recentHtml']);
        $body.=research_system_report_section('Source risks',$sections['risksHtml']);
        $body.=research_system_report_section('Unsupported or thinly supported knowledge',$sections['gapsHtml']);
        $body.=research_system_report_section('Provenance, verification & reproducibility',research_system_report_extended_html($s,['provenance','verification','evidence_packs','change_impact']));
    } elseif($type==='claims_verification'){
        $body.=research_system_report_section('Claim verification matrix',research_system_report_claim_matrix($claims));
        $body.=research_system_report_section('Evidence gaps',$sections['gapsHtml']);
        $body.=research_system_report_section('Conflicting Claims',$sections['conflictsHtml']);
    } elseif($type==='contradictions_gaps'){
        $body.=research_system_report_section('Evidence gaps',$sections['gapsHtml']);
        $body.=research_system_report_section('Contradictions & disputes',$sections['conflictsHtml']);
        $body.=research_system_report_section('Source risks that may affect conclusions',$sections['risksHtml']);
        $body.=research_system_report_section('Recommended follow-up',$sections['nextHtml']);
    } elseif($type==='source_freshness'){
        $html='<table><thead><tr><th>Source</th><th>Status</th><th>Version</th><th>Captured / checked</th><th>Changed in 30d</th></tr></thead><tbody>';
        foreach($sources as $src)$html.='<tr><td>'.$esc((string)($src['title']?:$src['domain']?:$src['public_id'])).'</td><td>'.$esc((string)$src['status']).'</td><td>'.(int)($src['version_number']??0).'</td><td>'.$esc((string)($src['captured_at']?:$src['last_checked_at']?:'')).'</td><td>'.(!empty($src['changed_30d'])?'Yes':'No').'</td></tr>';
        $html.='</tbody></table>';if(!$sources)$html=research_system_report_empty('No project Sources are recorded.');
        $body.=research_system_report_section('Source inventory & freshness',$html);
        $body.=research_system_report_section('Current source risks',$sections['risksHtml']);
        $events=(array)($s['monitoring']['events']??[]);$eh='<ul>';foreach(array_slice($events,0,20) as $e)$eh.=research_system_report_li((string)($e['summary']??$e['event_type']??'Monitoring event'),'',(string)($e['importance']??''));$eh.='</ul>';if(!$events)$eh=research_system_report_empty('No recent monitoring events are available.');
        $body.=research_system_report_section('Recent monitoring changes',$eh);
    } elseif($type==='entity_map'){
        $html='<table><thead><tr><th>Entity</th><th>Type</th><th>Status</th><th>Mentions</th><th>Relationships</th></tr></thead><tbody>';
        foreach($entities as $e)$html.='<tr><td>'.$esc((string)$e['canonical_name']).'</td><td>'.$esc((string)$e['entity_type']).'</td><td>'.$esc((string)$e['status']).'</td><td>'.(int)($e['mention_count']??0).'</td><td>'.(int)($e['relation_count']??0).'</td></tr>';
        $html.='</tbody></table>';if(!$entities)$html=research_system_report_empty('No structured entities have been identified yet.');
        $body.=research_system_report_section('Entity map',$html);
    } elseif($type==='timeline'){
        $timeline=(array)($s['timeline']??[]);$shownTimeline=array_slice($timeline,0,function_exists('research_report_studio_limit')?research_report_studio_limit($s,40,100,250):100);$html='<ol>';foreach($shownTimeline as $e)$html.=research_system_report_li((string)($e['title']??$e['event']??'Research event'),(string)($e['detail']??''),(string)($e['created_at']??''));$html.='</ol>'.research_system_report_limit_note(count($shownTimeline),count($timeline),'timeline events');if(!$timeline)$html=research_system_report_empty('No Research timeline events are available.');
        $body.=research_system_report_section('Research timeline',$html);
    } elseif(in_array($type,['research_evolution','what_changed','confidence_contradictions','open_questions_evolution','entity_theme_evolution'],true)){
        $body.=function_exists('research_longitudinal_render_report')?research_longitudinal_render_report($type,$s):research_system_report_section('Longitudinal Research intelligence',research_system_report_empty('No longitudinal Research baseline is available yet.'));
    } elseif($type==='action_plan'){
        $body.=research_system_report_section('Priority Research actions',$sections['nextHtml']);
        $tasks=(array)($s['tasks']['items']??[]);$html='<ul>';foreach($tasks as $t)$html.=research_system_report_li((string)$t['title'],(string)($t['description']??''),(string)$t['priority'].' · '.(string)$t['status']);$html.='</ul>';if(!$tasks)$html=research_system_report_empty('No active Research tasks are currently recorded.');
        $body.=research_system_report_section('Active tasks',$html);
        $body.=research_system_report_section('Evidence gaps',$sections['gapsHtml']);
        $body.=research_system_report_section('Contradictions',$sections['conflictsHtml']);
        $body.=research_system_report_section('Workflow, outcomes & downstream impact',research_system_report_extended_html($s,['workflow','change_impact','reviews']));
    } else {
        $body.=research_system_report_section('Research state',research_system_report_summary_block($s));
        $body.=research_system_report_section('Findings',$sections['findingsHtml']);
        $body.=research_system_report_section('Claims & verification',research_system_report_claim_matrix($claims));
        $body.=research_system_report_section('Evidence gaps',$sections['gapsHtml']);
        $body.=research_system_report_section('Contradictions',$sections['conflictsHtml']);
        $body.=research_system_report_section('Source risks',$sections['risksHtml']);
        $shownEntities=array_slice($entities,0,function_exists('research_report_studio_limit')?research_report_studio_limit($s,12,30,80):30);$entitiesHtml='<ul>';foreach($shownEntities as $e)$entitiesHtml.=research_system_report_li((string)$e['canonical_name'],(string)($e['description']??''),(string)$e['entity_type']);$entitiesHtml.='</ul>'.research_system_report_limit_note(count($shownEntities),count($entities),'entities');if(!$entities)$entitiesHtml=research_system_report_empty('No structured entities are currently available.');
        $body.=research_system_report_section('Entities',$entitiesHtml);
        $body.=research_system_report_section('Next actions',$sections['nextHtml']);
        $body.=research_system_report_section('Extended Research intelligence',research_system_report_extended_html($s));
    }
    $body.='<hr><p><small>This System Report is a deterministic processing of the Research Agent\'s authorized project data at the recorded data-state hash. Source evidence, Claims, Findings and authoritative Research objects remain independently inspectable.</small></p>';
    return ['title'=>$title,'html'=>$body,'summary'=>(string)$meta['description']];
}


function research_system_report_folder(PDO $pdo,array $viewer,array $project): array {
    $q=$pdo->prepare("SELECT public_id FROM research_workspace_objects WHERE project_id=? AND parent_id IS NULL AND object_type='folder' AND status='active' AND title='System Reports' ORDER BY id LIMIT 1");
    $q->execute([(int)$project['id']]);$public=(string)($q->fetchColumn()?:'');
    if($public!==''){ $row=research_agent_workspace_object($pdo,$viewer,$public,false);if($row)return $row; }
    return research_agent_workspace_create_folder($pdo,$viewer,$project,'System Reports');
}

function research_system_report_nonfatal_event(PDO $pdo,int $reportId,string $event,?int $userId=null): void {
    try{research_system_report_event($pdo,$reportId,$event,'system',$userId);}catch(Throwable $ignored){}
}
function research_system_report_queue_followups(PDO $pdo,int $reportId,int $projectId,int $userId,string $reason): void {
    if(function_exists('research_retrieval_queue_project')){
        try{research_retrieval_queue_project($pdo,$projectId);}
        catch(Throwable $e){research_system_report_nonfatal_event($pdo,$reportId,'retrieval_queue_failed',$userId);error_log('[Annotated System Report retrieval-queue] '.$e->getMessage());}
    }
    if(function_exists('research_autonomy_queue_project')){
        try{research_autonomy_queue_project($pdo,$projectId,$userId,'workspace_change',$reason);}
        catch(Throwable $e){research_system_report_nonfatal_event($pdo,$reportId,'autonomy_queue_failed',$userId);error_log('[Annotated System Report autonomy-queue] '.$e->getMessage());}
    }
}

function research_system_report_generate(PDO $pdo,array $config,array $viewer,string $agentPublic,string $reportType,string $customTitle='',bool $byAgent=false,?int $parentMessageId=null,array $studioOptions=[],?int $presetId=null,?int $refreshedFromId=null,string $generationMode=''): array {
    if(!research_report_studio_ready($pdo))throw new RuntimeException('Research Agent Report Studio requires the latest database upgrade.');
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);
    $project=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);if(!$project)throw new RuntimeException('Research Agent workspace not found.');
    research_agent_workspace_require_write($project);$type=research_system_report_type($reportType);
    $options=research_report_studio_options($studioOptions);
    $snapshot=research_system_report_snapshot($pdo,$config,$viewer,$agent);
    $snapshot=research_report_studio_scope_snapshot($pdo,$config,$viewer,$agent,$snapshot,$options);
    $render=research_system_report_render((string)$type['key'],$snapshot);
    [$render,$sections]=research_report_studio_filter_render($render,$options);
    $title=mb_substr(trim($customTitle)!==''?trim($customTitle):(string)$render['title'],0,240);if($title==='')$title=(string)$type['label'];
    $refBundle=research_system_report_ref_bundle($snapshot);$refs=$refBundle['refs'];$manifest=research_report_studio_manifest($snapshot);
    $metrics=[
      'sources'=>count((array)$snapshot['sources']),'claims'=>count((array)$snapshot['claims']),'findings'=>count((array)$snapshot['findings']),
      'entities'=>count((array)$snapshot['entities']),'claim_relations'=>count((array)$snapshot['claim_relations']),'entity_relations'=>count((array)$snapshot['entity_relations']),
      'evidence_refs'=>count($refs),'provenance_total_unique'=>(int)$refBundle['total_unique'],'provenance_reference_limit'=>(int)$refBundle['limit'],'provenance_truncated'=>$refBundle['truncated']?1:0,
      'gaps'=>count((array)($snapshot['workspace']['gaps']??[])),'conflicts'=>count((array)($snapshot['workspace']['conflicts']??[])),
      'coverage_truncated'=>count((array)($snapshot['coverage']['truncated']??[])),'diagnostic_count'=>count((array)($snapshot['diagnostics']??[])),
      'extended_intelligence_contexts'=>count((array)($snapshot['extended_intelligence']??[])),'section_count'=>count($sections)
    ];
    $parameters=['depth'=>$options['depth'],'focus_query'=>$options['focus_query'],'date_from'=>$options['date_from'],'date_to'=>$options['date_to'],'include_sections'=>$options['include_sections']];
    $scope=['source_ids'=>$options['source_ids'],'claim_ids'=>$options['claim_ids'],'finding_ids'=>$options['finding_ids'],'entity_ids'=>$options['entity_ids'],'folder_ids'=>$options['folder_ids']];
    $mode=$generationMode!==''?$generationMode:($byAgent?'agent':'user');if(!in_array($mode,['user','agent','program','legacy'],true))$mode='user';
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM research_projects WHERE id=? FOR UPDATE');$lock->execute([(int)$project['id']]);if(!$lock->fetchColumn())throw new RuntimeException('Research project not found.');
        $public=ulid_like();$pdo->prepare("INSERT INTO research_system_reports(public_id,research_agent_id,project_id,requested_by_user_id,report_type,title,rendered_html,rendered_summary,parameters_json,scope_json,sections_json,knowledge_manifest_json,status,generation_mode,document_object_id,input_state_hash,freshness_state,refreshed_from_report_id,preset_id,evidence_refs_json,metrics_json)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'ready',?,NULL,?,'current',?,?,?,?)")
          ->execute([$public,(int)$agent['id'],(int)$project['id'],(int)$viewer['id'],(string)$type['key'],$title,(string)$render['html'],(string)$render['summary'],
            json_encode($parameters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($scope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            json_encode($sections,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            $mode,(string)$snapshot['state_hash'],$refreshedFromId,$presetId,
            json_encode($refs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($metrics,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $reportId=(int)$pdo->lastInsertId();
        research_system_report_event($pdo,$reportId,'generated',$byAgent?'agent':'user',(int)$viewer['id'],['report_type'=>$type['key'],'state_hash'=>$snapshot['state_hash'],'depth'=>$options['depth'],'preset_id'=>$presetId,'refreshed_from_report_id'=>$refreshedFromId]);
        if($refreshedFromId)$pdo->prepare("UPDATE research_system_reports SET freshness_state='changed' WHERE id=? AND status='ready'")->execute([$refreshedFromId]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    research_system_report_queue_followups($pdo,$reportId,(int)$project['id'],(int)$viewer['id'],'A Research Report Run was generated.');
    return research_system_report_access($pdo,$viewer,$public)??['public_id'=>$public,'report_type'=>$type['key'],'title'=>$title,'metrics'=>$metrics,'evidence_refs'=>$refs,'document_public_id'=>null];
}

function research_system_report_archive(PDO $pdo,array $viewer,string $publicId,string $expectedAgentPublic=''): array {
    $report=research_system_report_access($pdo,$viewer,$publicId);if(!$report)throw new RuntimeException('System Report not found.');
    if($expectedAgentPublic!==''&&!hash_equals((string)$report['agent_public_id'],$expectedAgentPublic))throw new RuntimeException('System Report does not belong to this Research Agent.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)$report['agent_public_id']);if(!$project)throw new RuntimeException('System Report not found.');
    research_agent_workspace_require_write($project);
    if((string)$report['status']==='archived')return $report;
    $pdo->prepare("UPDATE research_system_reports SET status='archived',updated_at=NOW() WHERE id=?")->execute([(int)$report['id']]);
    research_system_report_event($pdo,(int)$report['id'],'archived','user',(int)$viewer['id']);
    research_system_report_queue_followups($pdo,(int)$report['id'],(int)$report['project_id'],(int)$viewer['id'],'A Research System Report was archived.');
    return research_system_report_access($pdo,$viewer,$publicId)??$report;
}

function research_system_report_knowledge(PDO $pdo,array $config,array $viewer,string $agentPublic): array {
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);$snapshot=research_system_report_snapshot($pdo,$config,$viewer,$agent);
    return ['agent'=>$agent,'snapshot'=>$snapshot,'reports'=>research_system_report_list($pdo,$viewer,$agentPublic,12),'report_types'=>research_system_report_types()];
}
