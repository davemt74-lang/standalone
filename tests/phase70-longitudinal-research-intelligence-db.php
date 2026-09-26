<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow','research-system-reports','research-report-studio','research-intelligence-delivery','research-longitudinal-intelligence'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p70(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p70throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p70(research_longitudinal_ready($pdo),'Phase 70 longitudinal schema is ready.');
$types=research_system_report_types();
foreach(['research_evolution','what_changed','confidence_contradictions','open_questions_evolution','entity_theme_evolution'] as $type)p70(isset($types[$type]),'Report Studio exposes '.$type.'.');

$run='p70'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('LongitudinalOwner','admin');$outsider=$makeUser('LongitudinalOutsider');

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 70 Research Agent','description'=>'Longitudinal Research fixture.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);p70((bool)$project,'Research Agent project is available.');

$source=ensure_source($pdo,'https://example.com/'.$run.'/longitudinal','Phase 70 Market Source');$text1='Mercury orchard demand increased 18 percent with strong buyer interest.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([(int)$source['id'],'https://example.com/'.$run.'/longitudinal','Phase 70 Market Source',$text1,hash('sha256',$text1)]);
$version1=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$version1,(int)$source['id']]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$claimPublic=$pub('claim');$claimStatement='Mercury orchard demand increased by 18 percent.';
$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,?, 'factual','supported')")
  ->execute([$claimPublic,(int)$project['id'],(int)$owner['id'],$claimStatement]);$claimId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship,note) VALUES(?,?,?,'source_version',?,'primary','Phase 70 baseline evidence')")
  ->execute([$pub('ev'),$claimId,(int)$owner['id'],$version1]);
$findingPublic=$pub('finding');$pdo->prepare("INSERT INTO research_findings(public_id,project_id,created_by_user_id,title,summary,status) VALUES(?,?,?,?,?,'draft')")
  ->execute([$findingPublic,(int)$project['id'],(int)$owner['id'],'Mercury demand is accelerating','Initial evidence supports stronger Mercury orchard demand.']);$findingId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO finding_claims(finding_id,claim_id,added_by_user_id,relationship,position) VALUES(?,?,?,'supports',0)")->execute([$findingId,$claimId,(int)$owner['id']]);

$baseline=research_longitudinal_capture($pdo,$owner,(string)$agent['public_id'],'manual',null);
p70(!empty($baseline['created'])&&($baseline['snapshot']['trigger_type']??'')==='baseline','First capture establishes a baseline snapshot.');
p70(count((array)$baseline['changes'])===0&&count((array)$baseline['milestones'])===0,'Baseline does not mislabel existing Research objects as newly introduced.');
$baselinePublic=(string)$baseline['snapshot']['public_id'];

$duplicate=research_longitudinal_capture($pdo,$owner,(string)$agent['public_id'],'manual',null);
p70(empty($duplicate['created'])&&($duplicate['snapshot']['public_id']??'')===$baselinePublic,'Identical Research state deduplicates to the existing snapshot.');

$text2='Mercury orchard demand increased 26 percent with verified enterprise buyer interest.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,2,?,?,?,?)")
  ->execute([(int)$source['id'],'https://example.com/'.$run.'/longitudinal','Phase 70 Market Source',$text2,hash('sha256',$text2)]);
$version2=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$version2,(int)$source['id']]);
$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship,note) VALUES(?,?,?,'source_version',?,'supports','Additional Phase 70 evidence')")
  ->execute([$pub('ev'),$claimId,(int)$owner['id'],$version2]);
$pdo->prepare('UPDATE research_findings SET summary=?,updated_at=NOW() WHERE id=?')->execute(['New enterprise evidence materially strengthens the Mercury demand finding.',$findingId]);

$second=research_longitudinal_capture($pdo,$owner,(string)$agent['public_id'],'manual',null);
p70(!empty($second['created'])&&count((array)$second['changes'])>=3,'Changed authoritative Research state creates a new snapshot and ledger events.');
$changes=(array)$second['changes'];
p70(count(array_filter($changes,fn($c)=>($c['object_type']??'')==='claim'&&($c['change_type']??'')==='strengthened'))===1,'Additional Claim evidence is classified as strengthened.');
p70(count(array_filter($changes,fn($c)=>($c['object_type']??'')==='source'&&($c['change_type']??'')==='source_updated'))===1,'New Source version is classified as source_updated.');
p70(count(array_filter($changes,fn($c)=>($c['object_type']??'')==='finding'&&($c['change_type']??'')==='changed'))===1,'Finding synthesis change is recorded.');
p70(count((array)$second['milestones'])>=2,'High-value Claim/Source evolution creates durable milestones.');

$latest=research_longitudinal_latest_snapshot($pdo,$owner,(string)$agent['public_id']);
p70($latest&&$latest['public_id']===$second['snapshot']['public_id'],'Latest longitudinal snapshot resolves correctly.');
p70(research_longitudinal_snapshot_access($pdo,$outsider,(string)$latest['public_id'])===null,'Longitudinal snapshot access is permission checked.');

