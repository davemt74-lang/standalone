# Annotated V1 — Live Worker Certification
Section score is **not 10/10 production** until actual deployed execution and notification delivery are verified. This code gate independently audits the already deployed sixteen canonical workers, records live health and explains operator checks; it creates no alternate scheduler, queue, Agent or migration.

## Audited code gaps and fix
- Previously an idle/starting heartbeat or a never-started optional training worker could be mistaken for production readiness. Require a fresh **last successful invocation** for active/core workers; allow dormant on-demand evaluation/training/post-training only with no backlog.
- The existing queue readers return errors, but prior health views did not combine them with required-worker classification. Fail closed for unavailable queues, missing worker scripts, failed queued jobs and pending on-demand work without a verified worker.
- Production cron/Supervisor installation and actual outbound authorized notification delivery cannot be inferred from database records. Always mark those **not verified** until an operator observes them on the deployed installation.
- Reuse app/release.php canonical registry/heartbeats/queue counters, Admin System Health, original Admin Agent and Research Automation (including Section 4D Sponsor alerts). Admin Agent receives read-only scoped diagnostics and **confirmation-required** repair guidance; the diagnostic never starts workers or mutates paid research state.

## Ten-point code acceptance gate
1. One canonical 16-worker registry and no second scheduler.
2. CLI-only machine-readable certification command with explicit production verification limitations.
3. Core workers require non-stale heartbeat and a recent actual successful invocation.
4. Dormant on-demand workers are correctly distinguished; pending work requires their heartbeat.
5. Queue errors and failed jobs block acceptance and cannot silently appear healthy.
6. Missing scripts and stale/failed workers are identified with canonical commands.
7. Existing Admin Health UI shows worker-level blockers and the operator checklist.
8. Existing Admin Agent receives read-only permission-scoped worker context and no autonomous repair authority.
9. Independent PHP 8.1/8.3 and MariaDB/MySQL8 contract, real DB heartbeat and queue fixture, and model governance pass on exact head.
10. PR is green, reviewed and merged, followed by exact merge full Website/Chrome ZIP verification and SHA-256 files.

## Actual deployed certification — separate mandatory gate
Run `php bin/v1-worker-certify.php --json` on the actual installed server under the deployment user. Verify actual cron/Supervisor service configuration for each core worker (the existing `php bin/research-automations.php --limit=25` scheduler must run once every 5 minutes). Prove one staged real task goes through Agent context → queue → AI worker → stored result; induce and recover a controlled failure without duplicate effects. Prove deadline/revision notification delivery to an authorized researcher and denial after revocation, including email/push configuration when applicable. Verify last_success_at advances across separate real invocations and the original Admin Agent reports a repair plan without acting without permission. Do not publish V1 production 10/10 on static or synthetic CI alone.

The existing Admin release-preflight includes environment/package/backup checks. This worker certificate is additive and read-only; no automatic restarts or repeated legacy CI.
