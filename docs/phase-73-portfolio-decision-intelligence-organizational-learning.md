# Phase 73 — Portfolio Decision Intelligence & Organizational Learning

Phase 73 moves Annotated's durable learning loop above a single Decision. Phase 71 made Decisions authoritative, and Phase 72 connected accepted Decisions to governed execution and Outcome Memory. Phase 73 connects Intelligence Portfolios to that same native chain and then builds cross-Decision organizational learning from authoritative records instead of parallel executive ledgers.

## Section 1 — Portfolio → Native Decision Handoff

Section 1 removes the user-facing architectural split between the older Phase 61 Portfolio “decision” record and the Phase 71 Decision Ledger.

### Historical Phase 61 records are preserved

Migration 099 does **not** rewrite or synthesize old Portfolio decisions.

Existing `research_intelligence_portfolio_decision_links` rows keep their Phase 61 `outcome_id`, optional follow-up Task, timestamps, and Outcome Learning history unchanged. The table is extended so future rows may point to a native `research_decisions` record instead.

The old PHP helper remains available only for compatibility with historical regression/internal callers. Current Portfolio UI and API routes no longer use it.

### New Portfolio decisions are native Decisions

The current Portfolio “Record decision” action now creates a real Phase 71 Decision:

- status starts **Draft**
- Decision type is Decision / Conclusion / Recommendation
- the Portfolio primary/anchor Research Program determines the authoritative Research Agent and project
- accountable owner defaults to the current user
- Portfolio, Portfolio insight, and Executive Briefing references are stored as normal permission-checked Decision refs
- an explicit idempotency key prevents duplicate Decisions on retries

No Phase 61 `portfolio_decision` Outcome Learning event is fabricated for the new handoff.

### Execution remains Phase 72

Creating the native Decision does not create a Research Task or Action Plan.

The correct execution path is:

**Portfolio intelligence → Draft Decision → explicit Decision review/disposition → Accepted Decision → Phase 72 Action Plan**

This means Portfolio-origin Decisions receive the same evidence graph, Team Review, Action Plan governance, execution variance, Outcome Memory, and reconsideration behavior as any other native Decision.

### Anchor and permission boundaries

The Portfolio anchor Program determines the Research Agent/project where the Decision lives. If no explicit anchor is present, a Primary Program is preferred, then the first accessible Program.

The user must have write access to that anchor Research project.

Portfolio / insight / Executive Briefing refs are accepted by the Decision Ledger only when:

- the viewer can access the Portfolio, and
- the Decision project is represented by a Program inside that Portfolio.

Team membership therefore remains authoritative. Removing Team access also removes access to the Portfolio-origin Decision.

### Legacy compatibility

The Phase 61 helper `research_intelligence_portfolio_record_decision()` remains present so historical regression and old internal integrations continue to work.

New surfaces route exclusively to `research_intelligence_portfolio_create_native_decision()`.

Portfolio decision history renders both forms:

- **Native** — real Phase 71 Decision with current lifecycle status and Open Decision navigation
- **Legacy Phase 61** — historical Outcome Learning record and optional historical follow-up Task

No backfill converts legacy rows into native Decisions.

### Section 1 invariants

- historical Phase 61 Portfolio decision rows are never rewritten
- migration 099 fabricates no native Decisions
- new Portfolio UI/API decisions create native Draft Decisions only
- native handoff is idempotent
- no direct follow-up Task is created from the new handoff
- no Action Plan is created automatically
- Portfolio-origin Decisions use the existing Phase 71 Decision Ledger
- accepted Portfolio-origin Decisions use the existing Phase 72 Action Plan handoff
- Portfolio, insight, and briefing provenance is permission checked
- Team revocation immediately removes Decision access
- no new worker, scheduler, queue, or executive-decision engine is introduced

## Planned Phase 73 continuation

- Section 2 — Portfolio Decision & Execution Rollups
- Section 3 — Cross-Decision Learning & Pattern Memory
- Section 4 — Strategic Dependency & Conflict Graph
- Section 5 — Recurring Strategic Review
- Section 6 — Executive Strategic Briefings & Team Review
- Section 7 — Organizational Agent Cognition & Governed Follow-Through
- Section 8 — End-to-End Hardening & Release

## Section 2 — Portfolio Decision & Execution Rollups

Section 2 is intentionally **schema-free**. It projects authoritative Phase 71/72 state upward into each Intelligence Portfolio and the organization Command Center without copying or re-owning execution state.

The rollup chain is:

