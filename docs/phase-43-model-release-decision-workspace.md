# Phase 43 — Model Release Decision Workspace

Phase 43 is the human release-decision layer above Phase 42 readiness packets.

The controlled path is now:

**Governed data → Frozen training dataset → Training job → Experimental model → Equivalent evaluation → Human review → Readiness packet → Human release decision → Explicit Phase 40 lifecycle action → Separate routing change**

Phase 43 records the human decision. It does not execute the release.

## Decision boundary

A Phase 43 outcome may be:

- Proceed to governed release
- Hold
- Reject

“Proceed” means the human review process is complete and the model may continue into the existing Phase 40 lifecycle.

It does not:

- move the model to candidate
- approve the model
- activate the model
- modify AI task routing
- call model inference
- launch another training job

Model Registry remains the only lifecycle-control surface.

AI Admin remains the routing-control surface.

## Required source evidence

A release decision starts from an unused, integrity-valid Phase 42 readiness packet whose plan is currently ready.

The release workspace snapshots:

- readiness packet hash
- candidate model version hash
- baseline model version hash
- deployment plan hash
- rollback plan hash
- risk policy hash

## Model-risk checklist

Every release decision receives a default checklist covering:

- readiness evidence
- training rights / consent
- documented limitations
- safety / abuse risk
- privacy / security
- operational capacity
- monitoring / alerting
- routing change plan
- rollback validation
- release / incident ownership

Required items cannot be marked Not Applicable.

A Proceed outcome requires all required items to be PASS.

## Rollout plan

The release draft records:

- rollout strategy: shadow / canary / limited / full
- target tasks or routing scope
- initial traffic percentage
- monitoring window
- success criteria
- monitoring / release owner
- change window
- routing notes

This is a plan only.

Phase 43 never applies routing changes.

## Rollback plan

The rollback plan records:

- target model version
- trigger conditions
- rollback steps
- recovery target
- rollback owner
- validation steps

The rollback target must:

- exist
- pass model-version integrity
- belong to the same Model Registry
- already be governed: approved, active, or deprecated
- differ from the release candidate

Phase 43 does not perform rollback.

Existing Phase 40 rollback remains authoritative.

## Independent review

The release-decision creator cannot satisfy the independent reviewer requirement.

Each reviewer signs:

- current release-decision subject hash
- recommendation
- note
- reviewer identity
- signed timestamp

Recommendations are:

- Proceed
- Hold
- Reject

If the checklist or release-plan snapshot changes after a review is signed, the reviewer’s stored snapshot no longer matches the current subject hash.

That review becomes stale and stops counting toward finalization until re-signed.

## Proceed gate

A Proceed decision requires:

- current readiness packet
- packet integrity
- model identity integrity
- baseline integrity
- valid governed rollback target
- complete deployment plan
- complete rollback plan
- all required checklist items passing
- configured number of current independent reviews
- enough Proceed recommendations
- zero current Hold recommendations
- zero current Reject recommendations

Hold and Reject decisions still require current release context and the configured number of independent reviewers, but do not require every checklist item to pass.

## Signed final decision

The final decision freezes:

- current subject hash
- outcome
- decision summary
- rationale
- current independent reviewer signatures
- final signer identity
- final signed timestamp

The deterministic decision hash is signed again into a final signature hash.

Direct database mutation of signed decision content breaks integrity verification.

## Historical lifecycle changes

After the signed decision, a human may use Model Registry to continue the Phase 40 lifecycle.

The historical signed decision remains valid when the model later becomes:

- candidate
- approved
- active
- deprecated

The model lifecycle action remains separate from the signed Phase 43 record.

## Signed export

`/admin/model-release-export.php`

Export is:

- POST-only
- CSRF-protected
- Admin-only
- blocked when final-decision integrity fails
- blocked when the source readiness packet fails integrity

The JSON export includes:

- decision hash/signature
- final signer
- readiness packet
- model/baseline identity
- risk policy
- rollout plan
- rollback plan
- checklist
- reviews
- signatures
- explicit non-execution boundary

## Admin UI

`/admin/model-release.php`

The workspace supports:

- ready-packet selection
- release-plan drafting
- rollback-plan drafting
- reviewer-count policy
- model-risk checklist
- opening independent review
- reviewer recommendations
- stale/current signature visibility
- final Proceed / Hold / Reject signing
- signed decision integrity
- export
- Model Registry handoff
- separate AI routing handoff
- archival
- audit events

It is linked from:

- Admin navigation
- Admin Home
- Post-Training Readiness
- Model Registry

Model Registry displays Phase 43 decision records but explicitly states that the records do not execute lifecycle transitions.

## Phase 43 guarantees

The MariaDB suite proves:

- valid ready packet eligibility
- exact packet snapshot
- one decision per packet
- governed same-family rollback enforcement
- invalid rollback blocks review
- creator cannot self-review
- signed independent reviews
- Proceed blocked before checklist completion
- checklist changes stale earlier reviewer signatures
- Hold review blocks Proceed
- re-signed Proceed review restores eligibility
- final signed Proceed record
- deterministic final-decision integrity
- no Phase 40 lifecycle receipt created
- candidate remains experimental
- no AI routing mutation
- separate reviewer/final signatures
- tamper detection
- signed Hold path
- Admin-only lifecycle
- audit history

The static contract additionally prevents:

- automatic Phase 40 transitions
- `ai_settings` mutation
- delegated release decisions to inference
- retraining from the release workspace

## Next phase

Phase 44 should focus on **Controlled Deployment & Routing Execution**.

Unlike Phase 43, it would execute explicitly authorized deployment changes—but only from a valid signed Proceed decision.

That phase should add:

- deployment execution record
- exact signed decision reference
- explicit target task-route changes
- canary/shadow rollout controls
- monitoring window state
- release observations
- stop/rollback action
- explicit human confirmation at each execution boundary
- routing change receipts
- automatic detection of release-health signals, but no autonomous “keep/rollback” decision

The signed human Phase 43 decision should remain the authorization source.
