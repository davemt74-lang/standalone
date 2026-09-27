-- Phase 70 — Longitudinal Research Intelligence & Synthesis
-- Durable project-state snapshots, append-only change ledger, and milestones.

CREATE TABLE IF NOT EXISTS research_longitudinal_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  captured_by_user_id BIGINT UNSIGNED NULL,
  trigger_type ENUM('baseline','manual','program_completed','program_quiet','system') NOT NULL DEFAULT 'system',
  trigger_public_id VARCHAR(64) NULL,
  state_hash CHAR(64) NOT NULL,
  state_json JSON NOT NULL,
  counts_json JSON NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_p70_snapshot_project_state(project_id,state_hash),
  INDEX idx_p70_snapshot_agent(research_agent_id,captured_at),
  INDEX idx_p70_snapshot_project(project_id,captured_at),
  CONSTRAINT fk_p70_snapshot_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_p70_snapshot_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_p70_snapshot_user FOREIGN KEY(captured_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_longitudinal_changes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  previous_snapshot_id BIGINT UNSIGNED NULL,
  object_type VARCHAR(40) NOT NULL,
  object_public_id VARCHAR(160) NOT NULL,
  change_type VARCHAR(40) NOT NULL,
  materiality ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  reason VARCHAR(500) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  dedupe_key CHAR(64) NOT NULL UNIQUE,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_p70_change_agent(research_agent_id,occurred_at),
  INDEX idx_p70_change_project(project_id,occurred_at),
  INDEX idx_p70_change_object(object_type,object_public_id,occurred_at),
  INDEX idx_p70_change_snapshot(snapshot_id,id),
  CONSTRAINT fk_p70_change_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_p70_change_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_p70_change_snapshot FOREIGN KEY(snapshot_id) REFERENCES research_longitudinal_snapshots(id) ON DELETE CASCADE,
  CONSTRAINT fk_p70_change_previous_snapshot FOREIGN KEY(previous_snapshot_id) REFERENCES research_longitudinal_snapshots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_longitudinal_milestones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  change_id BIGINT UNSIGNED NULL,
  milestone_type VARCHAR(64) NOT NULL,
  object_type VARCHAR(40) NOT NULL,
  object_public_id VARCHAR(160) NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  dedupe_key CHAR(64) NOT NULL UNIQUE,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_p70_milestone_agent(research_agent_id,occurred_at),
  INDEX idx_p70_milestone_project(project_id,occurred_at),
  INDEX idx_p70_milestone_object(object_type,object_public_id,occurred_at),
  CONSTRAINT fk_p70_milestone_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_p70_milestone_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_p70_milestone_change FOREIGN KEY(change_id) REFERENCES research_longitudinal_changes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
