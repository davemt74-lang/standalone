<?php
declare(strict_types=1);

function research_autonomy_ready(PDO $pdo): bool {
    try{
        foreach(['research_autonomy_runs','research_autonomy_jobs','research_autonomy_artifacts','research_autonomy_observations','research_autonomy_reports'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}

function research_autonomy_agent_by_id(PDO $pdo,int $agentId): ?array {
    $q=$pdo->prepare("SELECT ra.*,rp.public_id project_public_id,rp.title project_title,rp.owner_user_id project_owner_user_id,c.public_id conversation_public_id
      FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id JOIN conversations c ON c.id=ra.conversation_id
      WHERE ra.id=? AND ra.status='active' AND rp.status='active' LIMIT 1");
    $q->execute([$agentId]);return $q->fetch()?:null;
}

function research_autonomy_owner(PDO $pdo,array $agent): array {
    $q=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$q->execute([(int)$agent['project_owner_user_id']]);$viewer=$q->fetch();
    if(!$viewer)throw new RuntimeException('Research Agent owner is unavailable.');
    return $viewer;
}

function research_autonomy_queue(PDO $pdo,int $agentId,?int $requestedByUserId=null,string $trigger='workspace_change',string $reason=''): void {
    if(!research_autonomy_ready($pdo))return;
    $allowed=['manual','workspace_change','schedule','research_change','recovery'];if(!in_array($trigger,$allowed,true))$trigger='workspace_change';
    $reason=mb_substr(trim($reason),0,500);$q=$pdo->prepare("SELECT project_id FROM research_agents WHERE id=? AND status='active' LIMIT 1");$q->execute([$agentId]);$projectId=(int)($q->fetchColumn()?:0);if($projectId<1)return;
    $pdo->prepare("INSERT INTO research_autonomy_jobs(research_agent_id,project_id,requested_by_user_id,trigger_type,reason,status,available_at)
      VALUES(?,?,?,?,?,'queued',NOW())
      ON DUPLICATE KEY UPDATE requested_by_user_id=COALESCE(VALUES(requested_by_user_id),requested_by_user_id),
      trigger_type=VALUES(trigger_type),reason=VALUES(reason),
      rerun_requested=CASE WHEN status='processing' THEN 1 ELSE rerun_requested END,
      status=CASE WHEN status='processing' THEN status ELSE 'queued' END,
      attempts=CASE WHEN status='processing' THEN attempts ELSE 0 END,
      claim_token=CASE WHEN status='processing' THEN claim_token ELSE NULL END,
      lease_expires_at=CASE WHEN status='processing' THEN lease_expires_at ELSE NULL END,
      available_at=CASE WHEN status='processing' THEN available_at ELSE NOW() END,
      started_at=CASE WHEN status='processing' THEN started_at ELSE NULL END,
      completed_at=CASE WHEN status='processing' THEN completed_at ELSE NULL END,
      last_error=CASE WHEN status='processing' THEN last_error ELSE NULL END")
      ->execute([$agentId,$projectId,$requestedByUserId,$trigger,$reason!==''?$reason:null]);
}

function research_autonomy_queue_project(PDO $pdo,int $projectId,?int $requestedByUserId=null,string $trigger='workspace_change',string $reason=''): void {
    if(!research_autonomy_ready($pdo))return;$q=$pdo->prepare("SELECT id FROM research_agents WHERE project_id=? AND status='active' LIMIT 1");$q->execute([$projectId]);$agentId=(int)($q->fetchColumn()?:0);if($agentId>0)research_autonomy_queue($pdo,$agentId,$requestedByUserId,$trigger,$reason);
}

function research_autonomy_input_state(PDO $pdo,int $projectId): array {
    $counts=[];
    foreach([
      'sources'=>"SELECT COUNT(DISTINCT ps.source_id),COALESCE(MAX(sv.id),0),COALESCE(MAX(UNIX_TIMESTAMP(s.updated_at)),0) FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.source_id=s.id WHERE ps.project_id=?",
      'annotations'=>"SELECT COUNT(*),COALESCE(MAX(a.id),0),COALESCE(MAX(UNIX_TIMESTAMP(a.updated_at)),0) FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=? AND a.status='published'",
      'claims'=>"SELECT COUNT(*),COALESCE(MAX(id),0),COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) FROM research_claims WHERE project_id=?",
      'claim_evidence'=>"SELECT COUNT(ce.id),COALESCE(MAX(ce.id),0),COALESCE(MAX(UNIX_TIMESTAMP(ce.created_at)),0) FROM claim_evidence ce JOIN research_claims rc ON rc.id=ce.claim_id WHERE rc.project_id=?",
      'findings'=>"SELECT COUNT(*),COALESCE(MAX(id),0),COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) FROM research_findings WHERE project_id=? AND status<>'archived'",
      'workspace_user'=>"SELECT COUNT(*),COALESCE(MAX(rwo.id),0),COALESCE(MAX(UNIX_TIMESTAMP(rwo.updated_at)),0) FROM research_workspace_objects rwo LEFT JOIN research_autonomy_artifacts raa ON raa.object_id=rwo.id WHERE rwo.project_id=? AND rwo.status='active' AND raa.id IS NULL"
    ] as $key=>$sql){$q=$pdo->prepare($sql);$q->execute([$projectId]);$row=$q->fetch(PDO::FETCH_NUM)?:[0,0,0];$counts[$key]=array_map('intval',$row);}
    $hash=hash('sha256',json_encode($counts,JSON_UNESCAPED_SLASHES));
    return ['hash'=>$hash,'counts'=>$counts];
}

function research_autonomy_managed_object(PDO $pdo,int $agentId,string $key): ?array {
    $q=$pdo->prepare("SELECT raa.*,rwo.public_id,rwo.object_type,rwo.title,rwo.status FROM research_autonomy_artifacts raa JOIN research_workspace_objects rwo ON rwo.id=raa.object_id
      WHERE raa.research_agent_id=? AND raa.management_key=? AND raa.status='active' LIMIT 1");$q->execute([$agentId,$key]);return $q->fetch()?:null;
}

function research_autonomy_register_artifact(PDO $pdo,array $agent,array $object,string $key,string $kind,string $purpose,int $runId): void {
    $pdo->prepare("INSERT INTO research_autonomy_artifacts(research_agent_id,project_id,object_id,management_key,managed_kind,purpose,created_by_run_id,last_managed_run_id)
      VALUES(?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE purpose=VALUES(purpose),last_managed_run_id=VALUES(last_managed_run_id),status='active',updated_at=NOW()")
      ->execute([(int)$agent['id'],(int)$agent['project_id'],(int)$object['id'],$key,$kind,mb_substr($purpose,0,500),$runId,$runId]);
}

function research_autonomy_folder(PDO $pdo,array $viewer,array $agent,array $project,int $runId): array {
    $managed=research_autonomy_managed_object($pdo,(int)$agent['id'],'agent-research-root');
    if($managed&&$managed['status']==='active')return research_agent_workspace_object($pdo,$viewer,(string)$managed['public_id'],false)??$managed;
    $folder=research_agent_workspace_create_folder($pdo,$viewer,$project,'Agent Research');
    research_autonomy_register_artifact($pdo,$agent,$folder,'agent-research-root','folder','Autonomous Research Agent managed workspace.',$runId);
    return $folder;
}

function research_autonomy_scan(PDO $pdo,array $agent,int $runId): array {
    $projectId=(int)$agent['project_id'];$seen=[];$open=[];
    $q=$pdo->prepare("SELECT rc.id,rc.public_id,rc.statement,rc.status,
      COUNT(ce.id) evidence_count,
      SUM(CASE WHEN ce.relationship='supports' THEN 1 ELSE 0 END) supports_count,
      SUM(CASE WHEN ce.relationship='contradicts' THEN 1 ELSE 0 END) contradicts_count
      FROM research_claims rc LEFT JOIN claim_evidence ce ON ce.claim_id=rc.id
      WHERE rc.project_id=? GROUP BY rc.id ORDER BY rc.updated_at DESC,rc.id DESC LIMIT 300");
    $q->execute([$projectId]);
    foreach($q->fetchAll() as $claim){
        $type=null;$severity='medium';$title='';$detail='';
        $evidence=(int)$claim['evidence_count'];$contra=(int)$claim['contradicts_count'];$supports=(int)$claim['supports_count'];
        if($evidence===0){$type='evidence_gap';$title='Claim needs evidence';$detail=(string)$claim['statement'];$severity='high';}
        elseif($contra>0&&$supports>0){$type='contradiction';$title='Claim has conflicting evidence';$detail=(string)$claim['statement'];$severity='high';}
        elseif(in_array((string)$claim['status'],['disputed','contradicted'],true)){$type='contradiction';$title='Claim is marked '.(string)$claim['status'];$detail=(string)$claim['statement'];$severity='high';}
        if(!$type)continue;
        $fp=hash('sha256',$type.'|claim|'.$claim['public_id']);$seen[$fp]=true;
        $pdo->prepare("INSERT INTO research_autonomy_observations(public_id,research_agent_id,project_id,last_run_id,observation_type,subject_type,subject_public_id,fingerprint,title,detail,severity,status,evidence_refs_json)
          VALUES(?,?,?,?,?,'claim',?,?,?,?,?,'open',?)
          ON DUPLICATE KEY UPDATE last_run_id=VALUES(last_run_id),title=VALUES(title),detail=VALUES(detail),severity=VALUES(severity),status='open',resolved_at=NULL,last_seen_at=NOW(),updated_at=NOW()")
          ->execute([ulid_like(),(int)$agent['id'],$projectId,$runId,$type,(string)$claim['public_id'],$fp,$title,mb_substr($detail,0,10000),$severity,json_encode([['type'=>'claim','id'=>(string)$claim['public_id']]],JSON_UNESCAPED_SLASHES)]);
        $open[]=['type'=>$type,'subject_type'=>'claim','subject_public_id'=>(string)$claim['public_id'],'title'=>$title,'detail'=>$detail,'severity'=>$severity];
    }
    $q=$pdo->prepare("SELECT fingerprint FROM research_autonomy_observations WHERE research_agent_id=? AND status='open'");$q->execute([(int)$agent['id']]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $fp)if(!isset($seen[$fp]))$pdo->prepare("UPDATE research_autonomy_observations SET status='resolved',resolved_at=NOW(),updated_at=NOW() WHERE research_agent_id=? AND fingerprint=?")->execute([(int)$agent['id'],$fp]);
    return $open;
}

function research_autonomy_recent_evidence(PDO $pdo,int $projectId,int $limit=12): array {
    $limit=max(1,min(30,$limit));$q=$pdo->prepare("SELECT object_type,object_public_id,title,folder_public_id,source_updated_at,updated_at FROM research_retrieval_documents
      WHERE project_id=? ORDER BY COALESCE(source_updated_at,updated_at) DESC,id DESC LIMIT ".$limit);$q->execute([$projectId]);return $q->fetchAll()?:[];
}

function research_autonomy_report_html(array $agent,array $state,array $observations,array $recent): string {
    $esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $gaps=array_values(array_filter($observations,fn($x)=>$x['type']==='evidence_gap'));
    $conflicts=array_values(array_filter($observations,fn($x)=>$x['type']==='contradiction'));
    $counts=$state['counts'];$html='<h1>Living Research Report</h1><p>Maintained by '.$esc($agent['name']).'. Updated '.gmdate('Y-m-d H:i').' UTC.</p>';
    $html.='<h2>Current knowledge</h2><ul><li>Sources: '.(int)$counts['sources'][0].'</li><li>Annotations: '.(int)$counts['annotations'][0].'</li><li>Claims: '.(int)$counts['claims'][0].'</li><li>Findings: '.(int)$counts['findings'][0].'</li></ul>';
    $html.='<h2>Evidence gaps</h2>';if(!$gaps)$html.='<p>No open evidence gaps detected in structured claims.</p>';else{$html.='<ul>';foreach(array_slice($gaps,0,30) as $x)$html.='<li><strong>'.$esc($x['title']).'</strong>: '.$esc($x['detail']).' ['.$esc($x['subject_public_id']).']</li>';$html.='</ul>';}
    $html.='<h2>Contradictions</h2>';if(!$conflicts)$html.='<p>No open structured contradictions detected.</p>';else{$html.='<ul>';foreach(array_slice($conflicts,0,30) as $x)$html.='<li><strong>'.$esc($x['title']).'</strong>: '.$esc($x['detail']).' ['.$esc($x['subject_public_id']).']</li>';$html.='</ul>';}
    $html.='<h2>Recent evidence</h2>';if(!$recent)$html.='<p>No indexed evidence is available yet.</p>';else{$html.='<ul>';foreach($recent as $x)$html.='<li>'.$esc($x['title']).' <em>'.$esc($x['object_type']).'</em> ['.$esc($x['object_public_id']).']</li>';$html.='</ul>';}
    $html.='<h2>Next research targets</h2><p>'.($gaps?'Resolve the highest-severity evidence gaps first. ':'').($conflicts?'Compare conflicting evidence and record a resolution or explicit uncertainty. ':'').(!$gaps&&!$conflicts?'Continue monitoring for meaningful changes and new evidence.':'').'</p>';
    return $html;
}

function research_autonomy_upsert_report(PDO $pdo,array $viewer,array $agent,array $project,array $folder,array $state,array $observations,array $recent,int $runId): array {
    $managed=research_autonomy_managed_object($pdo,(int)$agent['id'],'living-report');$html=research_autonomy_report_html($agent,$state,$observations,$recent);$summary='Autonomous living report: '.count(array_filter($observations,fn($x)=>$x['type']==='evidence_gap')).' evidence gap(s), '.count(array_filter($observations,fn($x)=>$x['type']==='contradiction')).' contradiction(s).';
    if($managed){
        $obj=research_agent_workspace_object($pdo,$viewer,(string)$managed['public_id'],false);
        if(!$obj||$obj['object_type']!=='document')throw new RuntimeException('Managed living report is unavailable.');
        $doc=research_agent_workspace_save_document($pdo,$viewer,(string)$obj['public_id'],['title'=>'Living Research Report','content_html'=>$html,'summary'=>$summary,'base_revision'=>(int)$obj['revision_number']]);
    }else{
        $doc=research_agent_workspace_create_document($pdo,$viewer,$project,['title'=>'Living Research Report','document_type'=>'report','content_html'=>$html,'summary'=>$summary,'parent_id'=>(string)$folder['public_id']],true);
        research_autonomy_register_artifact($pdo,$agent,$doc,'living-report','report','Continuously maintained Research Agent report.',$runId);
    }
    $obj=research_agent_workspace_object($pdo,$viewer,(string)$doc['public_id'],false)??$doc;
    $pdo->prepare("INSERT INTO research_autonomy_reports(research_agent_id,project_id,object_id,report_key,evidence_state_hash,last_run_id)
      VALUES(?,?,?,'living-report',?,?)
      ON DUPLICATE KEY UPDATE object_id=VALUES(object_id),evidence_state_hash=VALUES(evidence_state_hash),last_run_id=VALUES(last_run_id),updated_at=NOW()")
      ->execute([(int)$agent['id'],(int)$agent['project_id'],(int)$obj['id'],$state['hash'],$runId]);
    $pdo->prepare("UPDATE research_autonomy_artifacts SET last_managed_run_id=?,updated_at=NOW() WHERE research_agent_id=? AND management_key='living-report'")->execute([$runId,(int)$agent['id']]);
    return $obj;
}

function research_autonomy_upsert_attention_sticky(PDO $pdo,array $viewer,array $agent,array $project,array $folder,array $observations,int $runId): array {
    $gaps=count(array_filter($observations,fn($x)=>$x['type']==='evidence_gap'));$conflicts=count(array_filter($observations,fn($x)=>$x['type']==='contradiction'));
    $body=$gaps||$conflicts?"Research attention
{$gaps} evidence gap(s)
{$conflicts} contradiction(s)
See Living Research Report for details.":"Research attention
No open structured evidence gaps or contradictions.";
    $managed=research_autonomy_managed_object($pdo,(int)$agent['id'],'attention-sticky');
    if($managed){$obj=research_agent_workspace_update_sticky($pdo,$viewer,(string)$managed['public_id'],['body'=>$body,'color'=>($gaps||$conflicts)?'yellow':'green']);}
    else{$obj=research_agent_workspace_create_sticky($pdo,$viewer,$project,['title'=>'Research Attention','body'=>$body,'color'=>($gaps||$conflicts)?'yellow':'green','parent_id'=>(string)$folder['public_id'],'x'=>40,'y'=>120]);research_autonomy_register_artifact($pdo,$agent,$obj,'attention-sticky','sticky','Research Agent attention summary.',$runId);}
    $pdo->prepare("UPDATE research_autonomy_artifacts SET last_managed_run_id=?,updated_at=NOW() WHERE research_agent_id=? AND management_key='attention-sticky'")->execute([$runId,(int)$agent['id']]);
    return $obj;
}

function research_autonomy_chat_update(PDO $pdo,array $agent,array $report,array $state,array $observations,int $runId): ?array {
    $gaps=count(array_filter($observations,fn($x)=>$x['type']==='evidence_gap'));$conflicts=count(array_filter($observations,fn($x)=>$x['type']==='contradiction'));
    $updateHash=hash('sha256',$state['hash'].'|'.$gaps.'|'.$conflicts.'|'.(string)$report['public_id']);
    $q=$pdo->prepare("SELECT last_posted_state_hash FROM research_autonomy_reports WHERE research_agent_id=?");$q->execute([(int)$agent['id']);$last=(string)($q->fetchColumn()?:'');if($last!==''&&hash_equals($last,$updateHash))return null;
    $body='Research workspace updated: '.$gaps.' evidence gap(s), '.$conflicts.' contradiction(s). I refreshed the Living Research Report and Research Attention note.';
    $conversation=['id'=>(int)$agent['conversation_id'],'public_id'=>(string)$agent['conversation_public_id']];
    $msg=agent_chat_insert_agent_message($pdo,$conversation,$body,0);
    $pdo->prepare("INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,?,?,?)")
      ->execute([(int)$msg['id'],'document',(string)$report['public_id'],json_encode(['label'=>'Living Research Report','autonomous_run_id'=>$runId],JSON_UNESCAPED_SLASHES)]);
    $pdo->prepare("UPDATE research_autonomy_reports SET last_posted_state_hash=?,updated_at=NOW() WHERE research_agent_id=?")->execute([$updateHash,(int)$agent['id']]);
    return $msg;
}

function research_autonomy_run(PDO $pdo,array $config,array $agent,?int $requestedByUserId,string $trigger,string $reason=''): array {
    $viewer=research_autonomy_owner($pdo,$agent);$project=project_access($pdo,(int)$viewer['id'],(string)$agent['project_public_id']);if(!$project||!project_can_write($project))throw new RuntimeException('Research Agent no longer has a writable project.');
    $state=research_autonomy_input_state($pdo,(int)$agent['project_id']);
    $q=$pdo->prepare("SELECT output_state_hash FROM research_autonomy_runs WHERE research_agent_id=? AND status='completed' ORDER BY id DESC LIMIT 1");$q->execute([(int)$agent['id']]);$previous=(string)($q->fetchColumn()?:'');
    $runPublic=ulid_like();$pdo->prepare("INSERT INTO research_autonomy_runs(public_id,research_agent_id,project_id,requested_by_user_id,trigger_type,reason,input_state_hash,status) VALUES(?,?,?,?,?,?,?,'processing')")
      ->execute([$runPublic,(int)$agent['id'],(int)$agent['project_id'],$requestedByUserId,$trigger,mb_substr($reason,0,500)?:null,$state['hash']]);$runId=(int)$pdo->lastInsertId();
    if($trigger!=='manual'&&$previous!==''&&hash_equals($previous,$state['hash'])){
        $pdo->prepare("UPDATE research_autonomy_runs SET status='skipped',output_state_hash=?,summary='No authoritative Research changes detected.',metrics_json=?,completed_at=NOW() WHERE id=?")
          ->execute([$state['hash'],json_encode(['changed'=>false]),$runId]);
        return ['run_id'=>$runId,'public_id'=>$runPublic,'status'=>'skipped','changed'=>false];
    }
    try{
        $folder=research_autonomy_folder($pdo,$viewer,$agent,$project,$runId);
        $observations=research_autonomy_scan($pdo,$agent,$runId);
        $recent=research_retrieval_ready($pdo)?research_autonomy_recent_evidence($pdo,(int)$agent['project_id'],12):[];
        $report=research_autonomy_upsert_report($pdo,$viewer,$agent,$project,$folder,$state,$observations,$recent,$runId);
        $sticky=research_autonomy_upsert_attention_sticky($pdo,$viewer,$agent,$project,$folder,$observations,$runId);
        $message=research_autonomy_chat_update($pdo,$agent,$report,$state,$observations,$runId);
        if(function_exists('research_retrieval_queue_project'))research_retrieval_queue_project($pdo,(int)$agent['project_id']);
        $metrics=['changed'=>true,'observations'=>count($observations),'evidence_gaps'=>count(array_filter($observations,fn($x)=>$x['type']==='evidence_gap')),'contradictions'=>count(array_filter($observations,fn($x)=>$x['type']==='contradiction')),'report_public_id'=>$report['public_id'],'attention_sticky_public_id'=>$sticky['public_id'],'chat_update'=>!empty($message)];
        $summary='Autonomous Research workspace refreshed with '.(int)$metrics['evidence_gaps'].' evidence gap(s) and '.(int)$metrics['contradictions'].' contradiction(s).';
        $pdo->prepare("UPDATE research_autonomy_runs SET status='completed',output_state_hash=?,summary=?,metrics_json=?,completed_at=NOW() WHERE id=?")
          ->execute([$state['hash'],$summary,json_encode($metrics,JSON_UNESCAPED_SLASHES),$runId]);
        return ['run_id'=>$runId,'public_id'=>$runPublic,'status'=>'completed','changed'=>true,'metrics'=>$metrics,'summary'=>$summary];
    }catch(Throwable $e){
        $pdo->prepare("UPDATE research_autonomy_runs SET status='failed',last_error=?,completed_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,1000),$runId]);throw $e;
    }
}

function research_autonomy_status(PDO $pdo,array $viewer,string $agentPublic): ?array {
    $agent=research_agent_access($pdo,$viewer,$agentPublic);if(!$agent)return null;
    $q=$pdo->prepare("SELECT public_id,status,trigger_type,summary,metrics_json,started_at,completed_at,last_error FROM research_autonomy_runs WHERE research_agent_id=? ORDER BY id DESC LIMIT 1");$q->execute([(int)$agent['id']]);$run=$q->fetch()?:null;
    if($run)$run['metrics']=json_decode((string)($run['metrics_json']??''),true)?:[];
    $q=$pdo->prepare("SELECT observation_type,severity,status,COUNT(*) total FROM research_autonomy_observations WHERE research_agent_id=? GROUP BY observation_type,severity,status");$q->execute([(int)$agent['id']]);$observations=$q->fetchAll()?:[];
    $q=$pdo->prepare("SELECT rwo.public_id,rwo.title,rwd.revision_number,rar.evidence_state_hash,rar.updated_at FROM research_autonomy_reports rar JOIN research_workspace_objects rwo ON rwo.id=rar.object_id JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id WHERE rar.research_agent_id=? LIMIT 1");$q->execute([(int)$agent['id']]);$report=$q->fetch()?:null;
    return ['agent_public_id'=>$agentPublic,'last_run'=>$run,'observations'=>$observations,'report'=>$report];
}
