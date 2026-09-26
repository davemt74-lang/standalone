<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow','research-system-reports','research-report-studio'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p68(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p68throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p68(research_report_studio_ready($pdo),'Phase 68 Report Studio schema is ready.');
$types=research_system_report_types();p68(count($types)===9&&isset($types['research_brief']['default_depth'],$types['full_intelligence']['sections']),'Nine built-in report definitions expose Studio metadata.');

$run='p68'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ReportStudioOwner','admin');$outsider=$makeUser('ReportStudioOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 68 Research Agent','description'=>'Per-Agent Report Studio fixture.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);p68((bool)$project,'Research Agent project is available.');

$source=ensure_source($pdo,'https://example.com/'.$run.'/report-studio','Phase 68 Market Source');$sourceText='Mercury orchard demand increased 18 percent and evidence quality is strong.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([(int)$source['id'],'https://example.com/'.$run.'/report-studio','Phase 68 Market Source',$sourceText,hash('sha256',$sourceText)]);
$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$versionId,(int)$source['id']]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$claimPublic=$pub('claim');$statement='Mercury orchard demand increased by 18 percent.';
$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,?, 'factual','supported')")
  ->execute([$claimPublic,(int)$project['id'],(int)$owner['id'],$statement]);$claimId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship,note) VALUES(?,?,?,'source_version',?,'primary','Phase 68 primary evidence')")
  ->execute([$pub('ev'),$claimId,(int)$owner['id'],$versionId]);
$findingPublic=$pub('finding');$pdo->prepare("INSERT INTO research_findings(public_id,project_id,created_by_user_id,title,summary,status) VALUES(?,?,?,?,?,'draft')")
  ->execute([$findingPublic,(int)$project['id'],(int)$owner['id'],'Mercury demand is accelerating','The current evidence supports stronger Mercury orchard demand.']);$findingId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO finding_claims(finding_id,claim_id,added_by_user_id,relationship,position) VALUES(?,?,?,'supports',0)")->execute([$findingId,$claimId,(int)$owner['id']]);

$beforeHash=research_system_report_authoritative_corpus_hash($pdo,(int)$project['id']);p68(strlen($beforeHash)===64,'Authoritative Research corpus hash is available before any Report Run.');
$run1=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'research_brief','Mercury Brief',false,null,[
  'depth'=>'quick','focus_query'=>'Mercury','claim_ids'=>[$claimPublic],'finding_ids'=>[$findingPublic],'source_ids'=>[(string)$source['public_id']]
]);
p68(empty($run1['document_public_id'])&&!empty($run1['rendered_html'])&&!empty($run1['sections'])&&!empty($run1['knowledge_manifest']),'Running a predefined report creates a Report Run without a Research Document.');
p68(($run1['parameters']['depth']??'')==='quick'&&($run1['parameters']['focus_query']??'')==='Mercury','Report Run persists Studio parameters.');
p68(($run1['scope']['claim_ids'][0]??'')===$claimPublic,'Report Run persists per-Agent scope.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_workspace_objects WHERE project_id=? AND object_type='folder' AND title='System Reports'");$q->execute([(int)$project['id']);p68((int)$q->fetchColumn()===0,'Running a Report does not create a Desktop document folder.');

research_retrieval_rebuild_project($pdo,[],(int)$project['id'],null,false);
$reportSearch=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'Mercury',['type'=>'report'],20,true);
p68(count(array_filter($reportSearch['results'],fn($x)=>($x['public_id']??'')===$run1['public_id']))===1,'Report Runs are first-class objects in unified Research retrieval.');
$afterRunHash=research_system_report_authoritative_corpus_hash($pdo,(int)$project['id']);p68(hash_equals($beforeHash,$afterRunHash),'Generating a Report Run does not contaminate the authoritative Research corpus hash.');
$fresh=research_report_studio_freshness($pdo,[],$owner,$run1);p68(($fresh['state']??'')==='current','A Report Run remains current after indexing itself.');

$ctx=agent_chat_context_item($pdo,$owner,'report',(string)$run1['public_id']);p68($ctx&&str_contains((string)$ctx['text'],'REPORT RUN'),'Report Run can be handed directly to its Research Agent.');
$map=agent_action_project_map($pdo,$owner,[$ctx]);p68(isset($map[(string)$project['public_id']]),'Governed Agent actions resolve the owning project from Report context.');