**Portfolio native Decision → Action Plan(s) → milestones / Research Tasks → execution variances → Collaborative Review → Outcome Memory**

### Portfolio rollup

Each native Portfolio Decision now exposes:

- current Decision type, lifecycle status, revision, confidence, and review state
- all accessible Action Plans created from that Decision
- Action Plan status, priority, owner, due date, overdue state, and source-Decision staleness
- milestone and linked Research Task counts
- open / resolved execution variance totals
- material open variance totals
- high-or-critical open variance totals
- Action Plan Collaborative Review status
- whether explicit Outcome Memory has been recorded

Historical Phase 61 Portfolio decision records remain counted separately as legacy records and are never interpreted as native Action Plan execution.

### Organization Command Center

The Command Center now aggregates:

- native Portfolio Decisions
- total / active / completed Action Plans
- overdue Action Plans
- material and high/critical open variances
- open and overdue Team Reviews
- recorded outcomes
- completed-without-outcome learning gaps
- preserved legacy Phase 61 records

A deterministic attention list identifies Action Plans that are overdue, have material variance, have open review, or are completed but still awaiting Outcome Memory.

### Authority boundary

Section 2 is read-only.

It does not create or mutate:

- Decisions
- Action Plans
- milestones
- Research Tasks
- execution evidence
- variances
- Collaborative Reviews
- Outcome Memory
- Programs

It adds no worker, scheduler, queue, or AI ranking path.

### Section 2 invariants

- no migration 100
- Phase 71 Decision Ledger remains authoritative
- Phase 72 Action Plan ledger remains authoritative
- Phase 72 execution-variance store remains authoritative
- Collaborative Review remains authoritative
- Decision Outcome Memory remains authoritative
- completed-without-outcome is visible until explicit human outcome handoff
- legacy Phase 61 records are visible but excluded from native execution totals
- all rollups remain permission checked and Team revocation applies immediately

## Section 3 — Cross-Decision Learning & Pattern Memory

Section 3 adds durable organizational memory above individual Decisions while preserving Phase 71/72 authority.

### Deterministic pattern types

A pattern is created only when the same normalized fact is supported by **two or more distinct native Portfolio Decisions**.

Supported patterns are:

- repeated Decision assumptions
- repeated Action Plan risks
- recurring execution variance types
- recurring Decision outcome assessments
- repeated explicit expected-versus-actual variance
- repeated outcome lessons

There is no embedding similarity, fuzzy matching, semantic clustering, or LLM-created pattern membership in Section 3.

### Durable Pattern Memory

Migration 100 adds:

- `research_intelligence_decision_pattern_runs`
- `research_intelligence_decision_patterns`
- `research_intelligence_decision_pattern_members`

A Pattern Memory refresh computes deterministic candidates from authoritative Phase 71/72 state, hashes the resulting pattern snapshot, and deduplicates identical refresh state.

Pattern records preserve:

- pattern type and stable fingerprint
- first seen / last seen
- active / inactive state
- evidence count
- distinct Decision count
- Action Plan and Outcome counts
- explicit member snapshots

Pattern runs preserve the deterministic snapshot that produced the current memory.

### Source authority

Pattern Memory is derived from:

- native Portfolio Decisions
- Decision assumptions
- Phase 72 Action Plan risks
- Phase 72 execution variances
- Decision Outcome Memory assessments
- explicit outcome variance summaries
- explicit outcome lessons

Pattern Memory never edits those sources.

It cannot:

- accept, reject, reopen, defer, supersede, or archive a Decision
- create, activate, pause, complete, cancel, or archive an Action Plan
- resolve a variance
- complete a Collaborative Review
- write Outcome Memory
- create a Research Task
- execute an Agent action

### Refresh authority

Users with Portfolio write access may explicitly refresh Pattern Memory.

The existing Portfolio intelligence cycle may also refresh it after a successful cycle. No new worker or scheduler is introduced. A Pattern Memory refresh failure is recorded as a Portfolio event and does not invalidate the Executive Briefing cycle.

### Portfolio and Command Center surfaces

Each Portfolio shows:

- active Pattern count
- repeated assumptions
- repeated risks
- recurring variance patterns
- recurring outcome patterns
- repeated lessons
- first / last seen timestamps
- explicit evidence-member counts

The organization Command Center aggregates accessible Pattern Memory and surfaces the strongest cross-Decision learning patterns by Decision and evidence coverage.

### Section 3 invariants

