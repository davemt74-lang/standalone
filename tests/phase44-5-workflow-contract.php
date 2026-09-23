<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fail=[];

$read=function(string $path)use($root,&$fail): string{
    $full=$root.'/'.$path;
    if(!is_file($full)){$fail[]='Missing '.$path;return '';}
    return (string)file_get_contents($full);
};
$need=function(string $content,string $needle,string $message)use(&$fail): void{
    if(!str_contains($content,$needle))$fail[]=$message;
};
$avoid=function(string $content,string $needle,string $message)use(&$fail): void{
    if(str_contains($content,$needle))$fail[]=$message;
};

$ci=$read('.github/workflows/ci.yml');
$full=$read('.github/workflows/full-regression.yml');
$package=$read('.github/workflows/package-two-zips.yml');
$static=$read('tests/ci/run-static-contracts.sh');
$governance=$read('tests/ci/run-model-governance.sh');
$prepare=$read('tests/ci/prepare-current-schema.php');
$regression=$read('tests/ci/run-full-regression.sh');
$docs=$read('docs/phase-44-5-ci-workflow-hardening.md');

$need($ci,'php-js-contracts:','Fast PR preflight must preserve the established php-js-contracts check surface.');
$need($ci,"php-version: ['8.1', '8.3']",'Fast PR preflight must lint on PHP 8.1 and 8.3.');
$need($ci,'bash tests/ci/run-static-contracts.sh','Fast PR preflight must execute static architecture/security contracts.');
$need($ci,'model-governance-integration:','Fast PR CI must include a targeted model-governance integration job.');
$need($ci,'bash tests/ci/run-model-governance.sh','Targeted model-governance integration must use the versioned runner.');
$avoid($ci,'tests/phase6-feeds-db.php','Fast PR CI must not inline the full historical database suite.');
$avoid($ci,'Annotated-Website.zip','Fast PR CI must not package release artifacts.');

$need($governance,'seq 37 99','Targeted governance runner must automatically discover numeric Phase 37+ database suites.');
$need($governance,'tests/phase"${phase}"-*-db.php','Targeted governance runner must discover future phase DB tests without YAML edits.');
$need($governance,'php tests/ci/prepare-current-schema.php','Targeted governance runner must prepare the current service database explicitly.');
$need($prepare,'installer_run','Targeted CI schema preparation must use the production installer/migration path.');
$need($prepare,'installer_pending_migrations','Targeted CI schema preparation must fail if migrations remain pending.');

$need($static,'tests/release-contracts.php','Static runner must retain release contracts.');
$need($static,'tests/security-contracts.php','Static runner must retain security contracts.');
$need($static,'tests/app-shell-contracts.php','Static runner must retain app-shell contracts.');
$need($static,"-name 'phase*-contract.php'",'Static runner must automatically discover phase architecture contracts.');
$need($static,'tests/phase32-unified-continuity-actions.php','Static runner must preserve the historical Phase 32 static contract.');

$need($full,"contains(github.event.pull_request.title, '[phase-gate]')",'Full regression must require the explicit [phase-gate] PR marker.');
$need($full,'bash tests/ci/run-full-regression.sh','Full regression must execute the versioned historical runner.');
$need($full,"php-version: ['8.1', '8.3']",'Full regression must run PHP 8.1 and PHP 8.3.');
$need($full,'mysql8-fresh-install:','Full regression must retain MySQL 8 fresh-install compatibility.');
$need($full,'needs: [full-regression, mysql8-fresh-install]','Packaging must depend on both full regression and MySQL 8 validation.');
$need($full,'uses: ./.github/workflows/package-two-zips.yml','Full regression must call the reusable two-package builder.');

$need($package,'workflow_call:','Two-package build must be reusable and downstream-only.');
$avoid($package,'pull_request:','Two-package build must not run independently on every pull-request update.');
$avoid($package,'!=="0.36.0"','Packaging must not hardcode a single Chrome extension version.');
$need($package,'manifest_version','Packaging must validate the Chrome Manifest V3 package.');
$need($package,'BUILD-METADATA.txt','Release package artifacts must carry validated-build metadata.');
$need($package,'SHA256SUMS.txt','Release packages must publish SHA-256 checksums.');

$historicalDbTests=[
    'tests/install-db.php','tests/integration-mariadb.php','tests/integration-security-db.php',
    'tests/research-knowledge-db.php','tests/research-intelligence-db.php','tests/research-entities-db.php',
    'tests/research-reports-db.php','tests/rich-capture-db.php',
    'tests/phase6-feeds-db.php','tests/phase7-public-discovery-db.php','tests/phase8-live-db.php',
    'tests/phase9-trust-db.php','tests/phase10-search-db.php','tests/phase11-release-db.php',
    'tests/phase12a-conversations-db.php','tests/phase12b-agent-chat-db.php',
    'tests/phase13-annotation-intelligence-db.php','tests/phase14-research-workspace-db.php',
    'tests/phase15-agent-driven-research-db.php','tests/phase16-cognitive-feed-db.php',
    'tests/phase17-proactive-intelligence-db.php','tests/phase18-research-automation-db.php',
    'tests/phase19-cross-research-db.php','tests/phase20-outcomes-db.php',
    'tests/phase21-research-reviews-db.php','tests/phase22-change-impact-db.php',
    'tests/phase23-research-portfolio-db.php','tests/phase24-living-research-db.php',
    'tests/phase25-research-network-db.php','tests/phase26-research-provenance-db.php',
    'tests/phase27-research-verification-db.php','tests/phase28-research-evidence-packs-db.php',
    'tests/phase29-research-workflow-db.php','tests/phase30-research-completion-db.php',
    'tests/research-v1-final-audit-db.php','tests/phase31-unified-object-handoff-db.php',
    'tests/phase33-unified-activity-db.php','tests/phase34-workspace-context-db.php',
    'tests/phase35-action-center-db.php','tests/phase36-end-to-end-workspace-journey-db.php',
    'tests/phase37-data-attribution-db.php','tests/phase38-dataset-registry-db.php',
    'tests/phase39-dataset-evaluation-db.php','tests/phase40-model-registry-db.php',
    'tests/phase41-training-registry-db.php','tests/phase42-post-training-readiness-db.php',
    'tests/phase43-model-release-decision-db.php','tests/phase44-model-deployment-db.php',
    'tests/phase45-model-observability-db.php','tests/phase46-model-improvement-db.php','tests/phase47-model-campaign-db.php','tests/phase48-closed-loop-release-audit-db.php','tests/phase49-release-candidate-ops-db.php','tests/phase49-5-recent-build-hardening-db.php','tests/phase50-research-agent-workspace-db.php',
];
foreach($historicalDbTests as $test){
    if(!str_contains($regression,$test))$fail[]='Full regression dropped historical coverage: '.$test;
}

$need($docs,'PR preflight','Phase 44.5 documentation must define the fast preflight tier.');
$need($docs,'Full regression phase gate','Phase 44.5 documentation must define the explicit full-regression gate.');
$need($docs,'Packaging — downstream of green full regression','Phase 44.5 documentation must define downstream-only packaging.');
$need($docs,'zero file differences','Phase 44.5 documentation must preserve post-merge tree verification.');

if($fail){
    foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");
    exit(1);
}
echo "Phase 44.5 Development Workflow & CI Hardening contract passed.\n";
