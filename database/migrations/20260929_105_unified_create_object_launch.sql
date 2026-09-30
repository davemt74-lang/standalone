-- Annotated Phase 74 Section 3 — Unified Create + Object Launch Experience
-- Navigation-only metadata. Canonical research objects remain owned by their existing domain tables.

CREATE TABLE IF NOT EXISTS research_object_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  source_type VARCHAR(48) NOT NULL,
  source_public_id VARCHAR(64) NOT NULL,
  target_type VARCHAR(48) NOT NULL,
  target_public_id VARCHAR(64) NOT NULL,
  relationship VARCHAR(48) NOT NULL DEFAULT 'created_from',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_object_link(source_type,source_public_id,target_type,target_public_id,relationship),
  INDEX idx_research_object_link_source(source_type,source_public_id,created_at),
  INDEX idx_research_object_link_target(target_type,target_public_id,created_at),
  CONSTRAINT fk_research_object_link_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_object_shortcuts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  object_type VARCHAR(48) NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  pinned_at DATETIME NULL,
  last_opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_object_shortcut(user_id,object_type,object_public_id),
  INDEX idx_user_object_shortcut_recent(user_id,last_opened_at),
  INDEX idx_user_object_shortcut_pinned(user_id,pinned_at),
  CONSTRAINT fk_user_object_shortcut_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
