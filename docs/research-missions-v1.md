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
