# Phase 61 — Portfolio Intelligence Operations & Executive Follow-Through

Phase 61 operationalizes Phase 60 without introducing a second scheduler, task system, Decision Memory, notification engine, or publication pipeline.

## 61A — Scheduled Portfolio Intelligence

- Portfolio cadence is executed inside the existing `bin/research-automations.php` invocation.
- Durable cycle receipts provide idempotency, retries, material hashes, snapshots, and briefing links.
- `material_only` mode skips duplicate briefings when the configured evidence threshold has not changed.
- No Phase 61 worker is added.

## 61B — Executive Briefing Automation

- Scheduled cycles freeze a Portfolio snapshot before creating a briefing.
- A briefing can reuse the exact frozen snapshot from its cycle.
- Briefings include a deterministic comparison with the prior snapshot under **New since last briefing**.
- Automation creates a draft Research Doc only. It never approves or publishes.

## 61C — Executive Subscriptions & Distribution

- Accessible users can subscribe with cadence and draft/published preferences.
- Draft-ready notices reuse Annotated notifications with durable receipt/acknowledgement state.
- When a briefing enters Phase 59 publishing, active published subscribers are passed into the existing Phase 59 recipient targets.
- Phase 59 remains authoritative for immutable publication and final distribution.

## 61D — Decisions & Follow-Through

- Executive decisions are authoritative Phase 20 Decision Memory events.
- Phase 61 stores only a link from Portfolio/insight/briefing to the Decision Memory outcome.
- Optional follow-through is created as an existing Phase 57 Research Task in the anchor Program workspace.
- Overdue/open follow-through is surfaced in Now and the organization command center.

## 61E — Portfolio Learning Loop

- Humans can explicitly mark an insight/briefing useful, irrelevant, resolved, escalated, or acted on.
- Feedback is inspectable and does not mutate Claims, Findings, evidence, or Agent conclusions.
- Human decision/feedback provenance remains distinct from deterministic aggregation and Agent inference.

## 61F — Organization Intelligence Command Center

The command center rolls accessible Portfolios into:

- Needs attention
- New since last briefing
- Decisions awaiting follow-through
- Emerging opportunities
- Cross-Portfolio themes
- Briefings awaiting review

Drill-down remains Organization → Portfolio → Program → Research artifact/evidence.

## Acceptance

Each 61A–61F section must reach 10/10. Required gates remain PHP 8.1/8.3 static contracts, model-governance integration, full historical regression, MySQL 8 fresh install, migration 058→059 rehearsal, package validation, green PR merge, and post-merge tree equivalence.