- migration 100 creates no synthetic Pattern rows
- a Pattern requires evidence from at least two distinct native Decisions
- legacy Phase 61 Portfolio decision rows are excluded from Pattern membership
- exact normalized evidence only; no fuzzy or AI similarity
- identical Pattern snapshots deduplicate to one Pattern run
- Pattern refresh does not mutate Decision, Action Plan, variance, review, or outcome state
- Team/Portfolio permission revocation immediately removes access
- existing Portfolio cycles are reused; no scheduler/worker/queue is introduced

## Section 4 — Strategic Dependency & Conflict Graph

Section 4 adds an explicit, audited relationship graph across native Portfolio Decisions and Phase 72 Action Plans.

### Relationship types

Supported relationships are:

- **Depends on**
- **Supports**
- **Conflicts with**
- **Duplicates**
- **Supersedes**
- **Blocks**
- **Materially affects**

Relationships are authored explicitly by a user with Portfolio write access. Section 4 does not infer or auto-create graph edges from text similarity, Pattern Memory, or Agent interpretation.

### Cross-Portfolio graph

A relationship is created from a source node that belongs to the selected Portfolio. Its target may belong to another accessible Intelligence Portfolio.

This makes cross-Portfolio dependency and conflict visible without copying either source object.

Only native Phase 71 Decisions and Phase 72 Action Plans are graph nodes. Legacy Phase 61 Portfolio decision records are excluded.

### Provenance and stale-state detection

Migration 101 adds:

- `research_intelligence_strategic_edges`
- `research_intelligence_strategic_edge_events`

Each edge preserves:

- source and target node type / public ID
- relationship type
- explicit rationale
- optional confidence
- explicit materiality: low / medium / high / critical
- source and target revision-aware state hashes at the time the relationship is recorded or acknowledged
- creating Portfolio
- creating/removing user
- required removal reason
- active / removed state
- immutable audit events

A relationship becomes **stale** when either authoritative endpoint changes after its state hash was recorded.

Stale does not mean invalid. It means a human should review whether the relationship still applies. A user may explicitly acknowledge current endpoint state to refresh the hashes.

### Symmetry and cycle safety

`conflicts_with` and `duplicates` are symmetric and are normalized so A↔B cannot be recorded twice in opposite directions.

`depends_on`, `blocks`, and `supersedes` are directional and reject a write that would create a cycle in the same relationship graph.

Self-relationships are rejected.

### Removal and restoration

Removing a relationship does not delete its history.

The edge becomes inactive, records who removed it, when, and the required removal reason, and retains its audit events. Recording the same relationship again restores the existing edge and writes a restoration event.

### Portfolio and organization views

Each Portfolio exposes:

- active relationship count
- dependency count
- conflicts
- blockers
- stale relationships
- cross-Portfolio relationships
- source/target navigation
- explicit rationale, materiality, and optional confidence
- connected cross-Portfolio endpoint nodes
- existing Phase 71 contradiction/challenge counts on Decision nodes
- acknowledge-current-state and reason-required remove controls

The organization Command Center surfaces attention-grade graph edges:

- conflicts
- blocks
- possible duplicates
- unresolved dependencies
- high-materiality impacts
- stale relationships

### Authority boundary

The graph is descriptive governance state only.

Graph operations cannot:

- change Decision lifecycle status
- create/reopen/reject/accept a Decision
- create, activate, pause, complete, or cancel an Action Plan
- resolve execution variance
- complete Team Review
- write Outcome Memory
- execute an Agent action

### Section 4 invariants

- migration 101 creates no synthetic strategic relationships
- only accessible native Decision / Action Plan nodes can be linked
- source node must belong to the Portfolio recording the edge
- cross-Portfolio target requires normal access to its authoritative node
- conflict/duplicate reverse writes de-duplicate to one edge
- dependency, blocking, and supersession cycles are rejected
- endpoint changes make saved graph provenance stale
- stale relationships require explicit human acknowledgement to refresh
- removal requires a reason, preserves audit history, and restoration reuses the edge
- cross-Portfolio graph payloads include connected external endpoint nodes rather than dangling edges
- existing Phase 71 contradiction/challenge state is referenced as node metadata instead of duplicated
- graph writes never mutate source Decision or Action Plan state
- Team / Portfolio permission revocation immediately removes graph access
- no worker, scheduler, queue, fuzzy inference, or AI relationship writer is introduced

## Section 5 — Recurring Strategic Review

Section 5 turns the strategic state built in Sections 1–4 into a recurring, human-governed review loop without introducing another scheduler or another review engine.

