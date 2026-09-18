<?php
declare(strict_types=1);

require_once __DIR__.'/migrations.php';

const ANNOTATED_RELEASE = 'V1.1 RC1';
const ANNOTATED_RELEASE_VERSION = '1.1.0-rc1';

function onboarding_ensure(PDO $pdo,int $userId): void {
    try{$pdo->prepare('INSERT IGNORE INTO user_onboarding(user_id) VALUES(?)')->execute([$userId]);}catch(PDOException $e){}
}
function onboarding_mark_seen(PDO $pdo,int $userId): void {
    onboarding_ensure($pdo,$userId);$pdo->prepare('UPDATE user_onboarding SET welcome_seen_at=COALESCE(welcome_seen_at,NOW()),dismissed_at=NULL WHERE user_id=?')->execute([$userId]);
}
function onboarding_dismiss(PDO $pdo,int $userId): void {
    onboarding_ensure($pdo,$userId);$pdo->prepare('UPDATE user_onboarding SET dismissed_at=NOW() WHERE user_id=? AND completed_at IS NULL')->execute([$userId]);
}
function onboarding_status(PDO $pdo,array $user,bool $persistCompletion=true): array {
    $uid=(int)$user['id'];onboarding_ensure($pdo,$uid);
    $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$uid]);$hasPassword=(bool)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COUNT(*) FROM user_identities WHERE user_id=?');$q->execute([$uid]);$identityCount=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COUNT(*) FROM extension_sessions WHERE user_id=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>NOW())');$q->execute([$uid]);$extensionCount=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM annotations WHERE user_id=? AND status IN ('published','restricted')");$q->execute([$uid]);$annotationCount=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM follows WHERE follower_user_id=?)+(SELECT COUNT(*) FROM source_watches WHERE user_id=?)');$q->execute([$uid,$uid]);$followCount=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COUNT(DISTINCT rp.id) FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id IS NOT NULL');$q->execute([$uid,$uid]);$projectCount=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT * FROM user_onboarding WHERE user_id=?');$q->execute([$uid]);$row=$q->fetch()?:[];
    $steps=[
        'account'=>['complete'=>$hasPassword||$identityCount>0,'label'=>'Secure your account','detail'=>$hasPassword?'Native password ready':($identityCount?'Connected login ready':'Add a password or OAuth login method'),'url'=>'/connected-accounts.php'],
        'extension'=>['complete'=>$extensionCount>0,'label'=>'Connect the Chrome sidebar','detail'=>$extensionCount?($extensionCount.' active extension session'.($extensionCount===1?'':'s')):'Authorize the Annotated Chrome extension','url'=>'/onboarding.php#extension'],
        'annotation'=>['complete'=>$annotationCount>0,'label'=>'Publish your first annotation','detail'=>$annotationCount?($annotationCount.' annotation'.($annotationCount===1?'':'s').' published'):'Open a webpage, highlight something, and publish from the sidebar','url'=>'/onboarding.php#annotation'],
        'follow'=>['complete'=>$followCount>0,'label'=>'Follow a researcher or source','detail'=>$followCount?($followCount.' follow/watch relationship'.($followCount===1?'':'s')):'Follow from This Page or watch a Source','url'=>'/explore.php'],
        'research'=>['complete'=>$projectCount>0,'label'=>'Start or join Research','detail'=>$projectCount?($projectCount.' accessible Research project'.($projectCount===1?'':'s')):'Create a Research project and add evidence','url'=>'/research.php'],
    ];
    $complete=true;foreach($steps as $s)if(!$s['complete']){$complete=false;break;}
    if($complete&&empty($row['completed_at'])&&$persistCompletion){$pdo->prepare('UPDATE user_onboarding SET completed_at=NOW(),dismissed_at=NULL WHERE user_id=?')->execute([$uid]);$row['completed_at']=gmdate('Y-m-d H:i:s');}
    return ['steps'=>$steps,'complete'=>$complete,'completed_count'=>count(array_filter($steps,fn($s)=>$s['complete'])),'total_count'=>count($steps),'row'=>$row];
}
function onboarding_should_redirect(PDO $pdo,int $userId): bool {
    try{$q=$pdo->prepare('SELECT welcome_seen_at,dismissed_at,completed_at FROM user_onboarding WHERE user_id=?');$q->execute([$userId]);$row=$q->fetch();return $row&&!$row['welcome_seen_at']&&!$row['dismissed_at']&&!$row['completed_at'];}catch(PDOException $e){return false;}
}
function onboarding_post_login_destination(PDO $pdo,int $userId): string {
    $dest=post_login_destination();if($dest==='/'&&onboarding_should_redirect($pdo,$userId))return '/onboarding.php';return $dest;
}

