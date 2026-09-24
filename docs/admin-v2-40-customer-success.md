# Admin V2.40 — Customer Success, Account Health & Retention

Admin V2.40 adds a governed Customer Success operating layer across Accounts, Support, Finance and product-adoption evidence. It also continues the Admin UI consistency work started in V2.30.

## Explainable account health

V2.40 does not use an opaque AI health prediction. The health index is deterministic and every contribution is stored as evidence.

Current negative signals include:

- non-active account lifecycle;
- past-due, paused or canceled subscription;
- open support cases;
- high/urgent support cases;
- support cases at or near SLA risk;
- open and critical finance reconciliation exceptions;
- outstanding receivables;
- missing or declining AI activity;
- no Research Project adoption after the onboarding window;
- single-user adoption on a team-capable account;
- overdue Customer Success follow-ups.

Each health calculation produces:

- a 0–100 health index;
- health state: healthy, watch, at-risk or critical;
- lifecycle stage: onboarding, adopting, established, expansion-ready or recovery;
- a complete signal object;
- a reason list with explicit point impact and evidence;
- explicit expansion/adoption opportunities;
- onboarding milestone evidence;
- a deterministic SHA-256 fingerprint.

A stored snapshot is only added when the evidence fingerprint changes, unless an administrator explicitly forces a snapshot.

## Expansion signals

V2.40 currently surfaces explicit opportunities when:

- account member utilization reaches 80% of the effective member limit;
- AI token utilization reaches 80% of the effective allowance;
- multi-user + Research Project adoption indicates an established collaborative workflow.

These are evidence signals, not sales predictions.

## Customer Success workspace

The Customer Success portfolio supports:

- healthy / watch / at-risk / critical views;
- onboarding / adopting / established / expansion-ready / recovery stages;
- My Accounts;
- unassigned accounts;
- overdue follow-up filtering;
- account/user search;
- Success-owner workload;
- portfolio health metrics;
- deterministic health refresh.

## Account Success 360

Each account exposes:

- current health index and lifecycle;
- exact health reasons and evidence;
- expansion/adoption opportunities;
- onboarding milestone completion;
- Success owner;
- Support context;
- Finance context;
- member and AI-usage context;
- follow-ups;
- success plans;
- plan milestones;
- immutable health history;
- append-only Customer Success audit timeline.

Support and Finance records remain authoritative in their own systems.

## Success ownership and follow-through

Migration 077 adds durable account owner assignments.

Follow-ups include:

- title and note;
- priority;
- owner;
- due date/time;
- open / completed / cancelled state;
- completion/reopen workflow;
- durable event and security-audit evidence.

Assignments and assigned follow-ups use Annotated's existing notification system with permission-checked deep links.

## Success plans

Account plans support:

- objective;
- owner;
- target date;
- active / paused / completed / cancelled state;
- ordered milestones;
- pending / in-progress / completed / blocked milestone state;
- append-only lifecycle evidence.

## Delegated permissions

V2.40 adds:

- `admin.customer_success.view`
- `admin.customer_success.manage`

A new system role, **Customer Success Admin**, receives Customer Success view/manage plus read-only Accounts, Support, Finance, AI Usage and Action Center context. It does not receive finance mutation, Support mutation, account mutation, role administration, or governed-action execution authority.

Operations Admin, Support Admin and Read-only Auditor receive Customer Success view for cross-functional visibility. Their existing mutation boundaries remain unchanged.

## Support and Finance handoff

Support cases and Finance reconciliation exceptions can deep-link directly to Account Success 360 with a prefilled follow-up title/note. The operator still explicitly creates the Success follow-up; cross-workspace links do not silently mutate Customer Success state.

## Agent governance

The Admin Agent receives aggregate Customer Success metrics only for operators with Customer Success view permission.

The Agent may summarize health changes, risks and opportunities or draft a success plan. It cannot assign Success owners, change health snapshots, create/complete follow-ups, mutate plans/milestones, contact customers, or alter Account/Billing state.

## Admin UI consistency

V2.40 standardizes the Admin shell beyond V2.30:

- consistent page-title width, spacing and typography;
- consistent card radius, padding and section spacing;
- standardized metric-card sizing;
- standardized filters and form-control heights;
- consistent page-level action rows;
- consistent table headers, row spacing and separators;
- reusable two-column content split;
- reusable two-column form grid;
- reusable compact inline forms;
- consistent empty/history states;
- responsive stacking for Admin content and Customer Success pages.

The V2.30 full-width right-hand Admin canvas remains authoritative: no outer white container/border is reintroduced.

## Migration and validation

Migration: `20260924_077_admin_customer_success_health.sql`

Release validation covers PHP 8.1/8.3 contracts and full historical regression, model governance, MySQL 8 fresh install, migration 076 → 077 rehearsal, repeat migration safety, Customer Success role isolation, explainable-health behavior, Admin UI consistency, CSS mirror parity and production package smoke.
