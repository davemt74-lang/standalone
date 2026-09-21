<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=getenv('DB_DSN')?:'';$dbUser=getenv('DB_USER')?:'root';$dbPass=getenv('DB_PASS')?:'';
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','functions','access','conversations','research-workspace','research-knowledge','workspace-context'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p34(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$run='p34'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array {
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ContextOwner');$member=$makeUser('ContextMember');$outsider=$makeUser('ContextOutsider');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Context Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);

$projectPublic=$pub('project');$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active')")
  ->execute([$projectPublic,$owner['id'],$teamId,'Context Research','Phase 34 context fixture']);$projectId=(int)$pdo->lastInsertId();

$url='https://context-'.$run.'.example.test/source';$sourcePublic=$pub('source');
$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status,monitoring_enabled,moderation_status) VALUES(?,'article',?,?,?,'Context Source','current',1,'visible')")
  ->execute([$sourcePublic,$url,hash('sha256',$url),'context.example.test']);$sourceId=(int)$pdo->lastInsertId();
$text='Context source '.$run;$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash,captured_at) VALUES(?,1,?,'Context Source',?,?,?,NOW())")
  ->execute([$sourceId,$url,$text,hash('sha256',$text),hash('sha256','target-'.$run)]);$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$sourceId]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$sourceId,$owner['id']]);

$makeAnnotation=function(string $visibility,?int $teamId,string $comment)use($pdo,$owner,$sourceId,$versionId,$pub): string {
    $capturePublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")
      ->execute([$capturePublic,$sourceId,$versionId,$owner['id'],'Captured '.$comment]);$captureId=(int)$pdo->lastInsertId();
    $annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status,published_at) VALUES(?,?,?,?,?,?,?,?, 'published',NOW())")
      ->execute([$annotationPublic,$owner['id'],$sourceId,$versionId,$captureId,$comment,$visibility,$teamId]);return $annotationPublic;
};
$teamAnnotation=$makeAnnotation('team',$teamId,'Team context annotation');
$privateAnnotation=$makeAnnotation('private',null,'Private context annotation');

$claimPublic=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'Context Claim','factual','supported')")
  ->execute([$claimPublic,$projectId,$owner['id']]);
$findingPublic=$pub('finding');$pdo->prepare("INSERT INTO research_findings(public_id,project_id,created_by_user_id,title,summary,status) VALUES(?,?,?,'Context Finding','Context finding summary','final')")
  ->execute([$findingPublic,$projectId,$owner['id']]);

$agentPublic=$pub('agent');$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,'Context Agent')")
  ->execute([$agentPublic,$owner['id']]);$agentId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner'),(?,?,'member')")->execute([$agentId,$owner['id'],$agentId,$member['id']]);

$resolved=workspace_context_resolve($pdo,$member,[
  'team_public_id'=>$teamPublic,'research_public_id'=>$projectPublic,'object_type'=>'annotation','object_public_id'=>$teamAnnotation,'agent_conversation_public_id'=>$agentPublic
]);
p34(($resolved['team']['public_id']??'')===$teamPublic,'current Team membership resolves Team context');
p34(($resolved['research']['public_id']??'')===$projectPublic,'current Research access resolves Research context');
p34(($resolved['object']['public_id']??'')===$teamAnnotation&&($resolved['object']['label']??'')==='Annotation','same-Team Annotation resolves as generic object context');
p34(($resolved['agent']['public_id']??'')===$agentPublic,'current Agent conversation membership resolves Agent context');

$claimContext=workspace_context_resolve($pdo,$member,['object_type'=>'claim','object_public_id'=>$claimPublic]);
p34(($claimContext['object']['public_id']??'')===$claimPublic&&($claimContext['research']['public_id']??'')===$projectPublic,'Claim context automatically restores its containing Research project');
p34(($claimContext['team']['public_id']??'')===$teamPublic,'Claim context automatically restores the accessible project Team');
$findingContext=workspace_context_resolve($pdo,$member,['object_type'=>'finding','object_public_id'=>$findingPublic]);
p34(($findingContext['research']['public_id']??'')===$projectPublic,'Finding context automatically restores its containing Research project');

$sourceContext=workspace_context_resolve($pdo,$member,['object_type'=>'source','object_public_id'=>$sourcePublic]);
p34(($sourceContext['object']['public_id']??'')===$sourcePublic,'accessible Research Source resolves as object context');
$privateForMember=workspace_context_resolve($pdo,$member,['object_type'=>'annotation','object_public_id'=>$privateAnnotation]);
p34($privateForMember['object']===null,'private Annotation never resolves for another Team member');

$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$owner['id'],$member['id']]);
$blocked=workspace_context_resolve($pdo,$member,['team_public_id'=>$teamPublic,'research_public_id'=>$projectPublic,'object_type'=>'annotation','object_public_id'=>$teamAnnotation]);
p34($blocked['object']===null,'user block drops stale Annotation object context');
p34(($blocked['research']['public_id']??'')===$projectPublic,'user block does not falsely remove unrelated current Research access');
$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$owner['id'],$member['id']]);

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$member['id']]);
$revoked=workspace_context_resolve($pdo,$member,['team_public_id'=>$teamPublic,'research_public_id'=>$projectPublic,'object_type'=>'claim','object_public_id'=>$claimPublic,'agent_conversation_public_id'=>$agentPublic]);
p34($revoked['team']===null&&$revoked['research']===null&&$revoked['object']===null,'Team revocation drops Team Research and Research-object context immediately');
p34(($revoked['agent']['public_id']??'')===$agentPublic,'independent Agent membership survives unrelated Team revocation');

$pdo->prepare('DELETE FROM conversation_members WHERE conversation_id=? AND user_id=?')->execute([$agentId,$member['id']]);
$agentRevoked=workspace_context_resolve($pdo,$member,['agent_conversation_public_id'=>$agentPublic]);
p34($agentRevoked['agent']===null,'Agent membership revocation drops stale Agent context immediately');

$outsiderContext=workspace_context_resolve($pdo,$outsider,['team_public_id'=>$teamPublic,'research_public_id'=>$projectPublic,'object_type'=>'claim','object_public_id'=>$claimPublic,'agent_conversation_public_id'=>$agentPublic]);
p34($outsiderContext['team']===null&&$outsiderContext['research']===null&&$outsiderContext['object']===null&&$outsiderContext['agent']===null,'outsider cannot resolve another user workspace refs');

$ownerPrivate=workspace_context_resolve($pdo,$owner,['object_type'=>'annotation','object_public_id'=>$privateAnnotation]);
p34(($ownerPrivate['object']['public_id']??'')===$privateAnnotation,'owner can resolve their own private Annotation context');

$runtime=file_get_contents($root.'/app/workspace-context.php');
p34(!str_contains($runtime,'INSERT INTO ')&&!str_contains($runtime,'UPDATE ')&&!str_contains($runtime,'DELETE FROM '),'workspace resolver is read-only and creates no persistent state');
p34(!str_contains($runtime,'selected_text')&&!str_contains($runtime,'text_commentary')&&!str_contains($runtime,'canonical_url'),'workspace resolver returns navigation metadata rather than source or Annotation content');

echo "Phase 34 Contextual Navigation & Workspace State MariaDB suite passed.\n";
