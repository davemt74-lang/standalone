-- Admin V1.30 — Account Lifecycle, Membership & Entitlement Administration
-- Extends the commercial account model without changing Research Team authority.

CREATE TABLE IF NOT EXISTS account_entitlement_overrides (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  entitlement_key VARCHAR(120) NOT NULL,
  value_json JSON NULL,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  expires_at DATETIME NULL,
  reason VARCHAR(500) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_account_entitlement_key(account_id,entitlement_key),
  INDEX idx_account_entitlement_status(account_id,status,expires_at),
  INDEX idx_account_entitlement_actor(updated_by_user_id,updated_at,id),
  CONSTRAINT fk_account_entitlement_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_account_entitlement_created_by FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_account_entitlement_updated_by FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_admin_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  subject_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account_admin_event_account(account_id,created_at,id),
  INDEX idx_account_admin_event_actor(actor_user_id,created_at,id),
  INDEX idx_account_admin_event_subject(subject_user_id,created_at,id),
  INDEX idx_account_admin_event_type(event_type,created_at,id),
  CONSTRAINT fk_account_admin_event_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_account_admin_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_account_admin_event_subject FOREIGN KEY(subject_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
