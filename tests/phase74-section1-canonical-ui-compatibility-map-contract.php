<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/research-surface-map.php';

function p74s1_fail(array &$fail,string $message): void {$fail[]=$message;}
function p74s1_keys(array $model): array {
    $keys=[];foreach($model as $group)foreach($group as $key=>$row)$keys[$key]=true;return array_keys($keys);
}
function p74s1_relative(string $root,string $path): string {return str_replace('\\','/',substr($path,strlen($root)+1));}

$fail=[];$map=research_surface_map();$allowed=research_surface_classifications();
if(($map['version']??'')!=='phase74.section1')p74s1_fail($fail,'Research surface map version must be phase74.section1.');
$canonical=p74s1_keys((array)($map['canonical_ui']??[]));$canonicalSet=array_fill_keys($canonical,true);

foreach(['agent.chat','agent.knowledge','agent.research','agent.reports','research.home','portfolios','attention'] as $key)
    if(!isset($canonicalSet[$key]))p74s1_fail($fail,'Missing canonical UI key '.$key.'.');
$agentTop=[];foreach((array)($map['canonical_ui']['agent']??[]) as $key=>$row)if(substr_count($key,'.')===1)$agentTop[]=$key;
sort($agentTop);$expectedTop=['agent.chat','agent.knowledge','agent.reports','agent.research'];sort($expectedTop);
if($agentTop!==$expectedTop)p74s1_fail($fail,'Agent primary UI must be exactly Chat, Knowledge, Research, Reports.');

$routeMap=(array)($map['routes']??[]);
$rootPages=[];foreach(glob($root.'/*.php')?:[] as $file){$base=basename($file);if(preg_match('/^(research|cross-research|report|evidence|source).*\.php$/',$base))$rootPages[]=$base;}
sort($rootPages);$mappedRoutes=array_keys($routeMap);sort($mappedRoutes);
if($rootPages!==$mappedRoutes){
    $missing=array_values(array_diff($rootPages,$mappedRoutes));$extra=array_values(array_diff($mappedRoutes,$rootPages));
    if($missing)p74s1_fail($fail,'Unmapped Research root pages: '.implode(', ',$missing));
    if($extra)p74s1_fail($fail,'Research route map references missing pages: '.implode(', ',$extra));
}
foreach($routeMap as $path=>$row){
    $classification=(string)($row['classification']??'');if(!in_array($classification,$allowed,true))p74s1_fail($fail,$path.' has invalid classification '.$classification.'.');
    if($classification==='KEEP_ENGINE')p74s1_fail($fail,$path.' is a page and must not be classified KEEP_ENGINE.');
    $target=(string)($row['target']??'');if($target===''||!isset($canonicalSet[$target]))p74s1_fail($fail,$path.' has invalid canonical target '.$target.'.');
    if(trim((string)($row['reason']??''))==='')p74s1_fail($fail,$path.' must explain its consolidation decision.');
}

$engineMap=(array)($map['engines']??[]);$engineFiles=[];
foreach(glob($root.'/app/*.php')?:[] as $file){$rel=p74s1_relative($root,$file);if(preg_match('/(research|cognitive|proactive|living-research|cross-research)/i',$rel))$engineFiles[]=$rel;}
sort($engineFiles);$mappedEngines=array_keys($engineMap);sort($mappedEngines);
if($engineFiles!==$mappedEngines){
    $missing=array_values(array_diff($engineFiles,$mappedEngines));$extra=array_values(array_diff($mappedEngines,$engineFiles));
    if($missing)p74s1_fail($fail,'Unmapped Research engine modules: '.implode(', ',$missing));
    if($extra)p74s1_fail($fail,'Research engine map references missing modules: '.implode(', ',$extra));
}
foreach($engineMap as $path=>$row){
    if(($row['classification']??'')!=='KEEP_ENGINE')p74s1_fail($fail,$path.' must remain KEEP_ENGINE in Section 1.');
    if(trim((string)($row['domain']??''))==='')p74s1_fail($fail,$path.' must declare its canonical UI domain.');
}

