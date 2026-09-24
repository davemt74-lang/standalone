-- Admin V1.90 — Tax, Invoices & Commercial Billing Hardening
-- Stripe calculates tax and executes invoice/payment state. Annotated stores billing policy, profile intent, invoice evidence and audit.

ALTER TABLE stripe_invoices
  ADD COLUMN subtotal_cents BIGINT NOT NULL DEFAULT 0 AFTER amount_remaining_cents,
  ADD COLUMN subtotal_excluding_tax_cents BIGINT NULL AFTER subtotal_cents,
  ADD COLUMN discount_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER subtotal_excluding_tax_cents,
  ADD COLUMN tax_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER discount_amount_cents,
  ADD COLUMN total_excluding_tax_cents BIGINT NULL AFTER tax_amount_cents,
  ADD COLUMN total_cents BIGINT NOT NULL DEFAULT 0 AFTER total_excluding_tax_cents,
  ADD COLUMN starting_balance_cents BIGINT NOT NULL DEFAULT 0 AFTER total_cents,
  ADD COLUMN ending_balance_cents BIGINT NULL AFTER starting_balance_cents,
  ADD COLUMN billing_reason VARCHAR(64) NULL AFTER invoice_number,
  ADD COLUMN collection_method VARCHAR(64) NULL AFTER billing_reason,
  ADD COLUMN automatic_tax_status VARCHAR(64) NULL AFTER collection_method,
  ADD COLUMN customer_tax_exempt VARCHAR(32) NULL AFTER automatic_tax_status,
  ADD COLUMN customer_address_json JSON NULL AFTER customer_tax_exempt,
  ADD COLUMN customer_tax_ids_json JSON NULL AFTER customer_address_json,
  ADD COLUMN custom_fields_json JSON NULL AFTER customer_tax_ids_json,
  ADD COLUMN invoice_description TEXT NULL AFTER custom_fields_json,
  ADD COLUMN invoice_footer TEXT NULL AFTER invoice_description,
  ADD COLUMN finalized_at DATETIME NULL AFTER paid_at;

CREATE TABLE IF NOT EXISTS commercial_billing_settings (
  stripe_mode ENUM('test','live') PRIMARY KEY,
  automatic_tax_enabled TINYINT(1) NOT NULL DEFAULT 0,
  collect_billing_address TINYINT(1) NOT NULL DEFAULT 1,
  collect_tax_ids TINYINT(1) NOT NULL DEFAULT 0,
  sync_billing_profile_to_stripe TINYINT(1) NOT NULL DEFAULT 1,
  invoice_memo VARCHAR(500) NULL,
  invoice_footer VARCHAR(1000) NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_commercial_billing_settings_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO commercial_billing_settings(stripe_mode) VALUES('test'),('live')
ON DUPLICATE KEY UPDATE stripe_mode=VALUES(stripe_mode);

CREATE TABLE IF NOT EXISTS account_billing_profiles (
  account_id BIGINT UNSIGNED PRIMARY KEY,
  legal_name VARCHAR(190) NULL,
  billing_email VARCHAR(255) NULL,
  address_line1 VARCHAR(190) NULL,
  address_line2 VARCHAR(190) NULL,
  address_city VARCHAR(120) NULL,
  address_state VARCHAR(120) NULL,
  address_postal_code VARCHAR(32) NULL,
  address_country CHAR(2) NULL,
  tax_exempt ENUM('none','exempt','reverse') NOT NULL DEFAULT 'none',
  invoice_prefix VARCHAR(12) NULL,
  purchase_order_number VARCHAR(120) NULL,
  source ENUM('customer','admin','checkout','stripe') NOT NULL DEFAULT 'customer',
  updated_by_user_id BIGINT UNSIGNED NULL,
  last_stripe_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_account_billing_profile_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_account_billing_profile_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commercial_tax_id_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  stripe_mode ENUM('test','live') NOT NULL,
  stripe_tax_id_id VARCHAR(255) NOT NULL,
  tax_id_type VARCHAR(64) NULL,
  value_last4 VARCHAR(8) NULL,
  verification_status VARCHAR(64) NULL,
  source_invoice_id VARCHAR(255) NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commercial_tax_snapshot(stripe_mode,stripe_tax_id_id),
  INDEX idx_commercial_tax_snapshot_account(account_id,stripe_mode,last_seen_at,id),
  CONSTRAINT fk_commercial_tax_snapshot_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commercial_invoice_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  stripe_mode ENUM('test','live') NOT NULL,
  stripe_invoice_id VARCHAR(255) NOT NULL,
  invoice_number VARCHAR(120) NULL,
  currency VARCHAR(12) NULL,
  financial_snapshot_sha256 CHAR(64) NOT NULL,
  financial_snapshot_json JSON NOT NULL,
  finalized_at DATETIME NULL,
  first_seen_event_id VARCHAR(255) NULL,
  last_seen_event_id VARCHAR(255) NULL,
  last_seen_event_type VARCHAR(120) NULL,
  drift_detected TINYINT(1) NOT NULL DEFAULT 0,
  drift_details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commercial_invoice_evidence(stripe_mode,stripe_invoice_id),
  INDEX idx_commercial_invoice_evidence_account(account_id,stripe_mode,created_at,id),
  INDEX idx_commercial_invoice_evidence_drift(stripe_mode,drift_detected,updated_at,id),
  CONSTRAINT fk_commercial_invoice_evidence_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commercial_billing_audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  stripe_mode ENUM('test','live') NULL,
  event_type VARCHAR(100) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_commercial_billing_audit_account(account_id,created_at,id),
  INDEX idx_commercial_billing_audit_type(event_type,created_at,id),
  CONSTRAINT fk_commercial_billing_audit_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_commercial_billing_audit_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
