<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/sponsored-agent-operations.php';
$fail=[];
$check=static function(bool $ok,string $message)use(&$fail):void{if(!$ok)$fail[]=$message;};
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$catalog=sponsored_agent_operations_catalog();
$check(count($catalog)===6&&array_keys($catalog)===['plan','tasks','report','milestones','update','submit'],
  'Existing Agent Chat exposes six governed plan, task, report, milestone, update and submission-readiness handoffs.');
foreach($catalog as $key=>$step){
    $check(trim((string)$step['title'])!==''&&trim((string)$step['prompt'])!=='',
      'Every operation has an accessible title and a concrete safe Agent prompt.');
}
$link=sponsored_agent_operations_link(['conversation_public_id'=>'agent-conv-1'],'sponsored-project-1','report');
$check(str_contains($link,'agent=agent-conv-1')&&str_contains($link,'sponsored_project=sponsored-project-1')&&str_contains($link,'sponsored_action=report'),
  'Deep link targets existing personal Agent conversation and precise project/action.');
$unknown=false;try{sponsored_agent_operations_link(['conversation_public_id'=>'agent-conv-1'],'project','unknown');}
catch(InvalidArgumentException $e){$unknown=true;}
$check($unknown,'Unrecognized operations fail closed.');
$helper=$read('app/sponsored-agent-operations.php');
$check(!str_contains($helper,'sponsored_project_submit(')&&!str_contains($helper,'sponsored_workspace_post(')
  &&!str_contains($helper,'agent_action_execute_capability(')&&!str_contains($helper,'PDO::beginTransaction'),
  'Handoffs never bypass native confirmation or nest independent Sponsored Project transactions.');
$check(str_contains($helper,'sponsored_agent_awareness_access(')&&
  str_contains($helper,'sponsored_agent_awareness_project_context(')&&
  str_contains($helper,"'requires_reacceptance'"),
  'Every handoff checks exact Agent ownership, accepted revision and current participant authority.');
$check(str_contains($helper,"'status']??'')!=='active'")&&
  str_contains($helper,"['open','scheduled']"),
  'Handoffs cannot propose work from paused/withdrawn or terminal Sponsored Project assignments.');
$check(str_contains($catalog['submit']['prompt'],'Do NOT submit')&&
  str_contains($catalog['update']['prompt'],'Do not post it'),
  'Agent submission and sponsor updates require separate human approval through existing forms.');
$home=$read('home.php');$workspace=$read('sponsored-project-workspace.php');
$check(str_contains($home,'$sponsoredOperationHandoff=sponsored_agent_operations_handoff(')&&
  str_contains($home,"if(\$sponsoredOperationHandoff):?><script>")&&
  str_contains($home,'JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT'),
  'Home validates operation requests server-side and injects escaped first-party Chat handoff.');
$check(str_contains($workspace,'$agentOperationAvailable=')&&
  str_contains($workspace,'sponsored_agent_operations_catalog()')&&
  str_contains($workspace,'sponsored_agent_operations_link(')&&
  str_contains($workspace,'research-sponsored-projects.php#project-'),
  'Project workspace shows action cards only to authorized assigned researchers and keeps manual final submission visible.');
$check(str_contains($read('app/agent-actions.php'),'function agent_action_confirm_execute(')&&
  str_contains($read('app/sponsored-research-projects.php'),'function sponsored_project_submit('),
  '4C reuses existing Human-confirmed Agent action and canonical Sponsored Project submission services.');
$check(!is_file($root.'/database/migrations/20261001_130_sponsored_agent_operations.sql'),
  'No speculative duplicate operations schema or migration introduced.');
if($fail){foreach($fail as $x)fwrite(STDERR,"FAIL: $x\n");exit(1);}
echo "Sponsored Agent Operations 4C safety and integration contracts passed.\n";
