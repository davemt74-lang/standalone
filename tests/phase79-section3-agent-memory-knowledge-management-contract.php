<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $path,string $needle,string $message)use($root,&$fail){$c=(string)@file_get_contents($root.'/'.$path);if($c===''||!str_contains($c,$needle))$fail[]=$message;};
$avoid=function(string $path,string $needle,string $message)use($root,&$fail){$c=(string)@file_get_contents($root.'/'.$path);if($c!==''&&str_contains($c,$needle))$fail[]=$message;};

$need('database/migrations/20260930_114_agent_memory_knowledge_management.sql','CREATE TABLE IF NOT EXISTS research_memory_controls','Section 3 must persist per-object Agent Memory controls.');
$need('database/migrations/20260930_114_agent_memory_knowledge_management.sql','CREATE TABLE IF NOT EXISTS research_memory_events','Section 3 must keep append-only memory change history.');
$need('database/migrations/20260930_114_agent_memory_knowledge_management.sql','CREATE TABLE IF NOT EXISTS research_memory_usage','Section 3 must keep Agent retrieval usage history.');
$need('app/bootstrap.php',"require_once __DIR__ . '/research-agent-memory.php';",'Agent Memory governance service must load globally.');
$need('app/research-agent-memory.php','function research_memory_upsert','Agent Memory must expose governed updates.');
$need('app/research-agent-memory.php','function research_memory_object_exists','Memory controls must validate objects against the selected Agent Project.');
$need('app/research-agent-memory.php','function research_memory_catalog_count','Memory management must support scalable paged catalogs.');
$need('app/research-agent-memory.php','function research_memory_apply_result','Agent Memory must enforce controls in retrieval.');
$need('app/research-agent-memory.php','function research_memory_controls_map','Agent retrieval must load memory controls in one project-scoped query rather than one query per result.');
$need('app/research-agent-memory.php','function research_memory_record_usage','Agent Memory must record downstream Agent use.');
$need('app/research-agent-memory.php','research_memory_privacy_state','Memory management must expose effective privacy state.');
$need('app/research-agent-memory.php','research_memory_source_kind','Memory management must expose provenance/source category.');
$need('app/research-retrieval.php',"if(function_exists('research_memory_apply_result'))",'Canonical retrieval must enforce Agent Memory controls.');
$need('app/research-retrieval.php',"mc.correction_text LIKE ?",'User corrections must be immediately searchable.');
$need('app/agent-chat.php',"research_memory_record_usage",'Agent Chat must record actual retrieved memory usage.');
$need('app/agent-chat.php','never attribute the correction to the source','Agent Chat must keep user corrections distinct from source evidence.');
$need('api/research-agent-memory.php',"action==='update'",'Agent Memory API must support governed updates.');
$need('api/research-agent-memory.php',"action==='history'",'Agent Memory API must expose audit history.');
$need('research-agent-knowledge.php','AGENT MEMORY','Canonical Knowledge Library must expose Agent Memory management.');
$need('research-agent-knowledge.php','data-memory-state','Knowledge UI must expose retrieval eligibility controls.');
$need('research-agent-knowledge.php','data-memory-correction','Knowledge UI must expose correction controls.');
$need('research-agent-knowledge.php','Last used by Agent','Knowledge UI must expose usage history.');
$need('research-agent-knowledge.php','memory_page','Knowledge UI must page large memory catalogs.');
$need('research-agent-knowledge.php','memory_q','Knowledge UI must search large memory catalogs.');
$need('assets/js/research-agent-memory.js','X-CSRF-Token','Agent Memory mutations must use CSRF-protected API calls.');
$need('assets/css/app.css','/* Phase 79.3 — Agent Memory / Knowledge Management */','Agent Memory must have dedicated responsive UI styling.');
$need('app/research-agent-knowledge-ui.php',"'library'=>",'Existing Library / Insights / Changes canonical model must remain intact.');
$need('app/research-agent-knowledge-ui.php',"'insights'=>",'Existing Insights view must remain intact.');
$need('app/research-agent-knowledge-ui.php',"'changes'=>",'Existing Changes view must remain intact.');
$avoid('app/research-agent-memory.php','CREATE TABLE','Runtime helper must not create schema dynamically.');
$avoid('app/research-agent-memory.php','ALTER TABLE','Runtime helper must not mutate schema dynamically.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 79 Section 3 Agent Memory / Knowledge Management contracts passed.\n";
