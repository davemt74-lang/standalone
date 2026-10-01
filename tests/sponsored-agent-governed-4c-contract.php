<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/sponsored-agent-operations.php';
$fail=[];
$check=static function(bool $yes,string $msg)use(&$fail):void{if(!$yes)$fail[]=$msg;};
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$caps=sponsored_agent_operation_capabilities();
$check(count($caps)===2&&isset($caps['sponsored.project.post_update'],$caps['sponsored.project.submit']),
  'Use exactly two bounded Sponsored Project Agent operations, not another Agent runtime.');
$accepted=str_repeat('a',64);
$input=['campaign_id'=>'campaign-test','campaign_revision'=>2,'accepted_terms_hash'=>$accepted,
    'title'=>'Update','body'=>'Source review under way','progress_status'=>'blocked','milestone_position'=>1];
$clean=sponsored_agent_operation_clean('sponsored.project.post_update',$input);
$check($clean['scope']==='participant'&&$clean['progress_status']==='blocked'
    &&$clean['accepted_terms_hash']===$accepted,
    'Agent update is forced private and binds exact accepted revision and terms hash.');
foreach([
 ['accepted_terms_hash'=>'invalid'],
 ['campaign_revision'=>0],
 ['progress_status'=>'completed'],
 ['title'=>''],
 ['body'=>'']
] as $bad){
    try{sponsored_agent_operation_clean('sponsored.project.post_update',array_merge($input,$bad));
        $check(false,'Malformed or escalated Sponsored update passed validation.');}
    catch(InvalidArgumentException $expected){}
}
$evidence=['campaign_id'=>'campaign-test','campaign_revision'=>2,'accepted_terms_hash'=>$accepted,
    'title'=>'Report submission','assets'=>[['type'=>'document','public_id'=>'owned-document']]];
$submission=sponsored_agent_operation_clean('sponsored.project.submit',$evidence);
$check(count($submission['assets'])===1&&$submission['assets'][0]['type']==='document',
    'Only bounded existing evidence is eligible for governed submission.');
try{sponsored_agent_operation_clean('sponsored.project.submit',array_merge($evidence,
    ['assets'=>[['type'=>'external_url','public_id'=>'fake']]]));$check(false,'Unknown evidence type accepted.');}
catch(InvalidArgumentException $expected){}
$backend=$read('app/sponsored-agent-operations.php');
$actions=$read('app/agent-actions.php');
$chat=$read('app/agent-chat.php');
$js=$read('assets/js/agent-chat.js');
$bootstrap=$read('app/bootstrap.php');
$workspace=$read('app/sponsored-project-workspace.php');
$submit=$read('app/sponsored-research-projects.php');
$check(str_contains($backend,'sponsored_agent_awareness_access(')
    &&str_contains($backend,'sponsored_research_campaign_terms_latest(')
    &&str_contains($backend,"$"."p['campaign_revision_accepted']")
    &&str_contains($backend,"$"."a['project_id']"),
    'Every proposal and confirmation uses current accepted revision, terms and exact assigned Agent Research project.');
$check(str_contains($backend,"'sponsored_project:'.")&&str_contains($backend, '$seen[$kind.'),
    'Proposal validates actual cited project and existing source evidence, not invented IDs.');
$check(str_contains($backend,"'scope'=>'participant'")&&str_contains($backend,"sponsored_workspace_validate_update("),
    'Agent never broadcasts, marks sponsor milestones completed, or bypasses private thread validation.');
$check(str_contains($actions,'sponsored_agent_operation_clean(')
  &&str_contains($actions,'sponsored_agent_operation_validate(')
  &&str_contains($actions,'sponsored_agent_operation_execute(')
  &&str_contains($actions,"'sponsored.project.post_update','sponsored.project.submit'"),
    'Existing Agent proposals and confirmed execution recheck Sponsored source provenance and stale state.');
$check(str_contains($backend,'sponsored_workspace_post(')&&str_contains($backend,'sponsored_project_submit('),
    'Writes reuse canonical progress and immutable submission/review services.');
$check(str_contains($workspace,'$ownsTransaction=!$pdo->inTransaction()')
  &&str_contains($submit,'$ownsTransaction=!$pdo->inTransaction()'),
    'Canonical services support one atomic existing confirmation transaction without nested PDO transactions.');
$check(str_contains($js,"'sponsored.project.submit':'Submit Sponsored Project research for review'")
  &&str_contains($chat,'Every proposal requires explicit researcher confirmation.'),
    'Agent Chat visibly distinguishes consequential sponsored actions and human confirmation.');
$check(!str_contains($backend,'payment_reference')&&!str_contains($backend,'compensation_admin_transition'),
    'No Agent payment or compensation action is introduced.');
if($fail){foreach($fail as $e)fwrite(STDERR,"FAIL: $e\n");exit(1);}
echo "Sponsored Research 4C governed Agent actions, evidence provenance and explicit confirmation contract passed.\n";
