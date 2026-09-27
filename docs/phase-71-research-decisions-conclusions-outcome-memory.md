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


## Section 2 — Mission → Decision Handoff

Section 2 adds an explicit bridge from a completed Research Mission execution cycle into the Decision & Conclusion Ledger.

- only Missions in **Review** or **Completed** state are eligible
- the existing Mission Plan must be completed
- a current synthesis answer must exist
- handoff is always explicitly requested; Missions never create Decisions automatically
- the resulting Decision is created as **Proposed**, never Accepted
- the Decision defaults to a Conclusion but the user may choose Decision or Recommendation
- Mission evidence is carried forward as durable pointers to the same Research objects, never copied content
- the handoff stores an immutable Mission-state snapshot containing the exact Mission revision, config hash, status, question, objective, synthesis, progress/readiness, criteria, and sub-question state
- explicit Decision confidence is accepted only when supplied; Mission confidence is not silently converted into Decision confidence
- one Mission may intentionally produce multiple Decisions, but retries against the same Mission state/request are idempotent
- Mission and Decision lifecycles remain independent after handoff; later Mission changes do not rewrite the stored handoff snapshot

### Section 2 invariants

- no automatic Mission → Decision conversion
- no automatic acceptance/rejection/defer action
- no evidence content duplication
- no Mission status mutation during handoff
- no scheduler, worker, cron, queue, or autonomous handoff loop
- handoff retries cannot create duplicate Decisions for the same idempotency key
- later Mission revisions cannot mutate an earlier handoff snapshot


## Section 3 — Decision Evidence & Challenge Graph

Section 3 makes every Decision inspectable from both sides without automatically changing its disposition.

### Challenge model

A Decision may carry explicit:

- contradictions
- assumptions that need validation
- uncertainty requiring follow-up
- alternatives that remain viable
- open questions
- reversal conditions: concrete evidence or events that would justify reconsidering the Decision

Each challenge has a severity, lifecycle, resolution note, and its own project-scoped evidence/context references.

### Graph

The deterministic Decision Evidence Graph contains:

- the Decision root
- supporting and contradicting Decision references
- assumptions, uncertainty, and alternatives already stored on the Decision
- challenge nodes
- challenge evidence that supports or counters each challenge
- explicit reversal-condition nodes

The graph is descriptive. It does not infer confidence, score the Decision, or change Decision status.

### Revision behavior

Challenge creation, edits, status changes, and challenge-reference changes participate in the existing immutable Decision configuration stream. The exact evidence/challenge picture can therefore be reconstructed for any Decision revision.

### Section 3 invariants

- challenge status never automatically changes Decision status
- challenge evidence remains a pointer to existing accessible Research objects; content is never copied
- cross-project challenge references are rejected
- closing or accepting a challenge requires an explicit resolution note
- reopening a challenge preserves its historical events and prior revisions
- reversal conditions describe what would justify reconsideration but do not themselves reopen the Decision
- no autonomous scoring, voting, acceptance, rejection, scheduler, worker, cron, or queue is introduced


## Section 4 — Outcome Memory

Section 4 connects a recorded Decision disposition to what actually happened afterward.

### Architecture

Outcome Memory reuses the existing Phase 20 `research_outcome_events` ledger. A Decision outcome therefore remains visible to existing Outcome Learning and feedback systems instead of creating a parallel event model.

A Decision-specific assessment is layered on top of that event with:

- expected result
- actual result
- success / partial success / failure / mixed / unresolved assessment
- variance between expectation and reality
- lessons learned
- explicit outcome confidence, when known
- follow-up / resolved / reopened state
- observation timestamp
- project-scoped follow-up evidence pointers
- immutable Outcome Memory revisions

### Lifecycle rules

- a Decision must have an explicit disposition before an outcome can be recorded
- Outcome Memory never auto-accepts, rejects, reopens, or supersedes the Decision
- outcome revisions do not rewrite Decision configuration revisions
- updating expected-vs-actual assessment updates a Decision-created Phase 20 outcome projection while preserving immutable Outcome Memory revisions; an explicitly linked pre-existing Phase 20 event remains read-only
- one Decision may accumulate multiple observed outcomes over time
- an existing user-owned Outcome Learning event from the same Research project may be explicitly linked instead of duplicated
- idempotency prevents accidental duplicate recording of the same observed outcome

### Section 4 invariants

- Phase 20 Outcome Learning tables remain authoritative event storage and are not replaced
- Outcome Memory never fabricates a Decision disposition
- actual outcome and assessment must be explicit; no hidden success score is inferred
- Decision outcome confidence is explicit and bounded to 0–1
- follow-up evidence remains pointers to accessible Research objects
- team-visible Decision Outcome Memory does not depend on the original recorder still being the viewer
- no scheduler, worker, cron, queue, or autonomous outcome judgment is introduced

## Planned Phase 71 sections

1. Decision & Conclusion Ledger Foundation
2. Mission → Decision Handoff
3. Decision Evidence & Challenge Graph
4. Outcome Memory
5. Decision Evolution & Reconsideration
6. Agent / Now / Report Studio Integration
7. Decision Command Center + Team Review
8. End-to-End Hardening & Release
