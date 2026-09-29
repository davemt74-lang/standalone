# Phase 74 — Research Agent Simplification & Unified Workspace

## Section 1 — Canonical UI & Compatibility Map

Phase 74 simplifies the Research Agent by **repackaging mature logic behind a smaller UI**, not by rewriting the research engine.

The architectural rule is:

> Keep the logic. Remove duplicate product concepts.

Section 1 changes no existing route behavior, deletes no database objects, replaces no scheduler, and creates no new authority system. It establishes the source-of-truth compatibility map used by the remaining Phase 74 sections.

The machine-readable contract is `app/research-surface-map.php`. CI scans the repository and fails when a Research page, engine module, or API exists without an explicit consolidation classification.

## Canonical product model

Global Research becomes:

**Research → Research Agents | Portfolios**

Opening a Research Agent becomes:

**Chat | Knowledge | Research | Reports**

Knowledge contains:

**Library | Insights | Changes**

Research contains:

**Missions | Tasks | Decisions | Follow-through | Recurring**

Reports contains:

**Create | Recent | Scheduled | Published**

Portfolios uses the existing Phase 60/73 Intelligence Portfolio engine. The older Research Portfolio concept is compatibility-only.

## Classification vocabulary

### KEEP ENGINE

Preserve the implementation, data, permissions, history, API behavior, workers, schedulers, audit state, and authority boundaries. The engine may be repackaged beneath a simpler UI.

Examples include Phase 54 retrieval, Programs, Monitoring, Phase 71 Decisions, Phase 72 Action Plans, Collaborative Review, Report Studio/System Reports, Phase 59 Publishing, Intelligence Portfolios, Pattern Memory, Strategic Graph, Strategic Reviews/Briefings, Organizational Cognition, and Agent Action confirmation.

### MERGE UI

The current page contains useful functionality that will appear inside one of the canonical surfaces instead of remaining a separate product destination.

### HIDE

The route remains available for deep links, inspectors, advanced provenance, exports, object details, audits, graph views, or contextual actions, but it is not primary navigation.

### LEGACY ROUTE

The old product concept should disappear from normal navigation. A later Phase 74 compatibility section will redirect or deep-link the URL into its canonical surface while preserving inbound links and APIs.

## Core consolidation decisions

| Existing concept | Simplified presentation | Preservation rule |
| --- | --- | --- |
| Research Project | Research Agent | Keep Project IDs, Team permissions, ownership and data boundaries internally. |
| Workspace / Desktop | Knowledge / workspace mode | Keep all file, desktop, note, upload, recording and bookmark logic. |
| Project Knowledge + Agent Knowledge | Knowledge | Merge presentation; keep retrieval/evidence engines. |
| Claims / Findings / Entities / Graph | Knowledge → Insights | Keep as object types and inspectors, not separate products. |
| Monitoring / Evolution / Longitudinal | Knowledge → Changes | Keep watches and change intelligence. |
| Mission | Research → Missions | Keep Mission as the research objective. |
| Tasks | Research → Tasks | Keep Tasks as the execution unit. |
| Programs | Research → Recurring | Keep Program runtime; present recurrence as a Research setting. |
| Phase 18 Automations | Research → Recurring | Keep compatibility API/runtime; retire standalone Automations UI. |
| Phase 71 Decisions | Research → Decisions | Canonical Decision Ledger. |
| Old Decision Memory / Outcomes | Decisions → Outcome Memory | Preserve history; stop presenting a second Decision product. |
| Phase 72 Action Plans | Research → Follow-through | Keep Action Plan authority; simplify the label/location. |
| Collaborative Review | Contextual Review / Needs Attention | One review engine, surfaced on the object plus a global attention inbox. |
| System Reports / Report Studio / Report Runs | Reports | One reporting experience. |
| Research Briefs | Report template | Preserve historical data/generation logic. |
| Executive / Strategic Briefings | Governed Report types | Preserve frozen packets, Team Review, Portfolio lineage and publication gates. |
| Publications | Reports → Published | Keep Phase 59 publication authority. |
| Delivery / subscriptions | Reports → Scheduled | Keep delivery/subscription runtime. |
| Legacy Research Portfolio | Research overview | Compatibility-only. |
| Intelligence Portfolio | Portfolios | The only primary Portfolio concept. |
| Organization Command Center | Portfolios → Overview | Fold organization-wide intelligence into Portfolios. |

## Route decisions

### MERGE UI

