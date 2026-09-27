# Research Missions V1

Research Missions turn an outcome-oriented research question into a durable, Agent-owned mission without introducing another scheduler.

## Product contract

A Mission answers five durable questions:

1. What are we trying to learn or decide?
2. What outcome defines success?
3. Which sub-questions remain unresolved?
4. Which existing Research Agent, project, plan, and Program own the work?
5. What is the Mission's current lifecycle state?

The architecture is:

**Question → Mission → Mission Plan → Research Tasks → Evidence/Claims/Findings → Mission Progress → Result**

Programs remain the recurring/scheduled execution mechanism. Tasks/Plans remain the governed unit of autonomous work. Missions orchestrate those systems; they do not duplicate them.

## Section 1 — Mission Foundation

Section 1 adds:

- durable `research_missions` owned by an existing Research Agent/project
- explicit research question, objective, success definition, scope, constraints, priority and lifecycle
- revisioned Mission configuration
- durable success criteria with evaluation state
- durable sub-questions with answer state and confidence
- append-only Mission event history
- optional foreign-key slots for the existing Research Plan and Program that later sections will orchestrate
- permission-aware list/detail/summary runtime
- governed mutation API with CSRF/session mutation auth and rate limits

Section 1 does **not** add a worker, cron entry, queue, scheduler, or new autonomous execution primitive.

## Section 2 — Mission Planning & Task Orchestration

Section 2 turns the durable Mission definition into the existing Research Task system without creating another execution primitive.

- Mission sub-questions compile into evidence-backed Research Tasks.
- A final synthesis task depends on every sub-question task and answers the primary Mission question.
- Mission task graphs use the existing Research Plan dependency model, completion gates, Task queue, Agent execution runtime, and living deliverable system.
- Plans are created paused so the task graph can be reviewed before any autonomous work is queued.
- An explicit start action activates the Mission and its existing Research Plan, queues only dependency-ready work, and creates the normal living Research Plan deliverable.
- Pause/resume uses the existing Research Plan controls and queue semantics.
- Plan creation is transaction-locked and idempotent so concurrent requests cannot create duplicate Mission Plans.
- Mission planning still does not create a Research Program. Program binding remains Section 4.

### Section 2 invariants

- Planning alone never queues autonomous work.
- Planning alone never creates the living deliverable.
- Every durable Mission sub-question links to one Task in the Mission Plan.
- Synthesis cannot run until all Mission sub-question tasks are complete.
- Starting or resuming uses the existing Research Task queue; there is no Mission worker, cron, scheduler, or Mission job table.
- One Mission keeps one execution Plan; repeated create-plan requests return the existing Plan.

## Section 3 — Mission Execution & Progress Engine

Section 3 derives Mission state from the existing Research Task execution system rather than creating a separate Mission runtime.

- linked sub-questions mirror their Research Task lifecycle: researching, blocked, or answered
- completed linked Tasks can carry their execution summary into the durable sub-question answer
- Mission progress exposes transparent task, sub-question, and success-criterion dimensions plus an overall percentage derived from the applicable dimensions
- blockers explicitly include failed/waiting Tasks, blocked sub-questions, and failed success criteria
- unresolved work lists open Tasks and sub-questions rather than hiding them behind a score
- sub-question confidence remains explicit user/Agent data; the system reports coverage and averages but never invents confidence from Task completion
- open contradiction/evidence-gap counts are surfaced as evidence flags, not silently converted into confidence
- completion readiness requires the existing Plan to be complete, sub-questions resolved, success criteria satisfied/waived, and explicit blockers cleared
- when the existing Research Plan completes, an active Mission moves to **Review**; the system does not auto-complete the Mission for the user
- if reviewed execution is reopened by revising a Task, the Mission returns to Active

### Section 3 invariants

- progress is explainable from stored Research state
- no generated percentage can override a failed criterion or blocker
- Mission completion remains an explicit human/governed lifecycle action
- Task execution remains owned by the existing Research Task worker and queue
- no Mission worker, scheduler, cron path, or Mission-specific job queue is added

## Section 4 — Programs & Change Response

Section 4 binds Missions to the existing Research Program scheduler for recurring monitoring and material-change response.

- a Mission may bind an existing same-Agent/same-project Research Program
- a Mission may explicitly create a Mission Watch Program from its objective and scope; newly created Mission Watch Programs start paused and require explicit activation
- Program cadence, timezone, materiality threshold, catch-up behavior, queueing, worker execution, and run history remain owned by the existing Research Program runtime
- completed existing Program runs are observed by Missions after normal Program reconciliation/delivery/longitudinal processing
- only a Program run with a recorded positive material-change count can reactivate a Mission that is completed, in review, or blocked
- quiet/no-material Program runs are recorded in Mission history but do not reactivate it
- unbinding a Program removes only the Mission association; it does not delete or archive the Research Program

### Section 4 invariants

- no Mission cadence, next-run field, cron, worker, scheduler, or Mission run queue is introduced
- Program binding is permission checked and constrained to the same Research Agent/project
- automatic Mission reactivation is evidence-driven by the existing Program run material-change ledger
- a newly created watch Program is paused by default so binding never silently begins recurring execution

