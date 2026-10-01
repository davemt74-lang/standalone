# Sponsored Research Section 4A — Unified Agent Integration Audit

**Baseline:** merged Sponsored Workspace S3, PR #250, commit `df2f69e2bee707c95fc5ccd8273dc45e77d07471`. No second Agent/runtime, Task, Team, submission, notification or automation implementation is authorized by this audit.

## Verified existing interfaces

| Domain | Existing entry point | Current behavior | 4B–4E integration rule |
| --- | --- | --- | --- |
| Project ownership | `sponsored_workspace_access` | Sponsor account manager or approved researcher with matching accepted participation and assigned Agent | Resolve the exact assignment before furnishing any Agent context; no sponsor access to researcher private Desktop or Library |
| Campaign specification | `sponsored_research_campaign_by_public`, `sponsored_project_builder_normalize` | Canonical current revision, scope, milestones, structured deliverables and deadlines | Use an explicit bounded read model; freeze the accepted revision/terms references; do not give agents cross-participant threads |
| Accepted participation | `sponsored_research_participation_get`, `sponsored_research_campaign_terms_latest` | Explicit terms/version acceptance, conflict/NDA rules, approved researcher eligibility | Do not treat mere public campaign visibility as an Agent assignment |
| Agent assignment | `sponsored_project_assignment`, `sponsored_project_agent_context` | Current Agent relationship and latest submission status; personal Agent ownership required by `sponsored_project_assign_agent` | Pair assignment with participation and current Agent ACL; keep existing Agent private storage |
| Chat | `agent_chat_context_options`, `agent_chat_context_item` | Selectable Research, Teams, annotations, owned files; bounded context revalidated by viewer | Missing `sponsored_project` selector/read adapter, and explicit assigned-agent context injection |
| Proposals | `agent_action_capabilities`, `agent_action_create_proposals`, `agent_action_confirm_execute` | Governed Research proposals with human confirmation, expiration, workspace state checks and audit | Add only bounded sponsored capabilities with current campaign revision and assignment checks; no direct unconfirmed Sponsored Project writes |
| Tasks, reports and source evidence | `research_task_create`, canonical Agent workspace and report APIs | Tasks, evidence and immutable Reports already live in assigned personal Research project | Scope proposed tasks to the assigned Agent's original Research project; never make a parallel sponsored task engine |
| Submission | `sponsored_project_submit` | Checks active assignment, submit authorization, accepted current terms, valid evidence assets, immutable hashes and submission revision rules | Reuse exactly this service after explicit human submission approval; preserve review and compensation policies |
| Progress | `sponsored_workspace_post`, `sponsored_workspace_milestone_states` | Attributed append-only sponsor and per-researcher posts; informational milestone journal, not canonical task status | Agent drafts can propose but do not autonomously publish project-wide posts; private researcher thread only and approval gated |
| Automation | `research_automation_workflows`, `research_automation_execute` | Bounded scheduled research workflow with owner access and input-hash dedupe | Add a Sponsored Project adapter for milestone deadline, blocker and revision signals; do not spawn redundant schedulers |
| Notifications | `notification_create`, `notification_object_access`, `notification_url` | Notification dedupe and per-object ACL, existing submitted/reviewed Sponsored submission notification links | Missing dedicated permission-checked `sponsored_project` update/deadline notification object and deep link |
| Samples | `sponsored_project_sample_projects`, `sponsored_workspace_demo_updates` | Toggle-controlled, read-only examples with no real assignments | Exclude demos from Agent context/proposals, automation, notifications and payments |

## Evidence-based missing connections

**4B — Agent project awareness.** Build a bounded, immutable-provenance context service that returns current project brief/specs, milestones, the *accepted* terms version and hash, own assignment and latest own submission. Expose a selected Sponsored Project context type to Agent Chat only for the assigned, approved researcher. Include current Agent ownership checks and an explicit no-access result for sponsors, strangers, unassigned researchers and disabled samples. Do not leak other participants or private messages.

**4C — Governed Agent operations.** Reuse Agent Chat proposal/confirm/stale flow and existing Research Task and Report services. Bind any action to one assignment, current campaign revision/terms acceptance and Research project. First enable planning and bounded draft work; require explicit researcher confirmation for any Sponsor Project post and final submission. Revalidate access and evidence at confirmation time. No payment, consent or sponsored acceptance action is agent-executable.

**4D — Proactive review and notifications.** Reuse existing Research Automation and notification infrastructure. Add assigned-project deadline and latest revision/blocker signals with stable dedupe keys and an ACL-validated project deep link. Never send researcher-private content to unrelated participants or use Sponsor Project data for training without separate consent.

**4E — E2E release.** Certify Sponsor creates terms/specs → approved Research Account explicitly accepts and assigns personally owned Agent → Agent receives only own project context → proposes bounded work → researcher confirms → existing task/report → human-approved submission → existing sponsor review/revision/notification, alongside denied outsider, privacy, stale-revision, paused/closed, duplicate, demo, and artifact cases.

## Ten evidence gates for 4A

1. Baseline confirmed at merged PR #250; no stale branch merged.
2. Identify and pin actual source entry points and functions for all 15 declared dependencies.
3. Reuse existing Sponsor Account/Research Account and exact assignment/participation ACL.
4. Existing selected Chat context does not already implement Sponsored Project; document gap accurately.
5. Existing Agent action registry/confirmation does not already expose Sponsored Project operations; do not duplicate the confirmation runtime.
6. Existing automation/notifications reused; document specific Sponsored signal/ACL gaps.
7. Existing submission service remains the sole final submission path.
8. Exclude samples and cross-researcher private files, messages, Team ACL grants and training consent.
9. Machine-readable integration dependency map and independent regression contract run in fast CI.
10. Exact-head PHP 8.1, PHP 8.3, MariaDB, MySQL 8 and model-governance checks green; merge only after success.

**Score policy:** 10/10 is an *acceptance result*, not a subjective code-quality guarantee; mark achieved only after all checks actually pass. This audit adds no migrations or user-facing mutations. Sections 4B–4E require their own section-gated PRs and release ZIPs after merge.
