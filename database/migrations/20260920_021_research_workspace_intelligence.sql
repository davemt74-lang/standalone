-- Annotated Phase 14 — Research Workspace Intelligence
-- Live/regenerable workspace analysis. Published research reports remain immutable snapshots.

CREATE TABLE IF NOT EXISTS research_workspace_intelligence (
  project_id BIGINT UNSIGNED PRIMARY KEY,
  status ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
  input_hash CHAR(64) NULL,
  summary MEDIUMTEXT NULL,
  highlights_json JSON NULL,
  gaps_json JSON NULL,
  conflicts_json JSON NULL,
  source_risks_json JSON NULL,
  next_actions_json JSON NULL,
  confidence DECIMAL(5,4) NULL,
  model_id BIGINT UNSIGNED NULL,
  ai_run_public_id VARCHAR(40) NULL,
  prompt_version VARCHAR(40) NOT NULL DEFAULT 'phase14-v1',
  last_error VARCHAR(1000) NULL,
  queued_at DATETIME NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_workspace_status(status,updated_at),
  INDEX idx_research_workspace_model(model_id,processed_at),
  CONSTRAINT fk_research_workspace_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_workspace_model FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