## Section 5 — Mission Command Center

Section 5 adds the full-width user-facing Mission workspace without changing the execution architecture.

- `/research-missions.php` is the primary Mission control surface for a selected Research Agent.
- Users can create a Mission from a title, primary question, objective, success definition, success criteria, and sub-questions.
- Mission creation remains non-executing. The Command Center requires an explicit **Build Mission Plan** action, then an explicit **Start Mission** action.
- Mission cards show lightweight progress from durable question/criterion state without running the full progress engine for every list row.
- The selected Mission shows authoritative progress, completion readiness, blockers, unresolved work, explicit confidence coverage, contradiction/evidence-gap flags, and the current synthesis answer.
- The Mission Plan panel exposes the existing Task graph, Task state, latest Task results, the existing living deliverable, and a direct link into the Tasks surface.
- Evidence shown in the Command Center comes from existing Research Task evidence references and preserves Task-level provenance.
- Mission Watch controls bind, activate, pause, or unbind the existing Research Program system.
- Mission event history and immutable configuration revisions are visible in the Command Center.
- Missions are added to the primary Research navigation across Research Agents, Knowledge, Reports, Monitoring, Tasks, Programs, and Evolution.

### Section 5 invariants

- the Command Center is a presentation/control layer over Sections 1–4, not a second execution system
- no new migration, worker, scheduler, queue, or Program replacement is introduced
- full Mission progress is loaded only for the selected Mission; list rendering stays bounded and lightweight
- archived/cancelled/completed state changes remain explicit governed user actions
- evidence, Task, Program, revision, and event history all come from existing durable records

## Section 6 — Agent Cognition, Collaboration & Reporting

Section 6 makes Missions first-class intelligence objects across existing Annotated systems.

- **Agent Chat** automatically receives a compact Mission context for the active Research Agent. It can explain Mission status, current synthesis, blockers, and completion readiness, but cannot silently complete or approve a Mission.
- Mission objects can also be attached directly to Agent Chat and preserve Mission, Research Project, Plan, and Program references.
- **Now / Cognitive Feed** surfaces Missions that are blocked, ready for review, actively progressing, or recently reactivated by material Program changes.
- **Governed Agent actions** can propose creating a draft Research Mission. User confirmation remains mandatory, and confirmation creates only the Mission definition—no Plan, Tasks, Program, queue, or autonomous execution.
- **Collaborative Review Center** accepts Mission subjects using the existing reviewer assignment, response, comment, staleness, restart, notification, and frozen-completion model.
- Mission review state is pinned to the exact Mission revision plus success-criterion, sub-question, and Plan state. Later Mission changes make the prior review visibly stale.
- **Report Studio** adds Mission Brief and Mission Review Brief report types. Mission state participates in deterministic state hashes, manifests, focus filtering, freshness comparison, and Report Run metrics.
- The Mission Command Center links directly to team review and focused Mission Report Studio runs.

### Section 6 invariants

- Agents may summarize and propose but cannot cast review votes, approve reviews, complete Missions, or bypass explicit confirmation.
- Mission review reuses the existing Review Center; no Mission-specific review table, reviewer system, or approval engine is introduced.
- Mission reports are deterministic views over durable Mission state and do not create new Mission state.
- Now and Agent Chat use permission-checked Mission access and do not leak Missions across Research Agent or Team boundaries.
- Migration 086 only extends the existing review subject enum to include `mission`; it does not rewrite existing reviews.

## Lifecycle

`draft → active → blocked/review → completed` is the normal path. Missions may also be cancelled or archived. Completed, cancelled, and archived Missions can be explicitly reactivated; every transition is recorded.

## V1 build sections

1. **Mission Foundation** — schema, lifecycle, criteria, sub-questions, revisions, events, API.
2. **Mission Planning & Task Orchestration** — convert the Mission into an existing Research Plan/Task graph with explicit success gates.
3. **Mission Execution & Progress Engine** — derive Mission progress, blockers, unresolved work, confidence and completion readiness from Tasks/evidence.
4. **Programs & Change Response** — optionally bind Missions to existing Programs for recurring work and material-change reactivation; no duplicate scheduler.
5. **Mission Command Center** — full-width Mission UI, creation flow, progress, evidence, questions, task graph and history.
6. **Agent Cognition, Collaboration & Reporting** — Mission context in Agent Chat/Now, governed Agent actions, team review and Report Studio outputs.
7. **End-to-End Hardening & Release** — upgrade rehearsal, desktop/mobile/extension validation, regression, packaging and final V1 acceptance.

## Section 1 invariants

- A Mission cannot exist outside an accessible Research Agent/project.
- Team access follows the Research Agent's existing team membership model.
- Mission creation does not automatically create a Plan, Tasks, Program, worker job, Report, or Document.
- Configuration revisions are immutable snapshots.
- Mission events are append-only.
- Success criteria and sub-questions remain durable even before autonomous work is planned.
- Migration 085 is additive and does not fabricate Mission history for existing projects.
