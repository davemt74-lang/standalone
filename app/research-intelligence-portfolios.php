<?php
declare(strict_types=1);

/**
 * Phase 60 Program Portfolio intelligence.
 *
 * This service deliberately does not replace Phase 23's personal project
 * attention portfolio. It groups Phase 58 Research Programs and derives
 * deterministic cross-program intelligence from their authoritative state.
 * Agent interpretation is stored separately as insight_kind=inference.
 */

function research_intelligence_portfolios_ready(PDO $pdo): bool {
    try{
        foreach(['research_intelligence_portfolios','research_intelligence_portfolio_programs','research_intelligence_portfolio_snapshots','research_intelligence_insights','research_executive_briefings','research_intelligence_portfolio_events'] as $table){
            if(!installer_table_exists($pdo,$table))return false;
        }
        return research_programs_ready($pdo)&&research_publications_ready($pdo)&&research_agent_workspace_ready($pdo);
    }catch(Throwable $e){return false;}
}

function research_intelligence_portfolio_can_write(array $portfolio): bool {
    return in_array((string)($portfolio['access_role']??''),['owner','admin','researcher'],true);
}

function research_intelligence_portfolio_event(PDO $pdo,int $portfolioId,string $event,string $actor='system',?int $actorUserId=null,array $payload=[]): void {
    $actor=in_array($actor,['user','agent','system'],true)?$actor:'system';
    $pdo->prepare("INSERT INTO research_intelligence_portfolio_events(public_id,portfolio_id,event_type,actor_type,actor_user_id,payload_json) VALUES(?,?,?,?,?,?)")
      ->execute([ulid_like(),$portfolioId,mb_substr(trim($event),0,64),$actor,$actorUserId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}

function research_intelligence_portfolio_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_intelligence_portfolios_ready($pdo))return null;$uid=(int)$viewer['id'];
    $q=$pdo->prepare("SELECT p.*,t.public_id team_public_id,t.name team_name,
      CASE WHEN p.owner_user_id=? THEN 'owner' WHEN p.team_id IS NOT NULL THEN COALESCE(tm.role,'') ELSE '' END access_role
      FROM research_intelligence_portfolios p
      LEFT JOIN teams t ON t.id=p.team_id
      LEFT JOIN team_members tm ON tm.team_id=p.team_id AND tm.user_id=?
      WHERE p.public_id=? AND (p.owner_user_id=? OR (p.team_id IS NOT NULL AND tm.user_id IS NOT NULL)) LIMIT 1");
    $q->execute([$uid,$uid,trim($publicId),$uid]);$row=$q->fetch();return $row?:null;
}

function research_intelligence_portfolio_list(PDO $pdo,array $viewer,int $limit=100,bool $includeArchived=false): array {
    if(!research_intelligence_portfolios_ready($pdo))return [];$uid=(int)$viewer['id'];$limit=max(1,min(200,$limit));
    $status=$includeArchived?"p.status IN ('active','archived')":"p.status='active'";
    $q=$pdo->prepare("SELECT p.*,t.public_id team_public_id,t.name team_name,
      CASE WHEN p.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role,
      COUNT(DISTINCT pm.program_id) program_count,COUNT(DISTINCT b.id) briefing_count,
      MAX(b.created_at) latest_briefing_at
      FROM research_intelligence_portfolios p
      LEFT JOIN teams t ON t.id=p.team_id
      LEFT JOIN team_members tm ON tm.team_id=p.team_id AND tm.user_id=?
      LEFT JOIN research_intelligence_portfolio_programs pm ON pm.portfolio_id=p.id
      LEFT JOIN research_executive_briefings b ON b.portfolio_id=p.id
      WHERE $status AND (p.owner_user_id=? OR (p.team_id IS NOT NULL AND tm.user_id IS NOT NULL))
      GROUP BY p.id,t.public_id,t.name,tm.role ORDER BY p.updated_at DESC,p.id DESC LIMIT ".$limit);
    $q->execute([$uid,$uid,$uid]);return $q->fetchAll()?:[];
}

function research_intelligence_portfolio_team(PDO $pdo,array $viewer,string $teamPublic): ?array {
    $teamPublic=trim($teamPublic);if($teamPublic==='')return null;
    $q=$pdo->prepare("SELECT t.*,tm.role access_role FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? WHERE t.public_id=? LIMIT 1");
    $q->execute([(int)$viewer['id'],$teamPublic]);$team=$q->fetch();
    if(!$team||!in_array((string)$team['access_role'],['owner','admin','researcher'],true))throw new RuntimeException('Choose a Team where you can manage Research.');
    return $team;
}

function research_intelligence_portfolio_create(PDO $pdo,array $viewer,array $input,bool $byAgent=false): array {
    if(!research_intelligence_portfolios_ready($pdo))throw new RuntimeException('Research Intelligence Portfolios require the latest database upgrade.');
    $title=mb_substr(trim((string)($input['title']??'')),0,255);$objective=mb_substr(trim((string)($input['objective']??'')),0,16000);
    if($title===''||$objective==='')throw new InvalidArgumentException('Portfolio title and objective are required.');
    $team=research_intelligence_portfolio_team($pdo,$viewer,(string)($input['team_id']??''));
    $cadence=(string)($input['briefing_cadence']??'manual');if(!in_array($cadence,['manual','weekly','monthly','quarterly'],true))$cadence='manual';
    $timezone=trim((string)($input['timezone_name']??($viewer['timezone_name']??'UTC')));research_automation_timezone($timezone);
    $time=research_automation_time((string)($input['briefing_time_local']??'09:00'));
    $weekday=in_array($cadence,['weekly'],true)?max(0,min(6,(int)($input['briefing_weekday']??1))):null;
    $day=in_array($cadence,['monthly','quarterly'],true)?max(1,min(28,(int)($input['briefing_day_of_month']??1))):null;
    $public=ulid_like();$pdo->prepare("INSERT INTO research_intelligence_portfolios(public_id,owner_user_id,team_id,title,objective,briefing_cadence,timezone_name,briefing_time_local,briefing_weekday,briefing_day_of_month) VALUES(?,?,?,?,?,?,?,?,?,?)")
      ->execute([$public,(int)$viewer['id'],$team['id']??null,$title,$objective,$cadence,$timezone,$time,$weekday,$day]);
    $portfolio=research_intelligence_portfolio_access($pdo,$viewer,$public);if(!$portfolio)throw new RuntimeException('Portfolio could not be created.');
    research_intelligence_portfolio_event($pdo,(int)$portfolio['id'],'created',$byAgent?'agent':'user',(int)$viewer['id'],['scope'=>$team?'team':'personal']);
    return $portfolio;
}

function research_intelligence_portfolio_update(PDO $pdo,array $viewer,string $publicId,array $input): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$publicId);if(!$p)throw new RuntimeException('Portfolio not found.');
    if(!research_intelligence_portfolio_can_write($p))throw new RuntimeException('You have view-only access to this Portfolio.');
    $title=mb_substr(trim((string)($input['title']??$p['title'])),0,255);$objective=mb_substr(trim((string)($input['objective']??$p['objective'])),0,16000);if($title===''||$objective==='')throw new InvalidArgumentException('Portfolio title and objective are required.');
    $cadence=(string)($input['briefing_cadence']??$p['briefing_cadence']);if(!in_array($cadence,['manual','weekly','monthly','quarterly'],true))$cadence=(string)$p['briefing_cadence'];
    $timezone=trim((string)($input['timezone_name']??$p['timezone_name']));research_automation_timezone($timezone);$time=research_automation_time((string)($input['briefing_time_local']??$p['briefing_time_local']));
    $weekday=$cadence==='weekly'?max(0,min(6,(int)($input['briefing_weekday']??$p['briefing_weekday']??1))):null;$day=in_array($cadence,['monthly','quarterly'],true)?max(1,min(28,(int)($input['briefing_day_of_month']??$p['briefing_day_of_month']??1))):null;
    $pdo->prepare("UPDATE research_intelligence_portfolios SET title=?,objective=?,briefing_cadence=?,timezone_name=?,briefing_time_local=?,briefing_weekday=?,briefing_day_of_month=?,updated_at=NOW() WHERE id=?")
      ->execute([$title,$objective,$cadence,$timezone,$time,$weekday,$day,(int)$p['id']]);
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'updated','user',(int)$viewer['id']);
    return research_intelligence_portfolio_access($pdo,$viewer,$publicId)??$p;
}

