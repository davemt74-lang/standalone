<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/v1-worker-certification.php';
$errors=[];$check=static function(bool $ok,string $label)use(&$errors):void{if(!$ok)$errors[]=$label;};
$specs=[
 'research_tasks'=>['command'=>'php worker/research-task-worker.php','stale_after'=>300],
 'research_automation'=>['command'=>'php bin/research-automations.php --limit=25','stale_after'=>900],
 'training'=>['command'=>'php bin/training-worker.php','stale_after'=>900],
];
$now=gmdate('Y-m-d H:i:s');
$workers=[
 'research_tasks'=>['status'=>'success','age_seconds'=>0,'last_success_at'=>$now],
 'research_automation'=>['status'=>'success','age_seconds'=>0,'last_success_at'=>$now],
 'training'=>['status'=>'never','age_seconds'=>null,'last_success_at'=>null],
];
$queues=[
 'research_tasks'=>['queued'=>0,'processing'=>0,'failed'=>0,'blocked'=>0],
 'research_automation'=>['queued'=>0,'processing'=>0,'failed'=>0,'blocked'=>0],
 'training'=>['queued'=>0,'processing'=>0,'failed'=>0,'blocked'=>0],
];
$r=v1_worker_certification_analyze($specs,$workers,$queues,$root);
$check($r['code_and_heartbeat_ready'],'Recent core successes and inactive optional training meet heartbeat readiness.');
$check(!$r['production_certified']&&$r['production_evidence']==='not_verified_by_repository',
 'Live production never self-certifies from DB records.');
$check($r['workers']['training']['state']==='inactive_on_demand','Idle on-demand training does not block readiness.');
$check($r['workers']['research_automation']['state']==='verified','Existing Research Automation scheduled worker is canonical.');
$fail=$workers;$fail['research_tasks']=['status'=>'starting','age_seconds'=>1,'last_success_at'=>null];
$x=v1_worker_certification_analyze($specs,$fail,$queues,$root);
$check(!$x['code_and_heartbeat_ready']&&in_array('no_recent_success',$x['blocking']['research_tasks']??[],true),
 'Starting heartbeat without a successful run never certifies a core worker.');
$missing=$queues;$missing['research_tasks']=['error'=>'unavailable'];
$x=v1_worker_certification_analyze($specs,$workers,$missing,$root);
$check(!$x['code_and_heartbeat_ready']&&in_array('queue_unavailable',$x['blocking']['research_tasks']??[],true),
 'Failed queue query never becomes an empty healthy queue.');
$postSpec=['post_training'=>['command'=>'php bin/post-training-worker.php','stale_after'=>900]];
$postHealth=['post_training'=>['status'=>'never','age_seconds'=>null,'last_success_at'=>null]];
$postQueues=['post_training'=>['queued'=>1,'processing'=>0,'review'=>0,'failed'=>0,'blocked'=>0]];
$x=v1_worker_certification_analyze($postSpec,$postHealth,$postQueues,$root);
$check($x['code_and_heartbeat_ready']&&$x['workers']['post_training']['state']==='inactive_on_demand',
 'Human-held draft post-training plans do not require a worker heartbeat.');
$postQueues['post_training']['review']=1;
$x=v1_worker_certification_analyze($postSpec,$postHealth,$postQueues,$root);
$check(!$x['code_and_heartbeat_ready']&&$x['workers']['post_training']['required_now'],
 'Post-training reviewing plans require a verified worker invocation.');
$backlog=$queues;$backlog['training']['queued']=1;
$x=v1_worker_certification_analyze($specs,$workers,$backlog,$root);
$check(!$x['code_and_heartbeat_ready']&&$x['workers']['training']['required_now'],
 'On-demand worker becomes required when queued work appears.');
$broken=$queues;$broken['research_automation']['failed']=1;
$x=v1_worker_certification_analyze($specs,$workers,$broken,$root);
$check(!$x['code_and_heartbeat_ready']&&in_array('failed_jobs_present',$x['blocking']['research_automation']??[],true),
 'Failed Research Automation jobs require operator review.');
$missingScript=['phantom'=>['command'=>'php worker/unregistered-worker.php','stale_after'=>300]];
$x=v1_worker_certification_analyze($missingScript,[],[], $root);
$check(in_array('worker_script_missing',$x['blocking']['phantom']??[],true),'Missing registered scripts are blocked.');
$ctx=v1_worker_certification_agent_context($x);
$check(str_contains($ctx,'READ ONLY')&&str_contains($ctx,'confirmation-required'),'Admin Agent receives diagnosis without autonomous repair permission.');
$admin=(string)file_get_contents($root.'/admin/system-health.php');
$ops=(string)file_get_contents($root.'/app/admin-operations.php');
$cli=(string)file_get_contents($root.'/bin/v1-worker-certify.php');
$check(str_contains($admin,'v1_worker_certification_snapshot(')&&str_contains($admin,'NOT VERIFIED'),'Admin health distinguishes server evidence from live verification.');
$check(str_contains($ops,'v1_worker_certification_agent_context(')&&str_contains($ops,'admin.operations.view'),'Admin Agent receives permission-scoped worker diagnosis.');
$check(str_contains($cli,"PHP_SAPI!=='cli'")&&str_contains($cli,'--json')&&str_contains($cli,'v1_worker_certification_snapshot('),'CLI certification is read-only and machine readable.');
$check(str_contains((string)file_get_contents($root.'/bin/research-automations.php'),'sponsored_agent_proactive_scan('),'Sponsor alerts reuse existing scheduler.');
if($errors){foreach($errors as $m)fwrite(STDERR,'FAIL: '.$m.PHP_EOL);exit(1);}
echo "V1 live worker certification contracts passed.\n";
