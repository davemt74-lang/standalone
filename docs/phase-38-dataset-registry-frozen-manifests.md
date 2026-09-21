# Phase 38 — Dataset Registry & Frozen Dataset Manifests

Phase 38 converts the governed Phase 37 corpus into reproducible, versioned dataset snapshots without training or fine-tuning a model.

## Purpose

The Dataset Registry creates an explicit boundary between live production data and anything that may later be used for retrieval evaluation, model evaluation, or model training.

The path is:

**Production object → Contribution Ledger → Rights / Consent → Derived Corpus → Frozen Dataset → Future Model/Evaluation Pipeline**

Phase 38 stops at the frozen dataset.

## Dataset purposes

Every dataset has exactly one purpose:

- **Retrieval** — shared retrieval / grounding datasets
- **Evaluation** — controlled quality and evaluation datasets
- **Training** — future model-training datasets
- **Commercial training** — future commercial model-training datasets

The purpose controls which Phase 37 eligibility flag must be true for an item to be selected.

A retrieval-eligible item is not automatically training-eligible.

## Drafts

Dataset drafts contain only:

- dataset identity
- purpose
- selection policy
- optional corpus-type filters
- item limit
- description

Drafts do not copy corpus text.

Admin can preview the current governed candidates before freezing.

The selection policy is normalized and hashed so semantically identical policy inputs produce a stable policy hash.

## Freezing

Freezing is explicit and administrator-only.

At freeze time Annotated:

1. re-runs the deterministic governed selection
2. copies the exact eligible text snapshot
3. copies metadata and eligibility snapshots
4. preserves content and provenance hashes
5. calculates metadata, eligibility, and item hashes
6. records deterministic item order
7. calculates a dataset manifest hash
8. records an immutable freeze event

After freezing, dataset definition and item snapshots cannot be edited through the Dataset Registry.

## Frozen item integrity

Each frozen item stores:

- corpus item reference
- source object identity and version
- contributor identity when available
- corpus type
- exact normalized text snapshot
- metadata snapshot
- content hash
- metadata snapshot hash
- provenance hash
- eligibility snapshot
- eligibility snapshot hash
- deterministic item hash

Manifest verification recomputes hashes from the actual frozen snapshots. It does not trust stored hash columns alone.

Directly changing frozen text or metadata therefore changes the recomputed manifest and blocks use.

## Current-use validation

A frozen dataset is historical evidence of what was eligible when it was frozen.

That does **not** mean it remains usable forever.

Before approved data export or future model use, Annotated checks each frozen item against the current Phase 37 corpus.

A dataset becomes blocked when an item:

- is missing
- is invalidated
- no longer satisfies the dataset purpose
- has changed content
- has changed provenance
- fails manifest integrity

This means later contributor opt-out or Source-rights revocation blocks future use without rewriting historical audit history.

## Export model

Two export modes exist:

### Manifest export

Available for frozen and retired datasets.

Contains identities and hashes but not frozen text.

This remains available for historical audit even when current use is blocked.

### Approved data export

Available only when:

- dataset status is frozen
- current rights and consent still validate
- content/provenance still match
- manifest integrity passes

The export includes exact frozen text, metadata, and eligibility snapshots.

A blocked or retired dataset cannot export full data.

## Retirement

Retirement prevents further use while preserving:

- dataset identity
- selection policy
- frozen items
- manifest hash
- lifecycle events
- manifest-only audit export

Retirement is not deletion.

## Admin UI

The Admin Dataset Registry is available at:

`/admin/datasets.php`

It supports:

- create dataset draft
- choose purpose
- filter corpus types
- set item cap
- preview current governed candidates
- update draft
- freeze immutable dataset
- view policy and manifest hashes
- inspect frozen items
- see current-use status
- export manifest
- export approved data
- retire dataset
- inspect lifecycle events

It is also linked from:

- Admin navigation
- Admin Home
- Data Governance

## Audit events

`data_dataset_events` records lifecycle actions such as:

- created
- draft_updated
- frozen
- exported_manifest
- exported_data
- retired

These events are operational audit records, not model-training signals.

## Versioning

Datasets use a stable slug plus incrementing version number.

Creating another dataset with the same normalized name creates the next version.

Frozen versions are never rewritten.

## Security and governance

Phase 38 does not:

- read arbitrary production tables for training
- bypass Phase 37 rights or consent
- automatically train a model
- fine-tune a model
- allow non-admin dataset lifecycle operations
- allow GET-based data export
- allow full-text export when current eligibility is blocked

## Phase 38 gates

The MariaDB suite proves:

- retrieval/training purpose separation
- closed training eligibility until explicit consent
- empty governed selections cannot freeze
- deterministic policy hashing
- exact text snapshot freeze
- no publisher-text substitution for contributor commentary
- frozen definitions are immutable
- manifest hash reproducibility
- current-use validation
- later opt-out blocks reuse
- historical snapshots remain auditable
- blocked full-text export
- retired manifest-only export
- training datasets become eligible only after explicit training consent
- lifecycle events are recorded
- direct frozen-text tampering fails manifest integrity
- dataset operations are admin-only

The static contract also verifies the Admin UI and prohibits direct model-training calls from the registry runtime.

## What comes next

Phase 38 prepares, but does not yet implement:

- dataset evaluation runs
- retrieval benchmark harnesses
- dataset splits
- model registry
- training-job registry
- model artifacts
- controlled fine-tuning
- contributor economics

Those later systems should reference a frozen Dataset Registry version rather than reading live production corpus rows directly.
