# Phase 47 — Model Improvement Campaigns & Controlled Retraining Handoff

Phase 47 closes the operational loop between Phase 46 production feedback and the existing Phase 38–45 model-governance pipeline.

It does **not** create a second training, evaluation, release, or deployment system.

## Boundary

Phase 47 may:

- group actionable Phase 46 improvement cases into a named campaign,
- select published human-approved evaluation/training proposals,
- create campaign-specific Phase 38 dataset drafts,
- lock an immutable campaign plan,
- validate when those datasets have been explicitly frozen by a human,
- create a Phase 39 baseline regression-suite draft,
- create a Phase 41 training-job draft,
- create a Phase 42 post-training readiness-plan draft after training succeeds,
- track downstream lifecycle state,
- compare original production failures with later Phase 45 evidence from the output model,
- surface campaigns through existing Notifications, Agent Now, Action Center, Model Registry, and Admin navigation.

Phase 47 must never:

- freeze a dataset,
- activate an evaluation suite,
- queue an evaluation run,
- queue or submit a training job,
- complete/manual-register a training result,
- prepare/queue Phase 42 candidate evaluations,
- transition a Model Registry version,
- sign a release decision,
- deploy or roll back a model,
- modify `ai_settings`,
- call model inference to decide campaign governance.

Those actions remain owned by the existing phases.

## Strategies

Campaign strategies are explicit:

- **Evaluation only** — production regressions become a governed benchmark/remediation project.
- **Prompt / tool / data remediation** — track non-weight changes against the same production regression evidence.
- **Controlled model training** — create a human-governed handoff into Phase 41.

Only a controlled model-training campaign can select training examples or create a training-job draft.

## Campaign scope

A campaign is tied to one logical Model Registry and one approved/active governed base model.

Only actionable, human-triaged Phase 46 cases may be linked.

Cases classified as:

- Untriaged
- Expected behavior
- No action

cannot enter a campaign.

If a Phase 46 case is tied to a governed model version, that version must belong to the campaign's logical model family.

## Approved evidence selection

Campaign evidence is selected from Phase 46 proposals.

Only proposals that are already:

- published,
- human-approved,
- redaction-attested,
- reuse-rights-attested,
- bound to a governed corpus item,

may be linked.

The campaign snapshots:

- proposal public ID,
- proposal type,
- content hash,
- approval hash,
- corpus item public ID.

Changing or replacing the approved evidence after campaign selection blocks current-use validation.

## Campaign-scoped datasets

Phase 47 extends the Phase 38 dataset selection policy with an optional:

`source_object_public_ids`

filter.

This allows one campaign to select exactly its approved Phase 46 proposal objects instead of selecting every object of the same corpus type.

Campaign drafts are:

- Evaluation → `model_regression_case`
- Training → `model_training_example`

The Dataset Registry UI shows the exact campaign-scoped source IDs.

Phase 47 only creates the draft.

A human must open Dataset Registry, inspect the preview, and explicitly click **Freeze dataset**.

## Phase 41 training compatibility

Phase 46 training examples are human-sanitized and approved for internal training reuse.

Phase 47 makes their governed corpus metadata compatible with the existing Phase 41 supervised parser by exposing:

- sanitized input as training input,
- sanitized expected output as training output,
- sanitized context as optional system context.

Because the reusable example is a new human-sanitized/rewritten artifact with explicit reuse approval, it does not carry an attribution-required training block.

It remains:

- shared retrieval ineligible,
- commercial training ineligible,
- usable only for the explicitly approved internal training purpose.

Phase 41 still revalidates every frozen item before queueing or execution.

## Immutable campaign plan

Before downstream handoff, an administrator locks the campaign.

The plan snapshots and hashes:

- campaign strategy,
- objective,
- success criteria,
- logical Model Registry,
- governed base model ID/hash,
- exact base approval-receipt hash,
- target model version label,
- selected Phase 46 cases,
- baseline recurrence/evidence hashes,
- baseline incident metric/value where available,
- approved proposal IDs/content hashes/approval hashes,
- corpus item IDs,
- evaluation/training dataset IDs,
- dataset selection-policy hashes.

After lock:

