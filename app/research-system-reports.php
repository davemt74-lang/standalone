<?php
declare(strict_types=1);

/**
 * Phase 64 — Research Agent Knowledge & System Reports
 *
 * System Reports are deterministic views over authoritative Research project
 * state. Every generated report is stored as a normal versioned Research Doc.
 */

function research_system_reports_ready(PDO $pdo): bool {
    try{
        return installer_table_exists($pdo,'research_system_reports')
            &&installer_table_exists($pdo,'research_system_report_events')
            &&research_agent_workspace_ready($pdo);
    }catch(Throwable $e){return false;}
}

function research_system_report_types(): array {
    return [
      'research_brief'=>['label'=>'Research Brief','description'=>'A concise state-of-research briefing: strongest findings, evidence, risks, gaps and next actions.','category'=>'overview'],
      'evidence_audit'=>['label'=>'Evidence Audit','description'=>'Audits the evidence corpus, source coverage, provenance, freshness and unsupported areas.','category'=>'evidence'],
      'claims_verification'=>['label'=>'Claims & Verification','description'=>'Processes structured Claims by verification state, evidence depth, support and contradiction.','category'=>'knowledge'],
      'contradictions_gaps'=>['label'=>'Contradictions & Gaps','description'=>'Focuses on disputed knowledge, conflicting evidence and missing evidence.','category'=>'intelligence'],
      'source_freshness'=>['label'=>'Source Freshness & Change','description'=>'Surfaces source recency, changed evidence, source risks and downstream research impact.','category'=>'evidence'],
      'entity_map'=>['label'=>'Entities & Relationships','description'=>'Summarizes people, companies, organizations, places, topics and relationships in the project.','category'=>'knowledge'],
      'timeline'=>['label'=>'Research Timeline','description'=>'Reconstructs the project chronologically from Claims, evidence, Findings and source changes.','category'=>'history'],
      'action_plan'=>['label'=>'Research Action Plan','description'=>'Turns current gaps, risks, monitoring signals and task state into prioritized follow-up.','category'=>'work'],
      'full_intelligence'=>['label'=>'Full Intelligence Report','description'=>'A comprehensive synthesis across evidence, Claims, Findings, entities, monitoring, work and open questions.','category'=>'overview'],
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
      rwo.public_id document_public_id,rwo.title document_title,rwd.revision_number document_revision
      FROM research_system_reports rsr
      JOIN research_agents ra ON ra.id=rsr.research_agent_id
      JOIN research_projects rp ON rp.id=rsr.project_id
      JOIN research_workspace_objects rwo ON rwo.id=rsr.document_object_id
      JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id
      WHERE rsr.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    if(!research_agent_access($pdo,$viewer,(string)$row['agent_public_id']))return null;
    $row['evidence_refs']=json_decode((string)($row['evidence_refs_json']??''),true)?:[];
    $row['metrics']=json_decode((string)($row['metrics_json']??''),true)?:[];
    return $row;
}

function research_system_report_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=50): array {
    if(!research_system_reports_ready($pdo))return [];
    $agent=research_system_report_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT rsr.public_id,rsr.report_type,rsr.title,rsr.status,rsr.input_state_hash,rsr.created_at,rsr.updated_at,
      rwo.public_id document_public_id,rwd.revision_number document_revision
      FROM research_system_reports rsr
      JOIN research_workspace_objects rwo ON rwo.id=rsr.document_object_id
      JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id
      WHERE rsr.research_agent_id=? ORDER BY rsr.created_at DESC,rsr.id DESC LIMIT ".$limit);
    $q->execute([(int)$agent['id']]);$rows=$q->fetchAll()?:[];$types=research_system_report_types();
    foreach($rows as &$row){$row['type_label']=$types[(string)$row['report_type']]['label']??ucwords(str_replace('_',' ',(string)$row['report_type']));}unset($row);
    return $rows;
}

