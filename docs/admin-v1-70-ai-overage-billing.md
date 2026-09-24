# Admin V1.70 — AI Overage Billing & Usage Plans

V1.70 connects the canonical AI usage ledger to optional, governed overage billing without making Stripe the usage source of truth.

## Safety and billing rules

- Existing packages remain **hard limit** after migration.
- Customer overage is **off by default** and requires an explicit account owner/admin opt-in.
- Every opted-in account must have a monthly dollar cap.
- Projected requests are checked against that cap before provider execution.
- Usage is measured and settled locally first; the immutable local ledger remains authoritative.
- Input and output overage are priced independently from a package snapshot.
- Sub-cent charges accumulate in micro-dollars locally and are reported to Stripe only once at least one cent is billable.
- Stripe invoice-item reporting is attached to the account's exact subscription and protected by durable idempotency keys.
- Test and Live Stripe modes remain isolated.
- Package/entitlement allowance changes during a billing period are prorated; explicit token credits/debits are applied after proration.
- Admin Agent access to overage analytics is read-only.

## Package policy

Admin → Packages now supports:

- Hard limit
- Allow opt-in overage
- Admin-defined overage
- Input price per 1M tokens
- Output price per 1M tokens
- Optional default monthly overage cap

## Customer controls

Billing → AI Overage lets an authorized account owner/admin explicitly enable or disable overage and choose a monthly cap. The page also shows accrued overage, Stripe-reported overage, and overage-token totals.

## Operations

Admin → AI Overage Billing shows account policy, opt-in status, accrued usage, reported amounts, cap state, immutable usage attribution, Stripe reporting batches, threshold events, and an explicit Stripe reconcile operation.

The daily billing runner now also reports pending AI overage:

`php bin/billing-operations.php`

## Deployment

Run `/upgrade.php` after deploying. Latest migration:

`20260924_070_ai_overage_billing_usage_plans.sql`

No new cron is required beyond the V1.60 daily billing operations runner.
