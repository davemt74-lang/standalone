<?php
declare(strict_types=1);

/**
 * Phase 70 — Longitudinal Research Intelligence & Synthesis
 *
 * Captures authoritative Research state independently of Report Runs and
 * records append-only transitions between deduplicated snapshots.
 */

function research_longitudinal_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_longitudinal_snapshots')
        &&installer_table_exists($pdo,'research_longitudinal_changes')
        &&installer_table_exists($pdo,'research_longitudinal_milestones');}
    catch(Throwable $e){return false;}
}

function research_longitudinal_agent(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_agent_access($pdo,$viewer,trim($agentPublic));if(!$agent)throw new RuntimeException('Research Agent not found.');
    return $agent;
}

function research_longitudinal_sort_map(array $rows,string $key='public_id'): array {
    $out=[];foreach($rows as $row){$id=trim((string)($row[$key]??''));if($id==='')continue;$out[$id]=$row;}ksort($out,SORT_STRING);return $out;
}

function research_longitudinal_derived_id(string $prefix,array $row): string {
    $basis=[
      (string)($row['claim_id']??''),(string)($row['ref_type']??''),(string)($row['ref_id']??''),
      (string)($row['title']??''),(string)($row['detail']??''),(string)($row['reason']??'')
    ];
    return $prefix.'-'.substr(hash('sha256',implode('|',$basis)),0,32);
}

