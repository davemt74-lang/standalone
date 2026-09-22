# Phase 40 — Model Registry & Candidate Lifecycle

Phase 40 adds a governed model-release layer above Annotated's existing `ai_models` provider/runtime configuration.

It does not train, fine-tune, or modify model weights.

## Architecture position

The intelligence pipeline is now:

**Production object → Contribution Ledger → Rights / Consent → Derived Corpus → Frozen Dataset → Evaluation Harness → Model Registry → future controlled training/runtime systems**

Phase 40 is the governance boundary between evaluation evidence and an explicitly approved model version.

## Existing AI runtime vs governed registry

Existing `ai_models` remains the operational provider/runtime configuration used by Annotated inference.

Phase 40 does not replace it.

A Model Registry version may reference an existing `ai_models` record, but governance state is separate from task routing.

This separation means:

- approving a version does not rewrite `ai_settings`
- activating a governed version does not rewrite `ai_settings`
- rolling back a governed version does not rewrite `ai_settings`
- administrators continue to control production task routing explicitly in AI Admin

## Model families

`data_model_registry` represents a logical model family.

Examples:
- Annotated Research Model
- Annotated Evidence Model
- Annotated Source Impact Model

A registry can contain many immutable governed versions.

Only one version may be the registry's governed active version at a time.

## Model versions

A version records:

- version label
- origin
- optional runtime `ai_model_id`
- architecture
- intended use
- license
- source URI
- context window
- optional artifact reference
- optional artifact SHA-256
- gate policy
- gate policy hash
- model version identity hash
- lifecycle status

Supported origins include:

- external hosted
- open source
- self hosted
- Annotated trained
- imported artifact

Phase 40 can register future Annotated-trained artifacts, but it cannot create them.

## Lifecycle

The governed lifecycle is:

**experimental → candidate → approved → active → deprecated → retired**

Optional abandonment paths allow a candidate or approved version to retire without becoming active.

### Experimental

Metadata and gate policy can still be edited.

### Candidate

Model metadata and gate policy become immutable.

Evaluation evidence may be linked or unlinked while the model is still candidate.

### Approved

Approval requires current release gates to pass.

The linked evidence set becomes immutable.

An approval receipt is created.

### Active

Activation requires:

- current release gates still pass
- a prior approval receipt exists
- that approval receipt passes integrity verification

Activating a new version automatically deprecates the previous active version and records a separate deprecation receipt.

### Deprecated

A deprecated version remains auditable and may be eligible for explicit rollback if it was previously active.

### Retired

A retired version remains in audit history but cannot re-enter the normal lifecycle.

## Model version identity integrity

Each model version stores a deterministic `version_hash`.

The hash covers:

- registry identity
- runtime model identity
- version label
- origin
- architecture
- intended use
- license
- source URI
- context window
- artifact reference
- artifact hash
- gate policy hash

Experimental edits recompute the hash.

Candidate and later versions are immutable through the application.

Release-gate evaluation also recomputes model-version integrity so direct database mutation cannot silently pass promotion gates.

## Evaluation evidence

Only completed, integrity-valid Phase 39 **model-response benchmark** runs can be linked.

The run must use the same runtime `ai_model_id` as the registered model version.

A retrieval-only benchmark cannot be used as model promotion evidence.

Approved and later versions cannot change evidence links.

## Gate policy

Phase 40 v1 supports:

- required model benchmark run count
- required human review count
- minimum human pass rate
- required evaluation-suite public IDs
- required evaluation integrity
- optional no-regression requirement
- maximum allowed regressed metrics

Required suites count only integrity-valid model-matched evidence.

A no-regression policy requires an actual Phase 39 baseline comparison. Absence of a baseline is not interpreted as proof of no regression.

Runtime model availability is also a gate.

## Human review

Human Phase 39 reviews remain separate from automated benchmark metrics.

Phase 40 can require a minimum number of reviews and a minimum human pass rate.

Automated benchmark scores never cause promotion automatically.

## Promotion receipts

Every governed status transition creates a tamper-evident promotion receipt.

Receipts snapshot:

