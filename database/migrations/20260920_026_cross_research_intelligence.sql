-- Annotated Phase 19 — Cross-Research Intelligence
-- Suggestions are derived from current accessible Research state.
-- Persist only explicit user decisions and accepted cross-project links.

CREATE TABLE IF NOT EXISTS cross_research_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  source_project_id BIGINT UNSIGNED NOT NULL,
  target_project_id BIGINT UNSIGNED NOT NULL,
  object_type ENUM('project','claim','entity','source','annotation','finding') NOT NULL DEFAULT 'project',
  source_object_public_id VARCHAR(64) NOT NULL,
  target_object_public_id VARCHAR(64) NOT NULL,
  relation_type ENUM('related','follow_up','supports','contradicts','duplicates','refines','depends_on','same_entity','shared_source','shared_annotation','shared_evidence','evidence_reuse','competes','derivative','context') NOT NULL DEFAULT 'related',
  rationale VARCHAR(1000) NULL,
  suggestion_key CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cross_research_link(user_id,source_project_id,target_project_id,object_type,source_object_public_id,target_object_public_id,relation_type),
  INDEX idx_cross_research_source_project(source_project_id,updated_at),
  INDEX idx_cross_research_target_project(target_project_id,updated_at),
  INDEX idx_cross_research_user(user_id,updated_at),
  INDEX idx_cross_research_suggestion(suggestion_key),
  CONSTRAINT fk_cross_research_link_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cross_research_link_source_project FOREIGN KEY(source_project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_cross_research_link_target_project FOREIGN KEY(target_project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT chk_cross_research_distinct_projects CHECK (source_project_id<>target_project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cross_research_decisions (
  user_id BIGINT UNSIGNED NOT NULL,
  suggestion_key CHAR(64) NOT NULL,
  decision ENUM('accepted','rejected') NOT NULL,
  link_public_id VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,suggestion_key),
  INDEX idx_cross_research_decision_user(user_id,decision,updated_at),
  CONSTRAINT fk_cross_research_decision_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_automations
  MODIFY COLUMN workflow_type ENUM('briefing','review','source_refresh','cross_research_review') NOT NULL;

