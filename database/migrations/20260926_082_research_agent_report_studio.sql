-- Phase 68 — Research Agent Report Studio
-- Separates Report Runs from optional Research Documents and adds per-Agent saved presets.

CREATE TABLE IF NOT EXISTS research_report_presets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  name VARCHAR(190) NOT NULL,
  report_type VARCHAR(64) NOT NULL,
  title_template VARCHAR(255) NULL,
  parameters_json JSON NULL,
  scope_json JSON NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  last_run_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_report_presets_agent(research_agent_id,status,updated_at),
  INDEX idx_report_presets_project(project_id,status,updated_at),
  CONSTRAINT fk_report_presets_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_presets_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_presets_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_system_reports
  DROP FOREIGN KEY fk_system_reports_document;

ALTER TABLE research_system_reports
  MODIFY COLUMN document_object_id BIGINT UNSIGNED NULL,
  ADD COLUMN rendered_html MEDIUMTEXT NULL AFTER title,
  ADD COLUMN rendered_summary TEXT NULL AFTER rendered_html,
  ADD COLUMN parameters_json JSON NULL AFTER rendered_summary,
  ADD COLUMN scope_json JSON NULL AFTER parameters_json,
  ADD COLUMN sections_json JSON NULL AFTER scope_json,
  ADD COLUMN knowledge_manifest_json JSON NULL AFTER sections_json,
  ADD COLUMN generation_mode ENUM('user','agent','program','legacy') NOT NULL DEFAULT 'user' AFTER status,
  ADD COLUMN freshness_state ENUM('current','changed','materially_changed','stale') NOT NULL DEFAULT 'current' AFTER input_state_hash,
  ADD COLUMN refreshed_from_report_id BIGINT UNSIGNED NULL AFTER freshness_state,
  ADD COLUMN preset_id BIGINT UNSIGNED NULL AFTER refreshed_from_report_id,
  ADD COLUMN document_created_at DATETIME NULL AFTER document_object_id,
  ADD INDEX idx_system_reports_refresh(refreshed_from_report_id,created_at),
  ADD INDEX idx_system_reports_preset(preset_id,created_at);

UPDATE research_system_reports rsr
JOIN research_workspace_documents rwd ON rwd.object_id=rsr.document_object_id
SET rsr.rendered_html=rwd.content_html,
    rsr.rendered_summary=rwd.summary,
    rsr.generation_mode='legacy',
    rsr.document_created_at=COALESCE(rsr.document_created_at,rsr.created_at)
WHERE rsr.rendered_html IS NULL;

ALTER TABLE research_system_reports
  ADD CONSTRAINT fk_system_reports_document FOREIGN KEY(document_object_id) REFERENCES research_workspace_objects(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_system_reports_refresh FOREIGN KEY(refreshed_from_report_id) REFERENCES research_system_reports(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_system_reports_preset FOREIGN KEY(preset_id) REFERENCES research_report_presets(id) ON DELETE SET NULL;
