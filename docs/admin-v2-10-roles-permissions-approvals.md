# Admin V2.10 — Roles, Delegated Permissions & Approval Policies

Admin V2.10 converts the operator-capability groundwork introduced in V2.0 into enforced delegated administration.

## Authorization model

`users.role='admin'` remains the outer authentication boundary. V2.10 does not create a second login identity type.

After Admin authentication, `admin_operator_profiles` resolves an effective Admin role from the V2.10 role registry. The shared `require_admin()` boundary delegates each Admin request to the access runtime, which maps the route and HTTP method to a capability.

GET/HEAD requests normally require a view capability. Mutating requests require the matching manage capability. Action Center and Roles & Permissions use additional operation-specific checks.

During the deployment window before migration 074 is applied, existing site administrators retain access rather than being locked out. After migration 074, delegated capability enforcement becomes authoritative.

## Seeded roles

Migration 074 seeds:

- Super Admin
- Operations Admin
- Billing Admin
- Support Admin
- AI & Models Admin
- Research & Data Admin
- Trust & Operations Admin
- Read-only Auditor

Legacy and newly encountered site administrators default to Super Admin for backward compatibility until a Super Admin explicitly delegates a narrower role.

The last active Super Admin cannot be demoted or disabled.

## Custom roles

Super Admin can create custom roles from the registered capability catalog.

Custom roles cannot grant `admin.*` and cannot grant `admin.roles.manage`. System roles are immutable. An assigned custom role cannot be archived until active operators are reassigned.

## Central route enforcement

Capability groups cover:

- Command Center / operational queue
- Accounts & users
- Billing
- AI usage
- AI & model lifecycle
- Research & data administration
- Trust & release operations
- Governed Action Center
- Roles & Permissions

The shared Admin navigation hides destinations the current operator cannot access, but the direct URL boundary remains authoritative and returns 403 when a capability is absent.

## Approval policies and separation of duties

V2.10 adds approval policy snapshots to governed Admin actions.

Default policy:

- Stripe subscription resync: routine, zero approvals
- AI overage reconciliation: elevated, one approval, reviewer distinct from requester
- billing-profile/tax-policy synchronization: elevated, one approval, reviewer distinct from requester

A new action snapshots the current policy. Later policy edits do not rewrite an existing action’s required approval count.

For an elevated action the lifecycle is:

`previewed → pending_approval → approved → executing → executed`

A reviewer can reject instead:

`pending_approval → rejected`

Rejected actions cannot execute.

The requester remains the executor, preserving the V2.0 request ownership rule. When the policy requires a distinct reviewer, the requester cannot approve their own action.

## Security audit

Role creation/change, operator assignment, approval-policy change, approval request/decision, action execution and action failure are written to `admin_security_audit_events`.

Existing domain ledgers remain authoritative for the business operation itself.

## Agent governance

Agent context may expose the current operator role and aggregate pending approval count as read-only evidence.

The Agent cannot:

- assign or change operator roles;
- create or modify role capabilities;
- change approval policies;
- approve or reject an action;
- claim an action executed without application confirmation.

## Migration and release validation

Migration: `20260924_074_admin_roles_permissions_approvals.sql`

Release gates include PHP 8.1 and 8.3 contracts/full regression, model governance, MySQL 8 fresh install, migration 073 → 074 rehearsal, repeat migration safety, and production package smoke.
