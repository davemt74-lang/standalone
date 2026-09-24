# Admin V1.50 — Account Invitations, Membership & Seat Management

V1.50 turns the commercial account member limit into a governed seat-management workflow without merging commercial membership with Research Teams.

## Authority boundary

Commercial accounts, subscription packages and seat entitlements are billing/account administration. Research Teams remain a separate collaboration and research-permission model. Accepting an account invitation never creates a `team_members` row or grants Research project access.

## Invitations

Owners and account admins can invite an email address as an account `member` or `admin`. Site administrators can perform the same operation from Admin → Account.

Invitation secrets are 256-bit random values. Only a SHA-256 hash is stored. The plaintext token exists in the generated HTTPS acceptance URL and is shown after create/resend; resending rotates the secret and immediately invalidates the prior URL.

Invitations expire after a bounded 1–30 day period, may be revoked or declined, and are append-only audited. Signup and login preserve invitation intent. New registration is bound to the invited email, and an already signed-in user must have the exact invited email before acceptance.

## Seat accounting

Effective seat usage is:

`accepted commercial account members + pending invitations with reserves_seat=1`

Package `member_limit` plus an active account-level member-limit override remains authoritative. Invitation creation and acceptance serialize on the commercial account lock. Acceptance always rechecks current capacity, so concurrent accepts cannot overfill the account.

A non-reserving invitation may be created while an account is full, but cannot be accepted until a seat becomes available.

Package changes, Stripe Checkout package preflight, direct Admin member additions and member-limit overrides all use the same reserved-seat model.

## Lifecycle

- Suspended accounts cannot create or accept invitations.
- Closed accounts revoke every outstanding pending invitation.
- The owner cannot be removed or demoted; organization ownership must be explicitly transferred.
- Personal account ownership cannot be transferred.
- Existing members are never automatically deleted because a package or Stripe state reduces capacity; the account is flagged over capacity for resolution.

## Delivery

Email delivery is optional and disabled by default. When `config.php` enables `mail.enabled`, Annotated uses the server PHP mail transport with a configured From address. The secure copyable invite URL remains canonical, so membership lifecycle and security do not depend on mail transport availability.

## Audit and privacy

`account_membership_events` records actor/subject/invitation, before/after state, reason and timestamp. Web-request IP and User-Agent values are hashed before storage; raw values are not persisted.

## Agent integration

Agent Chat receives read-only account/seat context. It may explain package seat counts and whether an account appears to have room, but it cannot invite, remove, promote, or transfer account members. Those actions stay in explicit account-management surfaces.