`cross-research.php`, `research-action-plans.php`, `research-agent-knowledge.php`, `research-decisions.php`, `research-entities.php`, `research-evidence-packs.php`, `research-evolution.php`, `research-intelligence-portfolios.php`, `research-missions.php`, `research-monitoring.php`, `research-programs.php`, `research-publications.php`, `research-report.php`, `research-reports.php`, `research-tasks.php`, and `research.php`.

### LEGACY ROUTE

`report-status.php`, `report.php`, `research-automations.php`, `research-brief.php`, `research-intelligence-command-center.php`, `research-knowledge.php`, `research-outcomes.php`, `research-portfolio.php`, `research-project.php`, and `research-reviews.php`.

### HIDE / contextual inspector

Evidence, source, Claim, Finding, Entity, citation, provenance, verification, audit, evidence-pack detail/export, graph/network/timeline, impact, report diff/export/network/provenance, publish action, workspace file and source-compare routes remain deep-linkable but leave primary navigation.

The exact per-route target and reason lives in `research_route_surface_map()` and is enforced by CI.

## Engine preservation

All existing Research-oriented application modules are classified **KEEP ENGINE** in Section 1.

This includes the systems that previously acquired separate user-facing names. Phase 74 does not replace:

- semantic retrieval
- Research Agent / Project permission boundaries
- workspace/Desktop objects
- source/evidence/Claim/Finding/Entity stores
- Monitoring and longitudinal intelligence
- Missions and Tasks
- Programs and recurrence
- Phase 18 Automation compatibility
- Phase 71 Decisions and Outcome Memory
- Phase 72 Action Plans, variance and outcome handoff
- Collaborative Review
- System Reports / Report Studio / report runs
- Research Docs
- Phase 59 Publishing
- delivery/subscriptions
- Intelligence Portfolios
- Pattern Memory
- Strategic Graph
- Strategic Review
- Executive Strategic Briefings / Team Review
- Organizational Cognition
- Agent Action proposal/confirmation governance

## API compatibility

Every current Research API stays live during Phase 74.

`api/research-automations.php`, `api/research-outcomes.php`, and `api/research-portfolio.php` are explicitly marked compatibility-only in the architecture map because their user-facing concepts are being superseded.

Other APIs remain normal KEEP ENGINE contracts even when their UI moves.

No client is forced to migrate during Section 1.

## Data compatibility

**No destructive data migration** is part of this consolidation.

Phase 74 Section 1 treats all mature Research database concepts as KEEP ENGINE. Table names are not renamed merely to match the new UI vocabulary.

In particular:

- `research_projects` remains the internal permission/data boundary.
- Phase 54 retrieval remains canonical for semantic context.
- Phase 71 remains the authoritative Decision system.
- Phase 72 remains the authoritative Action Plan/follow-through system.
- Collaborative Review remains the authoritative review engine.
- Phase 59 remains the authoritative publication engine.
- Phase 60/73 Intelligence Portfolio remains the authoritative Portfolio system.
- Agent Action proposals remain the authoritative confirmation boundary for consequential Agent follow-through.

Legacy records continue to be readable and usable.

## Anti-redundancy guardrail

The Section 1 contract dynamically inventories:

- root-level Research/report/evidence/source pages
- Research-oriented `app/` modules
- Research-oriented `api/` routes

A new matching surface causes CI to fail until it is explicitly classified.

That is intentional: Phase 74 should not create another top-level Research concept without deciding where it belongs in the canonical model.

## Build sequence from this contract

### Phase 74 Section 2 — Unified Research Agent Shell

Build the real Agent application shell:

**Chat | Knowledge | Research | Reports**

Persist the selected Research Agent across tabs. Keep Project internal. Preserve Desktop/Library as workspace modes rather than standalone research systems.

### Section 3 — Unified Knowledge UI

Package Library, retrieval, evidence, Claims, Findings, Entities, Monitoring, Evolution, provenance and verification into **Library | Insights | Changes**.

### Section 4 — Unified Research UI

Package Missions, Tasks, Decisions, Action Plans and Programs into **Missions | Tasks | Decisions | Follow-through | Recurring**.

### Section 5 — Unified Reports UI

Package System Reports, Report Studio, report runs, Research Docs, Briefings, scheduling, delivery, review and publication into **Create | Recent | Scheduled | Published**.

### Section 6 — Portfolios & Global Attention

Keep Intelligence Portfolios, fold Command Center into Portfolio Overview, remove the old Portfolio product surface, and make review/attention contextual.

### Section 7 — Legacy Route & Navigation Compatibility

