# Annotated V2 — Section 1: Canonical Collaborative Agent Roster

**Branch:** `annotated-v2-development` (forked from frozen V1 dev merge `6733b8da7d404e262c20d3aaa066545f6e8325ac`)  
**Status:** implementation candidate; V1 live deployment acceptance remains separately tracked.

## P0 duplication audit and architecture decision (ADR-V2-001)

- `research_agents.project_id` has a deliberate **unique** constraint. `research_agent_create()` always creates a canonical Project and private Agent Conversation. Do **not** remove uniqueness to implement multi-Agent collaboration: that would require re-auditing dozens of V1 assumptions.
- `research_agent_access()` authorizes through current owner or Team membership. `team_research_resources.php` already lets an owner assign an Agent together with its own Desktop/Library to one Team. Existing `research_tasks.php`, `research_missions.php`, `research-agent-memory.php`, `research-verification.php`, `research-publishing.php`, `research-provenance.php` and the original Research Automation worker remain the only execution, evidence, review, provenance and scheduling authorities.
- A V2 collaboration is therefore **a roster linking existing, distinct canonical Agents by reference**. The designated Lead's existing Project serves as the plan's identity. A role is a suggested work specialization, **not** a permission grant. This does not create a new Team, Project, Agent Conversation, shared memory, shared Library, task queue, worker or publication authority.
- For this first private pilot, *only the current owner* may create or change the roster. Members are eligible only if they are active/private and have the same owner and either both have no Team or both belong to the **same current owner-controlled Team**. Sponsor-assigned Agents are excluded to prevent private sponsor research crossing scope. Lead is never reassigned by the member endpoint.
- A collaboration has a small explicit cap (**3 Agents total**) to bound accidental cost and noise. V2 Section 1 **cannot dispatch work or consume LLM tokens**; task/token budgets must be checked against existing AI settings before adding the Section 2 handoff runtime. Pausing/removal is immediate for the V2 roster, not for the underlying Agent's legitimate V1 tasks.
- The three V2 tables introduced in migration **130** hold only an owner-scoped plan, Agent membership/roles and append-only audit events. They intentionally do not store or clone private evidence, credentials, prompts, memory or source text. Do not put raw source content into audit event JSON.
- The new read view uses canonical `research_agent_access()` again on *every* request and each returned member. Team revocation removes the member's ability to view the plan. Lead owner mutations also recheck Project/team ownership and current sponsor exclusions inside a transaction, with a row lock for the membership cap.
- The existing owner Agent edit screen links to a simple optional roster page; the original single-Agent experience remains unchanged when no roster is created.

## Threat model and fixed boundaries

| Threat | Protection / proof |
|---|---|
| Outsider adds a private Agent | Exact owner ID checked against existing Agent and Project; negative DB fixture. |
| Team data crosses scopes | Both Agents must match current owner and Team ID; source content never copied; negative fixture. |
| Sponsor-assigned private Agent enters ordinary collaboration | Active/paused/completed assignment check on create and mutation. |
| Historic membership grants access after Team revoke | Canonical per-request Agent access; negative revocation fixture. |
| Duplicated assignments or concurrent cap bypass | Unique DB key; plan `SELECT ... FOR UPDATE`; idempotent exact assignments. |
| Role becomes implied privilege | Roster exposes `allows_cross_agent_data_access=false`; no execution or retrieval integration. |
| Unbounded autonomous LLM cost | No orchestration in Section 1, max 3 membership cap, P2 requires explicit existing budget gates. |
| Owner UI CSRF or forged state | Original session and CSRF validation, rate limiting, owner-only writes and strict role/state enums. |

## Minimal migration and rollback

`database/migrations/20261002_130_v2_collaboration_roster.sql` only adds V2 metadata. All V1 objects, foreign keys and the original one-Agent-per-Project constraint remain intact. Rollback only on a **disposable staging backup** in FK-safe dependency order (events → assignments → plans) after explicit confirmation; never automatically drop live V2 planning data. The V1 release branch does not include this migration.

## Section 1 acceptance: score each point only with evidence

1. Audit the existing one-Agent-per-Project, Team and Sponsor architecture; document why roster metadata is the smallest justified schema.
2. Approve owner-only roles, limits and source-access non-grant as an ADR.
3. New roster maps **existing** Agents; no duplicate runtime or permission authority.
4. Owner can create a plan and assign independent eligible Agents with five distinct roles.
5. Owner/Team/sponsor/public/paused cross-scope negative tests reject forged relationships.
6. Duplicate membership is idempotent, capacity is row-locked, and pause/removal preserves audit history.
7. Optional owner page is linked from existing Agent edit, CSRF-protected, and V1 single-Agent behavior is unchanged.
8. Exact-head PHP 8.1/8.3, MariaDB, MySQL8 and model-governance jobs pass.
9. Reviewed PR merges into **only** the V2 development branch.
10. Packages are built from the exact V2 merge and ZIP integrity/SHA-256 checked.

**Explicit exclusions:** Work dispatch, multi-Agent shared context, real-time messaging, model/token budget accounting, evidence challenge, networking feed, Stories automation and publication revisions are **not** implied by a completed roster. These belong to Sections 2–5 and must revalidate permissions on every handoff.
