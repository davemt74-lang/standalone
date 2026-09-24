# Admin V1.40 — Stripe Billing, Checkout & Self-Service Subscriptions

Admin V1.40 connects Annotated commercial accounts to Stripe while preserving Annotated as the authority for accounts, packages, membership, entitlements and access.

## Billing authority

Stripe owns payment execution, payment methods, recurring charges, invoices and hosted billing UX. Annotated owns package definitions, account membership, AI allowances, account lifecycle and feature entitlements.

Each Stripe Customer is linked to exactly one Annotated account in one Stripe mode. Test and live mode mappings remain separate.

## Package pricing

Paid Annotated packages map to recurring monthly Stripe Prices. Admin can either create/synchronize Stripe Products + Prices from the Annotated package price or map an existing recurring monthly Stripe Price.

Stripe Prices remain immutable. When an Annotated package price changes, synchronization creates a new Stripe Price and deactivates the previous active mapping while retaining historical mappings for webhook resolution.

## Checkout and customer portal

Users without an active Stripe subscription can start Stripe Checkout from `/billing.php`. Existing Stripe subscribers are sent to Stripe Customer Portal for payment methods, invoices, cancellation and any subscription changes enabled in the Stripe portal configuration.

Checkout return pages never provision access directly. Stripe webhook events synchronize confirmed subscription state.

## Webhooks and idempotency

`/stripe/webhook.php` verifies the Stripe-Signature HMAC with a timestamp tolerance before parsing or applying an event. Each Stripe event ID is claimed in `stripe_webhook_events`; processed or in-flight duplicates do not apply account changes twice.

Handled events include checkout completion, subscription create/update/delete/pause/resume, invoice finalized/paid/payment failed/voided/uncollectible, refunds and disputes.

## Subscription state

Annotated supports `trialing`, `active`, `past_due`, `paused` and `canceled`.

`past_due` is a billing grace state. It remains eligible for existing product access while payment recovery proceeds. `paused` and `canceled` remove legacy Pro access through the existing account lifecycle gate.

## Local billing ledger

Migration 065 adds:

- `stripe_billing_settings`
- `stripe_package_prices`
- `stripe_customers`
- `stripe_subscriptions`
- `stripe_invoices`
- `stripe_webhook_events`
- `account_billing_events`
- `accounts.billing_source`
- `past_due` to account subscription status

Secret and webhook keys are encrypted at rest with `app.encryption_key`.

## Administrative safety

Manual Admin package assignment is blocked while an account has an active Stripe-managed subscription. Manual, complimentary and internal account workflows remain separate from Stripe. Stripe synchronization never creates or changes Research Teams.
