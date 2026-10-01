# Section 4E — Consolidated Sponsored Research E2E release gate

This section **certifies the existing canonical workflow**. It does not create another Sponsored Research Agent engine, database migration, task planner, notification store, payment rail or auto-submission feature.

## Reused, already merged production paths
- 4A dependency/permission map, approved Research Accounts and original sponsor campaign/terms authority.
- 4B exact personally owned Agent read-only context, including accepted revision and private thread filtering.
- Both 4C integrations: six guided project operations and the existing Agent Chat governed actions, pending proposals and **explicit researcher confirmation** for private progress updates and evidence-backed submissions.
- 4D scanner in the original Research Automation worker, existing notification dedupe, permission-checked deep links, private blocker redaction and revision alert recovery.
- Existing human sponsor review, immutable submission assets, compensation ledger and authorization checks.

## Consolidated integration evidence
The 4E database gate invokes the pre-existing complete 4D real-DB journey unchanged: sponsor setup → approved researcher → personally assigned Agent → bounded context → action proposal → no premature write → human confirmation → idempotent private update → stale terms rejection → explicit evidence-backed immutable submission → duplicate-confirmation protection → pending compensation → 72/24-hour deadline alerts → authorized private blocker alert → blocker resolution → sponsor-requested revision → recovery under the original notification key → withdrawal/revocation.

Then 4E adds a new independent outsider; asserts denied context and notification URLs; checks durable sponsor review and unchanged evidence; counts canonical submission and deduplicated revision notices; transitions to paused and closed; and demonstrates reminders remain suppressed. It retains read-only samples without creating actionable sample assignments.

No 4E runtime feature is introduced unless the integrated tests identify a specific missing behavior.

## Operator activation check
CI can certify the worker code and its real MariaDB/MySQL 8 behavior, but **cannot assert that the operator has installed the site's production cron schedule**. Before calling the feature live:
1. Confirm the existing Research Automation worker is scheduled under the site's deployment user, e.g. `php bin/research-automations.php --limit=25` via the existing approved scheduling method; do not create a second sponsored cron.
2. Invoke that existing worker on a production-like staging database and verify the `research_automation` heartbeat and `sponsored_deadlines`, `sponsored_blockers`, `sponsored_revisions` counters.
3. Verify actual approved users receive authorized notifications; an outsider and withdrawn participant cannot open stored notices or links. Check no copied private blocker text.
4. Restore test fixtures and document the production scheduler/configuration owner before enabling live paid projects. No automatic sponsor approval, researcher terms acceptance, evidence submission, financial action or training consent.

## Ten-point acceptance gate
1. All 4A–4D canonical entry points reused; no duplicated production subsystem.
2. Complete sponsor/approved Research Account/accepted terms/exact private Agent fixture.
3. Agent Chat context and proposal are permission scoped and do not write before confirmation.
4. Confirmed private progress update preserves ledger attribution, isolation and idempotency.
5. Stale terms and fabricated evidence are denied.
6. One confirmed immutable submission, original sponsor review/revision and pending compensation survive replay.
7. Deadline windows, generic sponsor blocker, suppression and revision notification are idempotent and authorized.
8. Outsider, revoked, paused, closed and demonstration access are negative-tested.
9. Exact 4E head: PHP 8.1, PHP 8.3, MariaDB, MySQL 8 and model-governance green; no unnecessary full historical rerun.
10. Merge and verify exact-merge Website/Chrome ZIP archives, manifest ancestry and SHA-256; complete the operator activation check separately before claiming a live production scheduler.

Record 10/10 **code/release acceptance only** after 1–10 are evidenced. Do not represent production scheduling as verified unless an operator actually verifies it.
