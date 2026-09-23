# Phase 57 — Research Tasks, Plans & Deliverables

Phase 57 converts Research intelligence into durable, governed work. It reuses the existing Research Agent/project, Phase 54 retrieval corpus, Phase 55 autonomous observations, Phase 56 monitoring events, versioned Research Docs, Agent Chat, Now feed, AI worker, and leased job infrastructure.

It does **not** introduce a second Research knowledge store or a second AI execution pipeline.

## 57.1 — Research Task Runtime

Tasks belong to a Research Agent and Research project. They support priorities, due dates, dependencies, explicit state, evidence references, completion evaluation, audit events, and leased execution jobs.

Phase 55 evidence gaps/contradictions and Phase 56 important monitoring events can create deduplicated Agent tasks in the Agent Research Queue.

## 57.2 — Versioned Research Plans

A plan contains an objective, priority, due date, deliverable definition, and ordered/dependent tasks. Every meaningful plan/task revision creates a durable plan snapshot rather than silently replacing the prior plan.

User and Agent-created plans use the same runtime. Agent Chat plan creation remains a governed action requiring the existing confirmation flow.

## 57.3 — Governed Task Execution

Task work uses `research_task_jobs` and the existing AI worker. The state path is:

`queued → ready → researching → review/waiting → complete`

Dependencies gate readiness. Waiting work does not churn automatically. Stale AI results are discarded when the task, dependency state, source versions, Claim evidence state, or relevant Research evidence changes while the model is working.

## 57.4 — Living Deliverables

Each plan can own a versioned Research Doc deliverable. The Agent maintains that normal Research document while task state changes.

If the user edits the deliverable directly, automatic Agent updates stop and the deliverable enters `needs_review`. The user can explicitly resume Agent management or finalize the deliverable. Managed deliverables are excluded from task evidence retrieval/hashing so the Agent cannot cite its own generated output as source evidence.

## 57.5 — Evidence Requirements & Completion Gates

Supported completion gates include:

- minimum independent Sources
- primary evidence
- no open contradiction for cited Claims
- fresh evidence
- citation count
- human review

A failed gate prevents automatic completion. Gate waivers are explicit user actions and are recorded. Draft-deliverable tasks require human review by default.

## 57.6 — Agent Chat, Now & Research UI

The Research Task Center provides plan progress, editing, task creation/editing, dependencies, execution controls, review/waiver controls, and direct access to the living deliverable.

Research Library has a Tasks view. Research Agent cards show active/waiting/review counts. Now surfaces tasks needing attention or ready for work. Legacy Research project tasks route Phase 57-managed work back to the Task Center so old controls cannot bypass completion gates.

## Runtime

Run continuously or once per minute:

```sh
php worker/research-task-worker.php
```

The existing AI worker remains responsible for model execution:

```sh
php worker/ai-worker.php
```

## Deployment

After deploying the Phase 57 website package, run `upgrade.php` to apply:

`20260923_055_research_tasks_plans_deliverables.sql`

Add `php worker/research-task-worker.php` to the normal worker schedule.

## Phase 57 audit dimensions

1. durable Agent/project task runtime
2. versioned plans and dependency integrity
3. leased execution and stale-result recovery
4. normal Research Doc deliverables with user-edit conflict safety
5. evidence-backed completion gates and explicit human review
6. Phase 55/56 signal-to-task integration
7. governed Agent Chat plan/task actions
8. Research Library, Agent card, Task Center, and Now integration
9. live permissions, audit events, legacy-path hardening, self-reference exclusion
10. PHP 8.1/8.3, MySQL 8, historical regression, package and deploy integrity
