# Phase 59 — Collaborative Review, Approval & Publishing

Phase 59 extends the existing Phase 21 Review Center and Phase 24 Living Research publication/version system. It does not create a second review engine, document store, evidence index, or report-version mechanism.

## Workflow

Living Research Doc → Phase 21 review round → anchored evidence-aware discussion → approval gates → explicit owner publication → immutable Phase 24 report version → controlled distribution.

A publication workflow pins an exact Research document revision. Later document edits invalidate an active approval and require a new review round. Prior review rounds remain immutable historical records.

## Review assignments

Phase 59 adds reviewer roles and required/optional assignment metadata to existing review assignments. A publication can distinguish reviewers from approvers. When explicit required approvers are assigned, ordinary reviewer approvals do not satisfy the publication approval-count gate. Required change requests or disagreements remain blocking.

The requester cannot review their own request. Agents never cast reviewer votes, satisfy owner approval, or complete reviews.

## Evidence-aware discussion

Publication reviews can attach threads to:
- document sections
- Claims
- Sources
- citations
- Phase 57 task results
- Phase 58 Program deltas
- general review discussion

Threads are durable, replyable, and explicitly resolved/reopened. Unresolved threads can block publication.

## Approval gates

Phase 59 supports:
- required human approvals
- project-owner approval
- completed/non-stale Review Center review
- zero unresolved anchored threads
- zero failed linked Phase 57 task completion gates
- zero open high-severity Research contradictions
- current evidence freshness

All configured gates must pass against the exact pinned document revision before publishing.

## Immutable publication snapshots

Publishing reuses `research_report_publish()` and existing `research_report_versions`. The immutable version records:
- publication workflow
- Research document object
- exact document revision number and public revision ID
- approval snapshot
- normal project Research snapshot
- exact document content snapshot and hash

The living document may continue evolving after publication. It cannot mutate the published version.

## Distribution

A workflow can distribute through existing in-app notification infrastructure to selected current project collaborators, current Team members, and existing Living Research subscribers. Live report access is checked again before an explicit recipient notification is sent. Distribution outcomes are audited.

## Agent Chat

Two governed actions are exposed:
- `research.prepare_publication_review`
- `research.publish_approved_document`

Both remain subject to existing Agent-action confirmation. The second still executes the full Phase 59 approval-gate and owner-authority checks; confirmation is not a bypass.

## User surfaces

- `research-publications.php` is the publishing workflow hub: Draft, In Review, Changes Requested, Approved, Published, Archived.
- `research-reviews.php` remains the human Review Center and now shows publication reviewer roles and anchored threads.
- Research document editors expose **Review & publish**, saving dirty document state before opening a workflow.
- Now surfaces overdue publication reviews, requested changes, and publication-ready work.

## Deployment

Apply:
`20260923_057_collaborative_review_approval_publishing.sql`

Phase 59 adds no new worker. Existing workers and Phase 58's 16-worker release schedule remain authoritative.
