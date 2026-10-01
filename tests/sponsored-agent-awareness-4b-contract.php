<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/sponsored-agent-awareness.php';
$failed=[];
$check=static function(bool $valid,string $description)use(&$failed):void{
    if(!$valid)$failed[]=$description;
};
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$adapter=$read('app/sponsored-agent-awareness.php');
$chat=$read('app/agent-chat.php');
$bootstrap=$read('app/bootstrap.php');
$js=$read('assets/js/agent-chat.js');
$check(str_contains($bootstrap,"'/sponsored-project-workspace.php'")&&str_contains($bootstrap,"'/sponsored-agent-awareness.php'"),
    'Bootstrap loads the existing scoped workspace and its read-only Agent context adapter.');
$check(str_contains($chat,"'sponsored_projects'=>[]")&&str_contains($chat,"if(\$type==='sponsored_project')")&&str_contains($chat,'sponsored_agent_awareness_agent_context('),
    'Native Chat context list, selected project resolver and exact assigned Agent auto-context reuse existing Chat.');
$check(str_contains($js,"['sponsored_projects','Assigned Sponsored Projects']"),
    'Visible Agent Chat picker displays assigned projects, not sponsor-only opportunities.');
$check(str_contains($adapter,"sponsored_workspace_access(")&&str_contains($adapter,"(\$access['role']??'')!=='researcher'"),
    'Only approved accepted assigned researchers pass native project policy.');
$check(str_contains($adapter,"$"."a['participation_id']")&&str_contains($adapter,"$"."p['id']")&&str_contains($adapter,'research_agent_access('),
    'Exact participation and current personal Agent ACL are independently revalidated.');
$check(str_contains($adapter,"!empty(\$agent['team_id'])")&&str_contains($adapter,"!empty(\$agent['project_team_id'])"),
    'Sponsored knowledge cannot enter a Team-shared Agent through this adapter.');
$check(str_contains($adapter,'sponsored_workspace_recent($pdo,$access')&&
    str_contains($read('app/sponsored-project-workspace.php'),'up.participant_user_id=?'),
    'Project updates use existing SQL-level own-thread filter.');
$check(str_contains($adapter,"$"."p['terms_text']")&&str_contains($adapter,"$"."p['terms_hash']")&&
    str_contains($adapter,"$"."p['campaign_revision_accepted']"),
    'Only personally accepted terms, immutable hash and accepted revision enter context.');
$check(str_contains($adapter,'mb_substr(implode(')&&str_contains($adapter,"'read_only'=>true")&&
    !str_contains($adapter,'sponsored_project_submit(')&&!str_contains($adapter,'sponsored_workspace_post('),
    'Context size bounded, read-only, and free of side-effect calls.');
$check(str_contains($adapter,'$stale=$acceptedRevision!==$currentRevision||$termsChanged')&&str_contains($adapter,'requires_reacceptance'),
    'Updated campaign revision is flagged rather than silently accepted.');
$check(str_contains($adapter,'sponsored_research_campaign_versions')&&str_contains($adapter,"$"."c['config_hash']"),'Read model validates stored immutable version lineage against live hash for accepted current revision.');
$check(str_contains($chat,'belongs to a different assigned Research Agent'),'Selected context cannot attach another Agent\'s project to the current Agent conversation.');
$check(!str_contains($js,'innerHTML=item.label'),'Context picker uses textContent to render untrusted project title.');
if($failed){foreach($failed as $e)fwrite(STDERR,"FAIL: $e\n");exit(1);}
echo "PASS: Sponsored Project Agent Awareness Section 4B integration/security contracts.\n";
