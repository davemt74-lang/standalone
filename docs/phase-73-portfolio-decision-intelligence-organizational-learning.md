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
