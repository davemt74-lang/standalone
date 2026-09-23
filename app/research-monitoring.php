<?php
declare(strict_types=1);

function research_monitor_ready(PDO $pdo): bool {
    try{
        foreach(['research_monitor_watches','research_monitor_jobs','research_monitor_runs','research_monitor_candidates','research_monitor_events','research_monitor_claim_states'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}

function research_monitor_cadences(): array {return ['hourly'=>'Hourly','daily'=>'Daily','weekly'=>'Weekly','manual'=>'Manual only'];}
function research_monitor_types(): array {return ['url'=>'URL','domain'=>'Domain','topic'=>'Topic','entity'=>'Entity','claim'=>'Claim','query'=>'Search query'];}

function research_monitor_next_run(string $cadence,?DateTimeImmutable $from=null): ?string {
    $from=$from?:new DateTimeImmutable('now',new DateTimeZone('UTC'));
    return match($cadence){
        'hourly'=>$from->modify('+1 hour')->format('Y-m-d H:i:s'),
        'daily'=>$from->modify('+1 day')->format('Y-m-d H:i:s'),
        'weekly'=>$from->modify('+7 days')->format('Y-m-d H:i:s'),
        'manual'=>null,
        default=>throw new InvalidArgumentException('Invalid monitoring cadence.'),
    };
}

function research_monitor_target(string $type,string $target): array {
    $type=strtolower(trim($type));$target=trim($target);
    if(!isset(research_monitor_types()[$type]))throw new InvalidArgumentException('Invalid watch type.');
    if($target==='')throw new InvalidArgumentException('Monitoring target is required.');
    if(mb_strlen($target)>1000)throw new InvalidArgumentException('Monitoring target is too long.');
    $canonical=null;$query=null;$identity=$target;
    if($type==='url'){
        $parts=parse_url($target);if(!$parts||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||empty($parts['host']))throw new InvalidArgumentException('URL watches require a public HTTP(S) URL.');
        $canonical=canonicalize_url($target);$identity=$canonical;
    }elseif($type==='domain'){
        $domain=mb_strtolower($target);$domain=preg_replace('#^https?://#i','',$domain);$domain=trim((string)preg_replace('#/.*$#','',$domain),'. ');
        if($domain===''||!preg_match('/^[a-z0-9.-]+$/i',$domain)||!str_contains($domain,'.'))throw new InvalidArgumentException('Enter a valid public domain.');
        $identity=$domain;$query='site:'.$domain;
    }else{$query=$target;$identity=mb_strtolower($target);}
    return ['type'=>$type,'target'=>$target,'canonical_url'=>$canonical,'query_text'=>$query,'watch_key'=>hash('sha256',$type.'|'.$identity)];
}

function research_monitor_agent(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_agent_access($pdo,$viewer,trim($agentPublic));if(!$agent||($agent['status']??'')==='archived')throw new RuntimeException('Research Agent is unavailable.');
    return $agent;
}

function research_monitor_project_can_write(PDO $pdo,array $viewer,array $agent): array {
    $project=project_access($pdo,(int)$viewer['id'],(string)$agent['project_public_id']);
    if(!$project||!project_can_write($project))throw new RuntimeException('This Research workspace is read only.');
    return $project;
}

function research_monitor_watch_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_monitor_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rmw.*,ra.public_id agent_public_id,ra.name agent_name,ra.conversation_id,c.public_id conversation_public_id,rp.public_id project_public_id,rp.title project_title
      FROM research_monitor_watches rmw
      JOIN research_agents ra ON ra.id=rmw.research_agent_id
      JOIN research_projects rp ON rp.id=rmw.project_id
      JOIN conversations c ON c.id=ra.conversation_id
      LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=?
      WHERE rmw.public_id=? AND rmw.status<>'archived'
        AND ((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?)) LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($publicId),(int)$viewer['id'],(int)$viewer['id']]);return $q->fetch()?:null;
}

function research_monitor_list(PDO $pdo,array $viewer,string $agentPublic,int $limit=100): array {
    if(!research_monitor_ready($pdo))return [];$agent=research_monitor_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT public_id FROM research_monitor_watches WHERE research_agent_id=? AND status<>'archived' ORDER BY status='active' DESC,updated_at DESC,id DESC LIMIT ".$limit);
    $q->execute([(int)$agent['id']]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$row=research_monitor_watch_access($pdo,$viewer,(string)$id);if($row)$out[]=$row;}
    return $out;
}

