# Phase 66 — Production Acceptance, UX QA & Release Hardening

Phase 66 is an acceptance gate, not a feature expansion.

## Acceptance objective

The release must support one coherent production journey:

**fresh account → browser capture/evidence → Research Agent → document/upload/recording → failure recovery → saved result → leave → return later → continue**

## Defect fixed

Phase 64 protected dirty Research Docs during in-app navigation and Phase 65 protected Agent-to-Home exits. A remaining production risk existed at the browser boundary: refresh, tab close, or window close could terminate the best-effort asynchronous save before it completed.

Phase 66 adds a `beforeunload` guard whenever:

- a Desktop Research Doc has unsaved changes;
- a Library Research Doc has unsaved changes; or
- a browser recording is actively recording.

The existing visibility-change save remains a best-effort save; the browser-leave guard prevents treating that best effort as guaranteed persistence.

## Automated acceptance matrix

The Phase 66 database journey verifies:

1. fresh account + active browser-capture session;
2. Research Agent workspace ownership;
3. web bookmark capture into a folder;
4. unsafe URL rejection;
5. document HTML sanitization;
6. durable revision save;
7. stale-revision overwrite rejection;
8. queued PDF extraction;
9. visible extraction failure state;
10. recording + transcription queue;
11. failed transcription retry/reset;
12. ready transcript persistence;
13. transcript → Research Doc conversion;
14. canonical onboarding completion through real Research activity;
15. return-later Agent/project/document re-resolution;
16. Trash/restore continuity for nested work;
17. explicit bounded production upload limits.

Existing Phase 64 and Phase 65 tests continue to cover active-project isolation, Team revocation, browser document history, dirty-save navigation guards, first-run guidance, Desktop/Library entry, and safe Agent return to Home.

## Release scope

No new database migration, cron, worker, queue, or product subsystem is added. Migration 079 remains latest and the V1.1 stable release identity remains unchanged.

Production acceptance is complete only when PHP 8.1/8.3 static contracts, model governance, full regression, the Phase 66 database journey, MySQL 8 fresh-install/upgrade rehearsals, and production package smoke all pass on the same feature head.
