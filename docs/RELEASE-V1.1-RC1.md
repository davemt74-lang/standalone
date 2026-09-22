# Annotated V1.1 RC1 Release Runbook

This runbook applies to **Annotated V1.1 RC1 (`1.1.0-rc1`)** and must be executed against the exact release-candidate commit that passed CI and package validation.

## Fresh installation

1. Create an empty MariaDB or MySQL 8 database and grant a database user access to it.
2. Upload the website package and open `/install.php`.
3. Enter the site URL and database credentials; the installer creates `config.php` automatically.
4. Continue installation; the installer imports the base schema and applies all bundled migrations automatically.
5. Create the first administrator when redirected to `/first-admin.php`.
6. No manual SQL import and no bootstrap/setup key are required.
7. Complete the production settings and run `php bin/release-preflight.php`.
8. Confirm Admin → **System Health** and Admin → **Intelligence Release Audit** have no blocking failures.

If the installer detects unrelated or partially initialized tables, it stops rather than modifying that database. Use a fresh empty database for a new install.

## 1. Before deployment

1. Run `php bin/release-preflight.php` and resolve every blocking failure.
2. Run `php bin/release-backup.php --dry-run --json` and confirm backup prerequisites are available.
3. Create a matched database/private-storage backup with `php bin/release-backup.php --output=/private/backup/root`.
4. Verify it independently with `php bin/release-backup-verify.php --backup=/private/backup/root/<backup-directory>`.
5. Generate the destructive restore plan with `php bin/release-restore-plan.php --backup=/private/backup/root/<backup-directory>` and archive it with the release evidence.
6. Record the currently deployed Git commit, package fingerprint, deploy ZIP SHA-256, extension ZIP SHA-256, and backup ID.
7. Confirm the previous known-good deploy package is still available for application rollback.
8. Confirm Admin → **System Health** and **Intelligence Release Audit** report the expected V1.1 RC1 identity and no blocking failures.

Backups must be written **outside the application/web tree**. The Phase 49 backup command enforces this boundary.

## 2. Required worker schedule

Run workers through Supervisor/systemd/cron using the same PHP/config/database environment as the web application.

Suggested minimum cadence:

```text
php worker/media-worker.php                 continuously / at least every minute
php worker/transcription-worker.php         continuously / at least every minute
php worker/source-monitor-worker.php        every 5 minutes
php worker/ai-worker.php                    continuously / at least every minute
php worker/saved-search-worker.php          every 5 minutes
php bin/research-automations.php --limit=25 every 5 minutes
php bin/evaluation-worker.php               every minute while evaluations are queued
php bin/training-worker.php                 every minute while training is queued/running
php bin/post-training-worker.php            every minute while post-training work is queued
```

After starting each worker, reload **Admin → System Health** and verify a fresh heartbeat for every required worker. Evaluation, Training, and Post-Training are release-critical whenever their queues are active.

## 3. Deployment

1. Extract the exact tested release-candidate server deploy ZIP over the application release directory.
2. Preserve production `config.php` and the external private evidence directory.
3. Sign in as an administrator.
4. Open `/upgrade.php` and apply all pending forward-only migrations.
5. Run `php bin/release-preflight.php` again.
6. Verify `RELEASE-MANIFEST.json` reports V1.1 RC1 / `1.1.0-rc1`, Phase 49, the expected package fingerprint, and the exact deploy commit.
7. Verify login, OAuth buttons, extension authorization, evidence playback, source pages, Search, Live, Notifications, Research, claims/moderation, Model Registry, Model Health, Improvement Campaigns, Intelligence Release Audit, and System Health.

## 4. Chrome extension RC

The RC extension package is built from the exact same merged source tree.

Before Chrome Web Store upload:

- confirm Manifest V3
- verify version `0.36.0`
- verify production server is present in the allowed extension-ID configuration
- inspect requested permissions: `sidePanel`, `activeTab`, `scripting`, `storage`, `identity`, `tabCapture`
- confirm first install opens Extension Setup
- confirm server URL validation requires HTTPS except localhost
- connect an account and verify Connected Accounts shows the extension version and expiry
- revoke the session and confirm the sidebar requires reconnect

## 5. V1.1 RC1 end-to-end release gate

Use a non-admin test account:

1. Create/sign in to the account.
2. Complete first-run onboarding.
3. Connect the Chrome sidebar.
4. Open a normal webpage and publish a text annotation.
5. Capture a screenshot region.
6. Capture a media clip no longer than 90 seconds.
7. Follow a person and a Source.
8. Open Following and comment on an annotation.
9. Join a Live room; test Cloak Mode.
10. Create or join a Research project and add evidence.
11. Search globally and within the current page.
12. Save a search and enable alerts.
13. Publish a Research report.
14. Trigger/inspect a Source change and its notification/integrity state.
15. File a community report and a rights claim with a separate test account.
16. Verify moderation can restrict/remove/restore without destroying preserved evidence.
17. Revoke Team/Research membership and confirm access disappears immediately.
18. Revoke extension session and older browser sessions.

## 6. Rollback

Database migrations are forward-only and may contain MariaDB DDL that cannot be transactionally rolled back.

If the application release must be rolled back:

1. Put the site into maintenance mode at the web server/load balancer.
2. Stop all workers.
3. Restore the **database backup and private evidence backup as a matched pair** from immediately before the deployment.
4. Restore the previous known-good deploy package.
5. Restore the prior production configuration if it changed.
6. Restart workers.
7. Re-run the previous release's health checks and smoke tests.
8. Remove maintenance mode only after data and application versions are consistent.

Do **not** run an older application binary against a database that has partially applied a newer migration unless that compatibility was explicitly tested.

## 7. Release evidence

Archive with the release:

- merged commit SHA
- Git tree SHA
- CI run ID
- server deploy ZIP SHA-256
- Chrome extension ZIP SHA-256
- database-backup reference
- private-storage-backup reference
- preflight output
- post-deploy health screenshot/export


## 8. Phase 49 release-candidate baseline

The V1.1 RC1 baseline is the exact merged Phase 49 release-candidate tree. Before promotion, verify the exact branch/commit being packaged contains:

- application release `1.1.0-rc1`
- Chrome extension `0.36.0` (unchanged because Phase 37–49 did not require a Chrome feature revision)
- Phase 37–48 intelligence governance and closed-loop audit gates
- Phase 49 release operations contract and database audit
- PHP 8.1 and PHP 8.3 full historical regression
- MariaDB targeted Phase 37–49 integration
- MySQL 8 fresh-install compatibility
- generated `RELEASE-MANIFEST.json`
- package smoke verification
- website/Chrome ZIP SHA-256 checksums
- matched database/private-storage backup ID and verification result

Do not promote an older `main` tree merely because it is the repository default branch; release from the exact tested `annotated-v1-1-development` Phase 49 commit.
