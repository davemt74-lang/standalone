-- Phase 80 Section 11 — Sponsored Project Compensation & Public Discovery

ALTER TABLE sponsored_research_campaigns
  ADD COLUMN compensation_model ENUM('flat_fee') NOT NULL DEFAULT 'flat_fee' AFTER budget_cents,
  ADD COLUMN researcher_compensation_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER compensation_model;

CREATE TABLE IF NOT EXISTS sponsored_research_project_compensations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  researcher_user_id BIGINT UNSIGNED NOT NULL,
  research_agent_id BIGINT UNSIGNED NOT NULL,
  accepted_submission_id BIGINT UNSIGNED NULL,
  compensation_model ENUM('flat_fee') NOT NULL DEFAULT 'flat_fee',
  amount_cents BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('pending','earned','approved_for_payment','paid','voided') NOT NULL DEFAULT 'pending',
  agreed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  earned_at DATETIME NULL,
  approved_at DATETIME NULL,
  paid_at DATETIME NULL,
  voided_at DATETIME NULL,
  payment_reference VARCHAR(190) NULL,
  admin_note TEXT NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_project_comp_assignment(assignment_id),
  INDEX idx_sponsored_project_comp_campaign(campaign_id,status,agreed_at),
  INDEX idx_sponsored_project_comp_researcher(researcher_user_id,status,agreed_at),
  CONSTRAINT fk_sponsored_project_comp_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_comp_assignment FOREIGN KEY(assignment_id) REFERENCES sponsored_research_agent_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_comp_researcher FOREIGN KEY(researcher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_comp_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_comp_submission FOREIGN KEY(accepted_submission_id) REFERENCES sponsored_research_project_submissions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_project_comp_actor FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS sponsored_research_project_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  sample_data_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sponsored_project_settings_actor FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sponsored_research_project_settings(id,sample_data_enabled) VALUES(1,1);
