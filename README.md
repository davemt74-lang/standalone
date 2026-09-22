# Annotated

Annotated is a standalone, sidebar-first social annotation and collaborative research platform.

This repository contains the Chrome Manifest V3 sidebar extension, PHP web/API application, MariaDB schema, and worker foundations for source snapshots, media processing, social feeds, live page collaboration, and research.

## First deployment

1. Create an **empty** MariaDB database and grant a database user access to it.
2. Upload/extract the Annotated website package into the web root.
3. Open `/install.php`. Enter the site URL, database host/port/name, database username, and database password.
4. The installer tests the connection, creates `config.php` automatically, imports `database/schema.sql`, and applies every bundled forward migration.
5. Create the first administrator when redirected to `/first-admin.php`. No bootstrap/setup key is used.
6. Install the matching Chrome extension and connect it to the Annotated site URL.

Normal web requests automatically route to `/install.php` until configuration and the base schema exist. After the first administrator is created, `/first-admin.php` closes permanently. Existing deployments continue to use `/upgrade.php` for later forward-only migrations.

## Extension account model

Users may create an Annotated account with email/password, Google, or X. The Chrome sidebar connects to that same account through a one-time browser authorization code and stores a revocable extension session token; it never stores the user's Annotated password or OAuth provider credentials.

## Media processing

Media annotations enforce the 90-second limit at the API and worker layers. Video derivatives are generated at 240p. The worker only processes media input supplied by an authorized acquisition/upload adapter and does not bypass third-party access controls.

## Audio commentary + transcription

Audio commentary is recorded in the Chrome sidebar and stored as a first-class annotation asset. Publishing audio creates an `annotation_transcripts` record and queues a `transcription_jobs` job.

Configure `transcription.command` in `config.php` with a local/private speech-to-text command template. The command receives:

- `{input}` — absolute path to the recorded audio file
- `{output}` — temporary path where the command must write UTF-8 plain text

Run `php worker/transcription-worker.php` from cron/supervisor/your queue runner. Raw machine text is retained separately from the user-edited transcript. Transcript edits never alter the original audio.

## Website surfaces added

- `/home.php` — Following feed
- `/explore.php` — active public sources + recent annotations
- `/source.php?id=...` — canonical Source page with annotation feed and version history
- `/source-compare.php` — text comparison between preserved Source Versions
- `/notifications.php` — social/research/source-change notifications
- `/teams.php` — create teams and add researchers for Team Live access

Source changes now create a `source_change_events` ledger and can notify annotation authors/watchers when a later captured version changes previously annotated passages.

## V1 beta account, privacy, and search

Migration `20260917_003_profiles_settings_search_collections.sql` adds public/private profile controls, search visibility, default annotation visibility, notification preferences, Saved collections, and the account fields used by the beta identity surfaces.

Website surfaces now include `/profile.php`, `/settings.php`, `/connected-accounts.php`, `/saved.php`, `/collection.php`, and `/search.php`. OAuth identities can be linked while signed in, but an identity already owned by another Annotated user cannot be reassigned through the linking flow. Extension sessions are individually revocable.

GitHub Actions in `.github/workflows/ci.yml` gates PHP syntax on PHP 8.1/8.3, Chrome extension JavaScript syntax, Manifest V3, migration ordering, the 90-second media contract, and the required public `File a claim` surface.

## AI / LLM backend

Annotated now includes a provider-neutral AI layer for system administration and Pro research. Configure providers and model IDs in **Admin → AI**; API keys are encrypted before database storage using `app.encryption_key` from `config.php`.

Supported adapters:

- OpenAI Responses API
- Anthropic Messages API
- Google Gemini `generateContent`
- generic OpenAI-compatible chat-completions endpoints

The system administrator can independently route models for admin operations, Pro users, source monitoring, moderation triage, research synthesis, and future transcript cleanup. Pro access is explicitly granted per user and only models marked **Pro** are available to non-admin users.

AI is intentionally advisory around moderation and legal/rightsholder claims. It can summarize and triage, but only human admins can resolve claims or restrict content.