Redirect/deep-link old product routes into canonical surfaces, remove duplicate navigation, preserve API contracts and inbound links.

### Section 8 — End-to-End Simplification Release

Audit desktop/mobile UX, authority boundaries, permissions, legacy-link compatibility, PHP/MySQL regressions, historical upgrades and production packaging to 10/10.


## Section 2 — Unified Research Agent Shell

Section 2 establishes the visible Research Agent application shell without rewriting any research engine.

The canonical Agent workspace is now:

**Chat | Knowledge | Research | Reports**

The same selected Research Agent is carried across all four tabs.

### Identity translation

The existing system intentionally uses two identifiers on different surfaces:

- Agent/Knowledge/Research/Reports use the durable Research Agent public ID.
- Agent Chat continues to use the existing Agent conversation public ID.

The unified shell translates those identifiers through the existing `research_agents` relationship. No new identity record, Project, conversation, or mapping table is introduced.

The shell also preserves:

- the underlying `research_projects` permission/data boundary
- Team-scoped Agent access
- the existing Agent conversation
- the existing Agent workspace
- the existing Agent monitoring/automation relationship
- the existing Agent name and lifecycle

### Workspace modes

**Library** and **Desktop** remain available from the shell, but they are presented as workspace modes rather than peer research products.

They continue to open the existing Home/Agent canvas using:

- `workspace=library`
- `workspace=desktop`

Their file/document/recording/bookmark/sticky logic is unchanged.

### Unified Research tab

Section 2 adds `research-agent-research.php` as the canonical Research tab.

It is intentionally a packaging layer over the existing engines:

- Missions → existing Research Missions
- Tasks → existing Research Tasks
- Decisions → existing Phase 71 Decision Ledger
- Follow-through → existing Phase 72 Action Plans
- Recurring → existing Research Programs

The new page performs no research lifecycle writes of its own.

Sections 3 and 4 will progressively bring the detailed Knowledge and Research experiences into these canonical tabs. Until then, the canonical shell deep-links into the mature pages so no functionality is lost.

### Global Research simplification

`research.php` now presents only the two global concepts defined in Section 1:

- Research Agents
- Portfolios

Each Research Agent card now exposes the same four canonical destinations:

- Open Agent
- Knowledge
- Research
- Reports

Workspace, Tasks, Programs, Monitoring, Decisions, Action Plans, Publishing, and Review Center remain functional routes but are no longer presented as competing primary choices on the global Research landing page.

### Agent switching

The shell includes a Research Agent selector whenever more than one accessible Agent exists.

Changing Agent:

- stays on the current canonical tab
- carries the correct Agent ID or conversation ID
- records the selected Agent in browser state for continuity
- never changes Project ownership
- never creates a duplicate Agent or Project

A selected Agent that falls outside a bounded list is explicitly reinserted into its shell selector, preventing context loss on older/less-recent Agents.

### Permission behavior

The shell relies entirely on `research_agent_access()` / `research_agent_by_conversation()` and the existing Team membership rules.

If Team access is revoked:

- direct Agent access disappears immediately
- the revoked Agent cannot remain the resolved shell context
- no shell-specific permission cache or authority layer exists

### Section 2 authority boundary

The unified shell is presentation/read-only infrastructure.

It introduces:

- no migration 104
- no new database tables
- no new scheduler
- no new worker
- no new research state
- no Decision or Action Plan transition
- no report-generation replacement
- no new permission model

The shell helper contains no INSERT, UPDATE, DELETE, CREATE TABLE, or ALTER TABLE operations.

### Section 2 permanent gates

CI verifies:

- exactly four primary Agent tabs: Chat, Knowledge, Research, Reports
- stable Agent identity across all four tab URLs
- Chat conversation ↔ Agent resolution
- Library/Desktop remain workspace modes
- Team Agent permission/revocation behavior
- no duplicate Agent or Project creation during navigation
- the global Research landing page exposes Agents + Portfolios rather than the previous long research menu
- each current engine remains reachable from the unified Research tab
- no migration 104
- package inclusion for the shell helper, JS, unified Research page, tests, and documentation


## Section 3 — Unified Knowledge UI

Section 3 turns the existing Research Agent Knowledge dashboard into the canonical three-view knowledge surface:

**Library | Insights | Changes**

This is a presentation consolidation only. It does not replace the mature knowledge, evidence, monitoring, verification, provenance, source intelligence, or longitudinal engines.

### Library

