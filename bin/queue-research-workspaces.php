<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/research-workspace.php';

if(!research_workspace_ready($pdo)){fwrite(STDERR,"Run /upgrade.php before queueing Research Workspace Intelligence.\n");exit(1);}
if(!research_workspace_ai_model_configured($pdo)){fwrite(STDERR,"No Research/default AI model is configured. Deterministic Research Workspace intelligence remains available; no AI jobs were queued.\n");exit(2);}
$limit=max(1,min(10000,(int)($argv[1]??1000)));
$q=$pdo->query("SELECT id,public_id,title FROM research_projects WHERE status='active' ORDER BY id ASC LIMIT ".$limit);
$queued=0;$current=0;
foreach($q->fetchAll() as $project){
    if(research_workspace_queue($pdo,(int)$project['id'],6)){$queued++;echo "queued {$project['public_id']} {$project['title']}\n";}
    else{$current++;}
}
echo "Research Workspace backfill complete: {$queued} queued, {$current} already current, limit {$limit}.\n";