$summary=research_longitudinal_summary($pdo,$owner,(string)$agent['public_id'],'-7 days');
p70(($summary['materiality']['high']??0)>=3&&count((array)$summary['milestones'])>=2,'Longitudinal summary exposes high-materiality changes and milestones.');
$comparison=research_longitudinal_compare_snapshots($pdo,$owner,$baselinePublic,(string)$latest['public_id']);
p70(in_array($claimPublic,(array)($comparison['diff']['claim']['changed']??[]),true),'Snapshot comparison identifies the changed Claim.');
p70(in_array((string)$source['public_id'],(array)($comparison['diff']['source']['changed']??[]),true),'Snapshot comparison identifies the changed Source.');

$context=research_longitudinal_prompt_context($pdo,$owner,(string)$agent['public_id'],'Catch me up on what changed last week.');
p70(str_contains($context,'LONGITUDINAL RESEARCH')&&str_contains($context,'Mercury orchard demand'),'Research Agent catch-up context includes longitudinal changes.');
$contextFromSnapshot=research_longitudinal_prompt_context($pdo,$owner,(string)$agent['public_id'],'What changed since '.$baselinePublic.'?');
p70(str_contains($contextFromSnapshot,'Mercury orchard demand'),'Research Agent can resolve “since this snapshot” longitudinal context.');

$docsBefore=(int)$pdo->query("SELECT COUNT(*) FROM research_workspace_objects WHERE project_id=".(int)$project['id']." AND object_type='document'")->fetchColumn();
$evolution=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'research_evolution','Mercury Research Evolution',false);
p70(empty($evolution['document_public_id'])&&str_contains(strip_tags((string)$evolution['rendered_html']),'Evolution summary'),'Research Evolution processor creates a Report Run, not a Document.');
$whatChanged=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'what_changed','Mercury What Changed',false);
p70(empty($whatChanged['document_public_id'])&&str_contains(strip_tags((string)$whatChanged['rendered_html']),'Material changes'),'What Changed processor uses longitudinal ledger data.');
$docsAfter=(int)$pdo->query("SELECT COUNT(*) FROM research_workspace_objects WHERE project_id=".(int)$project['id']." AND object_type='document'")->fetchColumn();
p70($docsBefore===$docsAfter,'Longitudinal Report Runs never create Research Documents automatically.');
$afterReports=research_longitudinal_capture($pdo,$owner,(string)$agent['public_id'],'manual',null);
p70(empty($afterReports['created']),'Running longitudinal reports does not manufacture longitudinal history or alter current state.');

$items=[];research_longitudinal_cognitive_observations($pdo,$owner,$items,20);
p70(count(array_filter($items,fn($i)=>($i['type']??'')==='research_longitudinal_change'))>=1,'High-materiality longitudinal changes surface in existing Now/cognitive feed.');

$program=research_program_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],'title'=>'Phase 70 Intelligence Program','objective'=>'Track Mercury evolution over time.','cadence'=>'daily','timezone_name'=>'UTC','run_time_local'=>'09:00',
  'priority'=>'medium','quiet_mode'=>'material_only','materiality_threshold'=>'important','deliverable_type'=>'weekly_report','monthly_run_limit'=>50
]);
$program=research_program_access($pdo,$owner,(string)$program['public_id']);
$pdo->prepare('UPDATE research_findings SET summary=?,updated_at=NOW() WHERE id=?')->execute(['A third evidence pass changes the Mercury interpretation again.',$findingId]);
$runPublic=research_program_enqueue($pdo,$program,(int)$owner['id'],'manual');p70($runPublic!==null,'Program fixture run can be enqueued.');
$programRun=research_program_run_row($pdo,$owner,(string)$runPublic);p70((bool)$programRun,'Program fixture run is accessible.');
$programCapture=research_longitudinal_capture_program($pdo,$program,(int)$programRun['id'],'program_completed');
p70($programCapture&&!empty($programCapture['created'])&&($programCapture['snapshot']['trigger_type']??'')==='program_completed','Existing Research Program lifecycle can capture longitudinal state with run lineage.');
p70(($programCapture['snapshot']['trigger_public_id']??'')===$runPublic,'Program-triggered snapshot records the originating Program run.');
$pdo->prepare("UPDATE research_program_runs SET status='completed',completed_at=NOW() WHERE id=?")->execute([(int)$programRun['id']]);

$snapshots=research_longitudinal_snapshot_list($pdo,$owner,(string)$agent['public_id'],20);
p70(count($snapshots)>=3&&($snapshots[0]['trigger_type']??'')==='program_completed','Snapshot history preserves manual and Program-triggered states.');

$sinceReport=research_longitudinal_since_token($pdo,$owner,(string)$agent['public_id'],(string)$evolution['public_id']);
p70($sinceReport!==null,'Longitudinal context can resolve an accessible Report Run as a historical “since” anchor.');

echo "Phase 70 Longitudinal Research Intelligence & Synthesis database journey passed.\n";
