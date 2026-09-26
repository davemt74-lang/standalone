-- Annotated Phase 64 — Unified Research Knowledge & System Reports

CREATE TABLE IF NOT EXISTS research_system_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NOT NULL,
  report_type VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  status ENUM('ready','archived') NOT NULL DEFAULT 'ready',
  document_object_id BIGINT UNSIGNED NOT NULL,
  input_state_hash CHAR(64) NOT NULL,
  evidence_refs_json JSON NULL,
  metrics_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_system_reports_project(project_id,status,created_at),
  INDEX idx_system_reports_agent(research_agent_id,status,created_at),
  INDEX idx_system_reports_type(project_id,report_type,created_at),
  CONSTRAINT fk_system_reports_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_system_reports_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_system_reports_requester FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_system_reports_document FOREIGN KEY(document_object_id) REFERENCES research_workspace_objects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_system_report_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  report_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_type ENUM('user','agent','system') NOT NULL DEFAULT 'system',
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_system_report_events(report_id,created_at),
  CONSTRAINT fk_system_report_events_report FOREIGN KEY(report_id) REFERENCES research_system_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_system_report_events_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
