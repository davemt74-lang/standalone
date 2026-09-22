# Phase 42 — Post-Training Evaluation & Promotion Readiness

Phase 42 closes the controlled training loop without collapsing training, evaluation, and release governance into one automatic system.

The flow is:

**Successful Phase 41 training → Experimental Phase 40 model → Equivalent Phase 39 benchmarks → Human review → Regression/gate checks → Tamper-evident readiness packet → Human Phase 40 lifecycle decision**

A readiness packet is not an approval, promotion, activation, or routing decision.

## Why Phase 42 exists

Phase 41 deliberately ends with an experimental model.

Phase 42 answers the next questions:

- Which governed benchmark definitions must the trained model run?
- Is the trained model being compared against the exact same frozen evidence and benchmark cases as its governed base model?
- Did the candidate run complete with valid hashes?
- Has a human reviewed the candidate output?
- Did configured quality metrics regress beyond policy?
- Do the existing Phase 40 release gates pass?
- Can all of that evidence be frozen into an auditable packet before a human makes the lifecycle decision?

## Inputs

A Phase 42 plan requires:

- a successful Phase 41 training job
- valid Phase 41 completion integrity
- current training rights/integrity
- the registered output model version
- an output runtime model binding
- the exact Phase 41 base model
- the exact Phase 40 approval receipt used to authorize that base model
- one or more active Phase 39 model benchmark suites

The output model must remain experimental until the readiness packet is created.

After a packet is ready, later explicit human transitions to candidate/approved/active remain historical Phase 40 decisions and do not create new Phase 42 packets.

## Governed baseline suites

A selected baseline suite must:

- be active
- be a Phase 39 model benchmark
- be bound to the exact Phase 41 base model runtime
- have a completed baseline run
- pass Phase 39 run integrity
- have that baseline run already linked as governed Phase 40 evidence to the base model version

This prevents Phase 42 from comparing against an arbitrary or unofficial benchmark.

## Equivalent candidate suites

Phase 42 does not mutate the baseline suite.

For each selected baseline suite it creates a new candidate suite bound to the trained runtime model.

The clone preserves:

- frozen evaluation dataset
- top-K
- prompt template
- case labels
- queries
- expected dataset items
- reference answers
- weights
- tags
- benchmark metric configuration

The candidate suite is activated and a new Phase 39 run is queued.

The Phase 40 benchmark fingerprint is recomputed immediately.

If the candidate fingerprint differs from the governed baseline fingerprint, preparation fails and the transaction rolls back.

## Two workers

Phase 42 keeps orchestration separate from benchmark execution.

### Evaluation worker

`php bin/evaluation-worker.php 5`

The existing Phase 39 worker executes queued model benchmarks.

Phase 42 never calls inference directly.

### Post-training readiness worker

`php bin/post-training-worker.php 10`

This worker:

- snapshots a bounded set of evaluating/awaiting-review plans
- refreshes each plan at most once per worker cycle
- reads Phase 39 run status
- verifies run integrity
- computes equivalent benchmark comparisons
- checks human-review requirements
- links valid candidate runs into Phase 40 evidence
- applies Phase 42 regression policy
- evaluates Phase 40 release gates
- issues a readiness packet when every configured check passes

The worker never trains models and never changes Phase 40 lifecycle state.

## Plan lifecycle

Supported states are:

- `draft`
- `evaluating`
- `awaiting_review`
- `ready`
- `blocked`
- `archived`

### Draft

The Admin selects:

- successful training job
- governed baseline suites
- human reviews required per run
- minimum human pass rate
- regression tolerance
- maximum regressed metrics
- whether regression policy is required
- whether Phase 40 release gates must pass

### Evaluating

Candidate Phase 39 runs are queued or processing.

### Awaiting review

All candidate runs are complete and integrity-valid, but configured human review requirements are incomplete or below the configured pass-rate threshold.

### Ready

All configured Phase 42 checks pass and a readiness packet has been issued.

### Blocked

Examples include:

- training lineage no longer valid
- plan hash failure
- missing candidate run
- failed/integrity-invalid candidate run
- benchmark fingerprint mismatch
- regression policy exceeded
- Phase 40 release gates not satisfied

A blocked plan can be refreshed after the underlying issue is corrected.

### Archived

Historical evidence and packets remain auditable.

## Immutable plan identity

When preparation completes, Phase 42 calculates a plan hash over:

- plan public ID
- training job identity
- training completion hash
- output model version identity/hash
- baseline model version identity/hash
- exact baseline approval receipt hash
- Phase 42 policy hash
- baseline suite IDs
- candidate suite IDs
- baseline run IDs
- candidate run IDs
- benchmark fingerprints

Direct mutation of those mappings breaks plan integrity.

## Human review

Phase 42 uses the existing Phase 39 human review records.

It does not create another review subsystem.

Each candidate run can require a configured minimum number of reviews.

Human pass rate follows the Phase 40 convention:

