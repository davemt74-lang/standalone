-- Auth and Chrome-extension hardening. Keep immutable once applied.
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email;
ALTER TABLE extension_sessions ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER last_used_at;
ALTER TABLE extension_sessions ADD INDEX IF NOT EXISTS idx_extension_session_expiry(expires_at,revoked_at);

UPDATE extension_sessions
SET expires_at=DATE_ADD(created_at,INTERVAL 30 DAY)
WHERE expires_at IS NULL;
