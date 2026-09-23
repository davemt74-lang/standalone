# Phase 56 — Continuous Research Monitoring & External Research Intelligence

Phase 56 extends the Phase 54 unified Research corpus and Phase 55 autonomous Research workspace with durable external monitoring. It does **not** create a second knowledge store.

## 56.1 — Research Watchlists

Research Agents can own project-scoped watches for URLs, domains, topics, entities, claims, and search queries. Watches support hourly, daily, weekly, and manual cadence; important/all alert modes; pause/resume/archive; and live permission checks.

## 56.2 — External Research Discovery

URL watches and domain sitemap discovery work without an external provider. Topic/entity/query discovery can use an optional provider-neutral command configured under `research_monitoring.discovery_command`. The command receives JSON at `{input}` and writes JSON results to `{output}`.

Discovered URLs enter a candidate ledger first. Canonical URL hashes deduplicate candidates. Relevance is scored before promotion. Users can promote or ignore candidates, and high-relevance candidates can optionally auto-promote. Promotion uses the existing `sources`, `project_sources`, Source Monitor, Phase 54 retrieval, and Phase 55 autonomy paths.

## 56.3 — Source Change Monitoring

The existing Source Monitor remains authoritative for fetching and versioning source content. Source change events are handed into Phase 56, retaining source/version/change-event provenance. The source-history lifecycle now explicitly supports `restored` after an unavailable source becomes reachable again.

## 56.4 — Claim Intelligence

Monitored claims maintain a deterministic derived state from explicit saved evidence: unresolved, supported, weakened, contradicted, or resolved.

When a Research AI model is configured, new source versions can also receive model-backed claim assessments: supports, weakens, contradicts, or unrelated. These assessments are stored in a separate provenance ledger and surfaced as monitoring events. They **never silently modify** `research_claims.status` or insert authoritative claim evidence.

## 56.5 — Proactive Research Updates

Meaningful monitoring events are deduplicated and batched into concise Research Agent Chat updates. Important-only watches suppress informational noise. New meaningful evidence queues the Phase 55 autonomous workspace so living reports and attention artifacts stay current.

The Research Library includes a Monitoring view with watch state, candidate counts, recent changes, and a link to the full Monitoring control center.

## 56.6 — Control, Recovery & Release Hardening

Monitoring jobs use the existing leased-job architecture with expired-lease recovery and `rerun_requested` behavior. Source changes, monitoring jobs, candidate promotion, claim assessments, chat updates, and project permissions all remain auditable and project-scoped.

External discovery is optional. If configured, the command must contain both `{input}` and `{output}` placeholders and PHP `exec` must be available. Candidate URLs are not fetched directly by the discovery layer; promoted sources flow through the existing public-URL/SSRF-safe Source Monitor.

## Runtime

Run continuously or once per minute:

```sh
php worker/research-monitor-worker.php
```

Existing workers remain required, especially:

```sh
php worker/source-monitor-worker.php
php worker/research-retrieval-worker.php
php worker/research-autonomy-worker.php
php worker/ai-worker.php
```

## Deployment

Run `upgrade.php` after deployment to apply:

`20260923_054_continuous_research_monitoring.sql`

## Phase 56 audit dimensions

1. Agent/project-scoped watchlists
2. Candidate discovery and deduplication
3. Promotion into the existing Source corpus
4. Source version/change provenance
5. Deterministic claim-state intelligence
6. Model-backed derived claim assessments
7. Proactive Agent Chat updates without spam
8. Research Library + Monitoring control UX
9. Leased worker recovery, rate limits, permissions, and provider safety
10. PHP 8.1/8.3, MySQL 8, historical regression, package and deploy integrity
