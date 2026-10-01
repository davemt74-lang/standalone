<?php
declare(strict_types=1);
/**
 * Section 4E consolidated end-to-end acceptance.
 * The existing 4D DB journey is already the full sponsor -> approved researcher
 * -> exact Agent -> proposal -> explicit confirmation -> immutable submission
 * -> sponsor review -> deduplicated notifications -> revocation journey.
 * Extend that single fixture with independent denied-role / terminal-state
 * and release-invariant assertions instead of copying its schema or runtime.
 */
require __DIR__.'/sponsored-agent-proactive-4d-db.php';
$gate=function(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('4E FAIL: '.$message);
    echo '4E PASS: '.$message.PHP_EOL;
};
$publicId=(string)$campaign['public_id'];
$agentId=(string)$agentPublic;
$outsider=$make('CompOutsider');
$gate(sponsored_agent_awareness_access($pdo,$outsider,$publicId,$agentId)===null,
    'Unapproved outsider cannot attach an accepted Sponsored Project to Agent context.');
$gate(!notification_object_access($pdo,$outsider,$blockNotice)
      &&notification_url($pdo,$outsider,$blockNotice)===null,
    'An unrelated viewer cannot read sponsor-only blocker notice or deep link.');
$gate(sponsored_agent_awareness_access($pdo,$researcher,$publicId,$agentId)===null,
    'Withdrawn researcher has no personal Agent Sponsored Project context.');
$reloaded=sponsored_project_submission_get($pdo,(string)$saved['public_id']);
$gate($reloaded!==null&&$reloaded['status']==='revision_requested',
    'Human sponsor review has a durable revision request on the original immutable submission.');
$gate(count($reloaded['assets'])===1
    &&$reloaded['assets'][0]['asset_type']==='document',
    'Human review and notification recovery preserve the submitted source evidence snapshot.');
$q=$pdo->prepare('SELECT COUNT(*) FROM sponsored_research_project_submissions WHERE assignment_id=?');
$q->execute([(int)$assignment['id']]);
$gate((int)$q->fetchColumn()===1,
    'Repeated confirmation and review preserve a single original submission.');
$q=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND dedupe_key=?');
$q->execute([(int)$researcher['id'],'sponsored-review:'.$saved['public_id'].':revision_requested']);
$gate((int)$q->fetchColumn()===1,
    'Original sponsor revision notification and proactive recovery share exactly one dedupe record.');
$beforeBlocked=(int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type='research_sponsored_deadline'")->fetchColumn();
sponsored_research_campaign_set_status($pdo,$sponsor,$publicId,'paused');
$gate(sponsored_agent_awareness_access($pdo,$researcher,$publicId,$agentId)===null,
    'Paused Sponsored Project cannot restore withdrawn or stale Agent authority.');
$paused=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('2030-12-31 09:59:00',new DateTimeZone('UTC')));
$gate($paused['deadlines']===0,
    'Paused project cannot emit an active-assignment deadline reminder.');
sponsored_research_campaign_set_status($pdo,$sponsor,$publicId,'closed');
$closed=sponsored_agent_proactive_scan($pdo,60,new DateTimeImmutable('2030-12-31 09:59:00',new DateTimeZone('UTC')));
$afterBlocked=(int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type='research_sponsored_deadline'")->fetchColumn();
$gate($closed['deadlines']===0&&$beforeBlocked===$afterBlocked,
    'Closed projects are excluded from reminders and never revive revoked notices.');
$gate(sponsored_project_sample_projects($pdo)!==[]
      &&sponsored_agent_awareness_access($pdo,$outsider,$publicId,$agentId)===null,
    'Read-only admin samples do not grant unrelated Agent authority.');
echo "Sponsored Research 4E consolidated E2E release journey passed.".PHP_EOL;
