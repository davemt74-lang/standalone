# Phase 39 — Dataset Evaluation & Benchmark Harness

Phase 39 evaluates frozen Phase 38 datasets before any model-training system exists.

It adds reproducible benchmark suites, deterministic retrieval evaluation, optional configured-model inference benchmarks, human review, regression baselines, tamper-evident run artifacts, and a first-class Admin Evaluation Harness.

## Architecture boundary

The data path remains:

**Production object → Contribution Ledger → Rights / Consent → Derived Corpus → Frozen Dataset → Evaluation Harness → future controlled model systems**

Phase 39 evaluates. It does not train.

No Phase 39 code:
- fine-tunes a model
- changes model weights
- creates training jobs
- promotes benchmark output into training data
- bypasses Phase 37 rights/consent
- reads arbitrary production content outside a frozen evaluation dataset

## Dataset requirement

Formal benchmark suites require:

- a Phase 38 dataset
- status = `frozen`
- purpose = `evaluation`
- current-use validation = usable

A retrieval-purpose dataset is not silently repurposed for formal evaluation.

If contributor consent, Source rights, corpus content, provenance, or manifest integrity later changes, new benchmark runs are blocked.

Historical completed runs remain auditable.

## Benchmark suite lifecycle

A suite begins as `draft`.

Draft configuration includes:
- frozen evaluation dataset
- benchmark type
- top K
- optional configured inference model
- model benchmark instruction
- description

Draft suites can add or delete benchmark cases.

Activation freezes:
- suite configuration
- case set
- model selection
- retrieval depth

After activation, the suite is immutable. Changes require a new suite.

## Benchmark cases

Every case includes:
- label
- benchmark question
- exact expected frozen dataset item
- optional reference answer
- weight
- optional tags
- deterministic case hash
- stable position

The expected item must belong to the suite's frozen dataset.

Reference answers are optional and are used only for transparent lexical similarity metrics. They are not hidden ground truth scores.

## Retrieval benchmark

Retrieval evaluation is deterministic.

Phase 39 v1:
1. tokenizes the benchmark question
2. removes a fixed small stopword list
3. measures query-term coverage against each frozen dataset text snapshot
4. adds a bounded exact-phrase bonus
5. sorts by score, then frozen dataset position for deterministic tie-breaking

Metrics include:
- expected rank
- hit @1
- hit @3
- hit @5
- mean reciprocal rank contribution
- top score
- latency

The run summary computes weighted averages.

## Model response benchmark

Model benchmarks use an existing enabled Admin model through the normal Annotated inference adapter.

They do not create a general AI job or a training job.

For each case:

1. deterministic retrieval selects top-K frozen context
2. context is bounded
3. the configured model receives the benchmark question and frozen context
4. the model is instructed to cite dataset items as `[[CORPUS_PUBLIC_ID]]`
5. the response is stored only as evaluation output

Transparent automated metrics include:
- retrieval metrics
- expected-citation hit
- grounded token ratio
- optional reference-answer token F1
- input tokens when the provider reports them
- output tokens when the provider reports them
- latency

Automated model metrics are advisory diagnostics.

They do not replace human review.

## Human review

Each result can receive a human review with:

- decision: pass / fail / needs work
- relevance: 1–5
- groundedness: 1–5
- accuracy: 1–5
- review note

Human reviews are stored separately from automated metrics.

This prevents an automated heuristic from becoming the authoritative quality judgment.

## Run reproducibility

When a run is queued, Annotated snapshots:

- dataset manifest hash
- suite config hash
- case-set hash
- benchmark type
- selected model identity when applicable

Model snapshots include only non-secret identity/configuration fields, including a hash of the configured provider endpoint rather than the endpoint itself.

Provider API keys are never copied into evaluation runs.

Before model inference starts, the worker re-derives the current model snapshot and compares it with the queued snapshot. If model identity, provider identity/type, endpoint fingerprint, or output-token configuration changed after queueing, the run fails before sending any frozen evaluation context to the provider.

## Result integrity

Every per-case result has a deterministic result hash derived from:

- case identity
- expected rank
- retrieved evidence
- response hash
- citations
- metrics hash
- automated pass state

The completed run also receives a run hash derived from:

