# Annotated V1 — web-server access boundary and admin recovery

The Website ZIP includes PHP libraries, CLI workers, SQL migrations, CI fixtures and internal documentation in the web-root deployment tree. Every production web server **must block HTTP requests** to /app, /bin, /database, /docs, /tests, /worker and /storage, and to legacy /reset-admin.php. These files remain available to local PHP and CLI workers.

The legacy reset-admin.php has been removed: it permitted administrator password changes without verifying server ownership. **Manually delete any stale reset-admin.php from the existing live web root**, because extracting a newer ZIP over older files does not delete obsolete files. If the older build is online, restrict that URL immediately until replacement.

## Apache / shared hosting

Use the bundled root .htaccess with mod_rewrite enabled and AllowOverride FileInfo Options (or the hosting provider's equivalent). Deny rules run before existing-file routing; test against the actual host. Preserve /uploads/.htaccess; legitimate public /uploads/profiles images must remain accessible, while executable uploads must not.

## Nginx

Nginx ignores .htaccess. Add these locations **before** the generic PHP regex location in the actual server block:

```nginx
location ~* ^/(?:app|bin|database|docs|tests|worker|storage)(?:/|$) { return 404; }
location ~* ^/(?:README|AUDIT)\.md$ { return 404; }
location ~* ^/reset-admin\.php$ { return 404; }
location ~* ^/admin-password-reset\.enable$ { return 404; }
location ~ (^|/)\.(?:git|github|env|svn|hg)(?:/|$|\.) { return 404; }
location ~* ^/uploads/.*\.(?:php|phtml|phar|cgi|pl|py|sh)$ { return 404; }
```

Place any permitted /.well-known/ ACME handling ahead of generic hidden-file restrictions. Code-level tests cannot prove which web-server configuration is active.

## Operator activation check

From an unauthenticated browser or HTTP client, confirm the actual deployed server returns 403 or 404 (without body disclosure) for /app/bootstrap.php, /bin/release-backup.php, /database/schema.sql, /docs/v1-webserver-and-recovery.md, /tests/phase81-v1-release-webroot-contract.php, /worker/ai-worker.php, /storage/.htaccess, and /reset-admin.php. Confirm /, /login.php, /assets/css/app.css, /extension/manifest.json and a real /uploads/profiles image remain reachable as intended.

The separate owner-controlled /admin-password-reset.php is disabled by default; the server owner must create admin-password-reset.enable through the hosting file manager/SSH before using it. Remove the marker immediately after recovery, add temporary IP restrictions where possible, and verify browser/extension sessions are revoked. The .enable marker itself must never be downloadable.

Fresh V1 installation now requires an **out-of-web-root server-owner token** for both installer authorization and first-administrator creation. Run `php bin/create-setup-token.php` over SSH from the application root (or generate 32 cryptographically random bytes as 64 lowercase hex characters with `openssl rand -hex 32` and place them in `../annotated-setup-owner.key` using the hosting file manager). Keep the file mode private, never place the token in a URL, and delete it immediately after finishing installation. The first-admin handler also attempts automatic cleanup. The web upgrade endpoint requires a genuine authenticated administrator even on an empty installation. Restrict initial access until the first administrator is created even with the token gate; HTTPS is strongly recommended.
