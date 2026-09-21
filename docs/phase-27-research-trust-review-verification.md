# Phase 27 — Research Trust, Review & Verification

Phase 27 adds a transparent evidence-review layer on top of Phase 26 provenance.

## Core rule

Annotated does **not** compute a single truth/trust score.

Instead it derives separate, inspectable signals:

- evidence state: support-only, contested, contradiction-only, context-only, or no evidence
- corroboration: single source, multiple sources on one domain, or independent-domain support
- freshness: current, mixed, stale, or unknown
- source integrity coverage: whether recorded Source Version hashes are present
- source state: current version, superseded version, changed after capture, unavailable, or restricted
- human review state: reviewed-current, needs-review, disputed, abstained, mixed, stale, or not-reviewed

These signals describe evidence and review state. They do not certify truth.

## Human verification events

Migration 034 stores append-only human verification events for Claims, Findings, and immutable Report Versions.

Each event pins:

- subject hash
- decision
- canonical evidence-state snapshot
- evidence-state SHA-256
- reviewer
- timestamp
- optional note

A later Claim/evidence edit or upstream Source change makes the prior event stale; it is never rewritten.

## Agent boundary

Agent context includes the same permission-filtered evidence signals and an explicit policy:

> Unsupported, contested, stale, restricted, or human-disputed Research must be qualified and attributed. Verification metadata is not truth certification.

Phase 27 does not add direct Agent execution or autonomous Claim-status changes.

## Provenance

Verification events are added to Phase 26 provenance as append-only lineage edges. Audit receipts therefore drift when verification history changes, while old receipts remain immutable.

## Public reports

Published Report snapshots may expose deterministic support/contradiction and source-diversity signals using only the immutable public snapshot. Current private project review state is never leaked into a public Report.

## Chrome

The Research tab adds a Verification entry and advances the extension to v0.27.0.
