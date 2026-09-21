# Phase 26 — Research Provenance & Audit Ledger

Phase 26 adds deterministic lineage and explicit immutable audit receipts without introducing a trust score.

## Provenance graph

The project manifest traces:
- Source Versions
- Annotations and capture hashes
- Claims and exact evidence
- Findings and Claim links
- immutable Report Versions and recomputed snapshot hashes
- explicit report citations
- collaborative Reviews
- the current viewer's permission-scoped Decision Memory

## Verification semantics

- Report snapshot hashes are recomputed from the exact stored immutable snapshot JSON.
- Audit receipt hashes are recomputed from canonical stored manifest JSON.
- Source content hashes are shown as recorded values unless the exact original hashing input is independently available.
- A valid hash is an integrity statement, not a truth/quality score.

## Audit receipts

- created only by explicit user action
- creator-scoped
- still require current project access
- immutable after creation
- later project changes appear as drift rather than rewriting the receipt
- downloadable as JSON

## Release

- Migration: `20260921_033_research_provenance_audit_ledger.sql`
- Chrome extension: `0.26.0`
- Dedicated suite: `tests/phase26-research-provenance-db.php`
- Merge only after exact-head historical CI + Phase 26 suite + two-package build are green.
