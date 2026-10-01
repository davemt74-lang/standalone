# Sponsored Research Section 4C — Governed Agent-Guided Project Operations

## Architecture decision

4B established bounded, read-only, accepted-revision Sponsored Project context inside the existing personal Agent Chat. 4C routes six practical workflows from the role-scoped project workspace into that same Agent conversation. We deliberately **do not** duplicate Agent Tasks, report services, proposal/confirmation, milestone authority, Workspace update storage, Sponsor submission or financial systems.

The existing Agent action executor `agent_action_confirm_execute` owns a database transaction. The canonical `sponsored_project_submit` and `sponsored_workspace_post` each independently open their own transaction and own notifications/audit. Nesting either in an Agent action would risk partial writes and invalid notifications. Therefore **final submission and private Sponsor updates remain explicit human actions in their existing forms**. The Agent can prepare evidence/checklists/drafts but cannot silently submit, post, accept new terms, mark sponsor milestones complete or request payment. A later separately planned system change could add native agent-triggered submission only after a safe exactly-once cross-runtime design is available.

## Canonical workflow

1. User enters their own Sponsored Project workspace. Active, approved, personally assigned researchers with currently accepted project revision and terms see the six-card Agent-guided operations panel. Sponsor, other researchers and demonstration visitors do not see action controls.
2. Plan research → personally assigned Agent Chat with exact, read-only Sponsor context; existing confirmed Research Plan actions only.
3. Prepare tasks → existing Research Task proposal/confirmation pipeline inside the Agent's original Research project.
4. Prepare report → existing Report Run and Research Document proposals with explicit confirmation, normal evidence and source lineage.
5. Milestones/blockers → read existing Sponsor-wide status and own participant thread; propose steps without changing sponsor milestone status.
6. Draft update → Agent creates suggested private update text, never publishes it. Researcher reviews and posts through the existing project workspace form.
7. Review readiness → Agent checks own ready report/accepted terms and advises; researcher explicitly selects and submits via the existing `/research-sponsored-projects.php` forms. This preserves native snapshot, version, review and compensation rules.

Every deep link includes the existing personal Agent conversation, exact Sponsored Project ID and an enumerated action key. `home.php` resolves the request server-side through the 4B exact-agent authorization adapter **before** emitting a safely JSON-encoded existing Agent Chat handoff event. No prompt or user-supplied project content can bypass that server verification. The actual Agent Chat system revalidates the context again at send time.

## Ten-point review gate

1. No new DB tables or duplicated Agent/Task/Report/Submission engines.
2. Exact approved researcher + matching accepted assignment and personally owned conversation, checked on every handoff.
3. Block Team-shared, guest/demo, sponsor, revoked, paused, closed, unaccepted-revision and changed-terms attempts.
4. Only fixed, server-authored prompts with bounded, authorized `sponsored_project` context; query-string prompt injection is impossible.
5. Plans, Tasks, Report Runs and documents reuse existing Agent Chat proposal and explicit confirmation.
6. Updates and final submissions always use separate, native human approval forms; no nested transactions or automatic payments.
7. Milestone review distinguishes information from authoritative task/sponsor completion state and exposes only allowed project messages.
8. Responsive, accessible action cards, existing Chat destinations and clear manual submit link.
9. Fast independent static architecture/security contracts and real MariaDB/MySQL 8 database denial/revocation/read-only journeys.
10. All five exact-head gates (PHP 8.1/8.3, MariaDB, MySQL 8, model governance) green, reviewed branch merged, full Website + Chrome ZIPs validated against checksum and contents.

**Acceptance score:** treat 10/10 as verified only after all ten specific criteria and required exact-head workflows pass. This is an Agent-guided first-party workflow, not an automatic sponsor-submission capability.
