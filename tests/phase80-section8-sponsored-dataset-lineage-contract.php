<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_123_phase80_sponsored_dataset_lineage.sql';
foreach(['sponsored_research_dataset_builds','sponsored_research_dataset_build_items','sponsored_research_dataset_events','consent_manifest_hash','usage_grant_hash','source_rights_hash'] as $n)$need($m,$n,'Migration 123 missing '.$n);
foreach(['function sponsored_research_dataset_assemble','function sponsored_research_dataset_consent_snapshot','function sponsored_research_dataset_current_use_status','function sponsored_research_dataset_refresh_knowledge_for_source'] as $n)$need('app/sponsored-research-datasets.php',$n,'Sponsored dataset runtime missing '.$n);
$need('app/data-attribution.php',"'sponsored_knowledge_item'",'Phase 37 must understand Sponsored Knowledge items.');
$need('app/data-attribution.php','separate_sponsored_knowledge_consent_required','Sponsored Knowledge must require separate explicit consent.');
$need('app/data-attribution.php','sponsored_knowledge_explicit_consent','Sponsored Knowledge eligibility must be purpose-specific.');
$need('app/sponsored-research-datasets.php','Administrator Dataset authority is required','Dataset assembly must retain canonical Admin authority.');
$need('app/sponsored-research-datasets.php',"'assembled_into','dataset'",'Knowledge release → Dataset lineage is missing.');
$need('research-sponsored-knowledge.php','Separate reuse consent','Contributor purpose-specific consent UI is missing.');
$need('admin/sponsored-research-datasets.php','Participation never implies training consent','Admin Dataset authority UI must preserve the participation/training boundary.');
$need('app/bootstrap.php',"'/sponsored-research-datasets.php'",'Sponsored dataset runtime must load through bootstrap.');
$need('app/research-surface-map.php',"'app/sponsored-research-datasets.php'=>'governance'",'Sponsored dataset bridge must remain registered as Research governance.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 8 Dataset Integration, Training Consent & Downstream Lineage contract passed.\n";
