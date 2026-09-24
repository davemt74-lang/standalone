# Admin V2.30 — Financial Reporting & Reconciliation

Admin V2.30 turns the existing Stripe, billing operations, promotions/credits, tax/invoice and AI-overage evidence into a dedicated finance workspace. It does not create a second financial source of truth.

## Admin UI layout repair

V2.30 also corrects the Admin shell visible in V2.20:

- the generic public `.panel` white container is removed from Admin main pages;
- the right-side Admin workspace uses the same page background as the surrounding application;
- the outer border and rounded container are removed;
- the main Admin column consumes the full width to the right of the fixed Admin sidebar;
- top, side and bottom padding remain so pages do not touch the viewport edges;
- inner cards remain bordered cards and preserve the information hierarchy;
- responsive Admin behavior collapses back to normal padded mobile flow.

## Authorization

V2.30 adds:

- `admin.finance.view`
- `admin.finance.manage`
- `admin.finance.export`

Billing Admin receives view/manage/export. Operations Admin receives finance view. Read-only Auditor receives finance view only. Custom roles may receive these registered capabilities under the existing V2.10 role governance rules.

`/admin/financial-reporting.php` requires Finance view for reads and Finance manage for mutations. `/admin/financial-export.php` requires the explicit Finance export capability.

## Finance dashboard

The Finance workspace reports a selected date range across synchronized source ledgers:

- invoice gross/subtotal;
- discounts;
- tax;
- invoiced total;
- collected amount;
- outstanding receivables;
- refunds where recorded in Stripe billing events;
- applied customer credits and debits;
- AI-overage reported amount and accrued amount;
- current MRR and ARR run-rate;
- open dunning count;
- revenue by package.

Stripe-synchronized invoice and billing ledgers remain payment truth.

## Reconciliation engine

A Finance reconciliation scan materializes durable exceptions from current evidence. V2.30 currently detects:

- finalized invoice financial-evidence drift;
- finalized invoices missing immutable commercial invoice evidence;
- paid invoices with a non-zero remaining balance;
- overdue Stripe receivables;
- failed AI-overage reporting batches;
- billable AI overage not yet represented by reported/invoiced batches;
- failed customer credit/debit adjustments.

Exceptions support owner assignment, acknowledgement, resolution, ignore and reopen. A resolved exception automatically reopens when the source condition is still present. An open/acknowledged exception automatically resolves when a later scan proves its source condition cleared. Ignored exceptions remain explicitly ignored.

Every lifecycle decision is append-only in `admin_finance_reconciliation_events` and important operator actions also enter the V2.10 security audit.

## Receivables

Outstanding Stripe invoices are grouped into:

- current;
- 1–30 days;
- 31–60 days;
- 61–90 days;
- 90+ days.

The workspace exposes the underlying account, package, invoice, due date, age and outstanding amount rather than hiding receivable evidence behind a single total.

## Account financial ledger

Each account can be opened as a Finance ledger combining:

- Stripe invoices;
- customer credits/debits;
- invoice discount events;
- AI-overage reporting batches.

Account 360 also receives a V2.30 financial summary with invoice count, paid amount, outstanding amount, tax, discounts and open reconciliation exceptions.

## Immutable period close

Finance operators can close a reporting period after review. The close stores:

- period metrics;
- receivable aging;
- reconciliation exception summary;
- close operator and reason;
- SHA-256 of the complete JSON close snapshot.

A Stripe mode + date range can only be closed once. Repeating the same close returns the existing immutable snapshot instead of rewriting history.

## Audited CSV exports

Finance export supports:

- invoice ledger for a date range, optionally scoped to an account;
- receivables;
- reconciliation exceptions;
- period-close ledger.

Every export stores the operator, type, filters, row count and SHA-256 of the exact generated CSV. Text values beginning with spreadsheet formula characters (`=`, `+`, `-`, `@`) are neutralized before export.

## Support and Agent integration

A finance exception can hand off to Support Operations with the related account and case title prefilled.

The Admin Agent receives aggregate V2.30 finance context only when the operator has Finance view authority. Finance context is read-only: the Agent cannot run reconciliation, resolve exceptions, close periods, export financial evidence, issue credits/refunds or execute financial mutations.

## Migration and release validation

Migration: `20260924_076_admin_financial_reporting_reconciliation.sql`

Release gates cover PHP 8.1 and 8.3 contracts/full regression, model governance, MySQL 8 fresh install, migration 075 → 076 rehearsal, repeat migration safety, Admin layout contracts, and production package smoke.
