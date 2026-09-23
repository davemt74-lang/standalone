<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach([
 'app/release-operations.php','bin/release-manifest.php','bin/release-backup.php','bin/release-backup-verify.php','bin/release-restore-plan.php',
 'tests/ci/package-smoke.sh','tests/phase49-release-candidate-ops-db.php','tests/phase49-release-candidate-ops-contract.php',
 'docs/phase-49-release-candidate-operational-hardening.md','docs/RELEASE-V1.1-RC1.md'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 49 file missing: '.$file;

if(is_file($root.'/database/migrations/20260922_047_release_candidate_operational_hardening.sql'))$fail[]='Phase 49 must not introduce a database migration.';

$need('app/release.php',"const ANNOTATED_RELEASE = 'V1.1';",'V1.1 final cutover must expose the stable release identity.');
$need('app/release.php',"const ANNOTATED_RELEASE_VERSION = '1.1.0';",'V1.1 final cutover must expose application version 1.1.0.');
$need('app/release.php','const ANNOTATED_RELEASE_PHASE = 62;','V1.1 final cutover must identify Phase 62.');
$need('app/release.php',"const ANNOTATED_EXTENSION_VERSION = '0.36.0';",'Phase 49 must retain the unchanged Chrome 0.36.0 identity.');
$need('app/release.php','function release_worker_specs','Release worker schedule must have one canonical definition.');
$need('app/release.php',"'evaluation'=>['command'=>'php bin/evaluation-worker.php'",'Evaluation worker must be release-critical.');
$need('app/release.php',"'evaluation'=>'data_evaluation_runs'",'Evaluation queue health must be visible in release operations.');
$need('bin/evaluation-worker.php','release_worker_heartbeat($pdo,\'evaluation\',\'starting\'','Evaluation worker must emit a starting heartbeat.');
$need('bin/evaluation-worker.php','release_worker_heartbeat($pdo,\'evaluation\',$status','Evaluation worker must emit completion/failure heartbeat.');

foreach(['release_manifest_data','release_package_fingerprint','release_database_target','release_backup_destination_assert','release_backup_requirements','release_backup_manifest_write','release_backup_manifest_verify','release_restore_plan','release_operational_audit'] as $fn)
    $need('app/release-operations.php','function '.$fn,'Phase 49 release operations helper missing: '.$fn);
$need('app/release-operations.php','Backup output must be outside the Annotated application/web tree.','Release backups must be blocked from the application/web tree.');
$need('app/release-operations.php',"'schema'=>'annotated.release-backup.v1'",'Release backup manifest must have a stable schema identifier.');
$need('app/release-operations.php',"hash_file('sha256'",'Backup verification must use SHA-256.');
$need('app/release-operations.php',"'execution'=>'manual_confirmation_required'",'Restore planning must remain a human-controlled destructive operation.');
$avoid('app/release-operations.php','DROP DATABASE','Phase 49 release operations must not execute destructive database restore SQL.');
$avoid('app/release-operations.php','TRUNCATE ','Phase 49 release operations must not perform destructive data mutation.');

$need('bin/release-backup.php','--single-transaction','Database backup must request a transaction-consistent dump.');
$need('bin/release-backup.php','--no-tablespaces','Database backup must avoid unnecessary tablespace privilege requirements.');
$need('bin/release-backup.php','0600','Database client credential file must be mode 0600.');
$need('bin/release-backup.php','release_backup_manifest_verify','Backup creation must verify the completed backup before reporting success.');
$need('bin/release-backup-verify.php','release_backup_manifest_verify','Backup verification must be independently callable.');
$need('bin/release-restore-plan.php','release_restore_plan','Restore planning must verify backup integrity before showing recovery steps.');
$avoid('bin/release-restore-plan.php','proc_open','Restore planner must not execute destructive restore commands.');

$need('admin/system-health.php','release_operational_audit','System Health must use the Phase 49 operational audit.');
$need('admin/system-health.php','Package fingerprint','System Health must expose the release package fingerprint.');
$need('admin/system-health.php','Evaluation, Training, and Post-Training workers','System Health release procedure must name model pipeline workers.');
$need('bin/release-preflight.php','release_operational_audit','CLI preflight must use the same Phase 49 operational audit.');
$need('bin/release-preflight.php','Fingerprint:','CLI preflight must expose package identity.');

$need('.github/workflows/package-two-zips.yml','RELEASE-MANIFEST.json','Authoritative package must contain the generated release manifest.');
$need('.github/workflows/package-two-zips.yml','package-website/extension/manifest.json','Server package must retain the canonical extension manifest needed to recompute release identity.');
$need('.github/workflows/package-two-zips.yml','tests/ci/package-smoke.sh','Authoritative package build must run the Phase 49 smoke test.');
$need('.github/workflows/package-two-zips.yml','bin/release-backup.php','Authoritative server ZIP must include backup tooling.');
$need('tests/ci/package-smoke.sh','Production config.php must never ship','Package smoke must reject production config.php.');
$need('tests/ci/package-smoke.sh','Embedded Chrome ZIP differs from standalone Chrome ZIP.','Package smoke must compare embedded and standalone Chrome ZIPs.');
$need('tests/ci/package-smoke.sh','1.1.0','Package smoke must validate V1.1 final identity.');
$need('tests/ci/package-smoke.sh','Phase 49 release package smoke test passed.','Package smoke must report its explicit Phase 49 gate.');

$need('.github/workflows/release-rc.yml',"'v1.1.0-rc*'",'RC workflow must use the V1.1 RC tag family.');
$avoid('.github/workflows/release-rc.yml',"'v1.0.0-rc*'",'Old V1.0 RC tag family must not remain authoritative.');
$need('.github/workflows/release-rc.yml',"php-version: ['8.1', '8.3']", 'RC tag workflow must repeat the PHP 8.1/8.3 full-regression matrix.');
$need('.github/workflows/release-rc.yml','bash tests/ci/run-full-regression.sh','RC tag workflow must run the complete historical regression before packaging.');
$need('.github/workflows/release-rc.yml','php tests/install-db.php','RC tag workflow must repeat the MySQL 8 fresh-install gate.');
$need('.github/workflows/release-rc.yml','uses: ./.github/workflows/package-two-zips.yml','RC tag workflow must use the same hardened reusable packager as the PR phase gate.');
$need('.github/workflows/release-rc.yml','git merge-base --is-ancestor','RC tag workflow must prove the tagged commit belongs to the tested development lineage.');
$need('docs/RELEASE-V1.1-RC1.md','php bin/release-backup.php --dry-run --json','Operator runbook must include backup preflight.');
$need('docs/RELEASE-V1.1-RC1.md','php bin/release-backup-verify.php','Operator runbook must include independent backup verification.');
$need('docs/RELEASE-V1.1-RC1.md','php bin/evaluation-worker.php','Operator runbook must include the Evaluation worker.');
$need('docs/RELEASE-V1.1-RC1.md','Phase 49 release-candidate baseline','Operator runbook must identify Phase 49 as the RC baseline.');

$need('tests/ci/run-full-regression.sh','tests/phase49-release-candidate-ops-db.php','Full regression must include Phase 49 operational DB coverage.');
$need('tests/phase44-5-workflow-contract.php','tests/phase49-release-candidate-ops-db.php','Workflow hardening must protect Phase 49 full-regression coverage.');
$need('tests/v1-rc-e2e-contract.php',"V1.1 RC1",'Legacy V1 RC contract must be updated to the V1.1 RC identity.');
$need('tests/v1-rc-e2e-contract.php',"1.1.0-rc1",'Legacy V1 RC contract must validate the current application version.');

$doc=(string)file_get_contents($root.'/docs/phase-49-release-candidate-operational-hardening.md');
foreach(['operations/hardening phase, not a new product','V1.1 RC1','Backup output is explicitly blocked','does **not** execute a destructive restore automatically','package smoke verification'] as $needle)
    if(!str_contains($doc,$needle))$fail[]='Phase 49 documentation boundary missing: '.$needle;

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 49 Release Candidate Deployment & Operational Hardening contract passed.\n";