function research_intelligence_portfolio_program_scope_ok(array $portfolio,array $program): bool {
    $portfolioTeam=(int)($portfolio['team_id']??0);$projectTeam=(int)($program['team_id']??0);
    if($portfolioTeam>0)return $projectTeam===$portfolioTeam;
    return $projectTeam===0&&(int)($program['project_owner_user_id']??0)===(int)$portfolio['owner_user_id'];
}

function research_intelligence_portfolio_program_by_public(PDO $pdo,array $viewer,string $programPublic): ?array {
    $program=research_program_access($pdo,$viewer,trim($programPublic));if(!$program)return null;
    $q=$pdo->prepare("SELECT rp.*,proj.team_id,proj.owner_user_id project_owner_user_id,proj.public_id project_public_id,proj.title project_title,ra.public_id agent_public_id,ra.name agent_name,c.public_id conversation_public_id
      FROM research_programs rp JOIN research_projects proj ON proj.id=rp.project_id JOIN research_agents ra ON ra.id=rp.research_agent_id JOIN conversations c ON c.id=ra.conversation_id WHERE rp.id=? LIMIT 1");
    $q->execute([(int)$program['id']]);return $q->fetch()?:null;
}

function research_intelligence_portfolio_add_program(PDO $pdo,array $viewer,string $portfolioPublic,string $programPublic,string $role='supporting'): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)throw new RuntimeException('Portfolio not found.');if(!research_intelligence_portfolio_can_write($p))throw new RuntimeException('You have view-only access to this Portfolio.');
    $program=research_intelligence_portfolio_program_by_public($pdo,$viewer,$programPublic);if(!$program)throw new RuntimeException('Research Program is unavailable.');
    if(!research_intelligence_portfolio_program_scope_ok($p,$program))throw new RuntimeException('A Portfolio can only contain Programs from the same personal or Team Research boundary.');
    $role=in_array($role,['primary','supporting','watch'],true)?$role:'supporting';
    $pdo->prepare("INSERT INTO research_intelligence_portfolio_programs(portfolio_id,program_id,added_by_user_id,member_role) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE member_role=VALUES(member_role)")
      ->execute([(int)$p['id'],(int)$program['id'],(int)$viewer['id'],$role]);
    $anchor=(int)($p['anchor_program_id']??0);if($anchor===0||$role==='primary')$pdo->prepare('UPDATE research_intelligence_portfolios SET anchor_program_id=?,updated_at=NOW() WHERE id=?')->execute([(int)$program['id'],(int)$p['id']]);
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'program_added','user',(int)$viewer['id'],['program_id'=>(string)$program['public_id'],'role'=>$role]);
    return research_intelligence_portfolio_detail($pdo,$viewer,$portfolioPublic)??$p;
}

