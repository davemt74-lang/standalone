# Phase 29 — Unified Research Workflow

Phase 29 is a product simplification pass. It introduces no new Research intelligence store and no new migration.

The project page becomes the primary workspace for the Research lifecycle:

**Capture → Investigate → Verify → Synthesize → Review → Publish → Monitor**

## Deterministic orchestration

The unified workflow derives stage state from existing authoritative modules:

- Sources / Annotations
- Claims / exact evidence
- Phase 27 verification
- Findings
- collaborative reviews
- immutable Report Versions
- Change Impact
- Evidence Pack drift

The workflow chooses one clear next step without hiding the underlying advanced tools.

## UX

- lifecycle strip on every Research project
- one primary Next step card
- lifecycle-organized project tool rail
- global Research surfaces keep advanced tools but de-emphasize them
- Chrome Research tab presents Workspace / Portfolio / Living / Reviews first; specialist tools move under Advanced
- Agent receives the current workflow stage and next-step context, but execution rules remain unchanged

## Boundaries

- no new database tables
- no AI-generated workflow state
- no automatic mutations
- no removal of specialist pages or audit surfaces
- existing permissions remain authoritative

Chrome advances to **v0.29.0**.
