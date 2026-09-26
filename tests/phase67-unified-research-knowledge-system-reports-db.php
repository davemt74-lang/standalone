<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow','research-system-reports'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p67(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p67throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p67(research_system_reports_ready($pdo),'Phase 67 System Reports schema is ready.');
$types=research_system_report_types();p67(count($types)===9&&isset($types['research_brief'],$types['full_intelligence']),'Phase 67 ships the complete nine-report system catalog.');

$run='p67'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('KnowledgeOwner','admin');$outsider=$makeUser('KnowledgeOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Unified Knowledge Agent','description'=>'Test the complete Research knowledge and reporting loop.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);p67($project!==null,'Research Agent project is available.');

$source=ensure_source($pdo,'https://example.com/'.$run.'/market','Phase 67 Market Source');$sourceText='Mercury orchard demand increased 18 percent while implementation risk remains moderate.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([(int)$source['id'],'https://example.com/'.$run.'/market','Phase 67 Market Source',$sourceText,hash('sha256',$sourceText)]);
$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$versionId,(int)$source['id']]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$claimPublic=$pub('claim');$statement='Mercury orchard demand increased by 18 percent.';
$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,?, 'factual','supported')")
  ->execute([$claimPublic,(int)$project['id'],(int)$owner['id'],$statement]);$claimId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship,note) VALUES(?,?,?,'source_version',?,'primary','Primary market evidence')")
  ->execute([$pub('ev'),$claimId,(int)$owner['id'],$versionId]);

$claim2Public=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,?, 'interpretation','unverified')")
  ->execute([$claim2Public,(int)$project['id'],(int)$owner['id'],'Implementation risk may constrain Mercury Orchard growth.']);$claim2Id=(int)$pdo->lastInsertId();
$claimRelPublic=$pub('claimrel');$pdo->prepare("INSERT INTO claim_relations(public_id,project_id,source_claim_id,target_claim_id,added_by_user_id,relation_type,note) VALUES(?,?,?,?,?,'context','Growth and implementation risk should be evaluated together')")
  ->execute([$claimRelPublic,(int)$project['id'],$claimId,$claim2Id,(int)$owner['id']]);

$findingPublic=$pub('finding');$pdo->prepare("INSERT INTO research_findings(public_id,project_id,created_by_user_id,title,summary,status) VALUES(?,?,?,?,?,'draft')")
  ->execute([$findingPublic,(int)$project['id'],(int)$owner['id'],'Demand is accelerating','Available evidence indicates stronger Mercury orchard demand.']);$findingId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO finding_claims(finding_id,claim_id,added_by_user_id,relationship,position) VALUES(?,?,?,'supports',0)")
  ->execute([$findingId,$claimId,(int)$owner['id']]);

$entity=research_entity_upsert($pdo,(int)$project['id'],(int)$owner['id'],'Mercury Orchard','company','The company referenced by the demand evidence.','manual');
research_entity_attach_reference($pdo,(int)$project['id'],(int)$entity['id'],(int)$owner['id'],'claim',$claimPublic,'Claim mention');
$market=research_entity_upsert($pdo,(int)$project['id'],(int)$owner['id'],'Orchard Market','topic','The market context for Mercury Orchard.','manual');
$entityRelPublic=$pub('entityrel');$pdo->prepare("INSERT INTO research_entity_relations(public_id,project_id,source_entity_id,target_entity_id,added_by_user_id,relation_type,note) VALUES(?,?,?,?,?,'associated_with','Market relationship')")
  ->execute([$entityRelPublic,(int)$project['id'],(int)$entity['id'],(int)$market['id'],(int)$owner['id']]);

$records=research_retrieval_collect_records($pdo,(int)$project['id']);$recordTypes=array_count_values(array_map(fn($x)=>(string)$x['object_type'],$records));
foreach(['source','claim','finding','entity','claim_relation','entity_relation'] as $type)p67(($recordTypes[$type]??0)>=1,'Unified retrieval includes structured '.$type.' knowledge.');
research_retrieval_rebuild_project($pdo,[],(int)$project['id'],$records,false);

