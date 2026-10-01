# Annotated V1 — Owner-verified installation, upgrades and recovery

## Audit and production risk
Previously `install.php` accepted public database configuration and `first-admin.php` allowed any unauthenticated visitor to create the initial administrator while `users` was empty. A freshly exposed domain could be claimed before the owner arrived. `upgrade.php` also allowed an unauthenticated upgrade when the users table was empty. Existing schema/migration and release backup utilities should be reused, not replaced.

## Owner-proof implementation
Generate a cryptographically random 32-byte (64-hex) token **outside** the webroot by running `php bin/create-setup-token.php` over SSH from the installed site or using `openssl rand -hex 32` and placing the value in `../annotated-setup-owner.key` with private filesystem permissions. The generator refuses to overwrite an existing token. Never upload the token into the public site, publish it in logs, or put it in a URL.

Visit /install.php, submit the token in the authorization form, then save and test DB credentials and run the existing base schema + immutable migration engine. The verified browser installer session stores only a SHA-256 token fingerprint and is invalidated when the token rotates or disappears. Visit /first-admin.php and enter the token again along with the initial admin credentials. The handler rechecks token and zero-user state while holding the existing first-admin MySQL advisory lock, creates the existing user/onboarding records and attempts to remove the key. If automatic removal is prevented by filesystem permissions, remove the key manually immediately. The account-existence check closes first-admin permanently once created. Upgrade.php requires a genuine authenticated admin regardless of user count.

## Ten acceptance requirements
1. Manual high-entropy owner token outside webroot; CLI creator refuses overwrite.
2. No token or malformed token cannot authorize installer.
3. Install session records only a digest, not the raw token; token rotation or deletion revokes it.
4. No configure/import mutation without owner-authorized session and CSRF.
5. First-admin independently checks owner token and empty-user DB inside its advisory lock.
6. First-admin uses existing user/onboarding flow; no second identity or token DB.
7. Upgrade PHP endpoint requires a genuine administrator for every request.
8. Pure static and real MariaDB/MySQL 8 DB journeys cover positive, negative, rotation, duplicate and migration invariants.
9. Exact-head PHP 8.1/8.3, MariaDB, MySQL 8 and model-governance gates green; reviewed before merge.
10. After merge build full Website and Chrome ZIPs, verify CRC/checksums and source ancestry.

Passing these tests certifies this **one release section**, not the entire installed V1 product. Actual first-install browser, all historical upgrade and private-storage restore rehearsals remain separate operational gates.