### Workers

Run these under cron/Supervisor in addition to the existing media/transcription workers:

```bash
php worker/source-monitor-worker.php 5
php worker/ai-worker.php 5
```

The source worker only fetches public HTTP(S) destinations, blocks private/reserved network targets, creates immutable Source Versions, and detects whether previously captured passages disappeared. When a source-change AI model is configured, the verified deterministic change event is queued for AI summarization after detection.

### Pro research AI

Pro users get **Ask Annotated** inside research projects. The model receives only project material the user is authorized to access and is instructed to cite Annotated Source/Annotation IDs. AI run records retain task, model, scope, token counts and provenance references.


## Private evidence storage

Screenshots, source snapshots, audio commentary, and processed media derivatives are stored with `private://...` references under `storage.private_root`, which should be outside the public web root. Browsers receive only authorization-aware `/evidence.php` URLs; the gateway re-checks annotation or Source access before streaming bytes and supports HTTP Range requests for media playback.

For an existing deployment, run this once after deploying Batch 3:

```bash
php bin/migrate-private-evidence.php
```

The utility copies legacy `/storage/uploads/...` evidence into the private root, rewrites database references, then removes the migrated public files. An Apache deny file is included under `/storage/`; for Nginx also deny the legacy URL namespace explicitly, for example:

```nginx
location ^~ /storage/ { return 404; }
```

Do not point `storage.private_root` at a directory served by the web server.

## Concurrency and migration reliability

Migration `20260917_006_worker_leases.sql` adds tokenized worker leases to media, transcription, AI, and source-monitor jobs. Each worker claim is selected under `FOR UPDATE`, receives a random claim token and expiry, and can only complete/retry the job while that lease is still current. Expired leases are reclaimed safely; a stale worker cannot overwrite a newer claim. Retryable jobs are delayed with `available_at`/`scheduled_at` instead of being immediately hot-looped.

Source Version allocation is serialized by locking the canonical `sources` row before reading `current_version_id` and choosing the next version number. The source-monitor worker fetches the remote page before opening the database transaction, then reconciles that fetched result against the latest locked Source Version before committing.

`upgrade.php` now uses MariaDB `GET_LOCK()` so only one schema upgrade can run at a time. Because MariaDB DDL can implicitly commit, upgrades no longer pretend DDL rollback is atomic. `schema_migration_runs` records every attempt, statement position, failure, and checksum. Failed migrations remain checksum-pinned and must be retried unchanged after the environmental problem is corrected. Destructive `DROP`/`TRUNCATE`/`RENAME TABLE` migrations are rejected by the automated contract gate in favor of reviewed expand/contract changes.


## V1 RC1 production readiness

The Phase 36 release-candidate tree uses the production-readiness layer introduced earlier in the build, now normalized for **V1 RC1 (`1.0.0-rc1`)**:

- `/onboarding.php` — one-time first-run checklist based on real account activity
- `/admin/system-health.php` — production readiness, migration, queue, and worker health
- `php bin/release-preflight.php` — CLI release gate using the same health service
- `docs/RELEASE-V1.1-RC1.md` — deployment, backup, rollback, worker, and Chrome release runbook
- browser-session revocation epoch plus existing per-extension session revocation
- OAuth callback state expiry and one-time consumption
- worker heartbeats for media, transcription, source monitoring, AI, and saved-search alerts
- Chrome extension version `0.36.0` with the Phase 36 end-to-end workspace hardening

Recommended recurring workers for RC validation:

```bash
php worker/media-worker.php
php worker/transcription-worker.php
php worker/source-monitor-worker.php 5
php worker/ai-worker.php 5
php worker/saved-search-worker.php 50
php bin/research-automations.php --limit=25
```

Before every RC/production deploy, back up both MariaDB and `storage.private_root`, run the preflight command, apply pending migrations through `upgrade.php`, and verify Admin → System Health & Release. See the full runbook for rollback rules.


## Phase 37 — Data & Attribution Architecture v1