- from status
- to status
- actor
- current model version hash
- gate policy
- gate checks
- evaluation evidence
- previous active version context
- decision note
- gate snapshot hash
- evidence snapshot hash
- receipt hash

Receipt verification recomputes all three hashes.

Changing a stored decision note, gate snapshot, evidence snapshot, or receipt identity directly in the database causes integrity validation to fail.

Activation refuses a corrupted approval receipt.

Rollback refuses a corrupted prior active receipt.

Automatic deprecation caused by activation or rollback also receives its own receipt.

## Governed activation

`data_model_registry.active_version_id` identifies the governed active version.

This is not the same thing as production AI routing.

Phase 40 deliberately does not update `ai_settings`.

This prevents an evaluation or approval workflow from silently changing which model serves a production task.

## Rollback

Rollback is an explicit administrator action.

A rollback target must:

- belong to the same logical model registry
- be approved or deprecated
- have previously been active
- have an integrity-valid historical active receipt
- still satisfy its current release gates

Rollback:

1. revalidates the target
2. deprecates the current active version
3. records a deprecation receipt
4. activates the rollback target
5. records a rollback activation receipt
6. leaves `ai_settings` untouched

## Model comparison

Phase 39 model suites are bound to a particular runtime model.

Therefore Phase 40 compares different models through an **equivalent benchmark fingerprint**, not by requiring one literal suite row to contain multiple models.

The fingerprint requires the same:

- frozen dataset manifest
- ordered benchmark case definitions
- benchmark type
- top-K
- prompt template
- metric-version/configuration excluding model identity

Only integrity-valid model runs participate.

The Admin comparison displays scorecards and human reviews side by side.

It does not rank candidates or choose a winner.

## Admin UI

Model Registry is available at:

`/admin/model-registry.php`

It is linked from:

- Admin navigation
- Admin Home
- AI Admin
- Evaluation Harness

The UI supports:

- create logical model registry
- register experimental model version
- select model origin
- bind runtime model
- define intended use/license/artifact metadata
- define release-gate policy
- edit experimental version
- view current gate state
- link/unlink eligible Phase 39 evidence
- move experimental → candidate
- approve candidate
- activate approved version
- deprecate active version
- retire superseded version
- rollback to a prior active version
- inspect promotion receipts and receipt integrity
- compare 2–6 versions on equivalent benchmark definitions
- inspect model-governance audit events
- jump to AI routing and Evaluation Harness

## Audit events

Events include:

- registry_created
- version_created
- version_updated
- evaluation_linked
- evaluation_unlinked
- status_transition
- superseded_by_activation
- superseded_by_rollback
- rollback_activated

Promotion receipts provide the immutable decision evidence behind lifecycle changes.

## Phase 40 test guarantees

The MariaDB suite proves:

- logical registry creation
- experimental version identity hashing
- experimental edits refresh the identity hash
- candidate metadata/gate immutability
- approval blocked before evidence
- mismatched model evidence rejected
- regression/human/integrity gates
- explicit approval
- evidence immutability after approval
- promotion receipt integrity
- governed activation
- activation does not rewrite AI task routing
- cross-model equivalent-benchmark comparison
- new activation deprecates prior active version
- rollback rejects never-active versions
- valid rollback restores prior active version
- rollback does not rewrite AI task routing
- receipt-note tampering is detected
- corrupted approval receipt blocks activation
- direct model-version metadata tampering breaks version integrity
- tampered version cannot be approved
- explicit retirement
- zero training/generic AI jobs created
- administrator-only governance
- lifecycle audit history

The static architecture/Admin contract additionally prevents:

- fine-tuning
- training-job creation
- generic AI job dispatch
- silent `ai_settings` mutation
- automatic model winner selection

## What comes next

Phase 40 creates the missing governance layer required before training.

The natural next phase is a **Training Job Registry & Controlled Fine-Tuning** layer where every training job must reference:

- an exact frozen Phase 38 training dataset
- an explicit base model version
- immutable training configuration
- rights/consent state
- code/runtime version
- output artifact hashes
- cost/log records
- failure state

Any output model should enter this Model Registry as a new experimental version and must return through Phase 39 evaluation before approval.
