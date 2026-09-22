<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

require_once $root.'/app/installer.php';
$modules=[
 'storage.php','jobs.php','ai.php','concurrency.php','functions.php','access.php','object-handoff.php','notifications.php',
 'source-integrity.php','annotation-intelligence.php','live.php','conversations.php','moderation.php','search.php',
 'rate-limit.php','proactive-intelligence.php','research-automation.php','cross-research.php','research-outcomes.php',
 'research-reviews.php','change-impact.php','research-portfolio.php','living-research.php','research-network.php',
 'research-provenance.php','data-attribution.php','data-datasets.php','data-evaluations.php','data-model-registry.php',
 'data-training.php','data-post-training.php','data-model-release.php','data-model-deployment.php','data-model-observability.php',
 'data-model-improvement.php','data-model-campaigns.php','intelligence-release-audit.php','research-verification.php',
 'research-evidence-packs.php','research-workflow.php','action-center.php'
];
foreach($modules as $module)require_once $root.'/app/'.$module;

function home_cognitive_mysql8(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

$run='home-mysql8-'.substr(bin2hex(random_bytes(6)),0,12);
$username='home_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','cloaked')")
    ->execute(['u-'.$run,$username,'Home MySQL 8',$username.'@example.test']);
$userId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$userId]);
$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$userId]);$viewer=$q->fetch();
home_cognitive_mysql8((bool)$viewer,'Home runtime fixture user exists');

$base=cognitive_feed_items($pdo,$viewer,[],false);
home_cognitive_mysql8(($base['ready']??false)===true,'Full Home cognitive item collection executes on MySQL 8');

$feed=cognitive_feed_compose_from_items($base,4,28);
home_cognitive_mysql8(($feed['ready']??false)===true,'NOW feed composes on MySQL 8');

$actions=action_center_compose($pdo,$viewer,[],60,$base);
home_cognitive_mysql8(($actions['ready']??false)===true,'Action Center composes from the same Home cognitive state on MySQL 8');

$brief=proactive_briefing($pdo,$viewer,3);
home_cognitive_mysql8(($brief['ready']??false)===true,'Proactive briefing executes from current NOW state on MySQL 8');

echo "Home Cognitive + Proactive MySQL 8 runtime suite passed.\n";