function research_longitudinal_state(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_longitudinal_agent($pdo,$viewer,$agentPublic);$projectId=(int)$agent['project_id'];
    $workspace=function_exists('research_workspace_deterministic_snapshot')?research_workspace_deterministic_snapshot($pdo,$projectId):[];
    $claims=[];foreach((array)(function_exists('research_workspace_claim_rows')?research_workspace_claim_rows($pdo,$projectId):[]) as $r)$claims[]=[
      'public_id'=>(string)$r['public_id'],'statement'=>(string)$r['statement'],'claim_type'=>(string)$r['claim_type'],'status'=>(string)$r['status'],
      'evidence_count'=>(int)($r['evidence_count']??0),'source_count'=>(int)($r['source_count']??0),'supports_count'=>(int)($r['supports_count']??0),
      'contradicts_count'=>(int)($r['contradicts_count']??0),'primary_count'=>(int)($r['primary_count']??0)
    ];
    $findings=[];foreach((array)(function_exists('research_system_report_findings')?research_system_report_findings($pdo,$projectId,100):[]) as $r)$findings[]=[
      'public_id'=>(string)$r['public_id'],'title'=>(string)$r['title'],'summary'=>(string)($r['summary']??''),'status'=>(string)$r['status'],'claim_count'=>(int)($r['claim_count']??0)
    ];
    $entities=[];foreach((array)(function_exists('research_project_entity_rows')?research_project_entity_rows($pdo,$projectId):[]) as $r)$entities[]=[
      'public_id'=>(string)$r['public_id'],'canonical_name'=>(string)$r['canonical_name'],'entity_type'=>(string)$r['entity_type'],'status'=>(string)$r['status'],
      'description'=>(string)($r['description']??''),'mention_count'=>(int)($r['mention_count']??0),'relation_count'=>(int)($r['relation_count']??0)
    ];
    $sources=[];$q=$pdo->prepare("SELECT s.public_id,s.title,s.domain,s.status,sv.version_number,sv.content_hash
      FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id
      WHERE ps.project_id=? ORDER BY s.public_id");$q->execute([$projectId]);
    foreach($q->fetchAll()?:[] as $r)$sources[]=[
      'public_id'=>(string)$r['public_id'],'title'=>(string)($r['title']??''),'domain'=>(string)($r['domain']??''),'status'=>(string)$r['status'],
      'version_number'=>(int)($r['version_number']??0),'content_hash'=>(string)($r['content_hash']??'')
    ];
    $tasks=[];foreach((array)(function_exists('research_system_report_active_tasks')?research_system_report_active_tasks($pdo,(int)$agent['id'],80):[]) as $r)$tasks[]=[
      'public_id'=>(string)$r['public_id'],'title'=>(string)$r['title'],'description'=>(string)($r['description']??''),'task_type'=>(string)$r['task_type'],
      'priority'=>(string)$r['priority'],'status'=>(string)$r['status'],'due_at'=>(string)($r['due_at']??'')
    ];
    $programs=[];foreach((array)(function_exists('research_system_report_active_programs')?research_system_report_active_programs($pdo,(int)$agent['id'],50):[]) as $r)$programs[]=[
      'public_id'=>(string)$r['public_id'],'title'=>(string)$r['title'],'objective'=>(string)($r['objective']??''),'status'=>(string)$r['status'],'cadence'=>(string)$r['cadence']
    ];
    $claimRelations=[];foreach((array)(function_exists('research_project_graph_rows')?research_project_graph_rows($pdo,$projectId):[]) as $r)$claimRelations[]=[
      'public_id'=>(string)($r['public_id']??research_longitudinal_derived_id('cr',$r)),'source_public_id'=>(string)($r['source_public_id']??''),'target_public_id'=>(string)($r['target_public_id']??''),
      'relation_type'=>(string)($r['relation_type']??''),'status'=>(string)($r['status']??'')
    ];
    $entityRelations=[];foreach((array)(function_exists('research_entity_relation_rows')?research_entity_relation_rows($pdo,$projectId):[]) as $r)$entityRelations[]=[
      'public_id'=>(string)($r['public_id']??research_longitudinal_derived_id('er',$r)),'source_public_id'=>(string)($r['source_public_id']??''),'target_public_id'=>(string)($r['target_public_id']??''),
      'relation_type'=>(string)($r['relation_type']??''),'status'=>(string)($r['status']??'')
    ];
    $questions=[];foreach((array)($workspace['gaps']??[]) as $r){$id=research_longitudinal_derived_id('q',$r);$questions[]=[
      'public_id'=>$id,'title'=>(string)($r['title']??'Evidence gap'),'detail'=>(string)($r['detail']??''),'priority'=>(string)($r['priority']??''),
      'claim_id'=>(string)($r['claim_id']??''),'ref_type'=>(string)($r['ref_type']??''),'ref_id'=>(string)($r['ref_id']??'')
    ];}
    $contradictions=[];foreach((array)($workspace['conflicts']??[]) as $r){$id=research_longitudinal_derived_id('c',$r);$contradictions[]=[
      'public_id'=>$id,'title'=>(string)($r['title']??'Contradiction'),'detail'=>(string)($r['detail']??''),'priority'=>(string)($r['priority']??''),
      'claim_id'=>(string)($r['claim_id']??''),'ref_type'=>(string)($r['ref_type']??''),'ref_id'=>(string)($r['ref_id']??'')
    ];}

    $objects=[
      'claim'=>research_longitudinal_sort_map($claims),'finding'=>research_longitudinal_sort_map($findings),'source'=>research_longitudinal_sort_map($sources),
      'entity'=>research_longitudinal_sort_map($entities),'task'=>research_longitudinal_sort_map($tasks),'program'=>research_longitudinal_sort_map($programs),
      'claim_relation'=>research_longitudinal_sort_map($claimRelations),'entity_relation'=>research_longitudinal_sort_map($entityRelations),
      'open_question'=>research_longitudinal_sort_map($questions),'contradiction'=>research_longitudinal_sort_map($contradictions)
    ];
    $counts=[];foreach($objects as $type=>$rows)$counts[$type]=count($rows);
    $basis=['schema'=>'annotated-longitudinal-state-v1','agent_public_id'=>(string)$agent['public_id'],'project_public_id'=>(string)$agent['project_public_id'],'objects'=>$objects];
    return ['agent'=>$agent,'objects'=>$objects,'counts'=>$counts,'state_hash'=>hash('sha256',json_encode($basis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION))];
}

function research_longitudinal_snapshot_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_longitudinal_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rls.*,ra.public_id agent_public_id,ra.name agent_name,rp.public_id project_public_id,rp.title project_title
      FROM research_longitudinal_snapshots rls JOIN research_agents ra ON ra.id=rls.research_agent_id JOIN research_projects rp ON rp.id=rls.project_id
      WHERE rls.public_id=? LIMIT 1");$q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    if(!research_agent_access($pdo,$viewer,(string)$row['agent_public_id']))return null;
    $row['state']=json_decode((string)$row['state_json'],true)?:[];$row['counts']=json_decode((string)($row['counts_json']??''),true)?:[];return $row;
}

function research_longitudinal_latest_snapshot(PDO $pdo,array $viewer,string $agentPublic): ?array {
    if(!research_longitudinal_ready($pdo))return null;$agent=research_longitudinal_agent($pdo,$viewer,$agentPublic);
    $q=$pdo->prepare('SELECT public_id FROM research_longitudinal_snapshots WHERE research_agent_id=? ORDER BY id DESC LIMIT 1');$q->execute([(int)$agent['id']]);$id=(string)($q->fetchColumn()?:'');
    return $id!==''?research_longitudinal_snapshot_access($pdo,$viewer,$id):null;
}