function research_intelligence_portfolio_remove_program(PDO $pdo,array $viewer,string $portfolioPublic,string $programPublic): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)throw new RuntimeException('Portfolio not found.');if(!research_intelligence_portfolio_can_write($p))throw new RuntimeException('You have view-only access to this Portfolio.');
    $program=research_intelligence_portfolio_program_by_public($pdo,$viewer,$programPublic);if(!$program)throw new RuntimeException('Research Program is unavailable.');
    $pdo->prepare('DELETE FROM research_intelligence_portfolio_programs WHERE portfolio_id=? AND program_id=?')->execute([(int)$p['id'],(int)$program['id']]);
    if((int)($p['anchor_program_id']??0)===(int)$program['id']){$q=$pdo->prepare("SELECT program_id FROM research_intelligence_portfolio_programs WHERE portfolio_id=? ORDER BY member_role='primary' DESC,created_at,program_id LIMIT 1");$q->execute([(int)$p['id']]);$next=(int)($q->fetchColumn()?:0);$pdo->prepare('UPDATE research_intelligence_portfolios SET anchor_program_id=?,updated_at=NOW() WHERE id=?')->execute([$next?:null,(int)$p['id']]);}
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'program_removed','user',(int)$viewer['id'],['program_id'=>(string)$program['public_id']]);
    return research_intelligence_portfolio_detail($pdo,$viewer,$portfolioPublic)??$p;
}

