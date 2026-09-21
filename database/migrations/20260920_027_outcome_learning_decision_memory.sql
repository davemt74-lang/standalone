-- Annotated Phase 20 — Outcome Learning & Decision Memory
-- Explicit, inspectable outcome events only. No hidden behavioral profile and no automatic preference steering.

CREATE TABLE IF NOT EXISTS research_outcome_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  decision_type VARCHAR(40) NOT NULL,
  source_type VARCHAR(64) NOT NULL,
  source_public_id VARCHAR(64) NULL,
  object_type VARCHAR(64) NULL,
  object_public_id VARCHAR(64) NULL,
  title VARCHAR(255) NOT NULL,
  summary VARCHAR(1200) NULL,
  note TEXT NULL,
  result_type VARCHAR(64) NULL,
  result_public_id VARCHAR(64) NULL,
  metadata_json JSON NULL,
  dedupe_key CHAR(64) NOT NULL,
  is_manual TINYINT(1) NOT NULL DEFAULT 0,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_outcome_user_dedupe(user_id,dedupe_key),
  INDEX idx_research_outcome_user_time(user_id,occurred_at),
  INDEX idx_research_outcome_project_time(project_id,occurred_at),
  INDEX idx_research_outcome_source(source_type,source_public_id),
  INDEX idx_research_outcome_object(object_type,object_public_id),
  INDEX idx_research_outcome_decision(decision_type,occurred_at),
  CONSTRAINT fk_research_outcome_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_outcome_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_outcome_refs (
  outcome_id BIGINT UNSIGNED NOT NULL,
  ref_type VARCHAR(64) NOT NULL,
  ref_public_id VARCHAR(64) NOT NULL,
  ref_role ENUM('context','source','result') NOT NULL DEFAULT 'context',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(outcome_id,ref_type,ref_public_id,ref_role),
  INDEX idx_research_outcome_ref_lookup(ref_type,ref_public_id),
  CONSTRAINT fk_research_outcome_ref_outcome FOREIGN KEY(outcome_id) REFERENCES research_outcome_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_outcome_feedback (
  user_id BIGINT UNSIGNED NOT NULL,
  outcome_id BIGINT UNSIGNED NOT NULL,
  usefulness ENUM('helpful','not_helpful') NULL,
  follow_up_state ENUM('none','follow_up','resolved','reopened') NOT NULL DEFAULT 'none',
  comment VARCHAR(1000) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,outcome_id),
  INDEX idx_research_outcome_feedback_state(user_id,follow_up_state,updated_at),
  CONSTRAINT fk_research_outcome_feedback_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_outcome_feedback_outcome FOREIGN KEY(outcome_id) REFERENCES research_outcome_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE research_automations
  MODIFY COLUMN workflow_type ENUM('briefing','review','source_refresh','cross_research_review','outcome_review') NOT NULL;
