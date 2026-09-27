-- Annotated Phase 71 Section 1 — Decision & Conclusion Ledger Foundation
-- Durable, versioned decision/conclusion objects with evidence lineage and append-only history.
-- Existing research_outcome_* tables remain the outcome-event layer and are not replaced.

CREATE TABLE IF NOT EXISTS research_decisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  accountable_user_id BIGINT UNSIGNED NULL,
  source_mission_id BIGINT UNSIGNED NULL,
  decision_type ENUM('decision','conclusion','recommendation') NOT NULL DEFAULT 'decision',
  title VARCHAR(255) NOT NULL,
  statement MEDIUMTEXT NOT NULL,
  rationale MEDIUMTEXT NULL,
  status ENUM('draft','proposed','accepted','rejected','deferred','superseded','reopened','archived') NOT NULL DEFAULT 'draft',
  confidence DECIMAL(5,4) NULL,
  assumptions_json JSON NULL,
  uncertainty_json JSON NULL,
  alternatives_json JSON NULL,
  current_revision INT UNSIGNED NOT NULL DEFAULT 1,
  config_hash CHAR(64) NOT NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_decision_agent(research_agent_id,status,updated_at),
  INDEX idx_research_decision_project(project_id,status,updated_at),
  INDEX idx_research_decision_mission(source_mission_id,updated_at),
  INDEX idx_research_decision_accountable(accountable_user_id,status,updated_at),
  CONSTRAINT fk_research_decision_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_created_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_accountable_user FOREIGN KEY(accountable_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_decision_mission FOREIGN KEY(source_mission_id) REFERENCES research_missions(id) ON DELETE SET NULL,
  CONSTRAINT chk_research_decision_confidence CHECK (confidence IS NULL OR (confidence>=0 AND confidence<=1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_decision_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  decision_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  config_json JSON NOT NULL,
  change_reason VARCHAR(1000) NULL,
  edited_by_user_id BIGINT UNSIGNED NULL,
  edited_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_decision_revision(decision_id,revision_number),
  INDEX idx_research_decision_version(decision_id,created_at),
  CONSTRAINT fk_research_decision_version_decision FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_version_user FOREIGN KEY(edited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_decision_refs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  decision_id BIGINT UNSIGNED NOT NULL,
  ref_type VARCHAR(64) NOT NULL,
  ref_public_id VARCHAR(160) NOT NULL,
  ref_role ENUM('supports','contradicts','context','source','assumption','alternative') NOT NULL DEFAULT 'context',
  strength DECIMAL(5,4) NULL,
  note VARCHAR(2000) NULL,
  added_by_user_id BIGINT UNSIGNED NULL,
  added_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_decision_ref(decision_id,ref_type,ref_public_id,ref_role),
  INDEX idx_research_decision_ref_lookup(ref_type,ref_public_id),
  INDEX idx_research_decision_ref_decision(decision_id,ref_role,id),
  CONSTRAINT fk_research_decision_ref_decision FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_ref_user FOREIGN KEY(added_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_research_decision_ref_strength CHECK (strength IS NULL OR (strength>=0 AND strength<=1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_decision_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  decision_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_type ENUM('user','agent','system') NOT NULL DEFAULT 'system',
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_decision_event_decision(decision_id,created_at),
  INDEX idx_research_decision_event_project(project_id,created_at),
  CONSTRAINT fk_research_decision_event_decision FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_event_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_decision_event_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
