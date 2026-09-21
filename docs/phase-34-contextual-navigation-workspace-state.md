# Phase 34 — Contextual Navigation & Workspace State

Phase 34 preserves **where the user is working** as they move through Browser, Feed, Team, Activity, Research, and Agent.

## Storage boundary

Workspace state is session-scoped and reference-only.

Website:
- `sessionStorage`

Chrome:
- `chrome.storage.session`

Stored fields are limited to:
- current user public ID
- current Team public ID
- current Research project public ID
- active object type + public ID
- active Agent conversation public ID
- surface key
- update timestamp

The state does **not** store URLs, page titles, selected text, source text, Annotation commentary, Research content, Agent prompts/responses, or browsing history.

## Current-access validation

`/api/workspace-context.php` re-resolves every stored reference against current permissions before any contextual navigation is rendered.

It checks:
- Team membership
- Research project access
- Annotation visibility + user blocks
- Source access
- Claim/Finding project access
- Agent conversation membership

When access disappears, the stale reference is dropped from the session state.

## Product behavior

- Research pages keep the current project active while navigating Claims, Findings, Activity, Agent, and Team surfaces.
- Team Chat selection updates the current Team.
- Agent conversations update the current Agent reference.
- active Annotation / Source / Claim / Finding refs remain available as a contextual return path.
- a compact workspace strip provides current Team / Research / object / Agent return links.
- **Clear context** removes only the ephemeral navigation refs. It does not delete chats, Research, memory, or application data.
- Chrome mirrors the same ref-only model using `chrome.storage.session`.

No new database migration or persistent memory system is introduced.

Chrome release: **v0.34.0**.
