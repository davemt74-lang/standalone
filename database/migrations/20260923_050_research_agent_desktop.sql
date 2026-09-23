-- Annotated Phase 52 — Research Agent Desktop

CREATE TABLE IF NOT EXISTS research_workspace_desktop_positions (
  project_id BIGINT UNSIGNED NOT NULL,
  object_type VARCHAR(32) NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  position_x INT NOT NULL DEFAULT 24,
  position_y INT NOT NULL DEFAULT 24,
  z_index INT NOT NULL DEFAULT 1,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(project_id,object_type,object_public_id),
  INDEX idx_research_desktop_updated(project_id,updated_at),
  CONSTRAINT fk_research_desktop_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_desktop_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
