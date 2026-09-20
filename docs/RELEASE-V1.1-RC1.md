# Annotated V1.1 RC1 Release Runbook

This runbook applies to **Annotated V1.1 RC1** and must be executed against the exact merged `main` commit that passed CI.

## Fresh installation

1. Copy `config.example.php` to `config.php` and configure the MariaDB connection.
2. Create an empty MariaDB database.
3. Open `/install.php` and click **Install Annotated**.
4. The installer imports the base schema and applies all bundled migrations automatically.
5. Create the first administrator when redirected to `/first-admin.php`.
6. No manual SQL import and no bootstrap/setup key are required.
7. Complete the production settings and run `php bin/release-preflight.php`.

If the installer detects unrelated or partially initialized tables, it stops rather than modifying that database. Use a fresh empty database for a new install.

## 1. Before deployment

1. Back up the MariaDB database with a transaction-consistent dump appropriate to your environment.
2. Back up the complete directory configured as `storage.private_root`.
3. Record the currently deployed Git commit and deployment-package checksum.
4. Confirm that the previous deploy package is still available for rollback.
5. Run:
   ```bash
   php bin/release-preflight.php
   ```
6. Resolve every **FAIL**. Review **WARN** items before continuing.
7. Confirm Admin → **System Health & Release** reports the expected RC version.

## 2. Required worker schedule

Run workers through Supervisor/systemd/cron using the same PHP/config/database environment as the web application.

Suggested minimum cadence:

```text
media-worker.php             continuously / at least every minute
transcription-worker.php     continuously / at least every minute
source-monitor-worker.php    every 5 minutes
ai-worker.php                continuously / at least every minute
saved-search-worker.php
php bin/research-automations.php --limit=25      every 5 minutes
```

After starting each worker, reload **Admin → System Health & Release** and verify a fresh heartbeat.

## 3. Deployment

1. Extract the exact-main server deploy ZIP over the application release directory.
2. Preserve production `config.php` and the external private evidence directory.
3. Sign in as an administrator.
4. Open `/upgrade.php` and apply all pending forward-only migrations.
5. Run `php bin/release-preflight.php` again.
6. Verify login, OAuth buttons, extension authorization, evidence playback, source pages, Search, Live, Notifications, Research, claims/moderation, and Admin health.

## 4. Chrome extension RC

The RC extension package is built from the exact same merged source tree.

Before Chrome Web Store upload:

- confirm Manifest V3
- verify version `0.9.0`
- verify production server is present in the allowed extension-ID configuration
- inspect requested permissions: `sidePanel`, `activeTab`, `scripting`, `storage`, `identity`, `tabCapture`
- confirm first install opens Extension Setup
- confirm server URL validation requires HTTPS except localhost
- connect an account and verify Connected Accounts shows the extension version and expiry
- revoke the session and confirm the sidebar requires reconnect

## 5. V1 end-to-end release gate

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
