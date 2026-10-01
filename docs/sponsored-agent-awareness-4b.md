# Section 4B — Sponsored Project Agent Awareness

## Baseline and architecture
Builds on merged 4A integration audit (PR #251) and Section 3 collaboration workspace, with no new tables, task engine, agent runtime or payment code.

`app/sponsored-agent-awareness.php` is the only new Sponsored Project Agent read adapter. Its guarded path requires: an active user, an approved Research Account, the exact accepted participation/assignment pairing, personally owned unshared nonarchived Research Agent, and authorized campaign access. Existing `sponsored_workspace_access`, `research_agent_access` and project journal SQL visibility remain the authority.

## Read model
- Latest campaign status, but brief, objective, questions, specifications, methods, deliverables and milestones come from the *accepted revision's immutable campaign revision, checking the authoritative stored hash against the live campaign hash when current*, not an unaccepted sponsor edit.
- Accepted personal participation terms (stored `terms_text`, terms version/hash), not latest unaccepted sponsor terms.
- If campaign revision or latest terms hash differs, flag the context as requiring renewed explicit acceptance; never imply the latest terms were accepted.
- Current exact assigned Agent, assignment status/submit flag and latest **own** submission status and own review note.
- At most eight workspace updates, fetched from existing SQL scope policy: sponsor project-wide notes and this researcher's own private thread only. All context strings and excerpts are bounded. No participant roster, other researcher's files or compensation details.
- Every attachment is revalidated through the existing Agent Chat context path and the automatic assigned-Agent context adapter. Selecting another assigned Agent's Sponsored Project inside a different Agent conversation fails closed. Manual and automatic project context require a privately owned Agent conversation with no additional members, rechecked for each message.
- No sample projects, sponsor credentials, unrelated authorized public projects, Team Agent sharing, training consent, action execution or submissions.

## User experience
Native Agent Chat context picker gains **Assigned Sponsored Projects** and the normal Agent conversation automatically receives only the matching Agent's own project context. No additional onboarding or separate Chat UI.

## Independent gate (10 evidence requirements)
1. Audited 4A baseline and reused canonical functions without additional migration.
2. Permission-checked personal approved researcher/accepted participation/matching assignment.
3. Personally owned Agent and Team sharing prohibited; selected cross-Agent and all shared-conversation contexts rejected.
4. Authorized context picker excludes sponsors, samples and unassigned approved researchers.
5. Read from immutable accepted campaign revision and validate its stored SHA-256 config hash and cross-check the current campaign hash without rehashing MySQL-reordered historical JSON.
6. Return only the user's own stored accepted terms/version/hash; flag revision/terms changes for reacceptance.
7. Existing per-user SQL journal filter excludes other researchers' private messages.
8. Bound context to 24,000 characters and journal count; no write-capable functions or new actions.
9. Independent static and real MariaDB/MySQL 8 tests cover positive and denied roles, isolation, withdrawal and stale terms/revisions.
10. All five exact-head CI gates green; PR merged before Section 4C starts. Full-size deployment archives must be verified before publication.

**Score:** 10/10 only when all conditions are evidenced and CI green.
