-- Annotated Phase 80 Section 1 — Research & Sponsor Account Governance

CREATE TABLE IF NOT EXISTS research_account_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  status ENUM('pending','approved','suspended','revoked') NOT NULL DEFAULT 'pending',
  verification_level ENUM('basic','identity','qualification','organization','payout') NOT NULL DEFAULT 'basic',
  specialties_json JSON NULL,
  languages_json JSON NULL,
  biography TEXT NULL,
  marketplace_visible TINYINT(1) NOT NULL DEFAULT 0,
  payout_readiness ENUM('not_started','pending','ready','blocked') NOT NULL DEFAULT 'not_started',
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  suspended_at DATETIME NULL,
  revoked_at DATETIME NULL,
  decision_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_research_account_status(status,updated_at),
  INDEX idx_research_account_marketplace(status,marketplace_visible,updated_at),
  CONSTRAINT fk_research_account_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_account_approved_by FOREIGN KEY(approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsor_account_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  status ENUM('pending','approved','suspended','revoked') NOT NULL DEFAULT 'pending',
  organization_name VARCHAR(190) NULL,
  website_url VARCHAR(500) NULL,
  verification_level ENUM('basic','identity','organization','billing') NOT NULL DEFAULT 'basic',
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  suspended_at DATETIME NULL,
  revoked_at DATETIME NULL,
  decision_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sponsor_account_status(status,updated_at),
  CONSTRAINT fk_sponsor_account_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sponsor_account_approved_by FOREIGN KEY(approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_account_governance_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  authority_type ENUM('research_account','sponsor_account') NOT NULL,
  subject_user_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('applied','profile_updated','approved','suspended','revoked','reopened') NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_research_governance_subject(subject_user_id,authority_type,created_at),
  INDEX idx_research_governance_actor(actor_user_id,created_at),
  CONSTRAINT fk_research_governance_subject FOREIGN KEY(subject_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_research_governance_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