function research_intelligence_portfolio_programs(PDO $pdo,array $viewer,array $portfolio): array {
    $q=$pdo->prepare("SELECT rp.*,pm.member_role,proj.public_id project_public_id,proj.title project_title,proj.team_id,proj.owner_user_id project_owner_user_id,ra.public_id agent_public_id,ra.name agent_name,c.public_id conversation_public_id,
      (SELECT COUNT(*) FROM research_program_runs rr WHERE rr.program_id=rp.id AND rr.status='completed' AND rr.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) completed_30d,
      (SELECT COUNT(*) FROM research_program_runs rr WHERE rr.program_id=rp.id AND rr.status='failed' AND rr.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) failed_30d
      FROM research_intelligence_portfolio_programs pm JOIN research_programs rp ON rp.id=pm.program_id JOIN research_projects proj ON proj.id=rp.project_id
      JOIN research_agents ra ON ra.id=rp.research_agent_id JOIN conversations c ON c.id=ra.conversation_id WHERE pm.portfolio_id=? ORDER BY pm.member_role='primary' DESC,rp.priority='urgent' DESC,rp.priority='high' DESC,rp.title");
    $q->execute([(int)$portfolio['id']]);$out=[];foreach($q->fetchAll() as $r){if(research_program_access($pdo,$viewer,(string)$r['public_id']))$out[]=$r;}return $out;
}

function research_intelligence_portfolio_available_programs(PDO $pdo,array $viewer,array $portfolio,int $limit=200): array {
    $uid=(int)$viewer['id'];$limit=max(1,min(300,$limit));$team=(int)($portfolio['team_id']??0);
    if($team>0){$q=$pdo->prepare("SELECT rp.public_id,rp.title,rp.status,rp.priority,proj.title project_title,ra.name agent_name FROM research_programs rp JOIN research_projects proj ON proj.id=rp.project_id JOIN research_agents ra ON ra.id=rp.research_agent_id JOIN team_members tm ON tm.team_id=proj.team_id AND tm.user_id=? WHERE proj.team_id=? AND rp.status<>'archived' AND NOT EXISTS(SELECT 1 FROM research_intelligence_portfolio_programs pm WHERE pm.portfolio_id=? AND pm.program_id=rp.id) ORDER BY rp.updated_at DESC LIMIT ".$limit);$q->execute([$uid,$team,(int)$portfolio['id']]);}
    else{$q=$pdo->prepare("SELECT rp.public_id,rp.title,rp.status,rp.priority,proj.title project_title,ra.name agent_name FROM research_programs rp JOIN research_projects proj ON proj.id=rp.project_id JOIN research_agents ra ON ra.id=rp.research_agent_id WHERE proj.team_id IS NULL AND proj.owner_user_id=? AND rp.status<>'archived' AND NOT EXISTS(SELECT 1 FROM research_intelligence_portfolio_programs pm WHERE pm.portfolio_id=? AND pm.program_id=rp.id) ORDER BY rp.updated_at DESC LIMIT ".$limit);$q->execute([$uid,(int)$portfolio['id']]);}
    return $q->fetchAll()?:[];
}

function research_intelligence_portfolio_delta_polarity(string $type): int {
    if(in_array($type,['source_unavailable','claim_weakened','claim_contradicted','contradiction_opened'],true))return -1;
    if(in_array($type,['source_added','source_restored','claim_added','claim_strengthened','claim_resolved','contradiction_resolved'],true))return 1;
    return 0;
}

function research_intelligence_portfolio_aggregate(PDO $pdo,array $viewer,array $portfolio,int $windowDays=30): array {
    $windowDays=max(1,min(365,$windowDays));$programs=research_intelligence_portfolio_programs($pdo,$viewer,$portfolio);
    $programIds=array_map(fn($p)=>(int)$p['id'],$programs);$summary=['programs'=>count($programs),'active'=>0,'paused'=>0,'failed_runs_30d'=>0,'completed_runs_30d'=>0,'material_changes'=>0,'high_changes'=>0,'stale_sources'=>0];
    foreach($programs as $p){if(($p['status']??'')==='active')$summary['active']++;if(($p['status']??'')==='paused')$summary['paused']++;$summary['failed_runs_30d']+=(int)$p['failed_30d'];$summary['completed_runs_30d']+=(int)$p['completed_30d'];}
    $deltas=[];$trends=[];$risks=[];$opportunities=[];$refs=[];$highTypes=['source_unavailable','claim_weakened','claim_contradicted','contradiction_opened'];
    if($programIds){$in=implode(',',array_map('intval',$programIds));$q=$pdo->prepare("SELECT d.*,rp.public_id program_public_id,rp.title program_title FROM research_program_deltas d JOIN research_programs rp ON rp.id=d.program_id WHERE d.program_id IN ($in) AND d.occurred_at>=DATE_SUB(NOW(),INTERVAL ".$windowDays." DAY) ORDER BY d.occurred_at DESC,d.id DESC LIMIT 1200");$q->execute();$deltas=$q->fetchAll()?:[];
        foreach($deltas as $d){$type=(string)$d['delta_type'];$trends[$type]=($trends[$type]??0)+1;$summary['material_changes']++;if(($d['importance']??'')==='high')$summary['high_changes']++;
            $item=['type'=>$type,'importance'=>$d['importance'],'summary'=>$d['summary'],'program_id'=>$d['program_public_id'],'program_title'=>$d['program_title'],'ref_type'=>$d['ref_type'],'ref_public_id'=>$d['ref_public_id'],'occurred_at'=>$d['occurred_at']];
            if(in_array($type,$highTypes,true))$risks[]=$item;elseif(research_intelligence_portfolio_delta_polarity($type)>0)$opportunities[]=$item;
            $rk=trim((string)($d['ref_type']??'')).':'.trim((string)($d['ref_public_id']??''));if($rk!==':'){$refs[$rk]['programs'][(string)$d['program_public_id']]=true;$refs[$rk]['polarities'][]=research_intelligence_portfolio_delta_polarity($type);$refs[$rk]['items'][]=$item;}
        }
        $q=$pdo->prepare("SELECT COUNT(*) FROM project_sources ps JOIN sources s ON s.id=ps.source_id LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE ps.project_id IN (SELECT DISTINCT project_id FROM research_programs WHERE id IN ($in)) AND (sv.captured_at IS NULL OR sv.captured_at<DATE_SUB(NOW(),INTERVAL 30 DAY))");$q->execute();$summary['stale_sources']=(int)$q->fetchColumn();
    }
    arsort($trends);$cross=[];foreach($refs as $key=>$g){if(count($g['programs'])<2)continue;$polarities=array_values(array_unique(array_filter($g['polarities'],fn($v)=>$v!==0)));$tension=in_array(-1,$polarities,true)&&in_array(1,$polarities,true);
        $cross[]=['key'=>$key,'kind'=>$tension?'cross_program_tension':'shared_signal','program_count'=>count($g['programs']),'program_ids'=>array_keys($g['programs']),'items'=>array_slice($g['items'],0,8)];
    }
    usort($cross,fn($a,$b)=>($b['program_count']<=>$a['program_count']));
    return ['window_days'=>$windowDays,'summary'=>$summary,'trends'=>$trends,'risks'=>array_slice($risks,0,30),'opportunities'=>array_slice($opportunities,0,30),'cross_program'=>array_slice($cross,0,30),'generated_at'=>date('Y-m-d H:i:s')];
}

function research_intelligence_portfolio_provenance(array $programs,array $aggregate): array {
    $rows=[];foreach($programs as $p)$rows[]=['program_id'=>(string)$p['public_id'],'program_revision'=>(int)$p['current_revision'],'last_run_at'=>$p['last_run_at'],'last_material_hash'=>$p['last_material_hash'],'project_id'=>(string)$p['project_public_id']];
    return ['programs'=>$rows,'window_days'=>(int)$aggregate['window_days'],'generated_at'=>(string)$aggregate['generated_at']];
}

function research_intelligence_portfolio_snapshot(PDO $pdo,array $viewer,string $portfolioPublic,int $windowDays=30,string $kind='manual'): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)throw new RuntimeException('Portfolio not found.');if(!research_intelligence_portfolio_can_write($p))throw new RuntimeException('You have view-only access to this Portfolio.');
    $kind=in_array($kind,['manual','briefing','system'],true)?$kind:'manual';$programs=research_intelligence_portfolio_programs($pdo,$viewer,$p);if(!$programs)throw new RuntimeException('Add at least one Research Program before creating a Portfolio snapshot.');
    $aggregate=research_intelligence_portfolio_aggregate($pdo,$viewer,$p,$windowDays);$provenance=research_intelligence_portfolio_provenance($programs,$aggregate);
    $aggregateJson=json_encode($aggregate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$provenanceJson=json_encode($provenance,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$aggregateJson."\n".$provenanceJson);$public=ulid_like();
    $pdo->prepare("INSERT INTO research_intelligence_portfolio_snapshots(public_id,portfolio_id,created_by_user_id,snapshot_kind,window_days,aggregate_json,provenance_json,snapshot_hash) VALUES(?,?,?,?,?,?,?,?)")->execute([$public,(int)$p['id'],(int)$viewer['id'],$kind,max(1,min(365,$windowDays)),$aggregateJson,$provenanceJson,$hash]);$id=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE research_intelligence_portfolios SET latest_snapshot_id=?,updated_at=NOW() WHERE id=?')->execute([$id,(int)$p['id']]);research_intelligence_portfolio_event($pdo,(int)$p['id'],'snapshot_created','user',(int)$viewer['id'],['snapshot_id'=>$public,'kind'=>$kind,'hash'=>$hash]);
    return ['id'=>$id,'public_id'=>$public,'portfolio_id'=>(int)$p['id'],'snapshot_kind'=>$kind,'window_days'=>$aggregate['window_days'],'aggregate'=>$aggregate,'provenance'=>$provenance,'snapshot_hash'=>$hash,'created_at'=>date('Y-m-d H:i:s')];
}

