# Phase 33 — Unified Activity & Context Awareness

Phase 33 makes activity across Annotated feel like one workspace without creating another event store.

## Principle

The activity stream is **derived at read time from authoritative product state**. It is not a second history database.

Sources include:
- Annotation publication relevant to the viewer
- Team messages in Teams the viewer currently belongs to
- Annotations added to accessible Research
- accessible Claim and Finding changes
- Report Versions the viewer can currently access
- the viewer's own Agent action lifecycle

## Access behavior

Every object is rechecked against current access:
- Annotation visibility and user blocks
- Team membership and conversation access
- Research project access
- Report Version visibility
- Agent action ownership and current Research access

If underlying access is removed, the activity disappears. Phase 33 does not preserve a leaked historical copy.

## Surfaces

- `/activity.php` — full workspace timeline
- `/api/activity.php` — same current-viewer timeline for clients
- Cognitive Feed — bounded recent context, excluding items already represented by unread Team/new evidence/Agent-result cards
- Chrome sidebar — Activity tab powered by the same API

No new database migration or AI ranking model is introduced.

Chrome release: **v0.33.0**.
