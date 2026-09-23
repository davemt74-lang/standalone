# Phase 58 — Research Programs & Recurring Intelligence

Phase 58 turns the existing Research Agent stack into a durable recurring-intelligence system without creating a second scheduler, knowledge store, or execution engine.

## Architecture

A Research Program belongs to one Research Agent and its existing Research project. Program configuration is versioned. Each queued run pins the exact Program revision/configuration that created it, so later edits affect future cycles only.

The Phase 58 worker uses the repository's existing leased-job primitives and release heartbeat/queue-health system. Scheduled and manual cycles capture a structured snapshot of the existing Research corpus, compare it with the previous run, and store provenance-backed deltas. Material cycles create a new Phase 57 plan and normal versioned Research Doc deliverable. Quiet cycles can be recorded without creating redundant plans or reports.

## Recurring intelligence lifecycle

1. The user creates or confirms a recurring Program with objective, cadence, scope, materiality policy, deliverable type, and budgets.
2. The scheduler queues one run under the configured concurrency/monthly limits and pins the Program revision.
3. The worker captures the current source/claim/contradiction/entity/annotation/workspace/monitoring state allowed by the Program scope.
4. The run records structured deltas against the previous captured run.
5. Material-only Programs suppress unchanged cycles.
6. Material cycles create a fresh Phase 57 plan, dependent tasks, completion gates, and versioned deliverable in the same Research workspace.
7. The Phase 57 task worker enforces the pinned per-run token budget, including reserved capacity for AI work already in flight.
8. Program reconciliation marks a run complete when its Phase 57 plan completes, records token use, and surfaces the result through Agent Chat, notifications, Program history, and Now.

## Continuity and safety

Program continuity is derived from durable prior Program runs, delta history, task outcomes, and Phase 57 execution summaries. It does not add a hidden memory database. Source evidence remains in the existing Research corpus and Phase 54 retrieval path.

Controls include pause/resume/archive, manual Run now, quiet-mode suppression, materiality thresholds, catch-up policy, max concurrent runs, monthly run limit, per-run token ceiling, max tasks per run, and scoped topics/source/claim/entity/watch IDs. Pausing drains queued cycles without deleting history.

The Agent can propose `research.create_program`, but the existing governed Agent-action confirmation flow remains authoritative; the Agent does not silently create schedules.

## UI

`research-programs.php` is the Program Control Center. Programs are also exposed from Research Agents, project navigation, and the Research Library slideout. Each Program shows run history, structured deltas, continuity context, linked Phase 57 plans/deliverables, review attention, failures, and next scheduled run.

## Operations

Apply migration:

`20260923_056_research_programs_recurring_intelligence.sql`

Run the continuous worker:

`php worker/research-program-worker.php`

The worker is registered in release worker health and queue health. Phase 58 is included in the full MariaDB/MySQL release regression and deploy-package smoke gates.
