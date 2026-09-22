# Phase 44 — Governed Model Deployment & Rollout

Phase 44 is the execution layer between a signed Phase 43 human **Proceed** decision and production AI routing.

## Boundary

A Phase 43 Proceed decision does not deploy anything. Phase 44 revalidates the signed decision, model identity, approval receipt, rollback target, active runtime binding, and current routing immediately before rollout.

Phase 44 is the first phase in the Data/Model governance sequence intentionally allowed to update `ai_settings`, and it does so only at **Full** rollout or explicit rollback. It never launches training and never asks an LLM to decide whether a rollout should progress.

## Rollout lifecycle

1. **Draft** — selects explicit governed route keys and snapshots their current baseline runtime IDs.
2. **Preflight passed** — verifies the signed Phase 43 decision, current release context, approved candidate, valid Phase 40 approval receipt, active rollback model, runtime availability, and unchanged baseline routing.
3. **Shadow** — keeps served routing on the baseline model and asynchronously mirrors the same production-shaped AI request to the fixed candidate runtime. Candidate output is recorded only as a deployment-scoped shadow run; it is never user-visible and never enters response/cognitive lineage.
4. **Canary** — after an independent human Proceed checkpoint, a bounded percentage (1–50%) of route resolutions may select the candidate while `ai_settings` stays unchanged.
5. **Limited** — after another current independent checkpoint, selected governed route keys resolve to the candidate through temporary overrides while baseline settings remain intact.
6. **Full** — after the Limited checkpoint, Phase 44 uses the existing Phase 40 lifecycle transition to activate the approved candidate, then writes only the selected `ai_settings` route columns to the candidate runtime and removes temporary overrides.
7. **Rolled back / Stopped** — staged rollouts can be stopped without changing baseline routing. Full deployments restore the exact routing snapshot first, then use the existing Phase 40 rollback path to reactivate the previously active governed version.

## Human checkpoint semantics

The deployment creator cannot satisfy the independent checkpoint. Checkpoint signatures bind to a deterministic deployment subject hash including the signed release decision, candidate/rollback version hashes, route-plan hashes, current routing hash, traffic percentage, stage, and deployment revision.

Pause, resume, stage change, or other revision-changing operations make earlier checkpoint signatures stale. There is no automatic Shadow → Canary → Limited → Full progression.

## Routing safety

`ai_setting_model_id()` remains the single existing baseline resolver. When Phase 44 is installed, it may consult a temporary deployment override:

- `shadow`: always returns the baseline model while completed baseline runs enqueue asynchronous candidate shadow inference.
- `canary`: returns the candidate only within the configured bounded percentage.
- `limited`: returns the candidate for the selected route key.
- `full`: no override remains; the governed baseline settings themselves contain the candidate runtime.

Any invalid/disabled candidate runtime, stale baseline mismatch, missing deployment schema, or resolver error fails safe to the baseline model ID.

## Auditability

Phase 44 stores:

- immutable Phase 43 decision/signature snapshots,
- candidate and rollback model-version hashes,
- before/current routing snapshots and hashes,
- monitoring policy and route-plan hashes,
- independent checkpoint signatures,
- deployment state transitions and route snapshot hashes,
- explicit full activation and rollback events.

The Admin workspace provides a POST/CSRF-protected JSON audit export.

## Phase boundary after Phase 44

Phase 44 controls deployment state and routing. Longer-horizon production quality, cost, latency, drift, and outcome analysis belongs in the next observability phase rather than silently changing rollout state.
