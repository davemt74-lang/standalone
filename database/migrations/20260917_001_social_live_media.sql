-- Annotated V1 social/live/media foundation. Keep immutable once applied.
CREATE TABLE IF NOT EXISTS extension_auth_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL UNIQUE,
  redirect_uri VARCHAR(500) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_extension_codes_expiry(expires_at),
  CONSTRAINT fk_extension_code_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS extension_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  device_name VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  INDEX idx_extension_session_user(user_id, revoked_at),
  CONSTRAINT fk_extension_session_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocks (
  blocker_user_id BIGINT UNSIGNED NOT NULL,
  blocked_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(blocker_user_id, blocked_user_id),
  CONSTRAINT fk_block_a FOREIGN KEY(blocker_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_block_b FOREIGN KEY(blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS page_presence_sessions (
  source_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  presence_mode ENUM('visible','team_only','cloaked','off') NOT NULL DEFAULT 'cloaked',
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(source_id,user_id),
  INDEX idx_presence_source_seen(source_id,last_seen_at),
  CONSTRAINT fk_presence_source FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_presence_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS media_derivatives (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  capture_id BIGINT UNSIGNED NOT NULL,
  media_type ENUM('video','audio') NOT NULL,
  storage_path VARCHAR(500) NULL,
  duration_seconds DECIMAL(10,3) NOT NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  resolution_label VARCHAR(32) NULL,
  checksum CHAR(64) NULL,
  processing_status ENUM('queued','processing','ready','blocked','failed') NOT NULL DEFAULT 'queued',
  processing_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_media_capture(capture_id),
  CONSTRAINT fk_media_capture FOREIGN KEY(capture_id) REFERENCES captures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  derivative_id BIGINT UNSIGNED NOT NULL,
  source_url TEXT NOT NULL,
  input_path VARCHAR(500) NULL,
  start_seconds DECIMAL(10,3) NOT NULL,
  end_seconds DECIMAL(10,3) NOT NULL,
  status ENUM('queued','processing','done','blocked','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_media_jobs_status(status,created_at),
  CONSTRAINT fk_media_job_derivative FOREIGN KEY(derivative_id) REFERENCES media_derivatives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  notification_type VARCHAR(64) NOT NULL,
  object_type VARCHAR(64) NULL,
  object_public_id VARCHAR(64) NULL,
  body VARCHAR(500) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notification_user(user_id,read_at,created_at),
  CONSTRAINT fk_notification_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE annotations ADD COLUMN IF NOT EXISTS team_id BIGINT UNSIGNED NULL AFTER visibility;
ALTER TABLE annotations ADD INDEX IF NOT EXISTS idx_annotations_team(team_id,published_at);
