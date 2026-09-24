# Admin V2.20 — Support Operations & Customer Service Workspace

Admin V2.20 adds a durable customer-support operating layer on top of the commercial account, billing, usage, operations and V2.10 delegated-permission systems.

## Support authorization

V2.20 adds two capabilities to the V2.10 registry:

- `admin.support.view`
- `admin.support.manage`

The seeded Support Admin role receives Support view/manage, Account view, Billing view, AI Usage view and Action Center view so Customer 360 has the necessary read-only commercial context. It does not receive billing mutation, model mutation, role administration, approval, or governed-action execution authority.

Operations Admin receives Support view/manage. Billing, AI & Models, Research & Data, and Trust & Operations roles receive Support view so escalations are visible to the delegated domain owner. Read-only Auditor also receives Support view only.

Direct route authorization is enforced for `/admin/support.php` and `/admin/support-case.php`. GET/HEAD requires view authority; mutation requests require manage authority.

## Support Inbox

The Support Inbox provides:

- open, assigned, waiting, escalated, resolved and closed workflows;
- urgent/high/normal/low priorities;
- SLA due times: urgent 4 hours, high 8 hours, normal 24 hours, low 72 hours;
- My Cases, Unassigned and SLA-risk filters;
- search by case, title, account, username or email;
- support category filters;
- per-operator saved/default views;
- operator workload;
- category volume;
- open, escalated, resolved and reopened metrics.

## Durable support cases

Migration 075 adds:

- `admin_support_cases`
- `admin_support_case_events`
- `admin_support_case_links`
- `admin_support_saved_views`

Cases retain account/customer linkage, owner, priority, state, SLA, escalation, duplicate relationship, resolution summary and key activity timestamps.

Case events are append-only evidence for creation, assignment, priority/state changes, internal notes, customer communications, escalation, resolution, reopen, duplicate handling and record links.

## Customer 360

The Support Case workspace combines:

- customer identity;
- commercial Account 360;
- account members;
- package/subscription state;
- Stripe identity;
- operational alerts;
- AI overage evidence;
- benefits/credits;
- governed Admin action count;
- support case links;
- support events plus authoritative account/billing/membership/AI/commercial/audit history.

Support evidence is viewer-scoped. It is not exposed by global Admin search or Account 360 to an operator who lacks `admin.support.view`.

## Collaboration and communications

Internal notes can contain `@username` mentions. Mentioned Support-authorized administrators receive normal Annotated in-app notifications linked to the case.

Case assignment also generates an in-app notification for the assignee.

Customer-facing updates are logged separately from internal notes. V2.20 records communication evidence; it does not silently send email or external messages.

## Escalation and governed actions

Support cases can be escalated to Billing/Finance, Operations, AI & Models, Research & Data, Trust & Moderation, or Security/Super Admin. Escalation is filterable by team and notifies active administrators who both have Support view authority and the corresponding domain capability.

Support staff do not gain direct financial or high-impact execution authority. Account/billing changes remain in their existing explicit Admin surfaces and V2.10 governed Action Center. The support case keeps the escalation reason and linked evidence so an authorized downstream operator can act under the existing approval rules.

## Duplicate handling

V2.20 detects exact active duplicates for the same account/user, category and title. It surfaces candidates but never silently merges them.

An operator may explicitly close a case as a duplicate of a canonical case. Both records and their histories remain durable.

## Agent governance

The Admin Agent receives aggregate Support metrics only when the operator has Support view permission.

Support context is read-only. The Agent cannot create, update, assign, prioritize, escalate, resolve, reopen, link, merge or communicate on a support case, and cannot use a support case to bypass V2.10 action approvals.

## Migration and release validation

Migration: `20260924_075_admin_support_operations.sql`

Release gates cover PHP 8.1 and 8.3 contracts/full regression, model governance, MySQL 8 fresh install, migration 074 → 075 rehearsal, repeat migration safety, and production package smoke.
