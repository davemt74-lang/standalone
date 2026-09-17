# Annotated

Annotated is a standalone, sidebar-first social annotation and collaborative research platform.

## V1 foundation

- Chrome Manifest V3 side-panel extension
- PHP website/API + MariaDB
- Native email/password signup and login
- Google OAuth and X OAuth (PKCE) into the same Annotated identity
- One-time **Create First Admin** bootstrap — no installer
- Versioned, checksum-protected `upgrade.php` database migrations
- Source → Source Version → Capture → Annotation provenance model
- Text selection capture and page-context API
- Server-enforced 90-second media clip rule foundation
- Public annotation pages that always link to the original source
- Mandatory unauthenticated **File a claim** workflow
- Research project foundation
- Live/Cloak Mode data model and sidebar surface

## First deployment

1. Create the MariaDB database and user.
2. Copy `config.example.php` to `config.php` and enter database/base URL/OAuth credentials.
3. Import `database/schema.sql` once into the empty database.
4. Open `/first-admin.php` and create the first administrator. This route permanently closes as soon as a user exists.
5. Load `extension/` as an unpacked Chrome extension for development.

There is intentionally **no installer**.

## Database upgrades

All schema changes after the initial `schema.sql` import go in `database/migrations/` using ordered names such as:

`20260918_001_add_source_snapshots.sql`

`upgrade.php`:

- discovers migrations in filename order
- records applied versions in `schema_migrations`
- records SHA-256 checksums
- refuses to silently rerun a migration whose contents changed after application
- requires an Annotated administrator once the first user exists

Never edit an already-applied migration. Add a new migration instead.

## Authentication

Annotated supports three entry paths into one account:

- Native email/password
- Google OAuth
- X OAuth 2.0 with PKCE

Provider identities are stored in `user_identities`. Google verified-email login can attach to the matching Annotated account; X users may exist without a native email/password until they add one later.

## Product invariants

- Every public annotation links to its original source URL.
- Captures reference an immutable source version.
- Every public annotation page exposes **File a claim**.
- Media endpoints reject clips longer than 90 seconds server-side.
- Public video derivatives are designed to be processed server-side at 240p.
- Cloaked users must never be identified through public page-presence APIs.
