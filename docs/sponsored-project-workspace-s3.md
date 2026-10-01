# Sponsored Research Section 3 — Project Workspace & Collaboration

## Consolidation

Build on the already-merged Sponsored Project detail and Project Builder (migration 128), approved Research Account participation, governed sponsor campaign, frozen researcher Agent assignments, existing Research Agent Desktop/Library, Team collaboration (PR #244), submissions, reviews and compensation. Do **not** attach Team-shared Agents to a Sponsored Project implicitly: the current assignment engine explicitly requires a personally owned Agent without Team sharing. Adding cross-Team Sponsored Research requires separately scoped participation consent and access review in a later section.

## Scope

- **One workspace per canonical campaign**, at `/sponsored-project-workspace.php?project=<public_id>`, discoverable from its project-detail page only when the visitor has approved sponsor or accepted assigned researcher access. Enabled sample projects have a non-operational preview, including for signed-out visitors.
- **Sponsor:** scoped roster of accepted assigned contributors, status of existing submissions, project-wide announcements and private messages addressed to one active assigned researcher. Access is checked through the existing sponsor-account manager guard.
- **Researcher:** their own Agent, existing Agent Desktop/Library, project and Reports links; only public project-wide sponsor updates and their own participant thread. No access to the other researchers' roster, private messages, Agent files, personal research or compensation.
- **Progress:** an append-only, author-attributed journal. Entries may reference milestones defined in the immutable Project Builder specification. Shared milestone status projects only sponsor-wide sponsor-authored updates, never private researcher notes; it is not the canonical task or acceptance state.
- **No duplicate engines:** no extra Projects, Tasks, Team membership ACL, new Agent storage, research submission review or payment ledger. The new migration 129 adds one narrow append-only progress table.

## Security & acceptance

1. Sponsor view uses the exact approved sponsor/account membership authority, never only logged-in state.
2. Researcher access requires approved Research Account, accepted active/completed campaign participation and the *same* assigned Agent/participation relationship.
3. Researcher public read only includes sponsor project-wide posts. Private participant threads match the current user and campaign in the SQL WHERE clause.
4. Sponsor private posts can target only currently active accepted assigned project participants.
5. Every write rechecks current sponsor/researcher authority before mutation, with CSRF validation and bounded input validation. Terminal projects and disabled Agent assignments are read-only.
6. Journal retains the authenticated author identity and role, never the browser-submitted author. Canonical audit events contain only update metadata, not private message bodies.
7. Demo projects do not create messages, assignments, tasks, evidence or compensation; the global Admin sample-data toggle immediately removes their pages when OFF.
8. Researcher Desktop and Library links resolve by current canonical Agent access. The sponsor cannot browse a researcher's private workspace through Sponsored Research.
9. Runtime, view and migration contracts plus real MariaDB/MySQL integration tests demonstrate cross-researcher isolation, revocation, audit provenance, sponsor broadcasts, disabled sample mutations, same-assignment binding and read-only terminal mode.
10. Exact-head PHP 8.1/8.3, targeted database and model-governance gates must all be green before merge. Package from merged baseline; test ZIP CRC, expected migration/content, SHA-256 and source ancestry before delivering.

**Acceptance score:** 10/10 only once all ten are evidenced by code inspection, passing checks and verified packaging. Do not declare 10/10 while checks remain pending.

## Related legacy PRs

- PR #244 Team collaboration is already merged and authoritative; this workspace does not duplicate team file rights.
- PR #236 is an old branch for compensation post-merge hardening. Its assignment-reopen protection has been superseded by current assignment guards. Its compensation-void behavior needs an independent reconciliation against the current locking architecture, not a stale merge into this workspace.
- PR #182 is obsolete as-is because it asserts migration 103 is the final release boundary, whereas this project has advanced to migrations 128 and 129.
