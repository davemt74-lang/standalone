# Phase 62 — V1.1 Final Release Validation & Production Cutover

Phase 62 is the final V1.1 release gate. It adds no product subsystem and no database migration.

## 62A — Architecture & code audit

Phases 50–61 remain authoritative and non-duplicative:

- Research Agent Workspace owns Research desktop objects and documents.
- Research Tasks owns plans, tasks, completion gates, and deliverables.
- Research Programs owns recurring Research work and material deltas.
- Phase 59 owns collaborative review, approval, immutable publication, and distribution.
- Intelligence Portfolios aggregate Programs without copying authoritative evidence.
- Phase 61 operates Portfolios through the existing Research Automation invocation.
- Decision Memory remains authoritative for executive decisions; Phase 61 only links those decisions back to Portfolios.
- No Phase 62 migration, worker, scheduler, task store, publication store, or intelligence ranker is introduced.

## 62B — End-to-end V1.1 journey

The final journey proves:

Research Agent → evidence/Claim → Research Task → Research Program → Research Doc → review/approval → immutable publication → Portfolio → scheduled intelligence cycle → Executive Briefing → executive decision → follow-up task → Decision Memory → next Portfolio cycle.

The journey includes Team access and revocation checks and verifies published snapshots remain immutable.

## 62C — Upgrade & recovery

Supported release validation rehearses:

- V1.1 RC1-era database state ending at migration 046 → migration 059
- Research Programs state ending at migration 056 → 059
- Portfolio state ending at migration 058 → 059
- a second upgrade pass that must be a no-op
- no failed migration receipts after completion

Existing Phase 49 backup/verify/restore-plan tooling remains authoritative. Restore execution stays manual and destructive operations are never triggered automatically.

## 62D — Surface validation

Static release contracts cover desktop/mobile responsive surfaces, the Chrome Manifest V3 package, Agent Chat, Research Desktop, Programs, Review Center, Publishing, Intelligence Portfolios, Command Center, notifications, Now, session continuity, and shared website/extension CSS.

## 62E — Performance & operational readiness

A seeded-organization soak test exercises multiple Research Agents and Programs, hundreds of source/Claim/delta records, multiple Portfolios, repeated intelligence cycles, bounded command-center output, subscriptions, decisions, and follow-through.

Scale tests assert bounded outputs and idempotent cycle behavior rather than relying on fragile wall-clock thresholds.

## 62F — Stable V1.1 cutover

The canonical release is:

- Release: **V1.1**
- Version: **1.1.0**
- Channel: **stable**
- Release phase: **62**
- Latest migration: **059**
- Chrome component: **0.36.0 / Manifest V3**

The extension component version remains 0.36.0 because Phase 62 does not change extension runtime behavior; its release-candidate wording is removed.

The stable tag workflow is `v1.1.0`. It repeats PHP 8.1/8.3 historical regression, MySQL 8, supported-baseline upgrades, and the same two-package build used by the PR gate.