function research_intelligence_portfolio_snapshots(PDO $pdo,array $portfolio,int $limit=20): array {
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT public_id,snapshot_kind,window_days,snapshot_hash,created_at FROM research_intelligence_portfolio_snapshots WHERE portfolio_id=? ORDER BY id DESC LIMIT ".$limit);$q->execute([(int)$portfolio['id']]);return $q->fetchAll()?:[];
}

function research_intelligence_portfolio_clean_refs(array $refs): array {
    $out=[];foreach(array_slice($refs,0,60) as $r){if(!is_array($r))continue;$type=mb_substr(strtolower(trim((string)($r['type']??''))),0,40);$id=mb_substr(trim((string)($r['id']??'')),0,80);if($type===''||$id==='')continue;$k=$type.':'.$id;$out[$k]=['type'=>$type,'id'=>$id,'label'=>mb_substr(trim((string)($r['label']??'')),0,255)];}return array_values($out);
}

function research_intelligence_portfolio_add_inference(PDO $pdo,array $viewer,string $portfolioPublic,array $input,bool $byAgent=false): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)throw new RuntimeException('Portfolio not found.');if(!research_intelligence_portfolio_can_write($p))throw new RuntimeException('You have view-only access to this Portfolio.');
    $title=mb_substr(trim((string)($input['title']??'')),0,255);$body=mb_substr(trim((string)($input['body']??'')),0,12000);if($title===''||$body==='')throw new InvalidArgumentException('Inference title and explanation are required.');
    $category=(string)($input['category']??'other');if(!in_array($category,['trend','risk','opportunity','contradiction','decision','freshness','dependency','other'],true))$category='other';$severity=(string)($input['severity']??'info');if(!in_array($severity,['info','watch','high'],true))$severity='info';
    $confidence=array_key_exists('confidence',$input)?max(0,min(1,(float)$input['confidence'])):null;$refs=research_intelligence_portfolio_clean_refs((array)($input['provenance_refs']??[]));if(!$refs)throw new InvalidArgumentException('Agent inference requires at least one explicit provenance reference.');
    $snapshotId=null;if(!empty($input['snapshot_id'])){$q=$pdo->prepare('SELECT id FROM research_intelligence_portfolio_snapshots WHERE public_id=? AND portfolio_id=?');$q->execute([(string)$input['snapshot_id'],(int)$p['id']]);$snapshotId=(int)($q->fetchColumn()?:0)?:null;}
    $provenance=json_encode(['refs'=>$refs],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$fingerprint=hash('sha256',$category.'|'.$severity.'|'.mb_strtolower($title).'|'.$provenance);$public=ulid_like();
    $pdo->prepare("INSERT INTO research_intelligence_insights(public_id,portfolio_id,snapshot_id,insight_kind,category,severity,title,body,confidence,provenance_json,fingerprint,created_by_user_id,created_by_agent) VALUES(?,?,?,'inference',?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE body=VALUES(body),confidence=VALUES(confidence),provenance_json=VALUES(provenance_json),status='active',resolved_at=NULL,updated_at=NOW()")
      ->execute([$public,(int)$p['id'],$snapshotId,$category,$severity,$title,$body,$confidence,$provenance,$fingerprint,(int)$viewer['id'],$byAgent?1:0]);
    $q=$pdo->prepare('SELECT * FROM research_intelligence_insights WHERE portfolio_id=? AND fingerprint=? LIMIT 1');$q->execute([(int)$p['id'],$fingerprint]);$row=$q->fetch();if(!$row)throw new RuntimeException('Inference could not be saved.');$row['provenance']=json_decode((string)$row['provenance_json'],true)?:[];
    research_intelligence_portfolio_event($pdo,(int)$p['id'],'inference_recorded',$byAgent?'agent':'user',(int)$viewer['id'],['insight_id'=>$row['public_id'],'category'=>$category]);return $row;
}

