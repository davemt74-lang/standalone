# Phase 48 — End-to-End Closed Loop Audit & Release Hardening

Phase 48 is a release-hardening phase, not a new intelligence subsystem.

It treats the Phase 37–47 architecture as one product lifecycle:

Governed corpus → frozen dataset → evaluation benchmark → governed model → controlled training → post-training readiness → human release decision → governed deployment → production observability → human improvement case → controlled improvement campaign

## Goals

Phase 48 verifies that the entire model-intelligence stack behaves as one governed system and is safe to package as a release candidate.

It adds:

- a read-only Intelligence Release Audit,
- one integrated MariaDB closed-loop audit,
- package assertions for the complete intelligence workspace,
- install/upgrade checks for the current migration set,
- cross-phase release-hardening contracts.

Phase 48 adds **no database migration** and no persistent Phase 48 state.

## Read-only Intelligence Release Audit

Admin → Intelligence Release Audit checks:

- no pending migrations,
- Phase 37–47 schema availability,
- currently active Model Registry version integrity,
- currently active model approval-receipt integrity,
- all recorded Phase 43 release-decision integrity,
- locked Phase 47 campaign plan/current-use integrity,
- one available closed-loop lineage sample.

The audit is intentionally read-only.

It cannot create or freeze datasets, activate or queue evaluations, create/queue/submit/complete training, prepare Phase 42 candidate evaluations, transition or roll back Model Registry lifecycle, sign release decisions, change deployment state, modify AI routing, or call model inference.

Release readiness is evidence, not authority.

## Integrated closed-loop database audit

tests/phase48-closed-loop-release-audit-db.php runs after Phases 37–47 on the same release-candidate database.

It independently verifies the full governance chain:

- Phase 37: sanitized Phase 46 evidence remains purpose-scoped in the governed corpus and excluded from shared retrieval/commercial training.
- Phase 38: Phase 47 campaign datasets retain exact source-object scope and valid frozen manifests.
- Phase 39: the campaign baseline remains a model benchmark containing the permanent production regression; the historical database also contains an integrity-valid completed benchmark.
- Phase 40: the campaign base model identity and approval receipt validate; all active model versions are audited.
- Phase 41: the campaign training handoff remains a draft, passes supervised-package preflight, and has zero execution attempts; a separate historical successful completion proves the execution path.
- Phase 42: the release-candidate database contains at least one integrity-valid readiness packet.
- Phase 43: sampled production evidence resolves to the exact signed Proceed decision used by Phase 44 and all recorded decisions pass integrity checks.
- Phase 44: deployment routing snapshots validate and the audit ledger preserves full activation plus governed rollback.
- Phase 45: the sampled improvement case resolves to a production-health incident with an integrity-valid health snapshot.
- Phase 46: the campaign preserves a human-triaged case plus separate published evaluation/training proposals whose content hashes validate.
- Phase 47: the locked campaign plan, datasets, regression-suite handoff, and Phase 41 draft handoff remain intact.

Phase 48 repeats the release audit and proves the audit itself does not mutate AI routing.

## Installer & upgrade hardening

Fresh install remains:

1. create/test configuration,
2. import base schema,
3. apply every bundled migration,
4. create the first administrator.

Upgrade remains:

1. administrator-only after users exist,
2. list pending immutable migration files,
3. apply pending migrations through the migration manager,
4. retain failed-migration audit information,
5. support retry-safe migration recovery.

Phase 48 has no migration of its own.

## Package hardening

The downstream package job must verify that the website ZIP includes at least:

- install.php
- upgrade.php
- current schema
- latest Phase 47 migration
- Phase 47 campaign workspace
- Phase 48 release-audit workspace
- Phase 48 documentation
- separately built Chrome extension ZIP

The package workflow remains downstream of PHP 8.1 full regression, PHP 8.3 full regression, and MySQL 8 fresh-install validation.

## Release workflow

The Phase 44.5 workflow remains authoritative:

1. ordinary PR updates run fast static/PHP plus targeted model-governance CI,
2. Phase 48 integrated DB audit joins that targeted chain automatically,
3. the final PR title receives [phase-gate],
4. full historical PHP 8.1/8.3 regression runs,
5. MySQL 8 fresh-install validation runs,
6. packaging runs only after all validation is green,
7. the exact tested feature head is merged,
8. the merged tree must have zero file differences from the green tested tree.

## Release interpretation

A green Phase 48 means the intelligence lifecycle is ready for a release-candidate deployment pass.

It does not mean every future model will be safe, every production incident is resolved, a campaign automatically improves a model, or release governance can be skipped.

It means the architecture, authority boundaries, lineage, integrity checks, install/upgrade path, regression matrix, and release packages have passed the current adversarial release gate.

## Next work

After Phase 48, the priority should shift from adding model-governance layers to real deployment smoke testing, operator UX polish, performance/load profiling, backup/restore and operational runbooks, production monitoring, and release notes/versioning.

Any future major feature phase should be justified by an actual product requirement rather than extending the governance stack for its own sake.