function research_longitudinal_claim_rank(string $status): int {
    return match(strtolower($status)){'verified','accepted','final'=>4,'supported'=>3,'mixed','disputed'=>1,'unsupported','rejected'=>0,default=>2};
}

function research_longitudinal_classify(string $type,?array $before,?array $after): array {
    if($before===null&&$after!==null)return ['introduced',$type==='claim'||$type==='finding'||$type==='source'?'high':'medium','New '.$type.' entered the Research state.'];
    if($before!==null&&$after===null){
        $change=in_array($type,['open_question','contradiction'],true)?'resolved':'removed';
        return [$change,in_array($type,['claim','finding','source','contradiction'],true)?'high':'medium',ucfirst(str_replace('_',' ',$type)).' left the current Research state.'];
    }
    if($type==='claim'){
        $old=(string)($before['status']??'');$new=(string)($after['status']??'');
        if($old!==$new){
            if(in_array(strtolower($new),['verified','accepted'],true))return ['verified','high','Claim verification state advanced from '.$old.' to '.$new.'.'];
            if(strtolower($new)==='disputed')return ['disputed','high','Claim became disputed.'];
            $a=research_longitudinal_claim_rank($old);$b=research_longitudinal_claim_rank($new);
            if($b>$a)return ['strengthened','high','Claim status strengthened from '.$old.' to '.$new.'.'];
            if($b<$a)return ['weakened','high','Claim status weakened from '.$old.' to '.$new.'.'];
        }
        $oldEvidence=(int)($before['evidence_count']??0);$newEvidence=(int)($after['evidence_count']??0);
        if($newEvidence>$oldEvidence)return ['strengthened','high','Claim gained supporting or contextual evidence.'];
        if($newEvidence<$oldEvidence)return ['weakened','high','Claim lost linked evidence.'];
        return ['changed','high','Claim content or evidence composition changed.'];
    }
    if($type==='finding'){
        if(($before['status']??'')!==($after['status']??'')){
            if(($after['status']??'')==='final')return ['strengthened','high','Finding advanced to final.'];
            return ['changed','high','Finding status changed.'];
        }
        return ['changed','high','Finding conclusion or supporting Claim set changed.'];
    }
    if($type==='source'){
        if(($before['status']??'')!==($after['status']??'')){
            $new=strtolower((string)($after['status']??''));if(in_array($new,['unavailable','failed','blocked'],true))return ['weakened','high','Source became unavailable or unhealthy.'];
            return ['strengthened','high','Source availability/status improved.'];
        }
        return ['source_updated','high','Source content/version changed.'];
    }
    if($type==='open_question')return ['changed','medium','Open research question or evidence gap changed.'];
    if($type==='contradiction')return ['changed','high','Contradiction details changed.'];
    if($type==='entity')return ['changed','medium','Entity understanding or relationship counts changed.'];
    if(in_array($type,['claim_relation','entity_relation'],true))return ['changed','medium','Research relationship changed.'];
    return ['changed','low',ucfirst($type).' state changed.'];
}

function research_longitudinal_milestone_type(string $type,string $change): ?string {
    if($type==='claim'&&in_array($change,['introduced','verified','strengthened','weakened','disputed','removed'],true))return 'claim_'.$change;
    if($type==='finding'&&in_array($change,['introduced','strengthened','weakened','removed'],true))return 'finding_'.$change;
    if($type==='source'&&in_array($change,['introduced','source_updated','strengthened','weakened','removed'],true))return 'source_'.$change;
    if($type==='contradiction'&&in_array($change,['introduced','resolved'],true))return 'contradiction_'.$change;
    if($type==='open_question'&&in_array($change,['introduced','resolved'],true))return 'question_'.$change;
    return null;
}

function research_longitudinal_object_title(string $type,array $row): string {
    foreach(['statement','title','canonical_name','domain','relation_type'] as $key)if(trim((string)($row[$key]??''))!=='')return mb_substr(trim((string)$row[$key]),0,255);
    return ucwords(str_replace('_',' ',$type));
}

