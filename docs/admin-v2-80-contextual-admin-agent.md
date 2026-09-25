# Admin V2.80 — Contextual Admin Agent & Admin-Wide Copilot

Admin V2.80 turns the V2.70 Admin Agent into a shared operating surface across Annotated Admin.

## Admin-wide copilot

The shared Admin shell now renders one contextual Admin Agent dock on every Admin page except the dedicated Agent canvas.

The dock provides:

- a ChatGPT-style sticky footer composer;
- a slide-up conversation panel;
- the same persistent Admin Agent threads used by the full canvas;
- current-page context shown above the composer;
- a clear-context control;
- a full-canvas handoff that preserves the active thread and source page;
- persistent Admin search/navigation cards;
- governed-action review cards.

No individual Admin page needs a second Agent implementation.

## Permission-aware page context

`app/admin-agent-context.php` resolves the current Admin page on the server.

Supported contextual examples include:

- Account 360;
- Support Case;
- Customer Success Account;
- governed Action Center records;
- filtered Accounts / Users / Support lists;
- all other Admin surfaces as bounded surface context.

The browser sends only the page path and query keys back to the Admin Agent API. The server re-resolves the page, object and capability before the context reaches the model. A client cannot grant itself additional Admin context by altering the payload.

Examples:

- on Account 360: “What is going on with this account?”
- on a Support Case: “What should I do next?”
- on Customer Success: “Why is this account unhealthy?”
- on Billing: “Find the failed invoice.”
- from any Admin page: “Take me to the account with this issue.”

## Navigation results

Authorized Admin search results are stored as `admin_link` conversation attachments on the assistant message.

This means the links:

- render in both the compact copilot and the full Admin Agent canvas;
- survive reloads and page changes;
- remain permission-filtered by the existing V2.70 domain controls;
- never accept arbitrary external URLs.

## Expanded governed actions

V2.80 keeps the V2.70 rule that the model never directly mutates Admin data.

The Agent can now prepare Action Center previews for:

- Stripe subscription resync;
- AI overage reconciliation;
- billing/tax policy synchronization;
- account package change;
- account suspend/reactivate.

Package and account-lifecycle changes are intentionally available only through the Admin Agent / governed preview path, not the Action Center’s generic manual picker.

For package and lifecycle changes V2.80 additionally enforces:

- exact authorized account context or Admin search resolution;
- explicit administrator intent;
- active package validation for package changes;
- lifecycle target limited to `active` or `suspended`;
- existing Admin capability checks;
- one required approval;
- reviewer must be different from requester;
- final execution through the existing authoritative Account 360 functions;
- existing Admin action ledger and Security & Compliance audit evidence.

Closing accounts, member changes, credits, support-case mutation, Customer Success mutation, entitlements and other operations remain on their existing Admin surfaces until they receive equivalent governed adapters.

## Full canvas continuity

Opening the full Admin Agent canvas from the footer passes the source Admin URL through the `from` parameter. The server resolves that page context again and the full canvas displays the same context strip.

The active Admin Agent thread is also shared with the compact copilot through the existing conversation runtime and a local active-thread pointer.

## Release impact

- no new database migration;
- no cron;
- no worker;
- no queue;
- migration 079 remains current;
- V1.1 release identity remains unchanged.
