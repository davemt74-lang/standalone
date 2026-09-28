-- Annotated Phase 73 Section 3 — Cross-Decision Learning & Pattern Memory
-- Durable deterministic pattern memory derived from authoritative Phase 71/72 state.

CREATE TABLE IF NOT EXISTS research_intelligence_decision_pattern_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  state_hash CHAR(64) NOT NULL,
  pattern_count INT UNSIGNED NOT NULL DEFAULT 0,
  triggered_by ENUM('manual','cycle','test') NOT NULL DEFAULT 'manual',
  created_by_user_id BIGINT UNSIGNED NULL,
  snapshot_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_p73_pattern_run_state(portfolio_id,state_hash),
  INDEX idx_p73_pattern_run_portfolio(portfolio_id,created_at),
  CONSTRAINT fk_p73_pattern_run_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_pattern_run_user FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_decision_patterns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  portfolio_id BIGINT UNSIGNED NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  pattern_type ENUM(
    'repeated_assumption','repeated_plan_risk','recurring_variance_type',
    'recurring_outcome_assessment','expected_actual_variance','repeated_lesson'
  ) NOT NULL,
  normalized_key_hash CHAR(64) NOT NULL,
  label VARCHAR(255) NOT NULL,
  summary TEXT NOT NULL,
  evidence_count INT UNSIGNED NOT NULL DEFAULT 0,
  decision_count INT UNSIGNED NOT NULL DEFAULT 0,
  action_plan_count INT UNSIGNED NOT NULL DEFAULT 0,
  outcome_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_run_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_p73_pattern_fingerprint(portfolio_id,fingerprint),
  INDEX idx_p73_pattern_active(portfolio_id,active,pattern_type,last_seen_at),
  INDEX idx_p73_pattern_run(last_run_id),
  CONSTRAINT fk_p73_pattern_portfolio FOREIGN KEY(portfolio_id) REFERENCES research_intelligence_portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_p73_pattern_run FOREIGN KEY(last_run_id) REFERENCES research_intelligence_decision_pattern_runs(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_intelligence_decision_pattern_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pattern_id BIGINT UNSIGNED NOT NULL,
  member_type ENUM('decision','action_plan','outcome','variance') NOT NULL,
  member_public_id VARCHAR(80) NOT NULL,
  member_role VARCHAR(64) NOT NULL DEFAULT 'evidence',
  snapshot_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_p73_pattern_member(pattern_id,member_type,member_public_id,member_role),
  INDEX idx_p73_pattern_member_lookup(member_type,member_public_id),
  CONSTRAINT fk_p73_pattern_member_pattern FOREIGN KEY(pattern_id) REFERENCES research_intelligence_decision_patterns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
