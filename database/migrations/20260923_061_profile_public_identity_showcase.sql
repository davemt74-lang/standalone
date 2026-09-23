-- Profile Phase 2 — Public Identity & Research Showcase
-- Presentation controls only. Existing annotations, research reports, collections and ACLs remain authoritative.

ALTER TABLE user_preferences
  ADD COLUMN IF NOT EXISTS profile_show_research TINYINT(1) NOT NULL DEFAULT 1 AFTER search_visibility,
  ADD COLUMN IF NOT EXISTS profile_show_collections TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_show_research,
  ADD COLUMN IF NOT EXISTS profile_show_about TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_show_collections;

CREATE TABLE IF NOT EXISTS profile_pins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  object_type ENUM('annotation','research_report','collection') NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  position TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_profile_pin_object(user_id,object_type,object_public_id),
  UNIQUE KEY uq_profile_pin_position(user_id,position),
  INDEX idx_profile_pin_user(user_id,position),
  CONSTRAINT fk_profile_pin_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
