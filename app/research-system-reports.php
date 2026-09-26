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
