# Phase 35 — Unified Action Center & Attention Routing

Phase 35 turns existing Annotated intelligence into one deterministic queue of **work that actually needs a next action**.

## No new intelligence layer

The Action Center composes from:
- Cognitive Feed observations
- Agent action proposals
- Team unread state
- collaborative Research review state
- source-change / downstream impact state
- Research gaps, conflicts, and open tasks
- Research lifecycle next-step state

It does not create another AI ranker, notification store, task database, or shadow event ledger.

## Action groups

- **Confirm** — explicit Agent writes waiting for user confirmation
- **Respond** — unread Team collaboration and reviews assigned to the user
- **Review** — source changes, stale/overdue reviews, conflicts, evidence gaps, and downstream change impact
- **Continue** — open Research tasks or the current Research lifecycle next step when not already covered by a more urgent action

## Resolution behavior

Action Center state is derived at request time. An item disappears when the authoritative condition resolves:
- Agent proposal confirmed/rejected/expires
- Team messages are read
- review is completed or no longer requires the user
- source/change impact is reviewed
- Research gap/task/workflow state advances
- access is revoked

Cognitive Feed dismissals are respected. Phase 35 does not create another dismissal state.

## Routing

Each item carries:
- direct authoritative links
- optional Agent handoff
- current workspace refs derived from the route

Website and Chrome use Phase 34 workspace state before navigation so users land in the correct Team / Research / object / Agent context.

Chrome release: **v0.35.0**.
