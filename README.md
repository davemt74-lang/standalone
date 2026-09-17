# Annotated

Annotated is a standalone, sidebar-first social annotation and collaborative research platform.

This repository contains the Chrome Manifest V3 sidebar extension, PHP web/API application, MariaDB schema, and worker foundations for source snapshots, media processing, social feeds, live page collaboration, and research.

## First deployment

1. Copy `config.example.php` to `config.php` and set the database + OAuth configuration.
2. Import `database/schema.sql` into the empty MariaDB database.
3. Open `/first-admin.php` and create the first Annotated administrator.
4. Sign in as the administrator and open `/upgrade.php` to apply the ordered files in `database/migrations/`.
5. Configure `storage.private_root` to a writable directory **outside the public web root** and grant the PHP/worker user read/write access.
6. Load the `extension/` folder as an unpacked Chrome extension for development, open Extension Options, and set the Annotated website/API URL.

There is intentionally no installer. The base schema is imported once; all later database changes are forward-only SQL migrations applied through `upgrade.php`.

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
