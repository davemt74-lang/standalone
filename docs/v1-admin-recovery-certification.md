# V1 — Administrator recovery: dual owner proof and atomic session revocation

## Audited gap
The original emergency administrator recovery used only a public-root enable marker. A visitor could reset any known administrator while the marker was enabled, and legacy-schema catch blocks could silently update the password without revoking browser or Chrome Extension sessions.

## Canonical change
Reuse the existing recovery page, server-side CSRF, IP rate limit and `annotated_admin_password_reset` advisory lock. The owner must now create both the existing web-root `admin-password-reset.enable` marker and a fresh 32-byte `../annotated-setup-owner.key` using `php bin/create-setup-token.php` (or `openssl rand -hex 32` and a hosting file manager). Owner proof is entered as a password field, never sent in a query string. Missing, revoked, symlinked or incorrect owner files fail closed.

Recovery validates again under its advisory lock, then calls one canonical transaction that **requires** both the `users.sessions_revoked_before` and `extension_sessions.revoked_at` schema. Credentials, future-dated one-second browser revocation cutoff and live extension-token revocation commit together; errors roll back the entire change. Both owner enabling files are removed on success where filesystem permissions allow; an error log directs manual deletion if not.

## Section acceptance
1. Existing page and canonical account/session stores reused.
2. Marker alone cannot authorize recovery.
3. High-entropy owner token stored outside public root and independently entered.
4. CSRF, existing IP limit and advisory lock retained.
5. Token and marker revalidated immediately before a write.
6. Non-admin accounts denied; no silent password-only fallback.
7. Browser session cutoff handles same-second old sessions and Chrome sessions are revoked atomically.
8. Targeted real MariaDB/MySQL8 tests prove authorized, denied, isolation and rollback under forced extension-session update failure.
9. Exact-head PHP 8.1/8.3, MariaDB, MySQL8 and model governance are all green.
10. Reviewed, merged, full Website + Chrome ZIPs rebuilt from exact merged commit and SHA-256/CRC/ancestry verified.

This is the recovery section's acceptance gate, not a claim that production hosting, paid projects or backup restore are certified.
