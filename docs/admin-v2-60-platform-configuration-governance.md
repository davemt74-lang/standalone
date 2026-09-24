# Admin V2.60 — Platform Configuration, Feature Governance & Release Operations

Admin V2.60 adds a governed platform control plane without replacing Annotated's existing release, migration, configuration, provider, package or deployment authorities.

## Architecture

V2.60 reuses:

- `release_environment_checks()`, worker/queue health and release operations;
- `app_schema_runtime_status()` and the existing migration ledger;
- the generated `RELEASE-MANIFEST.json` identity and package fingerprint;
- existing Stripe, AI-provider, OAuth, VP3 and mail configuration sources;
- V2.10 governed Admin actions and approval policies;
- V2.50 Security & Compliance audit normalization.

V2.60 does not store provider secrets and does not write `config.php`.

## Capabilities

- `admin.platform.view`
- `admin.platform.manage`
- `admin.platform.release`

The bounded **Platform Operations Admin** can inspect platform state, request governed feature changes, execute them after approval, update non-executing module/integration governance metadata, and capture release/drift snapshots. It does not inherit billing, model, account, security-role or privacy mutation authority.

## Feature governance

The feature registry stores lifecycle, observe/enforce mode, default eligibility, rollout percentage, package/account scopes and feature dependencies.

Changes use the existing Action Center:

1. preview;
2. submit for approval;
3. distinct authorized reviewer approval;
4. requester execution;
5. immutable platform event plus V2.50 security audit evidence.

Migration 079 seeds `change_platform_feature` as an elevated action requiring one distinct reviewer.

Existing seeded features start in **observe** mode, preventing V2.60 from silently changing existing production behavior. Product code can use `admin_platform_feature_effective()` where an enforced gate is intentionally integrated.

## Modules and integrations

Module desired state is governance metadata compared with observed runtime availability. It does not unload PHP code.

Integration health is derived from authoritative configuration/provider state. V2.60 stores desired state and ownership notes only; secrets remain in their current secure configuration sources.

## Release readiness and drift

V2.60 exposes safe configuration summaries, schema state, release identity, feature/module/integration state and deterministic drift fingerprints.

Operators with `admin.platform.release` can capture immutable:

- configuration snapshots;
- release-readiness snapshots;
- drift baselines.

System Health remains the authority for full package-integrity checks, backups, workers and deployment preflight.

## Integrations

- Command Center platform-readiness and drift metrics.
- Account 360 feature eligibility.
- System Health deep link to Platform Governance.
- Security & Compliance platform source-domain redaction.
- Agent Chat read-only V2.60 context.

## Migration

`database/migrations/20260924_079_admin_platform_configuration_governance.sql`

No new cron is required. Deploy the website package and run `/upgrade.php`.
