# Admin V2.70 — Admin Agent

Admin V2.70 replaces the legacy one-shot “Ask the backend” form with a persistent, Admin-native Agent workspace.

## Product surface

- dedicated Admin Agent chat canvas at `/admin/assistant.php`;
- persistent Admin Agent threads stored in the existing conversation runtime under the isolated `admin_agent` conversation type;
- ChatGPT-style sticky footer composer with auto-growing textarea, Enter-to-send and Shift+Enter newline behavior;
- new-chat control and recent Admin Agent thread history;
- quick prompts for attention, billing/usage, system health and customer risk.

## Runtime

`app/admin-agent.php` is the authoritative Admin Agent runtime. It reuses existing Admin services and AI configuration rather than creating a shadow Admin model.

The Agent receives sanitized, permission-aware context from the existing systems: Admin operations, billing and usage signals, support, finance, Customer Success, Security & Compliance, Platform Governance, worker/queue/migration health, and scoped Admin global-search results derived from the administrator’s question.

It never receives provider secrets, credentials or raw protected configuration.

## Governed actions

The Admin Agent cannot mutate Admin tables or execute privileged work directly.

For existing account-level governed actions, it may prepare a normal Action Center preview when the account was resolved through authorized Admin search and the current operator already has the required capability:

- Stripe subscription resync;
- AI overage reconciliation;
- billing profile / tax policy synchronization.

The preview is attached to the Agent response and links to the existing Action Center. Approval and execution remain controlled by the existing V2.10 permission, approval, separation-of-duties and audit systems.

All other mutations remain on their existing Admin surfaces.

## Persistence and isolation

Admin Agent threads use `conversation_type='admin_agent'`, so they remain separate from Research Agent conversations without a new database table or migration. Access requires both Admin authorization and ownership of the conversation.

## Release impact

No database migration, cron, queue or worker is introduced. Migration 079 remains current.
