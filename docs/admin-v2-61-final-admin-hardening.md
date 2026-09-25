# Admin V2.61 — Final Admin Hardening

Admin V2.61 is a stabilization-only phase. It deliberately adds no new Admin subsystem and no schema migration.

## Goals

- finish the shared Admin UI consistency work started in V2.40;
- close permission-boundary inconsistencies discovered while exercising V2.60;
- make the Admin workspace resilient to wide tables, long identifiers, hashes, and narrow screens;
- preserve one shared Admin shell and one synchronized website/extension base stylesheet;
- strengthen release contracts without changing the V1.1 release identity.

## Changes

### Operation-specific Platform Governance authorization

The Platform Governance route now requires only the view capability at the generic route boundary. Every POST mutation remains protected by the existing operation-specific capability checks:

- feature rollout previews require platform manage plus action-request authority;
- module and integration governance changes require platform manage;
- release/configuration snapshots require platform release;
- governed execution requires action-execute authority and the existing Action Center workflow.

This fixes the prior mismatch where an operator could legitimately hold release-snapshot authority and see the snapshot control but be rejected by the generic route guard before the operation-specific release check ran.

### Shared Admin shell and accessibility

- the active navigation item exposes `aria-current="page"`;
- the Command Center retains its established dashboard heading contract while the V2.61 release marker is added to the shared surface;
- the Admin shell identifies V2.61 while retaining historical lineage markers required by earlier contracts;
- legacy AI Providers & Routing and Source Monitor pages now render inside the same shared full-width Admin panel shell;
- keyboard focus is visible across Admin links, forms, buttons, and disclosure controls.

### Layout hardening

The shared Admin CSS now:

- prevents grid/flex children from forcing the workspace wider than the viewport;
- constrains controls and embedded media to their available column width;
- wraps long IDs, hashes, and code values safely;
- keeps large tables horizontally scrollable without destabilizing the page;
- restores the right-side `aside.card` treatment requested for the Admin UI: transparent background, no border, full column width, with spacing only;
- collapses that rail cleanly on narrower layouts.

The exact same CSS remains in `assets/css/app.css` and `extension/landing-app.css`.

## Regression contract

`tests/admin-v2-61-final-hardening-contract.php` verifies:

1. shared navigation and accessibility state;
2. Command Center naming consistency;
3. explicit delegated-access boundaries for every Admin navigation route;
4. operation-specific Platform Governance permissions;
5. shared shell/stylesheet usage across rendered Admin pages;
6. exact website/extension CSS synchronization;
7. V2.61 package and smoke-gate inclusion.

## Release and deployment

V2.61 has no migration and no new cron process. The existing latest migration remains migration 079. The V1.1 / 1.1.0 / phase 62 / stable release identity remains unchanged.

Release acceptance requires the normal phase gate to complete PHP 8.1/8.3 contracts, model-governance integration, both full historical regressions, MySQL 8 fresh-install/upgrade rehearsals, and production package smoke successfully.

Deploy the normal website package and run `upgrade.php` as usual; it is safe for the upgrade step to report no new schema migration for V2.61.