function research_intelligence_portfolio_inferences(PDO $pdo,array $portfolio,int $limit=50): array {
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT * FROM research_intelligence_insights WHERE portfolio_id=? AND insight_kind='inference' AND status='active' ORDER BY FIELD(severity,'high','watch','info'),updated_at DESC,id DESC LIMIT ".$limit);$q->execute([(int)$portfolio['id']]);$rows=$q->fetchAll()?:[];foreach($rows as &$r){$r['provenance']=json_decode((string)$r['provenance_json'],true)?:[];}unset($r);return $rows;
}

function research_intelligence_portfolio_briefings(PDO $pdo,array $portfolio,int $limit=30): array {
    $limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT b.*,rwo.public_id document_public_id,rwo.title document_title,pw.public_id publication_public_id,pw.status publication_status,
      ra.public_id agent_public_id,c.public_id conversation_public_id
      FROM research_executive_briefings b
      JOIN research_workspace_objects rwo ON rwo.id=b.document_object_id
      LEFT JOIN research_publication_workflows pw ON pw.id=b.publication_workflow_id
      LEFT JOIN research_agents ra ON ra.project_id=rwo.project_id AND ra.status<>'archived'
      LEFT JOIN conversations c ON c.id=ra.conversation_id
      WHERE b.portfolio_id=? ORDER BY b.id DESC LIMIT ".$limit);
    $q->execute([(int)$portfolio['id']]);return $q->fetchAll()?:[];
}

function research_intelligence_portfolio_render_briefing(array $portfolio,array $snapshot,array $inferences): string {
    $a=$snapshot['aggregate'];$h=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$html='<h1>'.$h($portfolio['title']).' — Executive Briefing</h1><p>'.$h($portfolio['objective']).'</p><p><strong>Evidence window:</strong> '.$h($a['window_days']).' days · <strong>Generated:</strong> '.$h($a['generated_at']).'</p>';
    $s=$a['summary'];$html.='<h2>Executive overview</h2><ul><li>'.$h($s['programs']).' Programs · '.$h($s['active']).' active · '.$h($s['paused']).' paused</li><li>'.$h($s['material_changes']).' material changes · '.$h($s['high_changes']).' high-priority changes</li><li>'.$h($s['failed_runs_30d']).' failed runs in the last 30 days</li><li>'.$h($s['stale_sources']).' source links have evidence older than 30 days or no current capture</li></ul>';
    $section=function(string $title,array $rows)use(&$html,$h){$html.='<h2>'.$h($title).'</h2>';if(!$rows){$html.='<p>No current items.</p>';return;}$html.='<ul>';foreach(array_slice($rows,0,15) as $r)$html.='<li><strong>'.$h($r['program_title']??$r['kind']??'Portfolio').':</strong> '.$h($r['summary']??($r['key']??'')).'</li>';$html.='</ul>';};
    $section('Risks',$a['risks']);$section('Opportunities',$a['opportunities']);$section('Cross-program signals',$a['cross_program']);
    $html.='<h2>What changed</h2>';if(!$a['trends'])$html.='<p>No material deltas in this window.</p>';else{$html.='<ul>';foreach(array_slice($a['trends'],0,15,true) as $type=>$count)$html.='<li>'.$h(str_replace('_',' ',$type)).': '.$h($count).'</li>';$html.='</ul>';}
    $html.='<h2>Agent interpretation</h2><p><em>The items below are explicitly stored inferences, not deterministic facts.</em></p>';if(!$inferences)$html.='<p>No Agent inferences are attached to this Portfolio.</p>';else{$html.='<ul>';foreach($inferences as $i)$html.='<li><strong>'.$h($i['title']).'</strong> ['.$h($i['category']).' · '.$h($i['severity']).'] '.$h($i['body']).($i['confidence']!==null?' Confidence '.$h(number_format((float)$i['confidence']*100,0)).'%.':'').'</li>';$html.='</ul>';}
    $html.='<h2>Provenance</h2><p>This briefing is derived from a frozen Portfolio snapshot. Every Program revision and last-material hash used for this snapshot is stored with the snapshot provenance.</p>';return $html;
}

