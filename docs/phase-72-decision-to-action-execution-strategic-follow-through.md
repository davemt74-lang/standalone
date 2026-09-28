# Phase 72 — Decision-to-Action Execution & Strategic Follow-Through

## Section 1 — Action Plan Ledger Foundation

Phase 72 turns durable Research Decisions into governed execution without introducing a parallel task engine, scheduler, or autonomous execution loop.

Section 1 introduces the durable **Action Plan Ledger**.

### Decision → Action boundary

An Action Plan is created explicitly from an existing Decision. The source Decision must currently be:

- Accepted, or
- Reopened

The resulting Action Plan always starts as **Draft**. Creation never activates work, creates Research Tasks, creates a Research Program, changes the Decision, or schedules autonomous execution.

Each Action Plan is pinned to the exact source Decision state used when it was created:

- Decision public ID
- Decision status
- immutable Decision revision
- Decision config hash
- Decision statement, rationale, confidence, accountable owner, and decided timestamp
- canonical Decision configuration snapshot

This source snapshot is immutable Action Plan provenance.

### Durable Action Plan record

Each Action Plan stores:

- title
- execution objective
- expected result
- explicit owner
- priority
- success measures
- risks and mitigations
- assumptions
- optional start and due dates
- lifecycle status
- immutable current revision/config hash
- activation/completion/cancellation timestamps
- source Decision provenance
- team-safe idempotency key

The Action Plan lifecycle is:

**Draft → Proposed → Active → Paused → Completed**

with explicit cancellation/archive paths.

### Immutable revisions and audit events

Action Plan configuration changes create immutable revisions. Lifecycle changes are append-only events and do not rewrite prior revisions.

The source Decision pin is not editable through Section 1. If current Decision state no longer matches the pinned Decision revision/hash/status, the Action Plan reports source_stale=true.

### Activation governance

Activation is explicit and allowed only when:

- the Action Plan is Proposed or Paused
- the source Decision is currently Accepted
- the source Decision still exactly matches the pinned revision/hash/status
- at least one explicit success measure exists

A stale Action Plan is never silently rebased onto a newer Decision.

An Agent may create or edit a Draft/Proposed Action Plan in future governed integrations, but the foundation explicitly prevents Agent-originated activation, completion, or cancellation.

### Reuse of existing platform primitives

Section 1 intentionally does **not** create milestones, execution tasks, recurring reviews, or schedulers.

Later Phase 72 sections will connect the Action Plan Ledger to:

- existing Research Tasks for execution work
- existing Research Programs for recurring review/follow-through
- existing Decision Outcome Memory for expected-vs-actual learning
- existing Collaborative Review for human governance

### Section 1 invariants

- no Action Plan is fabricated from historical Outcome Learning
- no Action Plan is created automatically when a Decision is accepted
- every Action Plan begins Draft
- Decision→Action retries are idempotent per Decision/request, including across Team members
- creating/updating/changing Action Plan state never changes the source Decision
- source Decision snapshots are immutable
- stale Decision provenance blocks activation
- at least one success measure is required before activation
- completed/cancelled/archived Action Plans cannot be edited
- no Research Task or Research Program is created by the foundation
- no scheduler, worker, cron, queue, or autonomous execution loop is introduced

### Section 1 API

/api/research-action-plans.php provides authenticated:

- list
- summary
- detail
- explicit Decision → Action Plan creation
- Action Plan update
- explicit lifecycle transition

All mutations use existing mutation authentication and rate limiting.

## Section 2 — Milestones, Tasks & Dependencies

Section 2 adds execution structure to the Action Plan Ledger while keeping the existing Research Task runtime as the single task-execution authority.

### Existing Research Tasks remain authoritative

Each Action Plan may lazily create one existing `research_task_plans` record as its execution task plan. Action Plan execution tasks are ordinary existing `research_tasks` records and continue to use:

- existing task completion gates
- existing task evidence refs
- existing task dependencies
- existing task jobs
- the existing Research Task worker
- existing task revisions, reviews, and audit events

Section 2 does not create a second task queue, worker, scheduler, or execution table.

### Milestones

Action Plans may contain ordered milestones with:

- title and description
- explicit owner
- Planned / In Progress / Completed / Cancelled status
- completion criteria
- target date
- completion timestamp
- parent milestone dependencies

A milestone may start or complete only while its Action Plan is Active. Completion requires all parent milestones complete, all linked execution tasks complete, and explicit completion criteria.

Agent-originated milestone completion/cancellation is blocked.

### Milestone dependencies

Milestone dependencies are constrained to one Action Plan and are cycle-checked.

Dependencies may only change while the child milestone is Planned. Once work starts, the milestone dependency graph is fixed for that execution cycle.

### Execution task links

Action Plan tasks are linked to either:

- a specific milestone, or
- the Action Plan directly as an unassigned execution task

