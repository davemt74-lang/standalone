<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};
$reject=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&str_contains($c,$needle))$fail[]=$message;};

foreach(['app/research-home-ui.php','assets/js/research-home.js','research.php','docs/phase-75-research-agent-daily-experience.md'] as $path)
    if(!is_file($root.'/'.$path))$fail[]='Phase 75 Section 1 file missing '.$path;

foreach([
 'Your research workspace','data-research-home','data-research-recent-grid','data-research-favorites-grid',
 'id="teams"','id="portfolios"','Continue where you left off','+ New Research Agent',
 '/research-intelligence-portfolios.php','research_agent_shell_href'
] as $needle)$need('research.php',$needle,'Research home missing '.$needle.'.');

foreach(['research_agent_chat_feed(','research_task_summary(','research_program_summary(','researchAdvancedTools','Legacy Portfolio','Research Network','Evidence Packs','Review Center'] as $needle)
    $reject('research.php',$needle,'Research home must not retain inventory-era/N+1 surface: '.$needle.'.');

$need('app/research-home-ui.php','function research_home_dashboard','Research Home must use one read-only composition helper.');
$need('app/research-home-ui.php','research_intelligence_portfolio_list','Research Home must reuse canonical Portfolio listing.');
$need('app/research-home-ui.php','research_intelligence_organization_command_center','Research Home must reuse canonical global attention.');
$reject('app/research-home-ui.php','INSERT INTO','Research Home composition must remain read-only.');
$reject('app/research-home-ui.php','UPDATE ','Research Home composition must remain read-only.');
$reject('app/research-home-ui.php','DELETE FROM','Research Home composition must remain read-only.');

foreach(['localStorage','annotated.researchFavorites.v1.','data-research-favorite','aria-pressed','data-research-favorites-section'] as $needle)
    $need('assets/js/research-home.js',$needle,'Research Agent favorites behavior missing '.$needle.'.');
$need('app/shell.php','app_shell_research_agent_dialog','Research Agent creation must continue using the single canonical shell dialog.');
$need('assets/js/research-agent-shell.js',"/api/research-agents.php?action=create",'Research Agent creation must continue using canonical API.');

$need('tests/ci/run-static-contracts.sh','phase75-section1-research-home-agent-launcher-contract.php','Static runner must include Phase 75 Section 1.');
$need('tests/ci/run-full-regression.sh','phase75-section1-research-home-agent-launcher-db.php','Full regression must include Phase 75 Section 1 DB test.');
$need('.github/workflows/package-two-zips.yml','phase75-section1-research-home-agent-launcher-contract.php','Package must include Phase 75 Section 1 contract.');
$need('tests/ci/package-smoke.sh','Phase 75 Section 1 Research Home & Agent Launcher package extensions passed.','Package smoke must validate Phase 75 Section 1.');
if(glob($root.'/database/migrations/*_104_research_home_agent_launcher.sql'))$fail[]='Phase 75 Section 1 must remain schema-free; favorites are UI preference state, not Research authority.';

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 75 Section 1 Research Home & Agent Launcher contracts passed.\n";
