# Phase 41 — Training Job Registry & Controlled Fine-Tuning

Phase 41 introduces controlled model-training execution on top of the governed data/model stack built in Phases 37–40.

It is intentionally not an autonomous learning loop.

The path is:

**Attributed production data → frozen Training dataset → controlled training job → experimental model version → Phase 39 evaluation → Phase 40 approval/activation**

A successful training job never approves, activates, or routes its output automatically.

## Required inputs

Every training job must bind:

- an exact frozen Phase 38 dataset
- a declared use class
- an exact approved/active Phase 40 base-model version
- an integrity-valid Phase 40 approval receipt for that base
- a target Phase 40 Model Registry
- an output experimental version label
- an immutable training configuration

### Use classes

Phase 41 distinguishes:

- **Internal training** → requires a frozen dataset whose purpose is `training`
- **Commercial training** → requires a frozen dataset whose purpose is `commercial_training`

A normal training dataset cannot be silently reused for commercial model training.

## Explicit supervision only

Phase 41 does not manufacture labels from ordinary corpus text.

Every frozen dataset item must already contain an explicit supervised example in its frozen metadata:

`training_example.messages`

or an explicit input/output pair.

Supported v1 message roles are:

- system
- developer
- user
- assistant

Each example must contain user input and assistant output, and the final message must be an assistant response.

If even one frozen item lacks a valid example, the job cannot queue.

This prevents raw annotations, sources, claims, or findings from being silently transformed into synthetic supervision.

## Attribution compatibility

A frozen item with `attribution_required=1` blocks weight training in Phase 41.

The reason is structural: a model weight update cannot reliably preserve response-level contributor attribution lineage.

Training can proceed only when the governed frozen item is eligible for the requested training purpose and does not require attribution that the resulting weights cannot satisfy.

## External/provider transfer

Provider API jobs require an explicit Admin acknowledgement that the frozen training package will be sent to the configured provider endpoint.

Phase 41 v1 provider execution supports:

- OpenAI providers
- explicitly configured OpenAI-compatible providers

The API credential remains encrypted in `ai_providers` and is never copied into:

- training jobs
- provider snapshots
- attempts
- logs
- events
- model versions

Provider snapshots preserve only provider/runtime identity and configuration needed for audit.

## Provider training format

Provider API execution uses JSONL chat-message training examples.

The worker:

1. revalidates current dataset rights/integrity
2. rebuilds the exact package
3. verifies its SHA-256 against the queued package hash
4. uploads it as a fine-tuning file
5. creates a supervised fine-tuning job
6. records provider file/job identifiers
7. polls provider job status
8. imports provider events as sanitized training logs
9. handles success/failure/cancellation
10. removes the uploaded training file on a best-effort basis after terminal completion

Provider calls occur in the training worker, not normal page requests.

## Job lifecycle

Training states are:

- draft
- queued
- preparing
- prepared
- submitted
- running
- cancel_requested
- succeeded
- failed
- cancelled
- blocked

### Draft

The administrator can edit:

- dataset
- use class
- base model
- output registry
- output version label
- executor
- hyperparameters
- provider acknowledgement
- retry limit
- cost-rate metadata

### Queue

Queueing freezes:

- dataset manifest hash
- base-model version hash
- rights snapshot
- provider snapshot
- exact JSONL package hash
- config hash
- overall job hash

Queued and later jobs cannot edit their training decision.

### Preparing

The worker rebuilds and verifies the exact package before any execution.

### Prepared

Manual/self-hosted jobs stop here until an administrator registers an output artifact.

### Submitted / Running

Provider API jobs have an external fine-tuning job ID and are polled by the worker.

### Succeeded

The output artifact is registered as a new Phase 40 **experimental** model version.

### Blocked

A job becomes blocked when current rights or immutable integrity no longer validates.

Blocked jobs are not silently retried.

## Rights revalidation

Training permission is not treated as a one-time queue check.

Phase 41 revalidates current use before:

- queueing
- package export
- worker preparation
- provider submission
- output registration

For active provider jobs, the worker also revalidates on polling.

If contributor consent/source rights/integrity changes while training is active, Annotated attempts provider cancellation and marks the job blocked.

If provider training already completed when the rights check fails, the provider result remains in audit history but Annotated does not register it as a Model Registry output.

This does not pretend that already-completed external weight updates can be retroactively erased.

## Base-model governance

A base model must be:

- Phase 40 status `approved` or `active`
- identity-hash valid
- backed by an integrity-valid Phase 40 approval receipt

Provider API execution additionally requires a bound enabled runtime model on a supported provider.

Directly editing a model status to `approved` is therefore insufficient to make it a valid training parent.

## Training package identity

The queued job stores:

- package SHA-256
- package byte count
- example count
- invalid example count
- dataset manifest hash
- rights snapshot hash
- provider snapshot hash
- job hash

The worker rebuilds the JSONL from the frozen dataset snapshot and refuses execution if the rebuilt package does not match the queued package hash.