function research_intelligence_portfolio_create_briefing(PDO $pdo,array $viewer,string $portfolioPublic,array $input=[],bool $byAgent=false): array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)throw new RuntimeException('Portfolio not found.');if(!research_intelligence_portfolio_can_write($p))throw new RuntimeException('You have view-only access to this Portfolio.');
    $programs=research_intelligence_portfolio_programs($pdo,$viewer,$p);if(!$programs)throw new RuntimeException('Add at least one Research Program before creating an Executive Briefing.');$anchor=null;$anchorId=(int)($p['anchor_program_id']??0);foreach($programs as $program)if((int)$program['id']===$anchorId){$anchor=$program;break;}$anchor=$anchor?:$programs[0];
    $project=project_access($pdo,(int)$viewer['id'],(string)$anchor['project_public_id']);if(!$project||!project_can_write($project))throw new RuntimeException('The anchor Program workspace is not writable.');
    $snapshot=research_intelligence_portfolio_snapshot($pdo,$viewer,$portfolioPublic,(int)($input['window_days']??30),'briefing');$inferences=research_intelligence_portfolio_inferences($pdo,$p,40);$html=research_intelligence_portfolio_render_briefing($p,$snapshot,$inferences);
    $title=mb_substr(trim((string)($input['title']??'')),0,240);if($title==='')$title=(string)$p['title'].' — Executive Briefing — '.gmdate('Y-m-d');
    $doc=research_agent_workspace_create_document($pdo,$viewer,$project,['title'=>$title,'content_html'=>$html,'summary'=>'Executive briefing across '.count($programs).' Research Programs. Deterministic aggregation and Agent inference are labeled separately.','document_type'=>'research_brief'],$byAgent);
    $public=ulid_like();$pdo->prepare("INSERT INTO research_executive_briefings(public_id,portfolio_id,snapshot_id,document_object_id,created_by_user_id,title) VALUES(?,?,?,?,?,?)")->execute([$public,(int)$p['id'],(int)$snapshot['id'],(int)$doc['id'],(int)$viewer['id'],$title]);
    $pdo->prepare('UPDATE research_intelligence_portfolios SET last_briefed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$p['id']]);research_intelligence_portfolio_event($pdo,(int)$p['id'],'briefing_created',$byAgent?'agent':'user',(int)$viewer['id'],['briefing_id'=>$public,'document_id'=>$doc['public_id'],'snapshot_id'=>$snapshot['public_id']]);
    return ['public_id'=>$public,'title'=>$title,'document'=>$doc,'snapshot'=>$snapshot,'portfolio'=>$p];
}

function research_intelligence_portfolio_briefing_access(PDO $pdo,array $viewer,string $publicId): ?array {
    $q=$pdo->prepare("SELECT b.*,p.public_id portfolio_public_id,rwo.public_id document_public_id,pw.public_id publication_public_id FROM research_executive_briefings b JOIN research_intelligence_portfolios p ON p.id=b.portfolio_id JOIN research_workspace_objects rwo ON rwo.id=b.document_object_id LEFT JOIN research_publication_workflows pw ON pw.id=b.publication_workflow_id WHERE b.public_id=? LIMIT 1");$q->execute([trim($publicId)]);$b=$q->fetch();if(!$b)return null;$p=research_intelligence_portfolio_access($pdo,$viewer,(string)$b['portfolio_public_id']);return $p?$b:null;
}

function research_intelligence_portfolio_prepare_publication(PDO $pdo,array $viewer,string $briefingPublic,array $input=[]): array {
    $b=research_intelligence_portfolio_briefing_access($pdo,$viewer,$briefingPublic);if(!$b)throw new RuntimeException('Executive Briefing not found.');$p=research_intelligence_portfolio_access($pdo,$viewer,(string)$b['portfolio_public_id']);if(!$p||!research_intelligence_portfolio_can_write($p))throw new RuntimeException('You have view-only access to this Portfolio.');
    if(!empty($b['publication_public_id'])){$w=research_publication_workflow_access($pdo,$viewer,(string)$b['publication_public_id']);if($w)return $w;}
    $workflow=research_publication_workflow_create($pdo,$viewer,(string)$b['document_public_id'],['title'=>(string)$b['title'],'summary'=>'Executive Briefing for '.$p['title'],'visibility'=>(string)($input['visibility']??($p['team_id']?'team':'private')),'required_approvals'=>max(1,(int)($input['required_approvals']??1)),'owner_approval'=>true,'review_complete'=>true,'no_unresolved_threads'=>true,'no_failed_task_gates'=>true,'no_high_contradictions'=>true,'fresh_evidence_days'=>max(1,min(365,(int)($input['fresh_evidence_days']??30)))]);
    $pdo->prepare("UPDATE research_executive_briefings SET publication_workflow_id=?,status='in_review',updated_at=NOW() WHERE id=?")->execute([(int)$workflow['id'],(int)$b['id']]);research_intelligence_portfolio_event($pdo,(int)$p['id'],'briefing_publication_prepared','user',(int)$viewer['id'],['briefing_id'=>$briefingPublic,'workflow_id'=>$workflow['public_id']]);return $workflow;
}