$doc=research_report_studio_create_document($pdo,$owner,(string)$agent['public_id'],(string)$run1['public_id'],['strongest_findings']);
p68($doc&&($doc['document_type']??'')==='report'&&str_contains((string)$doc['document_plain_text'],'Strongest Findings')&&!str_contains((string)$doc['document_plain_text'],'Open gaps'),'Create Document can use selected Report sections without mutating the Report Run.');
$docAgain=research_report_studio_create_document($pdo,$owner,(string)$agent['public_id'],(string)$run1['public_id'],[]);
p68(($docAgain['public_id']??'')===($doc['public_id']??''),'Create Document is idempotent for one Report Run.');
$run1=research_system_report_access($pdo,$owner,(string)$run1['public_id']);p68(($run1['document_public_id']??'')===($doc['public_id']??''),'Report Run retains a durable backlink to its derived Research Document.');
research_retrieval_rebuild_project($pdo,[],(int)$project['id'],null,false);
$afterDocHash=research_system_report_authoritative_corpus_hash($pdo,(int)$project['id']);p68(hash_equals($beforeHash,$afterDocHash),'Document created from a Report Run is excluded from authoritative report freshness state.');
$fresh=research_report_studio_freshness($pdo,[],$owner,$run1);p68(($fresh['state']??'')==='current','Creating a Document from a Report does not make its source Report stale.');

$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Phase 68 Intelligence Program','objective'=>'Keep Mercury evidence current.','cadence'=>'manual','timezone_name'=>'UTC','priority'=>'medium','deliverable_type'=>'weekly_report']);
$preset=research_report_studio_preset_save($pdo,$owner,(string)$agent['public_id'],[
  'name'=>'Mercury Weekly Brief','report_type'=>'research_brief','title'=>'Mercury Weekly Intelligence','depth'=>'deep','focus_query'=>'Mercury','program_id'=>$program['public_id'],'claim_ids'=>[$claimPublic]
]);
p68(($preset['program_public_id']??'')===$program['public_id']&&($preset['parameters']['depth']??'')==='deep','Saved preset belongs to the Research Agent and may reference one of its Programs.');
$otherAgent=research_agent_create($pdo,$owner,['name'=>'Other Phase 68 Agent','description'=>'Cross-Agent preset guard.','cadence'=>'manual','timezone_name'=>'UTC']);
p68throws(fn()=>research_report_studio_preset_save($pdo,$owner,(string)$otherAgent['public_id'],['name'=>'Bad Program Link','report_type'=>'research_brief','program_id'=>$program['public_id']]),'A Report preset cannot attach another Research Agent\'s Program.');
$presetRun=research_report_studio_run_preset($pdo,[],$owner,(string)$agent['public_id'],(string)$preset['public_id'],false);
p68(($presetRun['preset_public_id']??'')===$preset['public_id']&&empty($presetRun['document_public_id']),'Running a saved preset creates a new Report Run, not a Document.');

$unrelated=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,?, 'factual','unverified')")
  ->execute([$unrelated,(int)$project['id'],(int)$owner['id'],'An unrelated satellite market claim.']);
$freshScoped=research_report_studio_freshness($pdo,[],$owner,$run1);p68(($freshScoped['state']??'')==='current','Changes outside an explicit Report scope do not make the scoped Report stale.');

$pdo->prepare("UPDATE research_claims SET statement=?,updated_at=NOW() WHERE id=?")->execute(['Mercury orchard demand increased by 24 percent.',$claimId]);
$freshChanged=research_report_studio_freshness($pdo,[],$owner,$run1);p68(($freshChanged['state']??'')==='materially_changed','Underlying Claim changes make the old Report materially changed.');
$run2=research_report_studio_refresh($pdo,[],$owner,(string)$agent['public_id'],(string)$run1['public_id']);
p68(($run2['refreshed_from_public_id']??'')===$run1['public_id']&&empty($run2['document_public_id']),'Refresh creates a new Report Run with lineage and leaves document creation optional.');
$comparison=research_report_studio_compare($pdo,[],$owner,(string)$run1['public_id'],(string)$run2['public_id']);
p68(($comparison['diff']['claims']['changed_count']??0)>=1&&($comparison['diff']['material_change_count']??0)>=1,'Report comparison identifies changed Claims and material Research change.');

$clean=agent_action_clean_arguments('research.create_document_from_report',['report_id'=>$run2['public_id'],'section_keys'=>['strongest_findings']]);
p68(($clean['report_id']??'')===$run2['public_id'],'Agent action validates explicit Document creation from a Report Run.');
$run2ctx=agent_chat_context_item($pdo,$owner,'report',(string)$run2['public_id']);
p68(agent_action_validate_project_arguments($pdo,$owner,$project,'research.create_document_from_report',$clean,(array)$run2ctx['refs']),'Governed Document-from-Report action validates report provenance and project scope.');

p68(research_system_report_access($pdo,$outsider,(string)$run2['public_id'])===null,'Report Run access remains live permission checked.');
research_system_report_archive($pdo,$owner,(string)$run2['public_id'],(string)$agent['public_id']);
research_retrieval_rebuild_project($pdo,[],(int)$project['id'],null,false);
$archived=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'',['type'=>'report'],60,false);
p68(count(array_filter($archived['results'],fn($x)=>($x['public_id']??'')===$run2['public_id']))===0,'Archived Report Runs leave active unified retrieval.');

echo "Phase 68 Research Agent Report Studio database journey passed.\n";
