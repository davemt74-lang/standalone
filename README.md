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
