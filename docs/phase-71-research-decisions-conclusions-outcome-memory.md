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


## Section 5 — Decision Evolution & Reconsideration

Section 5 turns Decision history into an explicit review workflow without allowing new evidence or Agent reasoning to silently change a recorded Decision.

### Deterministic reconsideration signals

The runtime can surface review signals from durable Decision state:

- contradicting Decision evidence
- open or accepted challenges
- explicit reversal conditions
- assumption challenges
- partial, mixed, failed, follow-up, or reopened observed outcomes

Signals are descriptive. They do not create a reconsideration case and they never mutate Decision status.

### Reconsideration cases

A user may explicitly open a reconsideration against a recorded Decision disposition. Each case freezes:

- Decision status and immutable revision at opening
- evidence/challenge graph counts
- current Outcome Memory summary
- current deterministic review signals
- a canonical context hash
- trigger, materiality, reason, author, and timestamps

Non-manual cases must reference a signal that is still current. Duplicate attempts against the same active signal/context reuse the existing case.

### Review and apply

A case moves through Open → Reviewing → Resolved or Dismissed. Resolving requires an explicit recommendation: retain, reopen, supersede, or defer.

Resolution itself does **not** alter the Decision. A separate explicit Apply action is required. Apply is transactionally retry-safe, leaves an audit event, and refuses stale cases when the Decision revision or status changed after the opening snapshot.

### Evolution timeline

The Decision evolution API composes a chronological view of:

- immutable Decision revisions
- Decision lifecycle/audit events
- observed outcomes and Outcome Memory revisions
- reconsideration cases and their audit events

This is a read model over existing durable ledgers; it does not rewrite them.

### Section 5 invariants

- signals never auto-open cases
- opening/reviewing/resolving/dismissing a case never changes Decision disposition
- only an explicit Apply action may enact a resolved recommendation
- applied cases are immutable and retry-safe
- stale cases cannot be applied after Decision status/revision changes
- non-manual triggers must point to current deterministic signals
- no hidden score, autonomous vote, scheduler, worker, cron, queue, or auto-reconsideration loop is introduced


## Section 6 — Agent / Now / Report Studio Integration

Section 6 makes Decision Memory visible inside existing research surfaces without granting new autonomous write authority.

### Agent Chat

Research Agent conversations receive a read-only Decision Memory context block containing:

- Decision ID, type, status, revision, statement, and rationale
- explicit Decision confidence
- current high/critical reconsideration signal counts
- active reconsideration case count
- latest observed outcome and follow-up state
- underlying evidence/Mission provenance references

Agent Chat may explain "why did we decide this?", "what changed?", "what happened afterward?", and "what needs review?" from durable state. It cannot mutate Decision or reconsideration state through this integration.

### Now / Cognitive Feed

Now surfaces Decision attention items when durable state shows:

- critical/high reconsideration signals
- active reconsideration cases
- a reopened Decision

The feed item links back to the Research project and may prefill a read-only Agent question. It does not create a reconsideration case or change Decision status.

### Report Studio / published reports

Immutable report snapshots now include a Decision & Outcome projection.

Private/team reports may include Decision challenges and reconsideration history. Public reports are conservative:

- only Accepted or Superseded Decisions are included
- drafts, proposed, rejected, and deferred Decisions are excluded
- internal challenge/reconsideration detail is excluded
- Decision evidence notes are excluded
- recorded rationale, evidence IDs/roles/strength, outcomes, variance, and lessons may be published as part of the deliberate report snapshot

Published report rendering includes a Decisions & Outcomes section and Decision/Outcome timeline entries.

### Section 6 invariants

- no new schema or background worker is introduced
- Agent Decision context is read-only
- Now observations are read-only and deterministic
- report snapshots remain immutable after publishing
- public reports exclude unresolved/internal Decision review state
- Decision integration never auto-opens, resolves, applies, or changes a Decision


## Section 7 — Decision Command Center + Team Review

Section 7 provides a dedicated human governance surface for Decision Memory and extends the existing Collaborative Research Review system to Decisions and Decision reconsiderations.

### Decision Command Center

The Command Center aggregates every accessible non-archived Decision across the user's owned and Team Research projects.

It surfaces:

- Decision type, status, revision, project, and Research Agent
- proposed and reopened Decisions
- high/critical reconsideration signals
- active reconsideration cases
- Outcome Memory coverage
- current structured Team Review state, consensus, and staleness
- direct links to the underlying Research project and Review Center