function research_system_report_findings(PDO $pdo,int $projectId,int $limit=50): array {
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT rf.public_id,rf.title,rf.summary,rf.status,rf.updated_at,
      (SELECT COUNT(*) FROM finding_claims fc WHERE fc.finding_id=rf.id) claim_count
      FROM research_findings rf WHERE rf.project_id=? AND rf.status<>'archived'
      ORDER BY FIELD(rf.status,'published','final','draft'),rf.updated_at DESC LIMIT ".$limit);
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


function research_system_report_snapshot(PDO $pdo,array $config,array $viewer,array $agent): array {
    $projectId=(int)$agent['project_id'];$projectPublic=(string)$agent['project_public_id'];
    $workspace=function_exists('research_workspace_deterministic_snapshot')?research_workspace_deterministic_snapshot($pdo,$projectId):[];
    $claims=function_exists('research_workspace_claim_rows')?research_workspace_claim_rows($pdo,$projectId):[];
    $findings=research_system_report_findings($pdo,$projectId,60);
    $entities=function_exists('research_project_entity_rows')?research_project_entity_rows($pdo,$projectId):[];
    $timeline=function_exists('research_project_timeline')?research_project_timeline($pdo,$projectId):[];
    $monitoring=[];$monitorEvents=[];
    if(function_exists('research_monitor_ready')&&research_monitor_ready($pdo)){
        try{$monitoring=research_monitor_summary($pdo,$viewer,(string)$agent['public_id']);$monitorEvents=research_monitor_events($pdo,$viewer,(string)$agent['public_id'],30);}catch(Throwable $e){}
    }
    $tasks=[];$taskSummary=[];
    if(function_exists('research_tasks_ready')&&research_tasks_ready($pdo)){
        try{$taskSummary=research_task_summary($pdo,$viewer,(string)$agent['public_id']);$tasks=research_system_report_active_tasks($pdo,(int)$agent['id']);}catch(Throwable $e){}
    }
    $programs=[];$programSummary=[];
    if(function_exists('research_programs_ready')&&research_programs_ready($pdo)){
        try{$programSummary=research_program_summary($pdo,$viewer,(string)$agent['public_id']);$programs=research_system_report_active_programs($pdo,(int)$agent['id']);}catch(Throwable $e){}
    }
    $recent=[];$index=[];
    if(function_exists('research_retrieval_ready')&&research_retrieval_ready($pdo)){
        try{$search=research_retrieval_search($pdo,$config,$viewer,$projectPublic,'',[],24,false);$recent=$search['results']??[];$index=$search['index']??[];}catch(Throwable $e){}
    }
    $q=$pdo->prepare("SELECT s.public_id,s.title,s.domain,s.status,s.last_checked_at,sv.version_number,sv.captured_at,
      EXISTS(SELECT 1 FROM source_change_events sce WHERE sce.source_id=s.id AND sce.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) changed_30d
      FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id
      WHERE ps.project_id=? ORDER BY COALESCE(sv.captured_at,s.last_checked_at) DESC,s.id DESC LIMIT 100");
    $q->execute([$projectId]);$sourceRows=$q->fetchAll()?:[];

    $snapshot=[
      'schema'=>'annotated-system-report-v1',
      'agent'=>['public_id'=>(string)$agent['public_id'],'name'=>(string)$agent['name'],'description'=>(string)($agent['description']??'')],
      'project'=>['public_id'=>$projectPublic,'title'=>(string)$agent['project_title']],
      'workspace'=>$workspace,
      'claims'=>$claims,
      'findings'=>$findings,
      'entities'=>$entities,
      'sources'=>$sourceRows,
      'timeline'=>$timeline,
      'monitoring'=>['summary'=>$monitoring,'events'=>$monitorEvents],
      'tasks'=>['summary'=>$taskSummary,'items'=>$tasks],
      'programs'=>['summary'=>$programSummary,'items'=>$programs],
      'recent_evidence'=>$recent,
      'retrieval_index'=>$index,
    ];
    $snapshot['state_hash']=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    $snapshot['generated_at']=date('c');
    return $snapshot;
}

function research_system_report_refs(array $snapshot,int $limit=250): array {
    $refs=[];$seen=[];
    $push=function(string $type,string $id,?string $label=null)use(&$refs,&$seen,$limit): void{
        $id=trim($id);if($id===''||count($refs)>=$limit)return;$key=$type.':'.$id;if(isset($seen[$key]))return;$seen[$key]=true;
        $refs[]=['type'=>$type,'id'=>$id]+($label!==null&&$label!==''?['label'=>mb_substr($label,0,220)]:[]);
    };
    foreach((array)($snapshot['recent_evidence']??[]) as $r)$push((string)($r['object_type']??'evidence'),(string)($r['public_id']??''),(string)($r['title']??''));
    foreach((array)($snapshot['claims']??[]) as $r)$push('claim',(string)($r['public_id']??''),(string)($r['statement']??''));
    foreach((array)($snapshot['findings']??[]) as $r)$push('finding',(string)($r['public_id']??''),(string)($r['title']??''));
    foreach((array)($snapshot['entities']??[]) as $r)$push('entity',(string)($r['public_id']??''),(string)($r['canonical_name']??''));
    foreach((array)($snapshot['sources']??[]) as $r)$push('source',(string)($r['public_id']??''),(string)($r['title']??$r['domain']??''));
    return $refs;
}

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
    $findingsHtml='<ul>';foreach(array_slice($findings,0,12) as $x)$findingsHtml.=research_system_report_li((string)$x['title'],(string)($x['summary']??''),(string)($x['status']??''));$findingsHtml.='</ul>';if(!$findings)$findingsHtml=research_system_report_empty('No active Findings are recorded yet.');
    $gapsHtml='<ul>';foreach(array_slice($gaps,0,20) as $x)$gapsHtml.=research_system_report_li((string)($x['title']??'Evidence gap'),(string)($x['detail']??''),(string)($x['priority']??''));$gapsHtml.='</ul>';if(!$gaps)$gapsHtml=research_system_report_empty('No structured evidence gaps are currently detected.');
    $conflictsHtml='<ul>';foreach(array_slice($conflicts,0,20) as $x)$conflictsHtml.=research_system_report_li((string)($x['title']??'Conflict'),(string)($x['detail']??''),(string)($x['priority']??''));$conflictsHtml.='</ul>';if(!$conflicts)$conflictsHtml=research_system_report_empty('No structured contradictions are currently detected.');
    $risksHtml='<ul>';foreach(array_slice($risks,0,20) as $x)$risksHtml.=research_system_report_li((string)($x['title']??$x['domain']??'Source'),(string)($x['latest_diff']??''),!empty($x['target_changed'])?'changed':'review');$risksHtml.='</ul>';if(!$risks)$risksHtml=research_system_report_empty('No current source risks are detected.');
    $nextHtml='<ol>';foreach(array_slice($next,0,15) as $x)$nextHtml.=research_system_report_li((string)($x['title']??'Next step'),(string)($x['reason']??$x['detail']??''),(string)($x['priority']??''));$nextHtml.='</ol>';if(!$next)$nextHtml=research_system_report_empty('No deterministic next actions are currently queued by Research Intelligence.');
    $recentHtml='<ul>';foreach(array_slice($recent,0,24) as $r)$recentHtml.=research_system_report_li((string)($r['title']??'Evidence'),(string)($r['snippet']??''),strtoupper((string)($r['object_type']??'evidence')).(!empty($r['locator_label'])?' · '.$r['locator_label']:''));$recentHtml.='</ul>';if(!$recent)$recentHtml=research_system_report_empty('No indexed Research evidence is available.');
    return compact('findingsHtml','gapsHtml','conflictsHtml','risksHtml','nextHtml','recentHtml');
}

function research_system_report_render(string $type,array $s): array {
    $meta=research_system_report_type($type);$esc='research_system_report_escape';
    $project=(string)($s['project']['title']??'Research');$title=$project.' — '.$meta['label'];
    $body='<h1>'.$esc($title).'</h1><p><strong>Research Agent:</strong> '.$esc((string)($s['agent']['name']??'Research Agent')).'<br><strong>Generated:</strong> '.$esc((string)($s['generated_at']??date('c'))).'<br><strong>Data state:</strong> <code>'.$esc(substr((string)($s['state_hash']??''),0,16)).'</code></p>';
    $sections=research_system_report_common_sections($s);$claims=(array)($s['claims']??[]);$entities=(array)($s['entities']??[]);$sources=(array)($s['sources']??[]);

    if($type==='research_brief'){
        $body.=research_system_report_section('Research state',research_system_report_summary_block($s));
        $body.=research_system_report_section('Strongest Findings',$sections['findingsHtml']);
        $body.=research_system_report_section('Open gaps',$sections['gapsHtml']);
        $body.=research_system_report_section('Contradictions',$sections['conflictsHtml']);
        $body.=research_system_report_section('Next research actions',$sections['nextHtml']);
    } elseif($type==='evidence_audit'){
        $body.=research_system_report_section('Evidence inventory',research_system_report_summary_block($s));
        $body.=research_system_report_section('Recent indexed evidence',$sections['recentHtml']);
        $body.=research_system_report_section('Source risks',$sections['risksHtml']);
        $body.=research_system_report_section('Unsupported or thinly supported knowledge',$sections['gapsHtml']);
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
        $timeline=(array)($s['timeline']??[]);$html='<ol>';foreach(array_slice($timeline,0,100) as $e)$html.=research_system_report_li((string)($e['title']??$e['event']??'Research event'),(string)($e['detail']??''),(string)($e['created_at']??''));$html.='</ol>';if(!$timeline)$html=research_system_report_empty('No Research timeline events are available.');
        $body.=research_system_report_section('Research timeline',$html);
    } elseif($type==='action_plan'){
        $body.=research_system_report_section('Priority Research actions',$sections['nextHtml']);
        $tasks=(array)($s['tasks']['items']??[]);$html='<ul>';foreach($tasks as $t)$html.=research_system_report_li((string)$t['title'],(string)($t['description']??''),(string)$t['priority'].' · '.(string)$t['status']);$html.='</ul>';if(!$tasks)$html=research_system_report_empty('No active Research tasks are currently recorded.');
        $body.=research_system_report_section('Active tasks',$html);
        $body.=research_system_report_section('Evidence gaps',$sections['gapsHtml']);
        $body.=research_system_report_section('Contradictions',$sections['conflictsHtml']);
    } else {
        $body.=research_system_report_section('Research state',research_system_report_summary_block($s));
        $body.=research_system_report_section('Findings',$sections['findingsHtml']);
        $body.=research_system_report_section('Claims & verification',research_system_report_claim_matrix($claims));
        $body.=research_system_report_section('Evidence gaps',$sections['gapsHtml']);
        $body.=research_system_report_section('Contradictions',$sections['conflictsHtml']);
        $body.=research_system_report_section('Source risks',$sections['risksHtml']);
        $entitiesHtml='<ul>';foreach(array_slice($entities,0,30) as $e)$entitiesHtml.=research_system_report_li((string)$e['canonical_name'],(string)($e['description']??''),(string)$e['entity_type']);$entitiesHtml.='</ul>';if(!$entities)$entitiesHtml=research_system_report_empty('No structured entities are currently available.');
        $body.=research_system_report_section('Entities',$entitiesHtml);
        $body.=research_system_report_section('Next actions',$sections['nextHtml']);
    }
    $body.='<hr><p><small>This System Report is a deterministic processing of the Research Agent\'s authorized project data at the recorded data-state hash. Source evidence, Claims, Findings and authoritative Research objects remain independently inspectable.</small></p>';
    return ['title'=>$title,'html'=>$body,'summary'=>(string)$meta['description']];
}
