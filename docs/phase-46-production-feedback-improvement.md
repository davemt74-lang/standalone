# Phase 46 — Production Feedback & Model Improvement Loop

Phase 46 turns Phase 45 production evidence into governed improvement work.

## Boundary

Phase 46 may:

- cluster repeated production incidents and negative outcome signals,
- preserve evidence identifiers and hashes,
- let administrators classify/triage cases,
- create human-written sanitized evaluation or training proposals,
- require explicit redaction and reuse attestations,
- approve proposals with deterministic approval hashes,
- publish approved sanitized examples into the governed Phase 37 corpus,
- create Dataset Registry drafts that select those approved examples,
- maintain a permanent production regression-case library,
- surface unresolved work in Notifications, Agent Now, Action Center, Model Health, and Model Registry.

Phase 46 must never:

- copy raw production prompts or model output automatically into reusable data,
- update ai_settings,
- change Phase 44 deployment stage or traffic,
- roll back a deployment,
- transition Model Registry lifecycle,
- create a model version,
- freeze a dataset automatically,
- launch an evaluation run,
- launch training,
- promote or deploy a candidate,
- ask an LLM to decide what should be trained or deployed.

## Improvement cases

Evidence is clustered deterministically by model/version, governed route, and failure signature.

Sources include:

- Phase 45 model-health incidents,
- negative Phase 45 outcome signals such as stale/discarded output or downstream failure.

A case stores references and hashes only. It does not duplicate the underlying production prompt or model output.

Human classifications:

- Untriaged
- Model defect
- Prompt / tool defect
- Data defect
- Provider / runtime defect
- Expected behavior
- No action

Human statuses:

- New
- Investigating
- Ready for evaluation
- Ready for training
- Resolved
- No action

Repeated identical evidence is deduplicated and does not inflate recurrence counts.

## Sanitized proposals

Only actionable human-triaged cases may create reusable proposals.

Proposal types:

- Evaluation / regression case
- Training example

Each proposal requires human-written:

- sanitized input/scenario,
- expected behavior/output,
- optional sanitized context,
- purpose justification.

Before approval, an administrator must attest:

1. raw production/private material has been removed or rewritten, and
2. the sanitized example is approved for the requested evaluation/training reuse.

The proposal content is hashed before approval. Approval creates a second hash binding the content hash, approver, time, and reuse boundary.

Any content change after approval invalidates publication.

## Corpus publication

Publication is explicit and atomic.

Approved examples become the special governed object type:

`model_improvement_example`

Eligibility is intentionally narrow:

- evaluation proposal → `evaluation_eligible=1`, `training_eligible=0`
- training proposal → `training_eligible=1`, `evaluation_eligible=0`
- both → `shared_retrieval_eligible=0`
- both → `commercial_training_eligible=0`

The corpus contains only the sanitized proposal, never the underlying production prompt/output.

If corpus admission fails, the transaction rolls back and the proposal does not remain falsely published.

## Regression library

Publishing an evaluation proposal creates a permanent `data_model_regression_cases` record linked to:

- the improvement case,
- governed model version,
- route,
- sanitized query/scenario,
- expected behavior,
- immutable case hash.

Future evaluation datasets can include corpus type `model_regression_case`.

## Dataset handoff

Phase 46 can create a **draft** Dataset Registry record for:

- Evaluation: corpus type `model_regression_case`
- Training: corpus type `model_training_example`

This is only a draft policy.

The existing Dataset Registry still requires a separate human freeze action before any dataset can be used.

The existing Evaluation Harness and Training Registry remain the only systems that can run evaluation or training.

## Existing-evidence backfill

Administrators may backfill Phase 45 incidents and negative outcome signals after upgrading.

Backfill is idempotent:

- evidence links are unique,
- existing cases are reused by cluster key,
- repeated scans do not duplicate reusable evidence.

## Existing-surface integration

Phase 46 uses existing product surfaces:

- Notifications
- Agent Now / Needs Attention / Next Up
- Action Center → Review
- Model Health incident detail
- Model Registry
- Admin navigation

## Auditability

Improvement case events record:

- case creation,
- triage,
- proposal creation/update,
- proposal approval,
- publication into corpus.

Evidence bundles contain identifiers and hashes.

The Admin export contains case/evidence/event records and human-sanitized proposals only. It explicitly excludes raw production prompt/output material.

## Next boundary

A later controlled-training phase may consume frozen training datasets containing approved Phase 46 examples.

That later phase must still require an explicit training launch and the existing Phase 41–45 governance chain. Phase 46 itself never performs that action.
