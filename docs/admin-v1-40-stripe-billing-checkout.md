# Admin V1.40 — Stripe Billing, Checkout & Self-Service Subscriptions

Admin V1.40 connects Annotated commercial accounts to Stripe while preserving Annotated as the authority for accounts, packages, membership, entitlements and access.

## Billing authority

Stripe owns payment execution, payment methods, recurring charges, invoices and hosted billing UX. Annotated owns package definitions, account membership, AI allowances, account lifecycle and feature entitlements.

Each Stripe Customer is linked to exactly one Annotated account in one Stripe mode. Test and live mode mappings remain separate.

## Package pricing

Paid Annotated packages map to recurring monthly Stripe Prices. Admin can either create/synchronize Stripe Products + Prices from the Annotated package price or map an existing recurring monthly Stripe Price.

Stripe Prices remain immutable. When an Annotated package price changes, synchronization creates a new Stripe Price and deactivates the previous active mapping while retaining historical mappings for webhook resolution.

## Checkout and customer portal

Users without an active Stripe subscription can start Stripe Checkout from `/billing.php`. A user can administer billing for their personal account and for organization accounts where their commercial account role is `owner` or `admin`; ordinary account members have no billing authority. Existing Stripe subscribers are sent to Stripe Customer Portal for payment methods, invoices, cancellation and any subscription changes enabled in the Stripe portal configuration.

Checkout return pages never provision access directly. Stripe webhook events synchronize confirmed subscription state.

## Webhooks and idempotency

`/stripe/webhook.php` verifies the Stripe-Signature HMAC with a timestamp tolerance before parsing or applying an event. Each Stripe event ID is claimed in `stripe_webhook_events`; processed or in-flight duplicates do not apply account changes twice.

Handled events include checkout completion, subscription create/update/delete/pause/resume/trial-will-end, invoice finalized/paid/payment-failed/payment-action-required/voided/uncollectible, refunds and disputes. Dispute events can resolve their associated Charge when the customer is not present directly on the dispute object.

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

Secret/restricted API keys and webhook signing secrets are encrypted at rest with `app.encryption_key`. Stripe v1 requests use API-key HTTP Basic authentication. The optional publishable key is retained for future client-side Stripe surfaces but is not required for hosted Checkout or Customer Portal.

## Administrative safety

Manual Admin package assignment is blocked while an account has an active Stripe-managed subscription. Checkout refuses a package whose effective member limit is below current membership. If Stripe later reports a valid subscription change that lowers the package below current membership, Annotated still synchronizes Stripe's billing/package truth, preserves existing members, blocks future over-limit additions through the existing account rules, and flags the account for Admin attention rather than rejecting the webhook. Manual, complimentary and internal account workflows remain separate from Stripe. Stripe synchronization never creates or changes Research Teams.


## Test/live isolation
Stripe Customer, Price, Subscription, Invoice and webhook event identities are scoped by Stripe mode. Test history can coexist with live history without assuming provider IDs are globally unique across modes.

Any non-terminal subscription Price received from Stripe must be mapped to an Annotated package in the same mode. Checkout prevents a user from selecting a package below the account's current effective member capacity. If Stripe later reports an external package downgrade that makes the account over-capacity, Annotated still synchronizes Stripe's billing truth and flags the account for administrator resolution rather than rejecting the webhook.

## Stripe API version ownership
The integration intentionally does not hard-pin a Stripe API version in application code. Stripe API requests use the account's configured API version, and webhook payload shape follows the API version assigned to the Stripe webhook endpoint. When upgrading the Stripe API version in Workbench, update/test the webhook endpoint version in the same release window and run the V1.40 signed-webhook regression before production cutover.