Library is the canonical home for captured material and retrieval-ready knowledge: Sources, Annotations, files, Documents, recordings, bookmarks, imported VP3 research, recent evidence, semantic retrieval/index state, and the existing Library/Desktop workspace modes.

### Insights

Insights packages Claims, Findings, Entities and relationships, evidence gaps, contradictions, source-health intelligence, verification state, provenance, citations, Evidence Packs, and Claim/Entity graph inspectors. Verification remains an evidence-state review system rather than a truth score.

### Changes

Changes packages Monitoring watches, candidate-source discovery, monitoring events, source-change intelligence, longitudinal snapshots, material changes, confidence movement, and Evolution history.

### Authority and compatibility

Section 3 introduces no migration 104, new knowledge authority, duplicate Claim/Finding/Entity store, replacement retrieval index, monitoring worker, verification system, or rewritten provenance history. Existing APIs, workers, history and advanced routes remain live.

### Section 3 permanent gates

CI verifies the exact three-view model, stable Agent identity across views, Library/Desktop/VP3 reachability, Insight inspector reachability, Monitoring/Evolution reachability, Team revocation, presentation-only helper behavior, no migration 104, PHP 8.1/8.3 static gates, MySQL regression, model governance, and production package inclusion.


## Section 4 — Unified Research UI

Section 4 turns the Research Agent Research tab into the canonical five-view execution surface:

**Missions | Tasks | Decisions | Follow-through | Recurring**

The page reads the existing authoritative engines directly. It does not create a parallel lifecycle, scheduler, Decision store, Action Plan store, or Program runtime.

### Missions

Missions remain the outcome-driven research objective. The unified view surfaces Mission status, priority, sub-question progress, and success-criteria progress, then deep-links into the existing Mission editor for planning, execution, evidence, review, completion, and history.

### Tasks

Tasks packages the existing Research Plan / Task engine into the canonical Research workspace. Active, waiting, review, and complete counts come from the existing Task summary. Plan rows preserve priorities, completion gates, review state, deliverables, and the mature Task editor.

### Decisions

Decisions reads the Phase 71 Decision Ledger for the selected Research Agent. Decision status, rationale, confidence, reconsideration, Team Review, and Outcome Memory remain authoritative in Phase 71. The unified view is a focused Agent-level index; detailed Decision governance remains in the existing Decision Command Center.

### Follow-through

Follow-through is the user-facing placement for Phase 72 Action Plans. The unified view reads only Action Plans belonging to the selected Agent and exposes their lifecycle, source Decision, due state, and stale-source signal. Execution governance, variance, milestones, reviews, cognition, and final outcome handoff remain in the Phase 72 Action Plan engine.

### Recurring

Recurring makes Research Programs the primary recurring model. Program status, cadence, run health, next run, material-change handling, deliverables, and history remain in the existing Program engine.

Phase 18 Research Automations remain live as a compatibility system for historical scheduled and watch-triggered workflows. They are discoverable from Recurring, but they are no longer presented as a competing primary Research product.

### Authority and compatibility

Section 4 introduces no migration 104 and no new persistence. The helper is presentation-only. The canonical page preserves deep links into Missions, Task Plans, Decisions, Action Plans, Programs, and legacy Automations. Team membership and the existing Research Agent / internal Project boundary remain authoritative.

### Section 4 permanent gates

CI verifies the exact five-view model, stable Agent identity, existing engine reachability, item-level deep links, selected-Agent filtering, Team access and revocation, no duplicate Agent/Project creation, no migration 104, website/extension CSS parity, PHP 8.1/8.3 regressions, MySQL 8 journeys, and production package inclusion.


## Section 5 — Unified Reports UI

Section 5 turns the Research Agent Reports tab into the canonical four-view reporting surface:

**Create | Recent | Scheduled | Published**

The implementation deliberately reuses the existing System Reports, Report Studio, Research Document, intelligence-delivery, Collaborative Review, Phase 59 publishing, and strategic-briefing engines. It introduces no replacement report store, scheduler, review system, publishing workflow, or migration 104.

### Create

Create is the single report-authoring entry point. It contains the existing System Report catalog and Report Studio builder, keeps Saved Presets as reusable configurations instead of a separate top-level product, and links Portfolio / Executive Strategic Briefings back to the existing Portfolio briefing engine so frozen packets, Team Review, lineage, and publication gates stay authoritative.

### Recent

Recent combines Report Run history with the existing Intelligence Inbox. Report freshness, comparisons, provenance, Agent handoff, delivered/suppressed/failed cycles, and optional Research Document creation remain backed by the existing report and delivery engines. A derived Research Document can move directly into Published without altering the immutable source Report Run.

