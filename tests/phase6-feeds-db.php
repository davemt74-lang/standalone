<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/feed.php';
function p6_ok(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p6'.substr(bin2hex(random_bytes(8)),0,12);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array {$public=$pub('u');$username=substr($name.'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status) VALUES(?,?,?,?,NOW(),'active')")->execute([$public,$username,$name,$username.'@example.test']);return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'username'=>$username,'display_name'=>$name,'role'=>'user'];};
$a=$makeUser('phase6_a');$b=$makeUser('phase6_b');$c=$makeUser('phase6_c');

p6_ok(canonicalize_url('https://example.test/x?utm_source=n&b=2&a=1')==='https://example.test/x?a=1&b=2','tracking parameters are removed and query identity is stable');
p6_ok(canonicalize_url('https://youtu.be/abc123?si=tracking')===canonicalize_url('https://www.youtube.com/watch?v=abc123&t=99'),'YouTube URL variants resolve to one video identity');
p6_ok(source_identity_url('https://example.test/a','https://evil.test/a')==='https://example.test/a','cross-host canonical declarations are rejected');

$legacyRaw='https://legacy-'.$run.'.example.test/story?b=2&a=1';$legacyCanonical=canonicalize_url_legacy($legacyRaw);$legacyPublic=$pub('legacy');
$pdo->prepare('INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title) VALUES(?,?,?,?,?,?)')->execute([$legacyPublic,'webpage',$legacyCanonical,hash('sha256',$legacyCanonical),(string)parse_url($legacyCanonical,PHP_URL_HOST),'Legacy source']);
$legacyId=(int)$pdo->lastInsertId();$resolved=source_resolve_url($pdo,$legacyRaw,true);
p6_ok((int)($resolved['id']??0)===$legacyId,'legacy source hashes resolve through the Phase 6 identity bridge');
$q=$pdo->prepare('SELECT COUNT(*) FROM source_url_aliases WHERE source_id=?');$q->execute([$legacyId]);p6_ok((int)$q->fetchColumn()>=1,'resolved legacy sources remember the normalized alias');

$makeSource=function(string $url,string $title)use($pdo): array {$s=ensure_source($pdo,$url,$title);$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,content_hash) VALUES(?,1,?,?,?)')->execute([$s['id'],$s['canonical_url'],$title,hash('sha256',$url)]);$vid=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE sources SET current_version_id=?,status='current' WHERE id=?")->execute([$vid,$s['id']]);$s['version_id']=$vid;return $s;};
$s1=$makeSource('https://phase6-'.$run.'.example.test/article','Phase 6 source one');$s2=$makeSource('https://phase6-'.$run.'.example.test/other','Phase 6 source two');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$a['id'],'Phase 6 Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$a['id'],$teamId,$b['id']]);

$makeAnnotation=function(array $user,array $source,string $visibility,?int $teamId,string $text)use($pdo,$pub): string {
    $capturePublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")->execute([$capturePublic,$source['id'],$source['version_id'],$user['id'],'Evidence '.$text]);$captureId=(int)$pdo->lastInsertId();
    $annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status) VALUES(?,?,?,?,?,?,?,?,'published')")->execute([$annotationPublic,$user['id'],$source['id'],$source['version_id'],$captureId,$text,$visibility,$teamId]);return $annotationPublic;
};
$cPublic=$makeAnnotation($c,$s1,'public',null,'watched-source public');
$bTeam=$makeAnnotation($b,$s1,'team',$teamId,'followed-person team');
$bPrivate=$makeAnnotation($b,$s1,'private',null,'hidden private');
$aPrivate=$makeAnnotation($a,$s1,'private',null,'own private');
$bOther=$makeAnnotation($b,$s2,'public',null,'followed-person other source');