- case scope is immutable,
- proposal scope is immutable,
- replacement dataset drafts cannot be created by the campaign,
- any later mismatch in base authorization, proposal hashes, or dataset selection policy blocks handoff.

Dataset freezing itself does not change the selection-policy hash and therefore does not invalidate the locked campaign.

## Regression baseline handoff

After the evaluation dataset is explicitly frozen, Phase 47 can create a **Phase 39 suite draft** bound to the campaign's governed baseline runtime model.

For every frozen Phase 46 evaluation item, it adds one benchmark case using the approved:

- sanitized scenario/query,
- expected behavior/reference answer,
- route tag.

Phase 47 does not activate the suite and does not queue a run.

A human uses Evaluation Harness for those actions.

This completed baseline later becomes the governed template Phase 42 uses for equivalent post-training evaluation.

## Training handoff

For controlled model-training campaigns, Phase 47 requires:

- locked/current campaign plan,
- frozen/current training dataset,
- prepared baseline regression suite,
- a valid Phase 41 supervised package,
- no attribution-blocked items,
- at least one valid training example.

It then creates a **Phase 41 training draft**.

The job remains `draft`.

Phase 47 does not call:

- training queue,
- provider submission,
- manual completion,
- retry,
- cancellation.

The Training Registry remains the sole execution authority.

## Post-training handoff

When the Phase 41 job later succeeds and registers an experimental output model, Phase 47 can create a **Phase 42 post-training readiness draft** referencing the campaign baseline regression suite.

The Phase 39 baseline must already have the governed completed run Phase 42 requires.

Phase 47 does not call Phase 42 preparation.

Therefore it does not clone candidate suites or queue candidate evaluation runs.

Those remain explicit Post-Training Readiness actions.

## Campaign lifecycle

Campaign lifecycle is derived from governed downstream records:

- Planned
- Dataset preparation
- Ready for training
- Training
- Evaluation
- Release review
- Completed
- Abandoned

Phase 47 may synchronize its own campaign status, but it never changes status in the Dataset, Evaluation, Training, Model Registry, Release, Deployment, or AI Routing systems.

A model-training campaign reaches **Release review** only when its Phase 42 plan is ready.

Completion is a separate human action.

## Closed-loop outcome comparison

Once the campaign output model has:

1. gone through governed deployment, and
2. accumulated Phase 45 production observations,

Phase 47 compares the original linked failures against output-model evidence.

For metric-backed incidents:

- a matching new incident with a lower metric value → **improved**
- a matching incident at or above the original value → **recurring**
- sufficient route observations with no matching incident → **not reproduced yet**
- no relevant observations → **awaiting evidence**

For negative outcome-signal cases, the same signal type is checked against the output model.

“Not reproduced” is intentionally not described as permanently fixed.

A controlled model-training campaign cannot be marked Completed until the output model has governed production observations.

## Cross-surface lineage

Phase 47 appears in:

- Admin → Improvement Campaigns
- Notifications
- Agent Now
- Action Center → Review
- Phase 46 Model Improvements
- Dataset Registry campaign-scoped preview
- Training Registry
- Post-Training Readiness
- Model Registry

Model Registry shows whether a version is the campaign **base** or later **output**.

## Audit export

Campaign export is POST + CSRF protected.

It contains:

- campaign/plan metadata,
- hashes,
- case/proposal references,
- downstream governed handoff IDs/statuses,
- outcome comparison,
- campaign event ledger.

It does not query or export raw production prompts, raw production model output, provider secrets, or API keys.

## Full closed loop

The governed intelligence lifecycle is now:

**Production failure → Phase 45 observation/incident → Phase 46 human improvement case → sanitized approved evidence → Phase 47 campaign → Phase 38 frozen datasets → Phase 39 baseline regression → Phase 41 explicit training → Phase 42 equivalent post-training evaluation → Phase 43 human release decision → Phase 44 governed deployment → Phase 45 production observation**

## Next phase

The next appropriate phase is **Phase 48 — End-to-End Closed Loop Audit & Release Hardening**.

Phase 48 should avoid adding another major governance subsystem. It should adversarially execute the full Phase 37–47 journey, clean up cross-phase UX, verify install/upgrade/package behavior, and harden the complete intelligence lifecycle for release.
