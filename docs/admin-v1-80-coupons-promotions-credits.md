# Admin V1.80 — Coupons, Promotions & Credits

Admin V1.80 adds governed commercial promotions and monetary account credits without changing the authority boundaries established by Subscriptions, Stripe Billing, Billing Operations, or AI Overage Billing.

## Authority model

Annotated is authoritative for promotion definitions, eligibility, redemption limits, account credit intent, and audit history. Stripe remains the payment and invoice execution system. Test and Live mappings are isolated.

AI token credits remain AI-usage adjustments. Monetary credits in V1.80 never rewrite AI usage events or the immutable V1.70 overage ledger.

## Promotions

A promotion has a canonical Annotated code and policy:

- percentage or fixed-amount discount
- once, repeating, or forever duration
- optional repeating-month count
- all, new-customer, or existing-customer scope
- optional eligible package set
- optional package-price minimum
- start and end timestamps
- optional global redemption cap
- per-account redemption limit
- draft, active, or archived lifecycle

V1.80 intentionally supports one governed promotion per Checkout session. Discounts do not stack.

## Stripe synchronization

Each Annotated promotion has an independent Test or Live Stripe mapping. Synchronization creates a Stripe Coupon and Promotion Code that mirror the validated Annotated definition. The local definition hash prevents unnecessary remapping.

When a definition changes, a prior Stripe Promotion Code is deactivated before a replacement mapping is created.

## Checkout lifecycle

Customer Checkout accepts an optional promotion code.

Before Stripe Checkout is created, Annotated:

1. normalizes and resolves the code;
2. validates active dates, package eligibility, customer scope, minimum price, global cap, and per-account limit;
3. synchronizes the promotion to the currently configured Stripe environment;
4. reserves a durable local redemption;
5. creates Checkout with the exact Stripe Promotion Code.

An open Checkout session is reused only when package and promotion identity both match. Replaced or expired sessions void their pending local redemption. Checkout completion moves the reservation to applied and records the Stripe subscription ID.

## Invoice reconciliation

Invoice webhooks record Stripe's actual discount amount in `commercial_discount_events`.

The ledger keeps:

- gross invoice subtotal
- discount amount
- net invoice total
- account
- promotion/redemption linkage where known
- Stripe mode, subscription, and invoice IDs

Webhook replay is idempotent per Stripe invoice.

Billing Analytics presents these commercial adjustments separately from list-price MRR so recurring list value is not silently rewritten by discounts or customer credits.

## Customer credits

Admin can issue a monetary credit or debit to an account. Annotated first records the adjustment and a durable idempotency key, then mirrors the signed adjustment to the account's Stripe customer credit-balance transaction ledger.

Stripe sign convention is preserved:

- customer credit: negative amount
- customer debit: positive amount

An applied adjustment is not edited in place. Reversal creates a new opposite adjustment linked to the original. Failed remote operations remain durable and can be retried.

## Customer and Admin surfaces

`/billing.php` supports optional promotion codes for new paid Checkout and displays recorded account discounts, monetary credit adjustments, and recent applied promotions.

`/admin/promotions.php` provides:

- promotion creation and lifecycle management
- package and customer eligibility controls
- Test/Live Stripe synchronization
- redemption visibility
- invoice discount evidence
- account credit/debit operations
- retry and append-only reversal controls

`/admin/billing-analytics.php` adds 30-day gross, discount, net, credit, and debit commercial metrics.

## Agent governance

The Admin Agent receives read-only promotions and credits context. It may explain active-promotion counts, redemption counts, discounts, and credit totals, but it cannot create, edit, synchronize, redeem, credit, debit, reverse, or otherwise mutate commercial state.

## Database and release validation

Migration: `20260924_071_coupons_promotions_credits.sql`

Release gates include:

- PHP 8.1 static/contracts and full regression
- PHP 8.3 static/contracts and full regression
- model-governance integration
- MySQL 8 fresh install
- migration 070 → 071 rehearsal
- repeat migration no-op safety
- production package presence and smoke validation
