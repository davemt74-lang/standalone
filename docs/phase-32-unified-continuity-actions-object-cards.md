# Phase 32 — Unified Continuity Actions & Object Cards

Phase 31 established permission-checked object handoff. Phase 32 removes the next set of dead ends so a referenced Annotation remains actionable after it lands.

## Continuity loop

**Browser → Annotation → Feed → Team → Research → Agent → authoritative object**

### Team Chat object cards

Available Annotation attachments expose:
- Open — return to the authoritative Annotation.
- Research — reuse the existing Add to Research flow.
- Agent — reuse the existing Agent Chat context flow when the viewer has Agent access.

Unavailable attachments remain tombstones and expose no actions.

### Agent context cards

Agent message context chips now deep-link to authoritative Annotated objects for supported context types including Annotation, Research, Source, Team, Claim, and Finding.

The link is navigation only. It does not alter the Agent conversation, create memory, or grant access.

## Boundaries

- No new database tables or migrations.
- No copied Annotation content is persisted.
- Team attachment actions operate on the Phase 31 object reference.
- Research addition continues through the existing project/Annotation relation.
- Agent context continues through the existing permission-checked Agent context API.
- Server-side access remains authoritative even when a UI card exposes an action.
- Agent actions remain subject to the existing paid-plan/server capability gates and confirmation model.

Chrome release: **v0.32.0**.
