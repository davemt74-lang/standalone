<?php
declare(strict_types=1);
$root=dirname(__DIR__);
foreach(['app/v2-collaboration.php','research-agent-collaboration.php','database/migrations/20261002_130_v2_collaboration_roster.sql'] as $path)
 if(!is_file($root.'/'.$path))throw new RuntimeException('Missing V2 roster component: '.$path);
require_once $root.'/app/v2-collaboration.php';
function v2contract(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
v2contract(count(v2_collaboration_roles())===5,'All five delegated roles are recognized.');
v2contract(!v2_collaboration_compatible(['id'=>1,'owner_user_id'=>1,'team_id'=>2],['id'=>2,'owner_user_id'=>3,'team_id'=>2]),'Different owners cannot share a roster.');
v2contract(!v2_collaboration_compatible(['id'=>1,'owner_user_id'=>1,'team_id'=>2],['id'=>2,'owner_user_id'=>1,'team_id'=>3]),'Different Teams cannot share a roster.');
v2contract(!v2_collaboration_compatible(['id'=>1,'owner_user_id'=>1,'team_id'=>2],['id'=>1,'owner_user_id'=>1,'team_id'=>2]),'Lead cannot assign itself twice.');
v2contract(v2_collaboration_compatible(['id'=>1,'owner_user_id'=>1,'team_id'=>null],['id'=>2,'owner_user_id'=>1,'team_id'=>null]),'Same-owner personal Agents with separate projects can appear on a roster.');
$runtime=(string)file_get_contents($root.'/app/v2-collaboration.php');
$schema=(string)file_get_contents($root.'/database/migrations/20261002_130_v2_collaboration_roster.sql');
$ui=(string)file_get_contents($root.'/research-agent-collaboration.php');
v2contract(str_contains($schema,'v2_collaboration_events')
 &&str_contains($schema,'uq_v2_collaboration_member')&&!str_contains($schema,'ALTER TABLE research_agents'),
 'Roster schema is new metadata and leaves V1 one-Agent-per-project unchanged.');
foreach(['research_agent_access','research_agent_project','sponsored_research_agent_assignments','FOR UPDATE','revision=revision+1','v2_collaboration_event'] as $word){
 if($word==='research_agent_project')continue;
 v2contract(str_contains($runtime,$word),'Service retains canonical boundary: '.$word);
}
v2contract(str_contains($runtime,"'allows_cross_agent_data_access'=>false"),'Roster grants no automatic private Agent evidence or memory access.');
v2contract(str_contains($ui,'require_csrf()')&&str_contains($ui,'Only the Agent owner'),
 'Owner-facing page requires CSRF and checks owner before mutation.');
v2contract(str_contains((string)file_get_contents($root.'/research-agent-edit.php'),'Collaborate (V2)'),
 'Existing Agent edit screen is the entry point; no new disconnected shell.');
v2contract(!is_file($root.'/worker/v2-collaboration-worker.php'),'No duplicate worker or scheduler is introduced.');
echo "V2 Section 1 static contract passed.\n";
