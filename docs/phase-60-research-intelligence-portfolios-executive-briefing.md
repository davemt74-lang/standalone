# Phase 60 — Research Intelligence Portfolios, Executive Briefing & V1.1 RC1 Stabilization

Phase 60 is both the V1.1 RC1 stabilization gate for Phases 50–59 and the governed organization-intelligence layer above Phase 58 Research Programs.

## 60A — Stabilization gate

- Audit Phases 50–59 for duplicate/dead paths and preserve one authoritative service for each concern.
- Exercise upgrade from migration 056 through migrations 057 and 058.
- Keep the website and extension shared CSS byte-identical.
- Run PHP 8.1/8.3 static and full regression, model-governance integration, MySQL 8 fresh install, and package validation.
- The Phase 59 Agent publication validation bug is corrected by keeping argument cleaning pure and performing document/workflow access checks only in project validation.
- No new worker, scheduler, task system, review system, Claim store, document store, or publication system is introduced.

## 60B — Research Intelligence Portfolios

A durable Intelligence Portfolio groups multiple existing Research Programs within one personal or Team boundary.

- Programs remain authoritative for recurring work and deltas.
- Portfolio aggregation is deterministic and labeled separately from Agent inference.
- Cross-program shared references expose trends and tensions without inventing a hidden score.
- Frozen Portfolio snapshots preserve the exact aggregate and Program provenance used at a point in time.
- Inferences require explicit provenance refs and retain optional confidence.

The legacy Phase 23 Research Portfolio remains the personal project-attention command center. Phase 60 does not rename or replace it.

## 60C — Executive Briefing

Executive Briefings are ordinary versioned Research Docs created in the anchor Program's existing Research Agent workspace.

- Briefings include deterministic overview, risks, opportunities, cross-program signals, trends, explicit Agent inference, and provenance.
- Preparing a briefing for publication creates a normal Phase 59 workflow.
- Review, approval, immutable report publication, distribution, Now, notifications, and subscribers continue through existing systems.
- Published report immutability is therefore inherited from Phase 59 rather than duplicated.

## Release acceptance

Every Phase 60 section must score 10/10 before merge. Merge only after every PR workflow is green. After merge, compare the exact green feature head to the merged development tree and package the website and Chrome extension from the verified tree.
