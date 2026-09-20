<?php
declare(strict_types=1);

function change_impact_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_change_impact_reviews');}
    catch(Throwable $e){return false;}
}

function change_impact_final_decisions(): array {return ['reviewed_current','resolved','dismissed'];}
function change_impact_decisions(): array {
    return ['acknowledged'=>'Acknowledged','reviewed_current'=>'Reviewed — still current','needs_update'=>'Needs update','resolved'=>'Resolved','dismissed'=>'Dismissed'];
}

function change_impact_event(PDO $pdo,array $viewer,int $eventId): ?array {
    if($eventId<=0)return null;
    $q=$pdo->prepare("SELECT sce.*,s.public_id source_public_id,s.title source_title,s.canonical_url,s.domain,s.status source_status,
      pv.version_number previous_version_number,nv.version_number new_version_number,nv.captured_at new_version_captured_at
      FROM source_change_events sce JOIN sources s ON s.id=sce.source_id
      LEFT JOIN source_versions pv ON pv.id=sce.previous_version_id
      JOIN source_versions nv ON nv.id=sce.new_version_id
      WHERE sce.id=? LIMIT 1");
    $q->execute([$eventId]);$e=$q->fetch();if(!$e)return null;
    if(source_access($pdo,(string)$e['source_public_id'],$viewer)===null)return null;
    $e['id']=(int)$e['id'];$e['source_id']=(int)$e['source_id'];$e['new_version_id']=(int)$e['new_version_id'];$e['previous_version_id']=$e['previous_version_id']!==null?(int)$e['previous_version_id']:null;
    $e['target_changed']=(bool)$e['target_changed'];$e['affected_annotation_count']=(int)($e['affected_annotation_count']??0);
    return $e;
}

function change_impact_project_candidates(PDO $pdo,array $viewer,array $event): array {
    $sid=(int)$event['source_id'];$q=$pdo->prepare("SELECT DISTINCT rp.public_id
      FROM research_projects rp
      WHERE EXISTS(SELECT 1 FROM project_sources ps WHERE ps.project_id=rp.id AND ps.source_id=?)
         OR EXISTS(SELECT 1 FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=rp.id AND a.source_id=?)
         OR EXISTS(SELECT 1 FROM research_claims rc JOIN claim_evidence ce ON ce.claim_id=rc.id JOIN source_versions sv ON sv.id=ce.source_version_id WHERE rc.project_id=rp.id AND sv.source_id=?)
      ORDER BY rp.updated_at DESC,rp.id DESC");
    $q->execute([$sid,$sid,$sid]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$p=project_access($pdo,(int)$viewer['id'],(string)$public);if($p)$out[]=$p;}return $out;
}

function change_impact_review_map(PDO $pdo,array $viewer,int $eventId): array {
    if(!change_impact_ready($pdo))return [];$q=$pdo->prepare("SELECT rcir.*,rp.public_id project_public_id FROM research_change_impact_reviews rcir LEFT JOIN research_projects rp ON rp.id=rcir.project_id WHERE rcir.user_id=? AND rcir.source_change_event_id=?");$q->execute([$viewer['id'],$eventId]);$out=[];
    foreach($q->fetchAll() as $r){if(!empty($r['project_public_id'])&&!project_access($pdo,(int)$viewer['id'],(string)$r['project_public_id']))continue;$out[$r['object_type'].':'.$r['object_public_id']]=$r;}return $out;
}

function change_impact_annotation_rows(PDO $pdo,array $event,array $project): array {
    $q=$pdo->prepare("SELECT a.public_id,a.created_at,sai.impact_type,sai.previous_excerpt,sai.current_excerpt
      FROM source_annotation_impacts sai JOIN annotations a ON a.id=sai.annotation_id
      JOIN project_annotations pa ON pa.annotation_id=a.id AND pa.project_id=?
      WHERE sai.source_change_event_id=? AND a.status IN ('published','restricted')
      ORDER BY FIELD(sai.impact_type,'source_unavailable','passage_missing','passage_changed','source_restored','source_updated'),a.id");
    $q->execute([$project['id'],$event['id']]);return $q->fetchAll();
}

function change_impact_claim_rows(PDO $pdo,array $event,array $project): array {
    $q=$pdo->prepare("SELECT DISTINCT rc.public_id,rc.statement,rc.status,rc.claim_type,rc.updated_at,
      ce.public_id evidence_public_id,ce.relationship,ev.version_number evidence_version_number,nv.version_number current_version_number
      FROM research_claims rc
      JOIN claim_evidence ce ON ce.claim_id=rc.id
      JOIN source_versions ev ON ev.id=ce.source_version_id
      JOIN source_versions nv ON nv.id=?
      WHERE rc.project_id=? AND ev.source_id=? AND ev.version_number<nv.version_number
      ORDER BY FIELD(ce.relationship,'primary','contradicts','supports','context'),rc.updated_at DESC,rc.id DESC");
    $q->execute([$event['new_version_id'],$project['id'],$event['source_id']]);$group=[];
    foreach($q->fetchAll() as $r){$id=(string)$r['public_id'];if(!isset($group[$id]))$group[$id]=['public_id'=>$id,'statement'=>$r['statement'],'status'=>$r['status'],'claim_type'=>$r['claim_type'],'updated_at'=>$r['updated_at'],'evidence'=>[],'severity'=>'medium'];$group[$id]['evidence'][]=['public_id'=>$r['evidence_public_id'],'relationship'=>$r['relationship'],'captured_version'=>(int)$r['evidence_version_number'],'current_version'=>(int)$r['current_version_number']];if($event['target_changed']||in_array($r['relationship'],['primary','contradicts'],true)||in_array((string)$event['impact_type'],['passage_changed','passage_missing','source_unavailable'],true))$group[$id]['severity']='high';}
    return array_values($group);
}

function change_impact_finding_rows(PDO $pdo,array $project,array $claims): array {
    if(!$claims)return [];$ids=array_column($claims,'public_id');$marks=implode(',',array_fill(0,count($ids),'?'));
    $params=array_merge([$project['id']],$ids);$q=$pdo->prepare("SELECT DISTINCT rf.public_id,rf.title,rf.summary,rf.status,rf.updated_at,rc.public_id claim_public_id
      FROM research_findings rf JOIN finding_claims fc ON fc.finding_id=rf.id JOIN research_claims rc ON rc.id=fc.claim_id
      WHERE rf.project_id=? AND rc.public_id IN ($marks) AND rf.status<>'archived' ORDER BY rf.updated_at DESC,rf.id DESC");$q->execute($params);$out=[];
    foreach($q->fetchAll() as $r){$id=(string)$r['public_id'];if(!isset($out[$id]))$out[$id]=['public_id'=>$id,'title'=>$r['title'],'summary'=>$r['summary'],'status'=>$r['status'],'updated_at'=>$r['updated_at'],'claim_ids'=>[],'severity'=>'high'];$out[$id]['claim_ids'][]=$r['claim_public_id'];}
    return array_values($out);
}

function change_impact_snapshot_refs(array $snapshot): array {
    $out=['sources'=>[],'claims'=>[],'findings'=>[]];
    foreach((array)($snapshot['sources']??[]) as $s)if(!empty($s['id']))$out['sources'][(string)$s['id']]=true;
    foreach((array)($snapshot['claims']??[]) as $c)if(!empty($c['id']))$out['claims'][(string)$c['id']]=true;
    foreach((array)($snapshot['findings']??[]) as $f)if(!empty($f['id']))$out['findings'][(string)$f['id']]=true;
    return $out;
}

function change_impact_report_rows(PDO $pdo,array $event,array $project,array $claims,array $findings): array {
    $claimSet=array_fill_keys(array_column($claims,'public_id'),true);$findingSet=array_fill_keys(array_column($findings,'public_id'),true);$source=(string)$event['source_public_id'];
    $q=$pdo->prepare("SELECT rv.public_id,rv.version_number,rv.title,rv.summary,rv.snapshot_json,rv.snapshot_hash,rv.created_at,
      rr.public_id report_public_id,rr.current_version_id,rv.id version_id
      FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id
      WHERE rr.project_id=? AND rv.created_at<=? ORDER BY rv.version_number DESC");
    $q->execute([$project['id'],$event['created_at']]);$out=[];
    foreach($q->fetchAll() as $r){$snapshot=json_decode((string)$r['snapshot_json'],true);if(!is_array($snapshot))continue;$refs=change_impact_snapshot_refs($snapshot);$reasons=[];if(isset($refs['sources'][$source]))$reasons[]='Published snapshot contains the changed Source.';foreach($claimSet as $id=>$_)if(isset($refs['claims'][$id])){$reasons[]='Published snapshot contains affected Claim '.$id.'.';break;}foreach($findingSet as $id=>$_)if(isset($refs['findings'][$id])){$reasons[]='Published snapshot contains affected Finding '.$id.'.';break;}if(!$reasons)continue;$out[]=['public_id'=>$r['public_id'],'report_public_id'=>$r['report_public_id'],'version_number'=>(int)$r['version_number'],'title'=>$r['title'],'summary'=>$r['summary'],'snapshot_hash'=>$r['snapshot_hash'],'created_at'=>$r['created_at'],'is_current'=>(int)$r['current_version_id']===(int)$r['version_id'],'reasons'=>$reasons,'severity'=>(int)$r['current_version_id']===(int)$r['version_id']?'high':'medium'];}
    return $out;
}

function change_impact_review_rows(PDO $pdo,array $viewer,array $event,array $project,array $claims,array $findings,array $reports): array {
    if(!function_exists('research_reviews_ready')||!research_reviews_ready($pdo))return [];$claimSet=array_fill_keys(array_column($claims,'public_id'),true);$findingSet=array_fill_keys(array_column($findings,'public_id'),true);$reportSet=array_fill_keys(array_column($reports,'public_id'),true);
    $q=$pdo->prepare("SELECT public_id,subject_type,subject_public_id,title,status,created_at,completed_at FROM research_reviews WHERE project_id=? AND created_at<=? ORDER BY created_at DESC");$q->execute([$project['id'],$event['created_at']]);$out=[];
    foreach($q->fetchAll() as $r){$hit=($r['subject_type']==='claim'&&isset($claimSet[$r['subject_public_id']]))||($r['subject_type']==='finding'&&isset($findingSet[$r['subject_public_id']]))||($r['subject_type']==='report_version'&&isset($reportSet[$r['subject_public_id']]));if(!$hit)continue;$access=research_review_access($pdo,$viewer,(string)$r['public_id']);if(!$access)continue;$out[]=['public_id'=>$r['public_id'],'subject_type'=>$r['subject_type'],'subject_public_id'=>$r['subject_public_id'],'title'=>$r['title'],'status'=>$r['status'],'created_at'=>$r['created_at'],'completed_at'=>$r['completed_at'],'severity'=>'high'];}
    return $out;
}

function change_impact_cross_rows(PDO $pdo,array $viewer,array $event,array $project,array $claims,array $findings): array {
    if(!function_exists('cross_research_ready')||!cross_research_ready($pdo))return [];$ids=[(string)$event['source_public_id']=>true];foreach($claims as $c)$ids[(string)$c['public_id']]=true;foreach($findings as $f)$ids[(string)$f['public_id']]=true;if(!$ids)return [];
    $marks=implode(',',array_fill(0,count($ids),'?'));$params=array_merge([$viewer['id'],$project['id'],$project['id']],array_keys($ids),array_keys($ids));
    $q=$pdo->prepare("SELECT crl.*,sp.public_id source_project_public_id,tp.public_id target_project_public_id,sp.title source_project_title,tp.title target_project_title
      FROM cross_research_links crl JOIN research_projects sp ON sp.id=crl.source_project_id JOIN research_projects tp ON tp.id=crl.target_project_id
      WHERE crl.user_id=? AND (crl.source_project_id=? OR crl.target_project_id=?) AND (crl.source_object_public_id IN ($marks) OR crl.target_object_public_id IN ($marks))
      ORDER BY crl.updated_at DESC");
    $q->execute($params);$out=[];foreach($q->fetchAll() as $r){if(!project_access($pdo,(int)$viewer['id'],(string)$r['source_project_public_id'])||!project_access($pdo,(int)$viewer['id'],(string)$r['target_project_public_id']))continue;$out[]=['public_id'=>$r['public_id'],'relation_type'=>$r['relation_type'],'object_type'=>$r['object_type'],'source_project_public_id'=>$r['source_project_public_id'],'target_project_public_id'=>$r['target_project_public_id'],'source_project_title'=>$r['source_project_title'],'target_project_title'=>$r['target_project_title'],'source_object_public_id'=>$r['source_object_public_id'],'target_object_public_id'=>$r['target_object_public_id'],'rationale'=>$r['rationale'],'severity'=>'medium'];}return $out;
}

function change_impact_decision_state(array $map,string $type,string $public): ?array {return $map[$type.':'.$public]??null;}
function change_impact_is_resolved(?array $state): bool {return $state&&in_array((string)$state['decision'],change_impact_final_decisions(),true)&& (empty($state['snoozed_until'])||strtotime((string)$state['snoozed_until'])<=time());}

function change_impact_project(PDO $pdo,array $viewer,array $event,array $project,?array $reviewMap=null): array {
    $reviewMap=$reviewMap??change_impact_review_map($pdo,$viewer,(int)$event['id']);$annotations=change_impact_annotation_rows($pdo,$event,$project);$claims=change_impact_claim_rows($pdo,$event,$project);$findings=change_impact_finding_rows($pdo,$project,$claims);$reports=change_impact_report_rows($pdo,$event,$project,$claims,$findings);$reviews=change_impact_review_rows($pdo,$viewer,$event,$project,$claims,$findings,$reports);$cross=change_impact_cross_rows($pdo,$viewer,$event,$project,$claims,$findings);
    $attach=function(array $rows,string $type)use($reviewMap){foreach($rows as &$r){$r['review_state']=change_impact_decision_state($reviewMap,$type,(string)$r['public_id']);$r['needs_review']=!change_impact_is_resolved($r['review_state']);}unset($r);return $rows;};
    $annotations=$attach($annotations,'annotation');$claims=$attach($claims,'claim');$findings=$attach($findings,'finding');$reports=$attach($reports,'report_version');$reviews=$attach($reviews,'research_review');$cross=$attach($cross,'cross_research_link');
    $projectState=change_impact_decision_state($reviewMap,'project',(string)$project['public_id']);$counts=['annotations'=>count($annotations),'claims'=>count($claims),'findings'=>count($findings),'reports'=>count($reports),'reviews'=>count($reviews),'cross_research_links'=>count($cross)];
    $unresolved=0;foreach([$annotations,$claims,$findings,$reports,$reviews,$cross] as $rows)foreach($rows as $r)if($r['needs_review'])$unresolved++;
    $severity=($claims||$findings||array_filter($reports,fn($r)=>$r['is_current'])||$reviews)?'high':(($annotations||$reports||$cross)?'medium':'low');
    return ['project'=>['id'=>(int)$project['id'],'public_id'=>$project['public_id'],'title'=>$project['title'],'access_role'=>$project['access_role']??null],'event'=>$event,'counts'=>$counts,'unresolved_count'=>$unresolved,'severity'=>$severity,'project_review_state'=>$projectState,'annotations'=>$annotations,'claims'=>$claims,'findings'=>$findings,'reports'=>$reports,'reviews'=>$reviews,'cross_research_links'=>$cross];
}

function change_impact_event_view(PDO $pdo,array $viewer,int $eventId): ?array {
    $event=change_impact_event($pdo,$viewer,$eventId);if(!$event)return null;$map=change_impact_review_map($pdo,$viewer,$eventId);$projects=[];foreach(change_impact_project_candidates($pdo,$viewer,$event) as $project){$p=change_impact_project($pdo,$viewer,$event,$project,$map);if(array_sum($p['counts'])>0||$p['project_review_state'])$projects[]=$p;}
    return ['event'=>$event,'projects'=>$projects,'total_projects'=>count($projects),'total_unresolved'=>array_sum(array_column($projects,'unresolved_count'))];
}

function change_impact_find_object(array $view,string $type,string $public): ?array {
    foreach($view['projects'] as $p){if($type==='project'&&(string)$p['project']['public_id']===$public)return ['project'=>$p['project'],'object'=>$p['project']];$bucket=match($type){'annotation'=>'annotations','claim'=>'claims','finding'=>'findings','report_version'=>'reports','research_review'=>'reviews','cross_research_link'=>'cross_research_links',default=>null};if(!$bucket)continue;foreach($p[$bucket] as $o)if((string)$o['public_id']===$public)return ['project'=>$p['project'],'object'=>$o];}return null;
}

function change_impact_set_decision(PDO $pdo,array $viewer,int $eventId,string $type,string $public,string $decision,string $note='',?string $snoozedUntil=null): array {
    if(!change_impact_ready($pdo))throw new RuntimeException('Change Impact requires the Phase 22 database upgrade.');if(!isset(change_impact_decisions()[$decision]))throw new InvalidArgumentException('Invalid impact review decision.');$view=change_impact_event_view($pdo,$viewer,$eventId);if(!$view)throw new RuntimeException('Source change is unavailable.');$hit=change_impact_find_object($view,$type,$public);if(!$hit)throw new RuntimeException('Impacted Research object is unavailable.');
    $note=mb_substr(trim($note),0,8000);$snooze=null;if($snoozedUntil!==null&&trim($snoozedUntil)!==''){$ts=strtotime($snoozedUntil);if($ts===false||$ts<=time())throw new InvalidArgumentException('Snooze time must be in the future.');$snooze=date('Y-m-d H:i:s',$ts);}
    $publicId=ulid_like();$pdo->prepare("INSERT INTO research_change_impact_reviews(public_id,user_id,source_change_event_id,project_id,object_type,object_public_id,decision,note,snoozed_until) VALUES(?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE decision=VALUES(decision),note=VALUES(note),snoozed_until=VALUES(snoozed_until),updated_at=NOW()")
      ->execute([$publicId,$viewer['id'],$eventId,$hit['project']['id'],$type,$public,$decision,$note?:null,$snooze]);
    $q=$pdo->prepare('SELECT * FROM research_change_impact_reviews WHERE user_id=? AND source_change_event_id=? AND object_type=? AND object_public_id=? LIMIT 1');$q->execute([$viewer['id'],$eventId,$type,$public]);$row=$q->fetch()?:[];
    if(function_exists('research_outcome_try_record')&&research_outcomes_ready($pdo)){research_outcome_try_record($pdo,$viewer,['event_type'=>'change_impact_review','decision_type'=>$decision==='needs_update'?'reopened':($decision==='resolved'?'resolved':'recorded'),'source_type'=>'source_change_event','source_public_id'=>(string)$eventId,'project_public_id'=>$hit['project']['public_id'],'object_type'=>$type==='project'?'project':$type,'object_public_id'=>$public,'title'=>'Source change impact: '.(change_impact_decisions()[$decision]??$decision),'summary'=>$note,'refs'=>[['type'=>'source','public_id'=>$view['event']['source_public_id'],'role'=>'source'],['type'=>'project','public_id'=>$hit['project']['public_id'],'role'=>'context']],'metadata'=>['source_change_event_id'=>$eventId,'impact_decision'=>$decision],'occurred_at'=>date('Y-m-d H:i:s'),'dedupe_key'=>'change-impact:'.$viewer['id'].':'.$eventId.':'.$type.':'.$public.':'.$decision.':'.($row['updated_at']??date('c'))]);}
    return $row;
}

function change_impact_project_events(PDO $pdo,array $viewer,string $projectPublic,int $limit=20): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)return [];$limit=max(1,min(60,$limit));
    $q=$pdo->prepare("SELECT DISTINCT sce.id FROM source_change_events sce JOIN sources s ON s.id=sce.source_id
      WHERE EXISTS(SELECT 1 FROM project_sources ps WHERE ps.project_id=? AND ps.source_id=s.id)
         OR EXISTS(SELECT 1 FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=? AND a.source_id=s.id)
         OR EXISTS(SELECT 1 FROM research_claims rc JOIN claim_evidence ce ON ce.claim_id=rc.id JOIN source_versions sv ON sv.id=ce.source_version_id WHERE rc.project_id=? AND sv.source_id=s.id)
      ORDER BY sce.id DESC LIMIT ".$limit);$q->execute([$project['id'],$project['id'],$project['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $eid){$event=change_impact_event($pdo,$viewer,(int)$eid);if(!$event)continue;$impact=change_impact_project($pdo,$viewer,$event,$project);if(array_sum($impact['counts'])>0)$out[]=$impact;}return $out;
}

function change_impact_project_summary(PDO $pdo,array $viewer,string $projectPublic,int $limit=12): array {
    $events=change_impact_project_events($pdo,$viewer,$projectPublic,$limit);$out=['events'=>count($events),'unresolved'=>0,'high'=>0,'affected_claims'=>0,'affected_findings'=>0,'affected_reports'=>0,'items'=>[]];
    foreach($events as $i){$out['unresolved']+=$i['unresolved_count'];if($i['severity']==='high'&&$i['unresolved_count']>0)$out['high']++;$out['affected_claims']+=$i['counts']['claims'];$out['affected_findings']+=$i['counts']['findings'];$out['affected_reports']+=$i['counts']['reports'];$out['items'][]=['event_id'=>$i['event']['id'],'source_public_id'=>$i['event']['source_public_id'],'source_title'=>$i['event']['source_title']?:$i['event']['domain'],'created_at'=>$i['event']['created_at'],'severity'=>$i['severity'],'unresolved_count'=>$i['unresolved_count'],'counts'=>$i['counts'],'diff_summary'=>$i['event']['diff_summary']];}return $out;
}

function change_impact_context(PDO $pdo,array $viewer,string $projectPublic,int $limit=8): array {
    $s=change_impact_project_summary($pdo,$viewer,$projectPublic,$limit);if(!$s['events']||!$s['unresolved'])return ['text'=>'','refs'=>[],'summary'=>$s];$lines=['[CHANGE IMPACT / STALENESS]','Unresolved downstream impacts: '.$s['unresolved'].' across '.$s['events'].' recent Source change(s). Affected Claims: '.$s['affected_claims'].'; Findings: '.$s['affected_findings'].'; Report versions: '.$s['affected_reports'].'.'];$refs=[];
    foreach($s['items'] as $i){if($i['unresolved_count']<=0)continue;$lines[]='- '.$i['source_title'].' · '.$i['unresolved_count'].' item(s) need review · '.$i['created_at'].' [SOURCE '.$i['source_public_id'].'] [CHANGE EVENT '.$i['event_id'].']';$refs[]=['type'=>'source','id'=>$i['source_public_id']];}
    return ['text'=>implode("\n",$lines),'refs'=>$refs,'summary'=>$s];
}

function change_impact_agent_handoff(PDO $pdo,array $viewer,int $eventId,string $projectPublic=''): ?array {
    $view=change_impact_event_view($pdo,$viewer,$eventId);if(!$view)return null;$projects=$view['projects'];if($projectPublic!=='')$projects=array_values(array_filter($projects,fn($p)=>(string)$p['project']['public_id']===$projectPublic));if(!$projects)return null;
    $context=[];$lines=[];foreach($projects as $p){$context[]=['type'=>'research','public_id'=>$p['project']['public_id']];$lines[]=$p['project']['title'].': '.$p['unresolved_count'].' unresolved; '.$p['counts']['claims'].' Claims, '.$p['counts']['findings'].' Findings, '.$p['counts']['reports'].' Report versions, '.$p['counts']['reviews'].' prior reviews affected.';}
    $prompt='Review the downstream impact of Source change event '.$eventId.' for '.$view['event']['source_public_id'].'. '.implode(' ',$lines).' Explain the exact dependency chain and which Research may need human review. Treat every impact as potentially stale, not automatically false. Do not change Claim/Finding status, republish a Report, resolve a review, or execute a Research write without the normal explicit user action/confirmation.';
    return ['prompt'=>$prompt,'context'=>$context,'view'=>$view];
}

function change_impact_subject_latest_event(PDO $pdo,array $viewer,string $type,string $public,string $after='1970-01-01 00:00:00'): ?array {
    $sourceIds=[];$projectPublic='';
    if($type==='claim'){$q=$pdo->prepare("SELECT DISTINCT sv.source_id,rp.public_id project_public_id FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id JOIN claim_evidence ce ON ce.claim_id=rc.id JOIN source_versions sv ON sv.id=ce.source_version_id WHERE rc.public_id=?");$q->execute([$public]);foreach($q->fetchAll() as $r){$sourceIds[(int)$r['source_id']]=true;$projectPublic=(string)$r['project_public_id'];}}
    elseif($type==='finding'){$q=$pdo->prepare("SELECT DISTINCT sv.source_id,rp.public_id project_public_id FROM research_findings rf JOIN research_projects rp ON rp.id=rf.project_id JOIN finding_claims fc ON fc.finding_id=rf.id JOIN claim_evidence ce ON ce.claim_id=fc.claim_id JOIN source_versions sv ON sv.id=ce.source_version_id WHERE rf.public_id=?");$q->execute([$public]);foreach($q->fetchAll() as $r){$sourceIds[(int)$r['source_id']]=true;$projectPublic=(string)$r['project_public_id'];}}
    elseif($type==='report_version'){$q=$pdo->prepare("SELECT rv.snapshot_json,rp.public_id project_public_id FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id JOIN research_projects rp ON rp.id=rr.project_id WHERE rv.public_id=? LIMIT 1");$q->execute([$public]);$r=$q->fetch();if($r){$projectPublic=(string)$r['project_public_id'];$snap=json_decode((string)$r['snapshot_json'],true)?:[];$sourcePublics=array_keys(change_impact_snapshot_refs($snap)['sources']);if($sourcePublics){$marks=implode(',',array_fill(0,count($sourcePublics),'?'));$sq=$pdo->prepare("SELECT id FROM sources WHERE public_id IN ($marks)");$sq->execute($sourcePublics);foreach($sq->fetchAll(PDO::FETCH_COLUMN) as $sid)$sourceIds[(int)$sid]=true;}}}
    if($projectPublic===''||!project_access($pdo,(int)$viewer['id'],$projectPublic)||!$sourceIds)return null;$marks=implode(',',array_fill(0,count($sourceIds),'?'));$params=array_merge(array_keys($sourceIds),[$after]);$q=$pdo->prepare("SELECT id FROM source_change_events WHERE source_id IN ($marks) AND created_at>? ORDER BY id DESC LIMIT 1");$q->execute($params);$eid=(int)($q->fetchColumn()?:0);return $eid?change_impact_event($pdo,$viewer,$eid):null;
}

function change_impact_review_upstream_staleness(PDO $pdo,array $viewer,array $review): ?array {
    if(!in_array((string)$review['subject_type'],['claim','finding','report_version'],true))return null;$e=change_impact_subject_latest_event($pdo,$viewer,(string)$review['subject_type'],(string)$review['subject_public_id'],(string)$review['created_at']);if(!$e)return null;
    return ['event_id'=>$e['id'],'source_public_id'=>$e['source_public_id'],'source_title'=>$e['source_title']?:$e['domain'],'created_at'=>$e['created_at'],'reason'=>'Upstream source evidence changed after this review was requested.'];
}

function change_impact_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limitProjects=12): void {
    if(!change_impact_ready($pdo))return;$q=$pdo->prepare("SELECT DISTINCT rp.public_id FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.updated_at DESC LIMIT ".$limitProjects);$q->execute([$viewer['id'],$viewer['id'],$viewer['id']]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $projectPublic){$summary=change_impact_project_summary($pdo,$viewer,(string)$projectPublic,6);foreach($summary['items'] as $i){if($i['unresolved_count']<=0)continue;$priority=$i['severity']==='high'?'high':'medium';$actions=[cognitive_feed_action_link('Review Impact','/research-impact.php?event='.(int)$i['event_id'].'&project='.rawurlencode((string)$projectPublic)),cognitive_feed_action_agent('Ask Agent','Explain the downstream impact of this Source change and which Research needs human review. Do not automatically change Research conclusions.',[['type'=>'research','public_id'=>(string)$projectPublic],['type'=>'source','public_id'=>$i['source_public_id']]])];cognitive_feed_add($items,['key'=>cognitive_feed_key('change_impact','source_change',(string)$i['event_id'].':'.$projectPublic,(string)$i['created_at']),'type'=>'change_impact','section'=>'needs_attention','priority'=>$priority,'created_at'=>$i['created_at'],'score_extra'=>$priority==='high'?18:8,'title'=>'Source change affects downstream Research','body'=>$i['source_title'].' affects '.$i['unresolved_count'].' Research item(s) that still need review.','meta'=>['project'=>$projectPublic,'source'=>$i['source_public_id'],'event_id'=>$i['event_id'],'counts'=>$i['counts']],'actions'=>$actions]);}}
}

function change_impact_digest(PDO $pdo,array $viewer,string $projectPublic): string {
    $s=change_impact_project_summary($pdo,$viewer,$projectPublic,20);$lines=['Change impact review.','Recent change events: '.$s['events'].'. Unresolved downstream impacts: '.$s['unresolved'].'. Affected Claims: '.$s['affected_claims'].'. Findings: '.$s['affected_findings'].'. Report versions: '.$s['affected_reports'].'.'];foreach($s['items'] as $i)if($i['unresolved_count']>0)$lines[]='- '.$i['source_title'].' · '.$i['unresolved_count'].' unresolved · '.$i['severity'].' impact · event '.$i['event_id'].'.';return implode("\n",$lines);
}
function change_impact_input_hash(PDO $pdo,array $viewer,string $projectPublic): string {return hash('sha256',json_encode(change_impact_project_summary($pdo,$viewer,$projectPublic,20),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
