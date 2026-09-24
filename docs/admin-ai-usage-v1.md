# Admin V1.10 — Canonical LLM/System Usage Metering & Token Management

Admin V1.10 establishes one authoritative AI-usage ledger on top of the commercial account/package model introduced in migration 062.

## Canonical accounting

Every completed `ai_run()` is metered through `app/ai-usage.php`. Provider-reported input/output token counts are authoritative when available. If a provider omits one or both counts, the ledger records a deterministic fallback estimate and labels the event `mixed` or `estimated`.

Each event preserves the AI run, account/user attribution, provider/model, task type, account period, usage class, token counts, and model-price-based estimated cost. The ledger is idempotent per AI run.

## Chargeability

- `account`: user-requested Pro work; consumes the commercial account's monthly token allowance.
- `admin`: administrator operations; visible for cost/operations but not charged to a customer allowance.
- `system`: internal/background system work, including deployment shadow evaluation; visible but not charged.

Background jobs requested by a user remain attributable to that user's account when the worker classifies them as Pro work.

## Allowances and exhaustion

The active package's `monthly_ai_token_allowance` is the base allowance. `NULL` means the package is not capped/configured yet. A configured value of `0` means no account AI tokens are available.

The current request is allowed when a positive balance exists. Because actual provider usage is only known after completion, the final request may cross the allowance. Once the balance is exhausted, subsequent chargeable provider requests are blocked before execution.

Account periods roll forward automatically when usage is checked. Usage and adjustments remain attached to the period in which they occurred.

## Admin adjustments

Admin → AI Usage supports audited current-period token credits/debits. Adjustments never rewrite package definitions or historical usage. Each adjustment stores administrator, signed token delta, reason, period, and timestamp.

## Admin surfaces

- **Admin → AI Usage**: account balances, allowance consumption, system/admin usage, recent metered runs, estimated cost, and adjustment audit.
- **Admin → Users**: current package usage and remaining balance.
- **Admin → Packages**: continues to own editable monthly token allowance values.
- **Admin → AI**: continues to own providers, model routing, and input/output cost configuration.

Research Teams remain separate from commercial accounts. Token allowance ownership follows the commercial account, not `team_members`.
