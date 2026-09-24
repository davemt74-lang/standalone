-- Admin V1.40 — Stripe Billing, Checkout & Self-Service Subscriptions
-- Stripe owns payment execution. Annotated remains authoritative for accounts, packages and entitlements.

ALTER TABLE accounts
  MODIFY COLUMN subscription_status ENUM('trialing','active','past_due','paused','canceled') NOT NULL DEFAULT 'trialing',
  ADD COLUMN billing_source ENUM('manual','stripe','complimentary','internal') NOT NULL DEFAULT 'manual' AFTER subscription_status;

CREATE TABLE IF NOT EXISTS stripe_billing_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  mode ENUM('test','live') NOT NULL DEFAULT 'test',
  publishable_key VARCHAR(255) NULL,
  secret_key_ciphertext TEXT NULL,
  webhook_secret_ciphertext TEXT NULL,
  checkout_success_path VARCHAR(255) NOT NULL DEFAULT '/billing.php?checkout=success',
  checkout_cancel_path VARCHAR(255) NOT NULL DEFAULT '/billing.php?checkout=cancelled',
  portal_return_path VARCHAR(255) NOT NULL DEFAULT '/billing.php',
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_stripe_settings_user FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO stripe_billing_settings(id,mode) VALUES(1,'test')
ON DUPLICATE KEY UPDATE id=VALUES(id);

CREATE TABLE IF NOT EXISTS stripe_package_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  package_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('test','live') NOT NULL,
  stripe_product_id VARCHAR(255) NOT NULL,
  stripe_price_id VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_stripe_price_mode(mode,stripe_price_id),
  currency VARCHAR(12) NOT NULL DEFAULT 'usd',
  unit_amount_cents INT UNSIGNED NOT NULL,
  billing_interval VARCHAR(32) NOT NULL DEFAULT 'month',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_stripe_package_active(package_id,mode,active,id),
  CONSTRAINT fk_stripe_price_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE CASCADE,
  CONSTRAINT fk_stripe_price_actor FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('test','live') NOT NULL,
  stripe_customer_id VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_stripe_customer_mode(mode,stripe_customer_id),
  email_snapshot VARCHAR(255) NULL,
  name_snapshot VARCHAR(190) NULL,
  metadata_json JSON NULL,
  last_synced_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stripe_customer_account_mode(account_id,mode),
  INDEX idx_stripe_customer_account(account_id,id),
  CONSTRAINT fk_stripe_customer_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('test','live') NOT NULL,
  stripe_subscription_id VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_stripe_subscription_mode(mode,stripe_subscription_id),
  stripe_customer_id VARCHAR(255) NOT NULL,
  stripe_price_id VARCHAR(255) NULL,
  status VARCHAR(48) NOT NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  current_period_start DATETIME NULL,
  current_period_end DATETIME NULL,
  trial_end DATETIME NULL,
  canceled_at DATETIME NULL,
  ended_at DATETIME NULL,
  latest_invoice_id VARCHAR(255) NULL,
  last_event_id VARCHAR(255) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_stripe_subscription_account(account_id,mode,status,id),
  INDEX idx_stripe_subscription_customer(stripe_customer_id,id),
  INDEX idx_stripe_subscription_price(stripe_price_id,id),
  CONSTRAINT fk_stripe_subscription_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('test','live') NOT NULL,
  stripe_invoice_id VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_stripe_invoice_mode(mode,stripe_invoice_id),
  stripe_subscription_id VARCHAR(255) NULL,
  stripe_customer_id VARCHAR(255) NOT NULL,
  status VARCHAR(48) NULL,
  currency VARCHAR(12) NULL,
  amount_due_cents BIGINT NOT NULL DEFAULT 0,
  amount_paid_cents BIGINT NOT NULL DEFAULT 0,
  amount_remaining_cents BIGINT NOT NULL DEFAULT 0,
  invoice_number VARCHAR(120) NULL,
  hosted_invoice_url TEXT NULL,
  invoice_pdf_url TEXT NULL,
  period_start DATETIME NULL,
  period_end DATETIME NULL,
  due_at DATETIME NULL,
  paid_at DATETIME NULL,
  last_event_id VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_stripe_invoice_account(account_id,created_at,id),
  INDEX idx_stripe_invoice_subscription(stripe_subscription_id,id),
  CONSTRAINT fk_stripe_invoice_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stripe_event_id VARCHAR(255) NOT NULL,
  mode ENUM('test','live') NOT NULL,
  UNIQUE KEY uq_stripe_webhook_mode_event(mode,stripe_event_id),
  event_type VARCHAR(120) NOT NULL,
  object_id VARCHAR(255) NULL,
  payload_sha256 CHAR(64) NOT NULL,
  status ENUM('processing','processed','failed','ignored') NOT NULL DEFAULT 'processing',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
  error_text VARCHAR(1000) NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_stripe_webhook_status(status,received_at,id),
  INDEX idx_stripe_webhook_type(event_type,received_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_billing_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  account_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  source ENUM('stripe','admin','system') NOT NULL DEFAULT 'system',
  event_type VARCHAR(100) NOT NULL,
  stripe_mode ENUM('test','live') NULL,
  stripe_event_id VARCHAR(255) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account_billing_stripe_event(stripe_event_id,id),
  INDEX idx_account_billing_account(account_id,created_at,id),
  INDEX idx_account_billing_type(event_type,created_at,id),
  CONSTRAINT fk_account_billing_account FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_account_billing_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
