# Phase 65 — First-Run Experience & Daily Workflow Polish

Phase 65 makes the existing Annotated product understandable without adding another subsystem.

## Canonical loop

**Capture or add evidence → Research Agent → save useful work → return later and continue.**

## Changes

- Reworks onboarding around real product activity instead of requiring an unrelated social follow.
- Counts active Research Agents, Agent-owned Research projects, workspace evidence, and Agent conversation activity as first-run progress.
- Ensures the default Research Agent is available before the onboarding checklist is rendered.
- Adds a Home start card for incomplete onboarding and a compact Continue Research bar after onboarding.
- Adds direct Home links that open a verified Research Agent directly into Desktop or Library.
- Replaces the empty Home feed dead end with actions for adding evidence, asking the Agent, or installing browser capture.
- Adds actionable empty states to Research Desktop and Library.
- Adds Research Agent next-step actions for Add evidence, Library, New Research Doc, and Review next steps.
- Preserves the Phase 64 save/navigation protections and active-workspace isolation.

## Persistence and release scope

No database migration, cron, worker, or new background process is introduced. Existing authoritative Research Agent, workspace, conversation, annotation, and onboarding records are reused.

The V1.1 stable release identity remains unchanged and migration 079 remains latest.
