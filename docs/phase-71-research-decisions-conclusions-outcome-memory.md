# Phase 71 — Research Decisions, Conclusions & Outcome Memory

## Section 1 — Decision & Conclusion Ledger Foundation

Section 1 introduces a durable decision/conclusion object above Annotated's existing Research evidence and Outcome Learning systems.

### Purpose

Research Missions answer questions. The Decision & Conclusion Ledger records what people concluded or decided because of that research, why, who is accountable, what evidence informed it, and how that record changes over time.

The existing Phase 20 `research_outcome_*` tables remain the historical outcome-event and feedback layer. Phase 71 does not replace or rewrite them.

### Durable model

A Decision record belongs to one existing Research Agent and Research Project and stores:

- type: Decision, Conclusion, or Recommendation
- title and canonical statement
- rationale
- lifecycle status
- explicit confidence, when provided
- accountable owner
- optional source Research Mission
- assumptions
- uncertainty
- alternatives considered
- immutable configuration revision number and hash
- decision timestamp when a disposition is recorded

Supporting tables preserve:

- immutable configuration revisions
- typed references to accessible Research objects
- reference roles: supports, contradicts, source, context, assumption, alternative
- optional reference strength and notes
- append-only lifecycle/audit events

### Lifecycle

`draft → proposed → accepted / rejected / deferred`

Accepted, rejected, deferred, or superseded records can later be reopened. Superseded and archived states remain durable history rather than deleting the record.

A rationale is required before an accepted, rejected, or deferred disposition can be recorded.

### Evidence lineage

Section 1 references existing durable Research objects rather than copying them. References are permission checked and project scoped. Supported foundation references include Research Missions, Sources, Claims, Findings, Tasks, Plans, Programs, Entities, Report Versions, Reviews, Documents, and the owning Project.

Changing evidence lineage creates a new immutable Decision revision.

### API

`/api/research-decisions.php` provides authenticated list, summary, detail, create, update, lifecycle, add-reference, and remove-reference operations. Mutations use the existing API mutation authentication and rate limiting.

### Section 1 invariants

- Decision/Conclusion records are distinct durable objects, not aliases for `research_outcome_events`.
- Existing Outcome Learning history is not migrated, rewritten, or fabricated into Decisions.
- No decision is created automatically by a Mission.
- No Agent can silently accept, reject, or complete a decision through this foundation.
- Evidence references never copy source content and must resolve to accessible Research state.
- Configuration changes and evidence-lineage changes produce immutable revisions.
- Status changes are append-only events and do not rewrite historical revisions.
- No scheduler, worker, cron, queue, or autonomous decision loop is introduced.

## Planned Phase 71 sections

1. Decision & Conclusion Ledger Foundation
2. Mission → Decision Handoff
3. Decision Evidence & Challenge Graph
4. Outcome Memory
5. Decision Evolution & Reconsideration
6. Agent / Now / Report Studio Integration
7. Decision Command Center + Team Review
8. End-to-End Hardening & Release
