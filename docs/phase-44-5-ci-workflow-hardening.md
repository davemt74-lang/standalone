# Phase 44.5 — Development Workflow & CI Hardening

Phase 44.5 changes the development and release workflow, not Annotated product behavior.

## Goals

- Give every pull-request update a fast, useful result.
- Keep all historical regression coverage.
- Run the model-governance integration chain continuously without rerunning unrelated product history on every commit.
- Make the expensive full regression an explicit phase gate.
- Build release ZIPs only after that phase gate is green.
- Keep workflow logic versioned in small runner scripts rather than a continuously growing YAML file.
- Avoid contracts that depend on incidental button copy when an architectural boundary can be asserted directly.

## CI tiers

### 1. PR preflight — automatic on every pull-request update

The existing **Annotated CI** workflow remains the fast required surface.

It runs:

- PHP syntax on PHP 8.1 and 8.3,
- Chrome-extension/application JavaScript syntax,
- release/security/app-shell/concurrency/rate-limit/V1 contracts,
- all phase architecture contracts,
- the current model-governance database chain (Phase 37 and later) once on PHP 8.3/MariaDB.

The governance runner discovers future numeric Phase 45+ database tests automatically.

### 2. Full regression phase gate — explicit

When a phase is ready for final validation, prefix the PR title with:

`[phase-gate]`

The **Annotated Full Regression** workflow then runs on the PR and on any subsequent synchronization while the marker remains present.

The gate runs:

- complete PHP/JS syntax,
- all static contracts,
- the complete historical MariaDB sequence,
- PHP 8.1 and PHP 8.3,
- MySQL 8 fresh-install compatibility.

During normal implementation, leave `[phase-gate]` off the title so small commits do not repeatedly launch the historical suite.

### 3. Packaging — downstream of green full regression

The two-package workflow is reusable only; it is no longer triggered by each pull-request commit.

The Full Regression workflow calls it only after both:

- the PHP/MariaDB full-regression matrix is green, and
- MySQL 8 fresh-install validation is green.

The resulting website ZIP and Chrome-extension ZIP therefore belong to the same PR revision that passed the full gate.

## Runner scripts

- `tests/ci/run-static-contracts.sh` — fast architecture/security contracts.
- `tests/ci/run-model-governance.sh` — install plus Phase 37+ model/data governance DB chain.
- `tests/ci/run-full-regression.sh` — preserved historical DB order plus all static contracts.

The full historical list is intentionally explicit so removal of old coverage is a deliberate code review event. The targeted model-governance list is intentionally dynamic so Phase 45+ joins the fast integration chain automatically.

## Development convention

During implementation:

1. Build and audit locally/in the branch.
2. Push coherent checkpoints rather than one-line commits when possible.
3. Use automatic PR preflight to catch syntax and architecture drift.
4. Keep iterating until the phase is feature-complete.
5. Add `[phase-gate]` to the PR title.
6. Fix only failures surfaced by the full gate.
7. Merge only when full regression and downstream package build are green.
8. Verify the merged tree has zero file differences from the tested feature tree.

This keeps the safety net while dramatically reducing cancelled, superseded CI runs.
