# Admin V1.90 — Tax, Invoices & Commercial Billing Hardening

V1.90 closes the Admin V1 commercial-billing roadmap before Admin V2.0. It adds explicit tax policy, account billing profiles, richer invoice evidence, finalized-invoice integrity checks, and reconciliation while preserving the authority boundaries established in V1.40–V1.80.

## Authority model

Stripe remains responsible for tax calculation, invoice state, payment execution, hosted invoices and invoice PDFs. Annotated is authoritative for its own commercial policy, billing-profile intent, account/package identity, local invoice evidence, and audit history.

V1.90 does not implement jurisdiction-specific tax advice or determine where the business is required to register. The Admin chooses whether to enable Stripe automatic tax after configuring Stripe Tax.

## Safe default

Migration 072 creates both Test and Live commercial-billing settings with automatic tax disabled.

Existing Checkout and subscriptions therefore remain unchanged after upgrade. An Admin must explicitly enable automatic tax for the active Stripe environment.

## Checkout hardening

When V1.90 policy is enabled, new Checkout sessions can:

- enable Stripe automatic tax;
- require a billing address;
- enable tax-ID collection;
- allow Checkout to update the Stripe Customer address.

Each Checkout request carries an Annotated billing-policy fingerprint. Existing open Checkout sessions are reused only when package, promotion, and V1.90 billing-policy fingerprints still match.

## Existing subscriptions

Changing V1.90 policy does not silently mutate existing subscriptions.

Admin → Tax & Invoices exposes an explicit per-account “Sync current policy” operation. It first synchronizes the current Annotated billing profile to the Stripe Customer and then updates automatic-tax policy on the linked Stripe subscription.

## Billing profiles

Account owners and account billing admins can maintain:

- legal/business name;
- billing email;
- billing address;
- country;
- invoice prefix;
- optional purchase-order number.

Admin can govern the account tax-exemption state: none, exempt, or reverse charge.

When profile synchronization is enabled, Annotated mirrors the profile to the Stripe Customer. Customer invoice defaults can include the configured invoice footer and PO Number custom field.

## Invoice defaults

The Stripe webhook recommendation now includes `invoice.created`.

When a draft invoice arrives, Annotated can apply the current V1.90 invoice memo, footer, and account PO Number before finalization. Finalized invoices are never modified by V1.90 reconciliation.

## Invoice evidence

The existing `stripe_invoices` ledger now stores separated:

- subtotal;
- subtotal excluding tax;
- discount amount;
- tax amount;
- total excluding tax;
- total;
- starting and ending customer balance;
- billing reason;
- collection method;
- automatic-tax status;
- customer tax-exemption snapshot;
- customer address and tax-ID snapshots;
- invoice custom fields, memo and footer;
- finalized timestamp.

Customer billing history presents subtotal, discount, tax, total and paid amount independently.

## Finalized invoice integrity

`commercial_invoice_evidence` keeps a SHA-256 financial snapshot.

Draft evidence may evolve until the invoice finalizes. At finalization, the latest financial snapshot becomes the baseline. Later payment/status transitions are compared against that finalized baseline. A post-finalization financial mismatch is retained as a drift alert rather than silently replacing the baseline.

## Tax-ID minimization

Annotated does not copy full customer tax-ID values into its V1.90 tax snapshot table. It stores:

- Stripe tax-ID object ID;
- tax-ID type;
- verification status;
- final four characters only;
- source invoice and last-seen time.

## Analytics and Agent governance

Billing Analytics adds 30-day tax and invoice-integrity metrics. Admin Agent receives read-only tax and invoice context only.

The Agent cannot change tax policy, billing profiles, exemptions, invoice content, subscription tax settings, invoice evidence, or reconciliation.

## Migration and release validation

Migration: `20260924_072_tax_invoices_commercial_billing_hardening.sql`

Release gates include:

- PHP 8.1 static/contracts and full regression;
- PHP 8.3 static/contracts and full regression;
- model-governance integration;
- MySQL 8 fresh install;
- migration 071 → 072 rehearsal;
- repeat migration no-op safety;
- production package presence and smoke validation.
