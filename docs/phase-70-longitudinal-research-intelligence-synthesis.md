# Phase 70 — Longitudinal Research Intelligence & Synthesis

Phase 70 gives each Research Agent durable memory of how its project knowledge changes over time.

## Architecture

The canonical flow is:

**Authoritative Research state → Longitudinal snapshot → Change ledger → Milestones / trends → Longitudinal Report Run → Phase 69 delivery → optional Research Document**

Report Runs and derived Documents are not authoritative longitudinal inputs. Capturing or rendering history therefore cannot make current Research state stale by itself.

## Longitudinal snapshots

A snapshot records normalized current state for:

- Claims
- Findings
- Sources
- Entities
- Claim relationships
- Entity relationships
- Tasks
- Research Programs
- Open questions / evidence gaps
- Contradictions

Snapshots are deduplicated by project + state hash.

The first snapshot is a baseline. Existing objects are not incorrectly labeled as newly introduced.

## Research Change Ledger

Each subsequent snapshot is compared with the previous state.

Append-only change events preserve:

- object type and stable object ID
- change type
- materiality
- before state
- after state
- human-readable reason
- prior/current snapshot lineage
- occurrence time
- deterministic dedupe key

Representative change types include:

- introduced
- changed
- strengthened
- weakened
- disputed
- verified
- source_updated
- resolved
- removed

## Milestones

Important changes become durable milestones, including:

- Claim introduced / strengthened / weakened / disputed / verified
- Finding introduced / strengthened / weakened
- Source introduced / updated / weakened / restored
- Contradiction introduced / resolved
- Open question introduced / resolved

## Capture authority

No new worker or scheduler is created.

The existing Research Program lifecycle captures longitudinal state:

- before Phase 69 delivery on completed Program cycles
- before Phase 69 delivery on quiet Program cycles

Users may also explicitly capture the current state from Research Evolution or the API.

Identical current state is deduplicated and does not create another snapshot.

## Research Evolution workspace

Each Research Agent gains a Research Evolution surface with:

- 7 / 30 / 90 / 365-day windows
- material changes
- strengthening / weakening knowledge
- current open questions
- current contradictions
- milestones
- repeated-change trend intelligence
- append-only Change Ledger
- state snapshot history
- entry points into longitudinal System Reports

## Agent catch-up

Research Agent Chat receives authorized longitudinal context.

The Agent can answer questions such as:

- “Catch me up.”
- “What changed since yesterday?”
- “What changed last week?”
- “What changed last month?”
- “What changed since this Report Run?”
- “What changed since this longitudinal snapshot?”

Mentioned accessible Report Run or snapshot IDs resolve to their historical timestamp.

## Longitudinal System Reports

Phase 70 adds five predefined Report Studio processors:

1. **Research Evolution Brief**
2. **What Changed Brief**
3. **Confidence & Contradictions Brief**
4. **Open Questions Brief**
5. **Entity & Theme Evolution Brief**

They remain ordinary Report Runs and therefore inherit Phase 68 behavior:

- configurable depth/scope/sections
- saved presets
- refresh/history
- Ask Agent
- explicit Create Document only

They also inherit Phase 69 subscriptions and intelligence delivery without any new delivery architecture.

## Freshness boundary

Longitudinal history is added to Report rendering only after the current-state Report hash has been calculated.

Report Studio also excludes longitudinal context from its unscoped freshness basis.

This means:

- history may change while current Research state remains current
- running a longitudinal report does not create longitudinal change
- creating a Document from a report does not create longitudinal change
- snapshots and ledger rows do not contaminate authoritative Research retrieval state

## Now integration

Recent high-materiality ledger events appear in the existing Now/cognitive feed.

No separate longitudinal notification feed is introduced.

## Migration 084

`20260926_084_longitudinal_research_intelligence.sql`

Adds:

- `research_longitudinal_snapshots`
- `research_longitudinal_changes`
- `research_longitudinal_milestones`

All Phase 70 foreign-key constraint names are namespaced with `fk_p70_*`.

## Phase boundary

Phase 70 builds longitudinal state/history/synthesis only.

It does not:

- automatically create Research Documents
- introduce a new scheduler
- replace Phase 69 subscriptions/delivery
- mutate Claims/Findings based on trend interpretation
- infer decisions or outcomes on the user’s behalf
