# Admin V2.0 — Unified Operations Command Center

Admin V2.0 turns the Admin V1.x account and commercial infrastructure into one operational workspace.

V1.x remains authoritative for the systems it introduced. V2.0 does not replace the account, membership, Stripe, dunning, AI usage, AI overage, promotion/credit, tax, invoice, or audit ledgers. It adds a coordination layer over them.

## Command Center

Admin home is now the operational Command Center.

It includes:

- global search across accounts, users, Stripe invoices, Stripe subscriptions/customers, promotions, and V2.0 action correlation IDs;
- durable Needs Attention queue;
- severity, status, ownership and category filters;
- reasoned snooze and resolve state;
- explicit alert ownership;
- saved Admin views;
- summary links into Account 360 and governed Action Center;
- existing AI accounting and Admin workspace navigation.

Operational alert refresh reads existing authoritative source systems and materializes current conditions. Alerts that disappear from their source system auto-resolve. An unresolved source condition reopens an alert that was manually resolved, preventing a resolution click from hiding a still-active problem.

## Account 360

The existing Admin account page remains the mutation surface for lifecycle, membership, invitations and entitlements.

V2.0 adds an Account 360 layer containing:

- Stripe customer and subscription identity;
- billing profile and tax status;
- recent invoice count;
- recorded promotion discounts and monetary credit/debit balance;
- AI overage accrued and unreported amounts;
- account-specific operational alerts;
- unified chronological audit timeline.

The unified timeline reads the existing account, membership, billing, dunning, AI overage, promotions/credits, tax/invoice and V2.0 action ledgers. Source ledgers remain authoritative.

## Governed Action Center

V2.0 introduces an explicit preview → execute flow for bounded operational follow-through.

Initial supported actions:

- resynchronize a linked Stripe subscription;
- reconcile pending AI overage according to V1.70 policy;
- synchronize the current billing profile and tax policy to a linked Stripe subscription.

Each preview stores:

- acting Admin;
- account;
- action type;
- risk level;
- explicit reason;
- request snapshot;
- unique correlation ID.

Execution atomically claims the preview before side effects. The preview may only be executed by the Admin who created it. Results are recorded as executed or failed.

Higher-impact changes such as account lifecycle changes, membership changes, package changes, credits and other financial mutations remain on their existing explicit Admin surfaces in V2.0.

## Operational alerts

V2.0 materializes actionable conditions including available source signals for:

- billing dunning and past-due conditions;
- failed Stripe webhooks;
- trial deadlines;
- package/Stripe mapping issues;
- unresolved disputes;
- seat-capacity issues;
- exhausted AI allowance;
- failed AI overage reporting;
- finalized invoice evidence drift;
- missing billing-country data when automatic tax is enabled;
- suspended accounts.

Alert workflow state is local Admin operations state only; resolving or snoozing an alert never mutates its underlying billing/account source condition.

## Saved views

Admins can persist Command Center alert filters and optionally mark one as their default view. Saved views are private to the Admin user.

## Operator capability groundwork

Migration 073 adds `admin_operator_profiles`.

Existing and newly encountered site administrators receive a `super_admin` profile with `admin.*`. This is groundwork for Admin V2.10 delegated permissions.

V2.0 does not replace or weaken the existing `role='admin'` authorization boundary.

## Agent governance

Agent Chat receives a small read-only V2.0 operations summary.

The Agent may explain and prioritize operational context but cannot:

- resolve or snooze alerts;
- assign alert ownership;
- execute governed actions;
- mutate operator capabilities;
- claim that an Admin operation was executed without application confirmation.

## Migration and release validation

Migration: `20260924_073_admin_v2_operations_command_center.sql`

Release gates:

- PHP 8.1 static/contracts and full regression;
- PHP 8.3 static/contracts and full regression;
- model-governance integration;
- MySQL 8 fresh install;
- migration 072 → 073 rehearsal;
- repeat migration safety;
- production package presence and package smoke.
