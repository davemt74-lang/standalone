# Phase 64 — Core Product Experience & End-to-End Workflow Hardening

Phase 64 is a stabilization phase for the user-facing Annotated journey. It does not add another product subsystem or database migration.

## Release objective

A user can move through **Home → Research Agent → Desktop/Library → document or recording → Agent context → return later** without silent data loss, stale cross-workspace object access, or browser navigation that disagrees with the visible Research state.

## Hardening changes

### Research Doc browser continuity

Research Doc URLs already carry a `doc` query parameter, but the editor previously used `history.replaceState()` with no `popstate` restoration path. That meant the address bar changed while browser Back/Forward could not reliably reproduce the visible document state.

Phase 64:

- pushes document-open and document-close states into browser history for explicit user navigation;
- uses replace-state only for initial deep-link hydration;
- re-opens or closes the correct document on `popstate`;
- restores the current document URL if a navigation-triggered save fails.

### Unsaved-work protection

Several navigation paths previously attempted an asynchronous save, ignored failures, and immediately closed/reset the editor. Phase 64 makes save success a prerequisite for leaving dirty work.

Protected transitions include:

- opening a different Desktop Research Doc;
- closing a dirty Research Doc;
- closing the Research Desktop;
- Library document Back;
- Library close;
- Library → Desktop;
- Library → Ask Agent handoff.

If the save fails, the current editor remains open with the dirty state intact and the error is visible.

### Active-workspace object scoping

Authenticated users can legitimately have access to multiple Research Agent projects. Phase 64 adds `research_agent_workspace_object_in_project()` and routes object-specific API actions through it so a document, recording, sticky, trashed object, or generic workspace object must belong to the **currently active Research project**, not merely be accessible somewhere else.

This is a continuity and isolation boundary; existing underlying ownership and Team permissions remain authoritative.

### Existing workflow preserved

The phase intentionally retains the existing:

- drag/drop upload and folder placement;
- document revision conflict protection;
- recordings and transcript conversion;
- transcript Summary / Tasks / Ask Agent actions;
- Agent context handoff;
- Desktop/Library transitions;
- Home Research Agent deep-link project verification;
- immediate Team access revocation.

## Regression coverage

`tests/phase64-core-product-workflow-contract.php` locks the client navigation/data-loss protections and active-project API scope.

`tests/phase64-core-product-workflow-db.php` exercises a return-later journey across two Research Agents, including:

1. active-project document scoping;
2. durable document revision save;
3. stale-revision write rejection;
4. folder placement persistence after re-resolving the workspace;
5. ready recording transcript hydration;
6. transcript → Research Doc conversion;
7. cross-workspace isolation of the derived document;
8. Trash/restore active-read behavior;
9. authoritative workspace-context resolution;
10. immediate revocation after Team removal.

## Release identity

Phase 64 adds no database migration and no cron process. The existing V1.1 stable release manifest identity remains unchanged; migration 079 remains the latest schema migration.

Production acceptance requires PHP 8.1/8.3 contracts, full historical regression, the Phase 64 database journey, MySQL 8 fresh-install/upgrade validation, model-governance integration, and package smoke to pass.
