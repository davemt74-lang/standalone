# Phase 31 — Unified Object Handoff & Continuity

Research V1 is feature-complete. Phase 31 begins the end-to-end Annotated product-unification initiative:

**Browser → Annotation → Feed → Team → Research → Agent → Publish**

The first continuity primitive is a permission-checked object reference.

## Rules

- Handoffs never create a shadow copy of an Annotation.
- Team Chat stores only object type + public ID in the existing conversation attachment table.
- Every recipient re-resolves the object through current Annotated access rules.
- A handoff never grants access.
- Public Annotations may be shared to a Team.
- Team-only Annotations may only be shared into the same Team.
- Private Annotations cannot be converted into Team-visible chat attachments.
- If access is later revoked, the message remains but its attachment becomes an unavailable tombstone.
- Research continues using its existing project-annotation relation rather than a new handoff store.
- Agent Chat receives the same Annotation public ID through its existing permission-checked context system.

## Product surfaces

- Website Annotation cards: Team, Research, Agent continuity actions.
- Team Chat: structured Annotation attachment cards.
- Chrome sidebar Annotation cards: Team, Research, Agent continuity actions.
- Cross-page Agent handoff: an Annotation opened outside Home can continue into Agent Chat without copying its content.
- Chrome v0.31.0.

No new database migration is required. Phase 31 uses the existing `conversation_message_attachments` relation.
