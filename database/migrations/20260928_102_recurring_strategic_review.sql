-- Annotated Phase 73 Section 5 — Recurring Strategic Review
-- Reuses the existing Portfolio cycle clock and Collaborative Research Review engine.

ALTER TABLE research_reviews
  MODIFY COLUMN subject_type ENUM(
    'claim','finding','report_version','agent_action','document','mission',
    'decision','decision_reconsideration','action_plan','strategic_review'
  ) NOT NULL;

CREATE TABLE IF NOT EXISTS research_intelligence_strategic_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  cycle_id BIGINT UNSIGNED NULL,
  previous_strategic_review_id BIGINT UNSIGNED NULL,
  collaborative_review_id BIGINT UNSIGNED NULL,
  trigger_type ENUM('manual','cycle') NOT NULL DEFAULT 'manual',
  scheduled_for DATETIME NULL,
  packet_json JSON NOT NULL,
  packet_hash CHAR(64) NOT NULL,
  previous_packet_hash CHAR(64) NULL,
  material_changed TINYINT(1) NOT NULL DEFAULT 1,
  dedupe_key CHAR(64) NOT NULL UNIQUE,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_p73_strategic_review_portfolio(portfolio_id,created_at,id),
  INDEX idx_p73_strategic_review_cycle(cycle_id),
  INDEX idx_p73_strategic_review_collab(collaborative_review_id),
  CONSTRAINT fk_p73_strategic_review_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_strategic_review_project FOREIGN KEY(project_id) REFERENCES research_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_strategic_review_cycle FOREIGN KEY(cycle_id) REFERENCES research_intelligence_portfolio_cycles(id) ON DELETE SET NULL,
  CONSTRAINT fk_p73_strategic_review_previous FOREIGN KEY(previous_strategic_review_id) REFERENCES research_intelligence_strategic_reviews(id) ON DELETE SET NULL,
  CONSTRAINT fk_p73_strategic_review_collab FOREIGN KEY(collaborative_review_id) REFERENCES research_reviews(id) ON DELETE SET NULL,
  CONSTRAINT fk_p73_strategic_review_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_strategic_review_settings (
  portfolio_id BIGINT UNSIGNED PRIMARY KEY,
  status ENUM('active','paused') NOT NULL DEFAULT 'paused',
  cadence ENUM('every_cycle','weekly','monthly','quarterly') NOT NULL DEFAULT 'monthly',
  due_offset_hours INT UNSIGNED NOT NULL DEFAULT 72,
  reviewer_config_json JSON NOT NULL,
  last_strategic_review_id BIGINT UNSIGNED NULL,
  last_review_at DATETIME NULL,
  configured_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_p73_strategic_review_settings_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_strategic_review_settings_last FOREIGN KEY(last_strategic_review_id) REFERENCES research_intelligence_strategic_reviews(id) ON DELETE SET NULL,
  CONSTRAINT fk_p73_strategic_review_settings_user FOREIGN KEY(configured_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