function release_worker_heartbeat(PDO $pdo,string $worker,string $status='idle',string $message='',int $processed=0): void {
    if(!preg_match('/^[a-z0-9_\-]{2,64}$/',$worker))throw new InvalidArgumentException('Invalid worker name.');
    if(!in_array($status,['starting','idle','success','failure'],true))$status='idle';
    $message=mb_substr(trim($message),0,1000);$processed=max(0,$processed);$failed=$status==='failure'?1:0;
    try{$pdo->prepare("INSERT INTO worker_heartbeats(worker_name,last_started_at,last_seen_at,last_success_at,last_failure_at,last_status,processed_total,failed_total,last_message)
        VALUES(?,IF(?='starting',NOW(),NULL),NOW(),IF(?='success',NOW(),NULL),IF(?='failure',NOW(),NULL),?,?,?,?)
        ON DUPLICATE KEY UPDATE
          last_started_at=IF(VALUES(last_status)='starting',NOW(),last_started_at),
          last_seen_at=NOW(),
          last_success_at=IF(VALUES(last_status)='success',NOW(),last_success_at),
          last_failure_at=IF(VALUES(last_status)='failure',NOW(),last_failure_at),
          last_status=VALUES(last_status),
          processed_total=processed_total+VALUES(processed_total),
          failed_total=failed_total+VALUES(failed_total),
          last_message=VALUES(last_message)")->execute([$worker,$status,$status,$status,$status,$processed,$failed,$message?:null]);}catch(PDOException $e){}
}
function release_worker_health(PDO $pdo): array {
    $expected=['media','transcription','source_monitor','ai','saved_search'];$rows=[];try{$q=$pdo->query('SELECT * FROM worker_heartbeats');foreach($q->fetchAll() as $r)$rows[$r['worker_name']]=$r;}catch(PDOException $e){}
    $out=[];$now=time();foreach($expected as $name){$r=$rows[$name]??null;$seen=$r&&$r['last_seen_at']?strtotime((string)$r['last_seen_at']):0;$age=$seen?max(0,$now-$seen):null;$status=!$r?'never':(($age!==null&&$age>3600)?'stale':(string)$r['last_status']);$out[$name]=['name'=>$name,'status'=>$status,'age_seconds'=>$age,'last_seen_at'=>$r['last_seen_at']??null,'last_success_at'=>$r['last_success_at']??null,'last_failure_at'=>$r['last_failure_at']??null,'processed_total'=>(int)($r['processed_total']??0),'failed_total'=>(int)($r['failed_total']??0),'last_message'=>$r['last_message']??null];}return $out;
}
function release_queue_health(PDO $pdo): array {
    $tables=['media'=>'media_jobs','transcription'=>'transcription_jobs','source_monitor'=>'source_monitor_jobs','ai'=>'ai_jobs'];$out=[];
    foreach($tables as $name=>$table){try{$q=$pdo->query("SELECT status,COUNT(*) c FROM $table GROUP BY status");$counts=[];foreach($q->fetchAll() as $r)$counts[$r['status']]=(int)$r['c'];$out[$name]=['queued'=>$counts['queued']??0,'processing'=>$counts['processing']??0,'failed'=>$counts['failed']??0,'blocked'=>$counts['blocked']??0,'done'=>$counts['done']??0];}catch(PDOException $e){$out[$name]=['error'=>'unavailable'];}}
    return $out;
}
function release_command_available(string $command): bool {
    if(!function_exists('exec'))return false;$out=[];$code=1;@exec('command -v '.escapeshellarg($command).' 2>/dev/null',$out,$code);return $code===0&&!empty($out);
}
function release_is_placeholder(string $value): bool {
    $v=strtolower(trim($value));return $v===''||str_contains($v,'replace-with')||str_contains($v,'change-me')||str_contains($v,'example');
}
function release_environment_checks(PDO $pdo,array $config): array {
    $checks=[];$add=function(string $key,string $label,string $status,string $detail,bool $critical=true)use(&$checks){$checks[$key]=['key'=>$key,'label'=>$label,'status'=>$status,'detail'=>$detail,'critical'=>$critical];};
    $add('php','PHP '.PHP_VERSION,version_compare(PHP_VERSION,'8.1.0','>=')?'pass':'fail',version_compare(PHP_VERSION,'8.1.0','>=')?'PHP 8.1+ available':'PHP 8.1+ required');
    $base=(string)($config['app']['base_url']??'');$parts=parse_url($base);$https=$parts&&strtolower((string)($parts['scheme']??''))==='https'&&!empty($parts['host']);$add('base_url','HTTPS base URL',$https?'pass':'fail',$https?$base:'Production app.base_url must be an HTTPS URL.');
    try{$pdo->query('SELECT 1')->fetchColumn();$add('database','Database connection','pass','MariaDB connection is healthy.');}catch(Throwable $e){$add('database','Database connection','fail','Database query failed.');}
    try{[$pending]=migration_inventory($pdo,dirname(__DIR__).'/database/migrations');$add('migrations','Database migrations',count($pending)===0?'pass':'fail',count($pending)===0?'All migrations applied.':count($pending).' migration(s) pending.');}catch(Throwable $e){$add('migrations','Database migrations','fail','Migration inventory failed: '.mb_substr($e->getMessage(),0,180));}
    $root=(string)($config['storage']['private_root']??'');$doc=realpath((string)($_SERVER['DOCUMENT_ROOT']??''));$real=$root!==''?realpath($root):false;$inside=$doc&&$real&&str_starts_with(rtrim($real,'/').'/',rtrim($doc,'/').'/');$storageOk=$root!==''&&is_dir($root)&&is_writable($root)&&!$inside;$add('storage','Private evidence storage',$storageOk?'pass':'fail',$storageOk?'Writable outside public web root.':'Configure a writable storage.private_root outside the public web root.');
    $enc=(string)($config['app']['encryption_key']??'');$add('encryption','Encryption key',strlen($enc)>=32&&!release_is_placeholder($enc)?'pass':'fail','Use a unique 32+ character app.encryption_key.');
    $allowed=$config['extension']['allowed_ids']??[];$allowed=is_array($allowed)?array_values(array_filter($allowed)):[];$add('extension_ids','Chrome extension allowlist',count($allowed)>0?'pass':'fail',count($allowed).' allowed extension ID(s) configured.');
    foreach(['google','x'] as $provider){$p=$config['oauth'][$provider]??[];$ok=!empty($p['client_id'])&&!empty($p['client_secret'])&&!empty($p['redirect_uri']);$add('oauth_'.$provider,ucfirst($provider).' OAuth',$ok?'pass':'warn',$ok?'Configured.':'Not configured; users cannot use this provider.',false);}
    $ffmpeg=release_command_available('ffmpeg');$ffprobe=release_command_available('ffprobe');$add('media_tools','Media tools',$ffmpeg&&$ffprobe?'pass':'fail',$ffmpeg&&$ffprobe?'ffmpeg and ffprobe available.':'ffmpeg and ffprobe are required for media derivatives.');
    $trans=trim((string)($config['transcription']['command']??''));$add('transcription','Transcription command',$trans!==''?'pass':'warn',$trans!==''?'Configured.':'Not configured; audio transcripts will remain blocked.',false);
    $bootstrap=(string)($config['app']['bootstrap_key']??'');$users=0;try{$users=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();}catch(Throwable $e){}$bootstrapSafe=$users===0?strlen($bootstrap)>=24:($bootstrap===''||!release_is_placeholder($bootstrap));$add('bootstrap','Bootstrap key',$bootstrapSafe?'pass':'warn',$users===0?'Strong bootstrap key required until first admin exists.':'Rotate or remove the first-admin bootstrap secret after setup.',false);
    $workers=release_worker_health($pdo);foreach($workers as $name=>$w){$status=$w['status']==='never'?'warn':($w['status']==='stale'||$w['status']==='failure'?'warn':'pass');$add('worker_'.$name,ucwords(str_replace('_',' ',$name)).' worker',$status,$w['status']==='never'?'No heartbeat recorded yet.':('Last seen '.($w['last_seen_at']?:'unknown').' · '.$w['status']),false);}
    $criticalFail=false;$warnings=0;foreach($checks as $c){if($c['status']==='fail'&&$c['critical'])$criticalFail=true;if($c['status']==='warn')$warnings++;}
    return ['release'=>ANNOTATED_RELEASE,'version'=>ANNOTATED_RELEASE_VERSION,'ready'=>!$criticalFail,'warnings'=>$warnings,'checks'=>$checks,'workers'=>$workers,'queues'=>release_queue_health($pdo)];
}
