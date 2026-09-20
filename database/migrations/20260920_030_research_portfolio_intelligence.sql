-- Annotated Phase 23 — Research Portfolio Intelligence
-- Portfolio intelligence is derived from existing authoritative Research state.
-- Persist only explicit user portfolio preferences.

CREATE TABLE IF NOT EXISTS research_portfolio_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,project_id),
  INDEX idx_research_portfolio_pinned(user_id,pinned,updated_at),
  CONSTRAINT fk_research_portfolio_pref_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_portfolio_pref_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
