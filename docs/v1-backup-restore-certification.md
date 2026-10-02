# Annotated V1 — Backup and Restore Certification

## Existing foundation and audited gaps
Reuse `bin/release-backup.php`, its paired database/private-storage archives, immutable manifest and checksum verification, `release_restore_plan()` and the existing recovery preflight. No new backup scheduler, database migration, Agent task engine or automatic destructive restore is introduced.

Previously the manifest verifier compared hashes and file sizes, but a self-consistent backup with an invalid compressed stream or unsafe tar path/link could still reach the manual restore planner. Checksums detect corruption after creation; they **do not authenticate the backup author**. The canonical restore-plan helper remains non-destructive for compatibility with Phase 49 fixtures. Both shipped operator commands—`bin/release-restore-plan.php` and `bin/v1-restore-certify.php`—now enforce deep archive checks before displaying recovery steps. Backup creation and the independent backup verifier enforce the same checks.

## Correct incomplete-backup sequencing
The existing backup writer keeps `.INCOMPLETE` while creating the database/storage pair. Independent verification must reject this marker. To avoid the old self-verification deadlock, only the backup writer may call the manifest/archive validators with the explicit in-progress flag while it still owns the marker. It removes the marker **only after both checks succeed**. The independently callable verify and restore CLIs use the default strict behavior and reject any incomplete backup. This is tested against real compressed archives in both database jobs.

## V1 certification workflow
1. Run `php bin/release-backup.php --dry-run --json`, confirm dedicated backup directories are **outside** the application and its public webroot, and stop relevant writers or use the documented transactional database dump semantics.
2. Create a real backup using `php bin/release-backup.php --output=/secure/offsite-location --json`; copy it to an operator-controlled storage service that preserves exact bytes and restrictive access.
3. Independently run `php bin/release-backup-verify.php --backup=/path/to/backup --json`. This command now enforces the original manifest/hash gate **and** the deep archive checks before reporting success.
4. Before any restore command, run **`php bin/v1-restore-certify.php --backup=/path/to/backup --json`**. It verifies the original manifest and exact storage basename, checks database gzip stream integrity, performs a full GNU tar listing with absolute path preservation and escaped names, rejects traversal/absolute/ambiguous entries, links and special devices, and then invokes the existing human-confirmation restore planner. An invalid pair provides no restore plan. This tool does **not** execute any database or filesystem restore.
5. Independently rehearse a matched backup in an isolated staging installation using different database and private-storage targets. Never overwrite production while testing. Compare row counts and representative private-file hashes, apply the matching release, verify login/Agent tasks/Chrome/notifications, and record the tested backup ID and restore operator.
6. Only after staging evidence, maintenance window and a new emergency backup should an administrator follow the original manual restore plan on production. Pause web traffic and **every** Annotated worker, keep original immutable backup bytes, and re-check archive integrity immediately before extraction. Restore the database and storage as one pair, deploy the matching source release and run preflight/browser checks before resuming workers.

## Admin Agent authority
The existing Admin Agent may explain the backup prerequisites and give a step-by-step **read-only** recovery plan. It must not generate new SQL/extract files, select a production restore target, restart workers, delete old backups, or perform a paid-project action without an explicit administrator-confirmed workflow. CLI certification must always report `production_restored=false`; a successful archive check is not evidence that production was restored or that disaster recovery meets its target.

## Ten-point section acceptance
1. Existing release backup/verification/planning remain authoritative; no duplicate backup system or migration.
2. New CLI is CLI-only, non-destructive, uses canonical manifest verification, and reports human confirmation.
3. Corrupt or re-signed invalid database gzip is rejected.
4. Tar member paths are restricted to the configured private storage root; traversal/absolute/ambiguous paths are rejected.
5. Symlinks, hard links, devices and special objects are rejected; escaped names are inspected.
6. Read-only certification fails closed on absent/mismatched paired artifacts and unavailable GNU tar/gzip tooling.
7. Real MariaDB and MySQL8 fixtures create verified compressed backup pairs, rehearse isolated storage and SQL replay, and confirm corruption and symlink detection.
8. Admin runbook and agent guidance state that backup integrity is not backup provenance or live disaster-recovery certification.
9. Exact-head PHP 8.1/8.3, MariaDB, MySQL8 and model governance pass with no unnecessary historical reruns.
10. Reviewed PR merged; exact-merge Website/Chrome ZIPs pass archive smoke and SHA-256 checksum/ancestry verification.

Code/release 10/10 requires criteria 1–10. **V1 production recovery certification remains separate** and requires a real matched staging restore plus operator evidence; neither GitHub CI nor this CLI can truthfully certify the live host.