- run public ID
- dataset manifest hash
- suite config hash
- case-set hash
- model snapshot
- ordered result hashes
- summary hash

Integrity verification recomputes these values from stored result material.

Directly editing retrieved evidence, a model response, metrics, or the summary invalidates the completed run.

## Regression baselines

An Admin can mark an integrity-valid completed run as a suite baseline.

Future integrity-valid runs are compared against the baseline.

Phase 39 reports metric deltas and flags metrics that decline by more than two percentage points.

This is a diagnostic signal.

The UI explicitly does not turn the automated comparison into a release verdict.

A corrupted current run or corrupted baseline makes comparison unavailable.

## Run queue and worker

Runs are queued.

Workers claim runs under a database row lock to avoid duplicate execution.

Production command:

`php bin/evaluation-worker.php 5`

The optional argument is the maximum number of queued runs to process.

Retrieval and model benchmarks share the same queue.

## Run recovery

Failed runs can be requeued.

A `processing` run can be requeued only when its start time is more than 15 minutes old.

Requeue clears partial evaluation results and prior summary/run hashes.

This allows recovery from hard worker interruption without permitting arbitrary replay of active work.

## Benchmark artifact export

Completed runs can be exported from Admin as:

`annotated.evaluation-run.v1`

The export contains:

- run input hashes
- model identity snapshot
- suite configuration
- summary and hashes
- run integrity state
- per-case queries
- expected frozen item IDs
- retrieved evidence references
- model responses when present
- citations
- transparent metrics
- result hashes
- human reviews

The export is POST-only, CSRF-protected, Admin-only, and does not include provider secrets.

## Admin UI

The Evaluation Harness is available at:

`/admin/evaluations.php`

It is linked from:
- Admin navigation
- Admin Home
- Dataset Registry
- AI & LLM Admin

The UI supports:

- create suite
- select frozen evaluation dataset
- choose retrieval or model benchmark
- select configured model
- search frozen dataset items
- author benchmark cases
- activate immutable suite
- queue run
- inspect queued / processing / completed / failed states
- view run input hashes
- validate run integrity
- requeue failed/stale runs
- view automated scorecards
- set regression baseline
- compare against baseline
- inspect per-case retrieval and model outputs
- save human review
- export reproducible benchmark JSON
- retire suite
- inspect audit events

## Audit events

Evaluation events include:

- suite_created
- suite_updated
- case_added
- case_deleted
- suite_activated
- run_queued
- run_completed
- run_failed
- run_requeued
- human_review_saved
- baseline_set
- run_exported
- suite_retired

## Phase 39 tests

The MariaDB suite proves:

- only evaluation-purpose frozen datasets can benchmark
- suite and cases become immutable after activation
- run input hashes are snapshotted
- deterministic expected-item retrieval
- run/result integrity validation
- baseline integrity requirement
- separate human review
- benchmark artifact export
- latency telemetry
- worker queue claims distinct runs
- failed and stale runs can be safely requeued
- result tampering invalidates integrity
- corrupted baseline blocks regression comparison
- model identity snapshots exclude secrets
- model benchmark queueing creates no AI training job and no general AI run
- Admin-only lifecycle controls
- review score bounds
- later evaluation-consent revocation blocks new runs while preserving completed audit history
- lifecycle events are recorded

The static architecture/UI contract verifies:
- no fine-tune/training-job path
- formal evaluation-purpose enforcement
- immutable suite/case lifecycle
- queue row locking
- transparent heuristic metric names
- human review controls
- scorecard UI
- run integrity UI
- regression baseline UI
- benchmark export UI
- Admin navigation integration

## What Phase 39 does not do

Phase 39 does not decide that a model is "good" or "bad."

It stores measurable behavior and human judgments.

It also does not perform model selection automatically.

Any future model promotion system should consume:
- frozen dataset identity
- evaluation run identity
- integrity-valid benchmark results
- human review
- explicit release governance

rather than silently promoting a model from one automated score.

## What comes next

Possible next layers include:

- benchmark-suite version cloning
- stratified dataset splits
- evaluation scheduling
- richer retrieval strategies
- model registry
- model candidate comparison
- controlled training-job registry
- post-training evaluation gates
- contributor economics / attribution accounting

Those should continue to reference frozen datasets and integrity-valid evaluation runs rather than live production tables.
