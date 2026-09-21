# Phase 28 — Research Reproducibility & Evidence Packs

Phase 28 closes the reproducibility gap in the Research loop.

An Evidence Pack is an explicit immutable, permission-scoped snapshot for a Project, Claim, Finding, or immutable Report Version. It captures the exact relevant provenance and verification state at creation time so another authorized project member can replay what was known then.

## Rules

- Current Research remains authoritative.
- Evidence Packs are immutable replay records, not editable working copies.
- A canonical SHA-256 manifest verifies each pack.
- Replay never silently substitutes current state.
- Current-vs-pack comparison exposes drift by manifest section.
- Team/project authorization is checked at read time; losing project access removes pack access.
- JSON export contains Annotated metadata, hashes, references and permitted stored content already present in the Research model; it does not fetch new live source content.
- Agent context may explain pack state and drift but cannot rewrite a pack.

## Scopes

- Project: full permission-scoped Phase 26 provenance + Phase 27 Claim verification summaries.
- Claim: the Claim, exact evidence Source Versions and Annotations, dependent Findings, reviews and verification events.
- Finding: the Finding, linked Claims, exact evidence dependencies and Claim verification summaries.
- Report Version: immutable publication snapshot, snapshot hash status, citations, reviews and verification events.

## Product surfaces

- Evidence Packs hub and project workspace
- Replay / comparison page
- JSON manifest export
- Project / Claim / Report navigation
- Agent context
- Cognitive Feed pack-drift alerts
- Phase 26 provenance edges
- Chrome v0.28.0 Evidence Packs entry