Link roles are Execution, Validation, or Supporting.

Task creation uses the existing Research Task primitives, but Section 2 orders the transaction so the Action Plan/milestone link and dependencies exist before the task is queued.

### Execution readiness

The existing Research Task queue and worker both enforce Action Plan readiness.

A linked task may execute only when:

- its existing Research Task Plan is Active
- its Action Plan is Active
- its own existing task dependencies are complete
- if linked to a milestone, that milestone is In Progress
- all parent milestone dependencies are complete

Blocked tasks remain queued with an explicit Action Plan/milestone blocking reason.

### Lifecycle synchronization

Action Plan lifecycle synchronizes its linked existing Research Task Plan:

- Active → Task Plan Active
- Draft / Proposed / Paused / Cancelled → Task Plan Paused
- Archived → Task Plan Archived

Action Plan completion is blocked until every milestone is Completed or Cancelled and every linked execution task is complete.

### Section 2 invariants

- no parallel task engine is introduced
- no Action Plan-specific worker is introduced
- execution tasks remain normal Research Tasks
- task jobs never bypass Action Plan or milestone readiness
- milestone/task dependencies are cycle-safe
- cross-Action-Plan milestone and task dependencies are rejected
- milestones cannot start before the Action Plan is Active
- tasks linked to a Planned milestone do not execute
- Agent actions cannot complete/cancel milestones
- Action Plan completion cannot outrun milestone/task completion
- existing Research Task completion gates and human review remain authoritative

## Section 3 — Programs & Recurring Follow-Through

Section 3 binds Action Plans to the existing Research Programs runtime so execution can be reviewed on a recurring cadence without introducing a second scheduler.

### Existing Research Programs remain authoritative

A follow-through Program is an ordinary existing Research Program. It continues to own:

- cadence and timezone
- next-run calculation
- catch-up behavior
- concurrency limits
- monthly run limits
- per-run token budgets
- quiet/material-only behavior
- recurring Research Task Plan generation
- Program runs, deltas, memory, and audit events
- the existing Research Program worker

Section 3 adds only an Action Plan ↔ Program link and Action Plan execution state inside existing Program snapshots/deltas.

### Follow-through roles

An Action Plan may have at most one linked Program for each role:

- **Execution Review** — recurring milestone/task/blocker review
- **Success Measure Check** — recurring review of saved success measures against available evidence
- **Evidence Refresh** — material Research/evidence changes relevant to execution
- **Decision Follow-up** — execution changes that may warrant human review of the source Decision

A Program can belong to only one Action Plan.

### Explicit creation and linking

Follow-through Programs are never created automatically when an Action Plan activates.

A user may:

- create a new existing Research Program from an Action Plan using role-specific defaults
- link an existing Program from the same Research Agent/project
- unlink a Program without deleting or rewriting the Program
- enable/disable Action Plan lifecycle synchronization

Program run-now, cadence editing, scheduling, run claiming, task generation, and worker processing remain in the existing Research Programs API/runtime.

Agent-originated Program creation is permitted only as a governed draft action: the resulting Program is forced to **Paused** and cannot begin recurring execution merely because the Action Plan is Active.

### Action Plan lifecycle synchronization

For links with lifecycle sync enabled:

- Action Plan **Active** → Program **Active**
- Action Plan **Draft / Proposed / Paused / Completed / Cancelled** → Program **Paused**
- Action Plan **Archived** → Program **Archived**

Archived Programs are never resurrected.

A Program with sync disabled keeps its own independently controlled Program lifecycle.

### Execution-aware Program snapshots

Existing Program snapshots now include a compact linked Action Plan execution snapshot:

- Action Plan status, revision, priority, dates, and overdue state
- pinned/current source Decision state and staleness
- saved success measures
- milestone status, target dates, overdue/blocker state, and task counts
- overall linked execution-task status counts

The Program delta ledger records material execution changes using the existing Program run/delta system, including:

- Action Plan status change
- source Decision becoming stale/current
- Action Plan overdue
- milestone added/started/completed
- milestone blocked/unblocked/overdue
- execution task progress changes

No duplicate Action Plan history is created; Program snapshots are recurring observations over the authoritative Action Plan/Task/Decision ledgers.

### Section 3 invariants

- no Action Plan-specific scheduler, cron, queue, Program worker, or run table is introduced
- existing Research Programs remain the sole recurring scheduling authority
- linking never fabricates a Program run
- Program creation/linking is explicit
- an Action Plan may have at most one Program per follow-through role
- one Program cannot be linked to multiple Action Plans
- linked Programs must use the same Research Agent/project as the Action Plan
- lifecycle synchronization is explicit and can be disabled per link
- Agent-created follow-through Programs start Paused
- Program snapshots/deltas are read-only observations and never mutate Action Plan, milestone, task, or Decision state
- existing Program cadence/concurrency/budget/quiet-mode rules remain authoritative

