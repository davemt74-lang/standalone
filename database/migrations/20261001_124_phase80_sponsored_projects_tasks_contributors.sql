-- Phase 80 Section 9 — Sponsored Projects, Research Agent Assignments & Deliverable Submissions

CREATE TABLE IF NOT EXISTS sponsored_research_agent_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  participation_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NOT NULL,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  status ENUM('active','paused','completed','withdrawn','removed') NOT NULL DEFAULT 'active',
  agent_submit_enabled TINYINT(1) NOT NULL DEFAULT 1,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  withdrawn_at DATETIME NULL,
  removed_at DATETIME NULL,
  UNIQUE KEY uq_sponsored_agent_assignment_participation(participation_id),
  UNIQUE KEY uq_sponsored_agent_assignment_campaign_agent(campaign_id,research_agent_id),
  INDEX idx_sponsored_agent_assignment_campaign(campaign_id,status,assigned_at),
  INDEX idx_sponsored_agent_assignment_researcher(researcher_user_id,status,assigned_at),
  CONSTRAINT fk_sponsored_agent_assignment_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_agent_assignment_participation FOREIGN KEY(participation_id) REFERENCES sponsored_research_participations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_agent_assignment_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_agent_assignment_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_project_submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  assignment_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NOT NULL,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  status ENUM('submitted','under_review','accepted','revision_requested','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
  submitted_by_agent TINYINT(1) NOT NULL DEFAULT 1,
  submission_hash CHAR(64) NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sponsored_project_submission_campaign(campaign_id,status,submitted_at),
  INDEX idx_sponsored_project_submission_researcher(researcher_user_id,status,submitted_at),
  CONSTRAINT fk_sponsored_project_submission_assignment FOREIGN KEY(assignment_id) REFERENCES sponsored_research_agent_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_submission_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_submission_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_submission_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_project_submission_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  project_submission_id BIGINT UNSIGNED NOT NULL,
  asset_type ENUM('system_report','document','report_version') NOT NULL,
  asset_public_id VARCHAR(64) NOT NULL,
  asset_version VARCHAR(64) NULL,
  title VARCHAR(255) NULL,
  snapshot_json JSON NOT NULL,
  snapshot_hash CHAR(64) NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_project_submission_asset(project_submission_id,asset_type,asset_public_id,asset_version),
  INDEX idx_sponsored_project_submission_asset_ref(asset_type,asset_public_id,asset_version),
  CONSTRAINT fk_sponsored_project_submission_asset_submission FOREIGN KEY(project_submission_id) REFERENCES sponsored_research_project_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_agent_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NULL,
  project_submission_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_agent_event_campaign(campaign_id,created_at,id),
  CONSTRAINT fk_sponsored_agent_event_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_agent_event_assignment FOREIGN KEY(assignment_id) REFERENCES sponsored_research_agent_assignments(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_agent_event_submission FOREIGN KEY(project_submission_id) REFERENCES sponsored_research_project_submissions(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_agent_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
