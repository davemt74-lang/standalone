<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)(getenv('DB_USER')?:'root');$dbPass=(string)(getenv('DB_PASS')?:'');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','research-decisions','living-research'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function hotfix_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$run='p71hf'.substr(bin2hex(random_bytes(5)),0,10);
$publicUser='u-'.$run;$username='user_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
  ->execute([$publicUser,$username,'Publication Hotfix User',$username.'@example.test']);
$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);
$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$user=$q->fetch();
$projectPublic='project-'.$run;
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,description,status) VALUES(?,?,?,?,'active')")
  ->execute([$projectPublic,$uid,'Publication Hotfix Project','MySQL 8 managed publication listing regression']);
$project=project_access($pdo,$uid,$projectPublic);hotfix_assert($project!==null,'Hotfix project is accessible.');
$published=research_report_publish($pdo,$project,$user,'private','Hotfix Managed Report','Regression fixture for Publications page.');
hotfix_assert(!empty($published['public_id']),'Hotfix report publishes.');
$managed=living_research_managed_reports($pdo,$user,20);
hotfix_assert(in_array((string)$published['public_id'],array_column($managed,'public_id'),true),'Managed report query is MySQL 8 compatible and returns the owned publication.');
echo "Post-Phase-71 Research Publications MySQL 8 hotfix database journey passed.\n";