### Existing systems reused

Recurring Strategic Review deliberately reuses:

- the existing **Portfolio intelligence cycle** as the only recurring clock
- the existing **Collaborative Research Review** engine for reviewer assignment, responses, objections, deadlines, notifications, completion, and review audit history
- the existing Decision / Action Plan / Outcome / Pattern Memory / Strategic Graph stores as authoritative source state

There is no new worker, cron, queue, or independent scheduler.

A recurring Strategic Review can only be evaluated when the existing Portfolio cycle runs. If a Portfolio is configured as manual-only, recurring Strategic Review is also manual-only until normal Portfolio scheduling is enabled.

### Strategic Review settings

Each Portfolio may configure:

- active / paused status
- cadence: every Portfolio cycle, weekly, monthly, or quarterly
- review deadline offset in hours
- explicit current collaborators who should receive the Collaborative Review

The cadence is evaluated against the time of the existing Portfolio cycle. A review configuration cannot wake the system independently.

If configured reviewers lose access, the recurring review is not silently reassigned. The failed review handoff is recorded as a Portfolio event and the successful Portfolio cycle remains valid.

### Frozen Strategic Review packet

Migration 102 adds:

- `research_intelligence_strategic_reviews`
- `research_intelligence_strategic_review_settings`

A Strategic Review packet freezes a deterministic snapshot of:

- Portfolio aggregate signals
- native Decision and Phase 72 execution summary
- Action Plan attention: overdue work, material variance, open Team Review, and completed-without-outcome gaps
- Cross-Decision Pattern Memory summary and strongest patterns
- Strategic Dependency & Conflict Graph summary and attention
- deterministic review-focus items

The packet hash excludes capture time so unchanged strategic state remains hash-identical across review cycles.

The packet itself is immutable review evidence. New Portfolio changes do not rewrite an open review packet.

### Collaborative Review subject

Migration 102 extends the existing `research_reviews.subject_type` ENUM with `strategic_review`.

Each packet is handed to the existing Collaborative Review engine as a frozen `strategic_review` subject.

That means Strategic Reviews inherit the normal review behavior:

- reviewer assignment
- approve / request changes / disagree / abstain responses
- comments and audit events
- due dates and overdue state
- unresolved objections
- completion by an authorized human
- review notifications and Review Center visibility

Strategic Review does not add another voting model or another review status system.

### Current-state drift

A completed or open Strategic Review always remains pinned to the packet that was reviewed.

The Portfolio surface may compare the latest packet hash with current strategic state and show **current drift**. Current drift means new Portfolio state exists after the frozen packet; it does not invalidate, rewrite, or silently restart the existing Collaborative Review.

A later manual or recurring review captures the newer state.

### Manual review

A Portfolio writer can create a Strategic Review immediately using the configured reviewers.

Manual creation uses the same frozen packet and Collaborative Review path as recurring creation. An idempotency key prevents an accidental duplicate manual request.

### Organization Command Center

The organization Command Center aggregates accessible Strategic Review state:

- Portfolios with active recurring review configuration
- total / open / completed Strategic Reviews
- overdue Strategic Reviews
- changes requested
- unresolved objections
- latest-packet current drift
- direct links to reviews needing attention

### Authority boundary

Strategic Review is advisory and human-governed.

Creating, responding to, or completing a Strategic Review cannot:

- accept, reject, reopen, defer, supersede, or archive a Decision
- create, activate, pause, complete, cancel, or archive an Action Plan
- resolve execution variance
- change Strategic Graph edges
- write Decision Outcome Memory
- create Research Tasks
- execute Agent actions
- publish an Executive Briefing

Any follow-through remains an explicit later user action through the existing governed systems.

### Section 5 invariants

- migration 102 fabricates no Strategic Review rows
- migration 102 preserves all prior Decision, Action Plan, Pattern Memory, Strategic Graph, Portfolio, and Collaborative Review state
- no scheduler, worker, queue, or cron is introduced
- recurring review is evaluated only from the existing Portfolio cycle
- frozen packet hashes are deterministic and ignore capture time
- recurring review is de-duplicated per Portfolio cycle
- manual review supports explicit idempotency
- only explicitly configured current collaborators can be assigned
- reviewer access revocation is respected immediately
- Collaborative Research Review remains authoritative for responses and completion
- packet drift never rewrites an open or completed review
- Strategic Review never mutates Decision or Action Plan lifecycle state



## Section 6 — Executive Strategic Briefings & Team Review

