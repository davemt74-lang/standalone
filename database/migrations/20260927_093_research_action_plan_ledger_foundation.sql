-- Annotated Phase 72 Section 1 — Action Plan Ledger Foundation
-- Durable Decision-to-Action records pinned to an exact Decision revision/status.
-- Existing Research Tasks and Research Programs remain separate authorities and are not duplicated here.

CREATE TABLE IF NOT EXISTS research_action_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  decision_id BIGINT UNSIGNED NOT NULL,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  objective MEDIUMTEXT NOT NULL,
  expected_result MEDIUMTEXT NOT NULL,
  status ENUM('draft','proposed','active','paused','completed','cancelled','archived') NOT NULL DEFAULT 'draft',
  priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  success_measures_json JSON NOT NULL,
  risks_json JSON NULL,
  assumptions_json JSON NULL,
  start_on DATE NULL,
  due_on DATE NULL,
  source_decision_revision INT UNSIGNED NOT NULL,
  source_decision_config_hash CHAR(64) NOT NULL,
  source_decision_status VARCHAR(32) NOT NULL,
  source_decision_snapshot_json JSON NOT NULL,
  idempotency_key CHAR(64) NOT NULL,
  current_revision INT UNSIGNED NOT NULL DEFAULT 1,
  config_hash CHAR(64) NOT NULL,
  activated_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_action_plan_request(decision_id,idempotency_key),
  INDEX idx_research_action_plan_decision(decision_id,status,updated_at),
  INDEX idx_research_action_plan_agent(research_agent_id,status,updated_at),
  INDEX idx_research_action_plan_project(project_id,status,updated_at),
  INDEX idx_research_action_plan_owner(owner_user_id,status,updated_at),
  CONSTRAINT fk_research_action_plan_decision FOREIGN KEY(decision_id) REFERENCES research_decisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_created_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_owner_user FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_research_action_plan_dates CHECK (due_on IS NULL OR start_on IS NULL OR due_on>=start_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_action_plan_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  action_plan_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  config_json JSON NOT NULL,
  change_reason VARCHAR(1000) NULL,
  edited_by_user_id BIGINT UNSIGNED NULL,
  edited_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_action_plan_revision(action_plan_id,revision_number),
  INDEX idx_research_action_plan_version(action_plan_id,created_at),
  CONSTRAINT fk_research_action_plan_version_plan FOREIGN KEY(action_plan_id) REFERENCES research_action_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_version_user FOREIGN KEY(edited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_action_plan_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  action_plan_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_type ENUM('user','agent','system') NOT NULL DEFAULT 'system',
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_action_plan_event_plan(action_plan_id,id),
  INDEX idx_research_action_plan_event_project(project_id,created_at),
  CONSTRAINT fk_research_action_plan_event_plan FOREIGN KEY(action_plan_id) REFERENCES research_action_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_event_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_action_plan_event_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
