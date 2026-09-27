-- Research Missions V1 — Section 1: Mission Foundation
-- Additive mission state only. Missions reuse existing Research Agents, Tasks/Plans, and Programs.

CREATE TABLE IF NOT EXISTS research_missions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  research_question TEXT NOT NULL,
  objective TEXT NOT NULL,
  success_definition TEXT NULL,
  status ENUM('draft','active','blocked','review','completed','cancelled','archived') NOT NULL DEFAULT 'draft',
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  scope_json JSON NULL,
  constraints_json JSON NULL,
  plan_id BIGINT UNSIGNED NULL,
  program_id BIGINT UNSIGNED NULL,
  current_revision INT UNSIGNED NOT NULL DEFAULT 1,
  config_hash CHAR(64) NOT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_mission_agent(research_agent_id,status,updated_at),
  INDEX idx_research_mission_project(project_id,status,updated_at),
  INDEX idx_research_mission_plan(plan_id),
  INDEX idx_research_mission_program(program_id),
  CONSTRAINT fk_research_mission_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_mission_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_mission_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_mission_plan FOREIGN KEY(plan_id) REFERENCES research_task_plans(id) ON DELETE SET NULL,
  CONSTRAINT fk_research_mission_program FOREIGN KEY(program_id) REFERENCES research_programs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_mission_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  mission_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  config_json JSON NOT NULL,
  change_reason VARCHAR(1000) NULL,
  edited_by_user_id BIGINT UNSIGNED NULL,
  edited_by_agent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_research_mission_revision(mission_id,revision_number),
  INDEX idx_research_mission_version(mission_id,created_at),
  CONSTRAINT fk_research_mission_version_mission FOREIGN KEY(mission_id) REFERENCES research_missions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_mission_version_user FOREIGN KEY(edited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_mission_criteria (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  mission_id BIGINT UNSIGNED NOT NULL,
  criterion_type ENUM('answer','evidence','coverage','quality','custom') NOT NULL DEFAULT 'custom',
  label VARCHAR(255) NOT NULL,
  description TEXT NULL,
  target_json JSON NULL,
  status ENUM('pending','satisfied','failed','waived') NOT NULL DEFAULT 'pending',
  evaluation_json JSON NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  evaluated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_mission_criterion(mission_id,status,position),
  CONSTRAINT fk_research_mission_criterion_mission FOREIGN KEY(mission_id) REFERENCES research_missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_mission_subquestions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  mission_id BIGINT UNSIGNED NOT NULL,
  linked_task_id BIGINT UNSIGNED NULL,
  question TEXT NOT NULL,
  rationale TEXT NULL,
  status ENUM('open','researching','answered','blocked','deferred') NOT NULL DEFAULT 'open',
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  answer_summary MEDIUMTEXT NULL,
  confidence DECIMAL(5,4) NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  answered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_mission_subquestion(mission_id,status,position),
  INDEX idx_research_mission_subquestion_task(linked_task_id),
  CONSTRAINT fk_research_mission_subquestion_mission FOREIGN KEY(mission_id) REFERENCES research_missions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_mission_subquestion_task FOREIGN KEY(linked_task_id) REFERENCES research_tasks(id) ON DELETE SET NULL,
  CONSTRAINT chk_research_mission_confidence CHECK (confidence IS NULL OR (confidence>=0 AND confidence<=1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_mission_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  mission_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_type ENUM('user','agent','system') NOT NULL DEFAULT 'system',
  actor_user_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_mission_event_mission(mission_id,created_at),
  INDEX idx_research_mission_event_project(project_id,created_at),
  CONSTRAINT fk_research_mission_event_mission FOREIGN KEY(mission_id) REFERENCES research_missions(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_mission_event_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_mission_event_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
