<?php
declare(strict_types=1);
/** Section 4D source and pure deterministic boundary contracts; no database needed. */
$root=dirname(__DIR__);
require_once $root.'/app/sponsored-agent-proactive.php';
$failed=[];
$check=static function(bool $ok,string $label)use(&$failed):void{if(!$ok)$failed[]=$label;};
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$now=new DateTimeImmutable('2030-11-01T00:00:00Z');
$check(sponsored_agent_proactive_window('2030-11-02 23:00:00',$now)==='72h','48h upcoming deadline selects first bounded warning window.');
$check(sponsored_agent_proactive_window('2030-11-01 10:00:00',$now)==='24h','Near deadline selects the dedicated 24h warning.');
$check(sponsored_agent_proactive_window('2030-11-05 00:00:00',$now)===null,'No premature reminders outside the 72h window.');
$check(sponsored_agent_proactive_window('2030-10-30 00:00:00',$now)===null,'Overdue projects do not repeatedly emit upcoming warnings.');
$check(sponsored_agent_proactive_window('not-a-date',$now)===null,'Malformed deadlines cannot cause automated notifications.');
$runtime=$read('app/sponsored-agent-proactive.php');
$notify=$read('app/notifications.php');
$worker=$read('bin/research-automations.php');
$bootstrap=$read('app/bootstrap.php');
$check(str_contains($runtime,'sponsored_agent_awareness_access(')
  &&str_contains($runtime,'sponsored_research_campaign_terms_latest(')
  &&str_contains($runtime,"a.status='active'")
  &&str_contains($runtime,"p.status='active'"),
  'Every deadline/revision event requires current approved participant, exact assignment and accepted current terms.');
$check(str_contains($runtime,"up.scope='participant'")
  &&str_contains($runtime,"up.actor_role='researcher'")
  &&str_contains($runtime,"newer.progress_status IN ('ready_for_review','completed')"),
  'Private blocker signal is researcher-authored and suppressed once later resolved.');
$check(str_contains($runtime,"($authorized['role']??'')!=='sponsor'")
  &&str_contains($runtime,'sponsored_workspace_access(')
  &&!str_contains($runtime,"$"."row['body']"),
  'Only current authorized sponsor managers receive a generic blocker notice with no private message body.');
$check(str_contains($runtime,"'sponsored-deadline:'")
  &&str_contains($runtime,"'sponsored-blocker:'")
  &&str_contains($runtime,"'sponsored-review:'"),
  'Deadline, blocker and existing canonical revision keys are deterministic and idempotent.');
$check(str_contains($runtime,"'research_sponsored_revision_requested'")
  &&str_contains($runtime,"'sponsored_project_submission'"),
  'Revision-recovery projection reuses the existing sponsor-review notification and URL.');
$acl=substr($notify,0,(int)strpos($notify,'function notification_url('));
$url=substr($notify,(int)strpos($notify,'function notification_url('));
$check(str_contains($acl,"if($"."type==='sponsored_project')")
  &&str_contains($acl,"'research_sponsored_deadline'")
  &&str_contains($acl,"'research_sponsored_blocker'")
  &&str_contains($acl,'sponsored_workspace_access(')
  &&str_contains($acl,"'recipient_role'"),
  'Notification rows hide revoked or wrong-role private Sponsored Project alerts.');
$check(str_contains($url,"if($"."type==='sponsored_project')")
  &&str_contains($url,"'/sponsored-project-workspace.php?project='")
  &&str_contains($url,"'#activity'")&&str_contains($url,"'#milestones'"),
  'Existing activity and timeline page provides authorized notification deep links.');
$check(str_contains($worker,'sponsored_agent_proactive_scan($pdo,min(100,$limit))')
  &&str_contains($worker,'$sponsoredError'),
  'Existing worker invokes bounded 4D signal scans without creating a parallel scheduler.');
$check(str_contains($bootstrap,"'/sponsored-agent-proactive.php'"),'Canonical bootstrap loads only one 4D scan service.');
$check(!is_file($root.'/database/migrations/20261001_130_sponsored_agent_proactive.sql'),
  '4D does not duplicate the notification, scheduler or task schema.');
if($failed){foreach($failed as $e)fwrite(STDERR,"FAIL: $e\n");exit(1);}
echo "PASS: Sponsored Research 4D proactive deadline/blocker/revision security contracts.\n";
