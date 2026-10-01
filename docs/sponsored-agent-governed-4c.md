# Sponsored Research Section 4C — Governed Agent Operations

## Single canonical action path
Use the existing Agent Chat proposal, explicit user confirmation, expiry, stale-state protection, idempotency and append-only action events. This section adds only two bounded capabilities to that same registry: **post a private researcher progress update** and **submit existing evidence for sponsor review**. Native Research Tasks, Plans, Documents, Programs and Reports already serve this assigned personal Agent; no parallel sponsored task or AI runtime is created.

## Authorization and consent
Both actions require an explicit attached `sponsored_project` citation, current approved Research Account, matching personally owned Agent and original Research project, active accepted participation/assignment, identical current accepted campaign revision and latest accepted participation terms hash. The current Research project state hash is also checked at confirmation, while the proposed Sponsored context revision and terms hash are revalidated. No action accepts new terms on the researcher's behalf.

Private progress updates use the original `sponsored_workspace_validate_update` and `sponsored_workspace_post`, forcing participant scope and disallowing sponsor-wide broadcast or researcher-completed shared milestones. Final submissions require one to eight existing cited personally accessible Report/Document assets and call the original `sponsored_project_submit` with immutable evidence snapshots, latest linear revision chain, assignment ownership, sponsor review and original compensation rules. The Agent cannot accept/reject sponsored work, change payment state, grant training consent or broadcast confidential material.

## Concurrency
Existing Stage15 Agent confirmation holds a transaction on the pending proposal and Research project state. Its Sponsored adapters revalidate terms, assignment and cited evidence before write. Both existing canonical services can participate in the ambient confirmation transaction to avoid nested PDO transaction errors; when invoked standalone, each still owns its transaction. Confirming the same proposal again returns the recorded result rather than duplicating the post or submission.

## Ten acceptance criteria
1. Existing action registry/confirmation engine, not a second proposal system.
2. Only two scoped sponsored capabilities and one authorized assigned Agent Research project.
3. Provenance-linked exact Sponsored Project citation, accepted revision and latest terms hash.
4. Explicit user confirmation required; a proposal itself never mutates the project.
5. Private-only researcher update through existing actor-attributed append-only ledger.
6. Submitted evidence consists only of cited existing owned Reports, Documents or immutable report versions.
7. Confirmed submission retains canonical immutable snapshots and sponsor human review; never pays itself.
8. Expired, revoked, changed project/terms, cross-Agent or fabricated action cannot execute; duplicate confirmation is idempotent.
9. Static and real MariaDB/MySQL 8 tests cover create/confirm/dedupe, blocked broadcast, stale terms, evidence, original review/compensation.
10. Exact-head PHP 8.1, PHP 8.3, MariaDB, MySQL 8 and model-governance all green, reviewed 10/10, merged, full ZIP/checksum verification before 4D starts.

Score is an evidence-based acceptance gate, never asserted ahead of passing checks.
