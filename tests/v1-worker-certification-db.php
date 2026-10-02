<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/release.php';
require_once $root.'/app/v1-worker-certification.php';
$dsn=(string)getenv('DB_DSN');if($dsn==='')throw new RuntimeException('DB_DSN required');
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$check=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;};
$registry=release_worker_specs();
$check(isset($registry['research_tasks'],$registry['research_automation'],$registry['ai'])&&count($registry)===16,'Canonical original 16-worker registry reused.');
$pdo->exec('DELETE FROM worker_heartbeats');
$before=release_worker_health($pdo);
$check($before['research_automation']['status']==='never','Never-run automation is not misreported as healthy.');
release_worker_heartbeat($pdo,'research_automation','starting','certification start');
$starting=release_worker_health($pdo);
$check($starting['research_automation']['last_success_at']===null,'Starting a job does not manufacture success evidence.');
release_worker_heartbeat($pdo,'research_automation','success','scheduled=0 watch=0 sponsored_deadlines=0 sponsored_blockers=0 sponsored_revisions=0',0);
$after=release_worker_health($pdo);
$check($after['research_automation']['status']==='success'&&!empty($after['research_automation']['last_success_at']),'Existing worker success heartbeat is persisted.');
$queues=release_queue_health($pdo);
$check(array_key_exists('research_automation',$queues),'Canonical Research Automation queue exists in current schema.');
$report=v1_worker_certification_snapshot($pdo,$root);
$check(!$report['production_certified'],'Real database still cannot assert production cron or notification delivery.');
$check($report['workers']['research_automation']['state']==='verified'&&!$report['code_and_heartbeat_ready'],
 'Automation success is demonstrated but missing other core workers blocks whole-service acceptance.');
$check(isset($report['blocking']['ai'])&&in_array('no_recent_success',$report['blocking']['ai'],true),'Missing AI success blocks live certification.');
$check($report['workers']['training']['state']==='inactive_on_demand','Inactive evaluation/training need no fabricated heartbeat.');
echo "V1 worker real-DB heartbeat and queue certification passed.\n";
