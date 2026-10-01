<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_122_phase80_sponsored_research_knowledge_bases.sql';
foreach(['sponsored_research_knowledge_bases','sponsored_research_knowledge_items','sponsored_research_knowledge_releases','sponsored_research_knowledge_release_items','sponsored_research_knowledge_events','future_use_allowed','supersedes_item_id','manifest_hash'] as $n)$need($m,$n,'Migration 122 missing '.$n);
foreach(['function sponsored_research_knowledge_base_create','function sponsored_research_knowledge_promote','function sponsored_research_knowledge_release_create','function sponsored_research_knowledge_release_current_use_status','function sponsored_research_knowledge_item_revoke_rights','function sponsored_research_knowledge_item_withdraw'] as $n)$need('app/sponsored-research-knowledge.php',$n,'Sponsored Knowledge runtime missing '.$n);
$need('app/sponsored-research-knowledge.php','Only accepted Sponsored Research can be promoted to Knowledge.','Promotion must require accepted Sponsored Research.');
$need('app/sponsored-research-knowledge.php','Sources remain evidence lineage and cannot be promoted as standalone Knowledge items.','Sources must remain evidence lineage.');
$need('app/sponsored-research-knowledge.php','Knowledge Base release is blocked until every evidence Source has current retrieval rights.','Release must fail closed on Source rights.');
$need('app/sponsored-research-knowledge.php',"'knowledge_item_corrected'",'Correction must preserve a supersession event.');
$need('app/sponsored-research-knowledge.php',"'historical_releases_preserved'=>true",'Rights withdrawal must preserve historical releases.');
$need('app/bootstrap.php',"'/sponsored-research-knowledge.php'",'Sponsored Knowledge runtime must load through bootstrap.');
$need('research-sponsored-knowledge-admin.php','Promote accepted research','Sponsor Knowledge promotion workspace is missing.');
$need('research-sponsored-knowledge.php','Revoke future use','Contributor Knowledge-rights workspace is missing.');
$need('app/research-surface-map.php',"'app/sponsored-research-knowledge.php'=>'knowledge'",'Sponsored Knowledge engine must be registered in Research architecture.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 7 Knowledge Base Versioning & Promotion contract passed.\n";