The post-V1 development line adds a governed data substrate for future Annotated Intelligence without training directly from production activity.

Phase 37 adds:

- an append-only **Contribution Ledger** for supported human and Agent knowledge states
- generalized **Provenance Edges** that point back to authoritative Annotated objects
- separate **contributor consent** and **Source rights** controls
- a disposable, regenerable **derived corpus**
- deterministic shared-retrieval / evaluation / training eligibility
- **AI response lineage** showing which Annotated objects and contributors were supplied to a model
- contributor and administrator governance surfaces
- idempotent backfill for existing Annotated data

The governing rule is:

**Production object → Contribution Ledger → Rights / Consent → Derived Corpus → future Versioned Dataset → Controlled Model Release**

Public visibility does not imply model-training permission. Private and Team Research remains outside the shared corpus. External Source text requires explicit Source-rights approval. Phase 37 does not train or fine-tune a model.

See `docs/phase-37-data-attribution-architecture-v1.md`.


## Phase 38 — Dataset Registry & Frozen Dataset Manifests

The post-V1 data architecture now includes a governed Dataset Registry that turns Phase 37 corpus items into reproducible, immutable snapshots without training a model.

Phase 38 adds:

- purpose-specific datasets for retrieval, evaluation, training, and commercial training
- normalized selection policies with stable policy hashes
- deterministic governed-corpus selection
- immutable frozen text, metadata, provenance, and eligibility snapshots
- manifest hashing that recomputes from actual snapshot contents
- live current-use validation against later consent, rights, content, and provenance changes
- manifest-only audit export for frozen and retired datasets
- full data export only while current eligibility and integrity remain valid
- administrator-only create, preview, freeze, inspect, export, and retire workflows
- a first-class **Admin Dataset Registry UI**

The architecture remains:

**Production object → Contribution Ledger → Rights / Consent → Derived Corpus → Frozen Dataset → future controlled model/evaluation systems**

Phase 38 does not train or fine-tune a model.

See `docs/phase-38-dataset-registry-frozen-manifests.md`.


## Phase 39 — Dataset Evaluation & Benchmark Harness

The post-V1 intelligence-data architecture now includes a reproducible benchmark layer over frozen Phase 38 evaluation datasets.

Phase 39 adds:

- immutable benchmark suites and case sets
- deterministic retrieval evaluation
- optional configured-model inference benchmarks
- dataset-manifest, suite-config, and case-set snapshots per run
- per-case result hashes and completed-run integrity hashes
- transparent retrieval, grounding, citation, token, and latency metrics
- human pass / fail / needs-work reviews with 1–5 relevance, groundedness, and accuracy scales
- integrity-valid regression baselines and metric deltas
- concurrency-safe queued evaluation workers
- bounded failed/stale run recovery
- reproducible benchmark JSON exports
- a first-class **Admin Evaluation Harness UI**

Formal benchmarks require a frozen dataset whose purpose is specifically **Evaluation** and whose current rights/consent/integrity state still permits evaluation.

Model benchmarks use existing configured inference providers only. They do not fine-tune, create training jobs, or modify model weights.

The architecture remains:

**Production object → Contribution Ledger → Rights / Consent → Derived Corpus → Frozen Dataset → Evaluation Harness → future controlled model systems**

See `docs/phase-39-dataset-evaluation-benchmark-harness.md`.


## Phase 40 — Model Registry & Candidate Lifecycle

The post-V1 intelligence architecture now includes a governed model-release layer above existing provider/runtime configuration.

Phase 40 adds:

- logical model families and immutable governed model versions
- lifecycle states from experimental through retired
- tamper-evident model-version identity hashes
- Phase 39 model-evaluation linkage
- configurable release gates
- explicit human approval and activation
- tamper-evident promotion receipts
- governed active-version history
- explicit rollback to prior active versions
- equivalent-benchmark model comparison without automatic winner selection
- a first-class **Admin Model Registry UI**

