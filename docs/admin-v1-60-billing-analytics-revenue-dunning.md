# Admin V1.60 — Billing Analytics, Revenue Operations & Dunning

V1.60 adds an operational finance layer above the Stripe ledgers introduced in V1.40. Stripe remains payment and provider truth. Annotated owns commercial accounts, packages, access, membership, entitlements, derived billing analytics and explicit Admin policy decisions.

## Revenue metrics

The Billing Analytics dashboard reports current MRR/ARR, active/trialing/past-due subscriptions, recent collections, package-level recurring revenue and daily movement.

Current MRR includes non-closed paid accounts whose effective subscription status is active or past due. Manual billing uses the Annotated package price. Stripe-managed billing uses the recurring Stripe Price mapped to the account's current subscription in the selected Test/Live mode; an Annotated list-price edit does not change reported Stripe MRR until Stripe is actually using the new Price. Complimentary/internal accounts and trialing accounts are not counted as current MRR.

Daily snapshots persist:
- current MRR / ARR
- active, trialing, past-due, paused and canceled subscription counts
- new, expansion, contraction, churned and recovered MRR
- collected, failed, refunded and disputed amounts
- open dunning cases and over-capacity accounts

Snapshots are observational and Test/Live isolated. The first snapshot establishes a revenue baseline. New, expansion, contraction, churned and recovered MRR on later snapshots are computed by comparing durable per-account snapshots, so later package-price edits do not rewrite prior movement. Re-running the same mode/date updates that row; it does not append duplicates.

## Dunning

Stripe invoice events open/update durable dunning cases:
- `invoice.payment_failed`
- `invoice.payment_action_required`
- `invoice.marked_uncollectible`
- `invoice.paid`

The default policy is conservative: payment failures create grace/attention state but do **not** automatically suspend account access. Admin may enable suspension after the configured grace period.

If V1.60 itself suspends an account, a later successful payment can restore it only when:
1. restore-after-payment is enabled,
2. no other open dunning case exists, and
3. no later lifecycle decision superseded the dunning suspension.

V1.60 stores the exact account-lifecycle audit event ID created by its suspension and treats that ID as an ownership watermark. Payment recovery or Admin billing grace may restore access only when no later lifecycle event exists. This avoids second-level timestamp ambiguity and prevents billing recovery from undoing a later manual/security/compliance suspension. Granting grace can still clear V1.60's dunning ownership without reactivating an account that another lifecycle decision intentionally keeps suspended.

Voided invoices, canceled/non-billable Stripe subscriptions, and explicit commercial-account closure close remaining open dunning cases so stale payment alerts cannot survive the billing lifecycle that created them.

## Trials

Admin can define the trial reminder window and see Stripe trials approaching their end. Trial extension is an explicit Admin action that updates the Stripe subscription and then resynchronizes Annotated.

## Alerts

The dashboard surfaces:
- open/action-required/grace/suspended dunning
- trials ending soon
- missing paid-package Stripe Price mappings
- failed Stripe webhooks
- over-capacity accounts
- unresolved disputes

## Account billing timeline

Admin account detail combines provider billing events, dunning events, internal billing notes and cancellation feedback into one timeline.

## Cancellation intelligence

Customer billing includes a feedback form that records why a customer is considering/carrying out cancellation and whether the account is eligible for a future win-back offer. Saving feedback **does not** change or cancel the Stripe subscription; subscription changes remain in Stripe Customer Portal.

## Scheduled operations

Run:

`php bin/billing-operations.php`

daily. It reviews due dunning cases using the configured policy and writes/updates the current daily snapshot. Webhook requests update provider ledgers and dunning state only; they do not rebuild full account snapshots on the Stripe webhook hot path.

## Agent boundary

Only Admin Agent Chat receives global V1.60 revenue/dunning context. That context is read-only. The Agent cannot change billing, payments, refunds, dunning state, trial dates, packages, access or cancellation state.
