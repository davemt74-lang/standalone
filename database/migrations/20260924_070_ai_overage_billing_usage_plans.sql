-- Admin V1.70 — AI Overage Billing & Usage Plans
-- Local AI usage remains authoritative; Stripe receives only validated accumulated overage charges.

ALTER TABLE subscription_packages
  ADD COLUMN ai_overage_policy ENUM('hard_limit','allow_overage','admin_defined') NOT NULL DEFAULT 'hard_limit' AFTER monthly_ai_token_allowance,
  ADD COLUMN ai_overage_input_micros_per_million BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER ai_overage_policy,
  ADD COLUMN ai_overage_output_micros_per_million BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER ai_overage_input_micros_per_million,
  ADD COLUMN ai_overage_default_cap_cents INT UNSIGNED NULL AFTER ai_overage_output_micros_per_million;

CREATE TABLE IF NOT EXISTS ai_overage_billing_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  stripe_reporting_enabled TINYINT(1) NOT NULL DEFAULT 1,
  usage_thresholds_json JSON NOT NULL,
  cap_thresholds_json JSON NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_overage_settings_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_overage_billing_settings(id,stripe_reporting_enabled,usage_thresholds_json,cap_thresholds_json)
VALUES(1,1,'[50,75,90,100]','[75,90,100]')
ON DUPLICATE KEY UPDATE id=VALUES(id);

CREATE TABLE IF NOT EXISTS ai_overage_account_settings (
  account_id BIGINT UNSIGNED PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  monthly_cap_cents INT UNSIGNED NULL,
  pricing_source ENUM('package','custom') NOT NULL DEFAULT 'package',
  custom_input_micros_per_million BIGINT UNSIGNED NULL,
  custom_output_micros_per_million BIGINT UNSIGNED NULL,
  opted_in_at DATETIME NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_overage_account_setting_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_overage_account_setting_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_overage_period_entitlements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  source_allowance_tokens BIGINT UNSIGNED NULL,
  allowance_tokens BIGINT UNSIGNED NULL,
  last_recalculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_overage_period_entitlement(account_id,period_start,period_end),
  CONSTRAINT fk_ai_overage_period_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_overage_period_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_overage_usage_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  ai_usage_event_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  provider_id BIGINT UNSIGNED NULL,
  model_id BIGINT UNSIGNED NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  stripe_mode ENUM('test','live') NOT NULL,
  allowance_snapshot_tokens BIGINT UNSIGNED NULL,
  prior_used_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  included_input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  included_output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  overage_input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  overage_output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  overage_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  billable TINYINT(1) NOT NULL DEFAULT 0,
  input_rate_micros_per_million BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_rate_micros_per_million BIGINT UNSIGNED NOT NULL DEFAULT 0,
  raw_amount_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,
  amount_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cap_limited TINYINT(1) NOT NULL DEFAULT 0,
  pricing_source ENUM('package','custom','none') NOT NULL DEFAULT 'none',
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_overage_usage_event(ai_usage_event_id),
  INDEX idx_ai_overage_ledger_account_period(account_id,stripe_mode,period_start,period_end,id),
  CONSTRAINT fk_ai_overage_ledger_usage FOREIGN KEY(ai_usage_event_id) REFERENCES ai_usage_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_overage_ledger_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_overage_ledger_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_overage_ledger_provider FOREIGN KEY(provider_id) REFERENCES ai_providers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_overage_ledger_model FOREIGN KEY(model_id) REFERENCES ai_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_overage_report_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  stripe_mode ENUM('test','live') NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  source_through_usage_id BIGINT UNSIGNED NOT NULL,
  source_accrued_micros BIGINT UNSIGNED NOT NULL,
  amount_micros BIGINT UNSIGNED NOT NULL,
  amount_cents BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  stripe_subscription_id VARCHAR(255) NOT NULL,
  stripe_customer_id VARCHAR(255) NOT NULL,
  stripe_invoice_item_id VARCHAR(255) NULL,
  stripe_invoice_id VARCHAR(255) NULL,
  status ENUM('creating','reported','invoiced','failed','void') NOT NULL DEFAULT 'creating',
  last_error VARCHAR(1000) NULL,
  reported_at DATETIME NULL,
  invoiced_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_overage_batch_idempotency(idempotency_key),
  INDEX idx_ai_overage_batch_account(account_id,stripe_mode,period_start,period_end,status,id),
  CONSTRAINT fk_ai_overage_batch_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_overage_batch_source_usage FOREIGN KEY(source_through_usage_id) REFERENCES ai_overage_usage_ledger(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_overage_threshold_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  threshold_type ENUM('allowance','cap') NOT NULL,
  threshold_percent INT UNSIGNED NOT NULL,
  observed_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  observed_limit BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_overage_threshold(account_id,period_start,period_end,threshold_type,threshold_percent),
  CONSTRAINT fk_ai_overage_threshold_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_overage_audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_overage_audit_account(account_id,created_at,id),
  INDEX idx_ai_overage_audit_package(package_id,created_at,id),
  CONSTRAINT fk_ai_overage_audit_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_overage_audit_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_overage_audit_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
