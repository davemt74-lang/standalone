# Admin V2.50 — Security, Compliance & Administrative Audit Center

Admin V2.50 adds a governed Security & Compliance workspace over the existing Admin control plane.

## Architecture

V2.50 does **not** replace V2.10 audit or source-domain ledgers. The existing `admin_security_audit_events` table is extended with account, source-domain, source-event, correlation, sensitivity, metadata and hashed request-context fields. The Security & Compliance runtime then normalizes existing Account, Membership, Billing, Support, Finance, Customer Success and governed Action evidence into one chronological view while leaving those source tables authoritative.

## Permissions

New capabilities:

- `admin.security.view`
- `admin.security.manage`
- `admin.security.export`
- `admin.privacy.view`
- `admin.privacy.manage`

The bounded **Security & Compliance Admin** role can review security posture, cases, privacy workflows, delegated access and audited exports. It does not inherit Support, Finance, Billing, AI, Research, Customer Success or model mutation authority.

Cross-domain evidence follows the V2.40 privacy model: event existence and high-level state can be surfaced, but protected source payloads are redacted unless the operator also has the corresponding source-domain view capability.

## Workspace

`/admin/security-compliance.php` includes:

- security posture metrics;
- permission-aware administrative audit ledger;
- actor, account, domain, event, sensitivity, date and free-text filtering;
- deterministic permission-review signals;
- security-case lifecycle and immutable case events;
- privacy/data-rights request lifecycle and immutable request events;
- bounded CSV/JSON audit exports with SHA-256 evidence;
- links into Roles & Permissions;
- Admin Agent read-only context.

`/admin/security-export.php` creates an audited export from the operator's permission-scoped view. Export creation itself is written back to the security audit.

## Integrations

- Command Center: open security cases, overdue privacy requests and Security & Compliance destination.
- Account 360: recent normalized administrative evidence with source-domain redaction.
- Agent Chat: read-only V2.50 security/compliance context with explicit mutation denial.
- Shared Admin shell: V2.50 appears in the existing V2.40-consistent Admin UI.

## Migration

`database/migrations/20260924_078_admin_security_compliance_audit.sql`

No new cron is required. Upload the website package and run `/upgrade.php`.