Governed activation is intentionally separate from `ai_settings`; approval, activation, and rollback never silently change production AI task routing.

Phase 40 does not train, fine-tune, or modify model weights.

The architecture is now:

**Production object → Contribution Ledger → Rights / Consent → Derived Corpus → Frozen Dataset → Evaluation Harness → Model Registry → future controlled training/runtime systems**

See `docs/phase-40-model-registry-candidate-lifecycle.md`.


## Phase 41 — Training Job Registry & Controlled Fine-Tuning

Annotated now has a governed training layer between frozen training data and the Model Registry.

Phase 41 adds:

- explicit Internal vs Commercial training intent
- frozen Training/Commercial Training dataset enforcement
- approved Phase 40 base-model + approval-receipt enforcement
- explicit supervised-example validation with no synthetic labeling fallback
- attribution-compatibility blocking for weight training
- immutable config/rights/provider/package/job hashes
- exact base-model approval-receipt and Phase 41/app runtime snapshots
- retryable provider polling/cancellation errors without duplicate retraining
- Provider API and Manual/Self-hosted executors
- out-of-request training worker
- provider upload/submit/poll/cancel lifecycle
- attempt history, provider logs, cancellation, and bounded retries
- rights revalidation during active training
- trained-token and configurable cost accounting
- exact governed JSONL export
- provider/manual output artifacts
- automatic handoff only to a new Phase 40 **experimental** model version
- a first-class **Admin Training Registry UI**

Training success never updates `ai_settings` and never promotes, approves, or activates a model automatically.

The controlled path is:

**Governed data → Frozen training dataset → Training job → Experimental model → Evaluation → Human review → Phase 40 approval**

See `docs/phase-41-training-job-registry-controlled-fine-tuning.md`.


## Phase 42 — Post-Training Evaluation & Promotion Readiness

Annotated now has the governed bridge between a successful training job and a human model-release decision.

Phase 42 adds:

- successful Phase 41 output discovery
- governed Phase 40 baseline evidence requirements
- exact Phase 39 baseline-to-candidate suite cloning
- benchmark fingerprint equivalence enforcement
- queued candidate evaluation runs
- human-review readiness requirements
- transparent equivalent-run regression comparisons
- automatic linking of integrity-valid candidate runs into Phase 40 evidence
- existing Phase 40 release-gate reuse
- tamper-evident post-training plan hashes
- tamper-evident promotion-readiness packets
- current-lineage revalidation after packet creation
- packet preservation after later human lifecycle decisions
- a bounded post-training reconciliation worker
- System Health visibility
- a first-class **Admin Post-Training Readiness UI**

A readiness packet means only that the configured evidence gates were satisfied for **human consideration**.

Phase 42 never promotes, approves, activates, trains, or changes production AI routing automatically.

The controlled path is now:

**Governed data → Frozen training dataset → Training job → Experimental model → Equivalent evaluation → Human review → Readiness packet → Human Phase 40 decision**

See `docs/phase-42-post-training-evaluation-readiness.md`.


## Phase 43 — Model Release Decision Workspace

Annotated now has the explicit human decision layer between a Phase 42 readiness packet and Phase 40 lifecycle controls.

Phase 43 adds:

- integrity-valid readiness-packet intake
- rollout and rollback plans
- governed rollback-target validation
- model-risk checklist
- creator/reviewer separation
- configurable independent reviewer requirement
- reviewer signatures bound to the current decision snapshot
- automatic stale-signature detection after checklist/plan changes
- signed Proceed / Hold / Reject outcomes
- deterministic decision and signature hashes
- signed release-decision JSON export
- Model Registry decision history/handoff
- separate AI routing handoff
- a first-class **Admin Model Release Decision UI**

A Proceed decision is an authorization record for human continuation into Model Registry. It never changes model lifecycle or production AI routing automatically.

The controlled path is:

**Readiness packet → Risk/rollout/rollback review → Independent signatures → Signed human decision → Explicit Model Registry action → Separate routing action**

See `docs/phase-43-model-release-decision-workspace.md`.