The Training Admin can export the exact package through a POST + CSRF-protected endpoint while current rights remain valid.

## Attempts and retries

Provider execution attempts are stored separately from the job.

Each attempt records:

- attempt number
- status
- provider file ID
- provider job ID
- request hash
- response hash
- failure text
- timestamps

Failed jobs may be retried only while:

- attempt count is below the configured maximum
- current dataset rights/integrity still validates
- immutable job snapshots remain valid

Previous attempts are never deleted.

## Cancellation

Draft, queued, preparing, and manual-prepared jobs can be cancelled locally before provider submission.

Submitted/running provider jobs enter `cancel_requested`; the worker calls the provider cancellation endpoint and records the terminal state.

## Logs and audit events

`data_training_logs` stores sanitized operational/provider events with log hashes.

`data_training_events` stores lifecycle/audit actions such as:

- job_created
- draft_updated
- job_queued
- package_exported
- package_prepared
- provider_submitted
- training_succeeded
- provider_failed
- provider_cancelled
- job_retried
- cancel_requested
- worker_failed
- active_training_blocked
- completion_blocked
- manual_training_completed
- actual_cost_updated

## Cost accounting

Phase 41 never hardcodes provider prices.

An Admin may optionally record a configured USD-per-million-trained-tokens rate in the job.

When a provider reports trained-token count, Annotated can calculate an estimated cost from that configured rate.

Actual cost remains a separate Admin-recorded value.

## Output model handoff

A successful provider job creates:

1. a runtime `ai_models` entry for the returned provider model name, if needed
2. a Phase 40 model version with:
   - origin = `annotated_trained`
   - status = `experimental`
   - artifact reference
   - artifact descriptor hash
   - training-job lineage

A successful manual job creates the same Phase 40 experimental output from an explicit artifact URI and SHA-256.

Neither path:

- changes `ai_settings`
- promotes to candidate
- approves the model
- activates the model

The output must return through Phase 39 evaluation and Phase 40 governance.

## Manual/self-hosted outputs

Manual jobs are useful for:

- local GPU training
- self-hosted open-source model pipelines
- external providers without a Phase 41 API adapter

The administrator exports the exact governed JSONL package, performs the training externally, then records:

- artifact reference
- artifact SHA-256
- optional runtime model binding
- actual cost

If no runtime model exists yet, the experimental Phase 40 output can be bound later while still experimental.

Runtime binding becomes immutable after candidate promotion.

## Admin UI

Training Registry is available at:

`/admin/training.php`

It provides:

- training-job creation
- internal/commercial use selection
- frozen training-dataset selection
- approved base-model selection
- target Model Registry selection
- provider/manual executor selection
- supervised hyperparameters
- explicit provider-transfer acknowledgement
- readiness/preflight report
- invalid-example report
- attribution-block report
- immutable queue action
- exact JSONL export
- provider IDs/status
- cancellation
- retry
- manual output registration
- token/cost telemetry
- execution attempts
- provider/worker logs
- lifecycle audit history
- links to Dataset Registry, Model Registry, Evaluation Harness, and AI Admin

## Worker

Production command:

`php bin/training-worker.php 5`

The optional argument is the maximum number of jobs to process/poll in one invocation.

The worker handles queued submission, provider polling, cancellation, rights blocking, and terminal output registration.

## No generic AI-job shortcut

Fine-tuning does not use `ai_jobs`.

Training state has its own registry because model training needs stronger immutable dataset/model/config snapshots, attempt history, provider IDs, rights checks, and output lineage than normal inference jobs.

## No automatic promotion

Phase 41 deliberately never calls the Phase 40 lifecycle transition API.

The output is always experimental.

The controlled loop is:

**Training succeeds → experimental version → evaluation → human review → candidate → approved → active**

## Phase 41 gates

The MariaDB suite verifies:

- frozen training dataset enforcement
- explicit supervised example validation
- valid Phase 40 base approval receipt requirement
- queue snapshot/job/package hashing
- exact JSONL export
- manual worker preparation
- manual artifact output registration
- experimental output status
- runtime binding while experimental
- provider identity snapshots without secrets
- simulated provider completion handoff
- trained-token and configured cost accounting
- no AI routing mutation
- provider transfer acknowledgement
- commercial-purpose separation
- attribution-required blocking
- no silent synthetic supervision
- direct job tamper detection
- retry limits
- cancellation
- contributor opt-out blocking before execution
- administrator-only lifecycle
- no generic `ai_jobs` dispatch
- audit events

The architecture/Admin contract separately verifies the provider endpoints, worker boundary, POST/CSRF package export, and absence of automatic Phase 40 promotion or `ai_settings` mutation.

## Next phase

Phase 42 should close the training loop with a **Post-Training Evaluation Gate**:

- detect new Phase 41 experimental outputs
- require defined Phase 39 benchmark suites
- schedule evaluation
- require human review
- compare against governed baseline/candidate models
- create a promotion-readiness packet
- never auto-approve or auto-activate

That keeps training and release governance separate.
