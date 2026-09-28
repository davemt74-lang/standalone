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

