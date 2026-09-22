<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

require_once $root.'/app/functions.php';
require_once $root.'/app/access.php';
require_once $root.'/app/notifications.php';
require_once $root.'/app/research-library.php';

function research_library_db_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

$run='researchlib'.substr(bin2hex(random_bytes(6)),0,10);
$pub=fn(string $prefix)=>$prefix.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $label)use($pdo,$run,$pub): array{
    $username=substr(strtolower($label).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','cloaked')")
        ->execute([$pub('u'),$username,$label,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);
    return $q->fetch();
};

$owner=$makeUser('ResearchLibraryOwner');
$other=$makeUser('ResearchLibraryOther');

$projectPublic=$pub('project');
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,description) VALUES(?,?,?,?)")
    ->execute([$projectPublic,$owner['id'],'Folder Metrics Project','Project used to verify folder activity metrics.']);
$projectId=(int)$pdo->lastInsertId();

$privatePublic=$pub('private');
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title) VALUES(?,?,?)")
    ->execute([$privatePublic,$other['id'],'Private Other Project']);

$source=ensure_source($pdo,'https://'.$run.'.example.test/research','Research folder source');
$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,content_hash) VALUES(?,1,?,?,?)')
    ->execute([$source['id'],$source['canonical_url'],'Research folder source',hash('sha256',$run)]);
$versionId=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$source['id']]);

$capturePublic=$pub('capture');
$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text','Folder evidence')")
    ->execute([$capturePublic,$source['id'],$versionId,$owner['id']]);
$captureId=(int)$pdo->lastInsertId();

$annotationPublic=$pub('annotation');
$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,'Folder Annotation','public','published')")
    ->execute([$annotationPublic,$owner['id'],$source['id'],$versionId,$captureId]);
$annotationId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')
    ->execute([$projectId,$annotationId,$owner['id']]);
$pdo->prepare("INSERT INTO comments(annotation_id,user_id,body) VALUES(?,?,?)")
    ->execute([$annotationId,$owner['id'],'Folder comment']);
$pdo->prepare("INSERT INTO annotation_reactions(annotation_id,user_id,reaction) VALUES(?,?,'like')")
    ->execute([$annotationId,$owner['id']]);

notification_create($pdo,(int)$owner['id'],null,'research_update','research_project',$projectPublic,'Research project changed',[
    'allow_self'=>true,
    'category'=>'research',
    'dedupe_key'=>'research-library-'.$run,
    'context'=>['project_public_id'=>$projectPublic],
]);

$rows=research_library_projects($pdo,$owner,100);
$project=null;foreach($rows as $row)if(($row['public_id']??'')===$projectPublic){$project=$row;break;}
research_library_db_assert(is_array($project),'Accessible Research project appears in folder library');
research_library_db_assert((int)$project['annotation_count']===1,'Folder library counts attached Annotations');
research_library_db_assert((int)$project['comment_count']===1,'Folder library counts comments across project Annotations');
research_library_db_assert((int)$project['like_count']===1,'Folder library counts likes across project Annotations');
research_library_db_assert((int)$project['notification_count']>=1,'Folder library counts unread project notifications');
research_library_db_assert(trim((string)$project['recent_at'])!=='','Folder library computes recent activity');

$leaked=count(array_filter($rows,fn($row)=>($row['public_id']??'')===$privatePublic));
research_library_db_assert($leaked===0,'Folder library does not expose another user\'s private Research project');

echo "Research folder library database suite passed.\n";
