# Annotated

Annotated is a standalone, sidebar-first social annotation and collaborative research platform.

This repository contains the Chrome Manifest V3 sidebar extension, PHP web/API application, MariaDB schema, and worker foundations for source snapshots, media processing, social feeds, live page collaboration, and research.

## First deployment

1. Copy `config.example.php` to `config.php` and set the database + OAuth configuration.
2. Import `database/schema.sql` into the empty MariaDB database.
3. Open `/first-admin.php` and create the first Annotated administrator.
4. Sign in as the administrator and open `/upgrade.php` to apply the ordered files in `database/migrations/`.
5. Make `storage/uploads/` writable by the PHP/worker user.
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
