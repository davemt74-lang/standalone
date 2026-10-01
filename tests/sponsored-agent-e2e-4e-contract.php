<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$failed=[];
$assert=static function(bool $ok,string $m)use(&$failed):void{if(!$ok)$failed[]=$m;};
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$map=$read('app/sponsored-agent-integration-map.php');
$doc=$read('docs/sponsored-agent-e2e-4e.md');
$journey=$read('tests/sponsored-agent-e2e-4e-db.php');
$baseline=$read('tests/sponsored-agent-proactive-4d-db.php');
$worker=$read('bin/research-automations.php');
$assert(str_contains($journey,"require __DIR__.'/sponsored-agent-proactive-4d-db.php'"),
 '4E extends exact existing full transaction/alert journey rather than duplicating a runtime or SQL fixture.');
foreach(['sponsored_project_assign_agent','agent_action_create_proposals',
'agent_action_confirm_execute','sponsored_project_admin_status','sponsored_agent_proactive_scan',
'notification_object_access','sponsored_project_compensation_for_assignment'] as $symbol)
 $assert(str_contains($baseline,$symbol),'Canonical E2E prerequisite: '.$symbol);
foreach(['CompOutsider','revision_requested','paused','closed','assignment_id','dedupe_key',
  'sponsored_project_sample_projects'] as $marker)
 $assert(str_contains($journey,$marker),'4E includes negative/terminal fixture: '.$marker);
$assert(str_contains($worker,'sponsored_agent_proactive_scan(')
 &&str_contains($worker,'release_worker_heartbeat('),
 '4D uses the existing worker with observable worker heartbeat.');
$assert(str_contains($map,"'4e_acceptance'"),'Canonical map describes one consolidated 4E acceptance gate.');
$assert(str_contains($doc,'Operator activation check')
 &&str_contains($doc,'cron')
 &&str_contains($doc,'No automatic sponsor approval'),
 'Operator scheduling is an explicit operational release requirement, not an unproven CI claim.');
$assert(!is_file($root.'/database/migrations/20261001_130_sponsored_agent_e2e.sql'),
 '4E adds no new task/Agent/sponsor/notification schema.');
if($failed){foreach($failed as $error)fwrite(STDERR,'FAIL: '.$error.PHP_EOL);exit(1);}
echo 'Sponsored Research 4E source and deployment contracts passed.'.PHP_EOL;
