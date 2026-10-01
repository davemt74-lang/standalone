# Sponsored Project Builder — Section 2

## Canonical design

Build on the existing Sponsor Account, campaign, Research Agent, participation, version, report/submission/review and compensation systems. Migration 128 adds only `sponsored_research_campaigns.project_specs_json`. It does not create another project or task engine. Specifications are part of the exact campaign configuration stored in every immutable campaign-version snapshot and SHA-256 configuration hash.

## Sponsor workflow

Approved sponsors create or amend an existing campaign at `/sponsored-research.php`. The shared Project Builder opens in both the new-campaign and amendment forms, retaining previous values on edit. It accepts target audience, geography, scope inclusions/exclusions, selected research methods, up to 12 structured deliverables and up to 20 planned milestones. Deliverables require a title, one of five supported formats and acceptance criteria; optional dates and all milestones must fall on or before the submission deadline. Dated milestones must be in chronological order, but parallel same-day checkpoints are valid.

The same normalized record is available on `/sponsored-project.php?project=...` to authorized viewers, without modifying Agent assignments, scheduled research tasks, actual payments, or participant acceptance rights. Previously configured campaigns are supported through an empty specification default. The existing Sponsor Account authority and public/private/invite-only gates remain authoritative.

## Consent and revision guarantees

- All sponsor updates use the existing advisory lock, canonical campaign update, event and immutable revision ledger.
- Invalid specs, deadlines, method identifiers, excessive entry counts and malformed formats fail before database mutation.
- Once a researcher has an active or completed accepted participation, material specification changes are blocked. A later section can introduce explicit re-consent; this section never silently changes accepted deliverable obligations.
- Ancillary campaign fields may still change through the existing governed workflow; this build does not alter pre-existing participation/terms rules.
- No project specification grants AI training consent or compensation. The existing terms, explicit consent, submission/review and payment ledgers retain their authority.

## Demo parity

When Admin sample-data is enabled, each sample Sponsored Project displays representative audience, methods, deliverables, acceptance criteria, and milestones using the same project-details renderer. Examples cannot accept applications, initiate Research Agents, create submissions or trigger payments. Turning sample-data off removes sample cards and direct sample-detail URLs.

## Acceptance / 10-point quality gate

1. No duplicate project/task/payment engine.
2. New and existing sponsor campaign forms share fields and validation.
3. Every edit is an immutable, hashed campaign revision.
4. Explicit Sponsor Account access and existing public/private/invite-only visibility.
5. Validation rejects malformed structured inputs and impossible dates before database writes.
6. Existing saved specifications survive unrelated partial updates.
7. Participating researchers cannot be assigned changed obligations without re-consent.
8. Public and sample pages render the same structural specification sections without operational demo actions.
9. Fast static, MariaDB and MySQL 8 journeys cover create, update, consent locking, privacy, invalid writes and immutable lineage.
10. Required exact-head PHP 8.1/8.3 and model-governance integration CI must be green; verified Website/Extension release ZIPs and checksums follow merge.

Review score can be considered 10/10 only after all ten are evidenced and the required CI is green.
