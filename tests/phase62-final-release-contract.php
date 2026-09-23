<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('app/release.php',"const ANNOTATED_RELEASE = 'V1.1';",'62F stable release label must be V1.1.');
$need('app/release.php',"const ANNOTATED_RELEASE_VERSION = '1.1.0';",'62F stable application version must be 1.1.0.');
$need('app/release.php','const ANNOTATED_RELEASE_PHASE = 62;','62F release phase must be 62.');
$need('app/release.php',"const ANNOTATED_RELEASE_CHANNEL = 'stable';",'62F release channel must be stable.');
$need('app/release.php',"const ANNOTATED_EXTENSION_VERSION = '0.36.0';",'62D Chrome component identity remains explicit.');
$need('app/release-operations.php',"ANNOTATED_RELEASE_CHANNEL",'62F release manifest must derive the stable channel from canonical release identity.');
$need('app/release-operations.php',"'release label mismatch'",'62F installed-package validation must reject a mismatched release label.');
$need('app/release-operations.php',"'release channel mismatch'",'62F installed-package validation must reject a mismatched release channel.');
$need('.github/workflows/package-two-zips.yml','release_channel=','62F archived build metadata must preserve the release channel.');
$need('.github/workflows/package-two-zips.yml','latest_migration=','62C archived build metadata must preserve the latest migration.');
$need('extension/manifest.json','"version": "0.36.0"','62D Chrome package must retain its component version.');
$avoid('extension/manifest.json','release candidate','62F stable Chrome description must not call itself a release candidate.');

foreach([
 '.github/workflows/release-v1-1.yml','docs/RELEASE-V1.1.md','docs/phase-62-v1-1-final-release-validation-production-cutover.md',
 'tests/phase62-final-release-contract.php','tests/phase62-v1-1-final-release-db.php','tests/phase62-v1-1-soak-db.php','tests/ci/phase62-upgrade-supported-baselines.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 62 final release file missing: '.$file;

$need('.github/workflows/release-v1-1.yml',"'v1.1.0'",'62F final workflow must use the exact stable V1.1 tag.');
$need('.github/workflows/release-v1-1.yml',"php-version: ['8.1', '8.3']", '62F stable tag must repeat PHP 8.1/8.3 regression.');
$need('.github/workflows/release-v1-1.yml','phase62-upgrade-supported-baselines.php','62C stable tag must rehearse supported database upgrades.');
$need('.github/workflows/release-v1-1.yml','uses: ./.github/workflows/package-two-zips.yml','62F stable tag must reuse the authoritative two-package builder.');

$need('tests/ci/phase62-upgrade-supported-baselines.php','20260922_046_model_improvement_campaigns.sql','62C upgrade matrix must include the V1.1 RC1-era migration baseline.');
$need('tests/ci/phase62-upgrade-supported-baselines.php','20260923_056_research_programs_recurring_intelligence.sql','62C upgrade matrix must include the Programs baseline.');
$need('tests/ci/phase62-upgrade-supported-baselines.php','20260923_058_research_intelligence_portfolios_executive_briefing.sql','62C upgrade matrix must include the Portfolio baseline.');
$need('tests/ci/phase62-upgrade-supported-baselines.php','$again=migration_apply_pending','62C upgrades must prove a second pass is a no-op.');

$need('tests/phase62-v1-1-final-release-db.php','Research Agent → Claim → Task → Program → Research Doc → Review → Publish → Portfolio → Briefing → Decision → Follow-through','62B final integrated lifecycle marker is missing.');
$need('tests/phase62-v1-1-soak-db.php','hundreds of evidence and Claim records','62E seeded-organization scale marker is missing.');
$need('docs/RELEASE-V1.1.md','migration-046 (RC1-era)','62C production runbook must name the tested RC1-era upgrade boundary.');
$need('docs/RELEASE-V1.1.md','There is no separate Portfolio or Phase 62 worker.','62A runbook must preserve scheduler ownership.');
$need('docs/phase-62-v1-1-final-release-validation-production-cutover.md','adds no product subsystem and no database migration','62A must explicitly freeze subsystem/schema growth.');

if(glob($root.'/database/migrations/*_060_*.sql'))$fail[]='62A/62F Phase 62 final release must not introduce migration 060.';
foreach(['worker/phase62-worker.php','worker/release-worker.php','worker/portfolio-intelligence-worker.php'] as $forbidden)if(is_file($root.'/'.$forbidden))$fail[]='62A final cutover must not add a parallel worker: '.$forbidden;

$release=(string)file_get_contents($root.'/app/release.php');$operations=(string)file_get_contents($root.'/app/release-operations.php');
if(str_contains($release,'1.1.0-rc1'))$fail[]='62F canonical release runtime still contains the RC1 application version.';
if(str_contains($operations,"'channel'=>'release_candidate'"))$fail[]='62F release manifest still emits the release_candidate channel.';

$css=(string)file_get_contents($root.'/assets/css/app.css');$extCss=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$extCss)))$fail[]='62D website and extension shared CSS must remain byte-identical.';
foreach(['@media(max-width:720px)','.researchDesktop','.intelligencePortfolioCanvas','.intelligenceCommandCenter'] as $needle)if(!str_contains($css,$needle))$fail[]='62D responsive surface contract missing: '.$needle;

foreach([
 'home.php','research.php','research-programs.php','research-reviews.php','research-publications.php','research-intelligence-portfolios.php',
 'research-intelligence-command-center.php','app/agent-chat.php','app/cognitive-feed.php','app/notifications.php','app/research-agent-workspace.php',
 'app/research-tasks.php','app/research-programs.php','app/research-publishing.php','app/research-intelligence-portfolios.php','app/research-intelligence-operations.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='62D final surface/runtime missing: '.$file;

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 62 V1.1 Final Release static contract passed.\n";
