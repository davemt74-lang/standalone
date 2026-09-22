# Phase 45 — Production Model Observability & Outcome Monitoring

Phase 45 closes the loop after governed deployment by measuring what models actually do in production.

## Boundary

Phase 45 is an observation, evidence, and alert layer.

It may:

- record production inference telemetry,
- associate runs with governed model versions, deployments, routes, and rollout stages,
- capture latency, token usage, provider/runtime failures, optional cost estimates, and outcome signals,
- calculate rolling health snapshots,
- compare served and Shadow activity counts,
- create and update model-health incidents,
- notify administrators,
- surface incidents in Agent Now and Action Center,
- accept human incident review,
- export privacy-safe telemetry and audit evidence.

It must never:

- update `ai_settings`,
- change a Phase 44 deployment stage or traffic percentage,
- call governed model lifecycle transitions or rollback,
- launch training,
- promote a candidate,
- choose a replacement model,
- delegate rollback/deployment decisions to an LLM.

A threshold breach is evidence for a human decision. It is not a deployment command.

## Production telemetry

Every completed or failed `ai_run` can produce one immutable `data_model_observations` record.

The observation snapshots:

- runtime model,
- governed model version when identifiable,
- Phase 44 deployment when identifiable,
- governed route key,
- source task type,
- rollout stage,
- Shadow/non-Shadow status,
- run status,
- actor plan tier,
- latency,
- input/output tokens,
- optional estimated cost,
- normalized error class,
- scope identity,
- an integrity hash.

Prompt text and model output text are deliberately excluded from the observability record.

### Cost estimates

AI models may optionally store input/output USD cost per million tokens. Phase 45 converts actual token usage into micro-USD. If pricing is not configured, cost remains unknown rather than inferred from a hardcoded provider price.

## Outcome signals

`data_model_outcome_signals` attaches bounded -1..1 evidence to an observed run.

Initial automatic signals include:

- `downstream_applied` when Annotation Intelligence or Research Workspace Intelligence was successfully applied,
- `stale_discarded` when the result could not be applied because authoritative state changed.

The generic signal API supports future product evidence such as accepted, regenerated, corrected, abandoned, reviewer-positive/negative, and downstream success/failure without changing the telemetry schema.

## Rolling monitoring policy

Each deployment gets a monitoring policy with:

- rolling window,
- minimum samples,
- maximum failure rate,
- maximum p95 latency,
- optional maximum average input/output tokens,
- optional maximum average micro-USD cost,
- optional minimum average outcome score.

Policies are hashed. During Shadow/Canary periods, policy can also bound candidate-vs-baseline drift with maximum failure-rate delta and maximum p95-latency delta. Drift checks require enough samples in both cohorts. Editing a policy does not change Phase 44 state.

## Health snapshots

Health snapshots are append-only metric records for a deployment route/window. Metrics include:

- sample/completion/failure counts,
- failure rate,
- average and p95 latency,
- average input/output tokens,
- average/total estimated cost when available,
- outcome signal count and mean score,
- candidate and baseline cohort metrics,
- candidate-vs-baseline failure-rate and p95-latency deltas,
- Shadow-candidate observation count,
- served observation count,
- threshold breaches.

The metrics JSON is SHA-256 hashed so later incident review can refer to the exact evidence state.

## Incidents and human review

A breached threshold creates or refreshes a deduplicated incident for the deployment/route/metric.

Incident states:

- Open
- Investigating
- Accepted
- Resolved

Human review is recorded in an incident event ledger. Resolving or accepting an incident does not change deployment routing.

Open/investigating incidents are surfaced to administrators through:

- Model Health Admin,
- Notifications,
- Agent Now / Needs Attention,
- Action Center → Review,
- Model Registry production-health history.

Rollback remains an explicit Phase 44 operation.

## Shadow integration

Phase 44 Shadow requests now carry an internal `deployment_shadow_route` reference so Phase 45 can correctly attribute candidate Shadow telemetry to the production route being mirrored. Shadow output remains non-user-visible and excluded from response/cognitive lineage.

## Privacy

The Phase 45 export contains telemetry, hashes, policy, snapshots, incidents, and incident events. It does not export prompts or model output text.

## Workflow

Phase 45 uses the Phase 44.5 CI process:

1. Normal branch pushes run fast PHP 8.1/8.3/static checks plus targeted Phase 37+ governance integration.
2. Phase 45 DB and architecture tests join those targeted runners automatically.
3. When feature-complete, add `[phase-gate]` to the PR title.
4. Full historical PHP 8.1/8.3 regression and MySQL 8 must pass.
5. Only then are the website and Chrome-extension packages built.
