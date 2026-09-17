<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO($dsn,$dbUser,$dbPass,$opts);$pdo2=new PDO($dsn,$dbUser,$dbPass,$opts);
require_once $root.'/app/storage.php';
require_once $root.'/app/jobs.php';
require_once $root.'/app/concurrency.php';
require_once $root.'/app/functions.php';
require_once $root.'/app/access.php';
require_once $root.'/app/oauth.php';
require_once $root.'/app/extension-auth.php';
require_once $root.'/app/ai.php';
require_once $root.'/app/ai-access.php';
require_once $root.'/app/evidence-access.php';
require_once $root.'/app/migrations.php';

function ok(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function throws(callable $fn,string $message): void {$thrown=false;try{$fn();}catch(Throwable $e){$thrown=true;}if(!$thrown)throw new RuntimeException($message);}
$run='ci'.substr(bin2hex(random_bytes(8)),0,12);
function pub(string $prefix,string $run): string {return $prefix.'-'.$run.'-'.substr(bin2hex(random_bytes(4)),0,8);}
function make_user(PDO $pdo,string $run,string $name,string $role='user',string $plan='free'): array {
    $public=pub('u',$run);$username=substr($name.'_'.$run,0,48);$email=$username.'@example.test';
    $q=$pdo->prepare('INSERT INTO users(public_id,username,display_name,email,email_verified_at,role,plan_tier,status,live_presence_mode) VALUES(?,?,?,?,NOW(),?,?,\'active\',\'cloaked\')');
    $q->execute([$public,$username,$name,$email,$role,$plan]);$id=(int)$pdo->lastInsertId();
    return ['id'=>$id,'public_id'=>$public,'username'=>$username,'display_name'=>$name,'email'=>$email,'role'=>$role,'live_presence_mode'=>'cloaked'];
}
function make_source(PDO $pdo,string $run,string $suffix): array {
    $public=pub('s',$run);$url='https://example.com/'.$run.'/'.$suffix;$hash=hash('sha256',$url);
    $q=$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'webpage',?,?,?,'CI source','current')");$q->execute([$public,$url,$hash,'example.com']);$sourceId=(int)$pdo->lastInsertId();
    $snapshot='private://ci/'.$run.'/'.$suffix.'.png';$q=$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,screenshot_path) VALUES(?,1,?,?,?, ?,?)');$text='Initial evidence '.$suffix;$q->execute([$sourceId,$url,'CI source',$text,hash('sha256',$text),$snapshot]);$versionId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$sourceId]);
    return ['id'=>$sourceId,'public_id'=>$public,'url'=>$url,'version_id'=>$versionId,'snapshot'=>$snapshot];
}
function make_annotation(PDO $pdo,string $run,array $user,array $source,string $visibility,?int $teamId=null,string $suffix='a'): array {
    $capturePublic=pub('c',$run);$target='private://ci/'.$run.'/'.$suffix.'-target.png';$context='private://ci/'.$run.'/'.$suffix.'-context.png';
    $q=$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text,screenshot_target_path,screenshot_context_path) VALUES(?,?,?,?, 'text',?,?,?)");$q->execute([$capturePublic,$source['id'],$source['version_id'],$user['id'],'Evidence '.$suffix,$target,$context]);$captureId=(int)$pdo->lastInsertId();
    $public=pub('a',$run);$audio='private://ci/'.$run.'/'.$suffix.'.webm';$q=$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,audio_commentary_path,visibility,team_id,status) VALUES(?,?,?,?,?,?,?,?,?,'published')");$q->execute([$public,$user['id'],$source['id'],$source['version_id'],$captureId,'Comment '.$suffix,$audio,$visibility,$teamId]);
    return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'target'=>$target,'context'=>$context,'audio'=>$audio];
}

// Identity, team and research fixtures.
$owner=make_user($pdo,$run,'owner');$researcher=make_user($pdo,$run,'researcher');$viewer=make_user($pdo,$run,'viewer');$outsider=make_user($pdo,$run,'outsider');$admin=make_user($pdo,$run,'admin','admin');$pro=make_user($pdo,$run,'pro','user','pro');$free=make_user($pdo,$run,'free');
$teamPublic=pub('t',$run);$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'CI team']);$teamId=(int)$pdo->lastInsertId();
$member=$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)');foreach([[$owner['id'],'owner'],[$researcher['id'],'researcher'],[$viewer['id'],'viewer']] as [$uid,$role])$member->execute([$teamId,$uid,$role]);
$projectPublic=pub('p',$run);$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description) VALUES(?,?,?,?,?)')->execute([$projectPublic,$owner['id'],$teamId,'CI project','Runtime authorization project']);$projectId=(int)$pdo->lastInsertId();