$pdo->prepare('INSERT INTO follows(follower_user_id,followed_user_id) VALUES(?,?)')->execute([$a['id'],$b['id']]);
$pdo->prepare('INSERT INTO source_watches(source_id,user_id) VALUES(?,?)')->execute([$s1['id'],$a['id']]);

$page=feed_annotation_rows($pdo,$a,'page',(int)$s1['id'],null,20);$pageIds=array_column($page['annotations'],'public_id');
p6_ok(count($pageIds)===3,'This Page returns public, authorized Team, and own private items only');
p6_ok(in_array($cPublic,$pageIds,true)&&in_array($bTeam,$pageIds,true)&&in_array($aPrivate,$pageIds,true)&&!in_array($bPrivate,$pageIds,true),'This Page enforces annotation visibility server-side');

$first=feed_annotation_rows($pdo,$a,'following',null,null,2);p6_ok(count($first['annotations'])===2&&$first['next_cursor']!==null,'Following uses bounded cursor pagination');
$second=feed_annotation_rows($pdo,$a,'following',null,feed_cursor_decode($first['next_cursor']),2);$following=array_merge($first['annotations'],$second['annotations']);$followingIds=array_column($following,'public_id');
p6_ok(count($followingIds)===4,'Following combines the viewer own posts, followed people, and followed sources without duplicating items');
p6_ok(in_array($cPublic,$followingIds,true)&&in_array($bTeam,$followingIds,true)&&in_array($bOther,$followingIds,true)&&in_array($aPrivate,$followingIds,true)&&!in_array($bPrivate,$followingIds,true),'Following keeps Team/private boundaries while including the viewer own accessible annotations');
p6_ok(in_array($aPrivate,$followingIds,true),'Following/Home includes the viewer own published annotations');

$markId=$first['annotations'][0]['public_id'];p6_ok(feed_mark_read($pdo,$a,[$markId])===1,'feed read mutation accepts accessible annotations');
$refetch=feed_annotation_rows($pdo,$a,'following',null,null,20);$readRow=array_values(array_filter($refetch['annotations'],fn($r)=>$r['public_id']===$markId))[0]??null;p6_ok((bool)($readRow['is_read']??false),'read state is returned with following cards');

$top=feed_create_comment($pdo,$a,$cPublic,'Top-level Phase 6 comment');$reply=feed_create_comment($pdo,$b,$cPublic,'Inline reply',(int)$top['id']);$thread=feed_comments($pdo,$cPublic,$a);
p6_ok(count($thread['comments']??[])===2,'inline discussion returns comments and replies');
p6_ok((string)$thread['comments'][1]['parent_comment_id']===(string)$top['id'],'reply parentage is preserved');

p6_ok(feed_source_followed($pdo,(int)$s1['id'],(int)$a['id']),'source follow state is readable');
p6_ok(feed_toggle_source_follow($pdo,(int)$s1['id'],(int)$a['id'])===false,'source can be unfollowed inline');
p6_ok(feed_toggle_source_follow($pdo,(int)$s1['id'],(int)$a['id'])===true,'source can be followed inline again');

$like=feed_toggle_annotation_like($pdo,$a,$cPublic);p6_ok($like['liked']===true&&$like['like_count']===1,'annotation Like can be added and counted');
$likedFeed=feed_annotation_rows($pdo,$a,'page',(int)$s1['id'],null,20);$likedRow=array_values(array_filter($likedFeed['annotations'],fn($r)=>$r['public_id']===$cPublic))[0]??null;
p6_ok((bool)($likedRow['viewer_liked']??false)&&((int)($likedRow['like_count']??0)===1),'feed returns viewer Like state and count');
$unlike=feed_toggle_annotation_like($pdo,$a,$cPublic);p6_ok($unlike['liked']===false&&$unlike['like_count']===0,'annotation Like can be removed and count returns to zero');
p6_ok(($likedRow['post_type']??'')==='quote','feed exposes normalized annotation post type');

echo "Phase 6 This Page + Following MariaDB suite passed.\n";
