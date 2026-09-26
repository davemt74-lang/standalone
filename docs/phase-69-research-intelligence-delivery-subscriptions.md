# Phase 69 — Research Intelligence Delivery & Subscriptions

Phase 69 turns per-Research-Agent Report Studio presets into recurring intelligence without introducing another scheduler.

## Authority model

The existing **Research Program worker remains the only scheduler**.

A Phase 69 subscription binds:

- one user
- one Research Agent / project
- one saved Report Studio preset
- one existing Research Program

The Program owns cadence and cycle execution. Phase 69 only decides whether that cycle should produce and deliver the subscribed Report.

No new worker, cron, queue, scheduler, retrieval index, or document store is introduced.

## Delivery policies

Subscriptions support four policies:

- **Every Program cycle** — generate and deliver every completed/quiet cycle.
- **Only when this Report scope changed** — deliver only when the prior Report Run is no longer current.
- **Only when material Program changes affect this Report** — requires material Program deltas and a changed subscribed Report scope.
- **Only when stale** — deliver once the prior Report exceeds the configured stale window.

The first eligible cycle establishes a baseline Report Run.

## Delivery channels

Phase 69 supports:

- in-app notification
- Research Agent Chat update for private/non-Team Research Agents
- user-specific Intelligence Inbox
- user-specific Now/cognitive-feed item

Team Research Agents intentionally do **not** receive user-specific subscription messages in shared Agent Chat. The delivery remains available through that subscriber’s notification, Inbox, Now feed, and live Agent context.

Email/export delivery is intentionally deferred.

## Intelligence Inbox

Each Research Agent’s Reports workspace adds:

- **Subscriptions**
- **Intelligence Inbox**

The Inbox preserves:

- delivered cycles
- viewed cycles
- intentionally suppressed cycles
- failed cycles
- triggering Program run
- material-change count
- generated Report Run
- prior Report Run
- comparison manifest
- delivery channels
- reason code

This makes “nothing meaningful changed” auditable without notifying the user.

## Noise control and deduplication

Program-run delivery uses a deterministic subscription + Program-run dedupe key. Reconciliation or worker retries therefore cannot deliver the same subscription cycle twice.

Change policies use the previous Report Run’s Phase 68 freshness/manifest logic before generating another Report. Report-derived artifacts remain excluded from authoritative freshness state.

## Report lifecycle boundary

Phase 69 preserves the permanent Phase 68 distinction:

**Research knowledge → Report Run → intelligence delivery → optional Create Document**

Scheduled/subscribed delivery never creates a Research Document.

## Saved presets and Programs

Phase 68 presets remain the canonical report configuration. Phase 69 does not duplicate report parameters in the subscription.

A subscription may only bind a preset and Program owned by the same Research Agent. If a preset is already associated with a Program, the subscription cannot silently attach it to a different one.

## Agent awareness and governance

Research Agent Chat receives user-specific delivery context showing active/paused subscriptions and recent deliveries.

The existing governed Agent Action system adds:

- `research.create_report_subscription`
- `research.update_report_subscription`
- `research.set_report_subscription_status`

The Agent may propose these actions but cannot execute them without explicit user confirmation.

## Program integration

Phase 69 hooks into two existing Program lifecycle points:

- quiet/suppressed Program completion
- completed Research Program plan reconciliation

Delivery failures are non-fatal to the Program itself and are recorded in delivery history.

## Migration 083

`20260926_083_research_intelligence_delivery_subscriptions.sql`

Migration 083 adds:

- `research_intelligence_subscriptions`
- `research_report_deliveries`
- `research_report_delivery_events`

User deletion preserves historical delivery rows via nullable subscriber ownership. Missing/deleted subscription dependencies cause subscriptions to pause or delivery to suppress rather than silently running against the wrong scope.

## Phase boundary

Phase 69 includes Program-driven Report execution, material-change/freshness policies, subscriptions, delivery history, notifications, Agent awareness, Now integration, and Intelligence Inbox.

It intentionally does not:

- create Documents automatically
- add email delivery
- add a second scheduler
- run presets from a new worker
- broaden project permissions
