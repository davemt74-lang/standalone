# Admin V2.90 — Proactive Admin Intelligence & Investigation Plans

Admin V2.90 extends the V2.80 contextual Admin Agent with proactive, evidence-backed operational intelligence and durable investigation tracking.

## Scope

V2.90 adds three non-executing capabilities:

- **Proactive Admin signals** derived from the existing permission-filtered Admin dashboard snapshot and governed Action Center state.
- **Evidence attachments** stored on Admin Agent messages so the factual Admin records behind an answer survive reloads and canvas handoff.
- **Investigation plans** stored with the Admin Agent conversation, with trackable pending/done steps and internal Admin navigation targets.

The feature is intentionally implemented as a sidecar to the V2.80 privileged action path. It does not replace, bypass or broaden the existing Action Center execution model.

## Proactive intelligence

The V2.90 brief is deterministic. It uses the same authorized Admin snapshot already consumed by the V2.80 Agent and ranks currently actionable conditions such as:

- billing/dunning failures;
- past-due or paused subscriptions;
- failed overage reporting;
- failed AI/source-monitor jobs;
- stale/failing workers;
- moderation/rights workload;
- pending migrations;
- governed Admin actions awaiting review, approval or execution.

Signals are filtered by the current Admin operator's capabilities before they are returned.

## Evidence

After a normal V2.80 Admin Agent response completes, V2.90 attaches the server-authorized evidence used for follow-up. Evidence can include:

- current page context;
- authorized Admin search results;
- proactive Admin signals relevant to the prompt.

Evidence metadata is limited to internal Admin URLs. It is stored in the existing conversation attachment table under the `admin_evidence` attachment type.

## Investigation plans

When an administrator explicitly asks for investigation, triage, follow-up, next steps, resolution planning or action items, V2.90 creates a durable plan from the authorized evidence already available to the request.

Plan properties:

- conversation-scoped;
- owned by the Admin Agent thread owner;
- stored as an `admin_plan` conversation attachment;
- steps can be marked `pending` or `done`;
- internal links point back to authorized Admin surfaces;
- finishing a step records progress only—it never performs the underlying Admin operation.

Plans are deliberately deterministic and do not grant mutation authority.

## Security and governance

V2.90 preserves the V2.80 boundaries:

- the Admin Agent still cannot directly execute Admin state changes;
- `admin-operations.php` remains the authoritative governed mutation layer;
- package/lifecycle changes still require explicit intent, capability validation, a distinct reviewer, Action Center approval and normal execution;
- V2.90 does not call `admin_ops_action_execute`, `account_admin_change_package` or `account_admin_update_lifecycle`;
- another administrator cannot update plan progress in a conversation they do not own;
- evidence and proactive signals use existing capability filtering.

## UI

The full Admin Agent canvas renders evidence cards and interactive investigation plans. The sticky Admin-wide copilot renders compact evidence and plan cards and preserves the same conversation continuity established in V2.80.

## Release impact

- no new database migration;
- migration **079** remains current;
- no cron;
- no worker;
- no queue;
- no new autonomous execution authority.