$shared=make_source($pdo,$run,'shared');$publicAnn=make_annotation($pdo,$run,$owner,$shared,'public',null,'public');$teamAnn=make_annotation($pdo,$run,$owner,$shared,'team',$teamId,'team');$privatePersonal=make_annotation($pdo,$run,$owner,$shared,'private',null,'private-personal');$privateProject=make_annotation($pdo,$run,$owner,$shared,'private',null,'private-project');
$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$privateProject['id'],$owner['id']]);

// Annotation visibility and project-role enforcement.
ok(annotation_access($pdo,$publicAnn['public_id'],null)!==null,'Public annotation must be anonymous-readable.');
ok(annotation_access($pdo,$privatePersonal['public_id'],null)===null,'Private annotation leaked anonymously.');
ok(annotation_access($pdo,$privatePersonal['public_id'],$outsider)===null,'Private annotation leaked to outsider.');
ok(annotation_access($pdo,$privatePersonal['public_id'],$owner)!==null,'Owner lost access to private annotation.');
ok(annotation_access($pdo,$privatePersonal['public_id'],$admin)!==null,'Admin lost moderation access to private annotation.');
ok(annotation_access($pdo,$teamAnn['public_id'],$researcher)!==null,'Researcher cannot read team annotation.');
ok(annotation_access($pdo,$teamAnn['public_id'],$viewer)!==null,'Viewer cannot read team annotation.');
ok(annotation_access($pdo,$teamAnn['public_id'],$outsider)===null,'Team annotation leaked to outsider.');
ok(annotation_access($pdo,$privateProject['public_id'],$viewer)!==null,'Project viewer cannot read project evidence.');
ok(annotation_access($pdo,$privateProject['public_id'],$outsider)===null,'Project evidence leaked outside project.');
$paOwner=project_access($pdo,$owner['id'],$projectPublic);$paResearcher=project_access($pdo,$researcher['id'],$projectPublic);$paViewer=project_access($pdo,$viewer['id'],$projectPublic);
ok(($paOwner['access_role']??'')==='owner'&&project_can_write($paOwner),'Owner project write access failed.');
ok(($paResearcher['access_role']??'')==='researcher'&&project_can_write($paResearcher),'Researcher project write access failed.');
ok(($paViewer['access_role']??'')==='viewer'&&!project_can_write($paViewer),'Viewer unexpectedly has project write access.');

// Evidence gateway authorization uses the same access boundary.
ok(evidence_annotation_asset($pdo,$privatePersonal['public_id'],$owner,'target')===$privatePersonal['target'],'Owner evidence lookup failed.');
ok(evidence_annotation_asset($pdo,$privatePersonal['public_id'],$outsider,'target')===null,'Private evidence path leaked to outsider.');
ok(evidence_annotation_asset($pdo,$privateProject['public_id'],$viewer,'audio')===$privateProject['audio'],'Project viewer evidence lookup failed.');
ok(evidence_annotation_asset($pdo,$publicAnn['public_id'],null,'context')===$publicAnn['context'],'Public evidence lookup failed.');
$privateSource=make_source($pdo,$run,'private-source');$privateSourceAnn=make_annotation($pdo,$run,$owner,$privateSource,'private',null,'private-source');
ok(source_access($pdo,$privateSource['public_id'],$outsider)===null,'Private-only Source leaked before project access.');
ok(evidence_source_snapshot($pdo,$privateSource['public_id'],$privateSource['version_id'],$outsider)===null,'Private Source snapshot leaked.');
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$privateSource['id'],$owner['id']]);
ok(evidence_source_snapshot($pdo,$privateSource['public_id'],$privateSource['version_id'],$viewer)===$privateSource['snapshot'],'Authorized project Source snapshot failed.');

// Presence identity rules: cloaked never reveals; Team Only reveals only to shared-team members.
ok(presence_identity_visible($pdo,$outsider['id'],$owner['id'],'visible'),'Visible presence should be visible.');
ok(presence_identity_visible($pdo,$researcher['id'],$owner['id'],'team_only'),'Team Only presence should reveal within shared team.');
ok(!presence_identity_visible($pdo,$outsider['id'],$owner['id'],'team_only'),'Team Only presence leaked outside shared team.');
ok(!presence_identity_visible($pdo,$researcher['id'],$owner['id'],'cloaked'),'Cloaked presence revealed identity.');
ok(!presence_identity_visible($pdo,$researcher['id'],$owner['id'],'off'),'Off presence revealed identity.');