function research_longitudinal_capture(PDO $pdo,array $viewer,string $agentPublic,string $trigger='manual',?string $triggerPublic=null): array {
    if(!research_longitudinal_ready($pdo))throw new RuntimeException('Longitudinal Research Intelligence requires the latest database upgrade.');
    $allowed=['baseline','manual','program_completed','program_quiet','system'];if(!in_array($trigger,$allowed,true))$trigger='system';
    $state=research_longitudinal_state($pdo,$viewer,$agentPublic);$agent=$state['agent'];
    $q=$pdo->prepare('SELECT public_id FROM research_longitudinal_snapshots WHERE project_id=? AND state_hash=? LIMIT 1');$q->execute([(int)$agent['project_id'],$state['state_hash']]);$same=(string)($q->fetchColumn()?:'');
    if($same!=='')return ['snapshot'=>research_longitudinal_snapshot_access($pdo,$viewer,$same),'created'=>false,'changes'=>[],'milestones'=>[]];

    $previous=research_longitudinal_latest_snapshot($pdo,$viewer,$agentPublic);if(!$previous)$trigger='baseline';
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $public=ulid_like();$pdo->prepare("INSERT INTO research_longitudinal_snapshots(public_id,research_agent_id,project_id,captured_by_user_id,trigger_type,trigger_public_id,state_hash,state_json,counts_json)
          VALUES(?,?,?,?,?,?,?,?,?)")->execute([$public,(int)$agent['id'],(int)$agent['project_id'],(int)$viewer['id'],$trigger,$triggerPublic?mb_substr($triggerPublic,0,64):null,$state['state_hash'],
            json_encode($state['objects'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($state['counts'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $snapshotId=(int)$pdo->lastInsertId();$changes=[];$milestones=[];
        if($previous){
            $before=(array)$previous['state'];$after=(array)$state['objects'];$types=array_values(array_unique(array_merge(array_keys($before),array_keys($after))));
            sort($types,SORT_STRING);
            foreach($types as $type){$a=(array)($before[$type]??[]);$b=(array)($after[$type]??[]);$ids=array_values(array_unique(array_merge(array_keys($a),array_keys($b))));sort($ids,SORT_STRING);
                foreach($ids as $id){$old=$a[$id]??null;$new=$b[$id]??null;if($old!==null&&$new!==null&&hash('sha256',json_encode($old))===hash('sha256',json_encode($new)))continue;
                    [$change,$materiality,$reason]=research_longitudinal_classify($type,$old,$new);
                    $dedupe=hash('sha256','p70|'.$previous['public_id'].'|'.$public.'|'.$type.'|'.$id.'|'.$change);
                    $pdo->prepare("INSERT INTO research_longitudinal_changes(public_id,research_agent_id,project_id,snapshot_id,previous_snapshot_id,object_type,object_public_id,change_type,materiality,reason,before_json,after_json,dedupe_key)
                      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([ulid_like(),(int)$agent['id'],(int)$agent['project_id'],$snapshotId,(int)$previous['id'],$type,$id,$change,$materiality,$reason,
                        $old!==null?json_encode($old,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,$new!==null?json_encode($new,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,$dedupe]);
                    $changeId=(int)$pdo->lastInsertId();$changes[]=['id'=>$changeId,'object_type'=>$type,'object_public_id'=>$id,'change_type'=>$change,'materiality'=>$materiality,'reason'=>$reason,'before'=>$old,'after'=>$new];
                    $milestoneType=research_longitudinal_milestone_type($type,$change);if($milestoneType!==null){$row=$new??$old??[];$title=research_longitudinal_object_title($type,$row);$mDedupe=hash('sha256','p70m|'.$dedupe.'|'.$milestoneType);
                        $pdo->prepare("INSERT INTO research_longitudinal_milestones(public_id,research_agent_id,project_id,change_id,milestone_type,object_type,object_public_id,title,summary,dedupe_key)
                          VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([ulid_like(),(int)$agent['id'],(int)$agent['project_id'],$changeId,$milestoneType,$type,$id,$title,$reason,$mDedupe]);
                        $milestones[]=['milestone_type'=>$milestoneType,'object_type'=>$type,'object_public_id'=>$id,'title'=>$title,'summary'=>$reason];
                    }
                }
            }
        }
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['snapshot'=>research_longitudinal_snapshot_access($pdo,$viewer,$public),'created'=>true,'changes'=>$changes,'milestones'=>$milestones,'previous'=>$previous];
}

function research_longitudinal_change_list(PDO $pdo,array $viewer,string $agentPublic,?string $since=null,int $limit=200): array {
    if(!research_longitudinal_ready($pdo))return [];$agent=research_longitudinal_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(500,$limit));$params=[(int)$agent['id']];$where='rlc.research_agent_id=?';
    if($since!==null&&trim($since)!==''){$ts=strtotime($since);if($ts!==false){$where.=' AND rlc.occurred_at>=?';$params[]=date('Y-m-d H:i:s',$ts);}}
    $q=$pdo->prepare("SELECT rlc.*,rls.public_id snapshot_public_id,prev.public_id previous_snapshot_public_id
      FROM research_longitudinal_changes rlc JOIN research_longitudinal_snapshots rls ON rls.id=rlc.snapshot_id
      LEFT JOIN research_longitudinal_snapshots prev ON prev.id=rlc.previous_snapshot_id
      WHERE $where ORDER BY rlc.id DESC LIMIT ".$limit);$q->execute($params);$rows=$q->fetchAll()?:[];
    foreach($rows as &$r){$r['before']=json_decode((string)($r['before_json']??''),true);$r['after']=json_decode((string)($r['after_json']??''),true);}unset($r);return $rows;
}

function research_longitudinal_milestone_list(PDO $pdo,array $viewer,string $agentPublic,?string $since=null,int $limit=100): array {
    if(!research_longitudinal_ready($pdo))return [];$agent=research_longitudinal_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(300,$limit));$params=[(int)$agent['id']];$where='research_agent_id=?';
    if($since!==null&&trim($since)!==''){$ts=strtotime($since);if($ts!==false){$where.=' AND occurred_at>=?';$params[]=date('Y-m-d H:i:s',$ts);}}
    $q=$pdo->prepare("SELECT * FROM research_longitudinal_milestones WHERE $where ORDER BY id DESC LIMIT ".$limit);$q->execute($params);return $q->fetchAll()?:[];
}

function research_longitudinal_snapshot_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=60): array {
    if(!research_longitudinal_ready($pdo))return [];$agent=research_longitudinal_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT public_id,trigger_type,trigger_public_id,state_hash,counts_json,captured_at FROM research_longitudinal_snapshots WHERE research_agent_id=? ORDER BY id DESC LIMIT ".$limit);$q->execute([(int)$agent['id']]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$r)$r['counts']=json_decode((string)($r['counts_json']??''),true)?:[];unset($r);return $rows;
}

function research_longitudinal_summary(PDO $pdo,array $viewer,string $agentPublic,?string $since=null): array {
    $changes=research_longitudinal_change_list($pdo,$viewer,$agentPublic,$since,400);$milestones=research_longitudinal_milestone_list($pdo,$viewer,$agentPublic,$since,120);
    $counts=[];$types=[];$material=['high'=>0,'medium'=>0,'low'=>0];$objectTrend=[];
    foreach($changes as $c){$change=(string)$c['change_type'];$type=(string)$c['object_type'];$counts[$change]=($counts[$change]??0)+1;$types[$type]=($types[$type]??0)+1;$material[$c['materiality']]=($material[$c['materiality']]??0)+1;
        $key=$type.':'.$c['object_public_id'];if(!isset($objectTrend[$key]))$objectTrend[$key]=['object_type'=>$type,'object_public_id'=>$c['object_public_id'],'strengthened'=>0,'weakened'=>0,'disputed'=>0,'verified'=>0,'changes'=>0,'latest'=>$c];
        $objectTrend[$key]['changes']++;if(isset($objectTrend[$key][$change]))$objectTrend[$key][$change]++;
    }
    uasort($objectTrend,fn($a,$b)=>$b['changes']<=>$a['changes']);arsort($counts);arsort($types);
    $emerging=[];foreach($objectTrend as $row)if(in_array($row['object_type'],['entity','claim','finding'],true)&&$row['changes']>=2)$emerging[]=$row;
    return ['changes'=>$changes,'milestones'=>$milestones,'change_counts'=>$counts,'object_counts'=>$types,'materiality'=>$material,'trends'=>array_values($objectTrend),'emerging'=>array_slice($emerging,0,20)];
}

function research_longitudinal_compare_snapshots(PDO $pdo,array $viewer,string $olderPublic,string $newerPublic): array {
    $old=research_longitudinal_snapshot_access($pdo,$viewer,$olderPublic);$new=research_longitudinal_snapshot_access($pdo,$viewer,$newerPublic);if(!$old||!$new)throw new RuntimeException('Longitudinal snapshot not found.');
    if((int)$old['project_id']!==(int)$new['project_id'])throw new InvalidArgumentException('Snapshots must belong to the same Research project.');
    $out=[];$types=array_values(array_unique(array_merge(array_keys($old['state']),array_keys($new['state']))));sort($types,SORT_STRING);
    foreach($types as $type){$a=(array)($old['state'][$type]??[]);$b=(array)($new['state'][$type]??[]);$out[$type]=['added'=>[],'removed'=>[],'changed'=>[]];
        foreach($b as $id=>$row)if(!isset($a[$id]))$out[$type]['added'][]=$id;elseif(hash('sha256',json_encode($a[$id]))!==hash('sha256',json_encode($row)))$out[$type]['changed'][]=$id;
        foreach($a as $id=>$row)if(!isset($b[$id]))$out[$type]['removed'][]=$id;
    }
    return ['older'=>$old,'newer'=>$new,'diff'=>$out];
}

function research_longitudinal_report_data(PDO $pdo,array $viewer,string $agentPublic,int $days=30): array {
    $days=max(1,min(3650,$days));$latest=research_longitudinal_latest_snapshot($pdo,$viewer,$agentPublic);
    return ['ready'=>(bool)$latest,'latest_snapshot'=>$latest,'since_days'=>$days,'summary'=>research_longitudinal_summary($pdo,$viewer,$agentPublic,'-'.$days.' days')];
}

function research_longitudinal_change_html(array $rows,int $limit=30): string {
    $rows=array_slice($rows,0,max(1,$limit));if(!$rows)return research_system_report_empty('No longitudinal changes are available for this section.');
    $html='<ul>';foreach($rows as $c){$row=$c['after']??$c['before']??[];$title=research_longitudinal_object_title((string)$c['object_type'],(array)$row);
        $html.=research_system_report_li($title,(string)$c['reason'],strtoupper((string)$c['change_type']).' · '.strtoupper((string)$c['materiality']).' · '.(string)$c['occurred_at']);}
    return $html.'</ul>';
}

function research_longitudinal_milestone_html(array $rows,int $limit=24): string {
    $rows=array_slice($rows,0,max(1,$limit));if(!$rows)return research_system_report_empty('No longitudinal milestones are recorded for this period.');
    $html='<ol>';foreach($rows as $m)$html.=research_system_report_li((string)$m['title'],(string)($m['summary']??''),strtoupper(str_replace('_',' ',(string)$m['milestone_type'])).' · '.(string)$m['occurred_at']);
    return $html.'</ol>';
}

function research_longitudinal_render_report(string $type,array $snapshot): string {
    $data=(array)($snapshot['longitudinal']??[]);$summary=(array)($data['summary']??[]);$changes=(array)($summary['changes']??[]);$milestones=(array)($summary['milestones']??[]);
    if(empty($data['ready']))return research_system_report_section('Longitudinal Research baseline',research_system_report_empty('No longitudinal baseline has been captured yet. Run a Research Program cycle or capture the current state from Research Evolution.'));
    $latest=(array)($data['latest_snapshot']??[]);$state=(array)($latest['state']??[]);
    $filter=function(callable $fn)use($changes){return array_values(array_filter($changes,$fn));};
    $summaryHtml='<p><strong>'.count($changes).'</strong> recorded change(s) in the last '.(int)($data['since_days']??30).' days; '
      .'<strong>'.(int)($summary['materiality']['high']??0).'</strong> high-materiality; <strong>'.count($milestones).'</strong> milestone(s).</p>';
    foreach(array_slice((array)($summary['change_counts']??[]),0,8,true) as $k=>$v)$summaryHtml.='<p><strong>'.research_system_report_escape(ucwords(str_replace('_',' ',$k))).':</strong> '.(int)$v.'</p>';

    if($type==='research_evolution'){
        $strong=$filter(fn($c)=>in_array((string)$c['change_type'],['strengthened','verified'],true));
        $weak=$filter(fn($c)=>in_array((string)$c['change_type'],['weakened','disputed','removed'],true));
        $persistent=array_values(array_filter((array)($summary['trends']??[]),fn($r)=>(int)($r['changes']??0)>=2));
        $trendHtml='<ul>';foreach(array_slice($persistent,0,20) as $r){$latestChange=(array)($r['latest']??[]);$row=$latestChange['after']??$latestChange['before']??[];$trendHtml.=research_system_report_li(research_longitudinal_object_title((string)$r['object_type'],(array)$row),(int)$r['changes'].' longitudinal changes','STRENGTHENED '.(int)$r['strengthened'].' · WEAKENED '.(int)$r['weakened'].' · VERIFIED '.(int)$r['verified'].' · DISPUTED '.(int)$r['disputed']);}$trendHtml.='</ul>';if(!$persistent)$trendHtml=research_system_report_empty('No object has changed repeatedly in this period.');
        return research_system_report_section('Evolution summary',$summaryHtml)
          .research_system_report_section('Major milestones',research_longitudinal_milestone_html($milestones))
          .research_system_report_section('Strengthening and weakening',research_longitudinal_change_html(array_merge($strong,$weak),30))
          .research_system_report_section('Persistent change',$trendHtml)
          .research_system_report_section('What to review next',research_longitudinal_change_html(array_slice($weak,0,15),15));
    }
    if($type==='what_changed'){
        $material=$filter(fn($c)=>(string)$c['materiality']==='high');$resolved=$filter(fn($c)=>(string)$c['change_type']==='resolved');
        $newQuestions=$filter(fn($c)=>in_array((string)$c['object_type'],['open_question','contradiction'],true)&&(string)$c['change_type']==='introduced');
        return research_system_report_section('Change summary',$summaryHtml)
          .research_system_report_section('Material changes',research_longitudinal_change_html($material,40))
          .research_system_report_section('Resolved items',research_longitudinal_change_html($resolved,25))
          .research_system_report_section('New questions and contradictions',research_longitudinal_change_html($newQuestions,25))
          .research_system_report_section('Next review',research_longitudinal_change_html(array_slice($material,0,12),12));
    }
    if($type==='confidence_contradictions'){
        $claimMoves=$filter(fn($c)=>(string)$c['object_type']==='claim'&&in_array((string)$c['change_type'],['strengthened','weakened','verified','disputed','changed'],true));
        $verified=$filter(fn($c)=>(string)$c['object_type']==='claim'&&(string)$c['change_type']==='verified');
        $contradictions=$filter(fn($c)=>(string)$c['object_type']==='contradiction');
        $atRisk=$filter(fn($c)=>in_array((string)$c['change_type'],['weakened','disputed'],true));
        return research_system_report_section('Confidence movement',research_longitudinal_change_html($claimMoves,40))
          .research_system_report_section('Verification milestones',research_longitudinal_change_html($verified,25))
          .research_system_report_section('Contradiction history',research_longitudinal_change_html($contradictions,30))
          .research_system_report_section('At-risk knowledge',research_longitudinal_change_html($atRisk,25));
    }
    if($type==='open_questions_evolution'){
        $current=array_values((array)($state['open_question']??[]));$currentHtml='<ul>';foreach(array_slice($current,0,40) as $q)$currentHtml.=research_system_report_li((string)($q['title']??'Open question'),(string)($q['detail']??''),(string)($q['priority']??''));$currentHtml.='</ul>';if(!$current)$currentHtml=research_system_report_empty('No open longitudinal questions are present in the latest snapshot.');
        $opened=$filter(fn($c)=>(string)$c['object_type']==='open_question'&&(string)$c['change_type']==='introduced');
        $resolved=$filter(fn($c)=>(string)$c['object_type']==='open_question'&&(string)$c['change_type']==='resolved');
        $changed=$filter(fn($c)=>(string)$c['object_type']==='open_question'&&(string)$c['change_type']==='changed');
        return research_system_report_section('Open question state',$currentHtml)
          .research_system_report_section('New questions',research_longitudinal_change_html($opened,30))
          .research_system_report_section('Persistent questions',research_longitudinal_change_html($changed,30))
          .research_system_report_section('Resolved questions',research_longitudinal_change_html($resolved,30))
          .research_system_report_section('Recommended follow-up',research_longitudinal_change_html(array_merge($opened,$changed),20));
    }
    $entities=$filter(fn($c)=>(string)$c['object_type']==='entity');$relations=$filter(fn($c)=>in_array((string)$c['object_type'],['entity_relation','claim_relation'],true));$emerging=(array)($summary['emerging']??[]);
    $emergingHtml='<ul>';foreach(array_slice($emerging,0,25) as $r){$lc=(array)($r['latest']??[]);$row=$lc['after']??$lc['before']??[];$emergingHtml.=research_system_report_li(research_longitudinal_object_title((string)$r['object_type'],(array)$row),(int)$r['changes'].' changes in the period',ucwords(str_replace('_',' ',(string)$r['object_type'])));}$emergingHtml.='</ul>';if(!$emerging)$emergingHtml=research_system_report_empty('No repeatedly changing entities or themes are detected yet.');
    return research_system_report_section('Entity movement',research_longitudinal_change_html($entities,35))
      .research_system_report_section('Relationship changes',research_longitudinal_change_html($relations,35))
      .research_system_report_section('Emerging themes',$emergingHtml)
      .research_system_report_section('Persistent themes',$emergingHtml)
      .research_system_report_section('Implications',research_longitudinal_change_html(array_slice(array_values(array_filter($changes,fn($c)=>(string)$c['materiality']==='high')),0,20),20));
}

function research_longitudinal_agent_context(PDO $pdo,array $viewer,string $agentPublic,int $days=30): string {
    if(!research_longitudinal_ready($pdo))return '';$data=research_longitudinal_report_data($pdo,$viewer,$agentPublic,$days);if(empty($data['ready']))return '[LONGITUDINAL RESEARCH]\nNo longitudinal baseline has been captured yet.';
    $s=$data['summary'];$lines=['[LONGITUDINAL RESEARCH — LAST '.$days.' DAYS]','Changes: '.count((array)$s['changes']).'; high materiality: '.(int)($s['materiality']['high']??0).'; milestones: '.count((array)$s['milestones']).'.'];
    foreach(array_slice((array)$s['changes'],0,20) as $c){$row=$c['after']??$c['before']??[];$lines[]='- '.strtoupper((string)$c['change_type']).' · '.ucwords(str_replace('_',' ',(string)$c['object_type'])).' · '.research_longitudinal_object_title((string)$c['object_type'],(array)$row).' — '.(string)$c['reason'];}
    return mb_substr(implode("\n",$lines),0,14000);
}

function research_longitudinal_capture_program(PDO $pdo,array $program,int $runId,string $trigger): ?array {
    if(!research_longitudinal_ready($pdo))return null;
    try{$viewer=research_program_owner($pdo,$program);$q=$pdo->prepare('SELECT public_id FROM research_agents WHERE id=? LIMIT 1');$q->execute([(int)$program['research_agent_id']]);$agentPublic=(string)($q->fetchColumn()?:'');if($agentPublic==='')return null;
        $q=$pdo->prepare('SELECT public_id FROM research_program_runs WHERE id=? LIMIT 1');$q->execute([$runId]);$runPublic=(string)($q->fetchColumn()?:'');
        return research_longitudinal_capture($pdo,$viewer,$agentPublic,$trigger,$runPublic?:null);
    }catch(Throwable $e){error_log('[Annotated Longitudinal Research] '.$e->getMessage());return null;}
}

function research_longitudinal_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=12): void {
    if(!research_longitudinal_ready($pdo))return;$limit=max(1,min(30,$limit));
    $q=$pdo->prepare("SELECT rlc.public_id,rlc.research_agent_id,rlc.object_type,rlc.object_public_id,rlc.change_type,rlc.materiality,rlc.reason,rlc.after_json,rlc.before_json,rlc.occurred_at,ra.public_id agent_public_id,ra.name agent_name
      FROM research_longitudinal_changes rlc JOIN research_agents ra ON ra.id=rlc.research_agent_id
      WHERE rlc.materiality='high' AND rlc.occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY rlc.id DESC LIMIT ".($limit*4));$q->execute();
    $added=0;foreach($q->fetchAll()?:[] as $r){if($added>=$limit)break;if(!research_agent_access($pdo,$viewer,(string)$r['agent_public_id']))continue;$row=json_decode((string)($r['after_json']?:$r['before_json']?:'{}'),true)?:[];$title=research_longitudinal_object_title((string)$r['object_type'],$row);
        cognitive_feed_add($items,['key'=>cognitive_feed_key('research_longitudinal_change','research_change',(string)$r['public_id'],(string)$r['occurred_at']),'type'=>'research_longitudinal_change',
          'section'=>in_array((string)$r['change_type'],['weakened','disputed','removed'],true)?'needs_attention':'recent_changes','priority'=>'high','created_at'=>$r['occurred_at'],
          'title'=>ucwords(str_replace('_',' ',(string)$r['change_type'])).': '.$title,'body'=>(string)$r['reason'],'meta'=>['agent'=>$r['agent_name'],'object_type'=>$r['object_type']],
          'actions'=>[cognitive_feed_action_link('Open evolution','/research-evolution.php?agent='.rawurlencode((string)$r['agent_public_id'])),cognitive_feed_action_agent('Ask Agent','Explain this Research change, the evidence behind it, and what I should review next.',[['type'=>'research','public_id'=>(string)$r['agent_public_id']]])]]);
        $added++;
    }
}
