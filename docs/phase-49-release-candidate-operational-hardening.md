# Phase 49 — Release Candidate Deployment & Operational Hardening

Phase 49 turns the Phase 48 release-candidate architecture into an operable V1.1 RC deployment.

It is an operations/hardening phase, not a new product or model-governance subsystem.

## Canonical release identity

Phase 49 normalizes the application release identity to:

- Release: **V1.1 RC1**
- Application version: **1.1.0-rc1**
- Release phase: **49**
- Chrome extension: **0.36.0**

The Chrome version remains 0.36.0 because Phases 37–49 changed server-side governance and release operations rather than Chrome functionality.

The package build generates a `RELEASE-MANIFEST.json` from the canonical PHP release constants and the actual Chrome Manifest V3 file. The manifest also records:

- latest bundled migration,
- supported database families,
- minimum PHP version,
- package fingerprint,
- exact CI build SHA when available.

## Operational readiness

Admin → **System Health** and `php bin/release-preflight.php` now use the same Phase 49 operational audit.

Release readiness includes:

- PHP 8.1+,
- HTTPS production base URL,
- database connectivity,
- zero pending migrations,
- writable private evidence storage outside the web root,
- strong application encryption key,
- Chrome extension identity,
- media tools,
- configured optional providers,
- required worker heartbeats,
- backup tooling,
- `config.php` filesystem permissions.

The operational audit is read-only.

## Canonical worker schedule

One worker specification now drives System Health and release documentation.

Required workers:

- Media — `php worker/media-worker.php`
- Transcription — `php worker/transcription-worker.php`
- Source Monitor — `php worker/source-monitor-worker.php`
- AI — `php worker/ai-worker.php`
- Saved Search — `php worker/saved-search-worker.php`
- Research Automation — `php bin/research-automations.php --limit=25`
- Evaluation — `php bin/evaluation-worker.php`
- Training — `php bin/training-worker.php`
- Post-Training — `php bin/post-training-worker.php`

Phase 49 closes the missing Evaluation-worker heartbeat gap. Evaluation queue state is also included in release queue health.

## Backup tooling

`php bin/release-backup.php` creates one matched release backup containing:

- `database.sql.gz`
- `private-storage.tar.gz`
- `manifest.json`
- `RESTORE.txt`

The backup command:

- supports MySQL/MariaDB DSNs,
- uses transaction-consistent database dump options,
- keeps database credentials in a temporary mode-0600 client file,
- never writes the password into the backup manifest,
- archives the complete configured private evidence storage,
- computes SHA-256 and byte size for every component,
- writes a deterministic backup identity hash,
- verifies the backup immediately after creation.

Backup output is explicitly blocked from the Annotated application/web tree.

Recommended first command:

`php bin/release-backup.php --dry-run --json`

## Backup verification

`php bin/release-backup-verify.php --backup=/path/to/backup`

Verification independently checks:

- manifest JSON,
- manifest identity hash,
- required backup components,
- SHA-256 checksums,
- recorded byte sizes.

Changing a backup component after creation invalidates verification.

## Restore planning

`php bin/release-restore-plan.php --backup=/path/to/backup`

Phase 49 intentionally does **not** execute a destructive restore automatically.

The restore planner:

- refuses an invalid/tampered backup,
- identifies the configured database/storage targets,
- produces password-free command templates,
- requires the operator to stop traffic/workers first,
- requires database + private-storage restore as a matched pair,
- requires post-restore preflight/smoke validation.

This preserves human control over destructive production recovery.

## Release package smoke test

Every authoritative two-package build now:

1. builds the Chrome ZIP,
2. builds the server ZIP,
3. generates `RELEASE-MANIFEST.json`,
4. extracts both packages into a temporary directory,
5. verifies required release files,
6. verifies the server package does not contain `config.php`, `.git`, or `.github`,
7. verifies the embedded Chrome ZIP is byte-identical to the standalone Chrome ZIP,
8. verifies release/application/extension versions,
9. verifies the latest migration,
10. verifies the package fingerprint format,
11. syntax-checks the release-critical PHP entry points.

Only after the smoke test passes are checksums and build metadata published.

## Installation and upgrade

Phase 49 retains the existing simple deployment model:

### Fresh install

- upload the server ZIP,
- open `install.php`,
- provide site/database information,
- automatically import base schema + every migration,
- create the first administrator,
- run release preflight.

### Existing install

- deploy the exact tested server ZIP,
- preserve production `config.php`,
- open `upgrade.php`,
- apply pending immutable migrations,
- rerun release preflight,
- execute the post-deploy smoke checklist.

Phase 49 adds no migration of its own.

## Failure boundaries

Release operations do not:

- modify model routing,
- change deployment state,
- freeze datasets,
- execute model evaluation/training,
- sign release decisions,
- mutate model lifecycle,
- store secrets in release manifests.

A missing backup prerequisite or required worker can block operational readiness without changing application state.

## Authoritative CI path

The authoritative Phase 49 PR gate remains:

1. fast PHP 8.1/8.3 syntax + static contracts,
2. targeted Phase 37–49 MariaDB chain,
3. `[phase-gate]` full historical PHP 8.1/8.3 regression,
4. MySQL 8 fresh-install test,
5. hardened two-package build,
6. package smoke verification,
7. SHA-256 + build metadata,
8. exact tested-head merge,
9. zero-file-difference verification.

Release tags use the V1.1 RC tag family: `v1.1.0-rc*`.

## Release evidence

Archive these together for each candidate:

- merged commit SHA,
- CI run ID,
- `RELEASE-MANIFEST.json`,
- package fingerprint,
- server ZIP SHA-256,
- Chrome ZIP SHA-256,
- backup ID,
- backup verification result,
- preflight output,
- post-deploy System Health result,
- post-deploy Intelligence Release Audit result.

## Next step

After Phase 49 is green and merged, the next milestone is **V1.1 RC deployment validation** against the actual hosting environment.

That work should focus on real smoke testing, load/performance profiling, backup/restore rehearsal, operator feedback, and release notes—not another broad model-governance layer.
