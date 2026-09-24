# Admin V1.30 — Account Lifecycle, Membership & Entitlement Administration

V1.30 turns commercial accounts into governed administrative objects while preserving the separation between commercial account membership and Research Teams.

## Lifecycle
Administrators can create organization/internal accounts, rename accounts, suspend/reactivate/close them, pause/cancel/reactivate subscription state, and change packages. Closing an account cancels its subscription state without deleting history.

## Membership
Existing Annotated users can be added as account members with member/admin roles. The owner is protected from removal and ordinary role mutation. Organization ownership can be transferred only to an existing member; the former owner becomes an account admin. Personal-account ownership remains bound to the personal user.

Effective member limits are enforced on member additions and package changes.

## Entitlements
Package entitlements remain the baseline. Account-level overrides may temporarily replace monthly AI token allowance, member limit, or named feature values. Overrides can expire or be revoked. Revoking an override restores package authority.

Canonical AI metering now consumes the effective account token allowance before period credits/debits are applied.

## Audit
Every V1.30 account mutation records actor, subject when applicable, event type, before/after state, reason and timestamp in `account_admin_events`. Current overrides live in `account_entitlement_overrides`; revocation preserves the record.

## Separation from Research Teams
No V1.30 account operation creates, deletes, joins, or changes a Research Team. Commercial account roles govern billing/account administration only.