$claimSearch=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'18 percent',['type'=>'claim'],10,true);
p67(count(array_filter($claimSearch['results'],fn($x)=>($x['public_id']??'')===$claimPublic))===1,'Research Library search finds a structured Claim.');
$findingSearch=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'accelerating',['type'=>'finding'],10,true);
p67(count(array_filter($findingSearch['results'],fn($x)=>($x['public_id']??'')===$findingPublic))===1,'Research Library search finds a structured Finding.');
$entitySearch=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'Mercury Orchard',['type'=>'entity'],10,true);
p67(count(array_filter($entitySearch['results'],fn($x)=>($x['public_id']??'')===$entity['public_id']))===1,'Research Library search finds a structured Entity.');
$relationSearch=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'associated with',['type'=>'relation'],10,true);
p67(count(array_filter($relationSearch['results'],fn($x)=>($x['public_id']??'')===$entityRelPublic))===1,'Research Library searches knowledge-graph relationships alongside nodes.');

$claimCtx=agent_chat_context_item($pdo,$owner,'claim',$claimPublic);$findingCtx=agent_chat_context_item($pdo,$owner,'finding',$findingPublic);$entityCtx=agent_chat_context_item($pdo,$owner,'entity',(string)$entity['public_id']);
p67($claimCtx&&str_contains((string)$claimCtx['text'],$statement),'Selected Claim becomes authoritative Agent context.');
p67($findingCtx&&str_contains((string)$findingCtx['text'],'Demand is accelerating'),'Selected Finding becomes authoritative Agent context.');
p67($entityCtx&&str_contains((string)$entityCtx['text'],'Mercury Orchard'),'Selected Entity becomes authoritative Agent context.');
$claimRelCtx=agent_chat_context_item($pdo,$owner,'claim_relation',$claimRelPublic);$entityRelCtx=agent_chat_context_item($pdo,$owner,'entity_relation',$entityRelPublic);
p67($claimRelCtx&&str_contains((string)$claimRelCtx['text'],'Relationship: context'),'Selected Claim relationship becomes authoritative Agent context.');
p67($entityRelCtx&&str_contains((string)$entityRelCtx['text'],'Relationship: associated_with'),'Selected Entity relationship becomes authoritative Agent context.');

$report=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'research_brief','Market Research Brief',false);
p67(($report['report_type']??'')==='research_brief'&&!empty($report['document_public_id']),'User can generate a Research Brief as a versioned Research document.');
p67(strlen((string)$report['input_state_hash'])===64&&!empty($report['evidence_refs']),'System Report records a deterministic state hash and authoritative references.');
$doc=research_agent_workspace_object($pdo,$owner,(string)$report['document_public_id'],false);
p67($doc&&($doc['document_type']??'')==='report'&&str_contains((string)$doc['document_plain_text'],'Strongest Findings'),'Generated System Report is a normal Research Doc with rendered report content.');
p67(($doc['parent_title']??'')==='System Reports','System Reports live in the managed Desktop folder.');

research_retrieval_rebuild_project($pdo,[],(int)$project['id'],null,false);
$reportSearch=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'',['type'=>'report'],20,true);
p67(count(array_filter($reportSearch['results'],fn($x)=>($x['public_id']??'')===$report['document_public_id']))===1,'Research Library Reports filter returns generated System Report documents.');

$verification=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'claims_verification','Claims Verification',false);
$full=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'full_intelligence','Full Intelligence',false);
$list=research_system_report_list($pdo,$owner,(string)$agent['public_id'],20);
p67(count($list)>=3&&!empty($verification['document_public_id'])&&!empty($full['document_public_id']),'One Research Agent can create multiple report processors over the same knowledge.');

$clean=agent_action_clean_arguments('research.create_system_report',['report_type'=>'evidence_audit','title'=>'Agent Evidence Audit']);
p67(($clean['report_type']??'')==='evidence_audit','Governed Agent action validates System Report type.');
$GLOBALS['config']=[];
$action=agent_action_execute_capability($pdo,$owner,$project,'research.create_system_report',$clean);
p67(($action['type']??'')==='research_system_report'&&!empty($action['document_public_id']),'Research Agent can create a confirmed System Report through the governed action executor.');

p67(research_system_report_access($pdo,$outsider,(string)$report['public_id'])===null,'System Report access is live permission checked.');
p67throws(fn()=>research_retrieval_search($pdo,[],$outsider,(string)$project['public_id'],'Mercury',[],10,true),'Unified structured retrieval rejects a user outside the project boundary.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_system_report_events WHERE report_id=?');$q->execute([(int)$report['id']]);
p67((int)$q->fetchColumn()>=1,'System Report generation writes an audit event.');
echo "Phase 67 Unified Research Knowledge & System Reports database journey passed.\n";
