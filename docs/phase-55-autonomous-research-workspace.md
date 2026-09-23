# Phase 55 — Autonomous Research Workspace

Phase 55 turns the Phase 54 unified Research knowledge layer into an actively maintained Research Agent workspace without creating a second knowledge store.

## Scope

- Managed autonomous workspace artifacts: an Agent Research folder, Living Research Report, and Research Attention sticky.
- Evidence-gap and contradiction detection over structured Research claims and their evidence.
- Durable observation lifecycle with open/resolved/dismissed states and evidence references.
- Versioned living-report updates using the existing Research Doc revision system.
- Leased autonomous worker jobs with rerun-on-change behavior.
- Scheduled Research Automation handoff into the autonomous workspace loop.
- Change-only Agent Chat updates with a Living Research Report attachment.
- Live permission checks and a manual run/status API.
- Research Library inline viewer/editor: Docs open in the full-height slide-out Library and can be edited/saved there; uploaded PDFs/images/audio/video render inline when supported, with extracted text and open/download controls.
- Canvas controls (Library, Desktop, Close) live at the main workspace-canvas level and stick below the application header instead of scrolling with Agent Chat.

## Safety and ownership rules

The Research Agent only mutates objects registered in `research_autonomy_artifacts`. User-authored Docs, stickies, folders, uploads, recordings, annotations, bookmarks, and Sources are never silently rewritten by the autonomous loop. Agent-managed Docs retain normal document revisions so changes remain auditable and recoverable.

Autonomous observations are deterministic records derived from authoritative project state. Conflicting evidence is retained; the agent does not erase contradictory evidence or silently rewrite user conclusions.

All autonomous access is project-scoped and permission-checked. Removing access to the Research Agent also removes access to its autonomous status and managed artifacts through the existing workspace permission model.

## Runtime

Run the worker continuously or once per minute:

```
php worker/research-autonomy-worker.php
```

The existing scheduled Research Automation worker queues Phase 55 after monitoring completes. Workspace mutations also queue a run. Multiple mutations while a worker holds the lease set `rerun_requested` instead of invalidating the active claim.

## Deployment

Run `upgrade.php` after deployment to apply:

`20260923_053_autonomous_research_workspace.sql`

Phase 54 retrieval remains required and is reused directly. No parallel knowledge index is introduced.

## 10/10 audit dimensions

1. Managed-artifact safety
2. Living report revision integrity
3. Evidence-gap detection
4. Contradiction lifecycle
5. Change detection and loop suppression
6. Worker lease/recovery behavior
7. Scheduled automation integration
8. Agent Chat presentation/deduplication
9. Library inline viewer/editor and sticky canvas controls
10. Regression, permissions, packaging, and deployment
