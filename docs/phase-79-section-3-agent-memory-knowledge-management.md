# Phase 79 Section 3 — Agent Memory / Knowledge Management

## Goal

Give users direct control over what a Research Agent may remember/use without creating a second knowledge system or destroying the evidence/provenance already stored by Annotated.

The canonical surface remains:

**Research Agent → Knowledge → Library | Insights | Changes**

Agent Memory management lives inside **Knowledge → Library**.

## Existing systems reused

This section deliberately reuses:

- Research Agent / Project permission boundaries
- Phase 54 unified retrieval index
- Phase 67 unified Knowledge UI
- Source / Annotation / workspace provenance
- Team membership and Project write authority
- Agent Chat conversation runtime

It does not create a replacement retrieval engine, a second Knowledge database, or a second Agent identity.

## Durable memory governance

Migration 114 adds three narrow governance ledgers:

### `research_memory_controls`

One control per Project + knowledge object.

- `retrieval_state`
  - `inherit` — follow canonical source/project access
  - `include` — explicitly eligible for Agent retrieval, without overriding source permissions
  - `exclude` — never supply this object to future Agent retrieval
- optional user correction text
- correction hash / actor / timestamp

The source object remains untouched.

### `research_memory_events`

Append-only change history for:

- creation
- retrieval-state changes
- corrections
- correction removal
- restoration to inherited behavior

### `research_memory_usage`

Records each knowledge object actually supplied through unified retrieval to a Research Agent conversation, including:

- Agent
- user
- triggering conversation message
- object type / ID
- locator
- timestamp

## Retrieval behavior

Memory controls are enforced inside the canonical `research_retrieval_search()` path after existing access checks.

This is intentional:

1. Source / Team / Project access is evaluated first.
2. Memory governance may further restrict retrieval.
3. An explicit `include` can never bypass an existing permission boundary.
4. `exclude` removes the object from future Agent context immediately.
5. A correction is presented as an explicit user-provided authoritative note while the original captured material remains visible as original evidence.
6. Correction text is searched directly so a correction becomes discoverable immediately without waiting for a destructive index rewrite.

The retrieval index therefore stays an auditable representation of the underlying source while the governance layer controls Agent use.

## Privacy presentation

The Knowledge UI shows effective privacy derived from the underlying object and Project:

- Public
- Team
- Private

Memory governance does not silently widen visibility.

For objects whose source owns an explicit visibility value, that value wins. Other knowledge inherits the Team or personal Project boundary.

## Knowledge UI

Knowledge → Library includes a dedicated **Agent Memory** manager.

For every indexed knowledge item it shows:

- object type and title
- provenance/source category
- effective privacy
- retrieval state
- correction state
- Agent usage count
- last Agent use
- last source/update time

Users with Project write authority can:

- inherit source access
- explicitly include
- exclude from Agent
- add/replace a correction
- clear a correction
- inspect change history

## Agent Chat integration

Agent Chat already uses Phase 54 unified retrieval.

Section 3 records the concrete retrieval results after the triggering user message is created, preserving a durable relationship between:

**chat message → Agent → retrieved knowledge objects**

This provides the basis for later research-usage accounting without coupling paid/sponsored research into this section.

## Authority rules

- Existing Source / Annotation / Team / Project permissions remain authoritative.
- Team revocation immediately removes Agent access and memory-management authority.
- `include` never bypasses canonical permissions.
- Exclusion is non-destructive and reversible.
- Corrections do not rewrite captured evidence.
- No runtime code creates or alters schema.

## Release gates

Permanent coverage includes:

- PHP 8.1 / 8.3 syntax
- JavaScript syntax
- static architecture contract
- MariaDB full-regression DB journey
- MySQL 8 fresh-install DB journey
- migration 113 → 114 rehearsal
- repeat-safe migration application
- Team revocation
- correction / exclusion / restoration behavior
- usage ledger
- production package assertions

## Relationship to sponsored / paid research

This section intentionally does **not** implement sponsored research.

Its usage ledger and provenance controls create useful infrastructure for a later sponsored-research system, but sponsor campaigns, compensation, licensing, dataset royalties, and training-use consent will be designed and built separately after this section is complete.
