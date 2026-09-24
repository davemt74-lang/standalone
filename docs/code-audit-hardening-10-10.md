# Code Audit Hardening — 10/10 Release Gate

This hardening pass audits the merged Admin V1.40 development tree rather than adding a new product feature.

## Correctness and concurrency
- All commercial account lifecycle, membership, package, entitlement and Stripe state mutations serialize through the same account-scoped advisory lock.
- Capacity and package checks are re-read while that lock is held.
- Commercial account locking remains separate from Research Teams and does not alter research permissions.

## Stripe ordering and durability
Migration 066 adds a test/live-scoped account state watermark. Subscription and payment events retain their object ledgers, but an older event cannot overwrite account state established by a newer Stripe event—even when the events refer to different invoices.
- New local Stripe Price mappings are committed before the previous remote Price is archived.
- Failure to archive an old remote Price is a visible non-blocking cleanup warning; the newly mapped Price remains usable.
- Stripe continues to own Stripe-managed billing periods.

## AI usage
- Stripe-managed accounts no longer have billing periods advanced locally by AI metering.
- Completed-run metering handles a duplicate-key race by returning the already-recorded usage event and does not swallow other integrity errors.

## Public-source security
Public HTTP retrieval now resolves and validates a destination once, then pins that exact validated public IP into cURL. Redirects are revalidated on every hop, explicit ports are preserved, credential-bearing URLs are rejected, and private/reserved destinations remain blocked.

## Browser rendering
Research Agent load errors are rendered with DOM text nodes rather than injecting API error text through innerHTML.

## Release proof
The phase gate includes a dedicated static/runtime contract, database journey, MariaDB/PHP 8.1+8.3 validation, MySQL 8 validation, migration 065→066 rehearsal, historical regression, and production package smoke testing.
