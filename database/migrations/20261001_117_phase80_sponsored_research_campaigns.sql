-- Phase 80 Section 2 — Sponsored Research Campaign Foundation

CREATE TABLE IF NOT EXISTS sponsored_research_campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  sponsor_user_id BIGINT UNSIGNED NOT NULL,
  sponsor_profile_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  research_agent_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  brief TEXT NOT NULL,
  objective TEXT NOT NULL,
  status ENUM('draft','funding_required','scheduled','open','paused','closed','under_review','completed','cancelled','archived') NOT NULL DEFAULT 'draft',
  access_mode ENUM('public','private','invite_only') NOT NULL DEFAULT 'private',
  budget_currency CHAR(3) NOT NULL DEFAULT 'USD',
  budget_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_participants INT UNSIGNED NULL,
  eligibility_json JSON NULL,
  disclosure_json JSON NULL,
  starts_at DATETIME NULL,
  submission_deadline DATETIME NULL,
  current_revision INT UNSIGNED NOT NULL DEFAULT 1,
  config_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sponsored_campaign_account(account_id,status,updated_at),
  INDEX idx_sponsored_campaign_sponsor(sponsor_user_id,status,updated_at),
  INDEX idx_sponsored_campaign_access(access_mode,status,submission_deadline),
  INDEX idx_sponsored_campaign_agent(research_agent_id,status),
  CONSTRAINT fk_sponsored_campaign_sponsor_user FOREIGN KEY(sponsor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_campaign_sponsor_profile FOREIGN KEY(sponsor_profile_id) REFERENCES sponsor_account_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_campaign_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sponsored_campaign_agent FOREIGN KEY(research_agent_id) REFERENCES research_agents(id) ON DELETE SET NULL,
  CONSTRAINT chk_sponsored_campaign_budget CHECK (budget_cents>=0),
  CONSTRAINT chk_sponsored_campaign_participants CHECK (max_participants IS NULL OR max_participants>=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_campaign_questions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  question TEXT NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sponsored_campaign_question(campaign_id,position,id),
  CONSTRAINT fk_sponsored_campaign_question_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_campaign_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  config_json JSON NOT NULL,
  config_hash CHAR(64) NOT NULL,
  change_reason VARCHAR(1000) NULL,
  edited_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sponsored_campaign_revision(campaign_id,revision_number),
  INDEX idx_sponsored_campaign_version(campaign_id,created_at),
  CONSTRAINT fk_sponsored_campaign_version_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_campaign_version_user FOREIGN KEY(edited_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsored_research_campaign_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  campaign_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sponsored_campaign_event(campaign_id,created_at,id),
  INDEX idx_sponsored_campaign_event_actor(actor_user_id,created_at,id),
  CONSTRAINT fk_sponsored_campaign_event_campaign FOREIGN KEY(campaign_id) REFERENCES sponsored_research_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsored_campaign_event_user FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
