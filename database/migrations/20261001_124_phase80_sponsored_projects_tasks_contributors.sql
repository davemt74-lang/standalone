-- Phase 80 Section 9 — Sponsored Research Projects, Tasks & Contributors

CREATE TABLE IF NOT EXISTS sponsored_research_projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL UNIQUE,
  research_project_id BIGINT UNSIGNED NOT NULL UNIQUE,
  team_id BIGINT UNSIGNED NOT NULL,
  research_agent_id BIGINT UNSIGNED NULL,
  status ENUM('active','paused','completed','cancelled','archived') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_sponsored_project_team(team_id,status,updated_at),
  CONSTRAINT fk_sponsored_project_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_research_project FOREIGN KEY(research_project_id) REFERENCES research_projects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_team FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_project_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_project_contributors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  sponsored_project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('lead','researcher','reviewer','observer') NOT NULL DEFAULT 'researcher',
  status ENUM('invited','active','removed','declined') NOT NULL DEFAULT 'invited',
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
  invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME NULL,
  removed_at DATETIME NULL,
  notes VARCHAR(1000) NULL,
  UNIQUE KEY uq_sponsored_project_contributor(sponsored_project_id,user_id),
  INDEX idx_sponsored_project_contributor_user(user_id,status,invited_at),
  CONSTRAINT fk_sponsored_project_contributor_project FOREIGN KEY(sponsored_project_id) REFERENCES sponsored_research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_project_contributor_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_contributor_inviter FOREIGN KEY(invited_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_task_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  sponsored_project_id BIGINT UNSIGNED NOT NULL,
  research_task_id BIGINT UNSIGNED NOT NULL,
  contributor_user_id BIGINT UNSIGNED NOT NULL,
  assignment_role ENUM('owner','contributor','reviewer') NOT NULL DEFAULT 'contributor',
  status ENUM('assigned','accepted','in_progress','submitted','completed','reassigned','removed') NOT NULL DEFAULT 'assigned',
  assigned_by_user_id BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME NULL,
  submitted_at DATETIME NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_sponsored_task_assignment(research_task_id,contributor_user_id,assignment_role),
  INDEX idx_sponsored_task_assignment_project(sponsored_project_id,status,assigned_at),
  INDEX idx_sponsored_task_assignment_contributor(contributor_user_id,status,assigned_at),
  CONSTRAINT fk_sponsored_task_assignment_project FOREIGN KEY(sponsored_project_id) REFERENCES sponsored_research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_task_assignment_task FOREIGN KEY(research_task_id) REFERENCES research_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_task_assignment_contributor FOREIGN KEY(contributor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_task_assignment_assigner FOREIGN KEY(assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_task_deliverable_refs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  assignment_id BIGINT UNSIGNED NOT NULL,
  research_task_id BIGINT UNSIGNED NOT NULL,
  contributor_user_id BIGINT UNSIGNED NOT NULL,
  ref_type ENUM('annotation','source','claim','finding','report_version','mission','dataset','document') NOT NULL,
  ref_public_id VARCHAR(64) NOT NULL,
  note VARCHAR(1000) NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_task_deliverable_ref(assignment_id,ref_type,ref_public_id),
  INDEX idx_sponsored_task_deliverable_task(research_task_id,submitted_at),
  CONSTRAINT fk_sponsored_task_deliverable_assignment FOREIGN KEY(assignment_id) REFERENCES sponsored_research_task_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_task_deliverable_task FOREIGN KEY(research_task_id) REFERENCES research_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_task_deliverable_contributor FOREIGN KEY(contributor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_project_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  sponsored_project_id BIGINT UNSIGNED NOT NULL,
  task_assignment_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_project_event(sponsored_project_id,created_at,id),
  CONSTRAINT fk_sponsored_project_event_project FOREIGN KEY(sponsored_project_id) REFERENCES sponsored_research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_project_event_assignment FOREIGN KEY(task_assignment_id) REFERENCES sponsored_research_task_assignments(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_project_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
