# Phase 36 — End-to-End Workspace Journey & Release Hardening

Phase 36 is a release-hardening phase, not a feature phase.

The release candidate must behave like one application across:

**Browser → Annotation → Feed → Team → Research → Agent → Review → Publish → Monitor → Action Center**

## What changed

### One Home intelligence snapshot

Home previously collected the same Cognitive state once for Now and again for the Action Center count. Phase 36 collects it once per request and shares that immutable request snapshot between both renderers.

This changes no ranking, permissions, or persistent state. It removes duplicated database work on the highest-traffic signed-in page.

### Release cache normalization

Core shared clients participating in the end-to-end journey now use the Phase 36 release cache tag:
- Annotation cards
- workspace continuity
- Agent Chat
- Team Chat
- Activity
- Action Center
- Research Agent

This prevents a deployment from pairing the hardened PHP tree with older cached JavaScript.

## Integrated release journey

`tests/phase36-end-to-end-workspace-journey-db.php` proves one complete product loop:

1. Browser-equivalent capture preserves a Source Version and publishes an Annotation.
2. The Annotation appears in Feed.
3. The same Annotation is shared to Team Chat as a reference, not a copied object.
4. It enters a shared Research project.
5. Explicitly confirmed Agent actions create a Claim and attach the original evidence.
6. A second independent Source/domain is attached as corroborating evidence.
7. A collaborator records current human Claim verification.
8. Agent-assisted synthesis creates a Finding.
9. A collaborator performs human review.
10. The Research lifecycle reaches Publish.
11. A public immutable Report Version is published and hash-verified.
11. Workspace context re-resolves Team / Research / object / Agent references.
12. The Source changes after publication.
13. Source Integrity propagates downstream impact to Claim / Finding / Report.
14. Action Center routes the impact to human review.
15. Resolving the authoritative impact removes the Action Center item.
16. Team removal revokes private Team/Research/chat access while the intentionally public Report remains public.

No external LLM call or background-worker timing is required for the release gate.

## Scale and UX hardening

The Phase 36 contract locks:
- feed request cap: 30
- conversation request cap: 100
- Activity request cap: 120
- Action Center request cap: 100
- Chrome Action preview: 8
- incremental Chrome feed pagination
- six-tab single-row Chrome workspace
- tab/tabpanel ARIA state
- Team/Agent live regions
- mobile Team Chat control
- responsive website breakpoints
- session-only workspace continuity
- current release client cache tags

## Release boundary

Phase 36 adds no database migration and no additional intelligence, memory, ranking, task, or event subsystem.

If this phase is green on PHP 8.1, PHP 8.3, MariaDB, MySQL 8, the full historical matrix, packaging, and the integrated journey, the current architecture is considered ready for a serious V1 release-candidate deployment pass.