// Bearer session expiry/revocation must be enforced by the shared current_user() path.
$insertSession=$pdo->prepare('INSERT INTO extension_sessions(user_id,token_hash,device_name,expires_at,revoked_at) VALUES(?,?,?, ?,?)');
$activeToken=bin2hex(random_bytes(16));$expiredToken=bin2hex(random_bytes(16));$revokedToken=bin2hex(random_bytes(16));
$insertSession->execute([$owner['id'],hash('sha256',$activeToken),'active',date('Y-m-d H:i:s',time()+3600),null]);
$insertSession->execute([$owner['id'],hash('sha256',$expiredToken),'expired',date('Y-m-d H:i:s',time()-3600),null]);
$insertSession->execute([$owner['id'],hash('sha256',$revokedToken),'revoked',date('Y-m-d H:i:s',time()+3600),date('Y-m-d H:i:s')]);
$_SESSION=[];$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$activeToken;ok((int)(current_user($pdo)['id']??0)===$owner['id'],'Active extension session did not authenticate.');
$_SESSION=[];$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$expiredToken;ok(current_user($pdo)===null,'Expired extension session still authenticated.');
$_SESSION=[];$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$revokedToken;ok(current_user($pdo)===null,'Revoked extension session still authenticated.');unset($_SERVER['HTTP_AUTHORIZATION']);
$configFixture=['extension'=>['allowed_ids'=>['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']]];ok(extension_redirect_allowed('https://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.chromiumapp.org/annotated',$configFixture),'Allowed extension callback rejected.');ok(!extension_redirect_allowed('https://bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.chromiumapp.org/annotated',$configFixture),'Unlisted extension callback accepted.');

// OAuth linking cannot steal an existing provider identity or verified email.
$pdo->prepare('INSERT INTO user_identities(user_id,provider,provider_user_id,provider_email) VALUES(?,?,?,?)')->execute([$owner['id'],'google','google-'.$run,$owner['email']]);
throws(fn()=>oauth_finish_login($pdo,'google','google-'.$run,$outsider['email'],'Outsider','outsider',true,$outsider['id']),'OAuth identity collision did not fail closed.');
throws(fn()=>oauth_finish_login($pdo,'x','x-collision-'.$run,$outsider['email'],'Owner','owner',true,$owner['id']),'Verified-email OAuth link collision did not fail closed.');
$linked=oauth_finish_login($pdo,'x','x-owner-'.$run,$owner['email'],'Owner','owner',true,$owner['id']);ok($linked===$owner['id'],'Valid OAuth account linking failed.');

// Interactive AI model entitlement is explicit for Free, Pro, and Admin users.
$providerPublic=pub('provider',$run);$pdo->prepare("INSERT INTO ai_providers(public_id,label,provider_type,enabled) VALUES(?,?,'openai_compatible',1)")->execute([$providerPublic,'CI provider']);$providerId=(int)$pdo->lastInsertId();
$model=$pdo->prepare('INSERT INTO ai_models(public_id,provider_id,model_name,display_name,enabled,admin_enabled,pro_enabled) VALUES(?,?,?,?,1,?,?)');
$model->execute([pub('m',$run),$providerId,'admin-only-'.$run,'Admin only',1,0]);$adminOnly=(int)$pdo->lastInsertId();
$model->execute([pub('m',$run),$providerId,'pro-'.$run,'Pro model',1,1]);$proModel=(int)$pdo->lastInsertId();
$model->execute([pub('m',$run),$providerId,'pro-only-'.$run,'Pro only',0,1]);$proOnly=(int)$pdo->lastInsertId();
throws(fn()=>ai_interactive_model_record($pdo,$free,$proModel),'Free user received an interactive Pro AI model.');
ok((int)ai_interactive_model_record($pdo,$pro,$proModel)['id']===$proModel,'Pro user could not access Pro-enabled model.');
throws(fn()=>ai_interactive_model_record($pdo,$pro,$adminOnly),'Pro user received admin-only AI model.');
ok((int)ai_interactive_model_record($pdo,$admin,$adminOnly)['id']===$adminOnly,'Admin could not access admin-enabled model.');
throws(fn()=>ai_interactive_model_record($pdo,$admin,$proOnly),'Admin accessed model with admin_enabled disabled.');

