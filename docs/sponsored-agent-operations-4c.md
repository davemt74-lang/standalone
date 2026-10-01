# Sponsored Research Section 4C — Governed Agent Project Operations

**Baseline:** merged PR #252, commit `fd011e669552e00bdb75c633380464be189b314a`. Audit notes and implementation plan only until the first scoped action tests pass. No second Agent/action/task/submission system.

## Verified existing foundation

- `agent_action_create_proposals` already validates bounded research-project context, normalizes arguments, stores a deduplicated expiring proposal and appends immutable proposal events.
- `agent_action_confirm_execute` runs inside a database transaction, locks the proposal, rechecks Research project permission and state hash, and revalidates selected governed source-state hashes. Reuse this for Sponsored task proposals.
- Native `research_task_signal_plan` and `research_task_plan_add_task` create tasks under the existing personally owned Agent and project. Do not select another project's default Agent.
- `sponsored_agent_awareness_access` + `sponsored_agent_awareness_project_context` identify exactly approved, accepted personally owned assigned Agents and expose their accepted terms/configuration hashes. `sponsored_agent_awareness_private_conversation` must still pass at proposal creation **and** confirmation.
- `sponsored_workspace_post` begins its own transaction and rechecks the workspace ACL. `sponsored_project_submit` likewise begins its own transaction, locks the actual assignment and latest submission, freezes evidence snapshots and notifies only after commit. **Do not call either inside `agent_action_confirm_execute`'s already-open PDO transaction**: PDO does not support nested ordinary transactions.

## Gated implementation boundaries

**4C-1: Confirmed task proposals.** Extend the existing action capability map with `sponsored.create_task` using explicit Sponsored Project public ID, exact assigned Agent public ID, accepted campaign revision/config hash, accepted terms hash, and bounded task fields. At proposal creation and human confirmation, enforce: attached `sponsored_project` refs, same personal Agent's existing Research project, approved and active participant/assignment, accepted-current terms, private owner-only conversation and exact campaign state. At execution route through the existing Agent-specific task plan and task service; do not create a parallel Sponsored Tasks table or grant task creation if reconsent is needed.

**4C-2: Confirmed private project progress.** Agent Chat may draft an update for the researcher's *own participant thread only*. Treat it as a separate governed user approval flow with its own idempotency and fresh assignment/terms validation; call the canonical `sponsored_workspace_post` only outside another transaction. Do not permit Agent-authored sponsor broadcasts, sponsor milestone completion, direct notification creation or impersonated contributor identity.

**4C-3: Confirmed evidence submission.** Agent prepares a reviewable list of existing Report/Document/Report-version IDs, exact versions and project provenance. Obtain explicit human confirmation and recheck own Agent, current accepted terms, allowed asset ACL and revision lineage. Execute `sponsored_project_submit` in its own transactional boundary; reuse its existing snapshot, linear-revision, dedupe and notification behavior. On stale state reject without a partial confirmed result; never grant direct sponsor review, consent, training-use or compensation actions to the Agent.

**4C-4: Cross-boundary certification.** Test fresh and stale context/proposals, private shared-conversation denial, withdrawn/paused/closed assignments, stale terms, wrong Agent, unrelated project, cross-researcher assets, duplicate confirmation, rejected proposals, evidence hashes, sample OFF/ON no-execution, and explicit user approval before any actual mutation. End-to-end tests must show no duplicate engine.

## Ten acceptance checks per independently merged subsection

1. Works with exact merged baseline and current existing capability registry.
2. Reuses canonical Project Builder, workspace, personal Research Agent and task/submission services.
3. Proposal context binds exact project, assignment, Agent ID and accepted configuration/terms hashes.
4. Read/execute policies verify active approved Research Account and personal private Agent conversation.
5. No cross-researcher resources, Team sharing, sample writes or sponsor privilege escalation.
6. User sees precise proposed mutation and explicitly confirms or rejects it.
7. Every confirmation revalidates current state and is idempotent under duplicate requests.
8. Transaction boundaries are safe; no nested PDO transaction or unlogged partial writes.
9. Separate exact-head PHP 8.1, PHP 8.3, MariaDB/MySQL 8 and model-governance/security coverage.
10. Only after checks are green: merge, verify build ancestry and full-size Website + Chrome ZIP archive CRC and SHA-256. Never label unverified work 10/10.