**pass / (pass + fail)**

A `needs_work` review counts toward total reviews but does not increase the pass rate.

Automated model metrics never replace human review.

## Regression comparison

Phase 42 compares only fingerprint-equivalent model runs.

Default gated metrics are:

- automated pass rate
- expected citation
- grounded-token ratio
- reference-token F1

Latency is recorded as diagnostic context but is not a default quality gate.

The default regression tolerance is 2 percentage points.

Administrators may configure:

- require/no-require regression gate
- maximum regressed metrics
- regression tolerance

Phase 42 does not select a winner. It reports evidence against the configured policy.

## Phase 40 evidence linkage

Once a candidate run is:

- completed
- integrity-valid
- bound to the trained runtime model

Phase 42 may link that run into the output model's Phase 40 evaluation evidence.

That linkage does not change model lifecycle status.

No Phase 42 code calls the Phase 40 transition API.

## Phase 40 release gates

After all candidate runs are complete and human review/regression policy passes, Phase 42 evaluates the existing Phase 40 release gate function.

This preserves one release-governance source of truth.

If configured to require Phase 40 gates, readiness is blocked until those gates pass.

## Readiness packet

A ready plan creates an immutable packet with:

- packet schema/version
- plan public ID/hash
- training job public ID/completion hash
- output model identity/hash/status/runtime model
- baseline model identity/hash/status/approval receipt
- Phase 42 policy/hash
- plan checks
- Phase 40 gate snapshot
- every governed baseline/candidate benchmark mapping
- baseline and candidate run hashes
- metric deltas
- human-review summary
- readiness state
- explicit non-promotion boundary

The packet JSON is hashed with SHA-256.

Direct packet mutation is detectable.

Repeated refreshes with identical evidence do not create duplicate packets.

## Human lifecycle handoff

The Admin readiness page links to Model Registry after a packet is ready.

A human may then explicitly move the model through:

**experimental → candidate → approved → active**

Phase 42 does not perform any of those transitions.

If a model leaves experimental state after the packet is ready, Phase 42 preserves the historical plan and does not mint another packet merely because the human changed lifecycle status.

## Later governance invalidation

A readiness packet represents what was true when it was created.

Current readiness remains revalidatable.

If training eligibility, contributor consent, dataset integrity, model integrity, or other governed lineage later becomes invalid, a refresh can move the current plan to blocked.

The historical packet remains in the audit ledger with its original integrity hash.

This avoids pretending historical decisions never occurred while still preventing stale readiness from being treated as current.

## Admin UI

Phase 42 is available at:

`/admin/post-training.php`

It supports:

- selecting eligible successful Phase 41 outputs
- selecting governed baseline benchmark suites
- readiness policy configuration
- immutable preparation
- candidate suite/run visibility
- links into Phase 39 human-review screens
- current lineage checks
- regression metric display
- plan refresh
- packet integrity display
- readiness packet export
- explicit handoff to Model Registry
- plan archive
- audit event history

It is linked from:

- Admin navigation
- Admin Home
- Training Registry
- Evaluation Harness
- Model Registry

## System Health

Phase 42 adds a `post_training` worker heartbeat.

System Health displays:

- draft plans
- evaluating plans
- awaiting-review plans
- blocked plans
- ready plans
- archived plans

The Phase 39 evaluation queue remains separate.

## Safety boundaries

Phase 42 does not:

- fine-tune or train
- call a model provider directly
- create an alternative evaluation engine
- mutate `ai_settings`
- auto-select a model winner
- move a model to candidate
- approve a model
- activate a model

It orchestrates evidence and readiness only.

## Phase 42 test guarantees

The MariaDB suite proves:

- Phase 42 schema availability
- frozen evaluation benchmark fixture
- authentic governed Phase 40 baseline
- unlinked baseline rejection
- successful Phase 41 output requirement
- experimental output requirement
- plan creation
- plan identity hashing
- equivalent benchmark cloning
- fingerprint equivalence
- completed run → awaiting review
- valid run evidence linkage into Phase 40
- no model promotion during evidence linkage
- human review requirement
- readiness packet creation
- packet integrity
- no automatic promotion receipts
- no AI routing mutation
- explicit later human candidate transition remains separate
- no duplicate packet after human lifecycle action
- regression policy blocking
- experimental status retained on blocked outputs
- later training eligibility invalidation blocks current readiness
- historical packet persistence
- packet tamper detection
- administrator-only plan creation
- audit events

## Next phase

The next natural layer is **Phase 43 — Model Release Decision Workspace**.

That phase should improve the human decision itself rather than automate it:

- aggregate readiness packets
- side-by-side candidate/baseline evidence
- release decision notes
- independent reviewer sign-off
- model-risk checklist
- deployment/routing change plan
- rollback plan
- approval packet signing
- explicit handoff into Phase 40 candidate/approval/activation actions

Phase 43 should still preserve the rule that people make the release decision.
