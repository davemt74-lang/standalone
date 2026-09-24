# Admin V1.20 — Information Architecture & Dashboard

Admin V1.20 turns the expanding collection of admin tools into one coherent operations workspace. It is intentionally schema-neutral: migration 063 remains current.

## Shared admin shell

Interactive Admin pages use `app/admin-ui.php` for one categorized, accordion-style sidebar. Page-specific flat top navigation is visually retired whenever the shared sidebar is present.

Canonical groups:

- **Overview** — Dashboard, Admin Assistant
- **Accounts & Billing** — Accounts, Users, Packages, AI Usage
- **AI & Models** — Providers & Routing, Evaluations, Model Registry, Training, Post-Training, Release Decisions, Deployments, Model Health, Improvement Loop, Improvement Campaigns
- **Research & Data** — Source Monitor, Data Governance, Dataset Registry, Discovery Entities
- **Trust & Operations** — Moderation & Rights, System Health, Release Audit

The active group opens automatically and the active destination is highlighted.

## Admin control center

`/admin/` is now a consolidated operational dashboard rather than a flat card directory. It summarizes:

- users, commercial accounts, packages, annotations and sources
- customer AI token usage and exhausted accounts
- open moderation/rightsholder workload
- failed AI/source jobs
- stale or failing workers
- pending database migrations
- recent package/account events
- recent metered AI runs

The dashboard only reports state and links to authoritative workspaces; it does not bypass existing governance or approval paths.

## Accounts workspace

`/admin/accounts.php` provides a commercial-account view distinct from Users and Research Teams. It includes ownership, account type/status, package, subscription state, member count/limit, current AI usage, and account period.

Filters support account/user search, account status, subscription status, and package.

## Compatibility

Admin V1.20 introduces no schema changes. V1.10 migration `20260924_063_ai_usage_metering.sql` remains the latest migration. Existing page actions and backend authorities are unchanged; V1.20 reorganizes how administrators reach and understand them.