### Scheduled

Scheduled packages Report subscriptions around the existing Research Program scheduler. Presets, Programs, delivery policies, pause/resume/archive, manual delivery, notification delivery, Agent Chat delivery, and audit history remain in the Phase 69 runtime. Section 5 adds no worker or scheduler.

### Published

Published is the Research Agent-level front door to Phase 59 Collaborative Review, Approval & Publishing. It filters publication workflows to the selected Agent or its internal Project boundary, exposes publication state and immutable publication history, and deep-links into the mature governed workflow for reviewer assignment, anchored discussion, approval gates, owner approval, explicit publishing, and distribution.

Published documents remain immutable snapshots. Living Research Documents may continue changing after publication.

### Compatibility aliases

Legacy Report Studio URLs remain valid during Phase 74:

- `run`, `studio`, and `presets` resolve to **Create**
- `inbox`, `delivery`, and `history` resolve to **Recent**
- `subscriptions` and `schedule` resolve to **Scheduled**
- `publishing` and `publications` resolve to **Published**

Existing APIs and deep links remain live.

### Section 5 permanent gates

CI verifies the exact four-view Reports model, stable Agent identity, Report Studio/preset reachability, Report Run and delivery history, Program-owned scheduling, Phase 59 publication authority, Project-scoped publication filtering, strategic-briefing reachability, compatibility aliases, inherited Team/Project permission boundaries, no duplicate persistence, no migration 104, website/extension CSS parity, PHP 8.1/8.3 regressions, MySQL 8 journeys, and production package inclusion.


## Section 7 — Legacy Route & Navigation Compatibility

Section 7 turns the Section 1 compatibility map into runtime behavior without deleting any mature Research engine, database store, API, or advanced inspector.

Generic legacy product entry points now resolve into the canonical Phase 74 UI:

- Research Automations → Research → Recurring
- Research Brief → Reports → Create using the Research Brief template
- Project Knowledge → Agent Knowledge → Library
- Outcome Memory → Research → Decisions
- Legacy Research Portfolio → canonical Research home
- mapped Research Project → Agent Chat
- generic Review Center → Portfolios → Overview / review attention
- Organization Command Center → Portfolios → Overview

Object-specific legacy links remain reachable where they still provide advanced history, editing, review, or compatibility behavior. The compatibility router also accepts `legacy=1` as an explicit escape hatch for advanced screens.

The router never creates or mutates Research state. It only resolves the existing Agent↔Project relationship and emits canonical GET redirects. Projects that do not have a Research Agent mapping are left on their historical page rather than losing access.

The canonical Research UI no longer advertises the old standalone Automations product. Phase 18 automation runtime and API contracts remain intact underneath Recurring Research.

During the audit, `report.php` and `report-status.php` were identified as Trust & Safety moderation routes rather than Research Report routes. They are explicitly excluded from the Research compatibility inventory and remain unchanged.

Section 7 adds no migration 104, no new scheduler, no new worker, no new permission model, and no new authority system.


## Section 8 — End-to-End Simplification Release & Hardening

Section 8 closes Phase 74 as the production release gate for the simplified Research Agent product.

The canonical user journey is now:

**Chat → Knowledge → Research → Reports → Portfolios**

Research home remains the global entry for Agents and Portfolios. The Research Agent shell keeps one identity across Chat, Knowledge, Research, and Reports. Knowledge packages Library / Insights / Changes. Research packages Missions / Tasks / Decisions / Follow-through / Recurring. Reports packages Create / Recent / Scheduled / Published. Portfolios owns organizational rollups and global attention.

All mature engines, APIs, data stores, permissions, provenance, collaboration, publishing, automation, Decision, Action Plan, Program, Portfolio, and cognition logic remain authoritative underneath these simpler surfaces. Legacy routes translate into the canonical UI where safe; object-specific advanced screens and the explicit `legacy=1` compatibility escape hatch remain available.

Phase 74 is intentionally schema-free: **migration 103 remains the schema boundary**. Section 8 adds no migration 104, no parallel Research engine, no duplicate authority layer, and no new worker.

Final acceptance requires the complete Phase 74 Section 1–7 contract set, the integrated Research Agent journey, legacy-route compatibility, PHP 8.1/8.3 regression, MySQL fresh-install and historical upgrades, package smoke validation, extension packaging, and exact-head/merge zero-diff verification.
