<?php
declare(strict_types=1);
/** 4A audit-only regression gate; no database, migrations or runtime writes. */
$root=dirname(__DIR__);
require_once $root.'/app/sponsored-agent-integration-map.php';
$fail=[];
$check=static function(bool $ok,string $description)use(&$fail):void{
    if(!$ok)$fail[]=$description;
};
$map=sponsored_agent_integration_map();
$check(($map['scope']??'')==='Sponsored Research / assigned researcher and Agent only','Sponsored context has a bounded per-assignment audience.');
$check(sponsored_agent_integration_dependencies_present($root)===[],'Every mapped dependency resolves to an existing canonical function in the repository.');
$check(count($map['dependencies'])===15&&count($map['gaps'])===4&&count($map['invariants'])>=7,'Audit records 15 existing integrations, four follow-up sections and explicit safety rules.');
foreach(['4b_context','4c_actions','4d_proactive','4e_acceptance'] as $key)
    $check(isset($map['gaps'][$key]),'Every planned section has one explicit gap.');
foreach(['submission'=>'sponsored_project_submit','action_confirmation'=>'agent_action_confirm_execute','automation'=>'research_automation_execute','notification_acl'=>'notification_object_access','workspace_updates'=>'sponsored_workspace_post'] as $key=>$function)
    $check(($map['dependencies'][$key]['function']??'')===$function,'Integration routes '.$key.' to the canonical implementation.');
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$chat=$read('app/agent-chat.php');
$actions=$read('app/agent-actions.php');
$automation=$read('app/research-automation.php');
$submission=$read('app/sponsored-research-projects.php');
$workspace=$read('app/sponsored-project-workspace.php');
$notifications=$read('app/notifications.php');
$check(str_contains($chat,"function agent_chat_context_item(")&&str_contains($chat,"function agent_chat_context_normalize("),
    'Selected Agent Chat context is already permission-revalidated.');
$check(!preg_match("/if\\s*\\(\\s*\\$"."type\\s*===\\s*['\\\"]sponsored_project['\\\"]/",$chat),
    'The audit does not falsely mark a selected Sponsored Project context as already built.');
$registry=substr($actions,strpos($actions,'function agent_action_capabilities(): array {'),strpos($actions,'function agent_action_capability_prompt(): string {')-strpos($actions,'function agent_action_capabilities(): array {'));
$check(!str_contains($registry,"'sponsored.")&&!str_contains($registry,"'sponsored_research."),
    'Existing Agent action registry has no dedicated Sponsored Project write capabilities.');
$check(str_contains($actions,"function agent_action_confirm_execute(")
  &&str_contains($actions,"project_state_hash")
  &&str_contains($actions,"agent_action_event(")
  &&str_contains($actions,"function agent_action_create_proposals("),
    'Existing Agent action confirmation, stale protection and provenance will be reused.');
$check(str_contains($submission,'research_account_require_approved($pdo,$viewer)')
  &&str_contains($submission,'sponsored_project_assignment(')
  &&str_contains($submission,"agent_submit_enabled")
  &&str_contains($submission,'sponsored_research_participation_get(')
  &&str_contains($submission,'submission_hash'),
    'Canonical submission path requires approved Research Account, assigned Agent, accepted terms, submit gate and hashed assets.');
$check(str_contains($workspace,'(int)$assignment['."'participation_id'".']!==(int)$participation['."'id'".']')
  &&str_contains($workspace,"up.scope='project' OR (up.scope='participant' AND up.participant_user_id=?)"),
    'Workspace enforces matching participation and SQL-level private thread isolation.');
$check(str_contains($automation,'function research_automation_execute(')&&str_contains($automation,'function research_automation_enqueue(')
  &&str_contains($automation,"function research_automation_notify("),
    'Research scheduler and notification dedupe already exist.');
$check(str_contains($notifications,"if(\$type==='sponsored_project_submission')"),
    'Existing Sponsored submission notifications are integrated; no duplicate notification engine.');
$check(!str_contains($notifications,"if(\$type==='sponsored_project')"),
    'Dedicated Sponsored Project notification-object ACL remains a documented future gap.');
$docs=$read('docs/sponsored-agent-integration-4a.md');
$check(str_contains($docs,'**4B — Agent project awareness.**')
  &&str_contains($docs,'**4C — Governed Agent operations.**')
  &&str_contains($docs,'**4D — Proactive review and notifications.**')
  &&str_contains($docs,'**4E — E2E release.**'),
    'Audit specifies bounded next-section responsibilities and avoids duplicate engines.');
$check(!is_file($root.'/database/migrations/20261001_130_sponsored_agent_integration.sql'),
    'Audit-only section has no speculative database migration.');
if($fail){foreach($fail as $x)fwrite(STDERR,"FAIL: ".$x."\n");exit(1);}
echo "PASS: Sponsored Research Agent Integration Section 4A audit contracts and 15 canonical dependencies.\n";
