# Recent Build Code Review — Phase 44–49

This review covers the recently built governed model lifecycle and release-candidate operations from Phase 44 through Phase 49.

Reviewed baseline:

- Phase 44 — Governed Model Deployment & Rollout
- Phase 44.5 — Development Workflow & CI Hardening
- Phase 45 — Production Model Observability & Outcome Monitoring
- Phase 46 — Production Feedback & Model Improvement Loop
- Phase 47 — Model Improvement Campaigns & Controlled Retraining Handoff
- Phase 48 — End-to-End Closed Loop Audit & Release Hardening
- Phase 49 — Release Candidate Deployment & Operational Hardening

The baseline reviewed was merged development commit `2824a6d8108af671a7c39b2682f998fa450c2c74`.

## Initial review score

The first complete cross-phase review scored the recent build **8.4/10**.

| Area | Initial score | Primary findings |
| --- | ---: | --- |
| Architecture & boundaries | 9.2 | Phase ownership was strong, but orchestration constructors were not uniformly transaction-composable. |
| Security & authority | 9.4 | Human authority boundaries were strong; package integrity was narrower than the full deploy tree. |
| Concurrency & state integrity | 7.1 | Deployment/campaign/proposal state machines had stale-read race windows and incident creation could duplicate under concurrent evaluation. |
| Data integrity & auditability | 8.3 | Most hashes/events were strong, but some events were not atomic with state changes and idempotent actions could create misleading duplicate audit events. |
| Test & CI quality | 9.6 | Excellent layered gates, but no adversarial cross-phase concurrency/package-integrity suite existed. |
| Operations & recovery | 8.6 | Backup verification existed, but partial backups, restore-target mismatch, and exact installed-build provenance needed stronger fail-closed behavior. |

## Findings corrected

### Phase 44 — Deployment state machine

The baseline used fresh reads followed by several unconditional deployment updates.

The hardening review now:

- serializes every governed deployment mutation with a per-deployment MySQL/MariaDB advisory lock,
- re-reads deployment state after the lock is held,
- adds revision/status compare-and-swap conditions to state transitions,
- commits state changes and audit events together,
- serializes rollout checkpoint writes,
- keeps pause/resume/stop/advance idempotent against concurrent operators,
- changes full rollback ordering to restore Model Registry state before routing,
- compensates the Model Registry back to the candidate if routing/state rollback fails,
- records explicit activation/rollback compensation failures.

This removes the highest-severity race and split-brain paths found in the review.

### Phase 45 — Observability incident creation

Concurrent health evaluators previously could both observe no open incident and create duplicates.

Incident upsert is now serialized by:

`deployment + route + metric`

using the shared advisory-lock layer.

### Phase 46 — Improvement loop

Production evidence ingestion and proposal governance now use consistent concurrency semantics.

The hardening review:

- serializes incident/outcome clustering before case creation,
- serializes case triage,
- serializes proposal update/approval/publication,
- couples state changes with their audit events,
- makes identical draft saves valid instead of treating MySQL's zero affected-row result as a stale-write conflict,
- protects proposal corpus refresh across multiple concurrent Phase 47 campaigns.

### Phase 47 — Improvement campaigns

Campaign orchestration now uses one per-campaign serialization boundary.

The review hardens:

- case/proposal scope edits,
- dataset-draft creation and linkage,
- plan locking,
- regression-suite creation,
- Training Registry draft creation,
- Post-Training Readiness draft creation,
- campaign close,
- automatic lifecycle synchronization.

Automatic sync now uses compare-and-swap and explicitly refuses to overwrite `completed` or `abandoned`, preventing a stale background sync from reopening a human-closed campaign.

Repeated add/remove operations no longer write false audit events.

Dataset and Post-Training constructors were made outer-transaction aware so Phase 47 can create-and-link downstream objects atomically.

### Phase 48 — Closed-loop release audit

The representative closed-loop release sample now excludes abandoned campaigns.

An abandoned experiment can remain historical evidence without becoming the current release-readiness lineage sample.