Views include All, Needs Attention, Proposed, Reopened, Decided, Outcomes, and Team Reviews.

### Explicit Decision actions

The Command Center may expose only transitions already allowed by the Decision ledger. A status change is always a CSRF-protected explicit human action. Merely opening the Command Center, generating attention state, or completing a Team Review does not mutate the Decision.

### Native Team Review subjects

Migration 092 extends the existing `research_reviews.subject_type` enum with:

- `decision`
- `decision_reconsideration`

Decision reviews are pinned to a canonical review hash that includes Decision revision/configuration, disposition, Outcome Memory revisions, and reconsideration state. Reconsideration reviews are pinned to the case state, opening context, recommendation, resolution, and applied state.

Any material change after review request makes the review stale through the existing Review Center stale-state mechanism.

### Team review remains advisory

Review responses may approve, request changes, disagree, or abstain. Completion freezes the actual reviewer response set and consensus, but never changes the Decision or reconsideration automatically.

A reviewer consensus therefore becomes governance evidence, not an executable Decision.

### Section 7 invariants

- no parallel review engine is introduced
- Decision and reconsideration reviews use the existing append-only Review Center ledgers
- reviews are permission-checked against the underlying Research project
- review staleness is deterministic and state-hash based
- Team Review never auto-accepts, rejects, defers, reopens, supersedes, resolves, dismisses, or applies a Decision/reconsideration
- Command Center attention is a read model and has no side effects
- only explicit existing Decision actions may change Decision state


## Section 8 — End-to-End Hardening & Release

Section 8 freezes Phase 71 product scope and validates the complete Decision Memory lifecycle as one release unit. It adds no new schema, scheduler, worker, queue, or Decision authority.

### Final integrated journey

The final acceptance journey verifies the real chain:

**Research Mission → Mission synthesis → Proposed Decision → Team Review → explicit human disposition → evidence/challenge graph → Outcome Memory → reconsideration → Agent / Now / Report Studio → Decision Command Center → explicit reconsideration Apply → evolution/audit history**

The journey also verifies:

- Mission handoff remains idempotent and pinned to an immutable Mission snapshot
- Team approval remains advisory until a human explicitly changes Decision status
- Outcome Memory and reconsideration signals never silently alter Decision disposition
- reconsideration Apply is explicit and retry-safe
- Agent and Now projections remain read-only
- public report projection remains conservative when a Decision is no longer in a public disposition
- Decision/reconsideration Team Reviews become stale after material underlying state changes
- Team/Research access revocation removes Decision and review access immediately
- no duplicate Mission handoff, Outcome Memory, or parallel Decision state is created

### Supported Phase 71 upgrade matrix

The final MySQL gate rehearses upgrades to migration 092 from representative durable Phase 71 boundaries:

- migration 086 — pre-Phase 71 Research Missions baseline
- migration 087 — Decision Ledger foundation
- migration 089 — Decision Evidence & Challenge Graph
- migration 091 — Decision Evolution & Reconsideration

Each rehearsal must:

- preserve an existing Collaborative Review row
- apply every pending migration through 092
- finish with all Phase 71 Decision tables available
- expose Decision and Decision Reconsideration as native Review subjects
- create no synthetic Decision, outcome, reconsideration, or Team Review state
- leave no pending migration
- prove a second migration pass is a no-op

### Release invariants

- migration 092 is the final Phase 71 schema boundary
- all Sections 1–7 remain covered by their original contracts and DB journeys
- Section 8 introduces no product subsystem or database migration
- no Decision-specific worker, scheduler, cron, or queue is introduced
- existing Research Programs remain the recurring scheduler authority
- existing Collaborative Research Review remains the Team review authority
- Phase 20 Outcome Learning remains the underlying outcome-event authority
- production packaging must include all Phase 71 migrations, runtimes, UI, documentation, and acceptance gates

### Phase 71 completion

Phase 71 is release-ready only when fast CI, model governance, PHP 8.1/8.3 full regression, MySQL 8 fresh install, the supported Phase 71 upgrade matrix, the final integrated journey, and production package smoke all pass on the same exact head.

## Planned Phase 71 sections

1. Decision & Conclusion Ledger Foundation
2. Mission → Decision Handoff
3. Decision Evidence & Challenge Graph
4. Outcome Memory
5. Decision Evolution & Reconsideration
6. Agent / Now / Report Studio Integration
7. Decision Command Center + Team Review
8. End-to-End Hardening & Release
