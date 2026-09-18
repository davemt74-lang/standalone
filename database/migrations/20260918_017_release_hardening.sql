-- Annotated V1.1 Phase 11: V1 release hardening, onboarding, and operations.
-- Expand-only and retry-safe. Keep immutable once released.

CREATE TABLE IF NOT EXISTS user_onboarding (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  welcome_seen_at DATETIME NULL,
  dismissed_at DATETIME NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_onboarding_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_heartbeats (
  worker_name VARCHAR(64) NOT NULL PRIMARY KEY,
  last_started_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_failure_at DATETIME NULL,
  last_status ENUM('starting','idle','success','failure') NOT NULL DEFAULT 'idle',
  processed_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  failed_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_message VARCHAR(1000) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_worker_heartbeat_seen(last_seen_at,last_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE extension_sessions
  ADD COLUMN IF NOT EXISTS client_version VARCHAR(32) NULL AFTER device_name;

ALTER TABLE extension_sessions
  ADD INDEX IF NOT EXISTS idx_extension_session_user_active(user_id,revoked_at,expires_at,last_used_at);
