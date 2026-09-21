-- Annotated Phase 17 — Proactive Research Intelligence
ALTER TABLE user_preferences
  ADD COLUMN IF NOT EXISTS proactive_notification_mode ENUM('all','important','mentions') NOT NULL DEFAULT 'important' AFTER home_feed_mode,
  ADD COLUMN IF NOT EXISTS proactive_briefing_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER proactive_notification_mode;

CREATE TABLE IF NOT EXISTS cognitive_watches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  watch_type ENUM('source','project','claim','entity','question') NOT NULL,
  object_public_id VARCHAR(64) NULL,
  query_text VARCHAR(500) NULL,
  watch_key CHAR(64) NOT NULL,
  alert_level ENUM('all','important') NOT NULL DEFAULT 'important',
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  muted_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cognitive_watch_user_key(user_id,watch_key),
  INDEX idx_cognitive_watch_user_status(user_id,status,updated_at),
  INDEX idx_cognitive_watch_object(watch_type,object_public_id),
  CONSTRAINT fk_cognitive_watch_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cognitive_alert_states (
  user_id BIGINT UNSIGNED NOT NULL,
  observation_key CHAR(64) NOT NULL,
  observation_type VARCHAR(64) NOT NULL,
  state ENUM('active','snoozed','resolved') NOT NULL DEFAULT 'active',
  payload_hash CHAR(64) NOT NULL,
  notification_public_id VARCHAR(40) NULL,
  occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_notified_at DATETIME NULL,
  snoozed_until DATETIME NULL,
  resolved_at DATETIME NULL,
  PRIMARY KEY(user_id,observation_key),
  INDEX idx_cognitive_alert_user_state(user_id,state,last_seen_at),
  INDEX idx_cognitive_alert_notification(notification_public_id),
  CONSTRAINT fk_cognitive_alert_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