$apiMap=(array)($map['apis']??[]);$apiFiles=[];
foreach(glob($root.'/api/*.php')?:[] as $file){$rel=p74s1_relative($root,$file);if(preg_match('/(research|cognitive|proactive|living-research|cross-research)/i',$rel))$apiFiles[]=$rel;}
sort($apiFiles);$mappedApis=array_keys($apiMap);sort($mappedApis);
if($apiFiles!==$mappedApis){
    $missing=array_values(array_diff($apiFiles,$mappedApis));$extra=array_values(array_diff($mappedApis,$apiFiles));
    if($missing)p74s1_fail($fail,'Unmapped Research APIs: '.implode(', ',$missing));
    if($extra)p74s1_fail($fail,'Research API map references missing routes: '.implode(', ',$extra));
}
foreach($apiMap as $path=>$row){
    if(($row['classification']??'')!=='KEEP_ENGINE')p74s1_fail($fail,$path.' API contract must remain KEEP_ENGINE in Section 1.');
    if(!array_key_exists('compatibility_only',$row))p74s1_fail($fail,$path.' must explicitly declare compatibility_only.');
}

foreach((array)($map['database_concepts']??[]) as $key=>$row){
    if(($row['classification']??'')!=='KEEP_ENGINE')p74s1_fail($fail,'Database concept '.$key.' must remain KEEP_ENGINE.');
    if(trim((string)($row['surface']??''))==='')p74s1_fail($fail,'Database concept '.$key.' must name its simplified surface.');
    if(trim((string)($row['rule']??''))==='')p74s1_fail($fail,'Database concept '.$key.' must document its preservation rule.');
    foreach((array)($row['modules']??[]) as $module)if(!is_file($root.'/'.$module))p74s1_fail($fail,'Database concept '.$key.' references missing module '.$module.'.');
}
foreach((array)($map['aliases']??[]) as $key=>$row){
    $target=(string)($row['target']??'');if(!isset($canonicalSet[$target]))p74s1_fail($fail,'Concept alias '.$key.' has invalid target '.$target.'.');
    if(trim((string)($row['rule']??''))==='')p74s1_fail($fail,'Concept alias '.$key.' must document its compatibility rule.');
}

$specific=[
    'research.php'=>['MERGE_UI','research.home'],
    'research-agent-knowledge.php'=>['MERGE_UI','agent.knowledge'],
    'research-automations.php'=>['LEGACY_ROUTE','agent.research.recurring'],
    'research-outcomes.php'=>['LEGACY_ROUTE','agent.research.decisions'],
    'research-portfolio.php'=>['LEGACY_ROUTE','research.home'],
    'research-intelligence-portfolios.php'=>['MERGE_UI','portfolios'],
    'research-intelligence-command-center.php'=>['LEGACY_ROUTE','portfolios.overview'],
    'research-reports.php'=>['MERGE_UI','agent.reports'],
    'research-publications.php'=>['MERGE_UI','agent.reports.published'],
    'research-reviews.php'=>['LEGACY_ROUTE','attention'],
];
foreach($specific as $path=>[$classification,$target]){
    if(($routeMap[$path]['classification']??'')!==$classification||($routeMap[$path]['target']??'')!==$target)
        p74s1_fail($fail,$path.' violates the canonical consolidation decision.');
}
foreach(['api/research-automations.php','api/research-outcomes.php','api/research-portfolio.php'] as $path)
    if(empty($apiMap[$path]['compatibility_only']))p74s1_fail($fail,$path.' must be explicitly compatibility-only.');

if(!is_file($root.'/docs/phase-74-research-agent-simplification.md'))p74s1_fail($fail,'Phase 74 architecture document is missing.');
else{
    $doc=(string)file_get_contents($root.'/docs/phase-74-research-agent-simplification.md');
    foreach(['Chat | Knowledge | Research | Reports','KEEP ENGINE','MERGE UI','HIDE','LEGACY ROUTE','No destructive data migration','Phase 74 Section 2 — Unified Research Agent Shell'] as $needle)
        if(!str_contains($doc,$needle))p74s1_fail($fail,'Phase 74 architecture document missing '.$needle.'.');
}

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo 'Phase 74 Section 1 canonical UI & compatibility map passed: '.count($routeMap).' pages, '.count($engineMap).' engines, '.count($apiMap).' APIs, '.count($map['database_concepts'])." database concepts classified.\n";
