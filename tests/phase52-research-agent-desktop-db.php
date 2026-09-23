<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p52(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p52throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p52(research_agent_workspace_ready($pdo),'Phase 52 Research Agent Desktop schema is available.');
$run='p52'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
  $username=substr(strtolower($name).'_'.$run,0,48);
  $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
    ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
  $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('DesktopOwner','admin');$researcher=$makeUser('DesktopResearcher');$viewer=$makeUser('DesktopViewer');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Desktop Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$researcher,'researcher'],[$viewer,'viewer']] as [$member,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$member['id'],$role]);

$agent=research_agent_create($pdo,$owner,['name'=>'Desktop Research Agent','description'=>'Organize evidence visually.','team_id'=>$teamPublic,'cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$researcher,(string)$agent['public_id']);
p52(($project['access_role']??'')==='researcher','Team researcher receives Desktop write access from the Research Agent project.');

$folder=research_agent_workspace_create_folder($pdo,$researcher,$project,'Primary Sources');
$doc=research_agent_workspace_create_document($pdo,$researcher,$project,['title'=>'Desktop Memo','document_type'=>'memo','content_html'=>'<h1>Memo</h1><p>Desktop content.</p>']);
$bookmark=research_agent_workspace_create_bookmark($pdo,$researcher,$project,['url'=>'https://example.com/'.$run.'/source','title'=>'Desktop Source']);
$sticky=research_agent_workspace_create_sticky($pdo,$researcher,$project,['body'=>'Desktop sticky','color'=>'yellow','x'=>80,'y'=>110]);

$source=ensure_source($pdo,'https://example.com/'.$run.'/annotation','Desktop Annotation Source');
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([$source['id'],'https://example.com/'.$run.'/annotation','Desktop Annotation Source','Captured desktop evidence.',hash('sha256','Captured desktop evidence.')]);
$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$source['id']]);
$capPublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")
  ->execute([$capPublic,$source['id'],$versionId,$researcher['id'],'Captured desktop evidence.']);$captureId=(int)$pdo->lastInsertId();
$annPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'public','published')")
  ->execute([$annPublic,$researcher['id'],$source['id'],$versionId,$captureId,'Important desktop annotation']);
$annotationId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$annotationId,$researcher['id']]);

$desktop=research_agent_workspace_desktop_items($pdo,$researcher,$project,false);
$types=array_count_values(array_map(fn($x)=>(string)$x['object_type'],$desktop));
p52(($types['folder']??0)>=1&&($types['document']??0)>=1&&($types['bookmark']??0)>=1&&($types['annotation']??0)>=1,'Desktop combines workspace objects and linked annotations.');
p52(($types['sticky']??0)===0,'Open sticky notes are not duplicated as Desktop file icons.');
$stickies=research_agent_workspace_stickies($pdo,$researcher,$project);
p52(count(array_filter($stickies,fn($x)=>($x['public_id']??'')===$sticky['public_id']))===1,'Sticky notes remain durable floating Desktop objects.');

$saved=research_agent_workspace_desktop_position_save($pdo,$researcher,$project,'document',(string)$doc['public_id'],321,244,33);
p52($saved['x']===321&&$saved['y']===244&&$saved['z']===33,'Document icon drag position and z-order persist.');
$savedAnn=research_agent_workspace_desktop_position_save($pdo,$researcher,$project,'annotation',$annPublic,444,155,34);
p52($savedAnn['x']===444&&$savedAnn['y']===155,'Linked annotation icon positions persist without copying annotation content.');

$desktop=research_agent_workspace_desktop_items($pdo,$researcher,$project,false);
$docDesktop=current(array_filter($desktop,fn($x)=>($x['public_id']??'')===$doc['public_id']));
$annDesktop=current(array_filter($desktop,fn($x)=>($x['public_id']??'')===$annPublic));
p52(($docDesktop['desktop']['x']??0)===321&&($annDesktop['desktop']['y']??0)===155,'Desktop reload restores saved icon coordinates.');

$viewerProject=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);
p52throws(fn()=>research_agent_workspace_desktop_position_save($pdo,$viewer,$viewerProject,'document',(string)$doc['public_id'],9,9,2),'Team viewer cannot rearrange shared Research Desktop state.');

research_agent_workspace_trash($pdo,$researcher,(string)$bookmark['public_id']);
$trash=research_agent_workspace_desktop_items($pdo,$researcher,$project,true);
p52(count(array_filter($trash,fn($x)=>($x['public_id']??'')===$bookmark['public_id']))===1,'Desktop Trash exposes trashed workspace objects for restore.');
p52(count(array_filter($trash,fn($x)=>($x['object_type']??'')==='annotation'))===0,'Desktop Trash does not fabricate deletion state for linked annotations.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$researcher['id']]);
p52(research_agent_access($pdo,$researcher,(string)$agent['public_id'])===null,'Removed Team member immediately loses Research Agent Desktop access.');
p52throws(fn()=>research_agent_workspace_desktop_position_save($pdo,$researcher,$project,'document',(string)$doc['public_id'],1,1,1),'Removed Team member cannot mutate stale Desktop state.');

echo "Phase 52 Research Agent Desktop MariaDB suite passed.\n";