// Worker leases recover abandoned work while stale workers cannot complete newer claims.
$jobPublic=pub('job',$run);$pdo->prepare("INSERT INTO ai_jobs(public_id,task_type,object_type,object_public_id,status) VALUES(?,'ci_test','ci','object','queued')")->execute([$jobPublic]);$jobId=(int)$pdo->lastInsertId();
$select="SELECT * FROM ai_jobs WHERE status='queued' AND available_at<=NOW() ORDER BY id LIMIT 1";$claim1=job_claim($pdo,'ai_jobs',$select,[],60);ok($claim1&&$claim1['id']===$jobId,'Initial worker lease claim failed.');
$pdo->prepare('UPDATE ai_jobs SET lease_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?')->execute([$jobId]);$claim2=job_claim($pdo,'ai_jobs',$select,[],60);ok($claim2&&$claim2['id']===$jobId&&$claim2['claim_token']!==$claim1['claim_token'],'Expired lease was not safely reclaimed.');ok((int)$claim2['attempts']===2,'Reclaimed job attempt count is incorrect.');
throws(fn()=>job_claim_complete($pdo,'ai_jobs',$jobId,$claim1['claim_token']),'Stale worker completed a reclaimed job.');job_claim_complete($pdo,'ai_jobs',$jobId,$claim2['claim_token']);$q=$pdo->prepare('SELECT status FROM ai_jobs WHERE id=?');$q->execute([$jobId]);ok($q->fetchColumn()==='done','Current worker could not complete reclaimed job.');

// Source Version allocation is serialized by the canonical Source row lock.
$lockedSource=make_source($pdo,$run,'locking');$pdo2->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo->beginTransaction();lock_source_row($pdo,$lockedSource['id']);$pdo2->beginTransaction();$blocked=false;try{lock_source_row($pdo2,$lockedSource['id']);}catch(Throwable $e){$blocked=true;}if($pdo2->inTransaction())$pdo2->rollBack();ok($blocked,'Concurrent Source row lock was not serialized by MariaDB.');
$next=next_source_version_number($pdo,$lockedSource['id']);ok($next===2,'Locked Source Version allocation returned wrong next version.');$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,?,?,?,?,?)')->execute([$lockedSource['id'],$next,$lockedSource['url'],'CI source','Second version',hash('sha256','Second version')]);$v2=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$v2,$lockedSource['id']]);$pdo->commit();
$pdo2->beginTransaction();lock_source_row($pdo2,$lockedSource['id']);ok(next_source_version_number($pdo2,$lockedSource['id'])===3,'Next Source Version did not advance after serialized commit.');$pdo2->rollBack();

// MariaDB partial-DDL failure bookkeeping and checksum pinning are real, not transactional assumptions.
$tmp=sys_get_temp_dir().'/annotated-'.$run;mkdir($tmp,0700,true);$version='20991231_999_ci_partial';$probe='ci_migration_probe_'.preg_replace('/[^a-z0-9_]/i','',$run);$file=$tmp.'/'.$version.'.sql';file_put_contents($file,"CREATE TABLE $probe (id INT PRIMARY KEY);\nTHIS IS NOT VALID SQL;\n");$failed=false;try{migration_apply_pending($pdo,$tmp,1);}catch(Throwable $e){$failed=true;}ok($failed,'Partial-DDL migration unexpectedly succeeded.');
$q=$pdo->prepare('SELECT status,statement_index,statement_count FROM schema_migration_runs WHERE version=?');$q->execute([$version]);$mr=$q->fetch();ok(($mr['status']??'')==='failed'&&(int)($mr['statement_index']??0)===2,'Failed migration bookkeeping did not record statement position.');$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$probe]);ok((int)$q->fetchColumn()===1,'Test did not demonstrate MariaDB partial DDL persistence.');
file_put_contents($file,"CREATE TABLE $probe (id INT PRIMARY KEY);\n");throws(fn()=>migration_inventory($pdo,$tmp),'Changed previously-attempted migration was not checksum-pinned.');$pdo->exec("DROP TABLE `$probe`");$pdo->prepare('DELETE FROM schema_migration_runs WHERE version=?')->execute([$version]);@unlink($file);@rmdir($tmp);

echo "DB-backed security integration suite passed.\n";
