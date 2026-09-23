<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','runtime-compat','functions','access','source-integrity','annotation-intelligence','feed','public-discovery','profile-showcase'] as $lib)require_once $root.'/app/'.$lib.'.php';
function pp2(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function pp2throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

pp2(profile_showcase_ready($pdo),'Profile Phase 2 migration 061 showcase schema is ready.');
$run='pp2'.substr(bin2hex(random_bytes(5)),0,10);
$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $visibility='public')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,status,role,live_presence_mode) VALUES(?,?,?,?, 'active','user','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO user_preferences(user_id,profile_visibility) VALUES(?,?)')->execute([$id,$visibility]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ProfileOwner');$publicPerson=$makeUser('VisibleFollower');$privatePerson=$makeUser('PrivateFollower','private');
$prefs=profile_showcase_preferences($pdo,(int)$owner['id']);pp2((int)$prefs['profile_show_research']===1&&(int)$prefs['profile_show_collections']===1&&(int)$prefs['profile_show_about']===1,'Profile Phase 2 defaults expose public Research, collections and About without changing source visibility.');

$makeAnnotation=function(string $label)use($pdo,$owner,$pub): array{
    $url='https://example.test/'.$pub('source');$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'article',?,?,? ,?,'current')")->execute([$pub('s'),$url,hash('sha256',$url),'example.test',$label]);$sid=(int)$pdo->lastInsertId();
    $text='Evidence '.$label;$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())')->execute([$sid,$url,$label,$text,hash('sha256',$text)]);$sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$sv,$sid]);
    $pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")->execute([$pub('cap'),$sid,$sv,(int)$owner['id'],$text]);$cap=(int)$pdo->lastInsertId();
    $aid=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status,published_at) VALUES(?,?,?,?,?,?,'public','published',NOW())")->execute([$aid,(int)$owner['id'],$sid,$sv,$cap,'Comment '.$label]);return ['public_id'=>$aid,'source_id'=>$sid];
};
$a1=$makeAnnotation('One');$a2=$makeAnnotation('Two');

$pdo->prepare("INSERT INTO collections(public_id,owner_user_id,title,description,visibility) VALUES(?,?,?,?, 'public')")->execute([$pub('col'),(int)$owner['id'],'Public Collection','Profile showcase collection']);$collectionPublic=$pdo->prepare('SELECT public_id FROM collections WHERE owner_user_id=? ORDER BY id DESC LIMIT 1');$collectionPublic->execute([(int)$owner['id']]);$collectionId=(string)$collectionPublic->fetchColumn();$q=$pdo->prepare('SELECT id FROM collections WHERE public_id=?');$q->execute([$collectionId]);$cid=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT id FROM annotations WHERE public_id=?');$q->execute([$a1['public_id']]);$aid=(int)$q->fetchColumn();$pdo->prepare('INSERT INTO collection_items(collection_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$cid,$aid,(int)$owner['id']]);

$projectPublic=$pub('project');$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,title,description) VALUES(?,?,?,?)')->execute([$projectPublic,(int)$owner['id'],'Profile Research','Published profile research']);$projectId=(int)$pdo->lastInsertId();
$reportPublic=$pub('report');$pdo->prepare("INSERT INTO research_reports(public_id,project_id,created_by_user_id,title,summary,visibility,status,published_at) VALUES(?,?,?,?,?,'public','published',NOW())")->execute([$reportPublic,$projectId,(int)$owner['id'],'Public Research Report','Immutable public research result']);$reportId=(int)$pdo->lastInsertId();$snapshot=json_encode(['project'=>['title'=>'Public Research Report','summary'=>'Immutable public research result'],'findings'=>[],'claims'=>[],'sources'=>[]],JSON_UNESCAPED_SLASHES);$pdo->prepare("INSERT INTO research_report_versions(public_id,report_id,version_number,published_by_user_id,visibility,title,summary,snapshot_json,snapshot_hash) VALUES(?,?,1,?,'public',?,?,?,?)")->execute([$pub('rv'),$reportId,(int)$owner['id'],'Public Research Report','Immutable public research result',$snapshot,hash('sha256',$snapshot)]);$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE research_reports SET current_version_id=? WHERE id=?')->execute([$versionId,$reportId]);

profile_showcase_pin_set($pdo,$owner,'annotation',$a1['public_id'],true);profile_showcase_pin_set($pdo,$owner,'research_report',$reportPublic,true);profile_showcase_pin_set($pdo,$owner,'collection',$collectionId,true);
$q=$pdo->prepare('SELECT COUNT(*) FROM profile_pins WHERE user_id=?');$q->execute([(int)$owner['id']]);pp2((int)$q->fetchColumn()===3,'Profile Phase 2 enforces three durable public showcase pins.');
pp2throws(fn()=>profile_showcase_pin_set($pdo,$owner,'annotation',$a2['public_id'],true),'Profile Phase 2 rejects a fourth live public pin.');

$pdo->prepare("UPDATE collections SET visibility='private' WHERE public_id=?")->execute([$collectionId]);profile_showcase_pin_set($pdo,$owner,'annotation',$a2['public_id'],true);$q->prepare('SELECT COUNT(*) FROM profile_pins WHERE user_id=?');$q->execute([(int)$owner['id']]);pp2((int)$q->fetchColumn()===3,'Profile Phase 2 prunes stale/private pins before applying the three-item limit.');
$q=$pdo->prepare("SELECT COUNT(*) FROM profile_pins WHERE user_id=? AND object_type='collection'");$q->execute([(int)$owner['id']]);pp2((int)$q->fetchColumn()===0,'A collection removed from public visibility can no longer remain pinned.');

$pdo->prepare('INSERT INTO follows(follower_user_id,followed_user_id) VALUES(?,?),(?,?)')->execute([(int)$publicPerson['id'],(int)$owner['id'],(int)$privatePerson['id'],(int)$owner['id']]);
$people=profile_showcase_people($pdo,(int)$owner['id'],'followers',null,100);pp2(count($people)===1&&(string)$people[0]['username']===(string)$publicPerson['username'],'Public follower list excludes private profiles while preserving the aggregate count separately.');

$collections=profile_showcase_public_collections($pdo,(int)$owner['id'],20);pp2(count($collections)===0,'Collections tab resolves only currently public collections.');
$pdo->prepare("UPDATE collections SET visibility='public' WHERE public_id=?")->execute([$collectionId]);$collections=profile_showcase_public_collections($pdo,(int)$owner['id'],20);pp2(count($collections)===1&&(int)$collections[0]['item_count']===1,'Collections tab reuses the existing public collection and public-annotation visibility model.');

$profile=public_discovery_profile($pdo,(string)$owner['username'],null);pp2($profile!==null&&count($profile['reports'])===1&&count($profile['annotations'])===2,'Profile presentation reuses existing public annotation and immutable public Research discovery.');
$activity=profile_showcase_activity($profile,$collections);pp2(count($activity)>=4&&in_array('research_report',array_column($activity,'type'),true)&&in_array('collection',array_column($activity,'type'),true),'Activity composes existing public object types without copying their records.');

echo "Profile Phase 2 database journey passed.\n";
