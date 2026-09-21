# Phase 25 — Research Network & Citation Intelligence

Phase 25 adds explicit, version-pinned citations between Annotated Research reports.

## Invariants

- Working Research projects may update or remove their current report references.
- Published report versions capture citations to exact immutable target versions.
- Published citation edges are insert-only; newer target versions never retarget historical citations.
- Public publication versions include only public cited versions.
- Team/private publication versions may additionally include compatible same-Team citations.
- Cross-Team private citations are not allowed.
- Citation notifications are delivered only when the cited report owner can currently access the citing report.
- Agent and Cognitive Feed may explain citation updates, but do not change citation pins automatically.

## Surfaces

- Project Citation Workspace
- Immutable Report Network: References / Cited by
- Research Network hub
- Report-page Referenced Research section
- Research Agent citation context
- Cognitive Feed newer-cited-version signal
- Chrome Research Network entry

## Release

- Migration: `20260920_032_research_network_citation_intelligence.sql`
- Chrome extension: `0.25.0`
- Dedicated MariaDB suite: `tests/phase25-research-network-db.php`
- Merge only after exact-head historical CI + Phase 25 suite + two-package build are green.