function research_monitor_validate_claim(PDO $pdo,int $projectId,string $claimPublic): void {
    $q=$pdo->prepare('SELECT 1 FROM research_claims WHERE project_id=? AND public_id=? LIMIT 1');$q->execute([$projectId,$claimPublic]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Claim watch target must be a claim in this Research project.');
}

function research_monitor_create(PDO $pdo,array $viewer,array $input): array {
    if(!research_monitor_ready($pdo))throw new RuntimeException('Continuous Research Monitoring requires the latest database upgrade.');
    $agent=research_monitor_agent($pdo,$viewer,(string)($input['agent_id']??''));$project=research_monitor_project_can_write($pdo,$viewer,$agent);
    $norm=research_monitor_target((string)($input['watch_type']??'query'),(string)($input['target']??''));
    if($norm['type']==='claim')research_monitor_validate_claim($pdo,(int)$project['id'],$norm['target']);
    $cadence=strtolower(trim((string)($input['cadence']??$agent['monitoring_cadence']??'daily')));if(!isset(research_monitor_cadences()[$cadence]))$cadence='daily';
    $alert=in_array((string)($input['alert_level']??'important'),['all','important'],true)?(string)$input['alert_level']:'important';
    $auto=array_key_exists('auto_promote',$input)?((bool)$input['auto_promote']):in_array($norm['type'],['url','domain'],true);
    $public=ulid_like();$next=research_monitor_next_run($cadence);
    $pdo->prepare("INSERT INTO research_monitor_watches(public_id,research_agent_id,project_id,created_by_user_id,watch_type,target,canonical_url,query_text,watch_key,cadence,alert_level,auto_promote,status,next_check_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'active',?)
      ON DUPLICATE KEY UPDATE target=VALUES(target),canonical_url=VALUES(canonical_url),query_text=VALUES(query_text),cadence=VALUES(cadence),alert_level=VALUES(alert_level),auto_promote=VALUES(auto_promote),status='active',next_check_at=VALUES(next_check_at),last_error=NULL,updated_at=NOW()")
      ->execute([$public,(int)$agent['id'],(int)$project['id'],(int)$viewer['id'],$norm['type'],$norm['target'],$norm['canonical_url'],$norm['query_text'],$norm['watch_key'],$cadence,$alert,$auto?1:0,$next]);
    $q=$pdo->prepare('SELECT public_id FROM research_monitor_watches WHERE research_agent_id=? AND watch_key=? LIMIT 1');$q->execute([(int)$agent['id'],$norm['watch_key']]);$id=(string)$q->fetchColumn();
    $watch=research_monitor_watch_access($pdo,$viewer,$id);if($watch)research_monitor_queue($pdo,(int)$watch['id'],(int)$viewer['id'],'manual');
    return $watch?:[];
}

function research_monitor_update(PDO $pdo,array $viewer,string $publicId,array $input): array {
    $watch=research_monitor_watch_access($pdo,$viewer,$publicId);if(!$watch)throw new RuntimeException('Monitoring watch not found.');
    $agent=research_monitor_agent($pdo,$viewer,(string)$watch['agent_public_id']);research_monitor_project_can_write($pdo,$viewer,$agent);
    $cadence=strtolower(trim((string)($input['cadence']??$watch['cadence'])));if(!isset(research_monitor_cadences()[$cadence]))$cadence=(string)$watch['cadence'];
    $alert=in_array((string)($input['alert_level']??$watch['alert_level']),['all','important'],true)?(string)($input['alert_level']??$watch['alert_level']):(string)$watch['alert_level'];
    $auto=array_key_exists('auto_promote',$input)?((bool)$input['auto_promote']):(bool)$watch['auto_promote'];
    $next=research_monitor_next_run($cadence);
    $pdo->prepare("UPDATE research_monitor_watches SET cadence=?,alert_level=?,auto_promote=?,next_check_at=?,updated_at=NOW() WHERE id=?")->execute([$cadence,$alert,$auto?1:0,$next,(int)$watch['id']]);
    return research_monitor_watch_access($pdo,$viewer,$publicId)??[];
}

function research_monitor_set_status(PDO $pdo,array $viewer,string $publicId,string $status): bool {
    $watch=research_monitor_watch_access($pdo,$viewer,$publicId);if(!$watch)return false;$agent=research_monitor_agent($pdo,$viewer,(string)$watch['agent_public_id']);research_monitor_project_can_write($pdo,$viewer,$agent);
    if(!in_array($status,['active','paused','archived'],true))throw new InvalidArgumentException('Invalid watch status.');
    $next=$status==='active'?research_monitor_next_run((string)$watch['cadence']):null;
    $q=$pdo->prepare('UPDATE research_monitor_watches SET status=?,next_check_at=?,updated_at=NOW() WHERE id=?');$q->execute([$status,$next,(int)$watch['id']]);return $q->rowCount()>0;
}

function research_monitor_queue(PDO $pdo,int $watchId,?int $requestedByUserId=null,string $trigger='schedule'): void {
    if(!research_monitor_ready($pdo))return;if(!in_array($trigger,['schedule','manual','source_change','recovery'],true))$trigger='schedule';
    $q=$pdo->prepare("SELECT research_agent_id,project_id,status FROM research_monitor_watches WHERE id=? LIMIT 1");$q->execute([$watchId]);$w=$q->fetch();if(!$w||$w['status']!=='active')return;
    $pdo->prepare("INSERT INTO research_monitor_jobs(watch_id,research_agent_id,project_id,requested_by_user_id,trigger_type,status,available_at)
      VALUES(?,?,?,?,?,'queued',NOW())
      ON DUPLICATE KEY UPDATE requested_by_user_id=COALESCE(VALUES(requested_by_user_id),requested_by_user_id),trigger_type=VALUES(trigger_type),
      rerun_requested=CASE WHEN status='processing' THEN 1 ELSE rerun_requested END,
      status=CASE WHEN status='processing' THEN status ELSE 'queued' END,
      attempts=CASE WHEN status='processing' THEN attempts ELSE 0 END,
      claim_token=CASE WHEN status='processing' THEN claim_token ELSE NULL END,
      lease_expires_at=CASE WHEN status='processing' THEN lease_expires_at ELSE NULL END,
      available_at=CASE WHEN status='processing' THEN available_at ELSE NOW() END,
      started_at=CASE WHEN status='processing' THEN started_at ELSE NULL END,
      completed_at=CASE WHEN status='processing' THEN completed_at ELSE NULL END,
      last_error=CASE WHEN status='processing' THEN last_error ELSE NULL END")
      ->execute([$watchId,(int)$w['research_agent_id'],(int)$w['project_id'],$requestedByUserId,$trigger]);
}

function research_monitor_queue_due(PDO $pdo,int $limit=100): int {
    if(!research_monitor_ready($pdo))return 0;$limit=max(1,min(500,$limit));
    $q=$pdo->query("SELECT id FROM research_monitor_watches WHERE status='active' AND cadence<>'manual' AND (next_check_at IS NULL OR next_check_at<=NOW()) ORDER BY COALESCE(next_check_at,created_at),id LIMIT ".$limit);$count=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){research_monitor_queue($pdo,(int)$id,null,'schedule');$count++;}return $count;
}

function research_monitor_queue_for_source(PDO $pdo,int $sourceId,string $trigger='source_change'): int {
    if(!research_monitor_ready($pdo))return 0;
    $q=$pdo->prepare("SELECT DISTINCT rmw.id,rmw.watch_type,rmw.target,rmw.canonical_url,s.canonical_url source_url,s.domain
      FROM research_monitor_watches rmw
      JOIN project_sources ps ON ps.project_id=rmw.project_id
      JOIN sources s ON s.id=ps.source_id
      WHERE s.id=? AND rmw.status='active'");$q->execute([$sourceId]);$count=0;
    foreach($q->fetchAll() as $w){
        $matches=true;
        if($w['watch_type']==='url')$matches=canonicalize_url((string)$w['source_url'])===canonicalize_url((string)$w['canonical_url']);
        elseif($w['watch_type']==='domain')$matches=mb_strtolower((string)$w['domain'])===mb_strtolower((string)$w['target']);
        if($matches){research_monitor_queue($pdo,(int)$w['id'],null,$trigger);$count++;}
    }return $count;
}

function research_monitor_event(PDO $pdo,array $watch,string $type,string $key,string $summary,string $importance='important',?int $sourceId=null,?int $sourceChangeId=null,?int $claimId=null,array $payload=[]): bool {
    $importance=in_array($importance,['info','important','high'],true)?$importance:'important';$eventKey=hash('sha256',$key);$public=ulid_like();
    $q=$pdo->prepare("INSERT IGNORE INTO research_monitor_events(public_id,watch_id,project_id,event_key,event_type,source_id,source_change_event_id,claim_id,importance,summary,payload_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $q->execute([$public,(int)$watch['id'],(int)$watch['project_id'],$eventKey,$type,$sourceId,$sourceChangeId,$claimId,$importance,mb_substr(trim($summary),0,10000),$payload?json_encode($payload,JSON_UNESCAPED_SLASHES):null]);return $q->rowCount()===1;
}

function research_monitor_score(array $watch,string $url,string $title='',string $excerpt=''): array {
    $type=(string)$watch['watch_type'];$target=mb_strtolower(trim((string)$watch['target']));$hay=mb_strtolower(trim($title.' '.$excerpt.' '.$url));$score=20.0;$reasons=[];
    if($type==='url'&&canonicalize_url($url)===canonicalize_url((string)$watch['canonical_url'])){$score=100;$reasons[]='Exact watched URL.';}
    elseif($type==='domain'){
        $host=mb_strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));$host=preg_replace('/^www\./','',$host);$targetDomain=preg_replace('/^www\./','',$target);
        if($host===$targetDomain||str_ends_with($host,'.'.$targetDomain)){$score=90;$reasons[]='Matches watched domain.';}else{$score=0;$reasons[]='Outside watched domain.';}
    }else{
        $tokens=array_values(array_unique(array_filter(preg_split('/[^\pL\pN]+/u',$target)?:[],fn($x)=>mb_strlen($x)>=3)));$hits=0;
        foreach($tokens as $token)if(mb_stripos($hay,$token)!==false)$hits++;
        if($tokens){$ratio=$hits/count($tokens);$score=25+($ratio*70);$reasons[]=$hits.'/'.count($tokens).' target terms matched.';}
        if(mb_stripos($hay,$target)!==false){$score=max($score,92);$reasons[]='Exact target phrase matched.';}
    }
    return ['score'=>max(0,min(100,round($score,2))),'reason'=>mb_substr(implode(' ',$reasons),0,500)];
}

function research_monitor_candidate_ingest(PDO $pdo,array $watch,array $candidate): ?array {
    $raw=trim((string)($candidate['url']??''));$parts=parse_url($raw);if(!$parts||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||empty($parts['host']))return null;
    $url=canonicalize_url($raw);$hash=hash('sha256',$url);$title=mb_substr(trim((string)($candidate['title']??'')),0,500);$excerpt=mb_substr(trim((string)($candidate['excerpt']??'')),0,10000);$domain=mb_strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
    $published=null;if(!empty($candidate['published_at'])){try{$published=(new DateTimeImmutable((string)$candidate['published_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){}}
    $score=research_monitor_score($watch,$url,$title,$excerpt);$payloadHash=hash('sha256',json_encode([$url,$title,$excerpt,$published],JSON_UNESCAPED_SLASHES));$public=ulid_like();
    $pdo->prepare("INSERT INTO research_monitor_candidates(public_id,watch_id,project_id,canonical_url,canonical_url_hash,title,domain,excerpt,published_at,relevance_score,relevance_reason,payload_hash,status)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'candidate')
      ON DUPLICATE KEY UPDATE title=VALUES(title),domain=VALUES(domain),excerpt=VALUES(excerpt),published_at=VALUES(published_at),relevance_score=VALUES(relevance_score),relevance_reason=VALUES(relevance_reason),payload_hash=VALUES(payload_hash),last_seen_at=NOW()")
      ->execute([$public,(int)$watch['id'],(int)$watch['project_id'],$url,$hash,$title?:null,$domain?:null,$excerpt?:null,$published,$score['score'],$score['reason'],$payloadHash]);
    $q=$pdo->prepare('SELECT * FROM research_monitor_candidates WHERE watch_id=? AND canonical_url_hash=? LIMIT 1');$q->execute([(int)$watch['id'],$hash]);$row=$q->fetch();if(!$row)return null;
    research_monitor_event($pdo,$watch,'candidate_discovered','candidate|'.$row['id'].'|'.$payloadHash,'Discovered candidate source: '.($title?:$url),'info',null,null,null,['candidate_public_id'=>$row['public_id'],'score'=>(float)$row['relevance_score']]);
    if((int)$watch['auto_promote']===1&&(float)$row['relevance_score']>=65&&(string)$row['status']==='candidate')$row=research_monitor_candidate_promote($pdo,$watch,$row);
    return $row;
}

function research_monitor_candidate_promote(PDO $pdo,array $watch,array $candidate): array {
    if((string)$candidate['status']==='ignored')return $candidate;
    $source=ensure_source($pdo,(string)$candidate['canonical_url'],(string)($candidate['title']??''));
    $pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$watch['project_id'],(int)$source['id'],(int)$watch['created_by_user_id']]);
    $pdo->prepare("UPDATE sources SET monitoring_enabled=1,next_check_at=NOW() WHERE id=?")->execute([(int)$source['id']]);
    $pdo->prepare("INSERT INTO source_monitor_jobs(source_id,priority,status,scheduled_at) SELECT ?,3,'queued',NOW() WHERE NOT EXISTS(SELECT 1 FROM source_monitor_jobs WHERE source_id=? AND status IN ('queued','processing'))")->execute([(int)$source['id'],(int)$source['id']]);
    $pdo->prepare("UPDATE research_monitor_candidates SET status='promoted',source_id=?,promoted_at=COALESCE(promoted_at,NOW()) WHERE id=?")->execute([(int)$source['id'],(int)$candidate['id']]);
    research_monitor_event($pdo,$watch,'new_source','source|'.$source['id'].'|'.$candidate['id'],'Added a discovered source to Research: '.((string)($candidate['title']??'')?:$candidate['canonical_url']),'important',(int)$source['id'],null,null,['candidate_public_id'=>$candidate['public_id'],'source_public_id'=>$source['public_id']]);
    $candidate['status']='promoted';$candidate['source_id']=$source['id'];return $candidate;
}

function research_monitor_discovery_command(array $config,array $watch): array {
    $command=trim((string)($config['research_monitoring']['discovery_command']??''));if($command==='')return [];
    $input=tempnam(sys_get_temp_dir(),'annmon-in-');$output=tempnam(sys_get_temp_dir(),'annmon-out-');if(!$input||!$output)throw new RuntimeException('Unable to allocate discovery files.');
    try{
        $payload=['watch_type'=>$watch['watch_type'],'target'=>$watch['target'],'query'=>$watch['query_text']?:$watch['target'],'limit'=>30];
        file_put_contents($input,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        $cmd=str_replace(['{input}','{output}'],[escapeshellarg($input),escapeshellarg($output)],$command);$lines=[];$code=1;exec($cmd,$lines,$code);if($code!==0)throw new RuntimeException('External discovery command failed.');
        $raw=(string)file_get_contents($output);if(strlen($raw)>2*1024*1024)throw new RuntimeException('External discovery result is too large.');$json=json_decode($raw,true);
        $items=is_array($json)&&array_is_list($json)?$json:(is_array($json['results']??null)?$json['results']:[]);
        return array_slice(array_values(array_filter($items,'is_array')),0,30);
    }finally{@unlink($input);@unlink($output);}
}

function research_monitor_domain_sitemap(array $watch): array {
    if((string)$watch['watch_type']!=='domain')return [];$domain=(string)$watch['target'];$url='https://'.$domain.'/sitemap.xml';
    try{$fetch=fetch_public_url($url,1048576);if((int)$fetch['status']<200||(int)$fetch['status']>=400)return [];$body=(string)$fetch['body'];$out=[];
        if(preg_match_all('#<loc>\s*(https?://[^<]+)\s*</loc>#i',$body,$m))foreach(array_slice($m[1],0,30) as $candidate)$out[]=['url'=>html_entity_decode(trim($candidate),ENT_QUOTES|ENT_HTML5,'UTF-8'),'title'=>'','excerpt'=>''];
        return $out;
    }catch(Throwable $e){return [];}
}

function research_monitor_sync_source_changes(PDO $pdo,array $watch): int {
    $since=(string)($watch['last_checked_at']?:$watch['created_at']);$params=[(int)$watch['project_id'],$since];$filter='';
    if($watch['watch_type']==='url'){$filter=' AND s.canonical_url_hash=?';$params[]=hash('sha256',canonicalize_url((string)$watch['canonical_url']));}
    elseif($watch['watch_type']==='domain'){$filter=' AND (s.domain=? OR s.domain=?)';$params[]=(string)$watch['target'];$params[]='www.'.(string)$watch['target'];}
    $q=$pdo->prepare("SELECT sce.*,s.id source_id,s.public_id source_public_id,s.title,s.canonical_url FROM source_change_events sce JOIN sources s ON s.id=sce.source_id JOIN project_sources ps ON ps.source_id=s.id WHERE ps.project_id=? AND sce.created_at>?".$filter." ORDER BY sce.id ASC LIMIT 200");$q->execute($params);$count=0;
    foreach($q->fetchAll() as $e){
        $type=match((string)$e['change_type']){'unavailable'=>'source_unavailable','restored'=>'source_restored',default=>'source_changed'};
        $importance=(int)$e['target_changed']===1?'high':'important';$summary=trim((string)($e['diff_summary']??''));if($summary==='')$summary='Monitored source changed: '.($e['title']?:$e['canonical_url']);
        if(research_monitor_event($pdo,$watch,$type,'source-change|'.$e['id'],$summary,$importance,(int)$e['source_id'],(int)$e['id'],null,['source_public_id'=>$e['source_public_id'],'change_type'=>$e['change_type'],'target_changed'=>(int)$e['target_changed']]))$count++;
    }return $count;
}

function research_monitor_sync_claim(PDO $pdo,array $watch): int {
    if((string)$watch['watch_type']!=='claim')return 0;
    $q=$pdo->prepare("SELECT rc.id,rc.public_id,rc.statement,rc.status,
      SUM(CASE WHEN ce.relationship IN ('supports','primary') THEN 1 ELSE 0 END) supports_count,
      SUM(CASE WHEN ce.relationship='contradicts' THEN 1 ELSE 0 END) contradicts_count,
      COUNT(ce.id) evidence_count
      FROM research_claims rc LEFT JOIN claim_evidence ce ON ce.claim_id=rc.id WHERE rc.project_id=? AND rc.public_id=? GROUP BY rc.id LIMIT 1");
    $q->execute([(int)$watch['project_id'],(string)$watch['target']]);$claim=$q->fetch();if(!$claim)return 0;
    $supports=(int)$claim['supports_count'];$contra=(int)$claim['contradicts_count'];
    $assessment=match(true){
        (string)$claim['status']==='resolved'=>'resolved',
        $contra>0&&$supports>0=>'weakened',
        $contra>0=>'contradicted',
        $supports>0=>'supported',
        default=>'unresolved'
    };
    $hash=hash('sha256',json_encode([$claim['status'],$supports,$contra,(int)$claim['evidence_count']],JSON_UNESCAPED_SLASHES));
    $q=$pdo->prepare('SELECT assessment,evidence_hash FROM research_monitor_claim_states WHERE watch_id=? AND claim_id=?');$q->execute([(int)$watch['id'],(int)$claim['id']]);$previous=$q->fetch();
    $pdo->prepare("INSERT INTO research_monitor_claim_states(watch_id,claim_id,assessment,supports_count,contradicts_count,evidence_hash) VALUES(?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE assessment=VALUES(assessment),supports_count=VALUES(supports_count),contradicts_count=VALUES(contradicts_count),evidence_hash=VALUES(evidence_hash),updated_at=NOW()")
      ->execute([(int)$watch['id'],(int)$claim['id'],$assessment,$supports,$contra,$hash]);
    if($previous&&hash_equals((string)$previous['evidence_hash'],$hash))return 0;
    $eventType=match($assessment){'supported'=>'claim_supported','weakened'=>'claim_weakened','contradicted'=>'claim_contradicted','resolved'=>'claim_resolved',default=>null};if(!$eventType)return 0;
    $importance=in_array($assessment,['weakened','contradicted'],true)?'high':'important';
    $summary='Monitored claim is now '.$assessment.': '.mb_substr((string)$claim['statement'],0,600);
    return research_monitor_event($pdo,$watch,$eventType,'claim-state|'.$claim['id'].'|'.$hash,$summary,$importance,null,null,(int)$claim['id'],['claim_public_id'=>$claim['public_id'],'assessment'=>$assessment,'supports'=>$supports,'contradicts'=>$contra])?1:0;
}

function research_monitor_chat_updates(PDO $pdo,array $watch,int $runId): int {
    $q=$pdo->prepare("SELECT rme.id,rme.event_type,rme.importance,rme.summary FROM research_monitor_events rme WHERE rme.watch_id=? AND rme.notified_at IS NULL ORDER BY FIELD(rme.importance,'high','important','info'),rme.id ASC LIMIT 12");$q->execute([(int)$watch['id']]);$events=$q->fetchAll();if(!$events)return 0;
    if((string)$watch['alert_level']==='important')$events=array_values(array_filter($events,fn($e)=>in_array($e['importance'],['important','high'],true)));if(!$events)return 0;
    $lines=[];foreach(array_slice($events,0,5) as $e)$lines[]='• '.(string)$e['summary'];
    $body="Research monitoring update for “".mb_substr((string)$watch['target'],0,160)."”:
".implode("
",$lines);
    $q=$pdo->prepare('SELECT conversation_id FROM research_agents WHERE id=?');$q->execute([(int)$watch['research_agent_id']]);$conversationId=(int)$q->fetchColumn();if(!$conversationId)return 0;
    $messagePublic=ulid_like();$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,parent_message_id,body) VALUES(?,?,NULL,'agent',NULL,?)")->execute([$messagePublic,$conversationId,$body]);$messageId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$conversationId]);
    $pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,message_id,payload_json) VALUES(?,'agent_message_created',?,?)")->execute([$conversationId,$messageId,json_encode(['source'=>'research_monitoring','monitor_run_id'=>$runId],JSON_UNESCAPED_SLASHES)]);
    $ids=array_column($events,'id');$in=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("UPDATE research_monitor_events SET notified_at=NOW() WHERE id IN ($in)")->execute($ids);return count($events);
}

function research_monitor_run(PDO $pdo,array $config,array $watch,string $trigger): array {
    $runPublic=ulid_like();$inputHash=hash('sha256',json_encode([$watch['watch_type'],$watch['target'],$watch['last_result_hash'],$watch['last_checked_at']],JSON_UNESCAPED_SLASHES));
    $pdo->prepare("INSERT INTO research_monitor_runs(public_id,watch_id,research_agent_id,project_id,trigger_type,status,input_hash) VALUES(?,?,?,?,?,'processing',?)")->execute([$runPublic,(int)$watch['id'],(int)$watch['research_agent_id'],(int)$watch['project_id'],$trigger,$inputHash]);$runId=(int)$pdo->lastInsertId();
    try{
        $candidates=[];
        if((string)$watch['watch_type']==='url')$candidates[]=['url'=>(string)$watch['canonical_url'],'title'=>'','excerpt'=>''];
        elseif((string)$watch['watch_type']==='domain')$candidates=research_monitor_domain_sitemap($watch);
        if(!in_array((string)$watch['watch_type'],['url'],true))$candidates=array_merge($candidates,research_monitor_discovery_command($config,$watch));
        $seen=[];$discovered=0;$promotedBefore=0;$promotedAfter=0;
        foreach($candidates as $candidate){$row=research_monitor_candidate_ingest($pdo,$watch,$candidate);if(!$row)continue;$seen[]=$row['payload_hash'];$discovered++;if((string)$row['status']==='promoted')$promotedAfter++;}
        $events=research_monitor_sync_source_changes($pdo,$watch)+research_monitor_sync_claim($pdo,$watch);
        $outHash=hash('sha256',json_encode([$seen,$events,$promotedAfter],JSON_UNESCAPED_SLASHES));
        $pdo->prepare("UPDATE research_monitor_watches SET last_checked_at=NOW(),next_check_at=?,last_result_hash=?,last_error=NULL,updated_at=NOW() WHERE id=?")->execute([research_monitor_next_run((string)$watch['cadence']),$outHash,(int)$watch['id']]);
        $fresh=$watch;$fresh['last_checked_at']=gmdate('Y-m-d H:i:s');$notifications=research_monitor_chat_updates($pdo,$fresh,$runId);
        if(($events+$promotedAfter)>0&&function_exists('research_autonomy_queue'))research_autonomy_queue($pdo,(int)$watch['research_agent_id'],null,'research_change','Continuous Research monitoring found meaningful new evidence or changes.');
        if($promotedAfter>0&&function_exists('research_retrieval_queue_project'))research_retrieval_queue_project($pdo,(int)$watch['project_id']);
        $summary="Monitoring completed: $discovered candidate(s), $promotedAfter promoted source(s), $events derived change/claim event(s).";
        $pdo->prepare("UPDATE research_monitor_runs SET status='completed',discovered_count=?,promoted_count=?,event_count=?,output_hash=?,summary=?,completed_at=NOW() WHERE id=?")->execute([$discovered,$promotedAfter,$events,$outHash,$summary,$runId]);
        return ['public_id'=>$runPublic,'status'=>'completed','discovered'=>$discovered,'promoted'=>$promotedAfter,'events'=>$events,'notifications'=>$notifications,'summary'=>$summary];
    }catch(Throwable $e){
        $pdo->prepare("UPDATE research_monitor_runs SET status='failed',last_error=?,completed_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,1000),$runId]);
        $pdo->prepare("UPDATE research_monitor_watches SET last_error=?,next_check_at=DATE_ADD(NOW(),INTERVAL 1 HOUR),updated_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,1000),(int)$watch['id']]);
        research_monitor_event($pdo,$watch,'monitor_error','error|'.$runPublic,$e->getMessage(),'info',null,null,null,[]);
        throw $e;
    }
}

function research_monitor_candidate_access(PDO $pdo,array $viewer,string $publicId): ?array {
    $q=$pdo->prepare("SELECT rmc.*,rmw.public_id watch_public_id FROM research_monitor_candidates rmc JOIN research_monitor_watches rmw ON rmw.id=rmc.watch_id WHERE rmc.public_id=? LIMIT 1");$q->execute([trim($publicId)]);$row=$q->fetch();if(!$row)return null;
    $watch=research_monitor_watch_access($pdo,$viewer,(string)$row['watch_public_id']);if(!$watch)return null;$row['watch']=$watch;return $row;
}

function research_monitor_candidate_ignore(PDO $pdo,array $viewer,string $publicId): bool {
    $candidate=research_monitor_candidate_access($pdo,$viewer,$publicId);if(!$candidate)return false;$watch=$candidate['watch'];$agent=research_monitor_agent($pdo,$viewer,(string)$watch['agent_public_id']);research_monitor_project_can_write($pdo,$viewer,$agent);
    if((string)$candidate['status']==='promoted')throw new RuntimeException('Promoted sources remain part of Research; remove them from the project explicitly if needed.');
    $q=$pdo->prepare("UPDATE research_monitor_candidates SET status='ignored',last_seen_at=NOW() WHERE id=?");$q->execute([(int)$candidate['id']]);return $q->rowCount()>0;
}

function research_monitor_candidate_promote_for_user(PDO $pdo,array $viewer,string $publicId): array {
    $candidate=research_monitor_candidate_access($pdo,$viewer,$publicId);if(!$candidate)throw new RuntimeException('Discovery candidate not found.');$watch=$candidate['watch'];$agent=research_monitor_agent($pdo,$viewer,(string)$watch['agent_public_id']);research_monitor_project_can_write($pdo,$viewer,$agent);
    $row=research_monitor_candidate_promote($pdo,$watch,$candidate);if(function_exists('research_autonomy_queue'))research_autonomy_queue($pdo,(int)$watch['research_agent_id'],(int)$viewer['id'],'research_change','A monitored discovery candidate was promoted into Research.');
    return $row;
}

function research_monitor_summary(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_monitor_agent($pdo,$viewer,$agentPublic);$projectId=(int)$agent['project_id'];
    $q=$pdo->prepare("SELECT status,COUNT(*) total FROM research_monitor_watches WHERE research_agent_id=? AND status<>'archived' GROUP BY status");$q->execute([(int)$agent['id']]);$watchCounts=[];foreach($q->fetchAll() as $row)$watchCounts[$row['status']]=(int)$row['total'];
    $q=$pdo->prepare("SELECT status,COUNT(*) total FROM research_monitor_candidates WHERE project_id=? GROUP BY status");$q->execute([$projectId]);$candidateCounts=[];foreach($q->fetchAll() as $row)$candidateCounts[$row['status']]=(int)$row['total'];
    $q=$pdo->prepare("SELECT importance,COUNT(*) total FROM research_monitor_events WHERE project_id=? AND occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY importance");$q->execute([$projectId]);$eventCounts=[];foreach($q->fetchAll() as $row)$eventCounts[$row['importance']]=(int)$row['total'];
    $q=$pdo->prepare("SELECT MAX(last_checked_at) FROM research_monitor_watches WHERE research_agent_id=?");$q->execute([(int)$agent['id']);$last=$q->fetchColumn();
    return ['agent_public_id'=>$agentPublic,'watches'=>$watchCounts,'candidates'=>$candidateCounts,'events_30d'=>$eventCounts,'last_checked_at'=>$last?:null];
}

function research_monitor_candidates(PDO $pdo,array $viewer,string $watchPublic,int $limit=100): array {
    $watch=research_monitor_watch_access($pdo,$viewer,$watchPublic);if(!$watch)return [];$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT * FROM research_monitor_candidates WHERE watch_id=? ORDER BY status='candidate' DESC,relevance_score DESC,last_seen_at DESC LIMIT ".$limit);$q->execute([(int)$watch['id']]);return $q->fetchAll()?:[];
}

function research_monitor_events(PDO $pdo,array $viewer,string $agentPublic,int $limit=100): array {
    $agent=research_monitor_agent($pdo,$viewer,$agentPublic);$limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT rme.*,rmw.public_id watch_public_id,rmw.watch_type,rmw.target,s.public_id source_public_id,rc.public_id claim_public_id
      FROM research_monitor_events rme JOIN research_monitor_watches rmw ON rmw.id=rme.watch_id
      LEFT JOIN sources s ON s.id=rme.source_id LEFT JOIN research_claims rc ON rc.id=rme.claim_id
      WHERE rme.project_id=? ORDER BY rme.occurred_at DESC,rme.id DESC LIMIT ".$limit);$q->execute([(int)$agent['project_id']]);return $q->fetchAll()?:[];
}
