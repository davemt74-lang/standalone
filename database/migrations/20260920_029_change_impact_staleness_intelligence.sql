-- Annotated Phase 22 — Change Impact & Staleness Intelligence
-- Downstream impact remains derived from authoritative Research state.
-- Persist only explicit human review decisions for a source-change impact.

CREATE TABLE IF NOT EXISTS research_change_impact_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  source_change_event_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  object_type ENUM('project','annotation','claim','finding','report_version','research_review','cross_research_link') NOT NULL,
  object_public_id VARCHAR(64) NOT NULL,
  decision ENUM('acknowledged','reviewed_current','needs_update','resolved','dismissed') NOT NULL,
  note TEXT NULL,
  snoozed_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_change_impact_review(user_id,source_change_event_id,object_type,object_public_id),
  INDEX idx_change_impact_user(user_id,decision,updated_at),
  INDEX idx_change_impact_event(source_change_event_id,updated_at),
  INDEX idx_change_impact_project(project_id,updated_at),
  CONSTRAINT fk_change_impact_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_change_impact_event FOREIGN KEY(source_change_event_id) REFERENCES source_change_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_change_impact_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_automations
  MODIFY COLUMN workflow_type ENUM('briefing','review','source_refresh','cross_research_review','outcome_review','review_digest','impact_review') NOT NULL;
