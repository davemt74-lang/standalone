# Sponsored Research Section 4D — Proactive Signals and Notifications

## Purpose and consolidation

Build only the missing Sponsored Project signal projection. Reuse merged Sections 1–4C, exact approved researcher/assigned personal Agent ACL, canonical Sponsor Account managers, `bin/research-automations.php`, `notification_create`, the existing Notifications / Activity tab, and original sponsor submission/review notifications.

**No new scheduler, task engine, Agent memory, payment system, schema migration, or automatic Sponsor action.** Running the existing Research Automation worker at the site's configured schedule also executes this bounded, deduplicated project signal scan. Production delivery depends on that worker actually being scheduled.

## Signals

- **Deadlines:** notify the exact active, accepted, currently authorized researcher within the 72-hour window and again within the 24-hour window. Stable keys contain assignment, original deadline and window. No expired, withdrawn, unapproved, non-personal Agent or sample-project reminder.
- **Blockers:** select recent researcher-authored private-thread `blocked` entries from the canonical append-only project journal; suppress entries when later `ready_for_review` or `completed` updates resolve the thread. Notify only authorized managers of the sponsoring account. The notification body never contains private post text; the ACL-revalidated link opens their existing authorized project activity page.
- **Revisions:** reuse `research_sponsored_revision_requested` and the canonical `sponsored-review:<submission ID>:revision_requested` key emitted by sponsor review. The scanner only restores a genuinely missing alert for the current accepted active assignment; it does not create new review states or spam duplicate notifications.
- **Revocation:** `notification_object_access` revalidates the current exact assignment for deadlines and sponsor management authority for blocker alerts. Stored notices immediately disappear when recipient access is withdrawn. URL resolution always performs this ACL first.
- **Samples and consent:** virtual sample projects are absent from persistent scan queries. Notifications contain no paid-project data beyond the permitted recipient and never imply training consent, submission, payment, or shared-Agent access.

## Runtime boundaries

The single existing `bin/research-automations.php` worker calls `sponsored_agent_proactive_scan` with a bounded limit. Its own failure is captured and marked on worker health without preventing unrelated queued Research Automation tasks from being processed. Repeated worker invocations are idempotent through the existing unique notification dedupe ledger. The 4D service itself never calls the LLM or commits any Sponsored Research business change.

## Ten-point acceptance gate

1. Reuse existing automation runner and notification storage; no duplicate scheduled daemon.
2. Exact active assignment, accepted current project revision and latest published terms before researcher notices.
3. Deadline calculation deterministic in UTC, bounded to 72h and 24h windows, with stable dedupe.
4. Sponsor blocker visibility through canonical management authority and never a copied private post body.
5. Resolved blockers no longer generate new alerts.
6. Review-state notifications use the *existing* sponsor-review type and key; no duplicate status mutations.
7. Revalidate `sponsored_project` notification object rights and scoped deep links at read time.
8. Exclude virtual samples and withdrawn, stale, disabled or unrelated Agents.
9. Independent pure/security contract and real MariaDB/MySQL 8 journey exercise delivery, duplicate scans, blocker scope, revision catch-up, revocation and links.
10. Exact-head PHP 8.1, PHP 8.3, MariaDB, MySQL 8 and model-governance checks all green, then PR merge and verify full Website/Chrome ZIP integrity plus hashes.

Acceptance can be recorded as 10/10 **only after** all ten are demonstrated. Start 4E only after this branch is green, merged and packaged.
