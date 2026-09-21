# Phase 28 — Research Reproducibility & Evidence Packs

Phase 28 closes the reproducibility gap in the Research loop.

An Evidence Pack is an explicit immutable, permission-scoped snapshot for a Project, Claim, Finding, or immutable Report Version. It captures the exact relevant provenance and verification state at creation time so the creator can replay what was known then without turning creator-specific permissions into a sharing channel.

## Rules

- Current Research remains authoritative.
- Evidence Packs are immutable replay records, not editable working copies.
- A canonical SHA-256 manifest verifies each pack.
- Replay never silently substitutes current state.
- Current-vs-pack comparison exposes drift by manifest section.
- Packs are creator-scoped and project authorization is rechecked at read time; losing project access removes pack access.
- Viewer-private Decision Memory is deliberately excluded from Evidence Pack manifests.
- Sharing a frozen pack requires a future explicit sharing/export policy; Team membership alone is not permission to read another creator's pack.
- JSON export contains Annotated metadata, hashes, references and permitted stored content already present in the Research model; it does not fetch new live source content.
- Agent context may explain pack state and drift but cannot rewrite a pack.

## Scopes

- Project: permission-scoped Phase 26 provenance excluding viewer-private Decision Memory + Phase 27 Claim verification summaries.
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