Section 6 turns the frozen strategic state from Sections 1–5 into a leadership-ready briefing without creating a parallel document, review, or publishing system.

### Existing systems reused

An Executive Strategic Briefing is still:

- a normal `research_executive_briefings` record
- backed by a normal Research Doc in the Research Agent workspace
- reviewed through the existing Collaborative Research Review engine
- published through the existing Phase 59 publication workflow

Phase 59 remains the only publication workflow. Section 6 adds no new scheduler, worker, queue, or publishing engine.

### Frozen strategic briefing lineage

Migration 103 adds only `research_intelligence_strategic_briefings`.

The lineage record binds together:

- the existing Executive Briefing
- the frozen Phase 73 strategic packet used to render it
- an optional source Recurring Strategic Review packet
- the existing Collaborative Team Review
- source-review consensus provenance
- an idempotent creation key

Migration 103 fabricates no Strategic Briefing rows and does not rewrite existing Executive Briefings.

A Strategic Briefing may be created from either the current deterministic strategic packet or an explicitly selected Section 5 Strategic Review packet. When a prior Strategic Review is selected, the briefing renders only from that frozen packet; newer Portfolio state is not mixed into the document.

### Briefing content

The frozen packet supplies the briefing's deterministic leadership sections:

- Portfolio overview, risks, opportunities, cross-program signals, and material movement
- native Decision and Phase 72 Action Plan execution state
- overdue execution, material variance, and Outcome Memory gaps
- Cross-Decision Pattern Memory
- Strategic Dependency & Conflict Graph state
- deterministic Strategic Review focus
- source Strategic Review provenance when present

The packet state hash is stored with the lineage and rendered into the document for traceability. The normal Executive Briefing snapshot relationship is retained for compatibility, while the Section 6 packet is authoritative for the strategic sections.

### Team Review

Immediately after creation, the Strategic Briefing Research Doc is sent to the existing Collaborative Review engine as a normal `document` subject.

Reviewer assignment reuses the Portfolio's current Section 5 Strategic Review collaborator configuration. Revoked collaborators are never silently substituted.

Because the Team Review is a document review, any document edit makes the Team Review stale through the existing document revision/hash contract. An updated review is then required.

Current Portfolio strategic drift is tracked separately from document-review staleness. New Decision, execution, Pattern Memory, or Strategic Graph state can make the frozen briefing strategically old, but it never rewrites the frozen document or silently changes what the Team reviewed.

### Publication gate

Strategic Briefings add a stricter gate before the existing Phase 59 workflow is created.

A Strategic Briefing can enter Phase 59 only when its Collaborative Team Review is:

- completed
- not stale
- unanimously approved by all assigned reviewers

Open review, requested changes, disagreement, mixed review, missing reviewers, or a stale document review all block publication.

This stricter gate applies only to Section 6 Strategic Briefings. Historical and ordinary Executive Briefings retain their existing Phase 59 behavior.

### Authority boundary

Creating, reviewing, completing, or publishing an Executive Strategic Briefing cannot:

- accept, reject, reopen, defer, supersede, or archive a Decision
- create, activate, pause, complete, cancel, or archive an Action Plan
- resolve an execution variance
- edit or acknowledge a Strategic Graph relationship
- write Decision Outcome Memory
- create Research Tasks
- execute Agent actions

Team Review is communication governance, not Decision or execution authority.

### Portfolio and organization surfaces

Each Portfolio now shows:

- total Strategic Briefings
- open and completed Team Reviews
- unanimous approvals
- overdue reviews
- publication-ready briefings
- current strategic drift
- frozen packet hash
- direct Research Doc, Team Review, and publication links

The organization Command Center adds Executive Strategic Briefing attention for open/overdue Team Review, requested changes, unresolved objections, and current strategic drift.

### Section 6 invariants

- Migration 103 creates no synthetic Strategic Briefing lineage
- Strategic Briefings reuse the existing Executive Briefing and Research Doc stores
- the selected strategic packet is immutable briefing input
- an older selected packet is never mixed with current strategic state in the rendered briefing
- Team Review uses the existing `document` Collaborative Review subject
- a document edit makes the Team Review stale
- current strategic drift does not rewrite the frozen briefing or its Team Review subject
- publication requires completed, current, unanimously approved Team Review
- Phase 59 remains the only publication workflow
- Team / Portfolio permission revocation immediately removes access
- no new scheduler, worker, queue, or publishing engine is introduced
- no Decision, Action Plan, variance, Strategic Graph, Outcome Memory, Task, or Agent authority is added
