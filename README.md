# Annotated

Annotated is a standalone, sidebar-first social annotation and collaborative research platform.

This repository contains the Chrome Manifest V3 sidebar extension, PHP web/API application, MariaDB schema, and worker foundations for source snapshots, media processing, social feeds, live page collaboration, and research.

## First deployment

1. Copy `config.example.php` to `config.php` and set the MariaDB connection plus the production settings you plan to use.
2. Create an **empty** MariaDB database and grant the configured database user access to it.
3. Open `/install.php` in the browser and click **Install Annotated**. The installer imports `database/schema.sql` and applies every bundled forward migration automatically.
4. Create the first administrator on `/first-admin.php`. No bootstrap/setup key is used.
5. Configure `storage.private_root` to a writable directory **outside the public web root** and grant the PHP/worker user read/write access.
6. Load the `extension/` folder as an unpacked Chrome extension for development, open Extension Options, and set the Annotated website/API URL.

Normal web requests automatically route to `/install.php` until the base schema exists. After the first administrator is created, `/first-admin.php` closes permanently. Existing deployments continue to use `/upgrade.php` for later forward-only migrations.

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


## V1.1 RC1 release hardening

Phase 11 adds the release-candidate operating layer around the V1 product loop:

- `/onboarding.php` — one-time first-run checklist based on real account activity
- `/admin/system-health.php` — production readiness, migration, queue, and worker health
- `php bin/release-preflight.php` — CLI release gate using the same health service
- `docs/RELEASE-V1.1-RC1.md` — deployment, backup, rollback, worker, and Chrome release runbook
- browser-session revocation epoch plus existing per-extension session revocation
- OAuth callback state expiry and one-time consumption
- worker heartbeats for media, transcription, source monitoring, AI, and saved-search alerts
- Chrome extension version `0.9.0` with first-install setup and accessibility/keyboard hardening

Recommended recurring workers for RC validation:

```bash
php worker/media-worker.php
php worker/transcription-worker.php
php worker/source-monitor-worker.php 5
php worker/ai-worker.php 5
php worker/saved-search-worker.php 50
```

Before every RC/production deploy, back up both MariaDB and `storage.private_root`, run the preflight command, apply pending migrations through `upgrade.php`, and verify Admin → System Health & Release. See the full runbook for rollback rules.