function research_intelligence_portfolio_next_briefing(array $p): ?string {
    $cadence=(string)($p['briefing_cadence']??'manual');if($cadence==='manual')return null;$last=trim((string)($p['last_briefed_at']??$p['created_at']??''));$base=$last!==''?strtotime($last):time();
    $seconds=match($cadence){'weekly'=>7*86400,'monthly'=>30*86400,'quarterly'=>90*86400,default=>0};return $seconds>0?gmdate('Y-m-d H:i:s',$base+$seconds):null;
}

function research_intelligence_portfolio_is_due(array $p): bool {$next=research_intelligence_portfolio_next_briefing($p);return $next!==null&&strtotime($next)<=time();}

function research_intelligence_portfolio_detail(PDO $pdo,array $viewer,string $publicId): ?array {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$publicId);if(!$p)return null;$p['programs']=research_intelligence_portfolio_programs($pdo,$viewer,$p);$p['available_programs']=research_intelligence_portfolio_available_programs($pdo,$viewer,$p);$p['aggregate']=research_intelligence_portfolio_aggregate($pdo,$viewer,$p,30);$p['snapshots']=research_intelligence_portfolio_snapshots($pdo,$p,20);$p['inferences']=research_intelligence_portfolio_inferences($pdo,$p,40);$p['briefings']=research_intelligence_portfolio_briefings($pdo,$p,30);$p['next_briefing_at']=research_intelligence_portfolio_next_briefing($p);$p['briefing_due']=research_intelligence_portfolio_is_due($p);return $p;
}

function research_intelligence_portfolio_contains_project(PDO $pdo,array $viewer,string $portfolioPublic,int $projectId): bool {
    $p=research_intelligence_portfolio_access($pdo,$viewer,$portfolioPublic);if(!$p)return false;$q=$pdo->prepare("SELECT rp.public_id FROM research_intelligence_portfolio_programs pm JOIN research_programs rp ON rp.id=pm.program_id WHERE pm.portfolio_id=? AND rp.project_id=? LIMIT 1");$q->execute([(int)$p['id'],$projectId]);$program=(string)($q->fetchColumn()?:'');return $program!==''&&research_program_access($pdo,$viewer,$program)!==null;
}

function research_intelligence_portfolio_dashboard(PDO $pdo,array $viewer): array {
    $portfolios=research_intelligence_portfolio_list($pdo,$viewer,120,false);$summary=['portfolios'=>count($portfolios),'programs'=>0,'teams'=>0,'briefings'=>0,'due'=>0,'risk_signals'=>0,'opportunity_signals'=>0,'cross_program_tensions'=>0];$teams=[];
    foreach($portfolios as &$p){$detail=research_intelligence_portfolio_detail($pdo,$viewer,(string)$p['public_id']);if(!$detail)continue;$p['aggregate']=$detail['aggregate'];$p['briefing_due']=$detail['briefing_due'];$p['next_briefing_at']=$detail['next_briefing_at'];$summary['programs']+=(int)$p['program_count'];$summary['briefings']+=(int)$p['briefing_count'];if($detail['briefing_due'])$summary['due']++;$summary['risk_signals']+=count($detail['aggregate']['risks']);$summary['opportunity_signals']+=count($detail['aggregate']['opportunities']);$summary['cross_program_tensions']+=count(array_filter($detail['aggregate']['cross_program'],fn($x)=>$x['kind']==='cross_program_tension'));if(!empty($p['team_public_id']))$teams[(string)$p['team_public_id']]=true;}unset($p);$summary['teams']=count($teams);return ['summary'=>$summary,'portfolios'=>$portfolios,'generated_at'=>date('Y-m-d H:i:s')];
}

function research_intelligence_portfolio_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=20): void {
    if(!research_intelligence_portfolios_ready($pdo))return;$added=0;foreach(research_intelligence_portfolio_list($pdo,$viewer,50,false) as $p){if($added>=$limit)break;$detail=research_intelligence_portfolio_detail($pdo,$viewer,(string)$p['public_id']);if(!$detail)continue;$a=$detail['aggregate'];$tensions=count(array_filter($a['cross_program'],fn($x)=>$x['kind']==='cross_program_tension'));$risks=count($a['risks']);$due=$detail['briefing_due'];if(!$due&&$tensions===0&&$risks===0)continue;$priority=$tensions>0||$risks>0?'high':'medium';$body=[];if($tensions)$body[]=$tensions.' cross-program tension(s)';if($risks)$body[]=$risks.' risk signal(s)';if($due)$body[]='executive briefing due';
        cognitive_feed_add($items,['key'=>cognitive_feed_key('portfolio_intelligence','research_intelligence_portfolio',(string)$p['public_id'],(string)$p['updated_at']),'type'=>'portfolio_intelligence','section'=>$priority==='high'?'needs_attention':'next_up','priority'=>$priority,'created_at'=>(string)$p['updated_at'],'title'=>(string)$p['title'].' · Portfolio intelligence','body'=>implode(' · ',$body),'meta'=>['programs'=>(int)$p['program_count'],'scope'=>!empty($p['team_name'])?(string)$p['team_name']:'Personal'],'actions'=>[cognitive_feed_action_link('Open Portfolio','/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$p['public_id']))]]);$added++;}
}
