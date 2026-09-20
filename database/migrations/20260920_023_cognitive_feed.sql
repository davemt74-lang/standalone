-- Annotated Phase 16 — Cognitive Feed
-- Feed observations remain derived from authoritative product state.
-- Persist only explicit user feed preferences and dismissals.

ALTER TABLE user_preferences
  ADD COLUMN IF NOT EXISTS home_feed_mode ENUM('cognitive','latest') NOT NULL DEFAULT 'cognitive';

CREATE TABLE IF NOT EXISTS cognitive_feed_dismissals (
  user_id BIGINT UNSIGNED NOT NULL,
  observation_key CHAR(64) NOT NULL,
  observation_type VARCHAR(64) NOT NULL,
  dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,observation_key),
  INDEX idx_cognitive_dismissal_user_time(user_id,dismissed_at),
  CONSTRAINT fk_cognitive_dismissal_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