### Phase 49 — Release identity and recovery

The selected-file package fingerprint was replaced by a deterministic fingerprint of the complete package-managed deploy tree.

The release fingerprint:

- includes all package-managed application/admin/API/bin/worker/assets/database/docs content,
- excludes runtime secrets and mutable upload/storage paths,
- excludes source-only CI/test metadata,
- rejects managed symlinks,
- fails closed when a managed file cannot be hashed.

Runtime preflight now verifies the deployed `RELEASE-MANIFEST.json` against the full-tree fingerprint.

The package workflow generates the manifest from the **staged deploy tree**, not the source checkout.

Package smoke:

- extracts both ZIPs,
- rejects secrets/repository internals,
- rejects symlinks,
- verifies the embedded Chrome ZIP equals the standalone Chrome ZIP,
- recomputes the full staged-tree package fingerprint,
- validates the installed release manifest.

Recovery hardening now:

- marks a backup `.INCOMPLETE` until creation and verification fully succeed,
- refuses incomplete or empty backup components,
- binds valid production backups to the exact installed release manifest/build SHA,
- rejects database-target mismatch,
- rejects private-storage-target mismatch,
- refuses restore planning when required tools are unavailable,
- keeps destructive restore execution manual.

## Shared concurrency primitive

The review adds a single MySQL/MariaDB advisory-lock primitive in `app/functions.php`.

It is intentionally:

- connection scoped,
- released in `finally`,
- bounded by a short acquisition timeout,
- keyed by a hashed namespace/object identity,
- used for governance serialization rather than business-data locking.

This gives Phase 44–47 one consistent concurrency model instead of independent ad-hoc behavior.

## Permanent regression coverage

The review adds:

- `tests/phase49-5-recent-build-hardening-contract.php`
- `tests/phase49-5-recent-build-hardening-db.php`

The static contract protects:

- duplicate function detection in reviewed critical files,
- required advisory-lock helpers,
- deployment stale-state guards,
- compensation ordering,
- incident/case/proposal/campaign serialization,
- closed-campaign protection,
- transaction-composable constructors,
- full-tree release fingerprinting,
- installed manifest verification,
- fail-closed restore checks,
- staged-tree manifest generation,
- package symlink rejection.

The MariaDB suite adversarially proves:

- two DB connections cannot simultaneously mutate the same advisory-locked governed object,
- outer transaction rollback removes a composed Dataset Registry draft,
- identical proposal saves remain valid,
- stale campaign sync cannot resurrect an abandoned campaign,
- abandoned campaigns are excluded from the release lineage sample,
- managed-code changes alter the release fingerprint,
- runtime secrets/uploads do not alter package identity,
- package tampering invalidates installed-manifest verification,
- managed symlinks are rejected,
- incomplete backups are rejected,
- restore database/storage mismatches are rejected.

The suite is registered in the complete historical regression and protected by the Phase 44.5 workflow contract.

## 10/10 acceptance criteria

The reviewed Phase 44–49 scope may be scored **10/10** only after all of these pass on one exact head:

1. PHP 8.1 syntax/static contracts.
2. PHP 8.3 syntax/static contracts.
3. Targeted Phase 37+ MariaDB governance chain, including the new hardening suite.
4. Full historical PHP 8.1 regression.
5. Full historical PHP 8.3 regression.
6. MySQL 8 fresh-install compatibility.
7. Hardened two-package build.
8. Full release-package smoke and full-tree manifest verification.
9. Published SHA-256 checksums.
10. Merge of the exact tested feature head.
11. Zero file differences between the tested tree and merged development tree.

A 10/10 result means **no known blocking defect remains within the reviewed Phase 44–49 scope under the current architecture and adversarial test matrix**. It is not a claim that software can never contain an unknown defect.

## Post-review recommendation

Once this review is green and merged, the next work should be actual V1.1 RC deployment validation against the production hosting environment: smoke testing, load/performance profiling, backup/restore rehearsal, and operator feedback.

Do not add another broad model-governance layer before that operational validation.
