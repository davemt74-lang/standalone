# Phase 63 — VP3 Plugin Integration

Annotated is now designed as a first-class **VP3 plugin** while preserving its standalone runtime for development, recovery, and independent deployment.

## 63A — Plugin manifest & host mode

Annotated exposes a bounded plugin manifest with:
- plugin key `annotated`
- plugin contract/version
- launch endpoint
- signed Agent-context endpoint
- declared Research capabilities
- explicit authority split: VP3 owns host identity/plugin lifecycle; Annotated owns Research data.

The integration is URL/API based. VP3 and Annotated **do not share database tables**.

## 63B — Signed VP3 SSO

VP3 creates a short-lived HMAC launch token containing its authenticated user ID, display data, destination, nonce, issued time, and expiry.

Annotated:
- verifies issuer, audience, plugin key, timestamp and HMAC
- consumes a single-use nonce
- maps the VP3 user to an Annotated user through `vp3_host_identities`
- never auto-merges an existing Annotated account merely because email addresses match
- regenerates the Annotated session on successful launch
- retains a host audit receipt.

VP3 remains authentication authority. The local Annotated user exists only so all existing per-user Research ownership and ACL logic continues to work without bypasses.

## 63C — Signed cognition/context bridge

VP3 may request bounded Annotated intelligence through `/api/vp3/context.php`.

Each request is signed over method, path, timestamp, nonce, host user and body hash. Nonces are single use and requests outside the five-minute window are rejected.

The returned payload is explicitly data-only and never instruction authority. It contains bounded:
- Research Agents
- Research Programs
- Intelligence Portfolios
- needs-attention items
- decisions awaiting follow-through
- opportunities and briefings awaiting review.

Raw private document bodies and credentials are not exported.

## 63D — VP3 lifecycle

The VP3 host registers `annotated` in the existing v3.60 plugin lifecycle. Enable/disable is a preference layered over `annotated.access` commercial entitlement. Disabling Annotated in VP3 does not delete Annotated data.

## 63E — VP3 Agent Brain integration

VP3 registers Annotated as an external cognitive domain and, when the plugin is enabled/configured, adds the signed bounded context to Agent surface context. The bridge is cached briefly and fail-closed; Annotated data cannot become authentication or instruction authority.

## 63F — Release hardening

Phase 63 adds migration 060 only for host identity mapping, replay protection and audit receipts. Existing Research/Portfolio/Decision systems remain authoritative. No parallel Research tables, Agent Brain, scheduler, task store, publication store or VP3 entitlement store is introduced.
