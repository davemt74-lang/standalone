# Database migrations

Fresh deployments import `database/schema.sql` once.

After that, every schema/data change is shipped as a new ordered `.sql` file in this directory and applied through `/upgrade.php`.

Recommended naming:

`YYYYMMDD_NNN_short_description.sql`

Example:

`20260918_001_add_annotation_snapshots.sql`

Rules:

1. Never edit a migration after it has been applied in production.
2. Add a new migration for corrections or follow-up changes.
3. `upgrade.php` records the filename/version and SHA-256 checksum in `schema_migrations`.
4. Once the first account exists, only an Annotated administrator may run the upgrade UI.
5. Keep migrations forward-only and deployment-safe.
